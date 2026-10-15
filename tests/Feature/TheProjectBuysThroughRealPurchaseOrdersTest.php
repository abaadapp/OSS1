<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Support\GoodsReceipts;
use App\Support\ProjectFinancials;
use App\Support\PurchaseOrders;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\WalksTheProjectJourney;
use Tests\TestCase;

/**
 * المشروعُ يشتري بأوامر شراءٍ حقيقيّة — المرحلة 14.
 *
 * «+ إضافة شراء» في المشروع يفتح شاشةَ أمر الشراء الحاليّة والمشروعُ مختار،
 * فيُكتب `PurchaseOrder` واحدٌ بـ`project_id` يظهر في قسم المشتريات وفي تبويب
 * المشروع معًا. وبندُ الشراء منتجٌ أو خدمةٌ أو بندٌ مخصّص — والنوعُ من الصنف لا
 * من الشاشة، وما ليس صنفًا مخزونًا لا يبلغ الرفّ. والربحيّةُ بقواعدها: الأمرُ
 * التزام، والسندُ المعتمَد فعليّ، ولا يُحسب شيءٌ مرّتين.
 */
class TheProjectBuysThroughRealPurchaseOrdersTest extends TestCase
{
    use RefreshDatabase;
    use WalksTheProjectJourney;

    private Project $project;

    private Supplier $supplier;

    private Product $chairs;

