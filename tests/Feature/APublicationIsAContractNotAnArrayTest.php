<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Models\WebsiteSection;
use App\Models\WebsiteVersion;
use App\Support\MarketingSettings;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\Domains;
use App\Support\Website\Preview;
use App\Support\Website\Publication;
use App\Support\Website\Published;
use App\Support\Website\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * النشرُ عقدٌ له نسخة — لا مصفوفةٌ تُجمَّع.
 *
 * وثلاثةٌ يحرسها هذا الملفّ، وكلٌّ منها كان عطبًا قائمًا قبل هذه النسخة:
 *
 * ١) **ما تحفظه الشاشةُ يصل الموقعَ المنشور.** «أخفِ الأسعار» و«السماح
 *    لمحرّكات البحث» كانا يُحفظان ويُقال «حُفظ» ولا يقرؤهما شيء. ومقبضٌ لا
 *    يُدير شيئًا أسوأ من غياب المقبض، لأنّ صاحبه يظنّ أنّه فعل.
 *
 * ٢) **ونشرتان معًا لا تصطدمان.** الرقمُ كان `MAX + 1` بلا قفل، فضغطتان
 *    متقاربتان تكتبان الرقم نفسه ويسقط أحدهما على الفهرس الفريد.
 *
 * ٣) **ونسخةُ الأمس تُقرأ اليوم.** مستندٌ نُشر بشكلٍ قديم لا ينكسر عند
 *    قارئه، ولا يُعاد كتابةُ تاريخ التاجر من تحته.
 */
class APublicationIsAContractNotAnArrayTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط',
            'phone' => '96890000000', 'city' => 'مسقط', 'address' => 'الخوير',
            'site_slug' => 'wrood',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function bid(): int
    {
        return (int) $this->business->id;
    }

    private function catalogue(): Product
    {
        $category = Category::create(['business_id' => $this->bid(), 'name' => 'باقات']);

        return Product::create([
            'business_id' => $this->bid(), 'category_id' => $category->id,
            'name' => 'باقة ورد', 'price' => 12.5, 'active' => true, 'quantity' => 5,
        ]);
    }

    private function build(string $goal = Blueprints::STORE): Website
    {
        return Builder::create($this->business, $goal, 'modern', $this->owner->id);
    }

    /* ═════════════════════ ما يُحفظ يصل المنشور ═════════════════════ */

    public function test_the_publication_carries_what_the_shop_screen_saved(): void
    {
        $site = $this->build();

        MarketingSettings::save($this->bid(), 'website', [
            'store_show_prices' => '0', 'store_allow_orders' => '1',
        ]);

        $doc = Publication::compile($site->fresh());

        $this->assertFalse($doc['commerce']['show_prices']);
        // وسعرٌ مخفيٌّ لا يُطلب معه — والقاعدة في العقد لا في الشاشة
        $this->assertFalse($doc['commerce']['allow_orders'], 'يُطلب ما لا يُعرف ثمنه');
    }

    public function test_a_shop_that_shows_prices_and_takes_orders_says_both(): void
    {
        $site = $this->build();

        MarketingSettings::save($this->bid(), 'website', [
            'store_show_prices' => '1', 'store_allow_orders' => '1',
        ]);

        $doc = Publication::compile($site->fresh());

        $this->assertTrue($doc['commerce']['show_prices']);
        $this->assertTrue($doc['commerce']['allow_orders']);
    }

    /** والتعريفيُّ لا كتالوجَ له، فلا سعرَ ولا طلب */
    public function test_a_profile_site_neither_prices_nor_orders(): void
    {
        $doc = Publication::compile($this->build(Blueprints::PROFILE));

        $this->assertFalse($doc['commerce']['show_prices']);
        $this->assertFalse($doc['commerce']['allow_orders']);
    }

    /**
     * والكتالوجُ يُطلب منه على واتساب — وهو تعريفُ وجهته لا مفتاحٌ يُسأل عنه.
     *
     * ولذلك لا تعرض له الشاشةُ مقبضَ «السماح بالطلب» أصلًا، ويبقى المحفوظ
     * صفرًا. ولو قُرئ المحفوظ لَاختفى زرُّ الطلب من كلّ كتالوجٍ يعمل اليوم.
     */
    public function test_a_catalogue_orders_on_whatsapp_by_its_goal_not_by_a_switch(): void
    {
        $site = $this->build(Blueprints::CATALOG);

        MarketingSettings::save($this->bid(), 'website', ['store_allow_orders' => '0']);

        $this->assertTrue(Publication::compile($site->fresh())['commerce']['allow_orders']);
    }
    /* ═════════════════════ نشرتان معًا ═════════════════════ */

    /**
     * نشرتان متتاليتان لا تكتبان الرقم نفسه.
     *
     * والاختبار يقيس ما يمكن قياسُه في SQLite: أنّ الرقم يُقرأ من القاعدة
     * في كلّ نشرة لا من نسخةٍ في الذاكرة، وأنّ الفهرس الفريد لا يُخترق.
     * والقفلُ نفسه (`lockForUpdate`) لا يُحاكى هنا — يعمل على Postgres،
     * وSQLite تتجاهله. فما يُحرَس: أنّ نسخةً قديمة في يد المنادي لا تُنتج
     * رقمًا مكرّرًا.
     */
    public function test_two_publishes_never_share_a_number(): void
    {
        $site = $this->build();

        $stale = Website::whereKey($site->id)->first();

        $first = Publisher::publish($site, $this->owner->id);
        // النسخةُ القديمة لا تعرف بالأولى — ولو قرأت رقمَها من نفسها لتصادما
        $second = Publisher::publish($stale, $this->owner->id);

        $this->assertNotSame($first->number, $second->number);
        $this->assertSame(2, WebsiteVersion::where('website_id', $site->id)->count());
    }

    /**
     * والمنشورُ يشير إلى ما جُمّد بالضبط.
     *
     * `published_revision` كان يُقرأ من نسخةٍ في الذاكرة حُمّلت قبل الطلب،
     * فحفظٌ يقع بينهما يجعل اللوحة تقول «فيه تغييرات» عن تغييرٍ نُشر.
     */
    public function test_the_published_revision_matches_what_was_frozen(): void
    {
        $site = $this->build();

        // حفظٌ يقع بعد أن حُمّلت النسخة التي بيد المنادي
        Website::whereKey($site->id)->increment('draft_revision');

        Publisher::publish($site, $this->owner->id);

        $fresh = $site->fresh();

        $this->assertSame((int) $fresh->draft_revision, (int) $fresh->published_revision);
        $this->assertFalse($fresh->hasUnpublishedChanges(), 'نُشر كلُّ ما في المسوّدة ثمّ قيل إنّ فيها ما لم يُنشر');
    }

    /** والنشرةُ لا تُمسّ بعد كتابتها: تعديلُ المسوّدة لا يبلغها */
    public function test_a_version_is_frozen_once_written(): void
    {
        $site = $this->build();
        $version = Publisher::publish($site, $this->owner->id);

        $site->fresh()->update(['name' => 'اسمٌ جديد']);

        $this->assertSame('ورود مسقط', $version->fresh()->payload['name']);
    }

    /* ═════════════════════ نسخةُ الأمس ═════════════════════ */

    /**
     * مستندٌ بلا `commerce` يُقرأ بما كان يراه زائرُه.
     *
     * ولا يُقرأ بالافتراضيّ الجديد: لو كان كذلك لَتبدّلت مواقعُ منشورةٌ بلا
     * أن ينشرها أحد — وذلك أسوأ من عطب، لأنّه تغييرٌ لم يطلبه صاحبُه.
     */
    public function test_a_first_schema_document_is_read_as_it_was_seen(): void
    {
        $old = [
            'version' => 1,
            'name' => 'ورود مسقط',
            'goal' => Blueprints::STORE,
            'template' => 'modern',
            'theme' => ['primary' => '#2563eb'],
            'seo' => ['title' => 'ورود'],
            'globals' => [],
            'pages' => [],
        ];

        $doc = Publication::upgrade($old);

        $this->assertSame(Publication::SCHEMA, $doc['schema_version']);
        $this->assertTrue($doc['commerce']['show_prices'], 'أسعارٌ كانت تُعرض اختفت بلا نشر');
        $this->assertTrue($doc['commerce']['allow_orders']);
        $this->assertTrue($doc['seo']['index']);
    }

    /** ومستندٌ ناقصٌ لا يرمي: هذا مسارٌ يخدم زبونًا على نطاق تاجر */
    public function test_a_ragged_document_is_read_not_thrown_at(): void
    {
        $doc = Publication::upgrade(['name' => 'متجر']);

        foreach (Publication::CONTRACT as $key) {
            $this->assertArrayHasKey($key, $doc, "العقد بلا «{$key}»");
        }

        $this->assertSame([], $doc['pages']);
        $this->assertSame([], $doc['globals']);
    }

    /** والقارئُ نفسه يُرقّي: `Preview::resolve` لا تفترض شكل اليوم */
    public function test_the_reader_upgrades_before_it_reads(): void
    {
        $site = $this->build();
        $version = Publisher::publish($site, $this->owner->id);

        // نشرةٌ كُتبت بالشكل الأوّل — كما في قاعدةٍ قائمة
        $payload = $version->payload;
        unset($payload['commerce'], $payload['schema_version']);
        $payload['version'] = 1;
        $version->update(['payload' => $payload]);

        $doc = Preview::resolve($version->fresh()->payload, $this->bid());

        $this->assertArrayHasKey('commerce', $doc);
        $this->assertSame(Publication::SCHEMA, $doc['schema_version']);
    }

    /** والاستعادةُ تُرقّي أيضًا — فلا يدخل النقصُ إلى المسوّدة الحيّة */
    public function test_restoring_an_old_version_writes_a_whole_draft(): void
    {
        $site = $this->build();
        $version = Publisher::publish($site, $this->owner->id);

        $payload = $version->payload;
        unset($payload['commerce'], $payload['schema_version']);
        $version->update(['payload' => $payload]);

        Publisher::restore($site->fresh(), $version->fresh());

        $this->assertGreaterThan(0, $site->fresh()->pages()->count());
        $this->assertArrayHasKey('commerce', Publication::compile($site->fresh()));
    }

    /* ═════════════════════ ما لا يدخل النشرة ═════════════════════ */

    /**
     * قسمٌ لا يعرفه الكتالوج لا يخرج إلى زائر.
     *
     * يبقى في مسوّدة صاحبه — لا يُحذف عملُه — ولا يصل العارضَ الذي لا يعرف
     * كيف يرسمه.
     */
    public function test_a_section_the_catalogue_does_not_know_never_leaves(): void
    {
        $site = $this->build();
        $page = $site->homePage();

        WebsiteSection::create([
            'website_id' => $site->id, 'business_id' => $this->bid(), 'page_id' => $page->id,
            'type' => 'a_type_from_another_life', 'position' => 99, 'visible' => true, 'data' => [],
        ]);

        $types = collect(Publication::compile($site->fresh())['pages'])
            ->flatMap(fn ($p) => collect($p['sections'])->pluck('type'))->all();

        $this->assertNotContains('a_type_from_another_life', $types);
        $this->assertSame(1, WebsiteSection::where('type', 'a_type_from_another_life')->count(), 'حُذف عملُ التاجر');
    }

    /** وقسمٌ لا يصلح للوجهة بعد أن بدّلها التاجر يسقط كذلك */
    public function test_a_section_that_no_longer_suits_the_goal_never_leaves(): void
    {
        $this->catalogue();
        $site = $this->build(Blueprints::STORE);

        $this->assertContains('featured_products', $this->typesIn($site));

        $site->update(['goal' => Blueprints::PROFILE]);

        $this->assertNotContains('featured_products', $this->typesIn($site->fresh()));
    }

    /**
     * و«الأكثر مبيعًا» لا يُبنى لمن لم يبع بعد.
     *
     * متجرٌ رفع مئةَ صنفٍ ولم تقع فيه بيعةٌ واحدة كان يُبنى له شريطٌ فارغ في
     * صدر صفحته أوّلَ يوم — وعليه يحكم على الموقع كلِّه.
     */
    public function test_best_sellers_waits_for_a_first_sale(): void
    {
        $this->catalogue();

        $this->assertNotContains('best_sellers', $this->typesIn($this->build()));
    }

    /** @return list<string> */
    private function typesIn(Website $site): array
    {
        return collect(Publication::compile($site)['pages'])
            ->flatMap(fn ($p) => collect($p['sections'])->pluck('type'))->values()->all();
    }

    /** ولا يُنشر موقعٌ بلا رئيسية — النطاق يفتح على لا شيء */
    public function test_a_site_without_a_home_page_is_not_published(): void
    {
        $site = $this->build();
        $site->pages()->update(['is_home' => false]);

        $this->assertNotEmpty(Publication::problems($site->fresh()));
    }

    /* ═════════════════════ المخفيّ لا يُقرأ ═════════════════════ */
    /* ═════════════════════ الحيُّ يبقى حيًّا ═════════════════════ */

    /**
     * سعرُ المنتج يتبدّل في الموقع بلا نشرةٍ جديدة.
     *
     * النشرةُ تصف **ماذا يُعرض**، والكتالوجُ يقول **ما هو الآن**. ولو جُمّد
     * السعرُ لبقي سعرُ الأمس في موقعٍ نُشر قبل سنة، ولصار في النظام كتالوجان.
     */
    public function test_a_price_changes_without_republishing(): void
    {
        $product = $this->catalogue();
        $site = $this->build();
        Publisher::publish($site->fresh(), $this->owner->id);

        $product->update(['price' => 99.0]);

        $doc = Published::forBusiness($this->bid())['site'];
        $prices = collect($doc['data']['products'] ?? [])->pluck('price')->all();

        $this->assertContains(99.0, $prices);
    }
    /* ═════════════════════ الطبقاتُ لا تنقلب ═════════════════════ */

    /**
     * نطاقُ الموقع لا يستدعي متحكّم نقطة البيع.
     *
     * كان تذييلُ الموقع المنشور يقرأ طرقَ الدفع من
     * `PosController::enabledPaymentMethods` — طبقةُ ويبٍ تناديها طبقةُ
     * نطاق. فيتعلّق منطقُ المتجر بمسارٍ في الويب: لا يُنقل ولا يُنادى من
     * طابور ولا من أمرٍ في الطرفية إلّا بحيلة.
     */
    public function test_the_website_domain_never_calls_a_controller(): void
    {
        $offenders = [];

        foreach (glob(app_path('Support/Website/*.php')) ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), 'Http\\Controllers')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'طبقةُ الموقع تنادي متحكّمًا — انقل المنطق إلى مكتبة');
    }

    /** وطرقُ الدفع من مصدرٍ واحد يقرؤه الموقعُ ونقطةُ البيع */
    public function test_both_doors_read_the_same_payment_methods(): void
    {
        Setting::create(['business_id' => $this->bid(), 'key' => 'pay_transfer', 'value' => '0']);

        $this->assertSame(
            ['نقدي', 'بطاقة'],
            \App\Support\PaymentMethods::enabledFor($this->bid()),
        );

        $site = $this->build();
        Publisher::publish($site->fresh(), $this->owner->id);

        $doc = Published::forBusiness($this->bid())['site'];

        $this->assertNotContains('تحويل بنكي', $doc['brand']['payments']);
    }
}
