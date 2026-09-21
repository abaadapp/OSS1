<?php

namespace App\Support\Intelligence;

use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\CustomerInvoice;
use App\Models\Expense;
use App\Models\FinancialScenario;
use App\Models\JournalEntry;
use App\Models\PayrollRun;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\SupplierInvoice;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\PurchaseOrders;
use App\Support\SupplierInvoices;
use App\Support\Vat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * النقدُ — الآنَ وغدًا. المرحلة 11.
 *
 * ═══ أربعُ طبقاتٍ لا تختلط ═══
 *
 * • **فعليّ** — رصيدُ الصندوق والبنوك من الدفتر لحظةَ القراءة.
 * • **ملتزَم** — مستنداتٌ قائمة لها تاريخ: فاتورةٌ صادرة لم تُسدَّد، وسندُ
 *   مورّدٍ معتمَد لم يُسدَّد، ومصروفٌ غير مدفوع، وأمرُ شراءٍ مفتوح.
 * • **متوقَّع** — افتراضاتٌ مكتوبة: مصروفاتٌ متكرّرة (متوسّطُ ثلاثة أشهر أو
 *   ما كُتب)، ورواتبُ (متوسّطُ آخر مسيرات)، وبنودُ موازنةٍ معتمَدة إن طُلبت.
 * • **سيناريو / خطّ أنابيب** — ما يُضاف بيدٍ للمحاكاة، وعروضٌ لم تُقبل بنسبةِ
 *   تحوّلٍ مكتوبة — ولا يُخلط بالملتزَم أبدًا.
 *
 * وكلُّ بندٍ يحمل مصدرَه: «الفاتورة INV-100» أو «افتراض الموازنة» — لا رقمَ
 * بلا أصل. والتوقّعُ لا يُعرض نقدًا فعليًّا في أيّ شاشة.
 */
final class CashIntelligence
{
    /** @var list<int> */
    public const HORIZONS = [30, 60, 90, 180, 365];

    /** أكثرُ ما يُحمل من بنودٍ في الشهر الواحد للعرض — والمجموعُ كامل */
    private const ITEMS_PER_MONTH = 60;

    /* ─────────────────────────── الآن ─────────────────────────── */

    /** @return array<string, mixed> */
    public static function current(int $bid): array
    {
        $position = FinancialPerformance::cashPosition($bid);
        $receivables = FinancialPerformance::receivables($bid);
        $payables = FinancialPerformance::payables($bid);
        $openPo = self::openPurchaseOrders($bid);

        return [
            'currency' => Money::of($bid),
            'cash' => $position,
            'receivables' => $receivables,
            'payables' => $payables + ['open_purchase_orders' => round((float) $openPo->sum('remaining'), 3)],
            'net_position' => round($position['liquid'] + $receivables['outstanding'] - $payables['total'] - (float) $openPo->sum('remaining'), 3),
            'basis' => [
                'cash' => 'actual',
                'receivables' => 'committed',
                'payables' => 'committed',
                'net_position' => __('النقدُ السائل + الذمم المدينة − الذمم الدائنة − باقي أوامر الشراء المفتوحة — لا يُعدّ توقّعًا'),
            ],
        ];
    }

    /* ─────────────────────────── الافتراضات ─────────────────────────── */