    private Product $photography;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Mail::fake();
        $this->journeyCompany();
        $this->project = $this->acceptedProject(1000, 10);
        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'شركة النور', 'supplier_type' => Supplier::BOTH]);
        $this->chairs = Product::create(['business_id' => $this->business->id, 'name' => 'كرسي', 'price' => 10, 'cost' => 4, 'quantity' => 10,
            'alert_qty' => 1, 'active' => true, 'sku' => 'CH-1', 'kind' => Product::ITEM]);
        $this->photography = Product::create(['business_id' => $this->business->id, 'name' => 'تصوير فوتوغرافي', 'price' => 300, 'cost' => 200, 'quantity' => 0,
            'alert_qty' => 0, 'active' => true, 'sku' => 'SRV-PH', 'kind' => Product::SERVICE]);
    }

    private function order(array $items, array $extra = []): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('admin.purchases.store'), $extra + [
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'supplier_id' => $this->supplier->id,
            'payment_method' => 'آجل',
            'ordered_at' => now()->toDateString(),
            'expected_delivery_at' => now()->addDays(10)->toDateString(),
            'project_id' => $this->project->id,
            'return_to_project' => 1,
            'items' => $items,
        ]);
    }

    public function test_a_purchase_from_the_project_is_a_real_purchase_order_and_returns_to_the_project(): void
    {
        $this->get(route('admin.purchases.create', ['project_id' => $this->project->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Admin/Purchases/Create')->where('presetProjectId', $this->project->id));

        $this->order([['product_id' => $this->chairs->id, 'line_kind' => 'product', 'name' => 'كرسي', 'cost' => 4, 'quantity' => 50]])
            ->assertRedirect(route('admin.projects.show', ['id' => $this->project->id, 'tab' => 'purchases']));

        $po = PurchaseOrder::sole();
        $this->assertSame($this->project->id, $po->project_id);
        $this->assertTrue(ActivityLog::where('subject_type', 'project')->where('subject_id', $this->project->id)
            ->where('description', 'like', 'أنشأ من داخل المشروع '.$this->project->code.' أمر الشراء '.$po->number.'%')->exists());
    }

    public function test_the_same_order_appears_in_the_purchases_register_and_in_the_project_tab(): void
    {
        $this->order([['product_id' => $this->chairs->id, 'name' => 'كرسي', 'cost' => 4, 'quantity' => 5]]);
        $po = PurchaseOrder::sole();

        $register = collect($this->actingAs($this->owner)->get(route('admin.purchases.orders'))->assertOk()->viewData('page')['props']['orders']);
        $this->assertTrue($register->contains(fn ($o) => ($o['number'] ?? null) === $po->number), 'في قسم المشتريات العامّ');

        $this->get(route('admin.projects.show', $this->project->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('purchases.orders.0.number', $po->number)
                ->where('purchases.summary.orders', 1)->where('purchases.suppliers.0.name', 'شركة النور')->where('may.purchases', true));

        $this->get(route('admin.purchases.show', $po->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('order.project_id', $this->project->id)->where('order.project_code', $this->project->code));
    }

    public function test_the_line_kind_comes_from_the_catalog_not_from_the_screen(): void
    {
        $this->order([
            /* قالت الشاشةُ «منتج» والصنفُ خدمة — الخادمُ يقرأ الصنف */
            ['product_id' => $this->photography->id, 'line_kind' => 'product', 'name' => 'تصوير', 'cost' => 200, 'quantity' => 1],
            ['product_id' => $this->chairs->id, 'line_kind' => 'service', 'name' => 'كرسي', 'cost' => 4, 'quantity' => 20],
            ['line_kind' => 'service', 'name' => 'DJ للحفل الختامي', 'cost' => 150, 'quantity' => 1],
            ['line_kind' => 'custom', 'product_id' => $this->chairs->id, 'name' => 'نقل', 'cost' => 50, 'quantity' => 1],
            ['line_kind' => 'custom', 'name' => 'طباعة لافتات', 'cost' => 30, 'quantity' => 4],
        ]);

        $kinds = PurchaseOrderItem::get(['name', 'line_kind', 'product_id'])->mapWithKeys(fn ($i) => [$i->name => [$i->line_kind, $i->product_id]])->all();
        $this->assertEquals([
            'تصوير' => [PurchaseOrderItem::SERVICE, $this->photography->id],
            'كرسي' => [PurchaseOrderItem::PRODUCT, $this->chairs->id],
            'DJ للحفل الختامي' => [PurchaseOrderItem::SERVICE, null],
            'نقل' => [PurchaseOrderItem::PRODUCT, $this->chairs->id],
            'طباعة لافتات' => [PurchaseOrderItem::CUSTOM, null],
        ], $kinds, 'بصنفٍ: النوعُ من الصنف — وبلا صنف: خدمةٌ إن قيلت وإلّا مخصّص');
        $this->assertSame(1, PurchaseOrderItem::whereNull('product_id')->where('line_kind', PurchaseOrderItem::SERVICE)->count());
    }

    public function test_receiving_moves_stock_for_a_product_and_never_for_a_service(): void
    {
        $this->order([
            ['product_id' => $this->chairs->id, 'name' => 'كرسي', 'cost' => 4, 'quantity' => 30],
            ['product_id' => $this->photography->id, 'name' => 'تصوير', 'cost' => 200, 'quantity' => 2],
            ['line_kind' => 'service', 'name' => 'أمن الموقع', 'cost' => 100, 'quantity' => 1],
        ]);
        $po = PurchaseOrder::sole();
        $lines = $po->items()->orderBy('id')->get();

        $note = GoodsReceipts::record($po, [$lines[0]->id => 30, $lines[1]->id => 2, $lines[2]->id => 1], [], $this->owner);
        GoodsReceipts::approve($note, $this->owner);

        $this->assertSame(40, (int) $this->chairs->fresh()->quantity, 'الكرسيُّ صنفٌ مخزون — دخل الرفّ');
        $this->assertSame(0, (int) $this->photography->fresh()->quantity, 'التصويرُ خدمة — لا رفَّ لها');
        $this->assertSame([30, 2, 1], $po->items()->orderBy('id')->pluck('received_quantity')->map(fn ($q) => (int) $q)->all(), 'والاستلامُ يُقرأ لكلّ البنود');
        $this->assertSame(PurchaseOrders::RECEIVED, $po->fresh()->status);
    }

    public function test_the_order_is_committed_and_its_invoice_actual_without_counting_twice(): void
    {
        $this->order([['line_kind' => 'service', 'name' => 'تصوير الفعاليّة', 'cost' => 400, 'quantity' => 1]]);
        $po = PurchaseOrder::sole();
        $before = ProjectFinancials::summary($this->project->fresh());
        $this->assertGreaterThan(0, $before['committed'], 'أمرُ الشراء التزامٌ');
        $this->assertEqualsWithDelta(0.0, $before['actual'], 0.0005, 'ولا تكلفةَ فعليّة بالأمر وحده');

        GoodsReceipts::approve(GoodsReceipts::record($po, [$po->items()->value('id') => 1], [], $this->owner), $this->owner);
        $invoice = SupplierInvoices::create((int) $this->business->id, [
            'supplier_id' => $this->supplier->id, 'purchase_order_id' => $po->id, 'supplier_ref' => 'INV-77',
            'issued_at' => now()->toDateString(), 'subtotal' => (float) $po->items_subtotal, 'tax' => (float) $po->tax,
        ], $this->owner);
        $this->assertSame($this->project->id, $invoice->project_id, 'السندُ يرث مشروعَ أمره');
        SupplierInvoices::approve($invoice->fresh(), $this->owner);

        $after = ProjectFinancials::summary($this->project->fresh());
        $this->assertEqualsWithDelta(0.0, $after['committed'], 0.0005, 'فُوتر الأمرُ كلُّه — لا التزامَ باقٍ');
        $this->assertEqualsWithDelta($before['committed'], $after['actual'], 0.0005, 'ما كان التزامًا صار فعليًّا بعينه — لا مرّتين');
        $this->assertSame(SupplierInvoices::COST_DIRECT, $invoice->fresh()->cost_kind, 'خدمةٌ بلا صنفٍ مخزون — تكلفةٌ مباشرة لا مخزون');
    }

    public function test_the_purchases_tab_follows_the_purchases_permission(): void
    {
        $seller = User::create(['business_id' => $this->business->id, 'name' => 'بائع', 'email' => 'seller@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط']);
        $storekeeper = User::create(['business_id' => $this->business->id, 'name' => 'أمين المخزن', 'email' => 'store@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'inventory', 'status' => 'نشط']);

        $this->actingAs($seller)->get(route('admin.projects.show', $this->project->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('purchases', null)->where('may.purchases', false));
        $this->actingAs($seller)->post(route('admin.purchases.store'), [])->assertForbidden();

        $this->actingAs($storekeeper)->get(route('admin.projects.show', $this->project->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('may.purchases', true)->where('purchases.may.create', true));
    }

    public function test_a_project_of_another_company_is_neither_preset_nor_accepted(): void
    {
        $mine = $this->business;
        $mineProject = $this->project;
        $this->journeyCompany('شركة أخرى');

        $this->actingAs($this->owner)->get(route('admin.purchases.create', ['project_id' => $mineProject->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('presetProjectId', null));

        $this->actingAs($this->owner)->post(route('admin.purchases.store'), [
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'supplier_id' => Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّدهم'])->id,
            'payment_method' => 'آجل', 'ordered_at' => now()->toDateString(), 'project_id' => $mineProject->id,
            'items' => [['line_kind' => 'custom', 'name' => 'x', 'cost' => 1, 'quantity' => 1]],
        ])->assertSessionHasErrors('project_id');
        $this->assertSame(0, PurchaseOrder::where('project_id', $mineProject->id)->count());
        $this->assertNotNull($mine);
    }
}
