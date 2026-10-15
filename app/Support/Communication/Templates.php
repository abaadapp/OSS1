<?php

namespace App\Support\Communication;

use App\Models\Communication;
use App\Models\CommunicationTemplate;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * كتالوجُ الرسائل — ما يعرف النظامُ أن يقوله، وبأيّ متغيّرات.
 *
 * ═══ الكتالوج في الكود، والقرارُ في القاعدة ═══
 *
 * مفاتيحُ القوالب هنا هي مفاتيحُ `MessageIntent::templateKey` نفسُها التي
 * تُرسل بها المراحلُ 3 و4 و5 بريدَها — فلا يُعاد بناءُ البريد: يبقى في
 * `Quotations::email` وأخواتها، ويُسمّى هنا ليُعرض ويُعطَّل ويُقاس.
 *
 * وما تقرّره الشركةُ في قالبٍ — اسمُ قالب واتساب المعتمَد، ولغتُه، وحالُه،
 * وترتيبُ متغيّراته — صفٌّ في `communication_templates`. ومن لا صفَّ له
 * يُقرأ من الكتالوج على افتراضيّه: البريدُ فعّالٌ، وواتساب بلا اسمٍ معتمَد
 * (فلا يُرسَل حتى يُضبط).
 *
 * ═══ والمتغيّرات بالاسم لا بالرقم ═══
 *
 * Meta تريد `{{1}} {{2}}`، والقالبُ عندنا يقول `variables: [customer_name,
 * label, url]` بترتيبه — فالأوّلُ يملأ الأولى. ومن غيّر ترتيبَ خانات
 * قالبه عند Meta يغيّر الترتيبَ هنا ولا يمسّ الكود.
 */
final class Templates
{
    public const PURPOSES = ['quotation', 'scope', 'change_order', 'delivery', 'invoice', 'project', 'event', 'notice'];

