<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Communication;
use App\Models\CommunicationTemplate;
use App\Models\Customer;
use App\Models\Project;
use App\Support\Communication\Communications;
use App\Support\Communication\Subjects;
use App\Support\Communication\Templates;
use App\Support\Communication\Timeline;
use App\Support\Demo;
use App\Support\Messaging\Notifiers;
use App\Support\Pagination;
use App\Support\Permissions;
use App\Support\Search;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * المراسلات — الشاشة.
 *
 * ═══ بابان ═══
 *
 * سجلُّ الشركة كلُّه (`index` · `show` · `retry`) في قسم «المراسلات»: يفتحه
 * المالكُ والمدير ومن مُنحه. ونافذةُ المستند (`history` · `compose` ·
 * `send` · `timeline`) في قسم المستند نفسِه: البائعُ يرى مراسلاتِ عرضه
 * ويرسل منه بلا أن يُمنح السجلَّ كلَّه — والفعلُ يُسأل عنه بالاسم.
 *
 * ولا حسابَ هنا: القواعدُ في `Communications` و`Subjects` و`Templates`.
 */
class CommunicationController extends Controller
{
    private const PER_PAGE = [25, 50, 100];

    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    private function find(int|string $id): Communication
    {
        return Communication::where('business_id', $this->bid())->whereKey($id)
            ->with('customer', 'project', 'subject', 'initiator', 'retryOf', 'retries')->firstOrFail();
    }

    /**
     * المستندُ من نوعه ومعرّفه — في شركة الطالب وحدَها، وإلّا ٤٠٤.
     *
     * والنوعُ من `defaults('type')` في المسار لا من الرابط: الموجّهُ يمرّر
     * معاملاتِ المسار بترتيبها — معرّفُ الرابط أوّلًا ثمّ الافتراضيّ — فتأتي
     * `$id` قبل `$type` في توقيع كلّ دالّة.
     */
    private function subject(string $type, int|string $id): Model
    {
        $subject = Subjects::find($this->bid(), $type, $id);

        abort_if($subject === null, 404);

        return $subject;
    }

    /** @return array<string, bool> */
    private function may(): array
    {
        $user = auth()->user();

        return [
            'send' => (bool) $user?->may(Permissions::MESSAGING_SEND),
            'retry' => (bool) $user?->may(Permissions::COMMUNICATION_RETRY),
            'templates' => (bool) $user?->may(Permissions::COMMUNICATION_TEMPLATES_MANAGE),
            'view' => (bool) $user?->may(Permissions::COMMUNICATION_VIEW),
        ];
    }

    /** القنواتُ الموصولة فعلًا — الشاشةُ تُخفي بابًا لا يفتح */
    private function channels(): array
    {
        return [
            'email' => Notifiers::email()->configured(),
            'whatsapp' => Notifiers::whatsapp()->configured(),
        ];
    }

    /* ─────────────────────────── السجلّ ─────────────────────────── */

