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
use Illuminate\Support\Facades\DB;
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
            'password' => bcrypt('secret'), 'role' => 'sales', 'status' => 'نشط',
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
    /**
     * «محدّدة» بالبوكيه وحده — كما كتبها بابُ الإضافة قبل أن يُنزع من شاشة
     * المنتج: مداها في عمودها، ومنتجاتُها صفوفٌ في `product_addons`.
     */
    private function narrowToBouquet(Addon $addon): void
    {
        $addon->update(['scope' => Addon::SCOPE_SELECTED]);
        DB::table('product_addons')->insert([
            'business_id' => $this->business->id, 'product_id' => $this->bouquet->id, 'addon_id' => $addon->id,
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
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

        $this->narrowToBouquet($this->extraRoses);

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
        $this->narrowToBouquet($this->extraRoses);

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
}
