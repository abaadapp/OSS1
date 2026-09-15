<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchGooglePlace;
use App\Models\Business;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\BranchGoogle;
use App\Support\GoogleReviews;
use App\Support\MarketingSettings;
use App\Support\OrderStatus;
use App\Support\Storefront;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * لكلِّ فرعٍ مكانُه على الخريطة — وتقييمُ الزبون يصل الفرعَ الذي اشترى منه.
 *
 * ═══ أثقلُ ما هنا ═══
 *
 * معرّفٌ واحدٌ للمتجر كلِّه كان يعني أنّ إيصال فرع المعبيلة يحمل رمزًا يفتح
 * ملفَّ فرع الخوض. وهو عطبٌ **لا يراه صاحبُه أبدًا**: هو لا يمسح إيصالاته
 * بنفسه، والتقييماتُ تصل مكانًا آخر بهدوء.
 *
 * ═══ ولا يُصدَّق ما يأتي من المتصفّح ═══
 *
 * الشاشةُ تعرض نتائج البحث ويختار التاجر، والمُرسَل معرّفٌ وحده. فما يُحفظ
 * يُقرأ من ردّ Google في تلك اللحظة — ومن بدّل اسمًا أو معدّلًا في الطلب لا
 * يجعل شاشتَنا تشهد بما لم تقله Google.
 */
class EachBranchHasItsOwnPlaceTest extends TestCase
{
    use RefreshDatabase;

    private const A = 'ChIJN1t_tDeuEmsRUsoyG83frY4';

    private const B = 'ChIJrTLr_LlXwokRBiuGZuRoGaB';

    private Business $shop;

    private Branch $khoud;

    private Branch $mawaleh;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Cache::clear();
        Http::preventStrayRequests();

