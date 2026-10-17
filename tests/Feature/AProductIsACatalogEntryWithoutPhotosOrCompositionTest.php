<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * المنتجُ بندٌ في كتالوج معارضَ وفعاليّات — لا صورَ له ولا تركيب.
 *
 * «صور المنتج» و«التركيب» (مقاساتٌ ووصفةٌ وإضافات) من بقايا متجر التجزئة:
 * خرجا من نموذج المنتج ومن صفحته وقائمته، وأبوابُهما أُغلقت. والمعلوماتُ
 * الأساسيّة اسمٌ ونوعٌ وتصنيفٌ ووصفٌ وكودٌ وحال. والباقةُ تبقى ببنودها لأنّ
 * عرضَ السعر يفكّها.
 *
 * ولا يُمحى قديم: صورةٌ على القرص وصفوفُ معرضٍ ومقاساتٌ ووصفاتٌ وإضافات تبقى
 * كما هي — والمحوُ النهائيّ من السلّة وحده يمحو ملفّاتِ منتجه.
 */
class AProductIsACatalogEntryWithoutPhotosOrCompositionTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('public');

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($this->owner);
    }

    private function product(array $over = []): Product
    {
        return Product::create($over + [
            'business_id' => $this->business->id, 'name' => 'شاشة LED', 'price' => 120, 'cost' => 60,
            'quantity' => 4, 'alert_qty' => 1, 'active' => true, 'sku' => 'LED-'.uniqid(), 'barcode' => '628'.random_int(1000000000, 9999999999),
        ]);
    }

    public function test_the_basic_information_is_a_catalog_entry(): void
    {
        $category = Category::create(['business_id' => $this->business->id, 'name' => 'تصوير']);

        $this->post(route('admin.products.store'), [
            'name' => 'تصوير فوتوغرافي للفعالية', 'name_en' => 'Event photography', 'kind' => Product::SERVICE,
            'category_id' => $category->id, 'description' => 'مصوّران ليومٍ كامل وتسليم الصور خلال 3 أيام',
            'sku' => 'PHOTO-1', 'active' => false, 'price' => 250, 'quantity' => 40,
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.products.index'));

        $p = Product::where('sku', 'PHOTO-1')->sole();
        $this->assertSame('تصوير فوتوغرافي للفعالية', $p->name);
        $this->assertSame('Event photography', $p->name_en);
        $this->assertSame(Product::SERVICE, $p->kind);
        $this->assertSame($category->id, (int) $p->category_id);
        $this->assertSame('مصوّران ليومٍ كامل وتسليم الصور خلال 3 أيام', $p->description);
        $this->assertFalse((bool) $p->active);
        $this->assertSame(0, (int) $p->quantity, 'الخدمة لا رفَّ لها');
        $this->assertNotEmpty($p->barcode, 'الباركود يُولَّد في الخادم ولا يُسأل عنه في النموذج');
    }

    public function test_the_screens_carry_no_photo_and_no_composition(): void
    {
        $p = $this->product();

        $this->get(route('admin.products.create'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Products/Create')
                ->has('package.options')->where('package.items', [])
                ->missing('composition')->missing('gallery'));

        $this->get(route('admin.products.edit', $p->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Products/Edit')
                ->has('package.items')->has('package.options')
                ->missing('composition')->missing('gallery')->missing('galleryMax')->missing('galleryLimits'));

        // وصفحتُه بلا صور، ووصفُه ما كُتب لا وصفَ «باقة ورود» لكلّ بند
        $this->get(route('admin.products.show', $p->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Products/Show')
                ->missing('thumbs')->where('description', ''));

        $this->get(route('admin.products.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('products.0.image'));
    }

    public function test_a_sneaked_photo_or_composition_is_not_read(): void
    {
        $chocolate = $this->product(['name' => 'مكوّن']);

        $this->post(route('admin.products.store'), [
            'name' => 'منصة عرض', 'price' => 80, 'sku' => 'STAND-1',
            'image' => UploadedFile::fake()->image('stand.jpg'),
            'composition' => [
                'variants' => [['name' => 'كبير', 'price' => 100]],
                'recipe' => [['component_product_id' => $chocolate->id, 'quantity' => 2]],
                'new_addons' => [['name' => 'تغليف', 'price' => 1]],
            ],
        ])->assertSessionHasNoErrors();

        $stand = Product::where('sku', 'STAND-1')->sole();
        $this->assertNull($stand->getRawOriginal('image'));
        $this->assertSame(0, ProductVariant::count() + RecipeItem::count() + Addon::count() + ProductImage::count());
        $this->assertSame([], Storage::disk('public')->allFiles());

        $this->put(route('admin.products.update', $stand->id), [
            'name' => 'منصة عرض', 'price' => 90, 'image' => UploadedFile::fake()->image('again.jpg'),
        ])->assertSessionHasNoErrors();
        $this->assertNull($stand->fresh()->getRawOriginal('image'));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_photo_and_composition_doors_are_gone_and_the_package_stays(): void
    {
        foreach ([
            'admin.products.images.store', 'admin.products.images.promote', 'admin.products.images.destroy', 'admin.products.images.destroyMain',
            'admin.products.variants.store', 'admin.products.variants.update', 'admin.products.variants.destroy',
            'admin.products.recipe.store', 'admin.products.recipe.update', 'admin.products.recipe.destroy',
            'admin.products.addons.store', 'admin.products.addons.update', 'admin.products.addons.sync', 'admin.products.addons.destroy',
        ] as $gone) {
            $this->assertFalse(Route::has($gone), $gone);
        }

        foreach (['admin.products.package.store', 'admin.products.package.update', 'admin.products.package.destroy', 'admin.products.categories.store'] as $kept) {
            $this->assertTrue(Route::has($kept), $kept);
        }

        $package = $this->product(['name' => 'باقة مؤتمر', 'kind' => Product::PACKAGE, 'quantity' => 0]);
        $screen = $this->product(['name' => 'شاشة LED كبيرة']);
        $this->post(route('admin.products.package.store', $package->id), ['item_id' => $screen->id, 'quantity' => 2])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, PackageItem::where('package_id', $package->id)->count());

        $this->get(route('admin.products.edit', $package->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('package.items.0.name', 'شاشة LED كبيرة')
                ->where('package.items.0.quantity', 2));
    }

    public function test_what_was_there_before_is_kept_untouched(): void
    {
        Storage::disk('public')->put('products/old-main.jpg', 'jpg');
        Storage::disk('public')->put('products/old-extra.jpg', 'jpg');
        $p = $this->product(['image' => 'products/old-main.jpg']);
        $component = $this->product(['name' => 'كابل HDMI']);
        ProductImage::create(['business_id' => $this->business->id, 'product_id' => $p->id, 'path' => 'products/old-extra.jpg']);
        $variant = ProductVariant::create(['business_id' => $this->business->id, 'product_id' => $p->id, 'name' => 'كبير', 'price' => 150]);
        RecipeItem::create(['business_id' => $this->business->id, 'product_id' => $p->id, 'component_product_id' => $component->id, 'quantity' => 1]);
        $addon = Addon::create(['business_id' => $this->business->id, 'name' => 'تركيب', 'price' => 5, 'active' => true]);
        DB::table('product_addons')->insert(['business_id' => $this->business->id, 'product_id' => $p->id, 'addon_id' => $addon->id, 'sort_order' => 0]);

        $this->put(route('admin.products.update', $p->id), [
            'name' => 'شاشة LED', 'price' => 130, 'kind' => Product::ITEM, 'quantity' => 4, 'description' => 'شاشة داخلية',
        ])->assertSessionHasNoErrors();

        $p->refresh();
        $this->assertSame(130.0, (float) $p->price);
        $this->assertSame('products/old-main.jpg', $p->getRawOriginal('image'), 'الصورةُ القديمة لا تُمحى بحفظ النموذج');
        Storage::disk('public')->assertExists('products/old-main.jpg');
        Storage::disk('public')->assertExists('products/old-extra.jpg');
        $this->assertSame(1, ProductImage::where('product_id', $p->id)->count());
        $this->assertNotNull($variant->fresh());
        $this->assertSame(1, RecipeItem::where('product_id', $p->id)->count());
        $this->assertSame(1, DB::table('product_addons')->where('product_id', $p->id)->count());
    }

    public function test_purging_a_product_still_takes_its_old_photos_off_the_disk(): void
    {
        Storage::disk('public')->put('products/a.jpg', 'jpg');
        Storage::disk('public')->put('products/b.jpg', 'jpg');
        $p = $this->product(['image' => 'products/a.jpg', 'quantity' => 0]);
        ProductImage::create(['business_id' => $this->business->id, 'product_id' => $p->id, 'path' => 'products/b.jpg']);

        $this->delete(route('admin.products.destroy', $p->id), ['ack_stock' => true]);
        $this->delete(route('admin.products.purge', $p->id))->assertSessionHasNoErrors();

        $this->assertNull(Product::withTrashed()->find($p->id));
        Storage::disk('public')->assertMissing('products/a.jpg');
        Storage::disk('public')->assertMissing('products/b.jpg');
    }
}