    /**
     * ما يُقال عن كلّ مستند.
     *
     * `internal`: خبرٌ إلى الشركة نفسِها (قبل العميل، رفض العميل) — يُسجَّل
     * كغيره ويُعلَّم في الشاشة، ولا يُعرض في نافذة «أرسل للعميل».
     *
     * @var array<string, array{name: string, subject: string, purpose: string, channels: list<string>, variables: list<string>, internal?: bool, body?: string, title?: string}>
     */
    public const CATALOG = [
        'quotation.link' => [
            'name' => 'رابط عرض السعر', 'subject' => 'quotation', 'purpose' => 'quotation',
            'channels' => ['email', 'whatsapp'],
            'variables' => ['contact_name', 'customer_name', 'label', 'title', 'total', 'valid_until', 'url', 'company'],
            'body' => "السيد/ة {{contact_name}} المحترم/ة،\nيسرّنا أن نرفق عرض السعر {{label}} الخاص بـ«{{title}}» بقيمة {{total}} — وهو صالح حتى {{valid_until}}.\n{{url}}",
        ],
        'quotation.reminder' => [
            'name' => 'تذكير بعرض السعر', 'subject' => 'quotation', 'purpose' => 'quotation',
            'channels' => ['email', 'whatsapp'],
            'variables' => ['contact_name', 'customer_name', 'label', 'title', 'valid_until', 'url', 'company'],
            'body' => "السيد/ة {{contact_name}} المحترم/ة،\nنذكّركم بعرض السعر {{label}} الخاص بـ«{{title}}» — وهو صالح حتى {{valid_until}}.\n{{url}}",
        ],
        'quotation.receipt' => [
            'name' => 'إيصال قبول العرض', 'subject' => 'quotation', 'purpose' => 'quotation',
            'channels' => ['email'], 'variables' => ['contact_name', 'label', 'title', 'url', 'company'],
        ],
        'quotation.accepted' => [
            'name' => 'خبر قبول العرض (إلى الشركة)', 'subject' => 'quotation', 'purpose' => 'notice',
            'channels' => ['email'], 'variables' => ['label', 'customer_name'], 'internal' => true,
        ],
        'quotation.declined' => [
            'name' => 'خبر رفض العرض (إلى الشركة)', 'subject' => 'quotation', 'purpose' => 'notice',
            'channels' => ['email'], 'variables' => ['label', 'customer_name'], 'internal' => true,
        ],
        /* نطاقُ العمل — رابطُ توقيعه وتذكيرٌ به، وخبرُ القرار إلى الشركة */
        'scope.link' => [
            'name' => 'رابط نطاق العمل للتوقيع', 'subject' => 'scope_of_work', 'purpose' => 'scope',
            'channels' => ['email', 'whatsapp'],
            'variables' => ['contact_name', 'customer_name', 'label', 'project', 'event_date', 'url', 'company'],
            'title' => 'نطاق العمل {{label}} — {{company}}',
            'body' => "السيد/ة {{contact_name}} المحترم/ة،\nنرفق نطاق العمل {{label}} لمشروع «{{project}}» للمراجعة والاعتماد الإلكتروني قبل بدء التنفيذ.\n{{url}}",
        ],
        'scope.reminder' => [
            'name' => 'تذكير بتوقيع نطاق العمل', 'subject' => 'scope_of_work', 'purpose' => 'scope',
            'channels' => ['email', 'whatsapp'],
            'variables' => ['contact_name', 'customer_name', 'label', 'project', 'url', 'company'],
            'title' => 'تذكير: نطاق العمل {{label}} — {{company}}',
            'body' => "السيد/ة {{contact_name}} المحترم/ة،\nنذكّركم بنطاق العمل {{label}} لمشروع «{{project}}» — بانتظار اعتمادكم لنبدأ التنفيذ.\n{{url}}",
        ],
        'scope.signed' => [
            'name' => 'خبر توقيع نطاق العمل (إلى الشركة)', 'subject' => 'scope_of_work', 'purpose' => 'notice',
            'channels' => ['email'], 'variables' => ['label', 'customer_name'], 'internal' => true,
        ],
        'scope.declined' => [
            'name' => 'خبر طلب تعديل نطاق العمل (إلى الشركة)', 'subject' => 'scope_of_work', 'purpose' => 'notice',
            'channels' => ['email'], 'variables' => ['label', 'customer_name'], 'internal' => true,
        ],
        'change_order.link' => [
            'name' => 'رابط أمر التغيير', 'subject' => 'change_order', 'purpose' => 'change_order',
            'channels' => ['email', 'whatsapp'],
            'variables' => ['contact_name', 'customer_name', 'label', 'title', 'project', 'total_delta', 'url', 'company'],
            'body' => "تحية طيبة،\nنرفق أمر التغيير {{label}} على مشروع «{{project}}» — {{title}}.\n{{url}}",
        ],
        'change_order.reminder' => [
            'name' => 'تذكير بأمر التغيير', 'subject' => 'change_order', 'purpose' => 'change_order',
            'channels' => ['email', 'whatsapp'],
            'variables' => ['contact_name', 'customer_name', 'label', 'title', 'project', 'url', 'company'],
            'body' => "تحية طيبة،\nنذكّركم بأمر التغيير {{label}} على مشروع «{{project}}» — بانتظار قراركم.\n{{url}}",
        ],
        'change_order.accepted' => [
            'name' => 'خبر قبول أمر التغيير (إلى الشركة)', 'subject' => 'change_order', 'purpose' => 'notice',
            'channels' => ['email'], 'variables' => ['label', 'customer_name'], 'internal' => true,
        ],
        'change_order.declined' => [
            'name' => 'خبر رفض أمر التغيير (إلى الشركة)', 'subject' => 'change_order', 'purpose' => 'notice',
            'channels' => ['email'], 'variables' => ['label', 'customer_name'], 'internal' => true,
        ],
        'delivery.link' => [
            'name' => 'رابط حزمة التسليم', 'subject' => 'delivery_package', 'purpose' => 'delivery',
            'channels' => ['email', 'whatsapp'],
            'variables' => ['contact_name', 'customer_name', 'label', 'title', 'project', 'url', 'company'],
            'body' => "تحية طيبة،\nيسرّنا إبلاغكم بإتمام «{{title}}». تجدون في الرابط أدناه حزمة التسليم {{label}} بمخرجاتها وملفّاتها.\n{{url}}",
        ],
        'delivery.reminder' => [
            'name' => 'تذكير بحزمة التسليم', 'subject' => 'delivery_package', 'purpose' => 'delivery',
            'channels' => ['email', 'whatsapp'],
            'variables' => ['contact_name', 'customer_name', 'label', 'title', 'url', 'company'],
            'body' => "تحية طيبة،\nنذكّركم بحزمة التسليم {{label}} الخاصة بـ«{{title}}» — بانتظار اعتمادكم.\n{{url}}",
        ],
        'delivery.accepted' => [
            'name' => 'خبر قبول التسليم (إلى الشركة)', 'subject' => 'delivery_package', 'purpose' => 'notice',
            'channels' => ['email'], 'variables' => ['label', 'customer_name'], 'internal' => true,
        ],
        'delivery.declined' => [
            'name' => 'خبر رفض التسليم (إلى الشركة)', 'subject' => 'delivery_package', 'purpose' => 'notice',
            'channels' => ['email'], 'variables' => ['label', 'customer_name'], 'internal' => true,
        ],
        'invoice.link' => [
            'name' => 'رابط الفاتورة', 'subject' => 'customer_invoice', 'purpose' => 'invoice',
            'channels' => ['email', 'whatsapp'],
            'variables' => ['contact_name', 'customer_name', 'number', 'total', 'due_at', 'url', 'company'],
            'title' => 'الفاتورة {{number}} — {{company}}',
            'body' => "تحية طيبة،\nنرفق الفاتورة رقم {{number}} بقيمة {{total}} — وموعد سدادها {{due_at}}.\n{{url}}",
        ],
        'invoice.reminder' => [
            'name' => 'تذكير بالسداد', 'subject' => 'customer_invoice', 'purpose' => 'invoice',
            'channels' => ['email', 'whatsapp'],
            'variables' => ['contact_name', 'customer_name', 'number', 'outstanding', 'due_at', 'url', 'company'],
            'title' => 'تذكير بسداد الفاتورة {{number}} — {{company}}',
            'body' => "تحية طيبة،\nنذكّركم بأن الفاتورة رقم {{number}} ما زال منها {{outstanding}} غير مسدَّد، وموعد سدادها {{due_at}}.\n{{url}}",
        ],
        'project.update' => [
            'name' => 'بيان المشروع', 'subject' => 'project', 'purpose' => 'project',
            'channels' => ['email', 'whatsapp'],
            'variables' => ['contact_name', 'customer_name', 'code', 'name', 'starts_at', 'ends_at', 'location', 'owner', 'note', 'company'],
            'title' => 'مشروع {{name}} — {{company}}',
            'body' => "تحية طيبة،\nبخصوص مشروع «{{name}}» ({{code}}) المقرَّر من {{starts_at}} إلى {{ends_at}} في {{location}}:\n{{note}}",
        ],
        'event.reminder' => [
            'name' => 'تذكير بموعد الفعالية', 'subject' => 'project', 'purpose' => 'event',
            'channels' => ['email', 'whatsapp'],
            'variables' => ['contact_name', 'customer_name', 'name', 'starts_at', 'location', 'owner', 'note', 'company'],
            'title' => 'تذكير بموعد {{name}} — {{company}}',
            'body' => "تحية طيبة،\nنذكّركم بموعد فعالية «{{name}}» يوم {{starts_at}} في {{location}}. مسؤول المشروع لديكم: {{owner}}.\n{{note}}",
        ],
        /* والأتمتةُ — المرحلة 8 — تُبرّق للموظّفين من الباب نفسِه: داخليّةٌ لا تُرسَل للعميل */
        'automation.notice' => [
            'name' => 'تنبيه داخلي من الأتمتة', 'subject' => 'project', 'purpose' => 'notice',
            'channels' => ['email'], 'variables' => [], 'internal' => true,
        ],
        'automation.brief' => [
            'name' => 'الموجز الصباحي', 'subject' => 'project', 'purpose' => 'notice',
            'channels' => ['email'], 'variables' => [], 'internal' => true,
        ],
        /* والتشغيلُ — المرحلة 10: تعيينُ الفريق وتنبيهٌ تشغيليّ — داخليّان عن المشروع، لا يُرسَلان للعميل */
        'operation.crew_assignment' => [
            'name' => 'تعيين في فريق فعاليّة (إلى الموظّف)', 'subject' => 'project', 'purpose' => 'notice',
            'channels' => ['email'], 'variables' => [], 'internal' => true,
        ],
        'operation.alert' => [
            'name' => 'تنبيه تشغيلي (إلى الفريق)', 'subject' => 'project', 'purpose' => 'notice',
            'channels' => ['email'], 'variables' => [], 'internal' => true,
        ],
    ];

