<?php

namespace Tests\Feature\V1;

use App\Models\ChangeOrder;
use App\Models\CustomerInvoice;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Support\Books;
use App\Support\ChangeOrders;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Intelligence\CashIntelligence;
use App\Support\Intelligence\CustomerIntelligence;
use App\Support\Intelligence\ExecutiveMetrics;
use App\Support\Intelligence\FinancialPerformance;
use App\Support\Intelligence\ManagementReports;
use App\Support\Intelligence\Period;
use App\Support\Intelligence\ProjectProfitability;
use App\Support\Intelligence\RevenueIntelligence;
use App\Support\Ledger;
use App\Support\Operations\Consumables;
use App\Support\Operations\EventOperations;
use App\Support\ProjectFinancials;
use App\Support\PurchaseOrders;
use App\Support\Quotations;
use App\Support\Receivables;
use App\Support\Revenue;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Management\ManagementTestCase;

/**
 * الشركةُ الذهبيّة — بصمةٌ ماليّة ثابتة بأرقامٍ معروفة (المرحلة 12 §17 · §21 · §89).
 *
 * عرضٌ يُقبل → مشروعٌ → أمرُ تغييرٍ يُقبل → سندُ مورّدٍ لأصنافٍ مخزونة وآخرُ
 * لخدمة → مستهلَكاتٌ تُصرف وتُردّ → مصروفٌ يُدفع → فاتورةٌ → دفعةٌ جزئيّة →
 * إشعارُ دائن. ثمّ يُسأل كلُّ موضعٍ يقرأ المالَ — المشروعُ والدفترُ والإدارةُ
 * والتقاريرُ — فيجيب بالرقم نفسِه، وكلُّ قيدٍ متوازن.
 *
 * الضريبةُ 5٪ والشركةُ مسجَّلة. والأرقامُ إلى ثلاث منازل.
 */
class TheGoldenCompanyKeepsOneSetOfBooksTest extends ManagementTestCase
{
    use RefreshDatabase;

    private Project $goldenProject;

    private CustomerInvoice $goldenInvoice;

    private function balance(string $key): float
    {
        return round(Ledger::balance((int) $this->business->id, $key), 3);
    }

