<?php

namespace App\Support\Intelligence;

use App\Models\Budget;
use App\Models\FinancialForecast;
use App\Support\Money;
use App\Support\Receivables;
use RuntimeException;

/**
 * التقاريرُ الإداريّة — أقسامٌ تُعرض على الشاشة وتخرج إكسل وPDF وCSV من الباب
 * العامّ للتقارير (`ReportDownloadController`). المرحلة 11.
 *
 * ولا رقمَ يُحسب هنا: كلُّ قسمٍ صفوفٌ ممّا تردّه خدماتُ الذكاء الإداريّ
 * بالمرشّحات نفسِها التي تقرؤها الشاشة.
 */
final class ManagementReports
{
    /** @var list<string> */
    public const KEYS = [
        'management_monthly', 'project_profitability', 'receivables_aging', 'budget_vs_actual',
        'cash_forecast', 'customer_revenue', 'service_performance', 'asset_utilization',
    ];

    public const TITLES = [
        'management_monthly' => 'التقرير الإداري الشهري',
        'project_profitability' => 'ربحيّة المشاريع',
        'receivables_aging' => 'أعمار الذمم المدينة',
        'budget_vs_actual' => 'الموازنة مقابل الفعليّ',
        'cash_forecast' => 'التوقّع النقدي',
        'customer_revenue' => 'إيراد العملاء',
        'service_performance' => 'أداء الخدمات',
        'asset_utilization' => 'استخدام المعدّات',
    ];

    /** @param  array<string, mixed>  $filters */
    public static function period(int $bid, array $filters): Period
    {
        return Period::resolve($bid, (string) ($filters['period'] ?? 'this_month'), $filters['from'] ?? null, $filters['to'] ?? null);
    }

    /** @return array<string, mixed> */
    public static function build(string $key, int $bid, array $filters): array
    {
        if (! in_array($key, self::KEYS, true)) {
            throw new RuntimeException(__('تقريرٌ غير معروف.'));
        }

        return self::{$key}($bid, $filters);
    }

    /* ─────────────────────────── التقارير ─────────────────────────── */

