<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\ChangeOrder;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\DeliveryPackage;
use App\Models\Expense;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\ChangeOrders;
use App\Support\CustomerInvoices;
use App\Support\DeliveryPackages;
use App\Support\Ledger;
use App\Support\ProjectFinancials;
use App\Support\Projects;
use App\Support\PurchaseOrders;
use App\Support\Quotations;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SatisfiesThePathToClose;
use Tests\TestCase;

/**
 * المشروعُ يقرأ مالَه من مستنداته — في ثلاثة أوعيةٍ لا تتداخل (§16).
 *
 * ═══ ما يُفحص ═══
 *
 * الفعليُّ = المعتمَدُ من سندات الموردين + المدفوعُ من المصروفات · الملتزَمُ =
 * رصيدُ أمر الشراء بعد سنداته + المعلَّقُ بلا أمر + غيرُ المدفوع · المستحقُّ
 * للموردين جزءٌ من الفعليّ · لا ريالَ يُعدّ مرّتين · الفوترةُ تُقارَن بالقيمة
 * الحاليّة لا بالأصل · ولا يُقفَل مشروعٌ وملتزَمُه فوق الصفر.
 */
class AProjectReadsItsMoneyInThreeBucketsTest extends TestCase
{
    use RefreshDatabase;
    use SatisfiesThePathToClose;

    private Business $business;

    private User $owner;

    private Customer $customer;

