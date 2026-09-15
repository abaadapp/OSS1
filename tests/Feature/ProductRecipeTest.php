<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\OrderCorrection;
use App\Support\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الوصفة — بيعُ الباقة ينقص الورد لا الباقة.
 *
 * وهذا أخطر ما في المهمّة كلّها: خطأٌ في الخصم لا يظهر في شاشةٍ ولا في
 * رسالة، بل في جردٍ بعد شهر حين لا يعرف أحدٌ من أين جاء الفرق.
 */
class ProductRecipeTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private User $cashier;
    private Branch $branch;
    private Product $bouquet;
    private Product $rose;
    private Product $wrap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل الوصفات', 'email' => 'r@test.local', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك', 'email' => 'owner@r.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@r.local',
            'password' => bcrypt('secret'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->bouquet = Product::create([
            'business_id' => $this->business->id, 'name' => 'بوكيه الحب',
            'price' => 18, 'cost' => 0, 'quantity' => 0, 'active' => true,
        ]);

        $this->rose = Product::create([
            'business_id' => $this->business->id, 'name' => 'ورد أحمر',
            'price' => 1, 'cost' => 0.5, 'quantity' => 100, 'active' => true,
        ]);

        $this->wrap = Product::create([
            'business_id' => $this->business->id, 'name' => 'تغليف',
            'price' => 1, 'cost' => 0.25, 'quantity' => 40, 'active' => true,
        ]);

    }

    private function ingredient(Product $component, float $qty, ?ProductVariant $variant = null, float $wastage = 0): RecipeItem
    {
        return RecipeItem::create([
            'business_id' => $this->business->id,
            'product_id' => $this->bouquet->id,
            'variant_id' => $variant?->id,
            'component_product_id' => $component->id,
            'quantity' => $qty,
            'wastage_percent' => $wastage,
        ]);
    }

    private function variant(string $name, float $price): ProductVariant
    {
        return ProductVariant::create([
            'business_id' => $this->business->id, 'product_id' => $this->bouquet->id,
            'name' => $name, 'price' => $price, 'active' => true,
        ]);
    }
    private function line(int $qty = 1, ?ProductVariant $variant = null): array
    {
        return [
            'id' => $this->bouquet->id,
            'variant_id' => $variant?->id,
            'name' => 'بوكيه الحب',
            'qty' => $qty,
        ];
    }

    /* ------------------------------ التركيب ------------------------------ */

    public function test_a_product_can_hold_a_recipe(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.products.recipe.store', $this->bouquet->id), [
                'component_product_id' => $this->rose->id, 'quantity' => 12,
            ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('recipe_items', [
            'product_id' => $this->bouquet->id,
            'component_product_id' => $this->rose->id,
            'variant_id' => null,
        ]);
    }

    public function test_a_component_from_another_shop_is_refused(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'email' => 'o@r.local', 'status' => 'نشط']);
        $theirRose = Product::create([
            'business_id' => $other->id, 'name' => 'وردهم', 'price' => 1, 'cost' => 1, 'quantity' => 99,
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.products.recipe.store', $this->bouquet->id), [
                'component_product_id' => $theirRose->id, 'quantity' => 5,
            ])->assertSessionHasErrors('component_product_id');

        $this->assertDatabaseCount('recipe_items', 0);
    }

    public function test_a_product_cannot_be_a_component_of_itself(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.products.recipe.store', $this->bouquet->id), [
                'component_product_id' => $this->bouquet->id, 'quantity' => 1,
            ])->assertSessionHasErrors('component_product_id');
    }

    public function test_a_bouquet_cannot_be_nested_inside_another_recipe(): void
    {
        /*
         * الإصدار الأوّل يقصر المكوّنات على أصنافٍ مخزنيّة مباشرة — فلا حلقة
         * (أ يحوي ب، وب يحوي أ) تُدخل الخصم في تكرارٍ لا ينتهي.
         */
        $this->ingredient($this->rose, 12);

        $gift = Product::create([
            'business_id' => $this->business->id, 'name' => 'طقم هدية',
            'price' => 40, 'cost' => 0, 'quantity' => 0,
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.products.recipe.store', $gift->id), [
                'component_product_id' => $this->bouquet->id, 'quantity' => 1,
            ])->assertSessionHasErrors('component_product_id');
    }
}