    public static function management_monthly(int $bid, array $filters): array
    {
        $period = self::period($bid, $filters);
        $f = FinancialPerformance::snapshot($bid, $period);
        $cash = CashIntelligence::current($bid);
        $aging = Receivables::aging($bid);
        $projects = ProjectProfitability::list($bid, $period, 'margin_asc', 100);
        $funnel = RevenueIntelligence::funnel($bid, $period);
        $concentration = CustomerIntelligence::concentration($bid, $period);
        $ops = OperationalIntelligence::summary($bid, $period);
        $util = OperationalIntelligence::utilization($bid, $period);
        $budget = Budgeting::approvedFor($bid, (int) $period->start->format('Y'));
        $forecast = FinancialForecast::where('business_id', $bid)->where('status', FinancialForecast::APPROVED)->orderByDesc('approved_at')->first();
        $alerts = ManagementAlerts::for($bid, $period);
        $m = fn ($k, $v, $basis = '') => ['metric' => __($k), 'value' => $v, 'basis' => $basis];

        $summary = [
            $m('الإيراد الصافي', $f['revenue']['net'], $f['basis']['revenue']),
            $m('الإيراد الإجمالي شاملًا الضريبة', $f['revenue']['gross'], ''),
            $m('التحصيل', $f['collections']['total'], $f['basis']['collections']),
            $m('التكلفة المباشرة', $f['direct_costs']['total'], $f['basis']['direct_costs']),
            $m('المصروفات المدفوعة', $f['expenses']['total'], $f['basis']['expenses']),
            $m('الرواتب', $f['payroll'], $f['basis']['payroll']),
            $m('المساهمة الإجمالية', $f['gross_contribution'], ''),
            $m('النتيجة التشغيلية', $f['operating_result'], $f['basis']['operating_result']),
            $m('الذمم القائمة', $cash['receivables']['outstanding'], $cash['receivables']['basis']),
            $m('المتأخّر', $cash['receivables']['overdue'], ''),
            $m('النقد السائل', $cash['cash']['liquid'], $cash['cash']['basis']),
            $m('المستحقّ علينا', $cash['payables']['total'], ''),
        ];

        return [
            'periodLabel' => $period->label(),
            'summary' => ['revenue' => $f['revenue']['net'], 'collected' => $f['collections']['total'], 'expenses' => $f['expenses']['total'], 'net' => $f['operating_result']],
            'sections' => [
                'الملخّص التنفيذي' => $summary,
                'شهرًا بشهر' => array_map(fn ($r) => ['month' => $r['month'], 'revenue' => $r['revenue'], 'collections' => $r['collections'], 'expenses' => $r['expenses'], 'direct_costs' => $r['direct_costs'], 'projects' => $r['projects']], FinancialPerformance::monthly($bid, $period)),
                'المصروفات بالنوع' => array_map(fn ($r) => ['type' => $r['type'], 'total' => $r['total'], 'count' => $r['count']], $f['expenses']['by_type']),
                'أعمار الذمم' => array_map(fn ($b) => ['bucket' => __($b['label']), 'amount' => $b['amount']], $aging['buckets']),
                'أداء المشاريع' => array_map(fn ($r) => self::projectRow($r), $projects['rows']),
                'القمع التجاري' => array_map(fn ($s) => ['stage' => $s['label'], 'count' => $s['count'], 'value' => $s['value'], 'committed' => $s['committed'] ? __('نعم') : __('لا')], $funnel['stages']),
                'تركيز العملاء' => array_map(fn ($r) => ['customer' => $r['customer'], 'net' => $r['net'], 'percent' => $r['percent']], $concentration['top']),
                'التشغيل' => [
                    ['metric' => __('الفعاليّات'), 'value' => $ops['events']['total']], ['metric' => __('بُثّت'), 'value' => $ops['events']['went_live']],
                    ['metric' => __('تجاوزُ الجاهزيّة'), 'value' => $ops['readiness']['overridden']], ['metric' => __('الحوادث'), 'value' => $ops['incidents']['total']],
                    ['metric' => __('الحوادث العالية والحرجة'), 'value' => $ops['incidents']['severe']], ['metric' => __('إرجاعاتٌ متأخّرة'), 'value' => $ops['returns']['late']],
                ],
                'المعدّات بالصنف' => array_map(fn ($c) => ['category' => $c['label'], 'assets' => $c['assets'], 'deployed_days' => $c['deployed_days'], 'utilization' => $c['utilization_percent'], 'short_events' => $c['short_events'], 'rented_events' => $c['rented_events']], $util['categories']),
                'الموازنة مقابل الفعلي' => $budget === null ? [] : array_map(fn ($r) => ['account' => $r['code'].' '.$r['name'], 'kind' => $r['kind'] === 'revenue' ? __('إيراد') : __('مصروف'), 'planned' => $r['planned'], 'actual' => $r['actual'], 'variance' => $r['variance'], 'reading' => __(Budgeting::READING_LABELS[$r['reading']])], Budgeting::versusActual($budget, 'year')['rows']),
                'التوقّع النقدي المعتمَد' => $forecast === null ? [] : array_map(fn ($mo) => ['month' => $mo['month'], 'opening' => $mo['opening'], 'inflows' => $mo['inflows']['total'], 'outflows' => $mo['outflows']['total'], 'net' => $mo['net'], 'closing' => $mo['closing']], $forecast->projection['months'] ?? []),
                'التنبيهات' => array_map(fn ($a) => ['severity' => __(self::severityLabel($a['severity'])), 'title' => $a['title'], 'detail' => $a['detail']], $alerts),
            ],
        ];
    }

    public static function project_profitability(int $bid, array $filters): array
    {
        $period = isset($filters['period']) && $filters['period'] === 'all' ? null : self::period($bid, $filters);
        $list = ProjectProfitability::list($bid, $period, (string) ($filters['sort'] ?? 'margin'), 300);

        return [
            'periodLabel' => $period?->label() ?? __('كلّ المشاريع'),
            'summary' => ['revenue' => $list['totals']['revenue'], 'total' => $list['totals']['direct_costs'], 'profit' => $list['totals']['contribution'], 'count' => $list['totals']['projects']],
            'sections' => ['المشاريع' => array_map(fn ($r) => self::projectRow($r), $list['rows'])],
        ];
    }

