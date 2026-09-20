<?php

namespace App\Support;

/**
 * أعمدةُ كلّ تقرير كما تُصدَّر — مصدرٌ واحد للملفّات الثلاثة.
 *
 * ولولا موضعٌ واحد لَكتب كلُّ صيغةٍ أعمدتَها بنفسها: تخرج ورقةُ إكسل بسبعة
 * أعمدة وملفُّ CSV بخمسة عن التقرير الواحد، ويُقارَن الملفّان فلا يتّفقان —
 * ولا أحد يعرف أيُّهما الصحيح.
 *
 * و`kind` يقول كيف تُكتب الخليّة لا كيف تبدو: المبلغ رقمٌ يُجمع في خليّة لا
 * نصٌّ منسَّق، وإلّا خرجت الورقة بأعمدةٍ لا تُجمع — وهو أوّل ما يفعله من
 * يفتحها.
 */
class ReportColumns
{
    /**
     * @var array<string, list<array{0: string, 1: string, 2: string}>>
     *                                                                  [مفتاح الحقل، العنوان، النوع: money|number|text]
     */
    public const MAP = [
        'finance' => [
            ['at', 'التاريخ', 'text'], ['reference', 'السند', 'text'], ['description', 'البيان', 'text'],
            ['method', 'الوسيلة', 'text'], ['type', 'النوع', 'text'], ['amount', 'المبلغ', 'money'],
        ],
        'expenses' => [
            ['at', 'التاريخ', 'text'], ['type', 'النوع', 'text'], ['description', 'البيان', 'text'],
            ['method', 'الوسيلة', 'text'], ['status', 'الحالة', 'text'], ['amount', 'المبلغ', 'money'],
        ],
        'bank' => [
            ['at', 'التاريخ', 'text'], ['description', 'البيان', 'text'], ['reference', 'المرجع', 'text'],
            ['status', 'المطابقة', 'text'], ['amount', 'المبلغ', 'money'],
        ],
        'invoices' => [
            ['at', 'التاريخ', 'text'], ['number', 'رقم الفاتورة', 'text'], ['customer', 'العميل', 'text'],
            ['branch', 'الفرع', 'text'], ['due', 'الاستحقاق', 'text'], ['status', 'الحالة', 'text'],
            ['total', 'الإجمالي', 'money'], ['paid', 'المسدَّد', 'money'], ['outstanding', 'الباقي', 'money'],
        ],
        'products' => [
            ['name', 'المنتج', 'text'], ['category', 'القسم', 'text'], ['price', 'السعر', 'money'],
            ['quantity', 'الرصيد', 'number'], ['units', 'المُباع', 'number'],
            ['revenue', 'الإيراد', 'money'], ['profit', 'الربح', 'money'],
        ],
        'inventory' => [
            ['name', 'الصنف', 'text'], ['sku', 'الرمز', 'text'], ['category', 'القسم', 'text'],
            ['quantity', 'الرصيد', 'number'], ['alert', 'الحدّ الأدنى', 'number'],
            ['cost', 'التكلفة', 'money'], ['value', 'القيمة', 'money'],
        ],
        'stocktake' => [
            ['at', 'الوقت', 'text'], ['number', 'السند', 'text'], ['product', 'الصنف', 'text'],
            ['branch', 'الفرع', 'text'], ['reason', 'النتيجة', 'text'],
            ['delta', 'الفرق', 'number'], ['cost', 'التكلفة', 'money'], ['value', 'القيمة', 'money'],
        ],
        'purchases' => [
            ['at', 'التاريخ', 'text'], ['number', 'رقم الأمر', 'text'], ['supplier', 'المورّد', 'text'],
            ['status', 'الحالة', 'text'], ['received', 'الاستلام', 'text'], ['total', 'القيمة', 'money'],
        ],
        'suppliers' => [
            ['name', 'المورّد', 'text'], ['contact', 'مسؤول التواصل', 'text'], ['phone', 'الهاتف', 'text'],
            ['orders', 'عدد الأوامر', 'number'], ['total', 'إجمالي المشتريات', 'money'],
        ],
        'activity' => [
            ['at', 'الوقت', 'text'], ['user', 'المستخدم', 'text'],
            ['action', 'الإجراء', 'text'], ['description', 'التفصيل', 'text'],
        ],
        'vat' => [
            ['month', 'الشهر', 'text'], ['taxable', 'المبيعات الخاضعة', 'money'],
            ['output', 'ضريبة المخرجات', 'money'], ['purchases', 'المشتريات', 'money'],
            ['input', 'ضريبة المدخلات', 'money'], ['returns', 'المردودات', 'money'],
            ['due', 'الصافي المستحقّ', 'money'],
        ],
        'payments' => [
            ['name', 'الوسيلة', 'text'], ['total', 'الإجمالي', 'money'],
            ['count', 'عدد العمليات', 'number'], ['percent', 'النسبة', 'number'],
        ],
        'staff' => [
            ['name', 'الموظف', 'text'], ['role', 'الوظيفة', 'text'], ['branch', 'الفرع', 'text'],
            ['invoices', 'الفواتير', 'number'], ['sales', 'المبيعات', 'money'], ['status', 'الحالة', 'text'],
        ],
        'customers' => [
            ['name', 'العميل', 'text'], ['invoices', 'عدد الفواتير', 'number'], ['total', 'إجمالي الإنفاق', 'money'],
        ],
    ];

