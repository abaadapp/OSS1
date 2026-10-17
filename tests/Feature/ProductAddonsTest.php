<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\ProductAddons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الإضافات — من إضافةٍ في المتجر إلى إضافةٍ على المنتج وعلى البند.
 *
 * ولم يعد لها بابُ كتابةٍ في شاشة المنتج: خرجت مع «التركيب» من بقايا المتجر.
 * وما بقي يُحرس هنا: قراءتُها كما كُتبت (`ProductAddons`) لما تقرؤه المبيعاتُ
 * المتقاعدة وتصحيحُ فواتيرها — ومن لم يربط شيئًا لا تختفي إضافاتُه.
 */
class ProductAddonsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private User $cashier;
    private Product $bouquet;
    private Product $chocolateStock;
    private Addon $chocolate;
    private Addon $wrapService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل الإضافات', 'email' => 'a@test.local', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك', 'email' => 'owner@a.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@a.local',
            'password' => bcrypt('secret'), 'role' => 'sales', 'status' => 'نشط',
        ]);

        $this->bouquet = Product::create([
            'business_id' => $this->business->id, 'name' => 'بوكيه',
            'price' => 18, 'cost' => 6, 'quantity' => 50, 'active' => true,
        ]);

        $this->chocolateStock = Product::create([
            'business_id' => $this->business->id, 'name' => 'شوكولاتة (مخزون)',
            'price' => 4, 'cost' => 1.5, 'quantity' => 30, 'active' => true,
        ]);

        $this->chocolate = Addon::create([
            'business_id' => $this->business->id, 'name' => 'شوكولاتة', 'price' => 4,
            'active' => true, 'inventory_product_id' => $this->chocolateStock->id,
        ]);

        $this->wrapService = Addon::create([
            'business_id' => $this->business->id, 'name' => 'تغليف فاخر', 'price' => 1, 'active' => true,
        ]);

    }
    /**
     * قائمةُ المنتج كما كتبها بابُ «التركيب» قبل أن يُنزع من شاشة المنتج —
     * صفوفٌ في `product_addons` تقرؤها المبيعاتُ المتقاعدة كما هي.
     */
    private function allow(array $addonIds): void
    {
        foreach (array_values($addonIds) as $i => $id) {
            DB::table('product_addons')->insert([
                'business_id' => $this->business->id, 'product_id' => $this->bouquet->id,
                'addon_id' => $id, 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
    public function test_a_product_with_no_links_still_offers_every_shop_addon(): void
    {
        $allowed = ProductAddons::for($this->bouquet);

        $this->assertCount(2, $allowed);
    }

    public function test_linking_some_addons_narrows_the_list_to_those(): void
    {
        $this->allow([$this->chocolate->id]);

        $allowed = ProductAddons::for($this->bouquet->fresh());

        $this->assertCount(1, $allowed);
        $this->assertSame($this->chocolate->id, $allowed->first()->id);
    }

    public function test_a_switched_off_addon_is_never_offered(): void
    {
        $this->wrapService->update(['active' => false]);

        $this->assertCount(1, ProductAddons::for($this->bouquet));
    }
    /* ==================== الإضافة الخاصّة بمنتجٍ واحد ==================== */

    /**
     * «شريط ذهبي» صُنع لباقة الورد فلا يُعرض مع كيس السماد.
     *
     * والربط وحده لم يكن يكفي: غياب الربط عن كيس السماد معناه «كلّ إضافات
     * المتجر»، فكلّ إضافةٍ جديدةٍ كانت تنهال على كلّ منتجٍ لم يُربط له شيء —
     * وهم أكثر المنتجات.
     */
    public function test_a_private_addon_is_offered_only_with_its_own_product(): void
    {
        $ribbon = Addon::create([
            'business_id' => $this->business->id, 'product_id' => $this->bouquet->id,
            'name' => 'شريط ذهبي', 'price' => 0.5, 'active' => true,
        ]);

        $fertiliser = Product::create([
            'business_id' => $this->business->id, 'name' => 'كيس سماد',
            'price' => 2, 'cost' => 1, 'quantity' => 20, 'active' => true,
        ]);

        $this->assertTrue(ProductAddons::for($this->bouquet)->contains('id', $ribbon->id));
        $this->assertFalse(ProductAddons::for($fertiliser)->contains('id', $ribbon->id));

        // وإضافةُ المتجر تبقى للجميع كما كانت
        $this->assertTrue(ProductAddons::for($fertiliser)->contains('id', $this->wrapService->id));
    }

    /**
     * شاشةُ البيع لا تعرض على منتجٍ إضافةَ جاره.
     *
     * كانت تُرسل خريطة الربط الخام، وغيابُ الربط يُقرأ في الشاشة «كلّ
     * إضافات المتجر». وإضافةُ المنتج الخاصّة لا صفَّ ربطٍ لها، فكانت
     * تُعرض مع كلّ منتج — عكسُ معناها. والخادم يردّها عند الدفع، لكنّ
     * الكاشير يكون قد عرضها على الزبون.
     */
    public function test_the_pos_screen_offers_a_private_addon_only_with_its_product(): void
    {
        $ribbon = Addon::create([
            'business_id' => $this->business->id, 'product_id' => $this->bouquet->id,
            'name' => 'شريط ذهبي', 'price' => 0.5, 'active' => true,
        ]);

        $fertiliser = Product::create([
            'business_id' => $this->business->id, 'name' => 'كيس سماد',
            'price' => 2, 'cost' => 1, 'quantity' => 20, 'active' => true,
        ]);

        $this->actingAs($this->owner);
        $rows = collect(\App\Support\Demo::products())->keyBy('id');

        $this->assertContains($ribbon->id, $rows[$this->bouquet->id]['addon_ids']);
        $this->assertNotContains($ribbon->id, $rows[$fertiliser->id]['addon_ids']);

        // وإضافةُ المتجر تبقى معروضةً مع الاثنين
        $this->assertContains($this->wrapService->id, $rows[$this->bouquet->id]['addon_ids']);
        $this->assertContains($this->wrapService->id, $rows[$fertiliser->id]['addon_ids']);
    }

    /** ومنتجٌ لم يُربط له شيء يبقى يعرض إضافات المتجر كلّها */
    public function test_an_unlinked_product_still_offers_every_shop_addon(): void
    {
        $this->actingAs($this->owner);
        $ids = collect(\App\Support\Demo::products())->firstWhere('id', $this->bouquet->id)['addon_ids'];

        $this->assertEqualsCanonicalizing([$this->chocolate->id, $this->wrapService->id], $ids);
    }
}
