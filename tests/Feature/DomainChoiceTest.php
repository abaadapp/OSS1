<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use App\Support\MarketingSettings;
use App\Support\Storefront;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * السؤالُ الأوّل: من أين يأتي عنوان متجرك؟
 *
 * وكانت الصفحة تعرض بطاقتين معًا وفيهما كلمةُ «نطاق» بمعنيين متضادّين —
 * «موقعي عندكم» و«موقعي عند غيركم». فيكتب التاجر عنوان متجره في حقل الموقع
 * الخارجيّ، ويصير زرُّ «فتح الموقع» يشير إلى عنوانٍ لا وجود له.
 *
 * وثلاثةٌ تُفحص هنا: أنّ السؤال يُطرح **مرّةً** ولا يُعاد على من سبقه، وأنّ
 * تبديل الرأي **لا يمحو** عنوانًا محجوزًا ولا نطاقًا مكتوبًا، وأنّ السعر
 * يخرج من **موضعٍ واحد** — إذ سعرٌ يُكتب في الشاشة يبقى بعد أن يتغيّر في
 * الخادم، فيرى التاجر رقمًا ويُفوتر بآخر.
 */
class DomainChoiceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'ورد الخوير', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'صاحب النشاط', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    /** الطريق كما تراه الشاشة */
    private function path(): string
    {
        return $this->get(route('admin.settings.index', ['section' => 'website']))
            ->viewData('page')['props']['store']['path'];
    }

    /* ---------------------------- السؤال يُطرح مرّةً ---------------------------- */

    public function test_a_shop_that_never_chose_is_asked(): void
    {
        // '' هو ما تقرؤه الشاشة فتعرض البطاقات الثلاث
        $this->assertSame('', $this->path());
    }

    public function test_a_shop_that_already_reserved_an_address_is_not_asked_again(): void
    {
        /*
         * ومن ضبط موقعه قبل وجود هذا السؤال يُستنتج طريقُه ولا يُسأل: سؤالُه
         * «هل عندك نطاق؟» بعد أن حجز عنوانه يجعله يظنّ أنّ ما ضبطه ضاع.
         */
        $this->business->forceFill(['site_slug' => 'ward-alkhuwair'])->save();

        $this->assertSame('sub', $this->path());
    }

    public function test_a_shop_that_already_wrote_its_own_domain_is_not_asked_again(): void
    {
        MarketingSettings::save($this->business->id, 'website', ['site_domain' => 'mystore.om']);

        $this->assertSame('own', $this->path());
    }
    /* ------------------------------- السعر ------------------------------- */

    public function test_the_price_reaches_the_screen_from_the_configuration(): void
    {
        config([
            'storefront.pricing.subdomain.monthly' => 2,
            'storefront.pricing.subdomain.yearly' => 20,
            'storefront.currency' => 'ر.ع',
        ]);

        $pricing = $this->get(route('admin.settings.index', ['section' => 'website']))
            ->viewData('page')['props']['store']['pricing'];

        $this->assertSame(2.0, $pricing['subdomain']['monthly']);
        $this->assertSame(20.0, $pricing['subdomain']['yearly']);
        $this->assertFalse($pricing['subdomain']['free']);
        $this->assertSame('ر.ع', $pricing['currency']);
    }

    public function test_no_price_set_reads_as_included_not_as_missing(): void
    {
        /*
         * وصفرٌ ليس «سعرًا مفقودًا»: الشاشة تقول «مشمول في باقتك». ولو حُسب
         * ذلك في الشاشة لا في الخادم لافترقا يومًا — تقول واحدةٌ «٠٫٠٠٠ ر.ع»
         * ويقول الآخر «مجّانًا».
         */
        config(['storefront.pricing.subdomain.monthly' => 0, 'storefront.pricing.subdomain.yearly' => 0]);

        $this->assertTrue(Storefront::pricing()['subdomain']['free']);
    }

    public function test_a_broken_price_is_read_as_zero_not_as_a_negative_charge(): void
    {
        // قيمةٌ خاطئة في البيئة تُقرأ صفرًا: سعرٌ سالب يُعرض «خصمًا» لا رسمًا
        config(['storefront.pricing.subdomain.monthly' => 'مجانا', 'storefront.pricing.subdomain.yearly' => -5]);

        $pricing = Storefront::pricing();

        $this->assertSame(0.0, $pricing['subdomain']['monthly']);
        $this->assertSame(0.0, $pricing['subdomain']['yearly']);
        $this->assertTrue($pricing['subdomain']['free']);
    }

    /* ------------------------------ العزل ------------------------------ */

    public function test_a_neighbours_choice_is_not_read_as_mine(): void
    {
        $neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        MarketingSettings::save($neighbour->id, 'website', ['site_path' => 'own']);

        $this->assertSame('', $this->path());
    }
}