    /**
     * افتراضاتُ التوقّع الافتراضيّة — من التاريخ الفعليّ حيث يوجد، وإلّا صفرٌ يُقال.
     *
     * @return array<string, mixed>
     */
    public static function defaultAssumptions(int $bid): array
    {
        $now = now();
        $threeMonths = Period::between($bid, $now->copy()->startOfMonth()->subMonths(3), $now->copy()->startOfMonth());
        $expenses = FinancialPerformance::expenses($bid, $threeMonths);
        $expenseMonths = self::monthsWithExpenses($bid, $threeMonths);

        $payroll = PayrollRun::where('business_id', $bid)->whereIn('status', ['معتمدة', 'مصروفة'])
            ->orderByDesc('period')->limit(3)->get(['net']);

        return [
            'collection_delay_days' => 0,
            'overdue_collection_days' => 30,
            'recurring_monthly_expenses' => $expenseMonths > 0 ? round($expenses['total'] / $expenseMonths, 3) : 0.0,
            'recurring_source' => $expenseMonths > 0 ? __('متوسّطُ المصروفات المدفوعة في آخر :n أشهر', ['n' => $expenseMonths]) : __('لا تاريخَ مصروفاتٍ كافيًا — صفر'),
            'payroll_monthly' => $payroll->isEmpty() ? 0.0 : round((float) $payroll->avg('net'), 3),
            'payroll_source' => $payroll->isEmpty() ? __('لا مسيراتِ رواتبَ معتمَدة — صفر') : __('متوسّطُ صافي آخر :n مسيرات', ['n' => $payroll->count()]),
            'expense_growth_percent' => 0.0,
            'include_budget_expenses' => false,
            'include_pipeline' => false,
            'pipeline_conversion_percent' => 30.0,
        ];
    }

    /** @param  array<string, mixed>  $raw */
    public static function normalizeAssumptions(int $bid, array $raw): array
    {
        $defaults = self::defaultAssumptions($bid);
        $out = $defaults;
        foreach (['collection_delay_days', 'overdue_collection_days'] as $k) {
            if (isset($raw[$k]) && is_numeric($raw[$k])) {
                $out[$k] = max(0, min(365, (int) $raw[$k]));
            }
        }
        foreach (['recurring_monthly_expenses', 'payroll_monthly'] as $k) {
            if (isset($raw[$k]) && is_numeric($raw[$k])) {
                $out[$k] = round(max(0.0, (float) $raw[$k]), 3);
                $out[$k === 'recurring_monthly_expenses' ? 'recurring_source' : 'payroll_source'] = __('قيمةٌ كُتبت بيد');
            }
        }
        if (isset($raw['expense_growth_percent']) && is_numeric($raw['expense_growth_percent'])) {
            $out['expense_growth_percent'] = round(max(-100.0, min(100.0, (float) $raw['expense_growth_percent'])), 2);
        }
        if (isset($raw['pipeline_conversion_percent']) && is_numeric($raw['pipeline_conversion_percent'])) {
            $out['pipeline_conversion_percent'] = round(max(0.0, min(100.0, (float) $raw['pipeline_conversion_percent'])), 1);
        }
        $out['include_budget_expenses'] = (bool) ($raw['include_budget_expenses'] ?? false);
        $out['include_pipeline'] = (bool) ($raw['include_pipeline'] ?? false);

        return $out;
    }

    /* ─────────────────────────── التوقّع ─────────────────────────── */

