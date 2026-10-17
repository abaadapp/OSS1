<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * فهرس التقارير — مصدرٌ واحد يقرؤه فهرس التقارير في اللوحة.
 *
 * كل بندٍ هنا تقريرٌ موجود فعلًا في النظام، لا اقتراحٌ ولا وعد: إمّا صفحةٌ
 * تفتحه (`route`) وإمّا مفتاحُ بياناتٍ يعرضه العارض (`data` — انظر
 * ReportDataController). ولا يُضاف بندٌ ثالث لا هذا ولا ذاك: بطاقةٌ تُعرض
 * ولا تُفتح أسوأ من بطاقةٍ لا تُعرض — التاجر يظنّ الميزة موجودة ويبني عليها.
 *
 * وكلٌّ يحمل `section` صلاحيته، فما لا يملكه المستخدم لا يظهر له أصلًا. بلا
 * ذلك كان الفهرس يعرض عشرين بطاقة لمحاسبٍ لا يفتح منها إلا ثمانيًا، فيصطدم
 * باثنتي عشرة ٤٠٣ — والقائمة الجانبية تُخفي ما لا يُملك منذ البداية، فيفترق
 * البابان على الشيء نفسه.
 */
class Reports
{
    /*
     * أُعيد هذا الفهرس بعد حذفه في d34f32e.
     *
     * وعاد منقوصًا عمدًا: ستّة بنودٍ كانت تقصد شاشاتٍ حُذفت معه — الربحية،
     * وضريبة القيمة المضافة، وإقفال الورديات الإداريّ، وحركات المخزون،
     * والتحليلات المتقدّمة، والمبيعات حسب القسم. وإعادتُها بطاقاتٍ تقود إلى
     * ٤٠٤ ليست إعادةً للقسم بل إعادةٌ لمظهره. وقاعدة هذا الملفّ لم تتغيّر:
     * كل بندٍ إمّا صفحةٌ قائمة وإمّا مفتاح بيانات — ولا ثالث.
     */

    /** تصنيفات الفهرس بترتيب عرضها */
    public const CATEGORIES = [
        'financial' => 'التقارير المالية',
        'operational' => 'التقارير التشغيلية',
        'analytical' => 'تقارير التحليلات',
        // تقاريرُ الإدارة — المرحلة 11: تُفتح من شاشة «الإدارة › التقارير» وتخرج من الباب العامّ للتنزيل
        'management' => 'تقارير الإدارة',
    ];

