<?php

namespace App\Support\WhatsApp;

use App\Models\Business;
use App\Models\Communication;
use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Support\Contention;
use App\Support\Messaging\Notifiers;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * صندوقُ وارد واتساب — ما كتبه العميل، وما أجابته الشركة.
 *
 * ═══ أربعةُ أبواب ═══
 *
 * `receive()` — الـwebhook: رسالةٌ من العميل تُكتب مرّةً في محادثة رقمه.
 * `reply()` — الشاشة: نصٌّ حرٌّ داخل نافذة اليوم، ويُرفض بعدها بسببه.
 * `mirror()` — القناة: قالبٌ خرج من مستندٍ يظهر في المحادثة، يشير إلى قيده.
 * `applyStatus()` — الـwebhook: أُرسلت، وصلت، قُرئت، فشلت — ولا تتراجع.
 *
 * ═══ ولأيّ شركةٍ هذا الوارد؟ ═══
 *
 * رقمُ واتساب واحدٌ على الخادم (`services.whatsapp.phone_number_id`)، فشركتُه
 * `services.whatsapp.business_id` — أو الشركةُ الوحيدة إن لم يُسمَّ غيرها.
 * وخادمٌ بشركاتٍ ولا اسمَ لشركة الرقم يُسقط الوارد ولا يخمّن: رسالةُ عميلٍ في
 * صندوق شركةٍ أخرى أسوأ من رسالةٍ لم تصل.
 */
final class Inbox
{
    /** أقصى نصٍّ تقبله Meta في رسالةٍ واحدة */
    public const MAX_TEXT = 4096;

    /* ─────────────────────────── الشركة ─────────────────────────── */