    /** الحكايةُ كلُّها — تُبنى مرّةً وتُقرأ من كلّ باب */
    private function build(): void
    {
        $bid = (int) $this->business->id;
        $this->actingAs($this->owner);

        /* 1) عرضٌ: 2 × 1500 + 1 × 1000 = 4000 صافي + 200 ضريبة = 4200 */
        $q = Quotations::create($bid, $this->customer, ['title' => 'مؤتمر الطاقة', 'issued_at' => '2026-09-01'],
            [['description' => 'تنظيم', 'quantity' => 2, 'unit_price' => 1500], ['description' => 'تسجيل', 'quantity' => 1, 'unit_price' => 1000]], $this->owner);
        Quotations::send($q, $this->owner);
        Quotations::recordDecision($q->fresh(), 'accepted', ['decided_by_name' => 'سالم'], $this->owner);
        $project = Project::where('quotation_id', $q->id)->firstOrFail();
        $project->update(['starts_at' => '2026-09-05', 'ends_at' => '2026-09-06', 'status' => Project::IN_PROGRESS]);

        /* 2) أمرُ تغييرٍ مقبول: +1 تسجيل = +1000 صافي (+50 ضريبة) → المتعاقَد 5250 */
        $line = $project->items()->where('description', 'تسجيل')->firstOrFail();
        $co = ChangeOrders::create($project->fresh(), ['title' => 'مقعدٌ إضافيّ', 'requested_by_party' => 'customer'], [['project_item_id' => $line->id, 'quantity' => 1]], [], $this->owner);
        ChangeOrders::send($co, $this->owner);
        ChangeOrders::recordDecision($co->fresh(), ChangeOrder::ACCEPTED, [], $this->owner);

        /* 3) سندُ مورّدٍ على أمرِ شراءٍ لبطاقاتٍ مخزونة: 500 + 25 ضريبة — مخزون */
        $badge = $this->product('بطاقة', 500, 1.0);
        $po = PurchaseOrder::create(['business_id' => $bid, 'number' => 'PO-G1', 'supplier_id' => $this->supplier->id, 'supplier_name' => $this->supplier->name, 'status' => PurchaseOrders::SENT,
            'items_subtotal' => 500, 'tax' => 25, 'total' => 525, 'ordered_at' => now(), 'project_id' => $project->id]);
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $badge->id, 'name' => 'بطاقة', 'cost' => 1, 'quantity' => 500, 'line_total' => 500]);
        $si1 = SupplierInvoices::create($bid, ['supplier_id' => $this->supplier->id, 'purchase_order_id' => $po->id, 'supplier_ref' => 'G-1', 'issued_at' => '2026-09-03', 'subtotal' => 500, 'tax' => 25], $this->owner);
        SupplierInvoices::approve($si1, $this->owner, 'اعتمادٌ مباشر');

        /* 4) سندُ خدمةٍ بلا أمر: 1200 + 60 — تكلفةٌ مباشرة */
        $si2 = SupplierInvoices::create($bid, ['supplier_id' => $this->supplier->id, 'supplier_ref' => 'G-2', 'issued_at' => '2026-09-04', 'subtotal' => 1200, 'tax' => 60], $this->owner);
        $si2->update(['project_id' => $project->id]);
        SupplierInvoices::approve($si2->fresh(), $this->owner, 'اعتمادٌ مباشر');

        /* 5) مستهلَكات: تُصرف 200 وتُردّ 50 — المستهلَك 150 × 1 */
        $op = EventOperations::create($project->fresh(), ['name' => 'اليوم الأوّل', 'venue_name' => 'قاعة عُمان', 'event_starts_at' => '2026-09-05 09:00', 'event_ends_at' => '2026-09-05 17:00', 'operations_lead_id' => $this->owner->id], $this->owner);
        $issue = Consumables::issue($op, $badge, ['quantity' => 200, 'key' => 'g'], $this->owner);
        Consumables::giveBack($issue, ['quantity' => 50], $this->owner);

        /* 6) مصروفٌ مدفوع نقدًا 300 على المشروع — يُرحَّل */
        $expense = Expense::create(['business_id' => $bid, 'reference' => 'EXP-G', 'type' => 'نقل وتوصيل', 'description' => 'شاحنة', 'amount' => 300, 'method' => 'نقدي', 'status' => Expense::PAID, 'spent_at' => '2026-09-06', 'project_id' => $project->id]);
        Books::postExpense($expense);

        /* 7) فاتورةٌ على المشروع بكامل المتعاقَد: 5000 صافي + 250 = 5250 */
        $invoice = CustomerInvoices::create($bid, $this->customer, ['project_id' => $project->id, 'issued_at' => '2026-09-10', 'due_at' => '2026-10-10'],
            [['description' => 'تنظيم', 'quantity' => 2, 'unit_price' => 1500], ['description' => 'تسجيل', 'quantity' => 2, 'unit_price' => 1000]], $this->owner->id);
        $invoice = CustomerInvoices::issue($invoice->fresh(), $this->owner->id);
        $invoice->forceFill(['issued_at' => '2026-09-10'])->save();

        /* 8) دفعةٌ جزئيّة 2000 نقدًا · 9) إشعارُ دائن 210 (200 + 10) */
        CustomerPayments::record($bid, $this->customer, 2000, ['method' => 'نقدي', 'occurred_at' => '2026-09-12'], [$invoice->id => 2000], $this->owner->id);
        CustomerInvoices::creditNote($invoice->fresh(), 210, 10, 'خصمٌ لاحق', $this->owner->id);

        $this->goldenProject = $project->fresh();
        $this->goldenInvoice = $invoice->fresh();
    }

    public function test_every_reader_of_money_answers_with_the_same_numbers(): void
    {
        $this->build();
        $bid = (int) $this->business->id;
        $period = Period::resolve($bid, 'this_month');

        /* ─ المشروع (الصيغةُ الواحدة) ─ */
        $f = ProjectFinancials::summary($this->goldenProject);
        $this->assertEqualsWithDelta(4200.0, $f['approved_value'], 0.0005, 'الأصلُ بإجماليّه');
        $this->assertEqualsWithDelta(5250.0, $f['current_value'], 0.0005, 'بعد أمر التغيير');
        $this->assertEqualsWithDelta(5250.0, $f['invoiced'], 0.0005);
        $this->assertEqualsWithDelta(210.0, $f['credited'], 0.0005);
        $this->assertEqualsWithDelta(5040.0, $f['net_invoiced'], 0.0005);
        $this->assertEqualsWithDelta(4800.0, $f['revenue'], 0.0005, 'صافٍ من الضريبة بعد الإشعار: 5000 − 200');
        $this->assertEqualsWithDelta(2000.0, $f['collected'], 0.0005);
        $this->assertEqualsWithDelta(3040.0, $f['customer_outstanding'], 0.0005, '5250 − 2000 − 210');
        $this->assertEqualsWithDelta(1700.0, $f['supplier_actual'], 0.0005, '500 + 1200 صافيةً من الضريبة');
        $this->assertEqualsWithDelta(300.0, $f['expenses_paid'], 0.0005);
        $this->assertEqualsWithDelta(150.0, $f['stock_consumed'], 0.0005, '200 صُرفت − 50 رُدّت');
        $this->assertEqualsWithDelta(2150.0, $f['actual'], 0.0005);
        $this->assertEqualsWithDelta(2650.0, $f['realized_gross_profit'], 0.0005);

        $p = ProjectProfitability::forProject($this->goldenProject);
        $this->assertEqualsWithDelta(2650.0, $p['contribution'], 0.0005);
        $this->assertEqualsWithDelta(55.21, $p['margin_percent'], 0.005);
        $this->assertEqualsWithDelta(1785.0, $f['supplier_outstanding'], 0.0005, 'الذمّةُ للمورّد بإجماليّها: 525 + 1260');

        /* ─ الأداءُ الماليّ للشركة ─ */
        $s = FinancialPerformance::snapshot($bid, $period);
        $this->assertEqualsWithDelta(4800.0, $s['revenue']['net'], 0.0005);
        $this->assertEqualsWithDelta(240.0, $s['revenue']['tax'], 0.0005, '250 − 10');
        $this->assertEqualsWithDelta(5040.0, $s['revenue']['gross'], 0.0005);
        $this->assertEqualsWithDelta(2000.0, $s['collections']['total'], 0.0005);
        $this->assertEqualsWithDelta(1700.0, $s['direct_costs']['supplier_invoices'], 0.0005);
        $this->assertEqualsWithDelta(150.0, $s['direct_costs']['stock_consumed'], 0.0005);
        $this->assertEqualsWithDelta(300.0, $s['expenses']['total'], 0.0005);
        $this->assertEqualsWithDelta(2950.0, $s['gross_contribution'], 0.0005);
        $this->assertEqualsWithDelta(2650.0, $s['operating_result'], 0.0005);

        /* ─ الدفتر: كلُّ حسابٍ حيث يجب ─ */
        $this->assertEqualsWithDelta(3040.0, $this->balance('receivable'), 0.0005);
        $this->assertEqualsWithDelta(5000.0, $this->balance('sales'), 0.0005);
        $this->assertEqualsWithDelta(200.0, $this->balance('sales_returns'), 0.0005, 'الإشعارُ صافيًا في مردودات المبيعات');
        $this->assertEqualsWithDelta(155.0, $this->balance('tax_payable'), 0.0005, 'مخرجاتٌ 250 − إشعار 10 − مدخلاتٌ 85');
        $this->assertEqualsWithDelta(1785.0, $this->balance('payable'), 0.0005);
        $this->assertEqualsWithDelta(350.0, $this->balance('inventory'), 0.0005, 'اشتُري 500 واستُهلك 150');
        $this->assertEqualsWithDelta(150.0, $this->balance('cogs'), 0.0005);
        $this->assertEqualsWithDelta(1200.0, $this->balance('direct_purchases'), 0.0005);
        $this->assertEqualsWithDelta(300.0, $this->balance('transport'), 0.0005);
        $this->assertEqualsWithDelta(1700.0, $this->balance('cash'), 0.0005, '2000 قُبضت − 300 دُفعت');

        $tb = Ledger::trialBalance($bid);
        $this->assertTrue($tb['balanced']);
        foreach (JournalEntry::where('business_id', $bid)->get() as $entry) {
            $d = (float) JournalLine::where('journal_entry_id', $entry->id)->sum('debit');
            $c = (float) JournalLine::where('journal_entry_id', $entry->id)->sum('credit');
            $this->assertEqualsWithDelta($d, $c, 0.0005, 'قيدٌ غير متوازن: '.$entry->number.' — '.$entry->description);
            $this->assertGreaterThanOrEqual(2, JournalLine::where('journal_entry_id', $entry->id)->count());
        }
        $this->assertSame(JournalEntry::where('business_id', $bid)->count(), JournalEntry::where('business_id', $bid)->distinct('number')->count('number'));

        /* ─ الذممُ والإيرادُ والنقدُ من أبوابها القديمة ─ */
        $this->assertEqualsWithDelta(3040.0, Receivables::totals($bid)['total'], 0.0005);
        $this->assertEqualsWithDelta(4800.0, Revenue::summary($bid, $period->start, $period->end)['net'], 0.0005);
        $this->assertEqualsWithDelta(1700.0, FinancialPerformance::cashPosition($bid)['liquid'], 0.0005);
        $this->assertEqualsWithDelta(1700.0 + 3040.0 - 1785.0, CashIntelligence::current($bid)['net_position'], 0.0005);

        /* ─ السلّمُ والعملاءُ والتقاريرُ والنظرةُ العامّة ─ */
        $ladder = RevenueIntelligence::ladder($bid, $period);
        $this->assertEqualsWithDelta(4000.0, $ladder['quoted']['value'], 0.0005);
        $this->assertEqualsWithDelta(5000.0, $ladder['contracted']['value'], 0.0005, '4000 + 1000 أمرُ تغييرٍ صافيًا');
        $this->assertEqualsWithDelta(4800.0, $ladder['invoiced']['value'], 0.0005);
        $this->assertEqualsWithDelta(2000.0, $ladder['collected']['value'], 0.0005);
        $this->assertEqualsWithDelta(3040.0, $ladder['outstanding']['value'], 0.0005);

        $customer = collect(CustomerIntelligence::list($bid, $period)['rows'])->firstWhere('customer_id', $this->customer->id);
        $this->assertEqualsWithDelta(4800.0, $customer['invoiced'], 0.0005);
        $this->assertEqualsWithDelta(2000.0, $customer['collected'], 0.0005);
        $this->assertEqualsWithDelta(3040.0, $customer['outstanding'], 0.0005);

        $report = ManagementReports::build('project_profitability', $bid, ['period' => 'this_month']);
        $row = collect($report['sections']['المشاريع'])->firstWhere('code', $this->goldenProject->code);
        $this->assertEqualsWithDelta(2650.0, (float) $row['contribution'], 0.0005);
        $monthly = collect(ManagementReports::build('management_monthly', $bid, ['period' => 'this_month'])['sections']['الملخّص التنفيذي'])->keyBy('metric');
        $this->assertEqualsWithDelta(4800.0, (float) $monthly[__('الإيراد الصافي')]['value'], 0.0005);
        $this->assertEqualsWithDelta(2650.0, (float) $monthly[__('النتيجة التشغيلية')]['value'], 0.0005);

        $o = ExecutiveMetrics::overview($bid, $period, $this->owner);
        $this->assertEqualsWithDelta(4800.0, $o['financial']['revenue']['net'], 0.0005);
        $this->assertEqualsWithDelta(2650.0, $o['projects']['totals']['contribution'], 0.0005);
    }

    public function test_vat_is_a_liability_not_revenue_and_never_enters_profitability(): void
    {
        $this->build();
        $bid = (int) $this->business->id;

        $this->assertEqualsWithDelta(5000.0, $this->balance('sales'), 0.0005, 'المبيعاتُ بلا ضريبة');
        $this->assertEqualsWithDelta(4800.0, ProjectFinancials::summary($this->goldenProject)['revenue'], 0.0005);
        $this->assertEqualsWithDelta(1700.0, ProjectFinancials::summary($this->goldenProject)['supplier_actual'], 0.0005, 'ولا في التكلفة لمن يستردّها');
        $this->assertEqualsWithDelta(155.0, $this->balance('tax_payable'), 0.0005, 'صافي ما يُؤدّى للجهة');

        $vat = \App\Support\ReportData::vat($bid, ['range' => 'month']);
        $this->assertEqualsWithDelta(4800.0, (float) $vat['summary']['taxable'], 0.5);
        $this->assertEqualsWithDelta(240.0, (float) $vat['summary']['output'], 0.5);
        $this->assertEqualsWithDelta(85.0, (float) $vat['summary']['input'], 0.5, 'مدخلاتٌ 25 + 60');
    }
}
