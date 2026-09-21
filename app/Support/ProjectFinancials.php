<?php

namespace App\Support;

use App\Models\ChangeOrder;
use App\Models\CustomerInvoice;
use App\Models\Expense;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\StockAdjustment;
use App\Models\SupplierInvoice;
use Illuminate\Support\Collection;

/**
 * ماليّةُ المشروع — قراءةٌ فوق المستندات، ولا جدولَ ماليًّا جديدًا (§16).
 *
 * ═══ ثلاثةُ أوعيةٍ لا تتداخل ═══
 *
 * • **الفعليّ** — ما صار تكلفةً في الدفتر: سندُ المورّد **المعتمَد** بكامله
 *   (سُدِّد أم لا — الذمّةُ نشأت باعتماده: `SupplierInvoice::scopeOwed`)،
 *   والمصروفُ **المدفوع** (`Expense::scopePaid` — «المدفوعُ وحده مصروف»)،
 *   والعتادُ المستهلَك.
 * • **الملتزَم** — وُعد به ولم يصر قيدًا: رصيدُ أمر الشراء بعد ما غطّته
 *   سنداتُه المعتمَدة، وسندٌ معلَّقٌ بلا أمر شراء، ومصروفٌ لم يُسدَّد.
 *   وسندٌ يخرج من هذا الوعاء يومَ يُعتمد ويدخل الفعليَّ في اللحظة نفسِها.
 * • **المستحقُّ للموردين** — ما لم يُسدَّد من المعتمَد: **جزءٌ من الفعليّ**
 *   لا إضافةٌ إليه.
 *
 * ═══ وعلى أساس الدفتر نفسِه ═══
 *
 * الإيرادُ صافٍ من ضريبته، فالتكلفةُ كذلك: المسجَّلُ في الضريبة يستردّ
 * ضريبةَ مشترياته — والدفترُ يقيّد ثمنَ البضاعة صافيًا ويقيّد الضريبةَ في
 * «الضريبة المستحقّة» (انظر `SupplierInvoices::approve`). فسندُ المورّد وأمرُ
 * الشراء يُقرآن هنا صافيَين له، وإجماليَّين لمن لم يُسجَّل — والذمّةُ للمورّد
 * إجماليّةٌ دائمًا: ما يُدفع له لا ما يُقيَّد تكلفة.
 *
 * وإشعارُ الدائن ينقص الإيرادَ كما ينقصه في الدفتر (`sales_returns`): فوترةٌ
 * فوق نطاقٍ نقص ثمّ صُحّحت بإشعارٍ لا تبقى «فوق المعتمَد» بعد تصحيحها.
 *
 * ═══ وتعريفُ الربح في موضعٍ واحد ═══
 *
 * المستودعُ دفع ثمنَ تعريفَين مختلفين للربح من قبل — `Demo::reportSummary`.
 * فهنا وحدَه يُكتب، ومنه تقرأ الشاشةُ والتقريرُ ولقطةُ الإقفال.
 */
final class ProjectFinancials
{
    /**
     * @return array<string, float|bool>
     */
    public static function summary(Project $project): array
    {
        return self::summaries((int) $project->business_id, collect([$project]))[(int) $project->id];
    }

    /**
     * الصيغةُ نفسُها لمشاريعَ كثيرة — بعشرة استعلاماتٍ لا بعشرةٍ لكلّ مشروع (المرحلة 12 §57).
     *
     * كانت قائمةُ الربحيّة تسأل القاعدةَ عشرَ مرّاتٍ لكلّ مشروع، فخمسمئةُ
     * مشروعٍ خمسةُ آلاف استعلام. هنا تُجمع كلُّ فئةٍ مجمَّعةً بالمشروع، ثمّ
     * تُحسب الأرقامُ بالصيغة ذاتها؛ و`summary()` لمشروعٍ واحد يمرّ من هنا —
     * فلا صيغتان.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, array<string, mixed>> مفتاحُه معرّفُ المشروع
     */
    public static function summaries(int $bid, Collection $projects): array
    {
        $ids = $projects->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return [];
        }
        $registered = Vat::enabled($bid);
        $rate = Vat::rate($bid);
        $basis = self::costBasis($registered);

        $invoices = CustomerInvoice::where('business_id', $bid)->whereIn('project_id', $ids)->where('status', CustomerInvoice::ISSUED)->toBase()
            ->selectRaw('project_id, COALESCE(SUM(total), 0) AS invoiced, COALESCE(SUM(tax_total), 0) AS invoiced_tax')
            ->selectRaw('COALESCE(SUM('.CustomerInvoice::paidSql().'), 0) AS collected')
            ->selectRaw('COALESCE(SUM('.CustomerInvoice::creditedSql().'), 0) AS credited')
            ->selectRaw('COALESCE(SUM('.self::creditedTaxSql().'), 0) AS credited_tax')
            ->groupBy('project_id')->get()->keyBy('project_id');

