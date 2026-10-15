<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\GoodsReceiptNote;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Support\GoodsReceipts;
use App\Support\ProjectFinancials;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\Concerns\WalksTheProjectJourney;
use Tests\TestCase;

/**
 * الخدمةُ تُؤكَّد منفَّذةً ولا تبلغ الرفّ — والمطابقةُ بقواعدها (مراجعة PR #3).
 *
 * لا مستندَ ثانيًا: ورقةُ الاستلام واعتمادُها هي إثباتُ أنّ الخدمة نُفّذت،
 * وتُسمّى «تأكيدَ تنفيذ» حين يكون الأمرُ خدماتٍ وحدها. البضاعةُ تدخل الرفّ
 * باعتمادها كما كانت، والخدمةُ لا تدخل المخزون أبدًا، والأمرُ المختلط ورقتُه
 * واحدة. وسندُ المورّد لا يُعتمد قبل أن يثبت ما فيه — بالقاعدة الإجماليّة نفسِها،
 * والناقصُ يُقال باسمه: شحنةٌ لم تصل أم خدمةٌ لم يُؤكَّد تنفيذُها.
 */
class AServiceIsConfirmedAsPerformedNotShelvedTest extends TestCase
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
        $this->project = $this->acceptedProject(5000, 10);
        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'المتكاملة', 'supplier_type' => Supplier::BOTH]);
        $this->chairs = Product::create(['business_id' => $this->business->id, 'name' => 'كرسي', 'price' => 10, 'cost' => 4, 'quantity' => 10,
            'alert_qty' => 1, 'active' => true, 'sku' => 'CH-1', 'kind' => Product::ITEM]);
        $this->photography = Product::create(['business_id' => $this->business->id, 'name' => 'تصوير فوتوغرافي', 'price' => 300, 'cost' => 200, 'quantity' => 0,
            'alert_qty' => 0, 'active' => true, 'sku' => 'SRV-PH', 'kind' => Product::SERVICE]);
    }

    private function order(array $items): PurchaseOrder
    {
        $this->actingAs($this->owner)->post(route('admin.purchases.store'), [
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'supplier_id' => $this->supplier->id,
            'payment_method' => 'آجل',
            'ordered_at' => now()->toDateString(),
            'project_id' => $this->project->id,
            'items' => $items,
        ])->assertSessionHasNoErrors();

        return PurchaseOrder::latest('id')->firstOrFail();
    }

    private function invoice(PurchaseOrder $po): SupplierInvoice
    {
        return SupplierInvoices::create((int) $this->business->id, [
            'supplier_id' => $this->supplier->id, 'purchase_order_id' => $po->id, 'supplier_ref' => 'INV-'.$po->id,
            'issued_at' => now()->toDateString(), 'subtotal' => (float) $po->items_subtotal, 'tax' => (float) $po->tax,
        ], $this->owner);
    }

    /** خريطةُ «اسم البند ⇽ الكمية» — تسجيلٌ من باب الشاشة نفسِه */
    private function confirm(PurchaseOrder $po, array $byName): GoodsReceiptNote
    {
        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id), [
            'items' => $po->items()->get()->map(fn ($i) => ['id' => $i->id, 'quantity' => $byName[$i->name] ?? 0])->all(),
        ])->assertSessionHasNoErrors();

        return GoodsReceipts::approve(GoodsReceiptNote::where('purchase_order_id', $po->id)->latest('id')->firstOrFail(), $this->owner);
    }

    public function test_a_services_only_order_is_confirmed_as_performed_and_never_touches_stock(): void
    {
        $po = $this->order([
            ['product_id' => $this->photography->id, 'name' => 'تصوير فوتوغرافي', 'cost' => 200, 'quantity' => 2],
            ['line_kind' => 'service', 'name' => 'أمن الموقع', 'cost' => 100, 'quantity' => 1],
        ]);

        $this->get(route('admin.purchases.show', $po->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('order.receipt_mode', GoodsReceipts::SERVICES)->where('order.items.0.line_kind', 'service'));

        /* السندُ قبل التأكيد لا يطابق — بلغة الخدمة */
        $invoice = $this->invoice($po);
        $this->assertSame(SupplierInvoices::BLOCKED, $invoice->match_status);
        $this->assertStringContainsString('لم يُعتمد تأكيد تنفيذ أيّ خدمة على هذا الأمر بعد', (string) $invoice->match_notes);
        try {
            SupplierInvoices::approve($invoice->fresh(), $this->owner);
            $this->fail('اعتُمد سندُ خدمةٍ لم يُؤكَّد تنفيذُها');
        } catch (RuntimeException) {
        }

        $note = $this->confirm($po, ['تصوير فوتوغرافي' => 2, 'أمن الموقع' => 1]);
        $this->assertTrue(ActivityLog::where('description', 'like', 'سجّل تأكيد تنفيذ خدمة بانتظار الاعتماد '.$note->number.'%')->exists());
        $this->assertTrue(ActivityLog::where('description', 'اعتمد تنفيذ الخدمة '.$note->number)->exists());
        $this->get(route('admin.inventory.receipts.show', $note->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('note.mode', GoodsReceipts::SERVICES));

        $this->assertSame(0, (int) $this->photography->fresh()->quantity, 'الخدمةُ لا تدخل المخزون');
        $this->assertSame(0, InventoryMovement::count(), 'ولا حركةَ مخزونٍ لها');
        $this->get(route('admin.purchases.show', $po->id))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('timeline', fn ($events) => collect($events)->contains(fn ($e) => $e['title'] === 'اعتُمد تنفيذ الخدمة '.$note->number.' — لا تدخل المخزون'))
            ->where('timeline', fn ($events) => ! collect($events)->contains(fn ($e) => str_contains($e['title'], 'دخلت البضاعة الرفّ'))));

        SupplierInvoices::approve($invoice->fresh(), $this->owner);
        $this->assertSame(SupplierInvoices::APPROVED, $invoice->fresh()->approval_status, 'بعد التأكيد يطابق بلا تجاوز');
        $this->assertSame(SupplierInvoices::COST_DIRECT, $invoice->fresh()->cost_kind);
    }

    public function test_a_products_only_order_is_received_onto_the_shelf_as_before(): void
    {
        $po = $this->order([['product_id' => $this->chairs->id, 'name' => 'كرسي', 'cost' => 4, 'quantity' => 30]]);
        $this->get(route('admin.purchases.show', $po->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('order.receipt_mode', GoodsReceipts::GOODS));

        $invoice = $this->invoice($po);
        $this->assertStringContainsString('لم يُعتمد أيُّ استلامٍ على هذا الأمر بعد', (string) $invoice->match_notes, 'البضاعةُ بعبارتها كما كانت');

        $note = $this->confirm($po, ['كرسي' => 30]);
        $this->assertTrue(ActivityLog::where('description', 'اعتمد الاستلام '.$note->number)->exists());
        $this->assertSame(40, (int) $this->chairs->fresh()->quantity, 'دخل الرفّ باعتماده');
        $this->assertSame(1, InventoryMovement::where('product_id', $this->chairs->id)->count());

        SupplierInvoices::approve($invoice->fresh(), $this->owner);
        $this->assertSame(SupplierInvoices::COST_INVENTORY, $invoice->fresh()->cost_kind);
    }

    public function test_a_mixed_order_shelves_the_goods_and_only_confirms_the_service(): void
    {
        $po = $this->order([
            ['product_id' => $this->chairs->id, 'name' => 'كرسي', 'cost' => 4, 'quantity' => 30],
            ['product_id' => $this->photography->id, 'name' => 'تصوير فوتوغرافي', 'cost' => 200, 'quantity' => 1],
        ]);
        $this->get(route('admin.purchases.show', $po->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('order.receipt_mode', GoodsReceipts::MIXED));
        $committed = ProjectFinancials::summary($this->project->fresh())['committed'];

        /* وصلت الكراسي ولم يُؤكَّد التصوير — السندُ الكامل يُمنع، والناقصُ يُقال باسمه */
        $this->confirm($po, ['كرسي' => 30]);
        $this->assertSame(40, (int) $this->chairs->fresh()->quantity);
        $invoice = $this->invoice($po);
        $this->assertSame(SupplierInvoices::BLOCKED, $invoice->match_status);
        $this->assertStringContainsString('تم تأكيد تنفيذ 0 من 1 من الخدمات فقط', (string) $invoice->match_notes);
        $this->assertStringNotContainsString('تم استلام', (string) $invoice->match_notes, 'البضاعةُ وصلت كاملة — لا يُقال عنها نقص');
        try {
            SupplierInvoices::approve($invoice->fresh(), $this->owner);
            $this->fail('اعتُمد سندٌ كامل على خدمةٍ لم تُنفَّذ');
        } catch (RuntimeException) {
        }

        $this->confirm($po, ['تصوير فوتوغرافي' => 1]);
        $this->assertSame(40, (int) $this->chairs->fresh()->quantity, 'تأكيدُ الخدمة لا يزيد الكراسي');
        $this->assertSame(0, (int) $this->photography->fresh()->quantity, 'ولا يُدخل التصويرَ المخزون');
        $this->assertSame(1, InventoryMovement::count());

        SupplierInvoices::approve($invoice->fresh(), $this->owner);
        $after = ProjectFinancials::summary($this->project->fresh());
        $this->assertEqualsWithDelta(0.0, $after['committed'], 0.0005);
        $this->assertEqualsWithDelta($committed, $after['actual'], 0.0005, 'ما كان التزامًا صار فعليًّا بعينه — لا مرّتين');
    }
}
