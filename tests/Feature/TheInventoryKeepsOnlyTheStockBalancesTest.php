<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\GoodsReceiptNote;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Demo;
use App\Support\GoodsReceipts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * قسمُ المخزون لرصيد المنتجات وحده.
 *
 * «عمليات جرد المخزون» و«سجل المخزون» و«إشعار استلام بضاعة» خرجت من الواجهة:
 * لا تبويبَ ولا صفحة، وعناوينُها المحفوظة تعود إلى «المخزون» بدل أن تنكسر.
 *
 * والبنيةُ تحتها باقية: استلامُ أمر الشراء يكتب سندًا ينتظر الاعتماد، والسندُ
 * يُعتمد من صفحته فتدخل البضاعةُ الرفَّ بحركتها وقيدها — وما ينتظر يُعرض في
 * «المخزون» وفي الجرس. والصفوفُ القديمة لا تُمحى.
 */
class TheInventoryKeepsOnlyTheStockBalancesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Product $product;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'كرسي فعاليات', 'price' => 3, 'cost' => 1, 'quantity' => 0,
        ]);
        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مخازن الكراسي']);
    }

    private function order(int $qty = 50): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'number' => 'PO-'.random_int(1000, 9999), 'supplier_id' => $this->supplier->id, 'supplier_name' => $this->supplier->name,
            'status' => 'مُرسل', 'total' => $qty, 'ordered_at' => now(),
        ]);
        $po->items()->create(['product_id' => $this->product->id, 'name' => $this->product->name, 'cost' => 1, 'quantity' => $qty]);

        return $po->refresh();
    }

    public function test_the_three_screens_are_gone_and_their_addresses_lead_home(): void
    {
        $this->actingAs($this->owner);

        foreach (['admin.inventory.stocktake', 'admin.inventory.adjustments', 'admin.inventory.receipts'] as $name) {
            $this->get(route($name))->assertRedirect(route('admin.inventory.index'));
        }

        foreach (['Stocktake', 'Adjustments', 'Receipts'] as $page) {
            $this->assertFileDoesNotExist(resource_path("js/Pages/Admin/Inventory/{$page}.tsx"));
        }

        $tabs = (string) file_get_contents(resource_path('js/Components/SectionTabs.tsx'));
        foreach (['admin.inventory.stocktake', 'admin.inventory.adjustments', 'admin.inventory.receipts'] as $gone) {
            $this->assertStringNotContainsString("routeName: '{$gone}'", $tabs, $gone.' ما زال تبويبًا');
        }

        $this->get(route('admin.inventory.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Inventory/Index')->where('pendingReceipts', []));
    }

    public function test_receiving_a_purchase_order_still_reaches_the_shelf_once_approved(): void
    {
        $po = $this->order(50);
        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id))->assertSessionHasNoErrors();
        $note = GoodsReceiptNote::sole();
        $this->assertSame(GoodsReceipts::PENDING, $note->status);
        $this->assertSame(0, (int) $this->product->fresh()->quantity, 'لا تدخل الرفَّ قبل الاعتماد');

        // ينتظر في «المخزون» وفي الجرس — وكلاهما يقود إلى صفحة السند
        $this->get(route('admin.inventory.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('pendingReceipts.0.id', $note->id)
                ->where('pendingReceipts.0.number', $note->number)
                ->where('pendingReceipts.0.supplier', 'مخازن الكراسي')
                ->where('pendingReceipts.0.order', $po->number));
        $bell = collect(Demo::notifications())->firstWhere('key', 'grn-'.$note->id);
        $this->assertSame(route('admin.inventory.receipts.show', $note->id), $bell['url']);

        $this->get(route('admin.inventory.receipts.show', $note->id))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Inventory/ReceiptShow')->where('can.approve', true));
        $this->post(route('admin.purchases.receipts.approve', $note->id))->assertSessionHasNoErrors();

        $this->assertSame(50, (int) $this->product->fresh()->quantity, 'دخلت البضاعةُ الرفَّ بعد الاعتماد');
        $this->assertSame(1, InventoryMovement::where('product_id', $this->product->id)->count());
        $this->get(route('admin.inventory.index'))->assertInertia(fn ($p) => $p->where('pendingReceipts', []));
    }

    public function test_the_ledger_doors_stay_without_a_screen_and_old_rows_are_kept(): void
    {
        // فيهما وحدَهما قيدُ الفاقد والزيادة في الدفترين — بابان بلا شاشة لا محذوفان
        $this->assertTrue(Route::has('admin.inventory.stocktake.apply'));
        $this->assertTrue(Route::has('admin.inventory.adjustments.store'));
        $this->assertTrue(Route::has('admin.inventory.receipts.show'));
        $this->assertTrue(Route::has('admin.inventory.receipts.pdf'));

        $old = StockAdjustment::create([
            'business_id' => $this->business->id, 'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'product_id' => $this->product->id, 'number' => 'SA-000001', 'quantity_delta' => -1, 'cost_at_time' => 1,
            'reason' => 'تالف', 'adjusted_at' => now()->toDateString(),
        ]);

        $this->actingAs($this->owner)->get(route('admin.inventory.adjustments'))->assertRedirect();
        $this->assertNotNull($old->fresh(), 'سجلُّ التسويات لا يُمحى بخروج شاشته');
    }

    public function test_another_company_waits_on_its_own_receipts(): void
    {
        $other = Business::create(['name' => 'شركة أخرى', 'type' => 'عام', 'status' => 'نشط']);
        GoodsReceiptNote::create([
            'business_id' => $other->id, 'number' => 'GRN-000099', 'status' => GoodsReceipts::PENDING,
            'received_at' => now()->toDateString(),
        ]);

        $this->actingAs($this->owner)->get(route('admin.inventory.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('pendingReceipts', []));
    }
}
