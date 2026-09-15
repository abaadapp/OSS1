<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المستند العامّ — ما يقرؤه العارض في نطاق التاجر.
 *
 * وهو عقدٌ بين طرفين: افنت بلسُ تكتبه وتطبيقُ `storefront/` يقرؤه. فما
 * يُكسر هنا لا يظهر في اختبارات افنت بلس بل في موقع تاجرٍ يعمل — صورةٌ لا
 * تُحمَّل، أو سعرٌ بلا عملة، أو شعارٌ غائب.
 *
 * وثلاثةٌ يحرسها هذا الملفّ خاصّةً:
 *
 * ١) **الروابط مطلقة.** رابطٌ نسبيّ يُقرأ في نطاق التاجر لا في نطاق افنت بلس،
 *    فيطلب صورةً من متجره هو ولا يجدها.
 * ٢) **الهويّة تُقرأ لا تُجمَّد.** من بدّل شعاره لا يخطر له أن ينشر موقعه
 *    من جديد ليظهر التبديل.
 * ٣) **النصوص عربيّة مهما كانت لغة الطلب.** زائرُ متجرٍ ليس مستخدمًا في
 *    افنت بلس ولا لغةَ له تُقرأ منها، والمستند يقول `locale: ar`.
 */
class PublicDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط',
            'phone' => '96890000000', 'email' => 'shop@eventplus.om', 'city' => 'مسقط',
            'address' => 'الخوير، شارع ١٨', 'logo' => 'logos/wrood.png',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }
    public function test_the_document_carries_brand_currency_and_direction(): void
    {
        $this->seedShop();
        $doc = $this->document();

        $this->assertSame('ورود مسقط', $doc['brand']['name']);
        $this->assertSame('ورودٌ طازجة كلّ صباح', $doc['brand']['tagline']);
        $this->assertSame('96890000000', $doc['brand']['phone']);
        $this->assertSame('الخوير، شارع ١٨، مسقط', $doc['brand']['address']);
        $this->assertSame('OMR', $doc['currency']['code']);
        $this->assertSame(3, $doc['currency']['decimals']);
        $this->assertSame('ar', $doc['locale']);
        $this->assertSame('rtl', $doc['dir']);
    }
    public function test_a_product_without_a_real_photo_reports_no_photo(): void
    {
        $this->seedShop();

        // ما يكتبه إنشاء المنتج حين لا يرفع التاجر صورة
        Product::where('business_id', $this->business->id)->first()
            ->update(['image' => 'https://picsum.photos/seed/prod9/400/400']);

        $doc = $this->document();
        $images = [];

        foreach ($doc['data']['products'] as $product) {
            $images[] = $product['image'];
        }

        $this->assertContains(null, $images, 'صورةٌ وهميّة خرجت إلى موقع تاجر');
    }

    public function test_the_document_speaks_the_shops_language_not_the_readers(): void
    {
        $this->seedShop();

        // زائرٌ لا حساب له: لغةُ التطبيق الافتراضية إنجليزية (APP_LOCALE)
        app()->setLocale('en');

        $doc = $this->document();

        $this->assertSame('ar', $doc['locale']);
        $this->assertSame('rtl', $doc['dir']);
        $this->assertSame('إنستغرام', $doc['brand']['social'][0]['label']);
        $this->assertContains('نقدي', $doc['brand']['payments']);
    }

    public function test_a_shop_that_chose_english_gets_an_english_document(): void
    {
        $this->seedShop();
        Setting::create(['business_id' => $this->business->id, 'key' => 'locale', 'value' => 'en']);

        app()->setLocale('ar');
        $doc = $this->document();

        $this->assertSame('en', $doc['locale']);
        $this->assertSame('ltr', $doc['dir']);
        $this->assertSame('Instagram', $doc['brand']['social'][0]['label']);
        $this->assertContains('Cash', $doc['brand']['payments']);
    }

    public function test_social_accounts_reach_the_footer_from_where_they_were_written(): void
    {
        $this->seedShop();
        $doc = $this->document();

        $this->assertSame('instagram', $doc['brand']['social'][0]['network']);
        $this->assertSame('https://instagram.com/wroodmuscat', $doc['brand']['social'][0]['url']);
    }

    public function test_payment_methods_come_from_the_point_of_sale_not_a_second_setting(): void
    {
        $this->seedShop();
        Setting::create(['business_id' => $this->business->id, 'key' => 'pay_transfer', 'value' => '0']);

        $doc = $this->document();

        $this->assertNotContains('تحويل بنكي', $doc['brand']['payments']);
        $this->assertContains('نقدي', $doc['brand']['payments']);
    }
}