    /** وصفُ قالبٍ من الكتالوج — أو استثناءٌ لمفتاحٍ لا يُعرف */
    public static function describe(string $key): array
    {
        if (! isset(self::CATALOG[$key])) {
            throw new RuntimeException(__('لا قالب بهذا الاسم.'));
        }

        return self::CATALOG[$key] + ['key' => $key, 'internal' => false, 'body' => null, 'title' => null];
    }

    public static function exists(string $key): bool
    {
        return isset(self::CATALOG[$key]);
    }

    /** ما يُعرض في نافذة «أرسل للعميل» عن هذا المستند — بلا الأخبار الداخليّة */
    public static function forSubject(Model $subject, ?string $channel = null): array
    {
        $type = Subjects::keyOf($subject);

        return collect(self::CATALOG)
            ->filter(fn ($t) => $t['subject'] === $type && empty($t['internal'])
                && ($channel === null || in_array($channel, $t['channels'], true)))
            ->map(fn ($t, $k) => ['key' => $k, 'name' => __($t['name']), 'channels' => $t['channels'], 'purpose' => $t['purpose']])
            ->values()
            ->all();
    }

    /** هل يصلح هذا القالبُ لهذا المستند وهذه القناة؟ */
    public static function assertFits(string $key, Model $subject, string $channel): void
    {
        $t = self::describe($key);

        if ($t['subject'] !== Subjects::keyOf($subject) || ! empty($t['internal'])) {
            throw new RuntimeException(__('هذا القالب لا يُرسَل عن هذا المستند.'));
        }

        if (! in_array($channel, $t['channels'], true)) {
            throw new RuntimeException(__('هذا القالب لا يُرسَل عبر هذه القناة.'));
        }
    }