    /**
     * التقارير التي ليست جدولًا واحدًا — لكلٍّ منها أقسامٌ تُصدَّر معًا.
     *
     * وتحليلاتُ الهالك أوّلُها: شاشتُها ستُّ قراءاتٍ على الصفوف نفسها — بالصنف
     * وبالقسم وبالفرع وبالسبب وعبر الزمن ومقابل الاستهلاك. وتصديرُ واحدةٍ
     * منها وترْكُ خمسٍ يُخرج ملفًّا يقول أقلَّ ممّا على الشاشة، ومن يقارنه بها
     * يظنّ أنّ شيئًا سقط.
     *
     * @var array<string, array<string, list<array{0: string, 1: string, 2: string}>>>
     */
    public const SECTIONS = [
        'waste' => [
            'بالصنف' => [
                ['label', 'الصنف', 'text'], ['quantity', 'الكمية', 'number'], ['value', 'القيمة', 'money'],
            ],
            'بالقسم' => [
                ['label', 'القسم', 'text'], ['quantity', 'الكمية', 'number'], ['value', 'القيمة', 'money'],
            ],
            'بالفرع' => [
                ['label', 'الفرع', 'text'], ['quantity', 'الكمية', 'number'], ['value', 'القيمة', 'money'],
            ],
            'بالسبب' => [
                ['label', 'السبب', 'text'], ['quantity', 'الكمية', 'number'], ['value', 'القيمة', 'money'],
            ],
            'عبر الزمن' => [
                ['label', 'الشهر', 'text'], ['quantity', 'الكمية', 'number'], ['value', 'القيمة', 'money'],
            ],
            'مقابل الاستهلاك' => [
                ['label', 'الصنف', 'text'], ['consumed', 'المستهلَك', 'number'],
                ['waste', 'الهالك', 'number'], ['rate', 'النسبة %', 'number'], ['value', 'القيمة', 'money'],
            ],
        ],
        /* ─────────── تقاريرُ الإدارة — المرحلة 11 ─────────── */
        'management_monthly' => [
            'الملخّص التنفيذي' => [['metric', 'المؤشّر', 'text'], ['value', 'القيمة', 'money'], ['basis', 'الأساس', 'text']],
            'شهرًا بشهر' => [['month', 'الشهر', 'text'], ['revenue', 'الإيراد', 'money'], ['collections', 'التحصيل', 'money'], ['expenses', 'المصروفات', 'money'], ['direct_costs', 'التكلفة المباشرة', 'money'], ['projects', 'مشاريع', 'number']],
            'المصروفات بالنوع' => [['type', 'النوع', 'text'], ['total', 'المبلغ', 'money'], ['count', 'العدد', 'number']],
            'أعمار الذمم' => [['bucket', 'الفئة', 'text'], ['amount', 'المبلغ', 'money']],
            'أداء المشاريع' => [['code', 'الرمز', 'text'], ['name', 'المشروع', 'text'], ['customer', 'العميل', 'text'], ['contracted', 'المتعاقَد', 'money'], ['revenue', 'الإيراد', 'money'], ['collected', 'المحصَّل', 'money'], ['receivable', 'الذمّة', 'money'], ['direct_costs', 'التكلفة', 'money'], ['contribution', 'المساهمة', 'money'], ['margin', 'الهامش %', 'number'], ['completeness', 'الاكتمال', 'text']],
            'القمع التجاري' => [['stage', 'المرحلة', 'text'], ['count', 'العدد', 'number'], ['value', 'القيمة', 'money'], ['committed', 'ملتزَم', 'text']],
            'تركيز العملاء' => [['customer', 'العميل', 'text'], ['net', 'الإيراد', 'money'], ['percent', 'النسبة %', 'number']],
            'التشغيل' => [['metric', 'المؤشّر', 'text'], ['value', 'القيمة', 'number']],
            'المعدّات بالصنف' => [['category', 'الصنف', 'text'], ['assets', 'القطع', 'number'], ['deployed_days', 'أيّام الميدان', 'number'], ['utilization', 'الاستخدام %', 'number'], ['short_events', 'فعاليّات بنقص', 'number'], ['rented_events', 'فعاليّات بتأجير', 'number']],
            'الموازنة مقابل الفعلي' => [['account', 'الحساب', 'text'], ['kind', 'النوع', 'text'], ['planned', 'الموازنة', 'money'], ['actual', 'الفعلي', 'money'], ['variance', 'الانحراف', 'money'], ['reading', 'القراءة', 'text']],
            'التوقّع النقدي المعتمَد' => [['month', 'الشهر', 'text'], ['opening', 'الافتتاحي', 'money'], ['inflows', 'الداخل', 'money'], ['outflows', 'الخارج', 'money'], ['net', 'الصافي', 'money'], ['closing', 'الإقفال', 'money']],
            'التنبيهات' => [['severity', 'الشدّة', 'text'], ['title', 'التنبيه', 'text'], ['detail', 'التفصيل', 'text']],
        ],
        'project_profitability' => [
            'المشاريع' => [['code', 'الرمز', 'text'], ['name', 'المشروع', 'text'], ['customer', 'العميل', 'text'], ['contracted', 'المتعاقَد', 'money'], ['revenue', 'الإيراد', 'money'], ['collected', 'المحصَّل', 'money'], ['receivable', 'الذمّة', 'money'], ['direct_costs', 'التكلفة', 'money'], ['contribution', 'المساهمة', 'money'], ['margin', 'الهامش %', 'number'], ['completeness', 'الاكتمال', 'text']],
        ],
        'receivables_aging' => [
            'الأعمار' => [['bucket', 'الفئة', 'text'], ['amount', 'المبلغ', 'money']],
            'بالعميل' => [['customer', 'العميل', 'text'], ['outstanding', 'القائم', 'money'], ['overdue', 'المتأخّر', 'money'], ['oldest', 'أقدم فاتورة', 'text'], ['days', 'أيّام التأخّر', 'number']],
        ],
        'budget_vs_actual' => [
            'بالحساب' => [['account', 'الحساب', 'text'], ['kind', 'النوع', 'text'], ['bucket', 'المدّة', 'text'], ['planned', 'الموازنة', 'money'], ['actual', 'الفعلي', 'money'], ['variance', 'الانحراف', 'money'], ['variance_percent', 'الانحراف %', 'number'], ['reading', 'القراءة', 'text']],
        ],
        'cash_forecast' => [
            'الأشهر' => [['month', 'الشهر', 'text'], ['opening', 'الافتتاحي', 'money'], ['committed_in', 'داخل ملتزَم', 'money'], ['expected_in', 'داخل متوقَّع', 'money'], ['committed_out', 'خارج ملتزَم', 'money'], ['expected_out', 'خارج متوقَّع', 'money'], ['net', 'الصافي', 'money'], ['closing', 'الإقفال', 'money']],
            'البنود' => [['at', 'التاريخ', 'text'], ['label', 'البند', 'text'], ['category', 'الطبقة', 'text'], ['direction', 'الاتّجاه', 'text'], ['amount', 'المبلغ', 'money'], ['source', 'المصدر', 'text']],
        ],
        'customer_revenue' => [
            'العملاء' => [['customer', 'العميل', 'text'], ['quotations', 'عروض', 'number'], ['accepted', 'مقبولة', 'number'], ['invoiced', 'المفوتَر', 'money'], ['collected', 'المحصَّل', 'money'], ['outstanding', 'القائم', 'money'], ['overdue', 'المتأخّر', 'money'], ['projects', 'مشاريع', 'number']],
            'التركيز' => [['customer', 'العميل', 'text'], ['net', 'الإيراد', 'money'], ['percent', 'النسبة %', 'number']],
        ],
        'service_performance' => [
            'الأصناف' => [['name', 'الصنف', 'text'], ['quotations', 'عروض', 'number'], ['quoted_value', 'المعروض', 'money'], ['accepted_value', 'المقبول', 'money'], ['invoiced_value', 'المفوتَر', 'money'], ['projects', 'مشاريع', 'number'], ['average_price', 'متوسّط السعر', 'money'], ['discount', 'الخصم %', 'number'], ['contribution', 'المساهمة', 'money']],
        ],
        'asset_utilization' => [
            'القطع' => [['code', 'الرمز', 'text'], ['name', 'القطعة', 'text'], ['category', 'الصنف', 'text'], ['reserved_days', 'أيّام الحجز', 'number'], ['deployed_days', 'أيّام الميدان', 'number'], ['events', 'فعاليّات', 'number'], ['utilization', 'الاستخدام %', 'number'], ['status', 'الحالة', 'text']],
            'الأصناف' => [['category', 'الصنف', 'text'], ['assets', 'القطع', 'number'], ['deployed_days', 'أيّام الميدان', 'number'], ['utilization', 'الاستخدام %', 'number'], ['short_events', 'فعاليّات بنقص', 'number'], ['rented_events', 'فعاليّات بتأجير', 'number'], ['idle', 'قطع بلا استخدام', 'number']],
        ],
    ];

