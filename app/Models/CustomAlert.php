<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomAlert extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'threshold' => 'decimal:3',
            'due_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    /**
     * المقاييس التي يجوز بناء قاعدة عليها.
     *
     * قائمة مغلقة عمدًا: كل مقياس هنا يقابله استعلامٌ مكتوب في الكود، فلا
     * يصل شيءٌ من إدخال المستخدم إلى قاعدة البيانات كشرط.
     *
     * `unit` تُستعمل في الواجهة لتعرف هل الحدّ مبلغٌ أم عدد أيام أم عدد.
     */
    public const METRICS = [
        'daily_sales' => ['label' => 'إيرادات اليوم', 'unit' => 'money', 'section' => 'reports'],
        'monthly_expenses' => ['label' => 'مصروفات الشهر', 'unit' => 'money', 'section' => 'expenses'],
        /*
         * وكان مكانَه «الطلبات المعلّقة».
         *
         * لا مسارَ يُنشئ طلبًا منذ رُفعت نقطة البيع، فكان المقياسُ صفرًا
         * أبدًا: قاعدةٌ تُكتب عليه لا تتحقّق مرّةً واحدة، وتنبيهٌ لا يُطلق
         * أسوأ من تنبيهٍ لا يُكتب — صاحبُه يظنّ أنّ لا شيءَ يستحقّ الانتباه.
         *
         * وحلّ محلَّه ما يُتابَع فعلًا: فاتورةٌ مضى استحقاقُها وعليها باقٍ.
         *
         * وقسمُه «المالية» — بابُ فواتير العملاء منذ خرجت «المبيعات» — وبطاقتُه
         * تفتح الفواتيرَ نفسَها لا ملخّصَ المالية (`route`).
         */
        'overdue_invoices' => ['label' => 'فواتير متأخّرة', 'unit' => 'count', 'section' => 'finance', 'route' => 'admin.customerInvoices.index'],
        'low_stock_products' => ['label' => 'المنتجات تحت حد التنبيه', 'unit' => 'count', 'section' => 'inventory'],
        'dormant_customers' => ['label' => 'العملاء الراكدون', 'unit' => 'count', 'section' => 'customers'],
        'open_purchase_orders' => ['label' => 'أوامر شراء لم تُستلم', 'unit' => 'count', 'section' => 'purchases'],
        // وقسمُه `reports`: «الربحية» لم تكن قسمًا قطّ — انظر `url()`
        'today_profit' => ['label' => 'صافي ربح اليوم', 'unit' => 'money', 'section' => 'reports'],
    ];

    public const OPERATORS = ['>', '<'];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * المسار الذي تفتحه نقرة التنبيه.
     *
     * ═══ ولغمٌ نُزع من هنا ═══
     *
     * كان في الخريطة `'profitability' => 'admin.profitability.index'` — ولا
     * وجودَ لهذا المسار في الـ٢٩٧. ولم ينفجر لسببٍ واحد: `CustomAlertController`
     * يقصر `section` على `Permissions::sections()` وليس فيها «الربحية»، فلا
     * يُخزَّن الصفُّ أصلًا. فهو لغمٌ نائم: أوّلُ من يضيف `profitability` إلى
     * الأقسام يُسقط شاشةَ التنبيهات كلَّها بـ`RouteNotFoundException`.
     *
     * ونُزع الآن لا لاحقًا لأنّ المرحلة القادمة تضيف أقسامًا.
     *
     * وكلُّ مفتاحٍ هنا قسمٌ حقيقيٌّ في `Permissions::SECTIONS`، ويحرسه
     * `CustomAlertsTest`.
     */
    public function url(): string
    {
        return self::routeFor($this->section);
    }

    /**
     * قسمٌ → مسارُ شاشته.
     *
     * وواحدةٌ لا اثنتان: كانت `AlertMetrics::sectionUrl` تقرأ
     * `Permissions::ROUTES` وهذه تقرأ خريطتَها هي، فبطاقةُ اللوحة تفتح شاشةً
     * ونقرةُ التنبيه على المقياس نفسِه تفتح أخرى.
     */
    public static function routeFor(?string $section): string
    {
        $routes = [
            // ملخّص المبيعات لا فهرس التقارير: التنبيه على رقمٍ يقود إلى الرقم،
            // ومن نقر «مبيعات اليوم تجاوزت كذا» لا يريد قائمةً يختار منها
            'reports' => 'admin.reports.sales',
            'expenses' => 'admin.expenses.index',
            'inventory' => 'admin.inventory.index',
            'customers' => 'admin.customers.index',
            'purchases' => 'admin.purchases.index',
            'products' => 'admin.products.index',
            'employees' => 'admin.employees.index',
            'finance' => 'admin.finance.index',
            'suppliers' => 'admin.suppliers.index',
            'settings' => 'admin.settings.index',
            'dashboard' => 'admin.dashboard',
        ];

        return route($routes[$section] ?? 'admin.dashboard');
    }
}
