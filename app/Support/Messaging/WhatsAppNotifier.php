<?php

namespace App\Support\Messaging;

use App\Support\Communication\Communications;
use App\Support\Communication\Templates;
use App\Support\PhoneNumber;
use App\Support\WhatsApp\Inbox;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * واتساب — عبر WhatsApp Cloud API من Meta.
 *
 * ═══ ما تعرفه هذه الطبقةُ وحدَها (§21) ═══
 *
 * رمزُ الوصول ومعرّفُ الرقم، وأسماءُ القوالب المعتمَدة عند Meta وخاناتُها،
 * وشكلُ الطلب والردّ. والنظامُ الأساسيّ يقول «أرسل هذه النيّةَ إلى هذا
 * الرقم» — وينتظر `MessageResult`.
 *
 * ═══ ولا يُرسَل إلّا بقالبٍ معتمَد ═══
 *
 * Meta لا تقبل رسالةً خارجَ نافذة ٢٤ ساعة إلّا من قالبٍ اعتمدته — وكلُّ
 * رسائلنا تشغيليّةٌ تخرج من الشركة أوّلًا، فكلُّها قوالب. والقالبُ يُطابَق
 * بمفتاح النيّة (`templateKey`) في `communication_templates`: اسمُه عندهم،
 * ولغتُه، وترتيبُ خاناته. ومفتاحٌ بلا قالبٍ مضبوط يُرفض هنا بسببه، لا
 * يُرمى إلى Meta لتردّه بخطأٍ لا يفهمه أحد.
 *
 * ═══ والسرُّ لا يخرج ═══
 *
 * الرمزُ يُقرأ من `services.whatsapp.token` لحظةَ الطلب ولا يُمرَّر ولا
 * يُكتب — لا في القيد ولا في السجلّ ولا في نصّ الخطأ.
 *
 * ═══ ولا طابور — كالبريد ═══
 *
 * من يضغط «أرسل» واقفٌ أمام الشاشة؛ والطلبُ إلى Meta ثوانٍ، والخطأُ إن
 * وقع يراه هو. والحالُ بعد «أُرسلت» — وصلت، قُرئت، فشلت — تأتي من الـwebhook.
 */
final class WhatsAppNotifier implements Notifier
{
    private const TIMEOUT = 15;

    public function channel(): string
    {
        return 'whatsapp';
    }

    public function provider(): string
    {
        return 'meta';
    }

    /** موصولٌ حين يكون الرمزُ ومعرّفُ الرقم معًا — وما دونهما بابٌ لا يُعرض */
    public function configured(): bool
    {
        return filled(config('services.whatsapp.token')) && filled(config('services.whatsapp.phone_number_id'));
    }