    public static function receivables_aging(int $bid, array $filters): array
    {
        $aging = Receivables::aging($bid);

        return [
            'periodLabel' => __('الآن'),
            'summary' => ['total' => $aging['total'], 'overdue' => round((float) collect($aging['buckets'])->where('key', '!=', 'not_due')->sum('amount'), 3), 'customers' => count($aging['customers'])],
            'sections' => [
                'الأعمار' => array_map(fn ($b) => ['bucket' => __($b['label']), 'amount' => $b['amount']], $aging['buckets']),
                'بالعميل' => array_map(fn ($c) => ['customer' => $c['customer'], 'outstanding' => $c['outstanding'], 'overdue' => $c['overdue'], 'oldest' => $c['oldest_invoice'] ?? '—', 'days' => $c['days_overdue']], $aging['customers']),
            ],
        ];
    }

    public static function budget_vs_actual(int $bid, array $filters): array
    {
        $budget = filled($filters['budget'] ?? null)
            ? Budget::where('business_id', $bid)->whereKey((int) $filters['budget'])->first()
            : Budgeting::approvedFor($bid, (int) now()->format('Y'));
        if ($budget === null) {
            return ['periodLabel' => __('لا موازنة'), 'summary' => [], 'sections' => ['بالحساب' => []]];
        }
        $grouping = in_array($filters['grouping'] ?? 'month', ['month', 'quarter', 'year'], true) ? $filters['grouping'] : 'month';
        $bva = Budgeting::versusActual($budget, $grouping);

        $rows = [];
        foreach ($bva['rows'] as $r) {
            foreach ($r['buckets'] as $b) {
                $rows[] = ['account' => $r['code'].' '.$r['name'], 'kind' => $r['kind'] === 'revenue' ? __('إيراد') : __('مصروف'), 'bucket' => $b['label'],
                    'planned' => $b['planned'], 'actual' => $b['actual'], 'variance' => $b['variance'], 'variance_percent' => $b['variance_percent'], 'reading' => __(Budgeting::READING_LABELS[$b['reading']])];
            }
        }

        return [
            'periodLabel' => $budget->name.' v'.$budget->version.' — '.$budget->fiscal_year,
            'summary' => ['revenue' => $bva['totals']['revenue']['actual'], 'total' => $bva['totals']['expense']['actual'], 'net' => round($bva['totals']['revenue']['actual'] - $bva['totals']['expense']['actual'], 3)],
            'sections' => ['بالحساب' => $rows],
        ];
    }

    public static function cash_forecast(int $bid, array $filters): array
    {
        $forecast = filled($filters['forecast'] ?? null)
            ? FinancialForecast::where('business_id', $bid)->whereKey((int) $filters['forecast'])->first()
            : FinancialForecast::where('business_id', $bid)->where('status', FinancialForecast::APPROVED)->orderByDesc('approved_at')->first();

        $projection = $forecast !== null
            ? ['months' => $forecast->projection['months'] ?? [], 'opening_cash' => (float) $forecast->opening_cash, 'label' => $forecast->name.' — '.$forecast->horizon_days.' '.__('يومًا')]
            : (fn ($p) => ['months' => $p['months'], 'opening_cash' => $p['opening_cash'], 'label' => __('توقّعٌ حيّ — :n يومًا (غير معتمَد)', ['n' => $p['horizon_days']])])(
                CashIntelligence::project($bid, (int) ($filters['horizon'] ?? 90), []));

        $items = [];
        foreach ($projection['months'] as $mo) {
            foreach ($mo['items'] as $it) {
                $items[] = ['at' => $it['at'], 'label' => $it['label'], 'category' => __(self::categoryLabel($it['category'])), 'direction' => $it['direction'] === 'in' ? __('داخل') : __('خارج'), 'amount' => $it['amount'], 'source' => (string) ($it['source']['ref'] ?? $it['source']['type'] ?? '')];
            }
        }

        return [
            'periodLabel' => $projection['label'],
            'summary' => ['total' => $projection['opening_cash'], 'income' => round((float) array_sum(array_map(fn ($m) => $m['inflows']['total'], $projection['months'])), 3), 'outgo' => round((float) array_sum(array_map(fn ($m) => $m['outflows']['total'], $projection['months'])), 3)],
            'sections' => [
                'الأشهر' => array_map(fn ($mo) => ['month' => $mo['month'], 'opening' => $mo['opening'], 'committed_in' => $mo['inflows']['committed'], 'expected_in' => $mo['inflows']['expected'], 'committed_out' => $mo['outflows']['committed'], 'expected_out' => $mo['outflows']['expected'], 'net' => $mo['net'], 'closing' => $mo['closing']], $projection['months']),
                'البنود' => $items,
            ],
        ];
    }

