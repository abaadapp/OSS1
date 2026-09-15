<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\PaymentMethods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الموقع يقول ما في المحلّ — لا ما في الجرد.
 *
 * ═══ ثلاثةُ أكاذيبَ كانت تُقال، وكلُّها بحسن نيّة ═══
 *
 * ١) **صنفٌ أخفاه صاحبُه كان يُعرض.** عمودُ `published` أُضيف ليُخفي أوراقَ
 *    التغليف ومكوّناتِ الباقات وأصنافَ الجملة، وتقرؤه الصفحةُ البسيطة. أمّا
 *    الموقعُ المبنيّ — وهو الذي يُعرض على العنوان نفسه إن نُشر — فكان يقرأ
 *    `active` وحدها. فمن أخفى صنفًا رآه في موقعه ولم يفهم لماذا لم يُطعه
 *    المقبض.
 *
 * ٢) **صنفٌ نفد كان يُعرض بزرّ «اطلب».** وذلك الزرّ ادّعاءُ توفّر: الزبون
 *    يكتب رسالةً فيُجاب «انتهى» — خذلانٌ يقع بعد أن قرّر الشراء.
 *
 * ٣) **«البطاقة مفعّلة»** كانت تُقرأ من إعدادات نقطة البيع، وهي تصف ما يأخذه
 *    الكاشير على المنضدة. ولا بوّابةَ دفعٍ في افنت بلس، فالموقع لا يقبض شيئًا.
 *
 * وهذا الملفّ يحرس الثلاثة. وكلُّها تنكسر صامتةً: الموقعُ يُرسم، والشاشةُ
 * خضراء، ولا يكتشفها إلّا زبونٌ خُذل — وهو لا يعود ليُخبر.
 */
class TheSiteShowsWhatTheShopActuallyHasTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط', 'phone' => '96890000000',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function product(array $attrs = []): Product
    {
        return Product::create($attrs + [
            'business_id' => $this->business->id,
            'name' => 'باقة ورد',
            'price' => 12.5,
            'active' => true,
            'published' => true,
            'quantity' => 5,
        ]);
    }
    /* ==================== ١ · ما أخفاه صاحبُه لا يُعرض ==================== */

    public function test_a_product_the_owner_hid_is_not_on_the_site(): void
    {
        $this->product(['name' => 'باقة معروضة']);
        $this->product(['name' => 'ورق تغليف', 'published' => false]);

        $this->assertSame(['باقة معروضة'], $this->onSite());
    }

    /** والمطفأُ في نقطة البيع لا يُعرض كذلك — وهما شرطان لا شرط */
    public function test_a_product_switched_off_at_the_counter_is_not_on_the_site(): void
    {
        $this->product(['name' => 'باقة معروضة']);
        $this->product(['name' => 'صنف موقوف', 'active' => false]);

        $this->assertSame(['باقة معروضة'], $this->onSite());
    }

    /* ==================== ٢ · وما نفد لا يُعرض ==================== */

    public function test_a_sold_out_product_leaves_the_site(): void
    {
        $this->product(['name' => 'باقة متوفّرة']);
        $this->product(['name' => 'باقة نفدت', 'quantity' => 0]);

        $this->assertSame(['باقة متوفّرة'], $this->onSite());
    }

    /**
     * وذو الوصفة لا يُحكم عليه بكمّيته.
     *
     * مخزونُه مكوّناتُه، وكمّيتُه هو تبقى صفرًا أبدًا. والحكمُ عليها كان
     * سيُفرغ موقعَ محلّ الورود من الباقات كلِّها — وهي بضاعتُه.
     */
    public function test_a_recipe_product_is_not_judged_by_its_own_quantity(): void
    {
        $rose = $this->product(['name' => 'وردة', 'quantity' => 100]);
        $bouquet = $this->product(['name' => 'باقة مركّبة', 'quantity' => 0]);

        RecipeItem::create([
            'business_id' => $this->business->id,
            'product_id' => $bouquet->id,
            'component_product_id' => $rose->id,
            'quantity' => 12,
        ]);

        $this->assertContains('باقة مركّبة', $this->onSite());
    }

    /** ومتجرٌ يأذن بالبيع تحت الصفر لا نفادَ عنده — إعدادُ نقطة البيع نفسُه */
    public function test_a_shop_that_sells_below_zero_hides_nothing(): void
    {
        Setting::create([
            'business_id' => $this->business->id,
            'key' => 'allow_negative_stock', 'value' => '1',
        ]);

        $this->product(['name' => 'باقة نفدت', 'quantity' => 0]);

        $this->assertSame(['باقة نفدت'], $this->onSite());
    }
    /** ولا يُقرأ جردُ الجار: كلُّ استعلامٍ مقيَّدٌ بمتجره */
    public function test_a_neighbour_shelf_is_never_read(): void
    {
        $this->product(['name' => 'باقتي']);

        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Product::create([
            'business_id' => $other->id, 'name' => 'باقة الجار',
            'price' => 9, 'active' => true, 'published' => true, 'quantity' => 9,
        ]);

        $this->assertSame(['باقتي'], $this->onSite());
    }

    /* ==================== ٣ · والدفع يُقال بحقيقته ==================== */
}
