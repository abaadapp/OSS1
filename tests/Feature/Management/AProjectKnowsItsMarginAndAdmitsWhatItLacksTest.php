<?php

namespace Tests\Feature\Management;

use App\Models\Project;
use App\Support\CustomerInvoices;
use App\Support\Intelligence\CustomerIntelligence;
use App\Support\Intelligence\Period;
use App\Support\Intelligence\ProjectProfitability;
use App\Support\Intelligence\ServiceIntelligence;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * المشروعُ يعرف هامشَه ويعترف بما ينقصه.
 *
 * الصيغةُ واحدة فوق `ProjectFinancials`: إيرادٌ صافٍ − تكلفةٌ مباشرة = مساهمة؛
 * والاكتمالُ يُكتب: «غير كافٍ» بلا فواتيرَ ولا تكاليف، و«جزئيّ» حين تُوجد
 * تكاليفُ بلا مشروعٍ في نافذته أو التزاماتٌ لم تصر قيدًا. والمشروعُ الخاسر
 * يُقدَّم لا يُخفى. والعملاءُ أرقامٌ لا درجات.
 */
class AProjectKnowsItsMarginAndAdmitsWhatItLacksTest extends ManagementTestCase
{
    use RefreshDatabase;

    public function test_contribution_is_net_revenue_minus_direct_costs_with_stock_and_expenses(): void
    {
        $project = $this->project('EP-1', 2000);
        $invoice = $this->invoice(2000, '2026-09-10', $project);
        $this->pay($invoice, 1000, '2026-09-12');
        $this->supplierInvoice(600, 30, $project);
        $this->expense(150.5, '2026-09-09', $project);
        $this->expense(999, '2026-09-09', $project, 'معلّق');
        $this->supplierInvoice(300, 0, $project, approve: false);

        $p = ProjectProfitability::forProject($project->fresh());

        $this->assertEqualsWithDelta(2000.0, $p['revenue'], 0.0005);
        $this->assertEqualsWithDelta(1000.0, $p['collected'], 0.0005);
        $this->assertEqualsWithDelta(1100.0, $p['receivable'], 0.0005, 'الذمّةُ بإجمالي الفاتورة');
        $this->assertEqualsWithDelta(600.0, $p['direct_costs']['supplier_invoices'], 0.0005, 'صافيةً من ضريبةٍ تُستردّ');
        $this->assertEqualsWithDelta(150.5, $p['direct_costs']['expenses'], 0.0005);
        $this->assertEqualsWithDelta(750.5, $p['direct_costs']['total'], 0.0005);
        $this->assertEqualsWithDelta(1249.5, $p['contribution'], 0.0005);
        $this->assertEqualsWithDelta(62.48, $p['margin_percent'], 0.005);
        $this->assertEqualsWithDelta(1299.0, $p['committed'], 0.0005, 'سندٌ معلَّق ومصروفٌ لم يُدفع التزامٌ لا تكلفة');
        $this->assertSame('partial', $p['completeness']['status'], 'التزامٌ لم يصر قيدًا يجعل الصورةَ جزئيّة');
    }

    public function test_a_project_without_invoices_or_costs_is_insufficient_not_a_hundred_percent(): void
    {
        $project = $this->project('EP-2');
        $p = ProjectProfitability::forProject($project);

        $this->assertSame('insufficient', $p['completeness']['status']);
        $this->assertNull($p['margin_percent']);
        $this->assertEqualsWithDelta(0.0, $p['contribution'], 0.0005);
    }

    public function test_costs_without_a_project_inside_the_window_make_every_open_project_partial(): void
    {
        $project = $this->project('EP-3', 1000);
        $this->invoice(1000, '2026-09-10', $project);
        $this->supplierInvoice(200, 0, $project);
        $project->update(['status' => Project::CLOSED]);

        $this->assertSame('complete', ProjectProfitability::forProject($project->fresh())['completeness']['status']);

        /* سندٌ بلا مشروع في نافذة المشروع (±30 يومًا من 5–6 سبتمبر) */
        $this->supplierInvoice(50, 0, null, '2026-09-20');
        $p = ProjectProfitability::forProject($project->fresh());
        $this->assertSame('partial', $p['completeness']['status']);
        $this->assertNotEmpty($p['completeness']['reasons']);
    }

    public function test_a_credit_note_and_a_cancelled_invoice_shrink_project_revenue(): void
    {
        $project = $this->project('EP-4', 1000);
        $invoice = $this->invoice(1000, '2026-09-10', $project);
        $void = $this->invoice(400, '2026-09-11', $project);
        CustomerInvoices::cancel($void, 'خطأ', $this->owner->id);
        CustomerInvoices::creditNote($invoice, 105, 5, 'خصمٌ لاحق', $this->owner->id);

        $p = ProjectProfitability::forProject($project->fresh());
        $this->assertEqualsWithDelta(900.0, $p['revenue'], 0.0005);
        $this->assertEqualsWithDelta(1050.0, $p['invoiced'], 0.0005, 'الفاتورةُ الصادرة كما هي');
        $this->assertEqualsWithDelta(105.0, $p['credited'], 0.0005);
    }