    public static function customer_revenue(int $bid, array $filters): array
    {
        $period = self::period($bid, $filters);
        $list = CustomerIntelligence::list($bid, $period, 300);
        $concentration = CustomerIntelligence::concentration($bid, $period);

        return [
            'periodLabel' => $period->label(),
            'summary' => ['revenue' => $list['totals']['invoiced'], 'income' => $list['totals']['collected'], 'outstanding' => $list['totals']['outstanding'], 'customers' => $list['totals']['customers']],
            'sections' => [
                'العملاء' => array_map(fn ($r) => ['customer' => $r['customer'], 'quotations' => $r['quotations'], 'accepted' => $r['accepted'], 'invoiced' => $r['invoiced'], 'collected' => $r['collected'], 'outstanding' => $r['outstanding'], 'overdue' => $r['overdue'], 'projects' => $r['projects']], $list['rows']),
                'التركيز' => array_map(fn ($r) => ['customer' => $r['customer'], 'net' => $r['net'], 'percent' => $r['percent']], $concentration['top']),
            ],
        ];
    }

    public static function service_performance(int $bid, array $filters): array
    {
        $period = self::period($bid, $filters);
        $list = ServiceIntelligence::list($bid, $period, 300);

        return [
            'periodLabel' => $period->label(),
            'summary' => ['revenue' => $list['totals']['invoiced_value'], 'total' => $list['totals']['quoted_value'], 'products' => $list['totals']['products']],
            'sections' => ['الأصناف' => array_map(fn ($r) => ['name' => $r['name'], 'quotations' => $r['quotations'], 'quoted_value' => $r['quoted_value'], 'accepted_value' => $r['accepted_value'], 'invoiced_value' => $r['invoiced_value'], 'projects' => $r['projects'], 'average_price' => $r['average_selling_price'], 'discount' => $r['discount_percent'], 'contribution' => $r['contribution']], $list['rows'])],
        ];
    }

    public static function asset_utilization(int $bid, array $filters): array
    {
        $period = self::period($bid, $filters);
        $util = OperationalIntelligence::utilization($bid, $period);

        return [
            'periodLabel' => $period->label(),
            'summary' => ['items' => $util['assets']],
            'sections' => [
                'القطع' => array_map(fn ($r) => ['code' => $r['code'], 'name' => $r['name'], 'category' => $r['category_label'], 'reserved_days' => $r['reserved_days'], 'deployed_days' => $r['deployed_days'], 'events' => $r['events'], 'utilization' => $r['utilization_percent'], 'status' => $r['status']], $util['rows']),
                'الأصناف' => array_map(fn ($c) => ['category' => $c['label'], 'assets' => $c['assets'], 'deployed_days' => $c['deployed_days'], 'utilization' => $c['utilization_percent'], 'short_events' => $c['short_events'], 'rented_events' => $c['rented_events'], 'idle' => $c['idle']], $util['categories']),
            ],
        ];
    }

    /* ─────────────────────────── أدوات ─────────────────────────── */

    private static function projectRow(array $r): array
    {
        return ['code' => $r['project']['code'], 'name' => $r['project']['name'], 'customer' => $r['project']['customer'] ?? '—', 'contracted' => $r['contracted'], 'revenue' => $r['revenue'],
            'collected' => $r['collected'], 'receivable' => $r['receivable'], 'direct_costs' => $r['direct_costs']['total'], 'contribution' => $r['contribution'],
            'margin' => $r['margin_percent'], 'completeness' => __(self::completenessLabel($r['completeness']['status']))];
    }

    public static function completenessLabel(string $status): string
    {
        return match ($status) {
            Completeness::COMPLETE => 'مكتمل', Completeness::PARTIAL => 'جزئيّ', default => 'غير كافٍ',
        };
    }

    public static function severityLabel(string $severity): string
    {
        return match ($severity) {
            ManagementAlerts::CRITICAL => 'حرج', ManagementAlerts::WARNING => 'تحذير', default => 'معلومة',
        };
    }

    public static function categoryLabel(string $category): string
    {
        return match ($category) {
            'committed' => 'ملتزَم', 'expected' => 'متوقَّع', 'scenario' => 'سيناريو', 'pipeline' => 'خطّ أنابيب', default => $category,
        };
    }

    /** @return array<string, mixed> — لعرض المبالغ بعملة الشركة في الشاشة */
    public static function currency(int $bid): array
    {
        return Money::of($bid);
    }
}
