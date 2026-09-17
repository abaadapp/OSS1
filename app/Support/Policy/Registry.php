<?php

namespace App\Support\Policy;

/**
 * سياساتُ الشركة — قائمةٌ مغلقةٌ في الكود.
 *
 * ═══ لا Hard-code، ولا محرّكُ قواعد ═══
 *
 * على نمط `CustomAlert::METRICS` حرفًا: كلُّ مفتاحٍ هنا يقابله — إن كان له
 * فحصٌ — صنفٌ مكتوبٌ في PHP، «فلا يصل شيءٌ من إدخال المستخدم إلى قاعدة
 * البيانات كشرط». والمديرُ يضبط **المعاملاتِ والنصَّ** من الإعدادات، ولا
 * يكتب شرطًا.
 *
 * ═══ وفحصٌ واحدٌ اليوم ═══
 *
 * `lead_time_days` وحدَها لها فحص. والبقيّةُ **نصٌّ يُختم** في العرض
 * ويقرؤه العميلُ ويقبله — وهو الجزءُ الأهمّ (§18): نصُّ الإلغاء الذي قَبِله
 * العميلُ يُثبت الحقَّ، وفحصٌ آليٌّ للإلغاء لا يُثبت شيئًا. والفحوصُ تُضاف
 * صنفًا صنفًا حين تلزم.
 *
 * ═══ وما ليس هنا عمدًا ═══
 *
 * `discount_threshold`: بُني في المرحلة 2 إعدادًا (`quotation_discount_percent`)
 * لأنّه رقمٌ يحكم الكتابةَ لا نصٌّ يراه العميل. ولا يُكرَّر هنا.
 *
 * ═══ والمعاملات ═══
 *
 * لكلّ مفتاحٍ أسماءُ معاملاته وأنواعُها: `integer` أيّام، و`percent`
 * نسبة. ومنها تُبنى قواعدُ التحقّق وحقولُ الشاشة معًا، فلا يُضاف معاملٌ
 * في موضعٍ ويُنسى في آخر.
 */
class Registry
{
    public const INTEGER = 'integer';

    public const PERCENT = 'percent';

    /**
     * @var array<string, array{label: string, hint: string, params: array<string, string>, check: class-string<Check>|null, text: string}>
     */
    public const KEYS = [
        'lead_time_days' => [
            'label' => 'مهلة الطلب',
            'hint' => 'أقلّ عدد من الأيام بين تاريخ العرض وبدء الفعالية — وما دونه طلب عاجل',
            'params' => ['days' => self::INTEGER, 'surcharge_percent' => self::PERCENT],
            'check' => LeadTimeCheck::class,
            'text' => 'تُقبل الطلبات قبل موعد الفعالية بسبعة أيام على الأقل. وما دون ذلك يُعامل طلبًا عاجلًا يخضع لرسوم استعجال ويُنفَّذ بحسب توفر الفريق والمعدات.',
        ],
        'cancellation_window' => [
            'label' => 'الإلغاء',
            'hint' => 'ما يُحتسب على العميل إن ألغى قبل الموعد',
            'params' => ['days' => self::INTEGER, 'fee_percent' => self::PERCENT],
            'check' => null,
            'text' => 'يُقبل الإلغاء بلا رسوم قبل موعد الفعالية بأربعة عشر يومًا على الأقل. وما دون ذلك تُحتسب نسبة من قيمة العرض مقابل ما حُجز من فريق ومعدات.',
        ],
        'postponement_window' => [
            'label' => 'التأجيل',
            'hint' => 'متى يُقبل تأجيل الموعد وبأي شرط',
            'params' => ['days' => self::INTEGER],
            'check' => null,
            'text' => 'يُقبل تأجيل الموعد مرة واحدة إذا طُلب قبل الفعالية بسبعة أيام على الأقل، وبحسب توفر الموعد البديل.',
        ],
        'payment_terms' => [
            'label' => 'شروط الدفع',
            'hint' => 'الدفعة المقدَّمة والسداد',
            'params' => ['advance_percent' => self::PERCENT, 'days' => self::INTEGER],
            'check' => null,
            'text' => 'تُدفع دفعة مقدَّمة عند قبول العرض، ويُسدَّد الباقي خلال ثلاثين يومًا من تاريخ الفاتورة.',
        ],
        'general_terms' => [
            'label' => 'شروط عامة',
            'hint' => 'ما تُريد أن يقرأه العميل على كل عرض',
            'params' => [],
            'check' => null,
            'text' => 'الأسعار شاملة ما ذُكر في نطاق العمل فقط، وكل طلب خارجه يُسعَّر على حدة. وتلتزم الشركة بتنفيذ ما ورد في العرض بعد قبوله كتابيًا.',
        ],
    ];

    public static function known(string $key): bool
    {
        return array_key_exists($key, self::KEYS);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::KEYS);
    }

    public static function label(string $key): string
    {
        return __(self::KEYS[$key]['label'] ?? $key);
    }

    /** @return array<string, string> اسمُ المعامل ⇒ نوعُه */
    public static function params(string $key): array
    {
        return self::KEYS[$key]['params'] ?? [];
    }

    /** الفحصُ إن كان له — وnull لسياسةٍ نصّيّة */
    public static function check(string $key): ?Check
    {
        $class = self::KEYS[$key]['check'] ?? null;

        return $class === null ? null : new $class;
    }

    /**
     * القائمةُ كما تقرؤها الشاشة — بعناوينها ومعاملاتها ونصّها المقترَح.
     *
     * @return list<array<string, mixed>>
     */
    public static function catalog(): array
    {
        return array_map(fn (string $key) => [
            'key' => $key,
            'label' => self::label($key),
            'hint' => __(self::KEYS[$key]['hint']),
            'params' => self::KEYS[$key]['params'],
            'checked' => self::KEYS[$key]['check'] !== null,
            'text' => __(self::KEYS[$key]['text']),
        ], self::keys());
    }
}
