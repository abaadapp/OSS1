<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\Supplier;
use App\Support\GoodsReceipts;
use App\Support\PurchaseOrders;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\WalksTheProjectJourney;
use Tests\TestCase;

/**
 * المورّدُ المتوقّف لا يُنشأ عليه أمرُ شراءٍ جديد — وتاريخُه باقٍ (مراجعة PR #3).
 *
 * الحالةُ ليست شكليّة: المتوقّفُ يغيب عن قائمة المورّدين في شاشة أمر الشراء
 * ومعاينتِها وعن المورّد الافتراضيّ، **والخادمُ نفسُه** يردّ رقمَه إن أُرسل
 * يدويًّا. وأوامرُه القديمة وسنداتُها ومشاريعُها تُقرأ وتُستلم وتُعتمد كما كانت:
 * الإيقافُ للقادم لا للتاريخ.
 */
class AStoppedSupplierTakesNoNewPurchaseOrderButKeepsItsHistoryTest extends TestCase
{
    use RefreshDatabase;
    use WalksTheProjectJourney;

    private Project $project;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Mail::fake();
        $this->journeyCompany();
        $this->project = $this->acceptedProject(1000, 10);
        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'استوديو الضوء', 'supplier_type' => Supplier::SERVICES]);
    }

    private function order(?int $supplierId = null): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('admin.purchases.store'), [
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'supplier_id' => $supplierId ?? $this->supplier->id,
            'payment_method' => 'آجل',
            'ordered_at' => now()->toDateString(),
            'project_id' => $this->project->id,
            'items' => [['line_kind' => 'service', 'name' => 'تصوير الحفل', 'cost' => 300, 'quantity' => 1]],
        ]);
    }

    private function stop(): void
    {
        $this->actingAs($this->owner)->put(route('admin.suppliers.update', $this->supplier->id), ['name' => 'استوديو الضوء', 'status' => Supplier::INACTIVE])
            ->assertSessionHasNoErrors();
    }

    public function test_an_active_supplier_takes_an_order_and_once_stopped_the_server_refuses_a_new_one(): void
    {
        $this->order()->assertSessionHasNoErrors();
        $this->assertSame(1, PurchaseOrder::count());

        $this->stop();
        $this->assertFalse($this->supplier->fresh()->isActive());
        $this->assertTrue(ActivityLog::where('description', 'أوقف المورّد: استوديو الضوء')->exists(), 'الإيقافُ يُقال باسمه في السجلّ');

        /* رقمُه يُرسل يدويًّا — والخادمُ يردّه، لا القائمةُ وحدها */
        $this->order()->assertSessionHasErrors(['supplier_id' => 'المورّد استوديو الضوء متوقف — لا يُنشأ عليه أمر شراء جديد.']);
        $this->assertSame(1, PurchaseOrder::count());

        /* ويعود بإعادته */
        $this->actingAs($this->owner)->put(route('admin.suppliers.update', $this->supplier->id), ['name' => 'استوديو الضوء', 'status' => Supplier::ACTIVE]);
        $this->assertTrue(ActivityLog::where('description', 'أعاد تفعيل المورّد: استوديو الضوء')->exists());
        $this->order()->assertSessionHasNoErrors();
        $this->assertSame(2, PurchaseOrder::count());
    }

    public function test_the_stopped_supplier_leaves_the_pickers_and_the_preview(): void
    {
        $other = Supplier::create(['business_id' => $this->business->id, 'name' => 'مخازن الكراسي']);
        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => PurchaseOrders::DEFAULT_SUPPLIER], ['value' => (string) $this->supplier->id]);
        $this->stop();

        $this->actingAs($this->owner)->get(route('admin.purchases.create'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('defaultSupplierId', fn ($id) => (int) $id === $other->id)
                ->where('suppliers', fn ($list) => collect($list)->pluck('id')->all() === [$other->id]));

        $preview = fn (int $id) => (string) $this->actingAs($this->owner)->postJson(route('admin.purchases.preview'), [
            'supplier_id' => $id, 'items' => [['name' => 'تصوير', 'cost' => 300, 'quantity' => 1]],
        ])->assertOk()->json('html');
        $this->assertStringContainsString('مخازن الكراسي', $preview($other->id), 'النشطُ يُرسم على الورقة');
        $this->assertStringNotContainsString('استوديو الضوء', $preview($this->supplier->id), 'والمتوقّفُ لا يُستعمل مورّدًا لأمرٍ جديد');

        /* وفي سجلّ الموردين يبقى ظاهرًا بحاله */
        $this->actingAs($this->owner)->get(route('admin.suppliers.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('suppliers', fn ($list) => collect($list)->firstWhere('id', $this->supplier->id)['status'] === Supplier::INACTIVE));
    }

    public function test_its_old_orders_invoices_and_projects_stay_readable_and_workable(): void
    {
        $this->order()->assertSessionHasNoErrors();
        $po = PurchaseOrder::sole();
        $invoice = SupplierInvoices::create((int) $this->business->id, [
            'supplier_id' => $this->supplier->id, 'purchase_order_id' => $po->id, 'supplier_ref' => 'INV-9',
            'issued_at' => now()->toDateString(), 'subtotal' => (float) $po->items_subtotal, 'tax' => (float) $po->tax,
        ], $this->owner);
        $this->stop();

        $this->actingAs($this->owner)->get(route('admin.purchases.show', $po->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('order.supplier', 'استوديو الضوء'));
        $this->actingAs($this->owner)->get(route('admin.purchases.invoices.show', $invoice->id))->assertOk();
        $this->actingAs($this->owner)->get(route('admin.suppliers.show', $this->supplier->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('orders.0.number', $po->number)->where('projects.0.code', $this->project->code));
        $this->actingAs($this->owner)->get(route('admin.projects.show', $this->project->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('purchases.orders.0.number', $po->number));

        /* والأمرُ القديم يُكمل دورته: تأكيدُ التنفيذ ثمّ اعتمادُ سنده — التاريخُ لا يُكسر */
        GoodsReceipts::approve(GoodsReceipts::record($po, [$po->items()->value('id') => 1], [], $this->owner), $this->owner);
        SupplierInvoices::approve($invoice->fresh(), $this->owner);
        $this->assertSame(SupplierInvoices::APPROVED, $invoice->fresh()->approval_status);
        $this->assertSame($this->supplier->id, $po->fresh()->supplier_id, 'والربطُ القديم كما هو');
    }

    public function test_another_company_neither_uses_nor_touches_this_supplier(): void
    {
        $mine = $this->supplier;
        $this->journeyCompany('شركة أخرى');
        $this->project = $this->acceptedProject(500, 5);

        $this->order($mine->id)->assertSessionHasErrors(['supplier_id' => 'المورّد المحدد غير صالح.']);
        $html = (string) $this->actingAs($this->owner)->postJson(route('admin.purchases.preview'), ['supplier_id' => $mine->id])->assertOk()->json('html');
        $this->assertStringNotContainsString('استوديو الضوء', $html);
        $this->actingAs($this->owner)->put(route('admin.suppliers.update', $mine->id), ['name' => 'مخترق', 'status' => Supplier::INACTIVE])->assertNotFound();

        $this->assertSame(Supplier::ACTIVE, $mine->fresh()->status);
        $this->assertSame(0, PurchaseOrder::count());
    }
}
