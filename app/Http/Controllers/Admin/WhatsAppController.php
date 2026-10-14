<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Support\Demo;
use App\Support\Digits;
use App\Support\Messaging\Notifiers;
use App\Support\Permissions;
use App\Support\Search;
use App\Support\WhatsApp\Inbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * واتساب — صندوقُ الوارد.
 *
 * قائمةُ المحادثات ومحادثةٌ مفتوحة في صفحةٍ واحدة، والشاشةُ تعيد طلبَ
 * الجزأين وحدَهما كلَّ ثوانٍ (`only`) — فيظهر الواردُ بلا تحديث.
 * والقسمُ «المراسلات» (`ALIASES['whatsapp']`)، والردُّ فعلُ «الإرسال»
 * يُسأل عنه بالاسم. ولا حسابَ هنا: القواعدُ في `Inbox`.
 */
class WhatsAppController extends Controller
{
    /** ما يُعرض من المحادثة المفتوحة — أحدثُه */
    private const THREAD = 300;

    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    private function find(int|string $id): WhatsAppConversation
    {
        return WhatsAppConversation::where('business_id', $this->bid())->whereKey($id)->with('customer:id,name')->firstOrFail();
    }

    public function index(Request $request): Response
    {
        $bid = $this->bid();
        $q = Search::term($request);
        /* والرقمُ يُطابَق بخاناته اللاتينيّة — «٩١٢٣» تجد 9123 */
        $digits = preg_replace('/\D+/', '', (string) Digits::western($q)) ?? '';
        $filter = $request->string('filter')->toString() === 'unread' ? 'unread' : 'all';

        /* المفتوحةُ تُقرأ أوّلًا — فلا تعود القائمةُ بعدّادٍ صفّره فتحُها */
        $open = $request->integer('c') ? WhatsAppConversation::where('business_id', $bid)->whereKey($request->integer('c'))->with('customer:id,name')->first() : null;

        if ($open !== null) {
            Inbox::markRead($open);
        }

        $conversations = WhatsAppConversation::where('business_id', $bid)->with('customer:id,name')
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('contact_name', Search::like(), "%{$q}%")
                ->when($digits !== '', fn ($d) => $d->orWhere('wa_id', Search::like(), "%{$digits}%"))
                ->orWhereHas('customer', fn ($c) => $c->where('name', Search::like(), "%{$q}%"))))
            ->when($filter === 'unread', fn ($w) => $w->where('unread_count', '>', 0))
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->limit(200)->get()
            ->map(fn (WhatsAppConversation $c) => $this->row($c))->values();

        return Inertia::render('Admin/WhatsApp/Index', [
            'conversations' => $conversations,
            'thread' => $open ? $this->thread($open) : null,
            'filters' => ['q' => $q, 'filter' => $filter, 'c' => $open?->id],
            'configured' => Notifiers::whatsapp()->configured(),
            'may' => ['send' => (bool) auth()->user()?->may(Permissions::MESSAGING_SEND)],
        ]);
    }

    public function reply(Request $request, int|string $id): RedirectResponse
    {
        abort_unless(auth()->user()?->may(Permissions::MESSAGING_SEND), 403);

        $data = $request->validate(['body' => ['required', 'string', 'max:'.Inbox::MAX_TEXT]]);
        $conversation = $this->find($id);

        try {
            $message = Inbox::reply($conversation, $data['body'], auth()->user());
        } catch (RuntimeException $e) {
            return back()->with('toast', ['msg' => $e->getMessage(), 'type' => 'danger']);
        }

        if ($message->status === \App\Models\Communication::FAILED) {
            return back()->with('toast', ['msg' => __('لم تُرسَل الرسالة — :reason', ['reason' => (string) $message->failure_reason]), 'type' => 'danger']);
        }

        return back();
    }

    /** ملفٌّ في المحادثة — من القرص، أو من Meta أوّلَ مرّة */
    public function media(int|string $id): StreamedResponse
    {
        $message = WhatsAppMessage::where('business_id', $this->bid())->whereKey($id)->firstOrFail();

        $path = Inbox::mediaPath($message);
        abort_if($path === null, 404, __('تعذّر جلب الملفّ من واتساب.'));

        $name = $message->media_filename ?: basename($path);

        return Storage::disk('local')->response($path, $name, array_filter([
            'Content-Type' => $message->media_mime,
            'Cache-Control' => 'private, max-age=86400',
        ]), $message->type === 'document' ? 'attachment' : 'inline');
    }

    /** @return array<string, mixed> */
    private function row(WhatsAppConversation $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->customer?->name ?? $c->contact_name,
            'contact_name' => $c->contact_name,
            'wa_id' => $c->wa_id,
            'customer' => $c->customer ? ['id' => $c->customer->id, 'name' => $c->customer->name] : null,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'last_preview' => $c->last_preview,
            'last_direction' => $c->last_direction,
            'last_status' => $c->last_status,
            'unread_count' => $c->unread_count,
            'window_open' => $c->windowOpen(),
        ];
    }

    /** @return array<string, mixed> */
    private function thread(WhatsAppConversation $c): array
    {
        $messages = $c->messages()->orderByDesc('id')->limit(self::THREAD + 1)->get();
        $more = $messages->count() > self::THREAD;

        return [
            'conversation' => $this->row($c) + [
                'window_closes_at' => $c->last_inbound_at?->copy()->addDay()->toIso8601String(),
            ],
            'has_more' => $more,
            'messages' => $messages->take(self::THREAD)->reverse()->values()->map(fn (WhatsAppMessage $m) => [
                'id' => $m->id,
                'direction' => $m->direction,
                'type' => $m->type,
                'body' => $m->body,
                'status' => $m->status,
                'at' => ($m->occurred_at ?? $m->created_at)?->toIso8601String(),
                'media_url' => $m->hasMedia() || $m->media_path ? route('admin.whatsapp.media', $m->id) : null,
                'media_mime' => $m->media_mime,
                'media_filename' => $m->media_filename,
                'failure_reason' => $m->failure_reason,
                'sent_by_name' => $m->sent_by_name,
                'url' => $m->metadata['url'] ?? null,
                'location' => $m->metadata['location'] ?? null,
            ])->all(),
        ];
    }
}
