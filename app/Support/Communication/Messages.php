<?php

namespace App\Support\Communication;

use App\Models\Business;
use App\Models\Communication;
use App\Models\CommunicationTemplate;
use App\Models\User;
use App\Support\Messaging\MessageIntent;
use Illuminate\Database\Eloquent\Model;

/**
 * من القالب والمستند إلى نيّةِ رسالة.
 *
 * ═══ ما يُبنى هنا ═══
 *
 * الرسائلُ التي لا خدمةَ لها قبل هذه المرحلة: رابطُ الفاتورة وتذكيرُ
 * سدادها، وبيانُ المشروع وتذكيرُ موعده — وكلُّ رسالة واتساب أيًّا كان
 * قالبُها (البريدُ القديم يبقى في خدمته؛ انظر `Communications::send`).
 *
 * ═══ واللغةُ لغةُ القالب ═══
 *
 * النصُّ العربيُّ يمرّ بـ`__()` فيخرج إنجليزيًّا حين تُختار الإنجليزيّة —
 * تُثبَّت لغةُ التطبيق مدّةَ البناء ثمّ تُعاد. وقالبٌ كتبته الشركةُ بنفسها
 * (`body`) يُملأ كما كُتب.
 */
final class Messages
{
    /**
     * @param  array{note?: ?string, language?: ?string, recipient_name?: ?string}  $opts
     */
    public static function intent(Model $subject, CommunicationTemplate $template, string $to, ?User $actor = null, array $opts = []): MessageIntent
    {
        $language = in_array($opts['language'] ?? null, CommunicationTemplate::LANGUAGES, true) ? $opts['language'] : 'ar';
        $previous = app()->getLocale();
        app()->setLocale($language);

        try {
            $catalog = Templates::describe($template->key);
            $business = Business::find((int) $subject->business_id);
            $vars = Subjects::variables($subject, ['note' => (string) ($opts['note'] ?? '')]);
            $order = $template->variables ?: $catalog['variables'];

            $title = Templates::render($template->subject ?: ($catalog['title'] !== null ? __($catalog['title']) : null), $vars, $order)
                ?: __(':name — :company', ['name' => $template->name ?: __($catalog['name']), 'company' => $business?->name ?? '']);

            $body = Templates::render($template->body ?: ($catalog['body'] !== null ? __($catalog['body']) : null), $vars, $order);

            /* والفقراتُ سطورُ النصّ — والرابطُ يُنزع منها إلى زرّه في البريد */
            $url = filled($vars['url'] ?? null) ? $vars['url'] : null;
            $paragraphs = array_values(array_filter(
                array_map('trim', preg_split('/\r?\n/', $body) ?: []),
                fn ($p) => $p !== '' && $p !== $url,
            ));

            if ($paragraphs === []) {
                $paragraphs = [$title];
            }

            return new MessageIntent(
                to: $to,
                subject: $title,
                paragraphs: $paragraphs,
                url: $url,
                action: $url !== null ? self::action($catalog['subject']) : null,
                templateKey: $template->key,
                senderName: $business?->name,
                replyTo: filled($actor?->email) ? $actor->email : (filled($business?->email) ? $business->email : null),
                footer: $business?->name,
                about: $subject,
                customerId: Subjects::customerIdOf($subject),
                businessId: (int) $subject->business_id,
                variables: $vars,
                language: $language,
                recipientName: filled($opts['recipient_name'] ?? null) ? (string) $opts['recipient_name'] : ($vars['contact_name'] ?: ($vars['customer_name'] ?: null)),
            );
        } finally {
            app()->setLocale($previous);
        }
    }

    /** نصُّ الزرّ في البريد — بحسب ما يُفتح */
    private static function action(string $subjectType): string
    {
        return match ($subjectType) {
            'quotation' => __('فتح عرض السعر'),
            'change_order' => __('فتح أمر التغيير'),
            'scope_of_work' => __('فتح نطاق العمل والتوقيع'),
            'delivery_package' => __('فتح حزمة التسليم'),
            'customer_invoice' => __('فتح الفاتورة'),
            default => __('فتح المستند'),
        };
    }

    /** معاينةُ القالب بقيمٍ نموذجيّة — أو بقيم مستندٍ بعينه */
    public static function preview(CommunicationTemplate $template, ?Model $subject = null, string $language = 'ar'): array
    {
        $previous = app()->getLocale();
        app()->setLocale(in_array($language, CommunicationTemplate::LANGUAGES, true) ? $language : 'ar');

        try {
            $catalog = Templates::describe($template->key);
            $vars = $subject !== null ? Subjects::variables($subject) : Templates::sample();
            $order = $template->variables ?: $catalog['variables'];

            return [
                'title' => Templates::render($template->subject ?: ($catalog['title'] !== null ? __($catalog['title']) : null), $vars, $order)
                    ?: __(':name — :company', ['name' => $template->name ?: __($catalog['name']), 'company' => $vars['company'] ?? '']),
                'body' => Templates::render($template->body ?: ($catalog['body'] !== null ? __($catalog['body']) : null), $vars, $order),
                'parameters' => $template->channel === Communication::WHATSAPP ? Templates::parameters($template, $vars) : [],
            ];
        } finally {
            app()->setLocale($previous);
        }
    }
}