    /**
     * القالبُ كما تراه الشركة: صفُّها إن كُتب، وإلّا افتراضيُّ الكتالوج.
     *
     * والافتراضيُّ غيرُ محفوظ (`exists === false`): البريدُ فعّالٌ به،
     * وواتساب بلا اسمٍ معتمَد فلا يُرسَل حتى يُضبط.
     */
    public static function resolve(int $businessId, string $key, string $channel, string $language = 'ar'): CommunicationTemplate
    {
        $catalog = self::describe($key);

        $row = CommunicationTemplate::where('business_id', $businessId)
            ->where('key', $key)->where('channel', $channel)->where('language', $language)->first();

        if ($row !== null) {
            return $row;
        }

        return new CommunicationTemplate([
            'business_id' => $businessId,
            'key' => $key,
            'channel' => $channel,
            'name' => $catalog['name'],
            'purpose' => $catalog['purpose'],
            'language' => $language,
            'status' => CommunicationTemplate::ACTIVE,
            'provider_template' => null,
            'provider_language' => null,
            'variables' => $catalog['variables'],
            'subject' => null,
            'body' => null,
        ]);
    }

    /** قالبُ واتساب الجاهزُ للإرسال — أو null إن لم يُضبط أو أُوقف */
    public static function whatsapp(int $businessId, string $key, string $language = 'ar'): ?CommunicationTemplate
    {
        if (! self::exists($key)) {
            return null;
        }

        $t = self::resolve($businessId, $key, Communication::WHATSAPP, $language);

        return $t->isSendable() ? $t : null;
    }

