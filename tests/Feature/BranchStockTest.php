<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * البيع يحترم رصيد الفرع، والدفاتر تبقى متوازنة.
 *
 * كانت نقطة البيع تقرأ products.quantity (مجموع الشركة) وتخصم من
 * branch_stocks (رصيد الفرع) — فتُجيز بيع خمس قطع من صلالة ورصيدها صفر
 * لأن في مسقط عشرًا، ويخرج الجدولان من التوازن في المعاملة نفسها. وكان
 * max(0, …) في adjust يُخفي الانحراف فلا يظهر في أي تقرير.
 */
class BranchStockTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $muscat;

    private Branch $salalah;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->muscat = Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
        $this->salalah = Branch::create(['business_id' => $this->business->id, 'name' => 'صلالة']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 10, 'cost' => 4, 'quantity' => 10, 'alert_qty' => 2, 'active' => true,
        ]);

    }

    /** يوزّع 10 على مسقط و0 على صلالة */
    private function allocate(int $muscat = 10, int $salalah = 0): void
    {
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->muscat->id,
            'product_id' => $this->product->id, 'quantity' => $muscat,
        ]);
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->salalah->id,
            'product_id' => $this->product->id, 'quantity' => $salalah,
        ]);
    }
    /* ------------------------ لا قصّ للانحراف ------------------------ */

    public function test_adjust_no_longer_hides_drift_behind_a_zero_floor(): void
    {
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->muscat->id,
            'product_id' => $this->product->id, 'quantity' => 2,
        ]);

        BranchStock::adjust($this->business->id, $this->muscat->id, $this->product->id, -5);

        $this->assertSame(
            -3,
            (int) BranchStock::where('branch_id', $this->muscat->id)
                ->where('product_id', $this->product->id)->value('quantity'),
            'قُصّ الرصيد عند صفر فاختفى الانحراف',
        );
    }

    /* --------------------- بقية مسارات الكتابة --------------------- */

    public function test_receiving_a_purchase_order_keeps_the_books_balanced(): void
    {
        $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّد']);
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id, 'branch_id' => $this->salalah->id,
            'supplier_id' => $supplier->id, 'number' => 'PO-1',
            'status' => 'مطلوب', 'total' => 60, 'ordered_at' => now(),
        ]);
        $po->items()->create([
            'product_id' => $this->product->id, 'name' => $this->product->name,
            'quantity' => 6, 'cost' => 10,
        ]);

        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id));
        $this->approvePendingReceipts($this->business->id);

        $this->assertSame(16, (int) $this->product->fresh()->quantity);
        $this->assertSame(
            16,
            (int) BranchStock::where('product_id', $this->product->id)->sum('quantity'),
            'الاستلام كسر التوازن',
        );
        // البضاعة وصلت صلالة، فتُباع من صلالة لا من مسقط
        $this->assertSame(6, (int) BranchStock::where('branch_id', $this->salalah->id)
            ->where('product_id', $this->product->id)->value('quantity'));
    }

    public function test_an_inventory_movement_keeps_the_books_balanced(): void
    {
        $this->actingAs($this->owner)->post(route('admin.inventory.store'), [
            'product_id' => $this->product->id,
            'branch_id' => $this->salalah->id,
            'type' => 'إضافة كمية',
            'quantity' => 4,
        ]);

        $this->assertSame(14, (int) $this->product->fresh()->quantity);
        $this->assertSame(
            14,
            (int) BranchStock::where('product_id', $this->product->id)->sum('quantity'),
        );
    }
}
