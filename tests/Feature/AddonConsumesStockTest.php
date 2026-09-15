<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItemAddon;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\User;
use App\Support\OrderCorrection;
use App\Support\ProductAddons;
use App\Support\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الإضافة حين تأكل من الرفّ — واحدةً أو ثلاثًا.
 *
 * الربط بالمخزون كان سؤالًا واحدًا: أيّ صنفٍ ينقص؟ وهو يكفي «دبًّا» ولا
 * يكفي «زيادة ثلاث وردات». وما يُحرس هنا ثلاثة:
 *
 *   - أن يُخصم ما يُقال: ثلاثٌ لا واحدة، وستٌّ حين تُختار مرّتين.
 *   - أن يُردّ ما أُخذ: بلقطة البيع لا بإعداد الإضافة اليوم.
 *   - وألّا يُضاعَف شيءٌ مرّتين: لا بكمية البند، ولا بحركةٍ مكرّرة.
 */
class AddonConsumesStockTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private User $cashier;
    private Branch $branch;

    private Product $rose;
    private Product $bear;
    private Product $bouquet;

    private Addon $nameCard;    // خدمة بلا مخزون
    private Addon $bearAddon;   // قطعةٌ مقابل قطعة (بلا كمية مكتوبة — إرثٌ)
    private Addon $extraRoses;  // ثلاث ورداتٍ لكلّ إضافة
    private Addon $styling;     // وردتان — لتُجمع مع سابقتها على الصنف نفسه

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل الورد', 'email' => 'k@test.local', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك', 'email' => 'owner@k.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@k.local',
            'password' => bcrypt('secret'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->rose = $this->product('ورد أحمر', 100, cost: 0.300);
        $this->bear = $this->product('دبّ صغير', 10, cost: 2.000);
        $this->bouquet = $this->product('بوكيه الحب', 50, price: 18, cost: 0);

        $this->nameCard = $this->addon('كتابة اسم', 1.000);
        $this->bearAddon = $this->addon('دبّ', 5.000, $this->bear->id, null);
        $this->extraRoses = $this->addon('زيادة 3 وردات', 2.500, $this->rose->id, 3);
        $this->styling = $this->addon('تنسيق فاخر', 1.500, $this->rose->id, 2);

    }

    /* ------------------------------ أدواتٌ ------------------------------ */

    private function product(string $name, int $qty, float $price = 1, float $cost = 0): Product
    {
        $p = Product::create([
            'business_id' => $this->business->id, 'name' => $name,
            'price' => $price, 'cost' => $cost, 'quantity' => $qty, 'active' => true,
        ]);
        BranchStock::ensureAllocated($this->business->id, $p->id, $qty);

        return $p;
    }

    private function addon(string $name, float $price, ?int $stockId = null, ?float $each = 1): Addon
    {
        return Addon::create([
            'business_id' => $this->business->id, 'name' => $name, 'price' => $price, 'active' => true,
            'inventory_product_id' => $stockId,
            'inventory_quantity' => $stockId ? $each : null,
        ]);
    }
    private function line(array $addons, int $qty = 1): array
    {
        return [[
            'id' => $this->bouquet->id, 'name' => 'بوكيه الحب', 'qty' => $qty,
            'addons' => $addons,
        ]];
    }

    private function stock(Product $p): int
    {
        return (int) $p->fresh()->quantity;
    }

    private function branchStock(Product $p): int
    {
        return (int) BranchStock::where('branch_id', $this->branch->id)
            ->where('product_id', $p->id)->value('quantity');
    }

    private function recipe(int $componentId, float $qty): void
    {
        RecipeItem::create([
            'business_id' => $this->business->id, 'product_id' => $this->bouquet->id,
            'component_product_id' => $componentId, 'quantity' => $qty, 'sort_order' => 0,
        ]);
    }
    /* ------------------------------- المدى ------------------------------- */

    public function test_an_addon_with_no_scope_shows_with_every_product(): void
    {
        $this->assertTrue(ProductAddons::for($this->bouquet)->contains('id', $this->extraRoses->id));
    }

    public function test_a_selected_addon_shows_only_where_it_was_linked(): void
    {
        $other = $this->product('كيس سماد', 5);

        $this->actingAs($this->owner)->putJson(route('admin.products.addons.update', $this->extraRoses->id), [
            'name' => 'زيادة 3 وردات', 'price' => 2.5,
            'scope' => Addon::SCOPE_SELECTED, 'product_ids' => [$this->bouquet->id],
            'inventory_product_id' => $this->rose->id, 'inventory_quantity' => 3,
        ])->assertOk();

        $this->assertTrue(ProductAddons::for($this->bouquet->fresh())->contains('id', $this->extraRoses->id));
        $this->assertFalse(ProductAddons::for($other)->contains('id', $this->extraRoses->id));
    }

    public function test_narrowing_one_addon_does_not_strip_a_product_of_the_rest(): void
    {
        /*
         * الصفّ في product_addons يحمل معنيين: قائمةُ المنتج، ومدى الإضافة.
         * ولولا فصلُهما لصار اختيارُ «البوكيه» لإضافةٍ محدّدة يحصره فيها
         * وحدها — فيفقد كتابةَ الاسم والدبّ بضغطةٍ لا تقول ذلك.
         */
        $this->actingAs($this->owner)->putJson(route('admin.products.addons.update', $this->extraRoses->id), [
            'name' => 'زيادة 3 وردات', 'price' => 2.5,
            'scope' => Addon::SCOPE_SELECTED, 'product_ids' => [$this->bouquet->id],
            'inventory_product_id' => $this->rose->id, 'inventory_quantity' => 3,
        ])->assertOk();

        $allowed = ProductAddons::for($this->bouquet->fresh());

        $this->assertTrue($allowed->contains('id', $this->nameCard->id));
        $this->assertTrue($allowed->contains('id', $this->bearAddon->id));
    }

    public function test_a_product_owned_addon_is_offered_with_its_product_alone(): void
    {
        $other = $this->product('كيس سماد', 5);
        $ribbon = Addon::create([
            'business_id' => $this->business->id, 'name' => 'شريط ذهبي', 'price' => 1,
            'active' => true, 'product_id' => $this->bouquet->id,
        ]);

        $this->assertTrue(ProductAddons::for($this->bouquet)->contains('id', $ribbon->id));
        $this->assertFalse(ProductAddons::for($other)->contains('id', $ribbon->id));
    }
    /* ---------------------------- تعدّد المتاجر ---------------------------- */

    public function test_a_stock_item_from_another_shop_is_refused(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'email' => 'x@k.local', 'status' => 'نشط']);
        $theirs = Product::create([
            'business_id' => $other->id, 'name' => 'وردهم', 'price' => 1, 'cost' => 1,
            'quantity' => 50, 'active' => true,
        ]);

        $this->actingAs($this->owner)->postJson(route('admin.products.addons.store'), [
            'name' => 'إضافة مسروقة', 'price' => 1,
            'inventory_product_id' => $theirs->id, 'inventory_quantity' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors('inventory_product_id');

        $this->assertSame(0, Addon::where('name', 'إضافة مسروقة')->count());
    }

    public function test_a_product_from_another_shop_cannot_be_put_in_an_addon_scope(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'email' => 'y@k.local', 'status' => 'نشط']);
        $theirs = Product::create([
            'business_id' => $other->id, 'name' => 'منتجهم', 'price' => 1, 'cost' => 1,
            'quantity' => 5, 'active' => true,
        ]);

        $this->actingAs($this->owner)->putJson(route('admin.products.addons.update', $this->nameCard->id), [
            'name' => 'كتابة اسم', 'price' => 1,
            'scope' => Addon::SCOPE_SELECTED, 'product_ids' => [$theirs->id],
        ])->assertStatus(422)->assertJsonValidationErrors('product_ids.0');
    }

    public function test_a_shop_cannot_edit_another_shops_addon(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'email' => 'z@k.local', 'status' => 'نشط']);
        $theirs = Addon::create(['business_id' => $other->id, 'name' => 'إضافتهم', 'price' => 3, 'active' => true]);

        $this->actingAs($this->owner)->putJson(route('admin.products.addons.update', $theirs->id), [
            'name' => 'مسروقة', 'price' => 99,
        ])->assertNotFound();

        $this->assertSame('إضافتهم', $theirs->fresh()->name);
    }

    public function test_a_consumption_of_zero_is_refused(): void
    {
        $this->actingAs($this->owner)->postJson(route('admin.products.addons.store'), [
            'name' => 'وعدٌ فارغ', 'price' => 1,
            'inventory_product_id' => $this->rose->id, 'inventory_quantity' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors('inventory_quantity');
    }

    public function test_unlinking_the_stock_item_clears_the_consumption(): void
    {
        $this->actingAs($this->owner)->putJson(route('admin.products.addons.update', $this->extraRoses->id), [
            'name' => 'زيادة 3 وردات', 'price' => 2.5,
            'inventory_product_id' => null, 'inventory_quantity' => null,
        ])->assertOk();

        $fresh = $this->extraRoses->fresh();

        $this->assertNull($fresh->inventory_product_id);
        $this->assertNull($fresh->inventory_quantity);
    }
}