    /**
     * كلُّ قوالب الشركة — الكتالوجُ كاملًا، وما كُتب فوقه.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(int $businessId): array
    {
        $rows = CommunicationTemplate::where('business_id', $businessId)->get()
            ->keyBy(fn ($r) => $r->key.'|'.$r->channel.'|'.$r->language);

        $out = [];

        foreach (self::CATALOG as $key => $t) {
            foreach ($t['channels'] as $channel) {
                foreach (CommunicationTemplate::LANGUAGES as $language) {
                    $row = $rows[$key.'|'.$channel.'|'.$language] ?? null;
                    $resolved = $row ?? self::resolve($businessId, $key, $channel, $language);

                    $out[] = [
                        'id' => $row?->id,
                        'key' => $key,
                        'channel' => $channel,
                        'language' => $language,
                        'name' => $row?->name ?? __($t['name']),
                        'catalog_name' => __($t['name']),
                        'purpose' => $t['purpose'],
                        'subject_type' => $t['subject'],
                        'internal' => ! empty($t['internal']),
                        'status' => $resolved->status,
                        'sendable' => $resolved->isSendable(),
                        'provider_template' => $resolved->provider_template,
                        'provider_language' => $resolved->provider_language,
                        'variables' => $resolved->variables ?: $t['variables'],
                        'allowed_variables' => $t['variables'],
                        'subject' => $resolved->subject,
                        'body' => $resolved->body,
                        'default_body' => isset($t['body']) ? __($t['body']) : null,
                        'customized' => $row !== null,
                        'updated_at' => $row?->updated_at?->format('Y-m-d H:i'),
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * حفظُ قرار الشركة في قالب — ويُقيَّد في الأثر.
     *
     * @param  array{name?: string, status?: string, provider_template?: ?string, provider_language?: ?string, variables?: ?list<string>, subject?: ?string, body?: ?string}  $data
     */
    public static function save(int $businessId, string $key, string $channel, string $language, array $data, ?User $actor = null): CommunicationTemplate
    {
        $catalog = self::describe($key);

        if (! in_array($channel, $catalog['channels'], true)) {
            throw new RuntimeException(__('هذا القالب لا يُرسَل عبر هذه القناة.'));
        }

        if (! in_array($language, CommunicationTemplate::LANGUAGES, true)) {
            throw new RuntimeException(__('لغةٌ غير مدعومة.'));
        }

        $status = $data['status'] ?? CommunicationTemplate::ACTIVE;
        if (! in_array($status, CommunicationTemplate::STATUSES, true)) {
            throw new RuntimeException(__('حالةٌ غير معروفة.'));
        }

        /* والمتغيّراتُ ممّا يعرفه الكتالوج وحدَه — اسمٌ غريب لا يُملأ أبدًا */
        $variables = array_values(array_filter((array) ($data['variables'] ?? $catalog['variables']),
            fn ($v) => in_array($v, $catalog['variables'], true)));

        $template = CommunicationTemplate::updateOrCreate(
            ['business_id' => $businessId, 'key' => $key, 'channel' => $channel, 'language' => $language],
            [
                'name' => trim((string) ($data['name'] ?? '')) ?: __($catalog['name']),
                'purpose' => $catalog['purpose'],
                'status' => $status,
                'provider_template' => filled($data['provider_template'] ?? null) ? trim((string) $data['provider_template']) : null,
                'provider_language' => filled($data['provider_language'] ?? null) ? trim((string) $data['provider_language']) : null,
                'variables' => $variables ?: $catalog['variables'],
                'subject' => filled($data['subject'] ?? null) ? trim((string) $data['subject']) : null,
                'body' => filled($data['body'] ?? null) ? trim((string) $data['body']) : null,
                'updated_by' => $actor?->id,
            ],
        );

        Activity::log('settings', 'عدّل قالب المراسلات «'.$template->name.'» ('.$channel.' · '.$language.') — الحالة: '.$status, [
            'business_id' => $businessId, 'subject_id' => $template->id, 'subject_type' => 'communication_template', 'icon' => 'message-square',
        ]);

        return $template;
    }

    /**
     * ملءُ القالب — `{{name}}` بقيمته، و`{{1}}` بترتيب المتغيّرات.
     *
     * @param  array<string, string>  $vars
     * @param  list<string>|null  $order
     */
    public static function render(?string $body, array $vars, ?array $order = null): string
    {
        if ($body === null || $body === '') {
            return '';
        }

        $order = $order ?? array_keys($vars);

        return (string) preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/u', function ($m) use ($vars, $order) {
            $name = $m[1];
            if (ctype_digit($name)) {
                $name = $order[(int) $name - 1] ?? '';
            }

            return (string) ($vars[$name] ?? '');
        }, $body);
    }

    /**
     * قيمُ الخانات بترتيب القالب — للواتساب.
     *
     * @param  array<string, string>  $vars
     * @return list<string>
     */
    public static function parameters(CommunicationTemplate $template, array $vars): array
    {
        $catalog = self::describe($template->key);
        $order = $template->variables ?: $catalog['variables'];

        /* وخانةٌ فارغة ترفضها Meta — فتُملأ بشرطة لا بفراغ */
        return array_map(fn ($name) => ($vars[$name] ?? '') !== '' ? (string) $vars[$name] : '—', array_values($order));
    }

    /**
     * قيمٌ للمعاينة — ليرى من يضبط القالبَ شكلَه قبل أن يُرسَل به.
     *
     * @return array<string, string>
     */
    public static function sample(): array
    {
        return [
            'contact_name' => __('سالم'), 'customer_name' => __('وزارة الطاقة'), 'label' => 'Q-000012',
            'title' => __('مؤتمر الطاقة'), 'total' => '1,050.000', 'total_delta' => '+250.000', 'valid_until' => '2026-10-15',
            'event_date' => '2026-11-02', 'url' => 'https://hadath.om/i/xxxxxxxxxxxxxxxxxxxxxx', 'company' => __('افنت بلس'),
            'project' => __('مؤتمر الطاقة'), 'number' => 'INV-000034', 'outstanding' => '500.000', 'issued_at' => '2026-09-01',
            'due_at' => '2026-10-01', 'code' => 'PRJ-000007', 'name' => __('مؤتمر الطاقة'), 'starts_at' => '2026-11-02',
            'ends_at' => '2026-11-04', 'location' => __('مركز عُمان للمؤتمرات'), 'owner' => __('أحمد'), 'note' => __('نرجو تأكيد الحضور'),
        ];
    }
}
