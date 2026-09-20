<?php

namespace App\Support\Intelligence;

use App\Models\Budget;
use App\Models\CustomerInvoice;
use App\Models\FinancialForecast;
use App\Models\Project;
use App\Support\Money;

/**
 * التنبيهاتُ الإداريّة — قواعدُ حتميّة على عتباتٍ مضبوطة. المرحلة 11.
 *
 * كلُّ تنبيهٍ يقول ما وقع ورقمَه ومدّتَه وعتبتَه — ولا درجةَ مخاطرةٍ مبهمة
 * ولا تخمين. وما لا يكفي له التاريخُ لا يُقال: الشذوذُ يحتاج ثلاثةَ أشهرٍ
 * مرصودةً على الأقلّ.
 */
final class ManagementAlerts
{
    public const CRITICAL = 'critical';

    public const WARNING = 'warning';

    public const INFO = 'info';

    /** الأشهرُ المرصودة اللازمة قبل الحكم بشذوذ */
    public const MIN_OBSERVATIONS = 3;

    /** @return list<array<string, mixed>> */
    public static function for(int $bid, ?Period $period = null): array
    {
        $period ??= Period::resolve($bid, 'this_month');
        $t = Thresholds::for($bid);
        $currency = Money::of($bid);
        $money = fn (float $v) => Money::format($v, $currency);
        $alerts = [];

        /* ─ الذمم ─ */
        $receivables = FinancialPerformance::receivables($bid);
        if ($receivables['overdue'] >= $t['overdue_receivable_amount'] && $receivables['overdue'] > 0.0005) {
            $alerts[] = self::alert('overdue_receivables', self::CRITICAL, __('متأخّراتٌ فوق العتبة'),
                __('المتأخّرُ :v على :n عميلًا — العتبة :t', ['v' => $money($receivables['overdue']), 'n' => $receivables['customers'], 't' => $money($t['overdue_receivable_amount'])]),
                ['value' => $receivables['overdue'], 'threshold' => $t['overdue_receivable_amount'], 'period' => __('الآن')], route('admin.finance.receivables'));
        }
        $veryLate = CustomerInvoice::where('business_id', $bid)->overdue()
            ->whereDate('due_at', '<', now()->subDays((int) $t['overdue_invoice_days'])->toDateString())
            ->with('customer:id,name')->orderBy('due_at')->limit(20)->get();
        foreach ($veryLate as $inv) {
            $alerts[] = self::alert('invoice_overdue', self::WARNING, __('فاتورة :n متأخّرة :d يومًا', ['n' => $inv->number ?: '#'.$inv->id, 'd' => $inv->daysOverdue()]),
                __('على :c — الباقي :v — العتبة :t يومًا', ['c' => (string) ($inv->customer?->name ?? '—'), 'v' => $money($inv->outstanding()), 't' => (int) $t['overdue_invoice_days']]),
                ['value' => $inv->outstanding(), 'threshold' => $t['overdue_invoice_days'], 'period' => __('الآن')], route('admin.customerInvoices.show', $inv->id));
        }

        /* ─ هامشُ المشاريع ─ */
        foreach (Project::where('business_id', $bid)->open()->with('customer:id,name')->orderByDesc('id')->limit(100)->get() as $project) {
            $p = ProjectProfitability::forProject($project);
            if ($p['margin_percent'] === null || $p['completeness']['status'] === Completeness::INSUFFICIENT) {
                continue;
            }
            if ($p['margin_percent'] < $t['project_margin_percent']) {
                $alerts[] = self::alert('project_margin', $p['contribution'] < 0 ? self::CRITICAL : self::WARNING,
                    __('هامش المشروع :code دون العتبة', ['code' => $project->code]),
                    __('الهامش :m٪ على الإيراد :r — العتبة :t٪:p', ['m' => $p['margin_percent'], 'r' => $money($p['revenue']), 't' => $t['project_margin_percent'],
                        'p' => $p['completeness']['status'] === Completeness::PARTIAL ? ' — '.__('على التكاليف المخصَّصة حاليًّا') : '']),
                    ['value' => $p['margin_percent'], 'threshold' => $t['project_margin_percent'], 'period' => __('حتى الآن')], route('admin.projects.show', $project->id));
            }
            if ($p['expected_cost'] > $p['contracted'] + 0.0005 && $p['contracted'] > 0.0005) {
                $alerts[] = self::alert('project_cost_over_value', self::CRITICAL, __('تكلفة المشروع :code تتجاوز قيمته', ['code' => $project->code]),
                    __('التكلفةُ المتوقَّعة :c والقيمةُ المتعاقَد عليها :v', ['c' => $money($p['expected_cost']), 'v' => $money($p['contracted'])]),
                    ['value' => $p['expected_cost'], 'threshold' => $p['contracted'], 'period' => __('حتى الآن')], route('admin.projects.show', $project->id));
            }
        }

        /* ─ الموازنة ─ */
        $budget = Budgeting::approvedFor($bid, (int) now()->format('Y'));
        if ($budget !== null) {
            $bva = Budgeting::versusActual($budget, 'month');
            $month = (int) now()->format('n');
            foreach ($bva['rows'] as $row) {
                if ($row['kind'] !== 'expense') {
                    continue;
                }
                $bucket = $row['buckets'][$month - 1] ?? null;
                if ($bucket === null || $bucket['planned'] <= 0.0005) {
                    continue;
                }
                if ($bucket['variance_percent'] !== null && $bucket['variance_percent'] > $t['budget_variance_percent']) {
                    $alerts[] = self::alert('budget_variance', self::WARNING, __('مصروف «:a» فوق موازنة الشهر', ['a' => $row['name']]),
                        __('الفعليّ :a مقابل :p — انحرافٌ :v٪ والعتبة :t٪', ['a' => $money($bucket['actual']), 'p' => $money($bucket['planned']), 'v' => $bucket['variance_percent'], 't' => $t['budget_variance_percent']]),
                        ['value' => $bucket['variance_percent'], 'threshold' => $t['budget_variance_percent'], 'period' => $bucket['label']], route('admin.management.budgets', ['budget' => $budget->id]));
                }
            }
        }

        /* ─ النقدُ المتوقَّع ─ */
        $forecast = FinancialForecast::where('business_id', $bid)->where('status', FinancialForecast::APPROVED)->orderByDesc('approved_at')->first();
        if ($forecast !== null) {
            $lowest = (float) ($forecast->totals['lowest_closing'] ?? 0);
            if ($lowest < $t['cash_floor_amount']) {
                $alerts[] = self::alert('cash_floor', $lowest < 0 ? self::CRITICAL : self::WARNING, __('النقد المتوقَّع دون الحدّ الأدنى'),
                    __('أدنى إقفالٍ متوقَّع :v في توقّع «:f» — الحدّ :t', ['v' => $money($lowest), 'f' => $forecast->name, 't' => $money($t['cash_floor_amount'])]),
                    ['value' => $lowest, 'threshold' => $t['cash_floor_amount'], 'period' => $forecast->horizon_days.' '.__('يومًا')], route('admin.management.cash'));
            }
        } else {
            $current = FinancialPerformance::cashPosition($bid);
            if ($current['liquid'] < $t['cash_floor_amount'] && self::hasCashHistory($bid)) {
                $alerts[] = self::alert('cash_floor', self::WARNING, __('النقد السائل دون الحدّ الأدنى'),
                    __('النقدُ الآن :v — الحدّ :t (ولا توقّعَ معتمَدًا)', ['v' => $money($current['liquid']), 't' => $money($t['cash_floor_amount'])]),
                    ['value' => $current['liquid'], 'threshold' => $t['cash_floor_amount'], 'period' => __('الآن')], route('admin.management.cash'));
            }
        }

        /* ─ التركيز ─ */
        $concentration = CustomerIntelligence::concentration($bid, Period::resolve($bid, 'last_12_months'), $t);
        if (($concentration['above_threshold'] ?? false) && $concentration['customers'] > 1) {
            $top = $concentration['top'][0];
            $alerts[] = self::alert('customer_concentration', self::INFO, __('تركيزُ الإيراد في عميلٍ واحد'),
                __(':c يمثّل :p٪ من إيراد آخر 12 شهرًا — العتبة :t٪', ['c' => $top['customer'], 'p' => $top['percent'], 't' => $t['customer_concentration_percent']]),
                ['value' => $top['percent'], 'threshold' => $t['customer_concentration_percent'], 'period' => __('آخر 12 شهرًا')], route('admin.management.customers'));
        }

        /* ─ شذوذُ المصروفات ─ */
        foreach (Anomalies::expenses($bid, $t) as $a) {
            $alerts[] = $a;
        }

        /* ─ المعدّات والحوادث ─ */
        $ops = OperationalIntelligence::summary($bid, Period::resolve($bid, 'last_12_months'));
        if ($ops['events']['total'] >= 3 && $ops['incidents']['severe_per_event'] !== null && $ops['incidents']['severe_per_event'] > $t['critical_incidents_per_event']) {
            $alerts[] = self::alert('incident_trend', self::WARNING, __('الحوادثُ الحرجة لكلّ فعاليّة فوق العتبة'),
                __(':n حادثًا عاليًا أو حرجًا على :e فعاليّة (:r لكلّ فعاليّة) — العتبة :t', ['n' => $ops['incidents']['severe'], 'e' => $ops['events']['total'], 'r' => $ops['incidents']['severe_per_event'], 't' => $t['critical_incidents_per_event']]),
                ['value' => $ops['incidents']['severe_per_event'], 'threshold' => $t['critical_incidents_per_event'], 'period' => __('آخر 12 شهرًا')], route('admin.management.operations'));
        }
        $util = OperationalIntelligence::utilization($bid, Period::resolve($bid, 'last_12_months'), $t);
        foreach ($util['categories'] as $c) {
            if ($c['signal'] === 'repeated_shortage') {
                $alerts[] = self::alert('asset_shortage', self::INFO, __('نقصٌ متكرّر في صنف «:c»', ['c' => $c['label']]),
                    __('لم يُغطَّ المتطلَّبُ في :n فعاليّة من :e — العتبة :t', ['n' => $c['short_events'], 'e' => $c['required_in_events'], 't' => (int) $t['asset_shortage_events']]),
                    ['value' => $c['short_events'], 'threshold' => $t['asset_shortage_events'], 'period' => __('آخر 12 شهرًا')], route('admin.management.operations'));
            }
        }

        usort($alerts, fn ($a, $b) => self::rank($a['severity']) <=> self::rank($b['severity']));

        return $alerts;
    }

