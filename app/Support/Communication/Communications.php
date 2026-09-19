<?php

namespace App\Support\Communication;

use App\Models\ChangeOrder;
use App\Models\Communication;
use App\Models\CommunicationEvent;
use App\Models\CommunicationTemplate;
use App\Models\DeliveryPackage;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Activity;
use App\Support\ChangeOrders;
use App\Support\Contention;
use App\Support\DeliveryPackages;
use App\Support\Messaging\MessageIntent;
use App\Support\Messaging\MessageResult;
use App\Support\Messaging\Notifiers;
use App\Support\PhoneNumber;
use App\Support\Quotations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * سجلُّ المراسلات — ما قالته الشركةُ لعملائها، ومتى، وماذا صار إليه.
 *
 * ═══ ثلاثةُ أبواب ═══
 *
 * `record()` — تناديه القناةُ بعد كلّ محاولة إرسال، نجحت أو فشلت. فكلُّ ما
 * يمرّ بـ`Notifier` يُقيَّد، ولا يعرف من كتب `Quotations::email` أنّ سجلًّا
 * يُكتب تحته.
 *
 * `send()` — بابُ الشاشة: يفحص القالبَ والقناةَ والمُرسَلَ إليه وحالَ
 * المستند، ثمّ يُرسل. وللبريد الذي له خدمتُه (العرضُ وأمرُ التغيير وحزمةُ
 * التسليم) يناديها كما هي — لا يُعاد بناءُ ما يعمل.
 *
 * `applyEvent()` — بابُ المزوّد: خبرٌ عن رسالةٍ بمعرّفها، يُترجَم إلى الحال
 * الداخليّة ولا يُكتب مرّتين.
 *
 * ═══ والسياقُ الضمنيّ بابٌ مؤقّت ═══
 *
 * `about()` يقول للقناة عمّا تتكلّم الرسالةُ حين لا يقوله `MessageIntent`.
 * ومن يمرّر `about:` في النيّة لا يحتاجه. وهو للمواضع التي لا يُمسّ كودُها
 * في هذه المرحلة (`Quotations` و`ChangeOrders` تحت مراجعة) — ويُنزع يومَ
 * تمرّر نيّتُها موضوعَها بنفسها.
 */
final class Communications
{
    /** ثوانٍ لا تُرسَل فيها الرسالةُ نفسُها إلى الشخص نفسِه ثانيةً — ضغطتان على زرٍّ واحد */
    private const DEDUPE_SECONDS = 15;

    /** @var array{about: ?Model, customer_id: ?int, retry_of: ?int, metadata: array<string, mixed>, actor: ?string}|null */
    private static ?array $context = null;

    /** آخرُ ما قُيّد داخل `about()` — يقرؤه `send()` ليعيده */
    private static ?Communication $last = null;

    /* ─────────────────────────── السياق ─────────────────────────── */

    /**
     * تنفيذُ إرسالٍ باسم مستند — فتُقيَّد رسائلُه عليه.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function about(?Model $subject, callable $fn, ?int $customerId = null, ?int $retryOf = null, array $metadata = [], ?string $actor = null): mixed
    {
        $previous = self::$context;
        /* والسياقُ الأعلى يورّث ما لم يُقَل هنا: إعادةٌ من الأتمتة تحمل سياقَها */
        self::$context = [
            'about' => $subject, 'customer_id' => $customerId, 'retry_of' => $retryOf,
            'metadata' => $metadata ?: ($previous['metadata'] ?? []),
            'actor' => $actor ?? ($previous['actor'] ?? null),
        ];
        self::$last = null;

