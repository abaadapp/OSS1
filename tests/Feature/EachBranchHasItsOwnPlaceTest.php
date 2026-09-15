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
    /** وفرعٌ غيرُ مربوطٍ لا يُرسل رابطًا */
    public function test_an_unlinked_branch_cannot_ask_for_a_review(): void
    {
        $order = $this->order(OrderStatus::DELIVERED, $this->mawaleh);

        $this->post(route('admin.orders.reviewRequest', $order->number))->assertRedirect();

        $this->assertSame('danger', session('toast')['type']);
        $this->assertNull($order->fresh()->review_request_sent_at);
    }
}