    private Supplier $supplier;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);
        Ledger::seedChart($this->business->id);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@hadath.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة']);
        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّد']);

        $this->actingAs($this->owner);

        $quotation = Quotations::create((int) $this->business->id, $this->customer,
            ['title' => 'مؤتمر', 'issued_at' => now()->toDateString()],
            [['description' => 'تنظيم', 'quantity' => 1, 'unit_price' => 1000]], $this->owner);
        Quotations::send($quotation, $this->owner);
        Quotations::recordDecision($quotation->fresh(), 'accepted', ['decided_by_name' => 'سالم'], $this->owner);

        $this->project = Project::firstOrFail();
    }

    private function po(float $total, string $status = PurchaseOrders::SENT): PurchaseOrder
    {
        return PurchaseOrder::create([
            'business_id' => $this->business->id, 'number' => 'PO-'.rand(1000, 9999),
            'supplier_id' => $this->supplier->id, 'supplier_name' => 'مورّد',
            'status' => $status, 'total' => $total, 'items_subtotal' => $total, 'ordered_at' => now(),
            'project_id' => $this->project->id,
        ]);
    }

    private function supplierInvoice(float $subtotal, ?PurchaseOrder $po = null): SupplierInvoice
    {
        $invoice = SupplierInvoices::create((int) $this->business->id, [
            'supplier_id' => $this->supplier->id, 'purchase_order_id' => $po?->id,
            'supplier_ref' => 'R-'.rand(1000, 9999), 'issued_at' => now()->toDateString(),
            'subtotal' => $subtotal, 'tax' => 0,
        ], $this->owner);
        $invoice->update(['project_id' => $this->project->id]);

        return $invoice->fresh();
    }

    private function expense(float $amount, string $status): Expense
    {
        return Expense::create([
            'business_id' => $this->business->id, 'reference' => 'EXP-'.rand(1000, 9999), 'type' => 'نقل',
            'description' => 'شاحنة', 'amount' => $amount, 'method' => 'نقدي',
            'status' => $status, 'spent_at' => now()->toDateString(), 'project_id' => $this->project->id,
        ]);
    }

    public function test_actual_committed_and_outstanding_do_not_overlap(): void
    {
        /* أمرُ شراءٍ بـ600، سندٌ معتمَدٌ عليه بـ400 (سُدِّد منه 100)، وسندٌ معلَّقٌ عليه بـ150 */
        $po = $this->po(600);
        $approved = $this->supplierInvoice(400, $po);
        SupplierInvoices::approve($approved, $this->owner, 'اعتمادٌ مباشر');
        $approved->fresh()->update(['paid' => 100]);
        $this->supplierInvoice(150, $po);

        /* سندٌ معلَّقٌ بلا أمر شراء بـ80 · وسندٌ مرفوض بـ999 لا يُحسب */
        $this->supplierInvoice(80);
        SupplierInvoices::reject($this->supplierInvoice(999), 'مكرَّر', $this->owner);

        /* مصروفٌ مدفوعٌ بـ50 وغيرُ مدفوعٍ بـ30 · وأمرُ شراءٍ مسودّةٌ بـ5000 لا يُحسب */
        $this->expense(50, Expense::PAID);
        $this->expense(30, 'غير مدفوع');
        $this->po(5000, PurchaseOrders::DRAFT);

        $s = ProjectFinancials::summary($this->project);

        $this->assertEqualsWithDelta(400.0, $s['supplier_actual'], 0.0005, 'المعتمَدُ بكامله فعليّ — سُدِّد أم لا');
        $this->assertEqualsWithDelta(50.0, $s['expenses_paid'], 0.0005);
        $this->assertEqualsWithDelta(450.0, $s['actual'], 0.0005);

        /* الملتزَم: رصيدُ الأمر 600 − 400 = 200 (والمعلَّقُ عليه ضمن الرصيد) + 80 بلا أمر + 30 مصروف */
        $this->assertEqualsWithDelta(200.0, $s['open_purchase_orders'], 0.0005);
        $this->assertEqualsWithDelta(80.0, $s['pending_supplier_invoices'], 0.0005);
        $this->assertEqualsWithDelta(30.0, $s['expenses_unpaid'], 0.0005);
        $this->assertEqualsWithDelta(310.0, $s['committed'], 0.0005);

        /* والمستحقُّ للموردين جزءٌ من الفعليّ: 400 − 100 */
        $this->assertEqualsWithDelta(300.0, $s['supplier_outstanding'], 0.0005);
        $this->assertEqualsWithDelta(760.0, $s['expected_cost'], 0.0005, 'الفعليُّ + الملتزَم — بلا المستحقّ ثانيةً');
    }

    public function test_a_supplier_invoice_moves_from_committed_to_actual_when_approved(): void
    {
        $po = $this->po(600);
        $invoice = $this->supplierInvoice(600, $po);

        $before = ProjectFinancials::summary($this->project);
        $this->assertEqualsWithDelta(600.0, $before['committed'], 0.0005);
        $this->assertEqualsWithDelta(0.0, $before['actual'], 0.0005);

        SupplierInvoices::approve($invoice, $this->owner, 'اعتمادٌ مباشر');

        $after = ProjectFinancials::summary($this->project);
        $this->assertEqualsWithDelta(0.0, $after['committed'], 0.0005);
        $this->assertEqualsWithDelta(600.0, $after['actual'], 0.0005);
        $this->assertEqualsWithDelta(600.0, $after['supplier_outstanding'], 0.0005);
    }

    public function test_invoicing_is_measured_against_the_current_approved_value(): void
    {
        /* الأصلُ 1050 بضريبته · أمرُ تغييرٍ يُنقص 500 (−525 بضريبته) · والمفوتَرُ 1050 */
        $invoice = CustomerInvoices::create((int) $this->business->id, $this->customer, ['project_id' => $this->project->id],
            [['description' => 'تنظيم', 'quantity' => 1, 'unit_price' => 1000]], $this->owner->id);
        $invoice->update(['project_id' => $this->project->id]);
        CustomerInvoices::issue($invoice->fresh(), $this->owner->id);

        $before = ProjectFinancials::summary($this->project);
        $this->assertEqualsWithDelta(1050.0, $before['invoiced'], 0.0005);
        $this->assertFalse($before['invoiced_above_approved']);

        $item = $this->project->items[0];
        /* نقصُ الكميّة كلِّها: بندٌ صار صفرًا */
        $order = ChangeOrders::create($this->project, ['title' => 'إلغاء التنظيم', 'requested_by_party' => 'customer'],
            [['project_item_id' => $item->id, 'quantity' => -1]], [], $this->owner);
        ChangeOrders::send($order, $this->owner);
        ChangeOrders::recordDecision($order->fresh(), ChangeOrder::ACCEPTED, [], $this->owner);

        $after = ProjectFinancials::summary($this->project->fresh());
        $this->assertEqualsWithDelta(0.0, $after['current_value'], 0.0005);
        $this->assertEqualsWithDelta(1050.0, $after['approved_value'], 0.0005, 'الأصلُ لا يُكتب');
        $this->assertTrue($after['invoiced_above_approved'], 'مفوتَرٌ فوق المعتمَد — إشعارُ دائنٍ ينتظر يدًا');
        $this->assertEqualsWithDelta(1000.0, $after['realized_gross_profit'], 0.0005, 'الإيرادُ لا يُلمَس: الفاتورةُ صدرت');
    }

    public function test_a_project_cannot_close_while_anything_is_still_committed(): void
    {
        $po = $this->po(600);
        $this->project->update(['status' => Project::IN_PROGRESS]);

        $this->post(route('admin.projects.close', $this->project->id))->assertSessionHasErrors('project');
        $this->assertSame(Project::IN_PROGRESS, $this->project->fresh()->status);

        SupplierInvoices::approve($this->supplierInvoice(600, $po), $this->owner, 'اعتمادٌ مباشر');

        /* والمالُ وحدَه لا يُقفل: التسليمُ يجب أن يُقبل أوّلًا — المرحلة 5 */
        $this->post(route('admin.projects.close', $this->project->id))->assertSessionHasErrors('project');
        $this->assertSame(Project::IN_PROGRESS, $this->project->fresh()->status);

        $package = DeliveryPackages::create((int) $this->business->id, null, ['project_id' => $this->project->id], $this->owner);
        DeliveryPackages::addItem($package, ['kind' => 'item', 'project_item_id' => $this->project->items()->first()->id], null, $this->owner);
        $sent = DeliveryPackages::send(DeliveryPackages::ready($package->fresh(), $this->owner), $this->owner);
        DeliveryPackages::recordDecision($sent, DeliveryPackage::ACCEPTED, [], $this->owner);

        /* ومسارُ المشروع قبل الإقفال: نطاقٌ موقَّع وتنفيذٌ مكتمل وقيمةٌ مفوتَرة */
        $this->post(route('admin.projects.close', $this->project->id))->assertSessionHasErrors('project');
        $this->satisfyThePathToClose($this->project, $this->owner);

        $this->post(route('admin.projects.close', $this->project->id))->assertSessionHasNoErrors();

        $closed = $this->project->fresh();
        $this->assertSame(Project::CLOSED, $closed->status);
        $this->assertNotNull($closed->closed_at);
        $this->assertEqualsWithDelta(600.0, (float) $closed->financials_frozen['actual'], 0.0005);

        /* ومُقفَلٌ لا يقبل أمرَ تغيير */
        $this->post(route('admin.changeOrders.store', $this->project->id), [
            'title' => 'x', 'requested_by_party' => 'customer', 'new_starts_at' => now()->addDay()->toDateString(),
        ])->assertSessionHasErrors('items');

        Projects::reopen($closed, $this->owner);
        $this->assertSame(Project::IN_PROGRESS, $closed->fresh()->status);
        $this->assertNotNull($closed->fresh()->financials_frozen, 'اللقطةُ تبقى بعد الفتح');
    }
}