    /**
     * ما يُحمل إلى مركز العمليات — الحرجُ الرخيصُ حسابًا وحده.
     *
     * مركزُ العمليات يُفتح كلَّ صباح، فلا يُحمَّل بربحيّة كلّ مشروع: المتأخّراتُ
     * والنقدُ والموازنةُ استعلاماتٌ معدودة؛ والباقي في شاشة الإدارة.
     */
    public static function actionable(int $bid, int $limit = 10): array
    {
        $t = Thresholds::for($bid);
        $currency = Money::of($bid);
        $money = fn (float $v) => Money::format($v, $currency);
        $alerts = [];

        $receivables = FinancialPerformance::receivables($bid);
        if ($receivables['overdue'] >= $t['overdue_receivable_amount'] && $receivables['overdue'] > 0.0005) {
            $alerts[] = self::alert('overdue_receivables', self::CRITICAL, __('متأخّراتٌ فوق العتبة'),
                __('المتأخّرُ :v على :n عميلًا — العتبة :t', ['v' => $money($receivables['overdue']), 'n' => $receivables['customers'], 't' => $money($t['overdue_receivable_amount'])]),
                ['value' => $receivables['overdue'], 'threshold' => $t['overdue_receivable_amount'], 'period' => __('الآن')], route('admin.finance.receivables'));
        }

        $forecast = FinancialForecast::where('business_id', $bid)->where('status', FinancialForecast::APPROVED)->orderByDesc('approved_at')->first();
        $lowest = $forecast === null ? FinancialPerformance::cashPosition($bid)['liquid'] : (float) ($forecast->totals['lowest_closing'] ?? 0);
        if ($lowest < $t['cash_floor_amount'] && ($forecast !== null || self::hasCashHistory($bid))) {
            $alerts[] = self::alert('cash_floor', self::CRITICAL, $forecast === null ? __('النقد السائل دون الحدّ الأدنى') : __('النقد المتوقَّع دون الحدّ الأدنى'),
                __(':v مقابل حدٍّ :t', ['v' => $money($lowest), 't' => $money($t['cash_floor_amount'])]),
                ['value' => $lowest, 'threshold' => $t['cash_floor_amount'], 'period' => $forecast === null ? __('الآن') : $forecast->horizon_days.' '.__('يومًا')], route('admin.management.cash'));
        }

        $budget = Budgeting::approvedFor($bid, (int) now()->format('Y'));
        if ($budget !== null) {
            $bva = Budgeting::versusActual($budget, 'month');
            $month = (int) now()->format('n');
            foreach ($bva['rows'] as $row) {
                $bucket = $row['buckets'][$month - 1] ?? null;
                if ($row['kind'] === 'expense' && $bucket !== null && $bucket['planned'] > 0.0005 && $bucket['variance_percent'] !== null && $bucket['variance_percent'] > $t['budget_variance_percent']) {
                    $alerts[] = self::alert('budget_variance', self::CRITICAL, __('مصروف «:a» فوق موازنة الشهر', ['a' => $row['name']]),
                        __('الفعليّ :a مقابل :p — انحرافٌ :v٪ والعتبة :t٪', ['a' => $money($bucket['actual']), 'p' => $money($bucket['planned']), 'v' => $bucket['variance_percent'], 't' => $t['budget_variance_percent']]),
                        ['value' => $bucket['variance_percent'], 'threshold' => $t['budget_variance_percent'], 'period' => $bucket['label']], route('admin.management.budgets', ['budget' => $budget->id]));
                }
            }
        }

        return array_slice($alerts, 0, $limit);
    }

    /** شركةٌ لم تسجّل نقدًا قطّ لا يُقال لها «نقدُك دون الحدّ» — لا بياناتَ تُقاس */
    private static function hasCashHistory(int $bid): bool
    {
        $ids = CashIntelligence::liquidAccountIds($bid);

        return $ids !== [] && \App\Models\JournalLine::whereIn('account_id', $ids)->exists();
    }

    private static function alert(string $kind, string $severity, string $title, string $detail, array $facts, string $url): array
    {
        return ['kind' => $kind, 'severity' => $severity, 'title' => $title, 'detail' => $detail, 'facts' => $facts, 'url' => $url, 'at' => now()->format('Y-m-d')];
    }

    private static function rank(string $severity): int
    {
        return match ($severity) {
            self::CRITICAL => 0, self::WARNING => 1, default => 2,
        };
    }
}
