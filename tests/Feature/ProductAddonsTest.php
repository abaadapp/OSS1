<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\OrderCorrection;
use App\Support\ProductAddons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الإضافات — من إضافةٍ في المتجر إلى إضافةٍ على المنتج وعلى البند.
 *
 * وأهمّ ما يُحرس هنا التوافق: قبل هذا كانت كلّ إضافات المتجر تظهر مع كلّ
 * منتج، وتُباع بندًا مستقلًّا في السلّة. والاثنان يجب أن يظلّا يعملان — ومن
 * لم يربط شيئًا بعد لا يجوز أن تختفي إضافاته لحظة الترقية.
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
    private function allow(array $addonIds): void
    {
        $this->actingAs($this->owner)
            ->put(route('admin.products.addons.sync', $this->bouquet->id), ['addon_ids' => $addonIds])
            ->assertSessionHasNoErrors();
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
    public function test_a_shop_cannot_link_another_shops_addon(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'email' => 'o2@a.local', 'status' => 'نشط']);
        $theirs = Addon::create(['business_id' => $other->id, 'name' => 'بالونهم', 'price' => 2, 'active' => true]);

        $this->actingAs($this->owner)
            ->put(route('admin.products.addons.sync', $this->bouquet->id), ['addon_ids' => [$theirs->id]])
            ->assertSessionHasErrors('addon_ids.0');

        $this->assertDatabaseCount('product_addons', 0);
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
    /** وربطُها بمنتجٍ آخر يُردّ في بابه لا في نقطة البيع وحدها */
    public function test_another_products_addon_cannot_be_linked_here(): void
    {
        $chocolateBox = Product::create([
            'business_id' => $this->business->id, 'name' => 'علبة شوكولاتة',
            'price' => 6, 'cost' => 3, 'quantity' => 10, 'active' => true,
        ]);

        $ribbon = Addon::create([
            'business_id' => $this->business->id, 'product_id' => $chocolateBox->id,
            'name' => 'شريط ذهبي', 'price' => 0.5, 'active' => true,
        ]);

        $this->actingAs($this->owner)
            ->put(route('admin.products.addons.sync', $this->bouquet->id), ['addon_ids' => [$ribbon->id]])
            ->assertSessionHasErrors('addon_ids.0');

        $this->assertDatabaseCount('product_addons', 0);
    }

    /** والشاشة لا تعرض على منتجٍ إضافاتِ جيرانه أصلًا */
    public function test_the_product_screen_hides_another_products_addon(): void
    {
        $chocolateBox = Product::create([
            'business_id' => $this->business->id, 'name' => 'علبة شوكولاتة',
            'price' => 6, 'cost' => 3, 'quantity' => 10, 'active' => true,
        ]);

        Addon::create([
            'business_id' => $this->business->id, 'product_id' => $chocolateBox->id,
            'name' => 'شريط ذهبي', 'price' => 0.5, 'active' => true,
        ]);

        $mine = Addon::create([
            'business_id' => $this->business->id, 'product_id' => $this->bouquet->id,
            'name' => 'بطاقة معايدة', 'price' => 0.3, 'active' => true,
        ]);

        $names = collect(\App\Http\Controllers\Admin\ProductCompositionController::payload($this->bouquet)['addons'])
            ->pluck('label')->all();

        $this->assertContains('بطاقة معايدة', $names);
        $this->assertContains('تغليف فاخر', $names);
        $this->assertNotContains('شريط ذهبي', $names);

        $this->assertTrue(
            collect(\App\Http\Controllers\Admin\ProductCompositionController::payload($this->bouquet)['addons'])
                ->firstWhere('value', $mine->id)['private'],
        );
    }

    /** وإضافةٌ تُنشأ من شاشة المنتج تُولد خاصّةً به إن قيل ذلك */
    public function test_the_quick_form_can_create_a_private_addon(): void
    {
        $this->actingAs($this->owner)->postJson(route('admin.products.addons.store'), [
            'name' => 'شريط ذهبي', 'price' => 0.5, 'product_id' => $this->bouquet->id,
        ])->assertOk()->assertJsonPath('addon.private', true);

        $this->assertSame(
            $this->bouquet->id,
            (int) Addon::where('name', 'شريط ذهبي')->value('product_id'),
        );
    }

    /** واسمٌ واحد يجوز لمنتجين: «تغليف» لكلٍّ تغليفُه وسعرُه */
    public function test_the_same_private_name_is_free_on_another_product(): void
    {
        $chocolateBox = Product::create([
            'business_id' => $this->business->id, 'name' => 'علبة شوكولاتة',
            'price' => 6, 'cost' => 3, 'quantity' => 10, 'active' => true,
        ]);

        foreach ([$this->bouquet->id, $chocolateBox->id] as $id) {
            $this->actingAs($this->owner)->postJson(route('admin.products.addons.store'), [
                'name' => 'تغليف', 'price' => 1, 'product_id' => $id,
            ])->assertOk();
        }

        $this->assertSame(2, Addon::where('name', 'تغليف')->count());
    }

    /** ولا تُنسب إضافةٌ إلى منتج متجرٍ آخر */
    public function test_an_addon_cannot_be_owned_by_another_shops_product(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'email' => 'own@a.local', 'status' => 'نشط']);
        $theirs = Product::create([
            'business_id' => $other->id, 'name' => 'باقتهم', 'price' => 9, 'cost' => 3, 'quantity' => 5,
        ]);

        $this->actingAs($this->owner)->postJson(route('admin.products.addons.store'), [
            'name' => 'شريط', 'price' => 1, 'product_id' => $theirs->id,
        ])->assertStatus(422)->assertJsonValidationErrors('product_id');
    }
    /** وتُحذف من شاشتها */
    public function test_a_private_addon_can_be_deleted_from_its_product(): void
    {
        $ribbon = Addon::create([
            'business_id' => $this->business->id, 'product_id' => $this->bouquet->id,
            'name' => 'شريط ذهبي', 'price' => 0.5, 'active' => true,
        ]);

        $this->actingAs($this->owner)
            ->delete(route('admin.products.addons.destroy', [$this->bouquet->id, $ribbon->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Addon::where('id', $ribbon->id)->count());
    }

    /**
     * وإضافةُ المتجر لا تُحذف من شاشة منتجٍ واحد.
     *
     * تُعرض مع كلّ ما يبيعه المحلّ، وحذفُها من هنا يمسّ ما لا يراه من ضغط
     * الزرّ.
     */
    public function test_a_shop_wide_addon_cannot_be_deleted_from_a_product_screen(): void
    {
        $this->actingAs($this->owner)
            ->delete(route('admin.products.addons.destroy', [$this->bouquet->id, $this->wrapService->id]))
            ->assertNotFound();

        $this->assertSame(1, Addon::where('id', $this->wrapService->id)->count());
    }

    /** ولا إضافةُ منتجٍ آخر */
    public function test_another_products_addon_cannot_be_deleted_from_here(): void
    {
        $box = Product::create([
            'business_id' => $this->business->id, 'name' => 'علبة',
            'price' => 6, 'cost' => 3, 'quantity' => 4, 'active' => true,
        ]);
        $ribbon = Addon::create([
            'business_id' => $this->business->id, 'product_id' => $box->id,
            'name' => 'شريط', 'price' => 1, 'active' => true,
        ]);

        $this->actingAs($this->owner)
            ->delete(route('admin.products.addons.destroy', [$this->bouquet->id, $ribbon->id]))
            ->assertNotFound();

        $this->assertSame(1, Addon::where('id', $ribbon->id)->count());
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