    public static function businessFor(?string $phoneNumberId): ?int
    {
        $ours = (string) config('services.whatsapp.phone_number_id');

        /* خبرٌ عن رقمٍ غير رقمنا — تطبيقٌ واحدٌ عند Meta قد يحمل أرقامًا */
        if ($ours !== '' && filled($phoneNumberId) && $phoneNumberId !== $ours) {
            return null;
        }

        $named = (int) config('services.whatsapp.business_id');
        if ($named > 0) {
            return Business::whereKey($named)->exists() ? $named : null;
        }

        /* والشركةُ التجريبيّة لا تُحسب: عرضٌ على الخادم لا يُسقط وارد الشركة الحقيقيّة */
        $ids = Business::query()->where(fn ($q) => $q->where('is_demo', false)->orWhereNull('is_demo'))->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    /* ─────────────────────────── الوارد ─────────────────────────── */

    /**
     * رسالةٌ من العميل كما تكتبها Meta في `value.messages[]`.
     *
     * @param  array<string, mixed>  $message
     * @param  ?string  $name  اسمُه من `value.contacts[].profile.name`
     */
    public static function receive(int $businessId, array $message, ?string $name = null): ?WhatsAppMessage
    {
        $from = PhoneNumber::normalize((string) ($message['from'] ?? ''));
        $id = trim((string) ($message['id'] ?? ''));
        $type = strtolower(trim((string) ($message['type'] ?? 'text'))) ?: 'text';

        if ($from === null || $id === '') {
            return null;
        }

        /* التفاعلُ بوجهٍ على رسالةٍ ليس رسالة — ولا فقاعةَ له */
        if ($type === 'reaction') {
            return null;
        }

        $timestamp = (string) ($message['timestamp'] ?? '');
        $at = ctype_digit($timestamp)
            ? Carbon::createFromTimestampUTC((int) $timestamp)->setTimezone(config('app.timezone'))
            : now();

        [$body, $media] = self::contentOf($type, $message);

        return Contention::attempt(function () use ($businessId, $from, $id, $type, $at, $body, $media, $name, $message) {
            $conversation = self::conversation($businessId, $from, $name);

            $row = WhatsAppMessage::create([
                'business_id' => $businessId,
                'conversation_id' => $conversation->id,
                'direction' => Communication::INBOUND,
                'type' => mb_substr($type, 0, 20),
                'body' => $body,
                'media_id' => $media['id'] ?? null,
                'media_mime' => isset($media['mime_type']) ? mb_substr((string) $media['mime_type'], 0, 120) : null,
                'media_filename' => isset($media['filename']) ? mb_substr((string) $media['filename'], 0, 190) : null,
                'provider_message_id' => mb_substr($id, 0, 190),
                'status' => Communication::DELIVERED,
                'occurred_at' => $at,
                'metadata' => array_filter([
                    'context' => $message['context']['id'] ?? null,
                    'location' => $type === 'location' ? array_intersect_key((array) ($message['location'] ?? []), array_flip(['latitude', 'longitude', 'name', 'address'])) : null,
                ]) ?: null,
            ]);

            $locked = WhatsAppConversation::whereKey($conversation->id)->lockForUpdate()->first();
            $locked->forceFill([
                'last_inbound_at' => $locked->last_inbound_at === null || $at->gt($locked->last_inbound_at) ? $at : $locked->last_inbound_at,
                'unread_count' => $locked->unread_count + 1,
            ]);
            self::touch($locked, $row);
            $locked->save();

            return $row;
        });
    }

    /**
     * ما يُعرض في الفقاعة، وما يُنزَّل إن كان ملفًّا.
     *
     * @return array{0: ?string, 1: ?array<string, mixed>}
     */
    private static function contentOf(string $type, array $message): array
    {
        $part = (array) ($message[$type] ?? []);

        $body = match ($type) {
            'text' => $part['body'] ?? null,
            'image', 'video', 'document' => $part['caption'] ?? null,
            'location' => trim(implode(' — ', array_filter([$part['name'] ?? null, $part['address'] ?? null]))) ?: null,
            'button' => $part['text'] ?? null,
            'interactive' => $part['button_reply']['title'] ?? $part['list_reply']['title'] ?? null,
            'contacts' => collect((array) ($message['contacts'] ?? []))->map(fn ($c) => trim(($c['name']['formatted_name'] ?? '').' '.($c['phones'][0]['phone'] ?? '')))->filter()->implode("\n") ?: null,
            default => null,
        };

        $media = in_array($type, WhatsAppMessage::MEDIA, true) && filled($part['id'] ?? null) ? $part : null;

        return [$body !== null ? mb_substr((string) $body, 0, self::MAX_TEXT) : null, $media];
    }

    /* ─────────────────────────── الصادر ─────────────────────────── */

    /** ردٌّ نصّيٌّ من الشاشة — داخل نافذة اليوم وحدَها */
    public static function reply(WhatsAppConversation $conversation, string $text, User $actor): WhatsAppMessage
    {
        $text = trim($text);

        if ($text === '') {
            throw new RuntimeException(__('اكتب رسالة.'));
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            throw new RuntimeException(__('الرسالة أطول ممّا يقبله واتساب.'));
        }
        if (! $conversation->windowOpen()) {
            throw new RuntimeException(__('مضى يومٌ على آخر رسالةٍ من العميل — لا يُرسَل الآن إلّا قالبٌ معتمَد من شاشة المستند.'));
        }

        $notifier = Notifiers::whatsapp();
        if (! $notifier->configured()) {
            throw new RuntimeException(__('واتساب غير موصول على الخادم.'));
        }

        $result = $notifier->sendText($conversation->wa_id, $text);
        $now = now();

        return DB::transaction(function () use ($conversation, $text, $actor, $result, $now) {
            $row = WhatsAppMessage::create([
                'business_id' => $conversation->business_id,
                'conversation_id' => $conversation->id,
                'direction' => Communication::OUTBOUND,
                'type' => 'text',
                'body' => $text,
                'provider_message_id' => $result->providerMessageId,
                'status' => $result->sent ? Communication::SENT : Communication::FAILED,
                'occurred_at' => $now,
                'failed_at' => $result->sent ? null : $now,
                'failure_reason' => $result->sent ? null : mb_substr((string) $result->reason, 0, 2000),
                'sent_by' => $actor->id,
                'sent_by_name' => $actor->name,
            ]);

            $locked = WhatsAppConversation::whereKey($conversation->id)->lockForUpdate()->first();
            self::touch($locked, $row);
            $locked->save();

            return $row;
        });
    }

    /**
     * قالبٌ خرج من مستند — يُنسخ إلى محادثة رقمه ولا يُعاد قيدُه.
     *
     * ولا يرمي أبدًا: الرسالةُ ذهبت وقُيّدت، ومرآةٌ تسقط لا تُسقط ما قبلها.
     */
    public static function mirror(Communication $communication): void
    {
        try {
            if ($communication->channel !== Communication::WHATSAPP) {
                return;
            }

            $to = PhoneNumber::normalize($communication->recipient);
            if ($to === null) {
                return;
            }

            DB::transaction(function () use ($communication, $to) {
                $conversation = self::conversation((int) $communication->business_id, $to, $communication->recipient_name);

                if ($conversation->customer_id === null && $communication->customer_id !== null) {
                    $conversation->customer_id = $communication->customer_id;
                }

                $failed = in_array($communication->status, Communication::TERMINAL, true);

                $row = WhatsAppMessage::create([
                    'business_id' => $communication->business_id,
                    'conversation_id' => $conversation->id,
                    'communication_id' => $communication->id,
                    'direction' => Communication::OUTBOUND,
                    'type' => 'template',
                    'body' => $communication->title,
                    'provider_message_id' => $communication->provider_message_id,
                    'status' => $communication->status,
                    'occurred_at' => $communication->created_at ?? now(),
                    'failed_at' => $failed ? ($communication->failed_at ?? now()) : null,
                    'failure_reason' => $failed ? $communication->failure_reason : null,
                    'sent_by' => $communication->initiated_by,
                    'sent_by_name' => $communication->initiated_by_name,
                    'metadata' => ['url' => $communication->metadata['url'] ?? null] + ['template_key' => $communication->template_key],
                ]);

                self::touch($conversation, $row);
                $conversation->save();
            });
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /* ─────────────────────────── الحال ─────────────────────────── */

    /** خبرُ Meta عن رسالةٍ صادرة — يتقدّم ولا يتراجع */
    public static function applyStatus(string $providerMessageId, string $kind, Carbon $at, ?string $reason = null): void
    {
        $status = match ($kind) {
            'sent' => Communication::SENT,
            'delivered' => Communication::DELIVERED,
            'read' => Communication::READ,
            'failed' => Communication::FAILED,
            default => null,
        };

        if ($status === null || $providerMessageId === '') {
            return;
        }

        DB::transaction(function () use ($providerMessageId, $status, $at, $reason) {
            $rows = WhatsAppMessage::where('provider_message_id', $providerMessageId)
                ->where('direction', Communication::OUTBOUND)->lockForUpdate()->get();

            foreach ($rows as $row) {
                $current = Communication::RANK[$row->status] ?? 0;
                $next = Communication::RANK[$status] ?? 0;

                $update = match ($status) {
                    Communication::DELIVERED => $row->delivered_at === null ? ['delivered_at' => $at] : [],
                    Communication::READ => $row->read_at === null ? ['read_at' => $at] : [],
                    Communication::FAILED => ['failed_at' => $row->failed_at ?? $at, 'failure_reason' => $reason ?? $row->failure_reason],
                    default => [],
                };

                if ($next > $current) {
                    $update['status'] = $status;
                }

                if ($update !== []) {
                    $row->forceFill($update)->save();

                    if (isset($update['status'])) {
                        WhatsAppConversation::whereKey($row->conversation_id)
                            ->whereRaw('(select max(id) from whatsapp_messages where conversation_id = whatsapp_conversations.id) = ?', [$row->id])
                            ->update(['last_status' => $status]);
                    }
                }
            }
        });
    }

    /** فتحُ المحادثة يُصفّر عدّادها — ويقول للعميل «قُرئت» عن آخر ما كتب */
    public static function markRead(WhatsAppConversation $conversation): void
    {
        if ($conversation->unread_count === 0) {
            return;
        }

        $conversation->forceFill(['unread_count' => 0])->save();

        $last = $conversation->messages()->where('direction', Communication::INBOUND)
            ->whereNotNull('provider_message_id')->latest('id')->value('provider_message_id');

        if ($last !== null) {
            Notifiers::whatsapp()->markRead((string) $last);
        }
    }

    /* ─────────────────────────── الملفّات ─────────────────────────── */

    /**
     * مسارُ الملفّ على القرص — يُنزَّل من Meta في أوّل فتحٍ ثمّ يُقرأ من هنا.
     *
     * Meta تمحو روابطها بعد أيّام، فالنسخةُ عندنا هي ما يبقى.
     */
    public static function mediaPath(WhatsAppMessage $message): ?string
    {
        $disk = Storage::disk('local');

        if ($message->media_path !== null && $disk->exists($message->media_path)) {
            return $message->media_path;
        }

        if (! $message->hasMedia()) {
            return null;
        }

        $file = Notifiers::whatsapp()->download((string) $message->media_id);
        if ($file === null) {
            return null;
        }

        $mime = $message->media_mime ?: $file['mime'];
        $extension = $message->media_filename
            ? pathinfo($message->media_filename, PATHINFO_EXTENSION)
            : (self::EXTENSIONS[strtok((string) $mime, ';')] ?? 'bin');

        $path = sprintf('whatsapp/%d/%d-%s.%s', $message->business_id, $message->id, Str::random(8), Str::slug($extension) ?: 'bin');
        $disk->put($path, $file['body']);

        $message->forceFill(['media_path' => $path, 'media_mime' => $mime ? mb_substr($mime, 0, 120) : null])->save();

        return $path;
    }

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
        'video/mp4' => 'mp4', 'video/3gpp' => '3gp',
        'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac', 'audio/amr' => 'amr',
        'application/pdf' => 'pdf',
    ];

    /* ─────────────────────────── داخليّ ─────────────────────────── */

    /**
     * محادثةُ الرقم — تُنشأ أوّلَ مرّة، وتُربط بعميلٍ رقمُه رقمُها.
     *
     * والاصطدامُ على الإنشاء (رسالتان من رقمٍ جديدٍ في اللحظة نفسها) يُقرأ
     * بعده ما كتبه الآخر — لا محادثتان لرقمٍ واحد.
     */
    private static function conversation(int $businessId, string $waId, ?string $name): WhatsAppConversation
    {
        $existing = WhatsAppConversation::where('business_id', $businessId)->where('wa_id', $waId)->first();

        if ($existing === null) {
            $existing = Contention::attempt(fn () => WhatsAppConversation::create([
                'business_id' => $businessId,
                'wa_id' => $waId,
                'contact_name' => filled($name) ? mb_substr((string) $name, 0, 190) : null,
                'customer_id' => self::customerFor($businessId, $waId),
            ])) ?? WhatsAppConversation::where('business_id', $businessId)->where('wa_id', $waId)->firstOrFail();
        } elseif (filled($name) && $existing->contact_name !== $name) {
            $existing->forceFill(['contact_name' => mb_substr((string) $name, 0, 190)])->save();
        }

        if ($existing->customer_id === null) {
            $customer = self::customerFor($businessId, $waId);
            if ($customer !== null) {
                $existing->forceFill(['customer_id' => $customer])->save();
            }
        }

        return $existing;
    }

    /**
     * العميلُ الذي رقمُه هذا الرقم — بآخر ثمانية أرقامٍ ثمّ بالتطبيع الكامل.
     *
     * الأرقامُ في القاعدة مكتوبةٌ كما كُتبت (بمسافاتٍ، بصفرٍ، بمفتاحٍ أو بلا)،
     * فيُضيَّق بالذيل — وبين كلّ خانتين منه `%` فلا تحجبه مسافةٌ ولا شرطة —
     * ويُحسم بـ`PhoneNumber::normalize`. وعميلان بالرقم نفسه لا يُختار
     * أحدُهما: المحادثةُ تبقى بلا عميل حتّى يُعرف.
     */
    private static function customerFor(int $businessId, string $waId): ?int
    {
        $tail = '%'.implode('%', str_split(substr($waId, -8))).'%';

        $matches = Customer::where('business_id', $businessId)
            ->where(fn ($q) => $q->where('phone', 'like', $tail)->orWhere('contact_phone', 'like', $tail))
            ->limit(50)->get(['id', 'phone', 'contact_phone'])
            ->filter(fn ($c) => PhoneNumber::normalize($c->phone) === $waId || PhoneNumber::normalize($c->contact_phone) === $waId);

        return $matches->count() === 1 ? (int) $matches->first()->id : null;
    }

    /** آخرُ ما في المحادثة — لقائمة المحادثات */
    private static function touch(WhatsAppConversation $conversation, WhatsAppMessage $row): void
    {
        $at = $row->occurred_at ?? now();

        if ($conversation->last_message_at !== null && $at->lt($conversation->last_message_at)) {
            return;
        }

        $conversation->forceFill([
            'last_message_at' => $at,
            'last_preview' => mb_substr(self::preview($row), 0, 190),
            'last_direction' => $row->direction,
            'last_status' => $row->status,
        ]);
    }

    /** سطرٌ يُعرض تحت الاسم في القائمة */
    public static function preview(WhatsAppMessage $row): string
    {
        $label = match ($row->type) {
            'image' => __('📷 صورة'),
            'video' => __('🎥 فيديو'),
            'audio' => __('🎤 رسالة صوتية'),
            'document' => __('📄 مستند'),
            'sticker' => __('ملصق'),
            'location' => __('📍 موقع'),
            'contacts' => __('👤 جهة اتصال'),
            default => null,
        };

        $body = trim((string) $row->body);

        if ($label === null) {
            return $body !== '' ? $body : __('رسالة غير مدعومة');
        }

        return $body !== '' ? $label.' '.$body : $label;
    }
}
