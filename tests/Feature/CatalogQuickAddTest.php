<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تصنيفٌ يُنشأ من حيث يُحتاج — من جانب حقله في نموذج المنتج.
 *
 * ولم يكن له بابُ إنشاءٍ في النظام إطلاقًا: من تهيئة نوع النشاط أو من
 * استيراد ملفّ. فمن أراد تصنيفًا جديدًا وهو يُدخل بندًا لم يكن أمامه إلّا أن
 * يتركه بلا تصنيف. (وبابُ «الإضافات» بجانبه خرج مع تركيب المنتج.)
 */
class CatalogQuickAddTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'email' => 'q@test.local', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك', 'email' => 'owner@q.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'محاسب', 'email' => 'c@q.local',
            'password' => bcrypt('secret'), 'role' => 'accountant', 'status' => 'نشط',
        ]);
    }

    /* ------------------------------ الأقسام ------------------------------ */

    public function test_a_category_can_be_created_from_the_product_form(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('admin.products.categories.store'), ['name' => 'باقات'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('category.name', 'باقات');

        $this->assertDatabaseHas('categories', [
            'business_id' => $this->business->id, 'name' => 'باقات',
        ]);
    }

    public function test_the_new_category_comes_back_with_its_id_so_the_field_can_select_it(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson(route('admin.products.categories.store'), ['name' => 'ورود'])
            ->assertOk();

        $this->assertSame(
            Category::where('business_id', $this->business->id)->value('id'),
            $response->json('category.id'),
        );
    }

    public function test_two_categories_cannot_share_a_name_in_one_shop(): void
    {
        Category::create(['business_id' => $this->business->id, 'name' => 'ورود']);

        $this->actingAs($this->owner)
            ->postJson(route('admin.products.categories.store'), ['name' => 'ورود'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(1, Category::count());
    }

    public function test_the_same_name_is_free_in_another_shop(): void
    {
        // التفرّد على مستوى النشاط لا النظام — «ورود» عند غيرك ليست عندك
        $other = Business::create(['name' => 'محل آخر', 'email' => 'o@q.local', 'status' => 'نشط']);
        Category::create(['business_id' => $other->id, 'name' => 'ورود']);

        $this->actingAs($this->owner)
            ->postJson(route('admin.products.categories.store'), ['name' => 'ورود'])
            ->assertOk();

        $this->assertSame(2, Category::count());
    }

    public function test_the_category_belongs_to_the_signed_in_shop_not_to_a_posted_id(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'email' => 'o2@q.local', 'status' => 'نشط']);

        $this->actingAs($this->owner)
            ->postJson(route('admin.products.categories.store'), [
                'name' => 'باقات', 'business_id' => $other->id,
            ])->assertOk();

        $this->assertSame(
            $this->business->id,
            (int) Category::where('name', 'باقات')->value('business_id'),
        );
    }

    /* ------------------------------ الإضافات ----------------------------- */

    /* ------------------------------ الحراسة ------------------------------ */

    public function test_a_cashier_cannot_create_categories(): void
    {
        // الصلاحية نفسها التي تحرس شاشة المنتجات — لا صلاحيةَ جديدة
        $this->actingAs($this->cashier)
            ->postJson(route('admin.products.categories.store'), ['name' => 'باقات'])
            ->assertForbidden();

        $this->assertSame(0, Category::count());
    }

    public function test_a_guest_is_turned_away(): void
    {
        $this->postJson(route('admin.products.categories.store'), ['name' => 'باقات'])
            ->assertUnauthorized();
    }
}