    public function send(MessageIntent $intent): MessageResult
    {
        if (! $this->configured()) {
            return $this->done($intent, MessageResult::failed(__('واتساب غير موصول على الخادم.')));
        }

        $to = PhoneNumber::normalize($intent->to);
        if ($to === null) {
            return $this->done($intent, MessageResult::failed(__('رقمُ الهاتف لا يصلح — اكتبه بصيغته الدوليّة.')));
        }

        $template = $intent->businessId !== null
            ? Templates::whatsapp($intent->businessId, $intent->templateKey, $intent->language ?? 'ar')
            : null;

        if ($template === null) {
            return $this->done($intent, MessageResult::failed(__('لا قالب واتساب معتمَدًا لهذه الرسالة — اضبط اسمه في «المراسلات ‹ القوالب».')));
        }

        $parameters = Templates::parameters($template, $intent->variables);
        $header = Templates::headerParameters($template, $intent->variables);
        $button = Templates::buttonParameter($template, $intent->variables);
        $text = fn (array $values) => array_map(fn ($p) => ['type' => 'text', 'text' => $p], $values);

        /*
         * والمكوّناتُ بما ضُبط في القالب: ترويسةٌ متغيّرة إن كانت لها خانات، والمتنُ،
         * وزرُّ رابطٍ ديناميكيّ تُرسَل لاحقتُه وحدها (الرابطُ الأساس عند Meta).
         */
        $components = array_values(array_filter([
            $header === [] ? null : ['type' => 'header', 'parameters' => $text($header)],
            $parameters === [] ? null : ['type' => 'body', 'parameters' => $text($parameters)],
            $button === null ? null : ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $button]]],
        ]));

        $body = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $template->provider_template,
                'language' => ['code' => $template->provider_language ?: ($intent->language === 'en' ? 'en' : 'ar')],
                'components' => $components,
            ],
        ];

        return $this->done($intent, $this->post($body));
    }

    /**
     * نصٌّ حرٌّ إلى رقم — ردٌّ من صندوق الوارد داخل نافذة اليوم.
     *
     * ولا يُقيَّد في المراسلات: سجلُّه المحادثةُ نفسُها — انظر `Inbox::reply`.
     */
    public function sendText(string $to, string $text): MessageResult
    {
        if (! $this->configured()) {
            return MessageResult::failed(__('واتساب غير موصول على الخادم.'));
        }

        return $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => true, 'body' => $text],
        ]);
    }

    /**
     * «قُرئت» إلى العميل — العلامتان الزرقاوان عنده.
     *
     * جهدٌ لا وعد: إن سقط لم يسقط معه شيء.
     */
    public function markRead(string $providerMessageId): void
    {
        if (! $this->configured() || $providerMessageId === '') {
            return;
        }

        try {
            Http::withToken((string) config('services.whatsapp.token'))->acceptJson()->timeout(5)
                ->post($this->endpoint(), ['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $providerMessageId]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * ملفٌّ أرسله العميل — معرّفُه عند Meta يُسأل عن رابطه، والرابطُ يُنزَّل بالرمز نفسه.
     *
     * @return array{body: string, mime: string}|null
     */
    public function download(string $mediaId): ?array
    {
        if (! $this->configured() || $mediaId === '') {
            return null;
        }

        try {
            $meta = Http::withToken((string) config('services.whatsapp.token'))->acceptJson()->timeout(self::TIMEOUT)
                ->get(sprintf('https://graph.facebook.com/%s/%s', $this->version(), rawurlencode($mediaId)));

            $url = (string) $meta->json('url');
            if (! $meta->successful() || $url === '') {
                return null;
            }

            $file = Http::withToken((string) config('services.whatsapp.token'))->timeout(60)->get($url);

            return $file->successful()
                ? ['body' => $file->body(), 'mime' => (string) ($meta->json('mime_type') ?: $file->header('Content-Type'))]
                : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** الطلبُ نفسُه — ونتيجتُه بلا رمزٍ في نصّ الخطأ */
    private function post(array $body): MessageResult
    {
        try {
            $response = Http::withToken((string) config('services.whatsapp.token'))
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->post($this->endpoint(), $body);
        } catch (ConnectionException $e) {
            report($e);

            return MessageResult::failed(__('تعذّر الوصول إلى واتساب — :reason', ['reason' => $this->safe($e->getMessage())]));
        } catch (Throwable $e) {
            report($e);

            return MessageResult::failed(__('تعذّر إرسال واتساب — :reason', ['reason' => $this->safe($e->getMessage())]));
        }

        if (! $response->successful()) {
            $error = $response->json('error') ?? [];
            $reason = trim(((string) ($error['code'] ?? $response->status())).' — '.((string) ($error['message'] ?? __('رفض المزوّد الطلب'))));

            return MessageResult::failed($this->safe($reason));
        }

        $id = $response->json('messages.0.id');

        if (! filled($id)) {
            return MessageResult::failed(__('قبل المزوّد الطلب ولم يُعِد معرّفًا للرسالة.'));
        }

        return MessageResult::ok((string) $id);
    }

    /** عنوانُ الإرسال — إصدارُ الواجهة ومعرّفُ الرقم من الإعداد */
    public function endpoint(): string
    {
        return sprintf('https://graph.facebook.com/%s/%s/messages',
            $this->version(),
            (string) config('services.whatsapp.phone_number_id'),
        );
    }

    private function version(): string
    {
        return trim((string) config('services.whatsapp.api_version', 'v21.0'), '/');
    }

    /** نصُّ خطأٍ بلا رمز: لو ورد الرمزُ في رسالةٍ ما، لا يبلغ الشاشةَ ولا القيد */
    private function safe(string $message): string
    {
        $token = (string) config('services.whatsapp.token');

        return mb_substr($token !== '' ? str_replace($token, '•••', $message) : $message, 0, 500);
    }

    private function done(MessageIntent $intent, MessageResult $result): MessageResult
    {
        $communication = Communications::record($this->channel(), $this->provider(), $intent, $result);

        /* ورسالةُ القالب تظهر في محادثة العميل أيضًا — كما يراها هو */
        if ($communication !== null) {
            Inbox::mirror($communication);
        }

        return $result;
    }
}
