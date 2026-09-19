<?php

namespace App\Support;

use App\Models\CustomerInvoice;
use App\Models\Expense;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\StockAdjustment;
use App\Models\SupplierInvoice;

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
        $bid = (int) $project->business_id;
        $pid = (int) $project->id;
        $registered = Vat::enabled($bid);

        /* ─ الإيراد ─ */
        $issued = CustomerInvoice::where('business_id', $bid)->where('project_id', $pid)
            ->where('status', CustomerInvoice::ISSUED);

        $invoiced = round((float) (clone $issued)->sum('total'), 3);
        $invoicedTax = round((float) (clone $issued)->sum('tax_total'), 3);
        /* والمحصَّلُ بقاعدة `paidSql` نفسِها: تستبعد الملغى والشيكَ المرتجع */
        $collected = round((float) (clone $issued)
            ->selectRaw('COALESCE(SUM('.CustomerInvoice::paidSql().'), 0) AS v')->value('v'), 3);
        $credited = round((float) (clone $issued)
            ->selectRaw('COALESCE(SUM('.CustomerInvoice::creditedSql().'), 0) AS v')->value('v'), 3);
        $creditedTax = round((float) (clone $issued)
            ->selectRaw('COALESCE(SUM('.self::creditedTaxSql().'), 0) AS v')->value('v'), 3);

        $netInvoiced = round($invoiced - $credited, 3);
        $revenue = round(($invoiced - $invoicedTax) - ($credited - $creditedTax), 3);

        /* ─ الفعليّ ─ */
        $owed = SupplierInvoice::where('business_id', $bid)->where('project_id', $pid)->owed();
        $supplierActual = round((float) (clone $owed)
            ->selectRaw('COALESCE(SUM('.self::costBasis($registered).'), 0) AS v')->value('v'), 3);
        $supplierOutstanding = round((float) (clone $owed)
            ->selectRaw('COALESCE(SUM(total - paid), 0) AS v')->value('v'), 3);

        $expensesPaid = round((float) Expense::where('business_id', $bid)->where('project_id', $pid)
            ->paid()->sum('amount'), 3);

        /*
         * والاستهلاكُ سالبُ الكميّة: ما خرج من الرفّ إلى الفعاليّة.
         *
         * مؤجَّل — لا كاتبَ لـ`stock_adjustments.project_id` بعد. وقبل أن يُفتح
         * بابُه يُقرَّر الأساس: بضاعةٌ اشتُريت بأمر شراءٍ على المشروع تُقرأ هنا
         * بسند مورّدها؛ فإن استُهلكت من الرفّ باسمه أيضًا عُدّت مرّتين.
         */
        $stock = round(abs((float) StockAdjustment::where('business_id', $bid)->where('project_id', $pid)
            ->where('quantity_delta', '<', 0)
            ->selectRaw('COALESCE(SUM(quantity_delta * cost_at_time), 0) AS v')->value('v')), 3);

        $actual = round($supplierActual + $expensesPaid + $stock, 3);

        /* ─ الملتزَم ─ */
        $openPo = round(self::openPurchaseOrders($bid, $pid, $registered), 3);

        $pendingWithoutPo = round((float) SupplierInvoice::where('business_id', $bid)->where('project_id', $pid)
            ->where('approval_status', SupplierInvoices::PENDING)
            ->whereNull('purchase_order_id')
            ->selectRaw('COALESCE(SUM('.self::costBasis($registered).'), 0) AS v')->value('v'), 3);

        $expensesUnpaid = round((float) Expense::where('business_id', $bid)->where('project_id', $pid)
            ->unpaid()->sum('amount'), 3);

        $committed = round($openPo + $pendingWithoutPo + $expensesUnpaid, 3);

        /* ─ القيمة ─ */
        $currentValue = ProjectScope::currentValue($project);
        $currentNet = round($currentValue - self::taxOf($project, $currentValue), 3);

        $realized = round($revenue - $actual, 3);
        $expected = round($actual + $committed, 3);
        $forecast = round($currentNet - $expected, 3);

        return [
            'quoted_value' => round((float) $project->quoted_value, 3),
            'approved_value' => round((float) $project->approved_value, 3),
            'current_value' => $currentValue,
            'invoiced' => $invoiced,
            'credited' => $credited,
            'net_invoiced' => $netInvoiced,
            'collected' => $collected,
            'customer_outstanding' => round(max(0.0, $invoiced - $collected - $credited), 3),
            'supplier_actual' => $supplierActual,
            'expenses_paid' => $expensesPaid,
            'stock_consumed' => $stock,
            'actual' => $actual,
            'open_purchase_orders' => $openPo,
            'pending_supplier_invoices' => $pendingWithoutPo,
            'expenses_unpaid' => $expensesUnpaid,
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
