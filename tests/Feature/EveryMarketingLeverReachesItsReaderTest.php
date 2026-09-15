<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * كلُّ مقبضٍ في أدوات التسويق يصل إلى من يقرؤه.
 *
 * وهذا الملفّ عن عطبٍ من نوعٍ واحد وقع مرّتين في أداتين: **مقبضٌ يُرسم من
 * قائمةٍ، وتُقرأ قيمتُه من قائمةٍ أخرى**. فما دامت القائمتان متطابقتين لا
 * يظهر شيء؛ ويوم يُضاف بندٌ إلى إحداهما وحدها يصير على الشاشة مقبضٌ كامل
 * الهيئة لا يُدير شيئًا — يُضغط، ويُحفظ النموذج، ويُقال «حُفظت إعداداتك».
 *
 * ١) **إشعارات واتساب.** الشاشة ترسم المقابض من `WhatsAppEvent::ALL` —
 *    ستّة. وتُقرأ قيمُها وتُحفظ من `MarketingSettings::GROUPS['whatsapp']` —
 *    أربعة. فحدثا تذكير الفاتورة (قبل الاستحقاق وبعده) مقبضان مرسومان بلا
 *    مفتاح: يعودان مطفأين مهما ضُغطا. وأبعدُ من ذلك أنّ **المُرسِل يسأل
 *    المجموعة نفسها** — فتذكيرُ السداد لم يكن يخرج لمتجرٍ واحد قطّ، ولو
 *    كُتب الصفُّ في القاعدة بيد.
 *
 * ٢) **الظهور في البحث.** معرّفُ القياس يُحفظ، ويُبنى منه وسمٌ **يُعطى
 *    للتاجر ليلصقه في موقعه**. وكان ذلك صحيحًا يوم كان موقعُه دائمًا عند
 *    غيرنا؛ ثمّ صار لافنت بلس بانِي مواقع، وصارت الصفحةُ صفحتَنا — و`<head>`
 *    نكتبه نحن. فلا هو يستطيع اللصق، ولا نحن كنّا نضع الوسم: المعرّفُ
 *    يُحفظ ولا يخرج في صفحةٍ واحدة أبدًا، وصاحبُه ينتظر أرقامًا لا تأتي.
 *
 * والحارسُ الذي كان قائمًا يقيس اتّجاهًا واحدًا («كلُّ مفتاحٍ معروضٍ يقرؤه
 * المُرسِل») — والنقصُ كان في الاتّجاه الآخر. فصار يُقاس الاتّجاهان.
 */
class EveryMarketingLeverReachesItsReaderTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create([
            'name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط',
            'site_slug' => 'wrood', 'phone' => '96890000000', 'city' => 'مسقط',
        ]);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /* ==================== واتساب: ستّةُ أحداثٍ وستّةُ مفاتيح ==================== */
    /* ==================== السيو: الوسمُ يخرج حيث نملك الرأس ==================== */
    /**
     * ولا يُقاس عليه ما ليس بيده.
     *
     * `robots.txt` و`sitemap.xml` يسكنان جذرَ المضيف — وعنوانُ متجرٍ على
     * مسارٍ ليس جذرًا، فالسؤالُ يقيس جذرَ افنت بلس. وما ينقص منهما ينقص منّا
     * لا منه: «اطلبها ممّن بنى موقعك» يقرؤها من بنى موقعه عندنا فيبحث عن
     * جهةٍ لا وجود لها.
     */
    public function test_the_audit_asks_him_nothing_that_is_not_his_to_answer(): void
    {
        $this->publishSite();
        $this->saveMeasurementId('G-ABC12345');

        $checks = $this->auditWith('<html><head><title>ورود مسقط</title>'
            .'<script src="https://www.googletagmanager.com/gtag/js?id=G-ABC12345"></script>'
            .'</head><body></body></html>');

        $keys = array_column($checks, 'key');

        $this->assertNotContains('robots', $keys);
        $this->assertNotContains('sitemap', $keys);

        $analytics = $this->itemAt($checks, 'analytics');
        $this->assertSame('pass', $analytics['state']);
        $this->assertNull($analytics['fix']);
    }

    /**
     * ولو غاب الوسمُ عن الفحص لا يُطلب منه لصقُه.
     *
     * صفحتُه عندنا والوسمُ يخرج فيها لحظةَ الحفظ؛ فغيابُه عن قراءةٍ محفوظةٍ
     * من قبله خبرٌ عن الفحص لا نقصٌ يُصلحه بيده. و«الصقه داخل `<head>`»
     * تُرسله يبحث عن بابٍ لا يملكه، ثمّ يظنّ العطبَ في نفسه.
     */
    public function test_a_missing_tag_is_never_blamed_on_a_head_he_cannot_open(): void
    {
        $this->publishSite();
        $this->saveMeasurementId('G-ABC12345');

        // صفحةٌ ردّت بلا وسم — كأنّ الفحص محفوظٌ من قبل الحفظ
        $checks = $this->auditWith('<html><head><title>ورود مسقط</title></head><body></body></html>');

        $analytics = $this->itemAt($checks, 'analytics');

        $this->assertSame('fail', $analytics['state']);
        $this->assertStringNotContainsString('<head>', (string) $analytics['fix']);
        $this->assertStringContainsString('افحص الآن', (string) $analytics['fix']);
    }
    /** وما يُصلح يُقال حيث يُصلح — لا في رأسٍ لا يفتحه */
    public function test_what_is_missing_is_pointed_at_the_screen_that_fixes_it(): void
    {
        $this->publishSite();

        // صفحةٌ بلا عنوانٍ ولا وصف — وهما في «الموقع الإلكتروني ‹ الظهور في البحث»
        $checks = $this->auditWith('<html><head></head><body></body></html>');

        foreach (['title', 'description'] as $key) {
            $fix = (string) $this->itemAt($checks, $key)['fix'];

            $this->assertStringNotContainsString('<', $fix, "«{$key}»: يُطلب منه تحرير وسمٍ لا يملكه");
            $this->assertStringContainsString('الظهور في البحث', $fix);
        }
    }
    /* ------------------------------- التهيئة ------------------------------- */
    /** @return array{key:string, label:string, state:string, detail:?string, fix:?string} */
    private function itemAt(array $checks, string $key): array
    {
        foreach ($checks as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        $this->fail("لا بندَ «{$key}» في الفحص");
    }
}