    /**
     * البنود. `icon` مفتاحٌ تترجمه الواجهة إلى أيقونة (خريطة صريحة في
     * Reports/Index.tsx — الاستيراد الشامل من lucide يضخّ المكتبة كاملة).
     */
    public const ALL = [
        /* ------------------------------ مالية ------------------------------ */
        [
            'key' => 'sales',
            'category' => 'financial',
            'section' => 'reports',
            'title' => 'ملخّص المبيعات',
            'desc' => 'المبيعات والأرباح والمصروفات والضريبة، ومنحنى السنة والأكثر مبيعًا.',
            'icon' => 'trending-up',
            'route' => 'admin.reports.sales',
        ],
        [
            'key' => 'finance',
            'category' => 'financial',
            'section' => 'finance',
            'title' => 'الحركة المالية',
            /*
             * الوصف يصف الوجهة لا الأمنية.
             *
             * كان يعد بـ«المقبوضات والمدفوعات وصافي الحركة في المدة المختارة»،
             * والوجهة شاشةُ الحسابات البنكية: أرصدةٌ لا حركة، وبلا مبدّل فترة.
             * والحركة نفسها كانت في ثلاثة مسارات تصديرٍ لا يقصدها زرّ — فوُصلت
             * بالشاشة، وصار الوصف يقول أين تُقرأ وأين تُنزَّل.
             */
            'desc' => 'أرصدة الحسابات البنكية، وتقرير المقبوضات والمدفوعات يُنزَّل من زرّ «الحركة المالية».',
            'icon' => 'wallet',
            'route' => 'admin.reports.finance',
        ],
        [
            'key' => 'vat',
            'category' => 'financial',
            'section' => 'reports',
            'title' => 'ضريبة القيمة المضافة',
            'desc' => 'ما حصّلتَه من ضريبة وما دفعتَه، والفرقُ المستحقّ — شهرًا بشهر.',
            'icon' => 'percent',
            'route' => 'admin.reports.vat',
        ],
        [
            'key' => 'expenses',
            'category' => 'financial',
            'section' => 'expenses',
            'title' => 'المصروفات',
            'desc' => 'المصروفات حسب النوع والتاريخ ومن سجّلها.',
            'icon' => 'arrow-down-circle',
            'route' => 'admin.reports.expenses',
        ],
        [
            'key' => 'payments',
            'category' => 'financial',
            'section' => 'finance',
            'title' => 'وسائل الدفع',
            'desc' => 'توزيع التحصيل على النقد والبطاقة وبقية الوسائل.',
            'icon' => 'credit-card',
            'route' => 'admin.reports.payments',
        ],
        [
            'key' => 'bank',
            'category' => 'financial',
            'section' => 'finance',
            'title' => 'كشف الحساب البنكي',
            'desc' => 'مطابقة حركات البنك بحركات النظام، وما لم يُطابَق منها.',
            'icon' => 'landmark',
            'route' => 'admin.reports.bank',
        ],

        /* ----------------------------- تشغيلية ----------------------------- */
        [
            'key' => 'invoices',
            'category' => 'operational',
            // وقسمُها `finance`: هو القسمُ الذي يحكم شاشاتِ فواتير العملاء
            // أصلًا — انظر `Permissions::ALIASES['customerInvoices']`
            'section' => 'finance',
            'title' => 'فواتير العملاء',
            'desc' => 'كل فاتورةٍ بحال سدادها وفرعها وقيمتها وما بقي عليها.',
            'icon' => 'file-text',
            'route' => 'admin.reports.invoices',
        ],
        [
            'key' => 'products',
            'category' => 'operational',
            'section' => 'products',
            'title' => 'المنتجات',
            'desc' => 'المنتجات وأسعارها وأقسامها وكمياتها المتاحة.',
            'icon' => 'package',
            'route' => 'admin.reports.products',
        ],
        [
            'key' => 'inventory',
            'category' => 'operational',
            'section' => 'inventory',
            'title' => 'المخزون والكميات',
            'desc' => 'رصيد كل صنف وحدّه الأدنى وما بلغ حدّ إعادة الطلب.',
            'icon' => 'boxes',
            'route' => 'admin.reports.inventory',
        ],
        [
            'key' => 'stocktake',
            'category' => 'operational',
            'section' => 'inventory',
            'title' => 'عمليات جرد المخزون',
            'desc' => 'ما عُدّ وأين فارق الدفترُ الواقع: النقص والزيادة وقيمتهما لكل فرع.',
            'icon' => 'clipboard-list',
            'route' => 'admin.reports.stocktake',
        ],
        [
            'key' => 'purchases',
            'category' => 'operational',
            'section' => 'purchases',
            'title' => 'أوامر الشراء',
            'desc' => 'أوامر الشراء وقيمتها وحالة استلامها لكل مورّد.',
            'icon' => 'truck',
            'route' => 'admin.reports.purchases',
        ],
        [
            'key' => 'suppliers',
            'category' => 'operational',
            'section' => 'suppliers',
            'title' => 'المورّدون',
            'desc' => 'بيانات المورّدين وعدد أوامر الشراء لكل واحد.',
            'icon' => 'store',
            'route' => 'admin.reports.suppliers',
        ],
        [
            'key' => 'staff',
            'category' => 'operational',
            'section' => 'employees',
            'title' => 'أداء الموظفين',
            'desc' => 'مبيعات كل موظف هذا الشهر وفرعه وحالته.',
            'icon' => 'users',
            'route' => 'admin.reports.staff',
        ],
        [
            'key' => 'activity',
            'category' => 'operational',
            'section' => 'settings',
            'title' => 'سجل النشاط',
            'desc' => 'من فعل ماذا ومتى على النظام.',
            'icon' => 'history',
            'route' => 'admin.reports.activity',
        ],

        /* ----------------------------- تحليلات ----------------------------- */
        [
            'key' => 'customers',
            'category' => 'analytical',
            'section' => 'customers',
            'title' => 'العملاء الأكثر إنفاقًا',
            'desc' => 'من يشتري أكثر، وكم طلبًا وكم أنفق.',
            'icon' => 'star',
            'route' => 'admin.reports.customers',
        ],
        [
            'key' => 'waste',
            'category' => 'analytical',
            'section' => 'reports',
            'title' => 'تحليلات الهالك',
            'desc' => 'ما تلف وما فُقد: قيمته واتجاهه، وأيّ صنفٍ وفرعٍ يبتلعه.',
            'icon' => 'trash-2',
            'route' => 'admin.reports.waste',
        ],
        /* ------------------------------ الإدارة — المرحلة 11 ------------------------------ */
        ['key' => 'management_monthly', 'category' => 'management', 'section' => 'management', 'params' => ['report' => 'management_monthly'], 'title' => 'التقرير الإداري الشهري',
            'desc' => 'ملخّصٌ تنفيذيّ: الإيراد والتحصيل والمصروفات والنقد والذمم والمشاريع والقمع والتشغيل والموازنة والتوقّع والتنبيهات.',
            'icon' => 'briefcase', 'route' => 'admin.management.reports'],
        ['key' => 'project_profitability', 'category' => 'management', 'section' => 'management', 'params' => ['report' => 'project_profitability'], 'title' => 'ربحيّة المشاريع',
            'desc' => 'المتعاقَد والمفوتَر والمحصَّل والتكلفة المباشرة والمساهمة والهامش — واكتمالُ التكلفة لكلّ مشروع.',
            'icon' => 'briefcase', 'route' => 'admin.management.reports'],
        ['key' => 'receivables_aging', 'category' => 'management', 'section' => 'management', 'params' => ['report' => 'receivables_aging'], 'title' => 'أعمار الذمم المدينة',
            'desc' => 'ما لم يستحقّ، ومتأخّرٌ ١–٣٠ و٣١–٦٠ و٦١–٩٠ وأكثر — وبالعميل.',
            'icon' => 'wallet', 'route' => 'admin.management.reports'],
        ['key' => 'budget_vs_actual', 'category' => 'management', 'section' => 'management', 'params' => ['report' => 'budget_vs_actual'], 'title' => 'الموازنة مقابل الفعليّ',
            'desc' => 'الموازنةُ المعتمَدة مقابل الدفتر — بالحساب والشهر أو الربع أو السنة.',
            'icon' => 'wallet', 'route' => 'admin.management.reports'],
        ['key' => 'cash_forecast', 'category' => 'management', 'section' => 'management', 'params' => ['report' => 'cash_forecast'], 'title' => 'التوقّع النقدي',
            'desc' => 'الافتتاحيّ والداخل والخارج والإقفال المتوقَّع شهرًا بشهر — وكلُّ بندٍ بمصدره.',
            'icon' => 'trending-up', 'route' => 'admin.management.reports'],
        ['key' => 'customer_revenue', 'category' => 'management', 'section' => 'management', 'params' => ['report' => 'customer_revenue'], 'title' => 'إيراد العملاء',
            'desc' => 'المفوتَر والمحصَّل والذمم لكلّ عميل — وتركيزُ الإيراد.',
            'icon' => 'users', 'route' => 'admin.management.reports'],
        ['key' => 'service_performance', 'category' => 'management', 'section' => 'management', 'params' => ['report' => 'service_performance'], 'title' => 'أداء الخدمات',
            'desc' => 'ما عُرض وقُبل وفُوتر من كلّ صنفٍ في الكتالوج — ومتوسّطُ السعر والخصم.',
            'icon' => 'package', 'route' => 'admin.management.reports'],
        ['key' => 'asset_utilization', 'category' => 'management', 'section' => 'management', 'params' => ['report' => 'asset_utilization'], 'title' => 'استخدام المعدّات',
            'desc' => 'أيّامُ الحجز والميدان لكلّ قطعة وصنف — والنقصُ والتأجير.',
            'icon' => 'package', 'route' => 'admin.management.reports'],
    ];