    /**
     * إسقاطُ النقد على أفقٍ — أشهرٌ ببنودها ومصادرها.
     *
     * @param  array<string, mixed>  $assumptions
     * @return array<string, mixed>
     */
    public static function project(int $bid, int $horizonDays, array $assumptions, ?FinancialScenario $scenario = null, ?Carbon $base = null): array
    {
        $horizonDays = in_array($horizonDays, self::HORIZONS, true) ? $horizonDays : 90;
        $assumptions = self::normalizeAssumptions($bid, $assumptions);
        $base = ($base ?? now())->copy()->startOfDay();
        $end = $base->copy()->addDays($horizonDays);
        $registered = Vat::enabled($bid);
        $currency = Money::of($bid);

        $opening = FinancialPerformance::cashPosition($bid)['liquid'];
        $items = collect();
        $completeness = [];

        /* ─ ملتزَم: فواتيرُ عملاءَ قائمة ─ */
        $delay = (int) $assumptions['collection_delay_days'];
        $overdueDays = (int) $assumptions['overdue_collection_days'];
        $collectDelay = $scenario !== null ? $delay + self::scenarioDays($scenario, 'collection_delay_days') : $delay;
        foreach (CustomerInvoice::where('business_id', $bid)->where('status', CustomerInvoice::ISSUED)->whereRaw(CustomerInvoice::outstandingSql().' > 0.0005')->withMoney()
            ->with('customer:id,name')->orderBy('due_at')->limit(2000)->get() as $inv) {
            $amount = $inv->outstanding();
            $due = $inv->due_at?->copy()->startOfDay();
            $when = $due === null ? $base->copy()->addDays(30) : ($due->lt($base) ? $base->copy()->addDays($overdueDays) : $due->copy()->addDays($collectDelay));
            $items->push(self::item($when, $amount, 'in', 'committed', __('الفاتورة :n — :c', ['n' => $inv->number ?: '#'.$inv->id, 'c' => (string) ($inv->customer?->name ?? '')]),
                ['type' => 'customer_invoice', 'id' => $inv->id, 'ref' => $inv->number, 'url' => route('admin.customerInvoices.show', $inv->id)]));
        }

        /* ─ ملتزَم: سنداتُ مورّدين معتمَدة لم تُسدَّد ─ */
        foreach (SupplierInvoice::where('business_id', $bid)->owed()->whereRaw('(total - paid) > 0.0005')->with('supplier:id,name')->limit(2000)->get() as $si) {
            $when = ($si->due_at ?? $si->issued_at?->copy()->addDays(30) ?? $base->copy()->addDays(30))->copy()->startOfDay();
            $when = $when->lt($base) ? $base->copy() : $when;
            $items->push(self::item($when, round((float) $si->total - (float) $si->paid, 3), 'out', 'committed', __('سند مورّد :r — :s', ['r' => $si->supplier_ref, 's' => (string) ($si->supplier?->name ?? '')]),
                ['type' => 'supplier_invoice', 'id' => $si->id, 'ref' => $si->supplier_ref, 'url' => route('admin.purchases.invoices', ['q' => $si->supplier_ref])]));
        }

        /* ─ ملتزَم: مصروفاتٌ غير مدفوعة ─ */
        foreach (Expense::where('business_id', $bid)->unpaid()->limit(2000)->get() as $ex) {
            $when = ($ex->due_date ?? $ex->spent_at ?? $base)->copy()->startOfDay();
            $when = $when->lt($base) ? $base->copy() : $when;
            $items->push(self::item($when, (float) $ex->amount, 'out', 'committed', __('مصروف :d', ['d' => $ex->description ?: $ex->type]),
                ['type' => 'expense', 'id' => $ex->id, 'ref' => $ex->reference, 'url' => route('admin.expenses.index')]));
        }

        /* ─ ملتزَم: باقي أوامر الشراء المفتوحة ─ */
        foreach (self::openPurchaseOrders($bid) as $po) {
            $when = ($po['expected_at'] ?? $base->copy()->addDays(30))->copy()->startOfDay();
            $when = $when->lt($base) ? $base->copy() : $when;
            $items->push(self::item($when, $po['remaining'], 'out', 'committed', __('أمر شراء :n', ['n' => $po['number']]),
                ['type' => 'purchase_order', 'id' => $po['id'], 'ref' => $po['number'], 'url' => route('admin.purchases.show', $po['id'])]));
        }

        /* ─ متوقَّع: مصروفاتٌ متكرّرة ورواتبُ — شهرًا بشهر ─ */
        $budget = $assumptions['include_budget_expenses'] ? self::approvedBudgetExpenses($bid) : null;
        if ($assumptions['include_budget_expenses'] && $budget === null) {
            $completeness[] = __('لا موازنةَ معتمَدة لتُقرأ مصروفاتُها — استُعمل المتوسّط');
        }
        $expenseFactor = 1 + ((float) $assumptions['expense_growth_percent'] / 100);
        if ($scenario !== null) {
            $expenseFactor *= 1 + (self::scenarioPercent($scenario, 'expense_change_pct') / 100);
        }
        $month = $base->copy()->startOfMonth();
        $i = 0;
        while ($month->lt($end)) {
            $key = $month->format('Y-m');
            $when = $month->copy()->max($base);

            $recurring = $budget !== null && isset($budget[(int) $month->format('n')])
                ? (float) $budget[(int) $month->format('n')]
                : (float) $assumptions['recurring_monthly_expenses'] * (1 + (float) $assumptions['expense_growth_percent'] / 100) ** $i;
            $recurring = round($recurring * ($scenario !== null ? 1 + self::scenarioPercent($scenario, 'expense_change_pct') / 100 : 1), 3);
            if ($recurring > 0.0005) {
                $items->push(self::item($when->copy()->addDays(10)->min($end->copy()->subDay()), $recurring, 'out', 'expected',
                    $budget !== null ? __('مصروفاتُ الموازنة لشهر :m', ['m' => $key]) : __('مصروفاتٌ متكرّرة متوقَّعة لشهر :m', ['m' => $key]),
                    ['type' => $budget !== null ? 'budget' : 'assumption', 'ref' => $budget !== null ? __('موازنة معتمَدة') : $assumptions['recurring_source'], 'url' => null]));
            }
            $payroll = round((float) $assumptions['payroll_monthly'] * ($scenario !== null ? 1 + self::scenarioPercent($scenario, 'expense_change_pct') / 100 : 1), 3);
            if ($payroll > 0.0005) {
                $items->push(self::item($month->copy()->endOfMonth()->startOfDay()->min($end->copy()->subDay())->max($base), $payroll, 'out', 'expected',
                    __('رواتبُ شهر :m', ['m' => $key]), ['type' => 'assumption', 'ref' => $assumptions['payroll_source'], 'url' => null]));
            }
            $month->addMonth();
            $i++;
        }
        if ((float) $assumptions['payroll_monthly'] <= 0.0005) {
            $completeness[] = __('لا بياناتِ رواتبَ متكرّرة — الرواتب غير مضمَّنة');
        }
        if ((float) $assumptions['recurring_monthly_expenses'] <= 0.0005 && $budget === null) {
            $completeness[] = __('لا مصروفاتٍ متكرّرة مضمَّنة');
        }

        /* ─ خطُّ الأنابيب: عروضٌ مُرسَلة لم تُقبل — بنسبة تحوّل، منفصلةً ─ */
        if ($assumptions['include_pipeline']) {
            $rate = (float) $assumptions['pipeline_conversion_percent'] / 100;
            /* وتغيّرُ الإيراد في السيناريو يمسّ خطَّ الأنابيب وحدَه — الفواتيرُ الصادرة ثابتة */
            $rate *= $scenario !== null ? 1 + self::scenarioPercent($scenario, 'revenue_change_pct') / 100 : 1;
            foreach (Quotation::where('business_id', $bid)->where('status', Quotation::SENT)
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $base->toDateString()))->limit(500)->get() as $q) {
                $when = ($q->valid_until ?? $base->copy()->addDays(30))->copy()->startOfDay()->addDays(30)->max($base);
                $items->push(self::item($when, round((float) $q->total * $rate, 3), 'in', 'pipeline',
                    __('عرض :n × :p٪ تحوّل', ['n' => $q->number ?: '#'.$q->id, 'p' => $assumptions['pipeline_conversion_percent']]),
                    ['type' => 'quotation', 'id' => $q->id, 'ref' => $q->number, 'url' => route('admin.quotations.show', $q->id)]));
            }
        }

        /* ─ سيناريو: بنودٌ بيد ─ */
        if ($scenario !== null) {
            foreach (self::scenarioItems($scenario, $base, $end) as $it) {
                $items->push($it);
            }
        }

        /* ─ الأشهر ─ */
        $items = $items->filter(fn ($it) => $it['at']->gte($base) && $it['at']->lt($end))->sortBy(fn ($it) => $it['at']->timestamp)->values();
        $months = [];
        $running = $opening;
        $cursor = $base->copy()->startOfMonth();
        $categories = ['committed', 'expected', 'scenario', 'pipeline'];
        $totals = ['inflows' => array_fill_keys($categories, 0.0), 'outflows' => array_fill_keys($categories, 0.0)];

        while ($cursor->lt($end)) {
            $next = $cursor->copy()->addMonth();
            $monthItems = $items->filter(fn ($it) => $it['at']->gte($cursor) && $it['at']->lt($next));
            $in = array_fill_keys($categories, 0.0);
            $out = array_fill_keys($categories, 0.0);
            foreach ($monthItems as $it) {
                if ($it['direction'] === 'in') {
                    $in[$it['category']] += $it['amount'];
                } else {
                    $out[$it['category']] += $it['amount'];
                }
            }
            foreach ($categories as $c) {
                $in[$c] = round($in[$c], 3);
                $out[$c] = round($out[$c], 3);
                $totals['inflows'][$c] = round($totals['inflows'][$c] + $in[$c], 3);
                $totals['outflows'][$c] = round($totals['outflows'][$c] + $out[$c], 3);
            }
            /* الصافي المعتمَد لا يضمّ السيناريو ولا خطّ الأنابيب — يُقالان جنبَه */
            $inCore = round($in['committed'] + $in['expected'], 3);
            $outCore = round($out['committed'] + $out['expected'], 3);
            $net = round($inCore - $outCore, 3);
            $netAll = round(array_sum($in) - array_sum($out), 3);
            $closing = round($running + $net, 3);

            $months[] = [
                'month' => $cursor->format('Y-m'),
                'opening' => round($running, 3),
                'inflows' => $in + ['total' => $inCore, 'with_scenarios' => round(array_sum($in), 3)],
                'outflows' => $out + ['total' => $outCore, 'with_scenarios' => round(array_sum($out), 3)],
                'net' => $net,
                'net_with_scenarios' => $netAll,
                'closing' => $closing,
                'closing_with_scenarios' => round($running + $netAll, 3),
                'items' => $monthItems->take(self::ITEMS_PER_MONTH)->map(fn ($it) => ['at' => $it['at']->toDateString(), 'amount' => $it['amount'], 'direction' => $it['direction'],
                    'category' => $it['category'], 'label' => $it['label'], 'source' => $it['source']])->values()->all(),
                'more_items' => max(0, $monthItems->count() - self::ITEMS_PER_MONTH),
            ];
            $running = $closing;
            $cursor = $next;
        }

        $lowest = collect($months)->min('closing');
        $totalIn = round(array_sum($totals['inflows']) - $totals['inflows']['scenario'] - $totals['inflows']['pipeline'], 3);
        $totalOut = round(array_sum($totals['outflows']) - $totals['outflows']['scenario'] - $totals['outflows']['pipeline'], 3);

        return [
            'base_date' => $base->toDateString(),
            'horizon_days' => $horizonDays,
            'end_date' => $end->toDateString(),
            'currency' => $currency,
            'opening_cash' => round($opening, 3),
            'months' => $months,
            'totals' => [
                'inflows' => $totals['inflows'] + ['total' => $totalIn],
                'outflows' => $totals['outflows'] + ['total' => $totalOut],
                'net' => round($totalIn - $totalOut, 3),
                'closing' => round($opening + $totalIn - $totalOut, 3),
                'closing_with_scenarios' => round($opening + array_sum($totals['inflows']) - array_sum($totals['outflows']), 3),
                'lowest_closing' => $lowest === null ? round($opening, 3) : round((float) $lowest, 3),
                'items' => $items->count(),
            ],
            'assumptions' => $assumptions,
            'scenario' => $scenario === null ? null : ['id' => $scenario->id, 'name' => $scenario->name, 'kind' => $scenario->kind],
            'completeness' => ($completeness === [] ? Completeness::complete() : Completeness::partial($completeness))->toArray(),
            'basis' => __('الافتتاحيُّ من الدفتر (فعليّ)؛ والملتزَم مستنداتٌ قائمة بتواريخها؛ والمتوقَّع افتراضاتٌ مكتوبة؛ والسيناريو وخطُّ الأنابيب محاكاةٌ تُقال جنبَ الرقم ولا تدخل الإقفال المعتمَد'),
            'generated_at' => now()->format('Y-m-d H:i'),
        ];
    }

    /* ─────────────────────────── المدرج ─────────────────────────── */

    /**
     * المدرجُ النقديّ — النقدُ السائل ÷ متوسّطِ صافي الحرق الشهريّ (آخر ثلاثة أشهر كاملة من الدفتر).
     *
     * @return array<string, mixed>
     */
    public static function runway(int $bid): array
    {
        $liquid = FinancialPerformance::cashPosition($bid)['liquid'];
        $months = [];
        $cursor = now()->startOfMonth();
        for ($i = 1; $i <= 3; $i++) {
            $start = $cursor->copy()->subMonths($i);
            $end = $cursor->copy()->subMonths($i - 1);
            $months[] = self::cashMovement($bid, $start, $end) + ['month' => $start->format('Y-m')];
        }

        $active = array_filter($months, fn ($m) => $m['inflows'] > 0.0005 || $m['outflows'] > 0.0005);
        $definition = __('النقدُ السائل ÷ متوسّطُ صافي الحرق الشهريّ في آخر ثلاثة أشهر كاملة (مقبوضاتُ الدفتر − مدفوعاتُه على الصندوق والبنوك)');

        if (count($active) < 3) {
            return ['status' => 'insufficient_data', 'months_observed' => count($active), 'liquid' => $liquid, 'months' => $months, 'definition' => $definition,
                'reason' => __('يلزم ثلاثةُ أشهرٍ كاملة من حركة النقد — المرصود: :n', ['n' => count($active)])];
        }

        $avgNet = round(array_sum(array_column($months, 'net')) / 3, 3);
        if ($avgNet >= 0) {
            return ['status' => 'cash_positive', 'average_net' => $avgNet, 'liquid' => $liquid, 'months' => $months, 'definition' => $definition,
                'reason' => __('صافي الحرق موجب — الشركة لا تستهلك نقدها في المتوسّط')];
        }

        return ['status' => 'ok', 'average_net' => $avgNet, 'liquid' => $liquid, 'months' => $months, 'definition' => $definition,
            'runway_months' => round($liquid / abs($avgNet), 1)];
    }

    /**
     * حركةُ النقد الفعليّة في مدّة — من الدفتر: ما دخل الصندوقَ والبنوك وما خرج، بلا التحويلات الداخليّة.
     *
     * @return array{inflows: float, outflows: float, net: float}
     */
    public static function cashMovement(int $bid, Carbon $start, Carbon $endExclusive): array
    {
        $liquid = self::liquidAccountIds($bid);
        if ($liquid === []) {
            return ['inflows' => 0.0, 'outflows' => 0.0, 'net' => 0.0];
        }

        $rows = JournalEntry::where('journal_entries.business_id', $bid)->toBase()
            ->join('journal_lines', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->whereIn('journal_lines.account_id', $liquid)
            ->where('journal_entries.entry_date', '>=', $start->toDateString())->where('journal_entries.entry_date', '<', $endExclusive->toDateString())
            ->selectRaw('journal_entries.id AS eid, COALESCE(SUM(journal_lines.debit), 0) AS d, COALESCE(SUM(journal_lines.credit), 0) AS c')
            ->groupBy('journal_entries.id')->get();

        $in = 0.0;
        $out = 0.0;
        foreach ($rows as $r) {
            /* قيدٌ يمسّ النقدَ من طرفيه (تحويلٌ بين صندوقٍ وبنك) يُعدّ بصافيه */
            $net = (float) $r->d - (float) $r->c;
            if ($net > 0) {
                $in += $net;
            } else {
                $out += -$net;
            }
        }

        return ['inflows' => round($in, 3), 'outflows' => round($out, 3), 'net' => round($in - $out, 3)];
    }

    /** @return list<int> */
    public static function liquidAccountIds(int $bid): array
    {
        $ids = [];
        foreach (['cash', 'bank'] as $key) {
            $a = Ledger::account($bid, $key);
            if ($a !== null) {
                $ids[] = (int) $a->id;
                foreach ($a->children()->pluck('id') as $child) {
                    $ids[] = (int) $child;
                }
            }
        }
        /* وأوراقُ الحسابات البنكيّة المسجَّلة */
        foreach (\App\Models\BankAccount::where('business_id', $bid)->whereNotNull('account_id')->pluck('account_id') as $id) {
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }

    /* ─────────────────────────── أدوات ─────────────────────────── */

    /** @return Collection<int, array{id: int, number: string, remaining: float, expected_at: ?Carbon}> */
    public static function openPurchaseOrders(int $bid): Collection
    {
        $registered = Vat::enabled($bid);
        $covered = SupplierInvoice::where('business_id', $bid)->where('approval_status', SupplierInvoices::APPROVED)
            ->whereNotNull('purchase_order_id')->groupBy('purchase_order_id')
            ->selectRaw('purchase_order_id, SUM('.($registered ? '(total - tax)' : 'total').') AS covered')->pluck('covered', 'purchase_order_id');

        return PurchaseOrder::where('business_id', $bid)->where('status', '<>', PurchaseOrders::DRAFT)->whereNull('closed_at')
            ->limit(1000)->get(['id', 'number', 'total', 'tax', 'expected_delivery_at'])
            ->map(function (PurchaseOrder $po) use ($registered, $covered) {
                $basis = $registered ? (float) $po->total - (float) $po->tax : (float) $po->total;

                return ['id' => $po->id, 'number' => (string) $po->number, 'remaining' => round(max(0.0, $basis - (float) ($covered[$po->id] ?? 0)), 3), 'expected_at' => $po->expected_delivery_at];
            })->filter(fn ($r) => $r['remaining'] > 0.0005)->values();
    }

    /** مصروفاتُ الموازنة المعتمَدة لسنة الأساس — بالشهر */
    private static function approvedBudgetExpenses(int $bid): ?array
    {
        $budget = Budget::where('business_id', $bid)->where('status', Budget::APPROVED)->where('fiscal_year', (int) now()->format('Y'))->first();
        if ($budget === null) {
            return null;
        }

        return BudgetLine::where('budget_id', $budget->id)->where('kind', BudgetLine::EXPENSE)->toBase()
            ->selectRaw('month, COALESCE(SUM(planned_amount), 0) AS s')->groupBy('month')->pluck('s', 'month')
            ->map(fn ($v) => round((float) $v, 3))->all();
    }

    private static function monthsWithExpenses(int $bid, Period $period): int
    {
        return count(FinancialPerformance::monthlySeries(
            Expense::where('business_id', $bid)->paid()->whereDate('spent_at', '>=', $period->startDate())->whereDate('spent_at', '<', $period->endDate())->toBase(),
            'spent_at', 'amount', 'date'));
    }

    private static function item(Carbon $at, float $amount, string $direction, string $category, string $label, array $source): array
    {
        return ['at' => $at->copy()->startOfDay(), 'amount' => round($amount, 3), 'direction' => $direction, 'category' => $category, 'label' => $label, 'source' => $source];
    }

    private static function scenarioPercent(FinancialScenario $scenario, string $key): float
    {
        return (float) $scenario->assumptions->where('key', $key)->sum(fn ($a) => (float) $a->percent);
    }

    private static function scenarioDays(FinancialScenario $scenario, string $key): int
    {
        return (int) $scenario->assumptions->where('key', $key)->sum(fn ($a) => (int) $a->amount);
    }

    /** @return list<array<string, mixed>> */
    private static function scenarioItems(FinancialScenario $scenario, Carbon $base, Carbon $end): array
    {
        $out = [];
        foreach ($scenario->assumptions as $a) {
            $starts = ($a->starts_on ?? $base)->copy()->startOfDay()->max($base);
            $source = ['type' => 'scenario', 'ref' => $scenario->name.' — '.$a->label, 'url' => null];
            switch ($a->key) {
                case 'one_time_outflow':
                    $out[] = self::item($starts, (float) $a->amount, 'out', 'scenario', $a->label, $source);
                    break;
                case 'expected_contract':
                    $out[] = self::item($starts, (float) $a->amount, 'in', 'scenario', $a->label, $source);
                    break;
                case 'monthly_outflow':
                case 'monthly_inflow':
                    $months = max(1, (int) ($a->months ?? 12));
                    $cursor = $starts->copy();
                    for ($i = 0; $i < $months && $cursor->lt($end); $i++) {
                        $out[] = self::item($cursor->copy(), (float) $a->amount, $a->key === 'monthly_inflow' ? 'in' : 'out', 'scenario', $a->label.' ('.$cursor->format('Y-m').')', $source);
                        $cursor->addMonth();
                    }
                    break;
                default:
                    /* النسبُ (revenue_change_pct · expense_change_pct · collection_delay_days) تُطبَّق حيث تُقرأ لا بندًا */
                    break;
            }
        }

        return $out;
    }
}
