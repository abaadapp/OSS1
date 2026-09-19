<?php

namespace App\Support\Messaging;

use App\Support\Communication\Communications;
use App\Support\Communication\Templates;
use App\Support\PhoneNumber;
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

        $body = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $template->provider_template,
                'language' => ['code' => $template->provider_language ?: ($intent->language === 'en' ? 'en' : 'ar')],
                'components' => $parameters === [] ? [] : [[
                    'type' => 'body',
                    'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => $p], $parameters),
                ]],
            ],
        ];

        try {
            $response = Http::withToken((string) config('services.whatsapp.token'))
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->post($this->endpoint(), $body);
        } catch (ConnectionException $e) {
            report($e);

            return $this->done($intent, MessageResult::failed(__('تعذّر الوصول إلى واتساب — :reason', ['reason' => $this->safe($e->getMessage())])));
        } catch (Throwable $e) {
            report($e);

            return $this->done($intent, MessageResult::failed(__('تعذّر إرسال واتساب — :reason', ['reason' => $this->safe($e->getMessage())])));
        }

        if (! $response->successful()) {
            $error = $response->json('error') ?? [];
            $reason = trim(((string) ($error['code'] ?? $response->status())).' — '.((string) ($error['message'] ?? __('رفض المزوّد الطلب'))));

            return $this->done($intent, MessageResult::failed($this->safe($reason)));
        }

        $id = $response->json('messages.0.id');

        if (! filled($id)) {
            return $this->done($intent, MessageResult::failed(__('قبل المزوّد الطلب ولم يُعِد معرّفًا للرسالة.')));
        }

        return $this->done($intent, MessageResult::ok((string) $id));
    }

    /** عنوانُ الإرسال — إصدارُ الواجهة ومعرّفُ الرقم من الإعداد */
    public function endpoint(): string
    {
        return sprintf('https://graph.facebook.com/%s/%s/messages',
            trim((string) config('services.whatsapp.api_version', 'v21.0'), '/'),
            (string) config('services.whatsapp.phone_number_id'),
        );
    }

    /** نصُّ خطأٍ بلا رمز: لو ورد الرمزُ في رسالةٍ ما، لا يبلغ الشاشةَ ولا القيد */
    private function safe(string $message): string
    {
        $token = (string) config('services.whatsapp.token');

        return mb_substr($token !== '' ? str_replace($token, '•••', $message) : $message, 0, 500);
    }

    private function done(MessageIntent $intent, MessageResult $result): MessageResult
    {
        Communications::record($this->channel(), $this->provider(), $intent, $result);

        return $result;
    }
}
