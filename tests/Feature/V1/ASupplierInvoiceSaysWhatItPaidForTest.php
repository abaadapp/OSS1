<?php

namespace Tests\Feature\V1;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SupplierInvoice;
use App\Support\Intelligence\Budgeting;
use App\Support\Intelligence\Period;
use App\Support\Intelligence\FinancialPerformance;
use App\Support\Ledger;
use App\Support\PurchaseOrders;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Management\ManagementTestCase;

/**
 * سندُ المورّد يقول ماذا اشترى — المرحلة 12 §18.
 *
 * خدمةٌ بلا أمرِ شراء مشترياتٌ مباشرة في الدخل؛ وأصنافٌ مخزونة على أمرٍ مخزونٌ
 * في الميزانيّة؛ والأمرُ المختلط يُقسَم بحصص بنوده؛ والمحاسبُ يقول الطبيعةَ
 * بيده فتُطاع؛ والضريبةُ لا تتغيّر؛ وما رُحِّل قبل اليوم لا يُعاد كتابتُه؛
 * والموازنةُ ترى التكلفةَ على حسابها؛ وربحيّةُ المشروع لا تتغيّر لأنّها تقرأ
 * المستندَ لا الحساب.
 */
class ASupplierInvoiceSaysWhatItPaidForTest extends ManagementTestCase
{
    use RefreshDatabase;

    private function balance(string $key): float
    {
        return round(Ledger::balance((int) $this->business->id, $key), 3);
    }

    private function order(array $lines): PurchaseOrder
    {
        $po = PurchaseOrder::create(['business_id' => $this->business->id, 'number' => 'PO-'.random_int(1000, 9999), 'supplier_id' => $this->supplier->id, 'supplier_name' => $this->supplier->name,
            'status' => PurchaseOrders::SENT, 'items_subtotal' => array_sum(array_column($lines, 'line_total')), 'tax' => 0, 'total' => array_sum(array_column($lines, 'line_total')), 'ordered_at' => now()]);
        foreach ($lines as $line) {
            PurchaseOrderItem::create(['purchase_order_id' => $po->id] + $line);
        }

        return $po;
    }

    private function invoiceOn(?PurchaseOrder $po, float $subtotal, float $tax = 0, array $extra = []): SupplierInvoice
    {
        $invoice = SupplierInvoices::create((int) $this->business->id, $extra + [
            'supplier_id' => $this->supplier->id, 'purchase_order_id' => $po?->id, 'supplier_ref' => 'R-'.random_int(1000, 9999), 'issued_at' => now()->toDateString(),
            'subtotal' => $subtotal, 'tax' => $tax,
        ], $this->owner);

        return SupplierInvoices::approve($invoice, $this->owner, 'اعتمادٌ مباشر');
    }

    public function test_a_service_bought_without_a_purchase_order_is_a_direct_cost_not_an_asset(): void
    {
        $this->actingAs($this->owner);
        $invoice = $this->invoiceOn(null, 1000, 50);

        $this->assertSame(SupplierInvoices::COST_DIRECT, $invoice->cost_kind);
        $this->assertEqualsWithDelta(1000.0, (float) $invoice->direct_cost, 0.0005);
        $this->assertEqualsWithDelta(0.0, (float) $invoice->inventory_cost, 0.0005);
        $this->assertEqualsWithDelta(1000.0, $this->balance('direct_purchases'), 0.0005);
        $this->assertEqualsWithDelta(0.0, $this->balance('inventory'), 0.0005, 'لا مخزونَ من خدمة');
        $this->assertEqualsWithDelta(-50.0, $this->balance('tax_payable'), 0.0005, 'الضريبةُ المستردّة في حسابها كما كانت (مدينةٌ على حسابٍ دائن)');
        $this->assertEqualsWithDelta(1050.0, $this->balance('payable'), 0.0005, 'والذمّةُ بإجماليّها');
        $this->assertTrue(Ledger::trialBalance((int) $this->business->id)['balanced']);
    }

    public function test_stocked_items_on_a_purchase_order_still_go_to_inventory(): void
    {
        $this->actingAs($this->owner);
        $badge = $this->product('بطاقة', 0, 1);
        $po = $this->order([['product_id' => $badge->id, 'name' => 'بطاقة', 'cost' => 1, 'quantity' => 500, 'line_total' => 500]]);
        $invoice = $this->invoiceOn($po, 500);

        $this->assertSame(SupplierInvoices::COST_INVENTORY, $invoice->cost_kind);
        $this->assertEqualsWithDelta(500.0, $this->balance('inventory'), 0.0005);
        $this->assertEqualsWithDelta(0.0, $this->balance('direct_purchases'), 0.0005);
    }