        $this->shop = Business::create([
            'name' => 'ورد افنت بلس', 'type' => 'محل ورود', 'status' => 'نشط', 'phone' => '91234567',
        ]);
        $this->khoud = Branch::create(['business_id' => $this->shop->id, 'name' => 'فرع الخوض']);
        $this->mawaleh = Branch::create(['business_id' => $this->shop->id, 'name' => 'فرع المعبيلة']);
        JobTitle::create(['business_id' => $this->shop->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    /* ═══════════════ أدوات ═══════════════ */

    private function platformKey(string $key = 'platform-places-key'): void
    {
        Setting::updateOrCreate(
            ['business_id' => null, 'key' => GoogleReviews::PLATFORM_KEY],
            ['value' => Crypt::encryptString($key)],
        );
    }

    /** ردُّ «تفاصيل المكان» كما ترسله Google — بحقولها هي */
    private function fakeDetails(string $name = 'ورد افنت بلس — الخوض', ?float $rating = 4.8, int $count = 127): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response([
            'id' => self::A,
            'displayName' => ['text' => $name],
            'rating' => $rating,
            'userRatingCount' => $count,
            'googleMapsUri' => 'https://maps.google.com/?cid=111',
        ], 200)]);
    }

    private function fakeSearch(array $places): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response(['places' => $places], 200)]);
    }
    /** صفٌّ مربوطٌ مباشرةً — حين يقيس الاختبارُ ما بعد الربط لا الربطَ نفسه */
    private function linked(Branch $branch, string $placeId = self::A, ?float $rating = 4.8, int $count = 127): BranchGooglePlace
    {
        return BranchGooglePlace::create([
            'branch_id' => $branch->id,
            'place_id' => $placeId,
            'place_name' => 'ورد افنت بلس',
            'maps_url' => 'https://maps.google.com/?cid=111',
            'rating' => $rating,
            'review_count' => $count,
            'synced_at' => now(),
            'linked_at' => now(),
        ]);
    }

    /* ═══════════════ ١ · إعداد المنصّة ═══════════════ */

    /** المفتاح يُحفظ معمًّى — ونصُّه لا يُقرأ من القاعدة */
    public function test_the_platform_key_is_stored_encrypted(): void
    {
        GoogleReviews::storePlatformKey('AIzaSy-PLATFORM-0000000000');

        $stored = (string) Setting::whereNull('business_id')
            ->where('key', GoogleReviews::PLATFORM_KEY)->value('value');

        $this->assertNotSame('AIzaSy-PLATFORM-0000000000', $stored, 'المفتاح مكتوبٌ نصًّا في القاعدة');
        $this->assertSame('AIzaSy-PLATFORM-0000000000', Crypt::decryptString($stored));
        $this->assertSame('AIzaSy-PLATFORM-0000000000', GoogleReviews::platformKey());
    }
    /* ═══════════════ ٣ · الربط بالفرع ═══════════════ */

    public function test_a_branch_is_linked_to_its_own_place(): void
    {
        $this->platformKey();
        $this->fakeDetails();

        $this->linkVia($this->khoud)->assertSessionHasNoErrors();

        $row = BranchGoogle::for($this->khoud);

        $this->assertNotNull($row);
        $this->assertSame(self::A, $row->place_id);
        $this->assertSame('ورد افنت بلس — الخوض', $row->place_name);
        $this->assertSame(4.8, $row->rating);
        $this->assertSame(127, $row->review_count);
        $this->assertSame($this->owner->id, $row->linked_by);
        $this->assertNotNull($row->synced_at);
    }

    /**
     * وما جاء من المتصفّح لا يُكتب — يُكتب ما ردّته Google.
     *
     * وهو أثقلُ حارسٍ في الربط: من بدّل الاسمَ والمعدّل في الطلب يجعل شاشتَنا
     * تشهد لمحلٍّ بما لم تقله Google عنه.
     */
    public function test_place_data_sent_from_the_browser_is_ignored(): void
    {
        $this->platformKey();
        $this->fakeDetails(name: 'الاسم الحقيقي', rating: 3.1, count: 9);

        $this->linkVia($this->khoud, self::A, [
            'place_name' => 'اسمٌ لفّقه المتصفّح',
            'rating' => 5.0,
            'review_count' => 9999,
            'maps_url' => 'https://evil.example/',
        ])->assertSessionHasNoErrors();

        $row = BranchGoogle::for($this->khoud);

        $this->assertSame('الاسم الحقيقي', $row->place_name, 'كُتب اسمٌ جاء من المتصفّح');
        $this->assertSame(3.1, $row->rating);
        $this->assertSame(9, $row->review_count);
        $this->assertSame('https://maps.google.com/?cid=111', $row->maps_url);
    }

    /** ومعرّفٌ لا تعرفه Google لا يُكتب صفًّا */
    public function test_a_place_google_does_not_know_is_not_stored(): void
    {
        $this->platformKey();
        Http::fake(['places.googleapis.com/*' => Http::response(['error' => ['message' => 'Not found']], 404)]);

        $this->linkVia($this->khoud)->assertSessionHasErrors('place_id');

        $this->assertSame(0, BranchGooglePlace::count());
    }

    /** ومعرّفٌ غيرُ مقروءٍ يُردّ قبل أن يُنادى أحد */
    public function test_an_unreadable_place_id_never_reaches_google(): void
    {
        $this->platformKey();

        $this->linkVia($this->khoud, 'ليس معرّفًا')->assertSessionHasErrors('place_id');

        Http::assertNothingSent();
        $this->assertSame(0, BranchGooglePlace::count());
    }

    /** وردٌّ ناجحٌ بلا اسمٍ لا يُقبل: «تمّ الربط» تحته سطرٌ فارغ لا يُتحقّق منه */
    public function test_a_nameless_place_is_refused(): void
    {
        $this->platformKey();
        Http::fake(['places.googleapis.com/*' => Http::response([
            'id' => self::A, 'displayName' => ['text' => '  '],
        ], 200)]);

        $this->linkVia($this->khoud)->assertSessionHasErrors('place_id');
        $this->assertSame(0, BranchGooglePlace::count());
    }

    /* ═══════════════ ٤ · الفروع لا تختلط ═══════════════ */

    public function test_two_branches_hold_two_different_places(): void
    {
        $this->linked($this->khoud, self::A);
        $this->linked($this->mawaleh, self::B);

        $this->assertSame(self::A, BranchGoogle::for($this->khoud)->place_id);
        $this->assertSame(self::B, BranchGoogle::for($this->mawaleh)->place_id);

        $this->assertNotSame(
            BranchGoogle::reviewUrl(BranchGoogle::for($this->khoud)),
            BranchGoogle::reviewUrl(BranchGoogle::for($this->mawaleh)),
            'الفرعان يرسلان الزبائن إلى الملفّ نفسِه',
        );
    }
    /** وإعادةُ الربط ترفع الختم على الصفّ نفسِه — لا صفَّين لفرع */
    public function test_relinking_reuses_the_same_row(): void
    {
        $this->linked($this->khoud);
        BranchGoogle::unlink($this->khoud);

        $this->platformKey();
        $this->fakeDetails();
        $this->linkVia($this->khoud)->assertSessionHasNoErrors();

        $this->assertSame(1, BranchGooglePlace::where('branch_id', $this->khoud->id)->count());
        $this->assertNotNull(BranchGoogle::for($this->khoud));
    }
    /* ═══════════════ ٧ · الذاكرة والحصّة ═══════════════ */

    /** كم نداءً وقع حتّى الآن — يُقاس ولا يُفترض */
    private function calls(): int
    {
        return count(Http::recorded());
    }
    /** وربطٌ حديثٌ لا يُزامَن أصلًا — المزامنةُ للقديم وحده */
    public function test_a_fresh_link_is_not_synced(): void
    {
        $this->platformKey();
        $place = $this->linked($this->khoud, rating: 4.0, count: 10);
        $this->fakeDetails(rating: 4.9, count: 200);

        $this->assertFalse(BranchGoogle::isStale($place));
        $this->assertFalse(BranchGoogle::sync($place)['wrote'], 'زوُمن ربطٌ حديث');

        Http::assertNothingSent();
        $this->assertSame(4.0, BranchGoogle::for($this->khoud)->rating);
    }

    /** والقديمُ يُزامَن مرّةً ثمّ يصير حديثًا فلا يُزامَن ثانية */
    public function test_a_stale_link_is_synced_once(): void
    {
        $this->platformKey();
        $place = $this->linked($this->khoud, rating: 4.0, count: 10);
        $place->forceFill(['synced_at' => now()->subHours(BranchGoogle::STALE_HOURS + 1)])->save();

        $this->fakeDetails(name: 'ورد افنت بلس — الخوض', rating: 4.9, count: 200);

        $this->assertTrue(BranchGoogle::sync($place->fresh())['wrote'], 'لم يُزامَن ربطٌ قديم');
        $after = $this->calls();

        $this->assertSame(4.9, BranchGoogle::for($this->khoud)->rating);
        $this->assertSame(200, BranchGoogle::for($this->khoud)->review_count);

        // وصار حديثًا: نداءٌ ثانٍ لا يقع
        $this->assertFalse(BranchGoogle::sync(BranchGoogle::for($this->khoud))['wrote']);
        $this->assertSame($after, $this->calls());
    }
    /* ═══════════════ ٨ · الإيصال ═══════════════ */

    /**
     * ورمزُ الإيصال بملفّ **فرع الورقة** — ولا احتياطَ إلى فرعٍ آخر.
     *
     * وهو العطبُ الذي جاء الربطُ بالفرع ليُصلحه: زبونٌ اشترى من المعبيلة
     * يمسح رمزًا فيكتب تقييمًا يُحسب للخوض.
     */
    public function test_the_receipt_code_follows_the_branch_of_the_paper(): void
    {
        MarketingSettings::save($this->shop->id, 'google', ['google_review_on_receipt' => '1']);
        $this->linked($this->khoud, self::A);

        $this->assertSame(
            'https://search.google.com/local/writereview?placeid='.self::A,
            GoogleReviews::onReceipt($this->shop->id, $this->khoud->id),
        );

        $this->assertNull(
            GoogleReviews::onReceipt($this->shop->id, $this->mawaleh->id),
            'طُبع على إيصال فرعٍ غير مربوطٍ رمزُ فرعٍ آخر',
        );
    }

    /* ═══════════════ ٩ · طلب التقييم ═══════════════ */

    private function order(string $status, ?Branch $branch = null): Order
    {
        $customer = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'زبونة', 'phone' => '96890000001',
        ]);

        return Order::create([
            'business_id' => $this->shop->id,
            'branch_id' => ($branch ?? $this->khoud)->id,
            'customer_id' => $customer->id,
            'number' => 'INV-'.uniqid(),
            'status' => $status, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
            'customer_name' => 'زبونة',
        ]);
    }

    public function test_a_delivered_order_can_ask_for_a_review(): void
    {
        $this->linked($this->khoud, self::A);
        $order = $this->order(OrderStatus::DELIVERED);

        $this->post(route('admin.orders.reviewRequest', $order->number))->assertRedirect();

        $toast = session('toast');

        /* و`info` لا `success` — التجهيزُ ليس إرسالًا */
        $this->assertSame('info', $toast['type']);
        $this->assertStringContainsString('wa.me/96890000001', $toast['link']['url']);
        $this->assertStringContainsString(rawurlencode(self::A), $toast['link']['url']);
        $this->assertNotNull($order->fresh()->review_request_sent_at);
    }

    /** وطلبٌ لم يُسلَّم لا يُسأل عنه: لا تجربةَ قبل أن يصل الورد */
    public function test_an_undelivered_order_is_refused(): void
    {
        $this->linked($this->khoud);
        $order = $this->order(OrderStatus::PREPARING);

        $this->post(route('admin.orders.reviewRequest', $order->number))->assertRedirect();

        $this->assertSame('danger', session('toast')['type']);
        $this->assertNull($order->fresh()->review_request_sent_at);
    }

    /** وفرعٌ غيرُ مربوطٍ لا يُرسل رابطًا */
    public function test_an_unlinked_branch_cannot_ask_for_a_review(): void
    {
        $order = $this->order(OrderStatus::DELIVERED, $this->mawaleh);

        $this->post(route('admin.orders.reviewRequest', $order->number))->assertRedirect();

        $this->assertSame('danger', session('toast')['type']);
        $this->assertNull($order->fresh()->review_request_sent_at);
    }

    /**
     * والرابطُ رابطُ **فرع هذا الطلب**.
     *
     * وهو ما يفرّق طلبًا من الخوض عن طلبٍ من المعبيلة: تقييمُ الزبون يُحسب
     * للفرع الذي اشترى منه.
     */
    public function test_the_link_belongs_to_the_orders_own_branch(): void
    {
        $this->linked($this->khoud, self::A);
        $this->linked($this->mawaleh, self::B);

        $this->post(route('admin.orders.reviewRequest', $this->order(OrderStatus::DELIVERED, $this->mawaleh)->number));

        $this->assertStringContainsString(rawurlencode(self::B), session('toast')['link']['url']);
        $this->assertStringNotContainsString(rawurlencode(self::A), session('toast')['link']['url']);
    }

    /** ولا رقمَ لا رسالة — ويُقال، لا يُصمت */
    public function test_an_order_without_a_phone_is_told_so(): void
    {
        $this->linked($this->khoud);

        $order = Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->khoud->id,
            'number' => 'INV-NOPHONE', 'status' => OrderStatus::DELIVERED, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(), 'customer_name' => 'نقدي',
        ]);

        $this->post(route('admin.orders.reviewRequest', $order->number))->assertRedirect();

        $this->assertSame('danger', session('toast')['type']);
    }

    /** والشاشةُ لا تعرض الزرَّ إلا حين يعمل — بابٌ معروضٌ لا يُفتح أسوأ من غيابه */
    public function test_the_button_is_only_offered_when_it_works(): void
    {
        $this->linked($this->khoud);

        $ready = $this->order(OrderStatus::DELIVERED);
        $early = $this->order(OrderStatus::PREPARING);
        $unlinked = $this->order(OrderStatus::DELIVERED, $this->mawaleh);

        $show = fn (Order $o) => $this->get(route('admin.orders.show', $o->number))
            ->viewData('page')['props']['googleReview'];

        $this->assertTrue($show($ready)['show']);
        $this->assertFalse($show($early)['show']);
        $this->assertFalse($show($unlinked)['show']);
        $this->assertNotNull($show($unlinked)['reason'], 'أُخفي الزرُّ بلا سببٍ يُقرأ');
    }

    /* ═══════════════ ١٠ · صفحة المتجر ═══════════════ */

    private function publish(): void
    {
        $this->shop->forceFill(['site_slug' => 'ward-eventplus'])->save();
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1']);
        Product::create([
            'business_id' => $this->shop->id, 'name' => 'بوكيه',
            'price' => 12.5, 'cost' => 5, 'quantity' => 10, 'alert_qty' => 2, 'active' => true,
        ]);
    }

    public function test_the_site_shows_the_rating_only_when_the_merchant_asks(): void
    {
        $this->publish();
        $this->linked($this->khoud, rating: 4.8, count: 127);

        $this->assertNull(Storefront::page($this->shop->fresh())['google'], 'عُرض المعدّل بلا إذن');

        MarketingSettings::save($this->shop->id, 'google', ['google_show_on_site' => '1']);

        $shown = Storefront::page($this->shop->fresh())['google'];

        $this->assertSame(4.8, $shown['rating']);
        $this->assertSame(127, $shown['count']);
    }
    /** ومكانٌ مربوطٌ بلا تقييماتٍ لا يُعرض «٠ تقييم» */
    public function test_a_place_with_no_reviews_shows_no_number(): void
    {
        $this->publish();
        $this->linked($this->khoud, rating: null, count: 0);
        MarketingSettings::save($this->shop->id, 'google', ['google_show_on_site' => '1']);

        $this->assertNull(Storefront::page($this->shop->fresh())['google']);
    }
}
