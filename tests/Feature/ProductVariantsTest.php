<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المقاسات — «بوكيه الحب» صغير ووسط وكبير.
 *
 * وشرطُ القبول الأوّل ليس أن تعمل المقاسات، بل ألّا ينكسر ما قبلها: مئةُ
 * منتجٍ في متاجر قائمة لا مقاس لها، وبيعُها يجب أن يبقى كما كان حرفًا بحرف.
 */
class ProductVariantsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private User $cashier;
    private Product $bouquet;
    private Product $simple;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'email' => 'v@test.local', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك', 'email' => 'owner@v.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@v.local',
            'password' => bcrypt('secret'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->bouquet = Product::create([
            'business_id' => $this->business->id, 'name' => 'بوكيه الحب',
            'price' => 15, 'cost' => 6, 'quantity' => 50, 'active' => true,
        ]);

        $this->simple = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة مفردة',
            'price' => 2, 'cost' => 1, 'quantity' => 200, 'active' => true,
        ]);

    }

    private function variant(array $attributes = []): ProductVariant
    {
        return ProductVariant::create(array_merge([
            'business_id' => $this->business->id,
            'product_id' => $this->bouquet->id,
            'name' => 'وسط',
            'price' => 18,
            'active' => true,
        ], $attributes));
    }
    /* ------------------------------ الإنشاء ------------------------------ */

    public function test_a_business_can_add_a_size_to_its_product(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.products.variants.store', $this->bouquet->id), [
                'name' => 'كبير', 'price' => 25,
            ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('product_variants', [
            'business_id' => $this->business->id,
            'product_id' => $this->bouquet->id,
            'name' => 'كبير',
        ]);
    }

    public function test_a_size_belongs_to_its_product_and_its_business(): void
    {
        $variant = $this->variant();

        $this->assertSame($this->bouquet->id, $variant->product->id);
        $this->assertSame($this->business->id, (int) $variant->business_id);
    }

    public function test_one_shop_cannot_touch_another_shops_size(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'email' => 'o@v.local', 'status' => 'نشط']);
        $theirProduct = Product::create([
            'business_id' => $other->id, 'name' => 'باقتهم', 'price' => 9, 'cost' => 3, 'quantity' => 5,
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.products.variants.store', $theirProduct->id), ['name' => 'كبير', 'price' => 25])
            ->assertNotFound();

        $this->assertDatabaseCount('product_variants', 0);
    }
}