    public function index(Request $request): Response
    {
        $bid = $this->bid();

        $per = (int) $request->integer('per_page');
        $per = in_array($per, self::PER_PAGE, true) ? $per : self::PER_PAGE[0];

        $from = $request->date('from');
        $to = $request->date('to');

        $query = Communication::where('business_id', $bid)->with('customer', 'project', 'subject')
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('recipient', Search::like(), "%{$s}%")
                    ->orWhere('recipient_name', Search::like(), "%{$s}%")
                    ->orWhere('title', Search::like(), "%{$s}%")
                    ->orWhere('provider_message_id', $s)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', Search::like(), "%{$s}%"))
            ))
            ->when($request->string('channel')->toString(), fn ($q, $s) => in_array($s, Communication::CHANNELS, true)
                ? $q->where('channel', $s) : $q->whereRaw('1 = 0'))
            ->when($request->string('status')->toString(), fn ($q, $s) => in_array($s, Communication::STATUSES, true)
                ? $q->where('status', $s) : $q->whereRaw('1 = 0'))
            ->when($request->integer('project_id'), fn ($q, $id) => $q->where('project_id', $id))
            ->when($request->integer('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
            ->when($from, fn ($q, $d) => $q->where('created_at', '>=', $d->startOfDay()))
            ->when($to, fn ($q, $d) => $q->where('created_at', '<=', $d->endOfDay()));

        $page = (clone $query)->orderByDesc('id')->paginate($per)->withQueryString();

        /* والعدّاداتُ على المصفّى نفسِه: «ما وصل» ممّا أراه لا ممّا في القاعدة كلِّها */
        $counts = (clone $query)->reorder()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return Inertia::render('Admin/Communications/Index', [
            'communications' => collect($page->items())->map(fn (Communication $c) => $this->row($c))->all(),
            'pagination' => Pagination::meta($page),
            'filters' => $request->only('q', 'channel', 'status', 'project_id', 'customer_id', 'from', 'to', 'per_page'),
            'totals' => [
                'sent' => (int) ($counts[Communication::SENT] ?? 0) + (int) ($counts[Communication::DELIVERED] ?? 0) + (int) ($counts[Communication::READ] ?? 0),
                'delivered' => (int) ($counts[Communication::DELIVERED] ?? 0) + (int) ($counts[Communication::READ] ?? 0),
                'read' => (int) ($counts[Communication::READ] ?? 0),
                'failed' => (int) ($counts[Communication::FAILED] ?? 0) + (int) ($counts[Communication::BOUNCED] ?? 0),
            ],
            'statuses' => Communication::STATUSES,
            'channels' => $this->channels(),
            'projects' => Project::where('business_id', $bid)->orderByDesc('id')->limit(300)->get(['id', 'code', 'name'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->code.' — '.$p->name])->all(),
            'customers' => Customer::where('business_id', $bid)->orderBy('name')->get(['id', 'name'])->all(),
            'may' => $this->may(),
        ]);
    }

    public function show(int|string $id): Response
    {
        $c = $this->find($id);
        $subject = $c->subject;

        return Inertia::render('Admin/Communications/Show', [
            'communication' => $this->row($c) + [
                'language' => $c->language,
                'provider' => $c->provider,
                'provider_message_id' => $c->provider_message_id,
                'failure_code' => $c->failure_code,
                'failure_reason' => $c->failure_reason,
                'queued_at' => $c->queued_at?->format('Y-m-d H:i:s'),
                'sent_at' => $c->sent_at?->format('Y-m-d H:i:s'),
                'delivered_at' => $c->delivered_at?->format('Y-m-d H:i:s'),
                'read_at' => $c->read_at?->format('Y-m-d H:i:s'),
                'failed_at' => $c->failed_at?->format('Y-m-d H:i:s'),
                'url' => $c->metadata['url'] ?? null,
                'variables' => $c->metadata['variables'] ?? null,
                'subject_route' => Subjects::adminRoute($subject),
                'retryable' => $c->isRetryable(),
                'retry_of' => $c->retryOf === null ? null : ['id' => $c->retryOf->id, 'status' => $c->retryOf->status, 'created_at' => $c->retryOf->created_at?->format('Y-m-d H:i')],
                'retries' => $c->retries->map(fn (Communication $r) => [
                    'id' => $r->id, 'status' => $r->status, 'recipient' => $r->recipient,
                    'created_at' => $r->created_at?->format('Y-m-d H:i'), 'by' => $r->initiated_by_name,
                ])->values()->all(),
            ],
            'events' => $c->events->map(fn ($e) => [
                'id' => $e->id,
                'kind' => $e->kind,
                'status' => $e->status,
                'occurred_at' => $e->occurred_at?->format('Y-m-d H:i:s'),
                'code' => $e->code,
                'description' => $e->description,
                'provider' => $e->provider,
            ])->all(),
            'may' => $this->may(),
        ]);
    }

    /** إعادةُ ما فشل — بيدٍ وإذن، وإلى المُرسَل إليه نفسِه أو إلى عنوانٍ مصحَّح */
    public function retry(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::COMMUNICATION_RETRY), 403);

        $data = $request->validate(['to' => ['nullable', 'string', 'max:190']]);
        $original = $this->find($id);

        try {
            $retry = Communications::retry($original, auth()->user(), filled($data['to'] ?? null) ? $data['to'] : null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['retry' => $e->getMessage()]);
        }

        return redirect()->route('admin.communications.show', $retry->id)
            ->with('toast', ['msg' => __('أُعيد الإرسال إلى :to', ['to' => $retry->recipient]), 'type' => 'success']);
    }

    /* ─────────────────────────── نافذةُ المستند ─────────────────────────── */

    /** مراسلاتُ مستندٍ — لبطاقةٍ في شاشته */
    public function history(int|string $id, string $type): JsonResponse
    {
        abort_if(! auth()->user()?->may(Permissions::COMMUNICATION_VIEW), 403);

        $subject = $this->subject($type, $id);

        $may = $this->may();
        /* ولا زرَّ إرسالٍ لمستندٍ لا قالبَ له (العميلُ نفسُه: تُرسَل عنه مستنداتُه) */
        $may['send'] = $may['send'] && Templates::forSubject($subject) !== [];

        return response()->json([
            'communications' => Timeline::forSubject($subject)->map(fn (Communication $c) => $this->row($c))->values()->all(),
            'may' => $may + ['hub' => auth()->user()?->allows('communications') ?? false],
        ]);
    }

    /** قصّةُ المشروع بترتيبها — للتبويب */
    public function timeline(int|string $id): JsonResponse
    {
        abort_if(! auth()->user()?->may(Permissions::COMMUNICATION_VIEW), 403);

        /** @var Project $project */
        $project = $this->subject('project', $id);

        return response()->json([
            'timeline' => Timeline::forProject($project),
            'may' => $this->may() + ['hub' => auth()->user()?->allows('communications') ?? false],
        ]);
    }

    /** ما تحتاجه نافذةُ الإرسال: القوالبُ والقنواتُ ومن يُراسَل */
    public function compose(int|string $id, string $type): JsonResponse
    {
        abort_if(! auth()->user()?->may(Permissions::MESSAGING_SEND), 403);

        $subject = $this->subject($type, $id);
        $bid = $this->bid();
        $channels = $this->channels();

        $templates = collect(Templates::forSubject($subject))->map(function ($t) use ($bid, $channels) {
            $t['available'] = [];
            foreach ($t['channels'] as $channel) {
                foreach (CommunicationTemplate::LANGUAGES as $language) {
                    $resolved = Templates::resolve($bid, $t['key'], $channel, $language);
                    $t['available'][$channel][$language] = $channels[$channel] && $resolved->isSendable();
                }
            }

            return $t;
        })->values()->all();

        $why = null;
        try {
            Subjects::assertSendable($subject, '');
        } catch (RuntimeException $e) {
            $why = $e->getMessage();
        }

        return response()->json([
            'subject' => ['type' => $type, 'id' => $subject->getKey(), 'label' => Subjects::label($subject)],
            'channels' => $channels,
            'templates' => $templates,
            'recipients' => Subjects::recipients($subject),
            'languages' => CommunicationTemplate::LANGUAGES,
            'blocked' => $why,
        ]);
    }

    public function send(Request $request, int|string $id, string $type)
    {
        abort_if(! auth()->user()?->may(Permissions::MESSAGING_SEND), 403);

        $data = $request->validate([
            'channel' => ['required', Rule::in(Communication::CHANNELS)],
            'template' => ['required', 'string', 'max:80'],
            'to' => ['required', 'string', 'max:190'],
            'language' => ['nullable', Rule::in(CommunicationTemplate::LANGUAGES)],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [], ['channel' => __('القناة'), 'template' => __('القالب'), 'to' => __('المُرسَل إليه'), 'note' => __('رسالة مرافقة')]);

        $subject = $this->subject($type, $id);

        try {
            $communication = Communications::send($subject, $data['channel'], $data['template'], $data['to'], auth()->user(), [
                'language' => $data['language'] ?? 'ar', 'note' => $data['note'] ?? null,
            ]);
        } catch (RuntimeException $e) {
            return back()->withErrors(['communication' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'msg' => __('أُرسل عبر :channel إلى :to', ['channel' => Communications::channelLabel($communication->channel), 'to' => $communication->recipient]),
            'type' => 'success',
        ]);
    }

    /* ─────────────────────────── الصفّ ─────────────────────────── */

    /** @return array<string, mixed> */
    private function row(Communication $c): array
    {
        $subject = $c->subject;

        return [
            'id' => $c->id,
            'channel' => $c->channel,
            'status' => $c->status,
            'recipient' => $c->recipient,
            'recipient_name' => $c->recipient_name,
            'title' => $c->title,
            'template_key' => $c->template_key,
            'template_name' => Templates::exists($c->template_key) ? __(Templates::CATALOG[$c->template_key]['name']) : $c->template_key,
            'internal' => (bool) ($c->metadata['internal'] ?? false),
            'customer' => $c->customer?->name,
            'customer_id' => $c->customer_id,
            'project' => $c->project === null ? null : $c->project->code.' — '.$c->project->name,
            'project_id' => $c->project_id,
            'subject_type' => Subjects::keyOf($c->subject_type),
            'subject_id' => $c->subject_id,
            'subject_label' => Subjects::label($subject),
            'initiated_by' => $c->initiated_by_name,
            'retry_of_id' => $c->retry_of_id,
            'failure_reason' => $c->failure_reason,
            'created_at' => $c->created_at?->format('Y-m-d H:i'),
            'sent_at' => $c->sent_at?->format('Y-m-d H:i'),
            'delivered_at' => $c->delivered_at?->format('Y-m-d H:i'),
            'read_at' => $c->read_at?->format('Y-m-d H:i'),
            'failed_at' => $c->failed_at?->format('Y-m-d H:i'),
        ];
    }
}