    /**
     * التقارير التي يفتحها هذا المستخدم فعلًا، بروابطها المحلولة.
     *
     * الرابط يُحلّ هنا لا في الواجهة: `route()` في المتصفّح يحتاج تسجيل المسار
     * في Ziggy، ومسارٌ واحدٌ ناقص يُسقط الصفحة كلّها بدل بطاقةٍ واحدة.
     */
    public static function forUser(?User $user): array
    {
        return self::visibleTo($user)
            ->map(fn ($r) => [
                'key' => $r['key'],
                'category' => $r['category'],
                'title' => __($r['title']),
                'desc' => __($r['desc']),
                'icon' => $r['icon'],
                /* وتقاريرُ الإدارة تفتح شاشةً واحدةً بمعامل — المرحلة 11 */
                'href' => route($r['route'], $r['params'] ?? []),
            ])
            ->values()
            ->all();
    }

    /**
     * البنود التي يفتحها هذا المستخدم فعلًا — مصفاةً بصلاحيته وبباقته.
     *
     * موضعٌ واحد للتصفية يقرؤه الفهرس وشريطُ التنقّل معًا: لو صفّى كلٌّ
     * بنفسه لَعرض أحدهما تقريرًا يُخفيه الآخر، وصار للشيء الواحد بابان
     * يختلفان — وأحدهما يقود إلى ٤٠٣.
     *
     * @return Collection<int, array>
     */
    private static function visibleTo(?User $user): Collection
    {
        return collect(self::ALL)
            ->filter(fn ($r) => $user?->allows($r['section']) ?? false);
    }