    public function test_the_list_puts_the_weakest_margin_first_and_never_hides_a_loss(): void
    {
        $good = $this->project('EP-5', 1000);
        $this->invoice(1000, '2026-09-10', $good);
        $this->supplierInvoice(200, 0, $good);
        $bad = $this->project('EP-6', 500);
        $this->invoice(500, '2026-09-10', $bad);
        $this->supplierInvoice(800, 0, $bad);
        $this->project('EP-7', 100, ['starts_at' => '2024-01-01', 'ends_at' => '2024-01-02']);

        $list = ProjectProfitability::list((int) $this->business->id, Period::resolve((int) $this->business->id, 'this_month'), 'margin_asc');

        $this->assertSame(2, $list['totals']['projects'], 'بتاريخ البدء — القديمُ خارج المدّة');
        $this->assertSame('EP-6', $list['rows'][0]['project']['code']);
        $this->assertEqualsWithDelta(-300.0, $list['rows'][0]['contribution'], 0.0005);
        $this->assertSame(1, $list['totals']['negative']);
        $this->assertEqualsWithDelta(500.0, $list['totals']['contribution'], 0.0005);

        $all = ProjectProfitability::list((int) $this->business->id, null, 'revenue');
        $this->assertSame(3, $all['totals']['projects']);
    }

    public function test_the_timeline_lists_every_financial_fact_in_order_with_a_link(): void
    {
        $project = $this->project('EP-8', 1000);
        $invoice = $this->invoice(1000, '2026-09-10', $project);
        $this->pay($invoice, 300, '2026-09-12');
        $this->supplierInvoice(200, 0, $project, '2026-09-08');

        $rows = ProjectProfitability::timeline($project->fresh());
        $kinds = array_column($rows, 'kind');

        $this->assertContains('invoice', $kinds);
        $this->assertContains('collection', $kinds);
        $this->assertContains('supplier_invoice', $kinds);
        $this->assertSame(array_column($rows, 'at'), collect($rows)->pluck('at')->sort()->values()->all(), 'مرتَّبٌ بالتاريخ');
        foreach ($rows as $row) {
            $this->assertArrayHasKey('url', $row);
        }
    }

    public function test_customers_are_measured_not_graded_and_concentration_uses_the_threshold(): void
    {
        $bid = (int) $this->business->id;
        $big = $this->project('EP-9', 9000);
        $this->invoice(9000, '2026-09-10', $big);
        $small = \App\Models\Customer::create(['business_id' => $bid, 'name' => 'شركة صغيرة']);
        $this->invoice(1000, '2026-09-11', null, $small);

        $period = Period::resolve($bid, 'this_month');
        $list = CustomerIntelligence::list($bid, $period);
        $c = CustomerIntelligence::concentration($bid, $period);

        $this->assertSame(2, $list['totals']['customers']);
        $row = collect($list['rows'])->firstWhere('customer', 'وزارة الطاقة');
        $this->assertEqualsWithDelta(9000.0, $row['invoiced'], 0.0005);
        $this->assertArrayNotHasKey('score', $row, 'لا درجةَ ولا حكم');
        $this->assertArrayNotHasKey('rating', $row);

        $this->assertEqualsWithDelta(90.0, $c['top1_percent'], 0.05);
        $this->assertTrue($c['above_threshold'], 'العتبةُ الافتراضيّة 40٪');
        $this->assertSame(40.0, (float) $c['threshold_percent']);

        $this->assertSame('insufficient', CustomerIntelligence::concentration((int) $this->other->id, Period::resolve((int) $this->other->id, 'this_month'))['status']);
    }

    public function test_service_intelligence_reads_the_catalog_and_attributes_only_lines_with_an_item(): void
    {
        $bid = (int) $this->business->id;
        $product = $this->product('طابعة بطاقات', 10, 20);
        $invoice = CustomerInvoices::create($bid, $this->customer, ['issued_at' => '2026-09-10'],
            [['product_id' => $product->id, 'description' => 'طابعة', 'quantity' => 2, 'unit_price' => 100], ['description' => 'خدمةٌ حرّة', 'quantity' => 1, 'unit_price' => 50]], $this->owner->id);
        CustomerInvoices::issue($invoice->fresh(), $this->owner->id);

        $list = ServiceIntelligence::list($bid, Period::resolve($bid, 'this_month'));
        $row = collect($list['rows'])->firstWhere('product_id', $product->id);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(200.0, $row['invoiced_value'], 0.0005);
        $this->assertEqualsWithDelta(2.0, $row['invoiced_quantity'], 0.0005);
        $this->assertEqualsWithDelta(160.0, $row['contribution'], 0.0005, '200 − 2 × 20 تقديرًا');
        $this->assertCount(1, $list['rows'], 'البندُ الحرّ لا يُنسب');
    }
}
