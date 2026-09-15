<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\OrderStatus;
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
    /* ═══════════════ ١ · إعداد المنصّة ═══════════════ */
    /* ═══════════════ ٧ · الذاكرة والحصّة ═══════════════ */

    /** كم نداءً وقع حتّى الآن — يُقاس ولا يُفترض */
    private function calls(): int
    {
        return count(Http::recorded());
    }
    /* ═══════════════ ٨ · الإيصال ═══════════════ */
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
}