    /**
     * القسم الذي يُقاس به تقريرٌ بمساره — أو null فلا يُفتح.
     *
     * حارس المسار يشتقّ القسم من اسم المسار، فكلّ ما تحت `admin.reports.*`
     * يُقاس بصلاحية «التقارير» وحدها. وليست هذه تقاريرَ عن التقارير: فيها
     * مبيعاتُ كل موظف، وإنفاقُ كل عميل، ومقبوضاتُ الصندوق. فمن مُنح
     * «التقارير» وحدها يقرؤها كلّها بكتابة عنوانها، والفهرس نفسه لا يعرض
     * له منها بطاقةً واحدة — منعٌ في الشاشة لا وجود له عند الباب.
     *
     * فيُسأل هذا الفهرس عن قسم التقرير نفسه قبل أن تُبنى الصفحة.
     * والمسار المجهول يُردّ بـnull: يُغلق لا يُفتح.
     */
    /** القسمُ الذي يُقاس به تقريرٌ بمفتاحه — لتقاريرَ تُفتح من شاشةٍ لا تحمل اسمَها (المرحلة 11) */
    public static function sectionForKey(string $key): ?string
    {
        foreach (self::ALL as $report) {
            if ($report['key'] === $key) {
                return $report['section'];
            }
        }

        return null;
    }

    public static function sectionForRoute(string $route): ?string
    {
        foreach (self::ALL as $report) {
            if ($report['route'] === $route) {
                return $report['section'];
            }
        }

        return null;
    }

    /**
     * ما تعرضه شاشة «ملخّص المبيعات» — مصدرٌ واحد تقرؤه الشاشة والتغذية
     * والملفّات الثلاثة (Excel وPDF وCSV).
     *
     * كانت الملفّات تجمع أرقامها بنفسها: مؤشّراتُها من `adminStats` — وهي
     * أرقام اليوم والشهر مهما كانت الفترة المطلوبة، ومحصورةٌ بالفرع الحالي
     * بينما ما تحتها ليس كذلك — وأفضلُ منتجاتها مرتّبةً بالكمية بينما
     * الشاشة ترتّبها بالإيراد. فيخرج الملفّ بترويسةٍ تقول «اليوم» وجدولٍ
     * يحمل الشهر، وبقائمةٍ لأفضل خمسةٍ غير التي رآها التاجر قبل ضغطه بثانية.
     *
     * وباجتماعها هنا لا يبقى للاختلاف موضع.
     */
    public static function salesReport(?string $range): array
    {
        $range = Demo::range($range);

        return [
            'summary' => Demo::reportSummary($range),
            'salesSeries' => Demo::salesTrend($range),
            'paymentDistribution' => Demo::paymentDistribution($range),
            'topSellingProducts' => Demo::topSellingProducts(5, $range),
            'range' => $range,
        ];
    }

    /**
     * بطاقات الملخّص صفوفًا لورقة: اسمٌ وقيمة، بترتيب الشاشة نفسه.
     *
     * والقيمة رقمٌ خام لا نصٌّ منسَّق: الورقة تريدها رقمًا يُجمع في خليّة،
     * والـPDF يريدها منسَّقةً — فالتنسيق عند الكاتب لا هنا.
     */
    public static function summaryRows(array $summary): array
    {
        $rows = [
            ['إجمالي المبيعات', 'sales', true],
            // التكلفة تُقال صراحةً: بطاقةُ ربحٍ بلا سطر تكلفةٍ فوقها لا
            // يستطيع قارئها أن يتحقّق من الطرح ولا أن يعرف أنه وقع أصلًا
            ['تكلفة البضاعة المباعة', 'cogs', true],
            ['صافي الربح', 'profit', true],
            ['المصروفات', 'expenses', true],
            ['الضريبة المحصّلة', 'tax', true],
            ['المنتجات', 'products', false],
            ['تنبيهات المخزون', 'inventory_alerts', false],
            ['الموظفون', 'employees', false],
            ['العملاء', 'customers', false],
            ['وسائل الدفع', 'payment_methods', false],
        ];

        return collect($rows)->map(fn ($r) => [
            'label' => __($r[0]),
            'value' => $r[2] ? round((float) ($summary[$r[1]] ?? 0), 3) : (int) ($summary[$r[1]] ?? 0),
            'money' => $r[2],
        ])->all();
    }

    /** أسماء التصنيفات مترجمةً — الواجهة لا تخمّنها من المفتاح */
    public static function categoryLabels(): array
    {
        return collect(self::CATEGORIES)->map(fn ($l) => __($l))->all();
    }
}