    public function test_a_mixed_order_is_split_by_its_lines_and_a_service_product_is_not_stock(): void
    {
        $this->actingAs($this->owner);
        $badge = $this->product('بطاقة', 0, 1);
        $setup = Product::create(['business_id' => $this->business->id, 'name' => 'تركيب', 'kind' => Product::SERVICE, 'price' => 300, 'cost' => 0, 'quantity' => 0, 'active' => true, 'sku' => 'SRV-1']);
        $po = $this->order([
            ['product_id' => $badge->id, 'name' => 'بطاقة', 'cost' => 1, 'quantity' => 600, 'line_total' => 600],
            ['product_id' => $setup->id, 'name' => 'تركيب', 'cost' => 300, 'quantity' => 1, 'line_total' => 300],
            ['product_id' => null, 'name' => 'نقل', 'cost' => 100, 'quantity' => 1, 'line_total' => 100],
        ]);
        $invoice = $this->invoiceOn($po, 1000, 50);

        $this->assertSame(SupplierInvoices::COST_MIXED, $invoice->cost_kind);
        $this->assertEqualsWithDelta(600.0, (float) $invoice->inventory_cost, 0.0005, '600 من 1000 بحصّة السطور المخزونة');
        $this->assertEqualsWithDelta(400.0, (float) $invoice->direct_cost, 0.0005, 'الخدمةُ والبندُ الحرّ تكلفةٌ مباشرة');
        $this->assertEqualsWithDelta(600.0, $this->balance('inventory'), 0.0005);
        $this->assertEqualsWithDelta(400.0, $this->balance('direct_purchases'), 0.0005);
        $this->assertEqualsWithDelta(-50.0, $this->balance('tax_payable'), 0.0005);
        $entry = JournalEntry::where('business_id', $this->business->id)->where('source', 'سند مورّد')->sole();
        $this->assertEqualsWithDelta((float) JournalLine::where('journal_entry_id', $entry->id)->sum('debit'), (float) JournalLine::where('journal_entry_id', $entry->id)->sum('credit'), 0.0005);
    }

    public function test_the_accountant_may_state_the_nature_by_hand_and_it_is_obeyed(): void
    {
        $this->actingAs($this->owner);
        $invoice = $this->invoiceOn(null, 800, 0, ['cost_kind' => SupplierInvoices::COST_INVENTORY]);

        $this->assertSame(SupplierInvoices::COST_INVENTORY, $invoice->cost_kind);
        $this->assertEqualsWithDelta(800.0, $this->balance('inventory'), 0.0005);
        $this->assertEqualsWithDelta(0.0, $this->balance('direct_purchases'), 0.0005);

        $this->actingAs($this->owner)->post(route('admin.purchases.invoices.store'), ['supplier_id' => $this->supplier->id, 'supplier_ref' => 'R-X', 'issued_at' => now()->toDateString(), 'subtotal' => 10, 'tax' => 0, 'cost_kind' => 'weird'])
            ->assertSessionHasErrors('cost_kind');
    }

    public function test_what_was_posted_before_is_never_rewritten_and_the_split_is_frozen_on_the_invoice(): void
    {
        $this->actingAs($this->owner);
        /* سندٌ رُحِّل بالدلالة القديمة قبل هذه المرحلة — يبقى كما وقع */
        $old = SupplierInvoice::create(['business_id' => $this->business->id, 'supplier_id' => $this->supplier->id, 'supplier_ref' => 'OLD-1', 'issued_at' => '2026-08-01',
            'subtotal' => 300, 'tax' => 0, 'total' => 300, 'approval_status' => SupplierInvoices::APPROVED, 'approved_at' => '2026-08-01 10:00:00', 'cost_kind' => null]);
        Ledger::post((int) $this->business->id, 'سند مورّد: OLD-1', [['account' => 'inventory', 'debit' => 300], ['account' => 'payable', 'credit' => 300]], now()->subMonth(), 'سند مورّد', null, null, $old);

        try {
            SupplierInvoices::approve($old, $this->owner, 'مرّةً ثانية');
            $this->fail('اعتُمد معتمَد');
        } catch (\RuntimeException) {
        }

        $this->assertEqualsWithDelta(300.0, $this->balance('inventory'), 0.0005, 'القيدُ القديم في مكانه');
        $this->assertNull($old->fresh()->cost_kind, 'ولا يُخترع له وصفٌ بأثرٍ رجعيّ');
        $this->assertSame(1, JournalEntry::where('business_id', $this->business->id)->where('source', 'سند مورّد')->count());
    }

    public function test_management_numbers_agree_with_the_ledger_after_the_change(): void
    {
        $this->actingAs($this->owner);
        $project = $this->project('EP-COST', 5000);
        $invoice = SupplierInvoices::create((int) $this->business->id, ['supplier_id' => $this->supplier->id, 'supplier_ref' => 'R-P', 'issued_at' => '2026-09-08', 'subtotal' => 1200, 'tax' => 60], $this->owner);
        $invoice->update(['project_id' => $project->id]);
        SupplierInvoices::approve($invoice->fresh(), $this->owner, 'اعتمادٌ مباشر');

        $period = Period::resolve((int) $this->business->id, 'this_month');
        $this->assertEqualsWithDelta(1200.0, FinancialPerformance::directCosts((int) $this->business->id, $period)['supplier_invoices'], 0.0005, 'الإدارةُ تقرأ المستند — لم تتغيّر');
        $this->assertEqualsWithDelta(1200.0, \App\Support\ProjectFinancials::summary($project->fresh())['supplier_actual'], 0.0005);

        /* والموازنةُ ترى التكلفةَ الآن على حسابها 5150 — كانت تختفي في المخزون */
        $account = Ledger::account((int) $this->business->id, 'direct_purchases');
        $budget = Budgeting::approve(Budgeting::create((int) $this->business->id, ['fiscal_year' => 2026, 'lines' => [['account_id' => $account->id, 'month' => 9, 'planned_amount' => 1000]]], $this->manager), $this->owner);
        $row = collect(Budgeting::versusActual($budget, 'month')['rows'])->firstWhere('account_id', $account->id);
        $this->assertEqualsWithDelta(1200.0, $row['buckets'][8]['actual'], 0.0005);
        $this->assertSame('spent_above_plan', $row['buckets'][8]['reading']);
    }
}