    /** هل هذا التقرير أقسامٌ لا جدولًا واحدًا؟ */
    public static function sectioned(string $report): bool
    {
        return array_key_exists($report, self::SECTIONS);
    }

    /** أسماءُ أقسام تقريرٍ متعدّد — بترتيب الشاشة */
    public static function sectionsOf(string $report): array
    {
        return array_keys(self::SECTIONS[$report] ?? []);
    }

    /** @return list<array{key: string, label: string, kind: string}> */
    public static function sectionColumns(string $report, string $section): array
    {
        return array_map(
            fn ($c) => ['key' => $c[0], 'label' => __($c[1]), 'kind' => $c[2]],
            self::SECTIONS[$report][$section] ?? [],
        );
    }

    /** صفٌّ واحد بترتيب أعمدة قسمٍ بعينه */
    public static function sectionCells(string $report, string $section, array $row): array
    {
        return self::write(self::sectionColumns($report, $section), $row);
    }

    /** هل يُصدَّر هذا التقرير من الباب العام؟ */
    public static function has(string $report): bool
    {
        return array_key_exists($report, self::MAP);
    }

    /** @return list<array{key: string, label: string, kind: string}> */
    public static function for(string $report): array
    {
        return array_map(
            fn ($c) => ['key' => $c[0], 'label' => __($c[1]), 'kind' => $c[2]],
            self::MAP[$report] ?? [],
        );
    }

    /** أسماء الأعمدة وحدها — لرأس الجدول */
    public static function headings(string $report): array
    {
        return array_column(self::for($report), 'label');
    }

    /**
     * صفٌّ واحد بترتيب الأعمدة.
     *
     * والفارغ يُكتب شرطةً لا فراغًا: خليّةٌ خاوية في ورقةٍ تُقرأ خطأً في
     * القراءة، ولا يُعرف أوقع فيها شيءٌ أم لا.
     */
    public static function cells(string $report, array $row): array
    {
        return self::write(self::for($report), $row);
    }

    /** كتابةُ صفٍّ بأعمدةٍ معطاة — موضعٌ واحد للجدول الواحد وللأقسام */
    private static function write(array $columns, array $row): array
    {
        return array_map(function ($c) use ($row) {
            $value = $row[$c['key']] ?? null;

            if ($c['kind'] === 'money' || $c['kind'] === 'number') {
                return $value === null ? 0 : $value;
            }

            return $value === null || $value === '' ? '—' : (string) $value;
        }, $columns);
    }
}