        $owed = SupplierInvoice::where('business_id', $bid)->whereIn('project_id', $ids)->owed()->toBase()
            ->selectRaw('project_id, COALESCE(SUM('.$basis.'), 0) AS actual, COALESCE(SUM(total - paid), 0) AS outstanding')
            ->groupBy('project_id')->get()->keyBy('project_id');

        $pending = SupplierInvoice::where('business_id', $bid)->whereIn('project_id', $ids)
            ->where('approval_status', SupplierInvoices::PENDING)->whereNull('purchase_order_id')->toBase()
            ->selectRaw('project_id, COALESCE(SUM('.$basis.'), 0) AS v')->groupBy('project_id')->pluck('v', 'project_id');

        $expensesPaid = Expense::where('business_id', $bid)->whereIn('project_id', $ids)->paid()->toBase()
            ->selectRaw('project_id, COALESCE(SUM(amount), 0) AS v')->groupBy('project_id')->pluck('v', 'project_id');
        $expensesUnpaid = Expense::where('business_id', $bid)->whereIn('project_id', $ids)->unpaid()->toBase()
            ->selectRaw('project_id, COALESCE(SUM(amount), 0) AS v')->groupBy('project_id')->pluck('v', 'project_id');

        /*
         * والاستهلاكُ صافي ما خرج من الرفّ إلى الفعاليّة: الصرفُ سالبٌ والإرجاعُ
         * موجب — فما صُرف ٢٢٠٠ وعاد منه ٣٠٠ استُهلك ١٩٠٠ (المرحلة 11،
         * `Operations\Consumables`). وبتكلفة لحظة الصرف لا تكلفة اليوم.
         */
        $stock = StockAdjustment::where('business_id', $bid)->whereIn('project_id', $ids)->toBase()
            ->selectRaw('project_id, COALESCE(SUM(quantity_delta * cost_at_time), 0) AS v')->groupBy('project_id')->pluck('v', 'project_id');

        $deltas = ChangeOrder::where('business_id', $bid)->whereIn('project_id', $ids)->accepted()->toBase()
            ->selectRaw('project_id, COALESCE(SUM(total_delta), 0) AS v')->groupBy('project_id')->pluck('v', 'project_id');

        $openPo = self::openPurchaseOrdersFor($bid, $ids, $registered);

        $out = [];
        foreach ($projects as $project) {
            $pid = (int) $project->id;
            $inv = $invoices->get($pid);
            $invoiced = round((float) ($inv->invoiced ?? 0), 3);
            $invoicedTax = round((float) ($inv->invoiced_tax ?? 0), 3);
            $collected = round((float) ($inv->collected ?? 0), 3);
            $credited = round((float) ($inv->credited ?? 0), 3);
            $creditedTax = round((float) ($inv->credited_tax ?? 0), 3);

            $netInvoiced = round($invoiced - $credited, 3);
            $revenue = round(($invoiced - $invoicedTax) - ($credited - $creditedTax), 3);

            $supplierActual = round((float) ($owed->get($pid)->actual ?? 0), 3);
            $supplierOutstanding = round((float) ($owed->get($pid)->outstanding ?? 0), 3);
            $paidExpenses = round((float) ($expensesPaid[$pid] ?? 0), 3);
            $consumed = round(max(0.0, -(float) ($stock[$pid] ?? 0)), 3);
            $actual = round($supplierActual + $paidExpenses + $consumed, 3);

            $po = round((float) ($openPo[$pid] ?? 0), 3);
            $pendingWithoutPo = round((float) ($pending[$pid] ?? 0), 3);
            $unpaidExpenses = round((float) ($expensesUnpaid[$pid] ?? 0), 3);
            $committed = round($po + $pendingWithoutPo + $unpaidExpenses, 3);

            $currentValue = round((float) $project->approved_value + (float) ($deltas[$pid] ?? 0), 3);
            $currentNet = round($currentValue - self::taxAt($rate, $currentValue), 3);

            $realized = round($revenue - $actual, 3);
            $expected = round($actual + $committed, 3);
            $forecast = round($currentNet - $expected, 3);

            $out[$pid] = [
                'quoted_value' => round((float) $project->quoted_value, 3),
                'approved_value' => round((float) $project->approved_value, 3),
                'current_value' => $currentValue,
                'invoiced' => $invoiced,
                'credited' => $credited,
                'net_invoiced' => $netInvoiced,
                'revenue' => $revenue,
                'collected' => $collected,
                'customer_outstanding' => round(max(0.0, $invoiced - $collected - $credited), 3),
                'supplier_actual' => $supplierActual,
                'expenses_paid' => $paidExpenses,
                'stock_consumed' => $consumed,
                'actual' => $actual,
                'open_purchase_orders' => $po,
                'pending_supplier_invoices' => $pendingWithoutPo,
                'expenses_unpaid' => $unpaidExpenses,
                'committed' => $committed,
                'supplier_outstanding' => $supplierOutstanding,
                'expected_cost' => $expected,
                'realized_gross_profit' => $realized,
                'realized_margin_percent' => $revenue > 0 ? round($realized / $revenue * 100, 2) : 0.0,
                'forecast_margin' => $forecast,
                'forecast_margin_percent' => $currentNet > 0 ? round($forecast / $currentNet * 100, 2) : 0.0,
                /* نقصٌ قُبل بعد فوترة — إشعارُ دائنٍ ينتظر يدًا (§5.2)، وما صُحّح بإشعارٍ لم يعد فوق المعتمَد */
                'invoiced_above_approved' => $netInvoiced > $currentValue + 0.0005,
            ];
        }

