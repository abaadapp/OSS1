<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * الظهور في البحث وربط Google Analytics.
 *
 * وأخطرُ ما يحرسه هذا الملفّ أنّ **«مربوط» تُقاس بالفحص لا باللصق**: لو قيست
 * بأنّ التاجر كتب معرّفًا في حقلٍ لَقالت الشاشة «مربوط» لمن نسي أن يلصق
 * الوسم في موقعه — فينتظر أرقامًا لا تأتي أبدًا ولا يعرف لماذا، والشاشةُ هي
 * التي كذبت عليه.
 *
 * ويحرس معه أنّ الشاشة **لا تعِد بما لا تفعل**: الموقع خارج النظام، فلا حقلَ
 * فيها لعنوان الصفحة ولا وصفها — وهي الحقول التي حُذفت من الإعدادات لأنّها
 * تُملأ ولا تصل صفحةً يقرؤها محرّك.
 */
class SeoAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const ID = 'G-ABC1234567';

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Cache::clear();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);

        $this->fakeSite();

        Http::fake(function ($request) {
            $url = (string) $request->url();

            if (str_contains($url, 'robots.txt')) {
                return Http::response('User-agent: *', 200);
            }

            if (str_contains($url, 'sitemap.xml')) {
                return Http::response('<urlset/>', 200);
            }

            return Http::response($this->body, $this->status);
        });
    }
    /*
     * والردُّ يُبدَّل بمتغيّرٍ لا بتسجيلٍ ثانٍ.
     *
     * `Http::fake` تُراكم التسجيلات ويغلب أوّلُ ما يطابق، فتسجيلٌ ثانٍ في
     * منتصف الاختبار لا يُبدّل شيئًا — والاختبار يمرّ أو يسقط لغير سببه.
     */
    private string $body = '';

    private int $status = 200;

    private function fakeSite(array $with = [], int $status = 200): void
    {
        $this->body = $this->page($with);
        $this->status = $status;
    }
    /** بندٌ بمفتاحه من نتيجة الفحص */
    private function item(array $audit, string $key): array
    {
        return collect($audit['checks'])->firstWhere('key', $key) ?? [];
    }
    /* ==================== «مربوط» تُقاس بالفحص ==================== */

    public function test_a_saved_id_alone_is_not_a_connection(): void
    {
        /*
         * هذا هو الحارس الأوّل: الحقلُ مملوءٌ والموقعُ يفتح — ولا وسمَ فيه.
         * ولو قيست الحالة باللصق لَقالت «مربوط» ولانتظر أرقامًا لا تأتي.
         */
        $this->domain();
        $this->tag();
        $this->fakeSite();

        $audit = $this->check();

        $this->assertFalse($audit['site']['tagged']);
        $this->assertSame('fail', $this->item($audit, 'analytics')['state']);
        $this->assertNotNull($this->item($audit, 'analytics')['fix']);
    }

    public function test_the_tag_seen_on_the_page_is_the_connection(): void
    {
        $this->domain();
        $this->tag();
        $this->fakeSite(['tag' => self::ID]);

        $audit = $this->check();

        $this->assertTrue($audit['site']['tagged']);
        $this->assertSame('pass', $this->item($audit, 'analytics')['state']);
    }

    public function test_another_shops_tag_on_the_page_is_not_a_connection(): void
    {
        // وسمُ من بنى الموقع أو وسمُ متجرٍ آخر ليس وسمَك
        $this->domain();
        $this->tag();
        $this->fakeSite(['tag' => 'G-ZZZ9999999']);

        $this->assertFalse($this->check()['site']['tagged']);
    }

    /* ========================= بنود الظهور ========================= */

    public function test_noindex_is_named_because_nothing_else_shows_it(): void
    {
        /*
         * سطرٌ واحد يبقى من يوم التجربة فلا تظهر الصفحة في البحث أبدًا —
         * والتاجر يرى موقعه يفتح فيظنّه سليمًا.
         */
        $this->domain();
        $this->fakeSite(['robots' => 'noindex, nofollow']);

        $this->assertSame('fail', $this->item($this->check(), 'noindex')['state']);
    }

    public function test_a_page_that_allows_indexing_passes(): void
    {
        $this->domain();
        $this->fakeSite();

        $this->assertSame('pass', $this->item($this->check(), 'noindex')['state']);
    }
    /**
     * ووصفٌ قصيرٌ يُحذَّر منه — لا يُجاز بعلامةٍ خضراء.
     *
     * ═══ وكيف وُجد هذا ═══
     *
     * الباني يكتب للمتجر وصفًا افتراضيًّا: «تسوّق من متجري.» — خمسةَ عشرَ
     * حرفًا. وكان الفحصُ يُجيزه، فيقرأ صاحبُ المحلّ «وصف الصفحة ✓» ولا
     * يكتب وصفًا قطّ. وGoogle تطرح الوصفَ القصير وتختار جملةً من صفحته هي.
     *
     * فنظامٌ يكتب حشوًا ثمّ يشهد له بالسلامة أسوأ من نظامٍ لا يكتب شيئًا.
     */
    public function test_a_too_short_description_is_not_passed(): void
    {
        $this->domain();
        $this->fakeSite(['description' => 'تسوّق من متجري.']);

        $item = $this->item($this->check(), 'description');

        $this->assertSame('warn', $item['state'], 'أُجيز وصفٌ من خمسةَ عشرَ حرفًا');
        $this->assertNotNull($item['fix'], 'حُذّر بلا قولِ ما يُفعل');
    }
    /** ووصفٌ في المدى يمرّ — فالحارسُ يميّز ولا يحذّر من كلّ شيء */
    public function test_a_good_description_still_passes(): void
    {
        $this->domain();
        $this->fakeSite();

        $item = $this->item($this->check(), 'description');

        $this->assertSame('pass', $item['state']);
        $this->assertNull($item['fix'], 'نصيحةٌ تحت بندٍ سليم ضجيج');
    }

    public function test_a_missing_viewport_is_named_because_most_customers_use_a_phone(): void
    {
        $this->domain();
        $this->fakeSite(['viewport' => false]);

        $this->assertSame('fail', $this->item($this->check(), 'viewport')['state']);
    }

    public function test_a_passing_check_carries_no_advice(): void
    {
        // نصيحةٌ تحت بندٍ سليم ضجيجٌ يُخفي البند الذي يحتاج عملًا
        $this->domain();
        $this->fakeSite();

        foreach ($this->check()['checks'] as $row) {
            if ($row['state'] === 'pass') {
                $this->assertNull($row['fix'], "بندٌ سليم «{$row['key']}» يحمل نصيحة");
            }
        }
    }

    /* ========================== المواقف ========================== */

    public function test_a_shop_without_a_domain_is_told_so_not_shown_a_clean_report(): void
    {
        $this->tag();

        $this->assertSame('nodomain', $this->check()['state']);
    }

    public function test_a_site_that_does_not_open_is_told_so(): void
    {
        $this->domain();
        $this->fakeSite(status: 500);

        $audit = $this->check();

        $this->assertSame('unreachable', $audit['state']);
        $this->assertNotNull($audit['error']);
    }

    public function test_a_failure_is_not_kept_in_memory(): void
    {
        /*
         * موقعٌ أُصلح يعمل في اللحظة، فحفظُ العطل نصفَ ساعةٍ يجعل التاجر
         * يُصلح ثمّ يرى العطلَ نفسه فيظنّ أنّه لم يُصلح.
         */
        $this->domain();
        $this->fakeSite(status: 500);
        $this->assertSame('unreachable', $this->check()['state']);

        $this->fakeSite();
        $this->assertSame('ok', $this->check()['state']);
    }

    public function test_a_second_read_does_not_open_the_site_again(): void
    {
        // الفحصُ يفتح موقع التاجر من خادمنا — وفتحُ الشاشة ليس سببًا لطلبٍ جديد
        $this->domain();
        $this->fakeSite();

        $this->check();
        $sent = count(Http::recorded());
        $this->check();

        $this->assertCount($sent, Http::recorded());
    }
}
