<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * منتجٌ بلا صورةٍ يقول «بلا صورة» — ولا يُعطى صورةَ شيءٍ آخر.
 *
 * ═══ ما كان ═══
 *
 * `Product::getImageAttribute` كانت تردّ رابط `picsum.photos` — خدمةَ صورٍ
 * عشوائيّة على الإنترنت — لكلّ منتجٍ لم تُرفع له صورة. و`ProductController`
 * كانت **تكتب** ذلك الرابط في العمود عند الإضافة، والاستيرادُ مثلُها.
 *
 * فيُعرض للتاجر ولزبونه صورةُ شيءٍ لا صلة له بالمنتج: سيّارةٌ أو شاطئٌ أو
 * وجهُ إنسان مكان «لانيارد». ووقع على متجرٍ حقيقيّ.
 *
 * والمكتوبُ في العمود أسوأ من المحسوب: كان يُقرأ «له صورةٌ رفعها صاحبُه»
 * فيُنشر بها، وتُعدّ في سقف الصور، ويُرسم لها زرُّ حذفٍ لملفٍّ لا وجود له.
 * (والصورُ اليوم خارجَ تجربة المنتج كلِّها — وما رُفع قبلُ يبقى في عموده.)
 *
 * ═══ وما صار ═══
 *
 * الفراغُ يبقى فراغًا في العمود وفي المقروء. والشاشاتُ كلُّها كتبت حالتَها
 * الفارغة أصلًا — مربّعٌ رماديّ في القائمة و«📦» في نقطة البيع — ولم تقع
 * مرّةً لأنّ المقروء لم يكن فارغًا قطّ.
 *
 * والبديلُ المصنوع بقي للمتجر التجريبيّ وحده: يُكتب في بياناته عند بذره.
 */
class AProductWithoutAPhotoSaysSoTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->category = Category::create(['business_id' => $this->business->id, 'name' => 'ورد']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function add(array $over = []): Product
    {
        $this->actingAs($this->owner)
            ->post(route('admin.products.store'), $over + [
                'name' => 'لانيارد',
                'category_id' => $this->category->id,
                'price' => 5,
            ])
            ->assertSessionHasNoErrors();

        return Product::latest('id')->firstOrFail();
    }

    /* ==================== الإضافة ==================== */

    public function test_a_product_added_without_a_photo_keeps_its_column_empty(): void
    {
        $product = $this->add();

        $this->assertNull($product->getRawOriginal('image'), 'كُتبت في العمود صورةٌ لم يرفعها أحد');
    }

    public function test_and_reads_back_as_nothing_so_the_screen_shows_its_empty_state(): void
    {
        $this->assertSame('', $this->add()->image);
    }

    /**
     * والصورُ خرجت من نموذج المنتج — فما رُفع قبل ذلك يبقى في عموده ويُقرأ
     * رابطًا كما كان، وما يُرسل اليوم مع النموذج لا يُكتب.
     */
    public function test_a_photo_uploaded_before_is_kept_and_a_new_one_is_not_written(): void
    {
        $old = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة', 'price' => 5, 'cost' => 0, 'quantity' => 0,
            'image' => 'products/rose.jpg',
        ]);
        $this->assertStringContainsString('/storage/products/rose.jpg', $old->image);

        $product = $this->add(['image' => UploadedFile::fake()->image('rose.jpg')]);
        $this->assertNull($product->getRawOriginal('image'));
    }

    /* ==================== ولا يعود من بابٍ آخر ==================== */

    public function test_no_product_door_invents_a_photo(): void
    {
        /*
         * حارسُ مصدر: الاختراعُ يعود بسطرٍ واحد في أيّ بابٍ يُضيف منتجًا،
         * ولا يُكتشف إلّا حين يرى تاجرٌ صورةَ شيءٍ لا يبيعه.
         */
        foreach ([
            'app/Http/Controllers/Admin/ProductController.php',
            'app/Http/Controllers/Admin/ProductImportExportController.php',
        ] as $file) {
            $this->assertStringNotContainsString(
                'Demo::image',
                (string) file_get_contents(base_path($file)),
                "«{$file}» ما زال يخترع صورةً لمنتج",
            );
        }
    }
    /* ==================== وما كُتب قبل الإغلاق ==================== */

    public function test_the_migration_empties_a_photo_no_one_uploaded(): void
    {
        $invented = Product::create([
            'business_id' => $this->business->id, 'name' => 'لانيارد',
            'price' => 5, 'cost' => 0, 'quantity' => 0,
            'image' => 'https://picsum.photos/seed/prod6a96b636b9466/400/400',
        ]);
        $real = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة',
            'price' => 5, 'cost' => 0, 'quantity' => 0,
            'image' => 'products/rose.jpg',
        ]);

        (require base_path('database/migrations/2026_09_09_010000_a_product_without_a_photo_says_so.php'))->up();

        $this->assertNull($invented->fresh()->getRawOriginal('image'));
        // ‏ولا تمسّ صورةً رفعها التاجر
        $this->assertSame('products/rose.jpg', $real->fresh()->getRawOriginal('image'));
    }
}