        return $out;
    }

    /** ضريبةُ إشعارات الدائن على الفاتورة — على نمط `CustomerInvoice::creditedSql` */
    private static function creditedTaxSql(): string
    {
        return '(SELECT COALESCE(SUM(n.tax_amount), 0) FROM customer_credit_notes n'
            .' WHERE n.customer_invoice_id = customer_invoices.id)';
    }

    /** ما يُقيَّد تكلفةً من سند المورّد أو أمر الشراء: صافيًا لمن يستردّ ضريبته، وإجماليًّا لمن لا */
    private static function costBasis(bool $registered): string
    {
        return $registered ? '(total - tax)' : 'total';
    }

    /**
     * رصيدُ أوامر الشراء المفتوحة — ما لم تغطِّه سنداتٌ معتمَدة.
     *
     * أمرًا أمرًا لا مجموعًا: أمرٌ فُوتر بأكثر من قيمته (فرقُ شحن) لا يُعوّض
     * أمرًا لم يُفوتَر بعد. والمسودّةُ لا تُحسب: نيّةٌ لم تخرج إلى المورّد.
     * والمُقفَلُ لا يُحسب: استُلم وفُوتر بأقلّ، وقال من يملك ذلك إنّ الباقي
     * لن يأتي — انظر `PurchaseOrders::close`.
     */
    /**
     * باقي أوامر الشراء المفتوحة لكلّ مشروع — ما لم تغطِّه سنداتٌ معتمَدة.
     *
     * @param  list<int>  $ids
     * @return array<int, float>
     */
    private static function openPurchaseOrdersFor(int $bid, array $ids, bool $registered): array
    {
        $covered = SupplierInvoice::where('business_id', $bid)
            ->where('approval_status', SupplierInvoices::APPROVED)
            ->whereNotNull('purchase_order_id')
            ->groupBy('purchase_order_id')
            ->selectRaw('purchase_order_id, SUM('.self::costBasis($registered).') AS covered')
            ->pluck('covered', 'purchase_order_id');

        $sums = [];
        foreach (PurchaseOrder::where('business_id', $bid)->whereIn('project_id', $ids)
            ->where('status', '<>', PurchaseOrders::DRAFT)
            ->whereNull('closed_at')
            ->get(['id', 'project_id', 'total', 'tax']) as $po) {
            $basis = $registered ? (float) $po->total - (float) $po->tax : (float) $po->total;
            $sums[(int) $po->project_id] = ($sums[(int) $po->project_id] ?? 0.0) + max(0.0, $basis - (float) ($covered[$po->id] ?? 0));
        }

        return $sums;
    }

    private static function taxAt(float $rate, float $gross): float
    {
        return $rate <= 0 || $gross <= 0 ? 0.0 : round($gross - $gross / (1 + $rate / 100), 3);
    }

    private static function openPurchaseOrders(int $bid, int $pid, bool $registered): float
    {
        $covered = SupplierInvoice::where('business_id', $bid)
            ->where('approval_status', SupplierInvoices::APPROVED)
            ->whereNotNull('purchase_order_id')
            ->groupBy('purchase_order_id')
            ->selectRaw('purchase_order_id, SUM('.self::costBasis($registered).') AS covered')
            ->pluck('covered', 'purchase_order_id');

        $sum = 0.0;

        foreach (PurchaseOrder::where('business_id', $bid)->where('project_id', $pid)
            ->where('status', '<>', PurchaseOrders::DRAFT)
            ->whereNull('closed_at')
            ->get(['id', 'total', 'tax']) as $po) {
            $basis = $registered ? (float) $po->total - (float) $po->tax : (float) $po->total;
            $sum += max(0.0, $basis - (float) ($covered[$po->id] ?? 0));
        }

        return $sum;
    }

    /**
     * الضريبةُ الكامنة في قيمةٍ معتمَدة — بنسبة الشركة لا ببندٍ بند.
     *
     * تقديرٌ للهامش المتوقَّع لا رقمٌ يُقيَّد: القيمةُ المعتمَدة وعدٌ، والضريبةُ
     * الفعليّة تُعرف يومَ تُصدَر الفاتورة.
     */
    private static function taxOf(Project $project, float $gross): float
    {
        $rate = Vat::rate((int) $project->business_id);

        return $rate <= 0 || $gross <= 0 ? 0.0 : round($gross - $gross / (1 + $rate / 100), 3);
    }
}