        try {
            return $fn();
        } finally {
            self::$context = $previous;
        }
    }

    public static function last(): ?Communication
    {
        return self::$last;
    }

    /* ─────────────────────────── القيد ─────────────────────────── */

    /**
     * قيدُ محاولةِ إرسال — تناديه القناةُ بعد أن تحاول.
     *
     * ولا يرمي أبدًا: الرسالةُ ذهبت أو لم تذهب، وسجلٌّ يسقط لا يُسقط ما
     * وراءه. وما لا يُعرف متجرُه لا يُقيَّد — ولا متجرَ يعني أنّ الرسالة
     * ليست من مراسلات شركةٍ مع عميل.
     */
    public static function record(string $channel, string $provider, MessageIntent $intent, MessageResult $result): ?Communication
    {
        try {
            $about = $intent->about ?? self::$context['about'] ?? null;
            $user = auth()->user();
            $businessId = $intent->businessId ?? ($about?->business_id ?? null) ?? $user?->business_id;

            if (! $businessId) {
                return null;
            }

            $recipient = self::normalizeRecipient($channel, $intent->to) ?? mb_substr(trim($intent->to), 0, 190);
            $sent = $result->sent;
            $now = now();

            $communication = Communication::create([
                'business_id' => (int) $businessId,
                'customer_id' => $intent->customerId ?? self::$context['customer_id'] ?? Subjects::customerIdOf($about),
                'project_id' => Subjects::projectIdOf($about),
                'subject_type' => $about?->getMorphClass(),
                'subject_id' => $about?->getKey(),
                'channel' => $channel,
                'direction' => Communication::OUTBOUND,
                'recipient' => $recipient,
                'recipient_name' => $intent->recipientName !== null ? mb_substr($intent->recipientName, 0, 190) : null,
                'template_key' => mb_substr($intent->templateKey, 0, 80),
                'title' => mb_substr($intent->subject, 0, 255),
                'language' => $intent->language ?? app()->getLocale(),
                'provider' => mb_substr($provider, 0, 40),
                'provider_message_id' => $result->providerMessageId !== null ? mb_substr($result->providerMessageId, 0, 190) : null,
                'status' => $sent ? Communication::SENT : Communication::FAILED,
                'queued_at' => $now,
                'sent_at' => $sent ? $now : null,
                'failed_at' => $sent ? null : $now,
                'failure_code' => $sent ? null : 'send_failed',
                'failure_reason' => $sent ? null : mb_substr((string) $result->reason, 0, 2000),
                'initiated_by' => $user?->id,
                'initiated_by_name' => $user?->name ?? (self::$context['actor'] ?? __('النظام')),
                'retry_of_id' => self::$context['retry_of'] ?? null,
                'metadata' => array_filter([
                    'url' => $intent->url,
                    'variables' => $intent->variables ?: null,
                    'internal' => (Templates::exists($intent->templateKey) && ! empty(Templates::CATALOG[$intent->templateKey]['internal'])) ?: null,
                ]) + (array) (self::$context['metadata'] ?? []),
            ]);

            self::$last = $communication;

            if (! $sent) {
                Activity::log('status', 'فشل إرسال «'.$communication->title.'» عبر '.self::channelLabel($channel).' إلى '.$recipient.' — '.$result->reason, [
                    'business_id' => (int) $businessId, 'subject_id' => $communication->id, 'subject_type' => 'communication',
                    'icon' => 'alert-triangle', 'color' => 'danger',
                ]);
            }

            return $communication;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /* ─────────────────────────── الإرسال من الشاشة ─────────────────────────── */

    /**
     * إرسالُ رسالةٍ عن مستند — من الشاشة، بقالبٍ وقناةٍ ومُرسَلٍ إليه.
     *
     * و`metadata` تُكتب في القيد كما هي — به تقول الأتمتةُ «لماذا أرسلتُ هذا»
     * (`automation_rule_id` · `automation_run_id` · `trigger` · `reason`)، و`actor`
     * اسمُ الفاعل حين لا مستخدمَ في الجلسة («الأتمتة»).
     *
     * @param  array{note?: ?string, language?: ?string, retry_of?: ?int, recipient_name?: ?string, metadata?: array<string, mixed>, actor?: ?string}  $opts
     */
    public static function send(Model $subject, string $channel, string $templateKey, string $to, ?User $actor = null, array $opts = []): Communication
    {
        if (! in_array($channel, Communication::CHANNELS, true)) {
            throw new RuntimeException(__('قناةٌ غير معروفة.'));
        }

        $businessId = (int) $subject->business_id;
        $language = in_array($opts['language'] ?? null, CommunicationTemplate::LANGUAGES, true) ? $opts['language'] : 'ar';

        Templates::assertFits($templateKey, $subject, $channel);

        $template = Templates::resolve($businessId, $templateKey, $channel, $language);
        if (! $template->isActive()) {
            throw new RuntimeException(__('هذا القالب موقوف — فعّله من «المراسلات ‹ القوالب» قبل أن تُرسل به.'));
        }
        if (! $template->isSendable()) {
            throw new RuntimeException(__('لا قالب واتساب معتمَدًا لهذه الرسالة — اضبط اسمه في «المراسلات ‹ القوالب».'));
        }

        $notifier = Notifiers::for($channel);
        if (! $notifier->configured()) {
            throw new RuntimeException($channel === Communication::WHATSAPP
                ? __('واتساب غير موصول على الخادم.')
                : __('إرسالُ البريد غير مضبوط على الخادم — انسخ الرابط وأرسله بنفسك.'));
        }

        $recipient = self::normalizeRecipient($channel, $to);
        if ($recipient === null) {
            throw new RuntimeException($channel === Communication::WHATSAPP
                ? __('رقمُ الهاتف لا يصلح — اكتبه بصيغته الدوليّة.')
                : __('البريد الإلكتروني لا يصلح.'));
        }

        Subjects::assertSendable($subject, $templateKey);

        $retryOf = isset($opts['retry_of']) ? (int) $opts['retry_of'] : null;
        self::guardDuplicate($businessId, $channel, $subject, $templateKey, $recipient, $retryOf);

        $note = filled($opts['note'] ?? null) ? mb_substr(trim((string) $opts['note']), 0, 2000) : null;

        $communication = self::about($subject, function () use ($subject, $channel, $templateKey, $recipient, $actor, $note, $language, $template, $notifier, $opts) {
            /*
             * والبريدُ الذي له خدمتُه يبقى في خدمته: هي تعرف التذكيرَ من
             * الأولى، وتكتب `sent_to`، وتُقيّد أثرَها. ولا يُعاد بناؤها هنا.
             */
            /*
             * والخدمةُ القديمة تقرّر «رابطًا» أو «تذكيرًا» بما إن أُرسل بريدٌ من
             * قبل (`sent_to`). فتُنادى للرابط دائمًا، وللتذكير حين سبقه رابط —
             * وتذكيرٌ لعرضٍ لم يُبرَّد قطّ يُبنى من قالبه في الكتالوج.
             */
            $reminded = isset($subject->sent_to) && $subject->sent_to !== null;
            $legacy = match (true) {
                $channel !== Communication::EMAIL => null,
                $subject instanceof Quotation && ($templateKey === 'quotation.link' || ($templateKey === 'quotation.reminder' && $reminded)) => fn () => Quotations::email($subject, $recipient, $note, $actor),
                $subject instanceof ChangeOrder && ($templateKey === 'change_order.link' || ($templateKey === 'change_order.reminder' && $reminded)) => fn () => ChangeOrders::email($subject, $recipient, $note, $actor),
                $subject instanceof DeliveryPackage && ($templateKey === 'delivery.link' || ($templateKey === 'delivery.reminder' && $reminded)) => fn () => DeliveryPackages::email($subject, $recipient, $note, $actor),
                default => null,
            };

            if ($legacy !== null) {
                /* وبلغة القالب المختارة: نصُّها يمرّ بـ`__()` فيخرج بلغة التطبيق لحظتَها */
                $previous = app()->getLocale();
                app()->setLocale($language);
                try {
                    $legacy();
                } finally {
                    app()->setLocale($previous);
                }

                return self::$last;
            }

            $intent = Messages::intent($subject, $template, $recipient, $actor, [
                'note' => $note, 'language' => $language, 'recipient_name' => $opts['recipient_name'] ?? null,
            ]);
            $result = $notifier->send($intent);

            if (! $result->sent) {
                throw new RuntimeException((string) $result->reason);
            }

            return self::$last;
        }, null, $retryOf, (array) ($opts['metadata'] ?? []), $opts['actor'] ?? null);

        if ($communication === null) {
            throw new RuntimeException(__('أُرسلت الرسالة ولم يُكتب قيدُها — راجع سجلّ الأخطاء.'));
        }

        Activity::log('updated', ($retryOf ? 'أعاد إرسال «' : 'أرسل «').$communication->title.'» عبر '.self::channelLabel($channel).' إلى '.$recipient, [
            'business_id' => $businessId, 'subject_id' => $communication->id, 'subject_type' => 'communication',
            'icon' => $channel === Communication::WHATSAPP ? 'message-circle' : 'mail',
        ]);

        return $communication;
    }

    /**
     * إعادةُ إرسالِ ما فشل — بيدٍ وإذنٍ، وصفٍّ جديدٍ يشير إلى أصله.
     *
     * ولا يُعاد ما نجح، ولا ما نجحت إعادتُه: رسالةٌ ماليّةٌ تصل مرّتين
     * أسوأ من رسالةٍ لا تصل. والمُرسَلُ إليه يُبدَّل إن كان الخطأُ فيه —
     * بريدٌ ارتدّ يُصحَّح لا يُعاد كما هو.
     */
    public static function retry(Communication $original, ?User $actor = null, ?string $to = null): Communication
    {
        if (! $original->isRetryable()) {
            throw new RuntimeException($original->isTerminal()
                ? __('أُعيد إرسالُ هذه الرسالة ووصلت — لا تُعاد ثانيةً.')
                : __('لا يُعاد إلا ما فشل أو ارتدّ.'));
        }

        $subject = $original->subject;
        if ($subject === null) {
            throw new RuntimeException(__('موضوعُ هذه الرسالة لم يعد موجودًا — لا تُعاد.'));
        }

        return self::send($subject, $original->channel, $original->template_key, $to ?? $original->recipient, $actor, [
            'language' => $original->language, 'retry_of' => $original->id,
            'recipient_name' => $original->recipient_name,
        ]);
    }

    /* ─────────────────────────── خبرُ المزوّد ─────────────────────────── */

    /**
     * خبرٌ من المزوّد عن رسالة — يُترجَم ويُكتب مرّةً.
     *
     * ═══ لا يُكتب مرّتين ═══
     *
     * لكلّ خبرٍ معرّفٌ (`eventId`)، والقيدُ الفريد يردّ الثاني. والحالُ
     * لا تتراجع: «وصلت» بعد «قُرئت» تُكتب خبرًا ولا تمحو القراءة. ولا
     * يُبحث عن الرسالة إلّا بمعرّفها عند المزوّد نفسِه — فلا يكتب مزوّدٌ
     * على رسالة مزوّدٍ آخر، ولا متجرٌ على رسالة متجر.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function applyEvent(string $provider, string $providerMessageId, string $kind, ?string $eventId = null, ?Carbon $occurredAt = null, ?string $code = null, ?string $description = null, array $payload = []): ?Communication
    {
        if ($providerMessageId === '') {
            return null;
        }

        $communication = Communication::where('provider', $provider)->where('provider_message_id', $providerMessageId)->first();

        if ($communication === null) {
            return null;
        }

        $status = self::statusOf($kind);
        $at = $occurredAt ?? now();

        $written = Contention::attempt(fn () => CommunicationEvent::create([
            'business_id' => $communication->business_id,
            'communication_id' => $communication->id,
            'provider' => $provider,
            'event_id' => $eventId !== null ? mb_substr($eventId, 0, 190) : null,
            'kind' => mb_substr($kind, 0, 30),
            'status' => $status,
            'occurred_at' => $at,
            'code' => $code !== null ? mb_substr($code, 0, 80) : null,
            'description' => $description !== null ? mb_substr($description, 0, 2000) : null,
            'payload' => self::sanitize($payload),
        ]));

        /* خبرٌ مكرَّر: كُتب من قبل — فلا يُمسّ شيء */
        if ($written === null) {
            return $communication;
        }

        if ($status === null) {
            return $communication;
        }

        DB::transaction(function () use ($communication, $status, $at, $code, $description) {
            $locked = Communication::whereKey($communication->id)->lockForUpdate()->first();
            $current = Communication::RANK[$locked->status] ?? 0;
            $next = Communication::RANK[$status] ?? 0;

            $update = [];

            /* وطوابعُ الأوقات تُكتب أوّلَ مرّة — «أوّلُ قراءةٍ» هو ما يُقاس */
            $column = match ($status) {
                Communication::SENT => 'sent_at',
                Communication::DELIVERED => 'delivered_at',
                Communication::READ => 'read_at',
                Communication::FAILED, Communication::BOUNCED => 'failed_at',
                default => null,
            };
            if ($column !== null && $locked->{$column} === null) {
                $update[$column] = $at;
            }

            if (in_array($status, Communication::TERMINAL, true)) {
                $update['failure_code'] = $code !== null ? mb_substr($code, 0, 80) : ($locked->failure_code ?? 'provider_failure');
                $update['failure_reason'] = $description !== null ? mb_substr($description, 0, 2000) : $locked->failure_reason;
            }

            if ($next > $current || (in_array($status, Communication::TERMINAL, true) && ! $locked->isTerminal())) {
                $update['status'] = $status;
            }

            if ($update !== []) {
                $locked->update($update);
            }

            if (in_array($status, Communication::TERMINAL, true) && ! in_array($communication->status, Communication::TERMINAL, true)) {
                Activity::log('status', 'أبلغ المزوّد بفشل «'.$locked->title.'» إلى '.$locked->recipient.' — '.($description ?? $code ?? ''), [
                    'business_id' => $locked->business_id, 'subject_id' => $locked->id, 'subject_type' => 'communication',
                    'icon' => 'alert-triangle', 'color' => 'danger',
                ]);
            }
        });

        return $communication->fresh();
    }

    /** الحالُ الداخليّة من نوع الخبر — أو null لخبرٍ لا يغيّر حالًا */
    public static function statusOf(string $kind): ?string
    {
        return match ($kind) {
            'sent', 'accepted' => Communication::SENT,
            'delivered' => Communication::DELIVERED,
            'read', 'opened' => Communication::READ,
            'failed', 'undelivered' => Communication::FAILED,
            'bounced', 'complaint' => Communication::BOUNCED,
            default => null,
        };
    }

    /* ─────────────────────────── المساعدات ─────────────────────────── */

    /** المُرسَلُ إليه مطبَّعًا للقناة — أو null لما لا يصلح */
    public static function normalizeRecipient(string $channel, ?string $raw): ?string
    {
        return $channel === Communication::WHATSAPP ? PhoneNumber::normalize($raw) : self::normalizeEmail($raw);
    }

    public static function normalizeEmail(?string $raw): ?string
    {
        $email = mb_strtolower(trim((string) $raw));

        if ($email === '' || mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    public static function channelLabel(string $channel): string
    {
        return $channel === Communication::WHATSAPP ? __('واتساب') : __('البريد');
    }

    /**
     * ما يُخزَّن من ردّ المزوّد — بلا سرّ.
     *
     * المفاتيحُ التي تشبه رمزًا أو مفتاحًا تُحذف أينما كانت، والنصوصُ
     * الطويلة تُقصّ: الحمولةُ خبرٌ عن الرسالة لا نسخةٌ منها.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function sanitize(array $payload, int $depth = 0): array
    {
        $out = [];

        foreach ($payload as $key => $value) {
            if (preg_match('/token|secret|password|authorization|credential|api[_-]?key|signature/i', (string) $key)) {
                continue;
            }

            if (is_array($value)) {
                $out[$key] = $depth < 4 ? self::sanitize($value, $depth + 1) : '[…]';
            } elseif (is_string($value)) {
                $out[$key] = mb_substr($value, 0, 500);
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** ضغطتان على «أرسل» رسالةٌ واحدة — والإعادةُ الصريحةُ تمرّ */
    private static function guardDuplicate(int $businessId, string $channel, Model $subject, string $templateKey, string $recipient, ?int $retryOf): void
    {
        if ($retryOf !== null) {
            return;
        }

        $key = 'communications:dedupe:'.sha1(implode('|', [$businessId, $channel, $subject->getMorphClass(), $subject->getKey(), $templateKey, $recipient]));

        if (! Cache::add($key, 1, self::DEDUPE_SECONDS)) {
            throw new RuntimeException(__('أُرسلت هذه الرسالة نفسُها قبل لحظات — انتظر قليلًا قبل أن تعيدها.'));
        }
    }
}
