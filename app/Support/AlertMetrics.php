<?php

namespace App\Support;

use App\Models\CustomAlert;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Expense;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Setting;

/**
 * قيم المقاييس التي تُبنى عليها التنبيهات المخصّصة.
 *
 * كل مقياس استعلامٌ مكتوب هنا لا صيغة تصل من المستخدم — انظر
 * CustomAlert::METRICS.
 */
class AlertMetrics
{
    /** كم يومًا بلا شراء يُعدّ العميل بعدها راكدًا (قابل للضبط من الإعدادات) */
    public static function dormantDays(int $businessId): int
    {
        $value = Setting::where('business_id', $businessId)
            ->where('key', 'dormant_customer_days')->value('value');

        return max(1, (int) ($value ?: 60));
    }

    /**
     * العملاء الراكدون: تعاملوا يومًا ثم انقطعوا.
     *
     * من لم يتعامل قطّ ليس راكدًا بل لم يبدأ — خلطهما يُغرق التنبيه بأسماء لا
     * معنى لمتابعتها. ولذلك يُشترط وجود فاتورةٍ سابقة.
     *
     * وكان المقياسُ آخرَ **طلب** — وجدولُ الطلبات فارغٌ أبدًا، فكان الانضمام
     * لا يردّ صفًّا واحدًا: صفرُ راكدين مهما انقطع العملاء. انظر `Revenue`.
     */
    public static function dormantCustomers(int $businessId, ?int $days = null)
    {
        $days = $days ?? self::dormantDays($businessId);
        $cutoff = now()->subDays($days)->toDateString();

        $lastInvoice = CustomerInvoice::selectRaw('customer_id, MAX(issued_at) as last_at')
            ->where('business_id', $businessId)
            ->where('status', CustomerInvoice::ISSUED)
            ->whereNotNull('customer_id')
            ->groupBy('customer_id');

        return Customer::where('customers.business_id', $businessId)
            ->joinSub($lastInvoice, 'o', fn ($j) => $j->on('o.customer_id', '=', 'customers.id'))
            ->where('o.last_at', '<', $cutoff)
            ->orderBy('o.last_at')
            ->select('customers.*', 'o.last_at')
            ->get();
    }

    /**
     * البطاقات الاختيارية للوحة — مقاييس التقارير نفسها معروضةً كبطاقات.
     *
     * المصدر واحد مقصود: هذه المقاييس تُبنى عليها التنبيهات المخصّصة أصلًا،
     * فلو بُنيت للّوحة قائمةٌ ثانية لافترقتا عند أوّل تعديل — يقول التنبيه
     * رقمًا وتقول البطاقة غيره، ولا يعرف التاجر أيّهما يصدّق.
     *
     * وكلٌّ منها يقود إلى قسمه: البطاقة سؤال، وقسمها هو الجواب المفصَّل.
     */
    public static function catalog(int $businessId): array
    {
        $icons = [
            'daily_sales' => 'shopping-bag', 'monthly_expenses' => 'arrow-down-circle',
            'overdue_invoices' => 'clock', 'low_stock_products' => 'alert-triangle',
            'dormant_customers' => 'user-x', 'open_purchase_orders' => 'clipboard-list',
            'today_profit' => 'piggy-bank',
        ];
        $colors = [
            'daily_sales' => 'primary', 'monthly_expenses' => 'danger',
            'overdue_invoices' => 'warning', 'low_stock_products' => 'warning',
            'dormant_customers' => 'secondary', 'open_purchase_orders' => 'info',
            'today_profit' => 'success',
        ];

        return collect(CustomAlert::METRICS)
            ->map(function ($m, $key) use ($businessId, $icons, $colors) {
                $value = self::value($key, $businessId);

                return [
                    'key' => $key,
                    'label' => __($m['label']),
                    'value' => $m['unit'] === 'money' ? Demo::money($value) : (string) (int) $value,
                    'icon' => $icons[$key] ?? 'activity',
                    'color' => $colors[$key] ?? 'primary',
                    'url' => self::sectionUrl($m['section']),
                ];
            })
            ->values()->all();
    }

    /**
     * قسم المقياس → مساره. وما لا يملكه المستخدم لا يقود إلى شيء.
     *
     * والخريطةُ من `CustomAlert::routeFor` لا نسخةٌ ثانية: كانت تُقرأ هنا من
     * `Permissions::ROUTES` وهناك من خريطةٍ مكتوبةٍ بيدها — فبطاقةُ اللوحة
     * تفتح شاشةً ونقرةُ التنبيه على المقياس نفسِه تفتح أخرى.
     */
    private static function sectionUrl(string $section): ?string
    {
        return auth()->user()?->allows($section) ? CustomAlert::routeFor($section) : null;
    }

    /** القيمة الحالية لمقياس */
    public static function value(string $metric, int $businessId): float
    {
        return match ($metric) {
            'daily_sales' => Revenue::total($businessId, now()->startOfDay(), now()->startOfDay()->addDay()),

            'monthly_expenses' => (float) Expense::where('business_id', $businessId)
                ->whereBetween('spent_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('amount'),

            // والمتأخّرةُ تُشتقّ ولا تُخزَّن — النطاقُ نفسُه الذي تقرؤه الشاشة
            'overdue_invoices' => (float) CustomerInvoice::where('business_id', $businessId)
                ->overdue()->count(),

            'low_stock_products' => (float) Product::where('business_id', $businessId)
                ->whereColumn('quantity', '<', 'alert_qty')->count(),

            'dormant_customers' => (float) self::dormantCustomers($businessId)->count(),

            'open_purchase_orders' => (float) PurchaseOrder::where('business_id', $businessId)
                ->where('status', '!=', 'مستلم')->count(),

            // الإيراد ناقص التكلفة ناقص مصروفات اليوم
            'today_profit' => self::todayProfit($businessId),

            default => 0.0,
        };
    }

    /**
     * ربحُ اليوم — والتكلفةُ فيه **تقديرٌ لا قيد**، انظر `Revenue::cogs`.
     *
     * وبندُ خدمةٍ بلا صنفٍ تكلفتُه صفرٌ هنا، وهو الصواب: تكلفةُ الخدمة
     * عمالةٌ ومشترياتٌ تُقيَّد في موضعها لا في بطاقة صنف.
     */
    private static function todayProfit(int $businessId): float
    {
        $from = now()->startOfDay();
        $to = $from->copy()->addDay();

        $revenue = Revenue::total($businessId, $from, $to);
        $cost = Revenue::cogs($businessId, $from, $to);

        $expenses = (float) Expense::where('business_id', $businessId)
            ->whereDate('spent_at', today())->sum('amount');

        return round($revenue - $cost - $expenses, 3);
    }

    /** هل تحقّق شرط القاعدة الآن؟ */
    public static function triggered(CustomAlert $alert, int $businessId): bool
    {
        $value = self::value($alert->metric, $businessId);
        $threshold = (float) $alert->threshold;

        return $alert->operator === '>' ? $value > $threshold : $value < $threshold;
    }
}
