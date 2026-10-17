<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * قسم المنتجات — المفاتيح الموصولة، والرموز التي لا تتكرّر، والنسخة التي
 * تشبه أصلها فعلًا.
 *
 * وكلّ ما هنا يمرّ في الشاشة بلا خطأ: مفتاحٌ يُضغط فلا يفعل شيئًا، ونسخةٌ
 * تبدو طبق الأصل وتسلك سلوكًا آخر، وقسمٌ من متجر الجار يُقرأ اسمُه هنا.
 */
class ProductSectionAuditTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function product(array $over = []): Product
    {
        return Product::create(array_merge([
            'business_id' => $this->business->id, 'name' => 'قميص', 'price' => 10, 'cost' => 6,
            'quantity' => 20, 'alert_qty' => 5, 'active' => true,
            'sku' => 'SH-'.uniqid(), 'barcode' => '628'.random_int(1000000000, 9999999999),
        ], $over));
    }

    private function neighbour(): Business
    {
        return Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
    }

    /* --------------------- القسم من أقسام هذا المتجر --------------------- */

    public function test_adding_a_product_refuses_a_category_of_another_store(): void
    {
        $theirs = Category::create(['business_id' => $this->neighbour()->id, 'name' => 'أقسامهم']);

        $this->post(route('admin.products.store'), [
            'name' => 'وردة', 'price' => 5, 'category_id' => $theirs->id,
        ])->assertSessionHasErrors('category_id');

        $this->assertSame(0, Product::where('business_id', $this->business->id)->count());
    }

    public function test_editing_a_product_refuses_a_category_of_another_store(): void
    {
        $theirs = Category::create(['business_id' => $this->neighbour()->id, 'name' => 'أقسامهم']);
        $p = $this->product();

        $this->put(route('admin.products.update', $p->id), [
            'name' => 'قميص', 'price' => 10, 'category_id' => $theirs->id,
        ])->assertSessionHasErrors('category_id');

        $this->assertNull($p->fresh()->category_id);
    }

    public function test_our_own_category_is_accepted(): void
    {
        $mine = Category::create(['business_id' => $this->business->id, 'name' => 'ورود']);

        $this->post(route('admin.products.store'), [
            'name' => 'وردة', 'price' => 5, 'category_id' => $mine->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($mine->id, Product::firstOrFail()->category_id);
    }

    public function test_the_bulk_action_still_refuses_a_foreign_category(): void
    {
        $theirs = Category::create(['business_id' => $this->neighbour()->id, 'name' => 'أقسامهم']);
        $p = $this->product();

        $this->post(route('admin.products.bulk'), [
            'action' => 'category', 'ids' => [$p->id], 'category_id' => $theirs->id,
        ]);

        $this->assertNull($p->fresh()->category_id);
    }
    /* ------------------- النسخة تشبه أصلها ------------------- */

    public function test_a_copy_carries_the_recipe_and_the_sizes(): void
    {
        $stem = $this->product(['name' => 'ساق', 'quantity' => 100]);
        $bouquet = $this->product(['name' => 'باقة']);
        $variant = ProductVariant::create([
            'business_id' => $this->business->id, 'product_id' => $bouquet->id,
            'name' => 'كبير', 'price' => 30, 'active' => true,
        ]);
        RecipeItem::create([
            'business_id' => $this->business->id, 'product_id' => $bouquet->id,
            'variant_id' => $variant->id, 'component_product_id' => $stem->id, 'quantity' => 12,
        ]);

        $this->post(route('admin.products.duplicate', $bouquet->id));
        $copy = Product::where('name', 'like', '%نسخة%')->firstOrFail();

        // ولو نُسخ الصفّ وحده لبيعت النسخة بلا أن تُنقص ساقًا واحدة
        $this->assertSame(1, RecipeItem::where('product_id', $copy->id)->count());
        $this->assertSame(1, ProductVariant::where('product_id', $copy->id)->count());
    }

    public function test_the_copied_recipe_points_at_the_copies_own_size(): void
    {
        $stem = $this->product(['name' => 'ساق', 'quantity' => 100]);
        $bouquet = $this->product(['name' => 'باقة']);
        $variant = ProductVariant::create([
            'business_id' => $this->business->id, 'product_id' => $bouquet->id,
            'name' => 'كبير', 'price' => 30, 'active' => true,
        ]);
        RecipeItem::create([
            'business_id' => $this->business->id, 'product_id' => $bouquet->id,
            'variant_id' => $variant->id, 'component_product_id' => $stem->id, 'quantity' => 12,
        ]);

        $this->post(route('admin.products.duplicate', $bouquet->id));
        $copy = Product::where('name', 'like', '%نسخة%')->firstOrFail();
        $copiedItem = RecipeItem::where('product_id', $copy->id)->firstOrFail();
        $copiedVariant = ProductVariant::where('product_id', $copy->id)->firstOrFail();

        // وإلّا أشارت وصفةُ النسخة إلى مقاسٍ في الأصل فلم تُقرأ أبدًا
        $this->assertSame($copiedVariant->id, $copiedItem->variant_id);
        $this->assertNotSame($variant->id, $copiedItem->variant_id);
    }

    public function test_a_copy_carries_the_allowed_addons(): void
    {
        $addon = Addon::create([
            'business_id' => $this->business->id, 'name' => 'شريط', 'price' => 1, 'active' => true,
        ]);
        $p = $this->product();
        \DB::table('product_addons')->insert([
            'business_id' => $this->business->id, 'product_id' => $p->id,
            'addon_id' => $addon->id, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->post(route('admin.products.duplicate', $p->id));
        $copy = Product::where('name', 'like', '%نسخة%')->firstOrFail();

        // والفارغة تعني «كلّ الإضافات» لا «لا شيء» — فإسقاط الربط يوسّع النسخة
        $this->assertSame([$addon->id], $copy->allowedAddons()->pluck('addons.id')->all());
    }

    public function test_a_copy_still_starts_with_no_stock_and_new_codes(): void
    {
        $p = $this->product(['quantity' => 40]);

        $this->post(route('admin.products.duplicate', $p->id));
        $copy = Product::where('name', 'like', '%نسخة%')->firstOrFail();

        $this->assertSame(0, (int) $copy->quantity);
        $this->assertNotSame($p->sku, $copy->sku);
        $this->assertNotSame($p->barcode, $copy->barcode);
    }
    /* ------------------- الحذف الجماعي له فاعل ------------------- */

    public function test_a_bulk_deletion_records_who_did_it(): void
    {
        $p = $this->product();

        $this->post(route('admin.products.bulk'), ['action' => 'delete', 'ids' => [$p->id], 'ack_stock' => true]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'deleted', 'subject_type' => 'product', 'subject_id' => $p->id,
        ]);
    }

    /* ------------------- الصورة خرجت من نموذج المنتج ------------------- */

    /** ونموذج المنتج ما عاد يقبلها: بابٌ مغلقٌ لا يُكتب منه شيء — والصورُ كلُّها خرجت من تجربة المنتج */
    public function test_the_product_form_no_longer_carries_the_image(): void
    {
        Storage::fake('public');
        $p = $this->product();
        $before = $p->getRawOriginal('image');

        $this->put(route('admin.products.update', $p->id), [
            'name' => 'قميص', 'price' => 10,
            'image' => UploadedFile::fake()->image('sneaked.jpg'),
        ])->assertSessionHasNoErrors();

        $this->assertSame($before, $p->fresh()->getRawOriginal('image'), 'كُتبت الصورة من بابٍ أُغلق');
        $this->assertSame(0, Storage::disk('public')->files('products') === [] ? 0 : count(Storage::disk('public')->files('products')));
    }
}
