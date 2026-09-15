<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use App\Support\PlanLimits;
use App\Support\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * لوحة المنصّة: ما تقوله أرقامُها وما تفعله مقابضُها.
 *
 * كلّ اختبارٍ هنا يحرس تطابقًا بين شاشتين تتكلّمان عن الشيء نفسه، أو مقبضًا
 * يُحفَظ ويُقرأ فعلًا. والرقمان المفترقان أسوأ من الرقم الخاطئ: أحدهما يبدو
 * صحيحًا دائمًا.
 */
class PlatformAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'boss@eventplusapp.om',
            'password' => bcrypt('secret12345'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    private function demoStore(): Business
    {
        return Business::create(['name' => 'المتجر التجريبي', 'status' => 'نشط', 'is_demo' => true]);
    }

    /* ------------------ الضريبة الافتراضية للمنصّة ------------------ */

    public function test_the_platform_tax_mode_reaches_a_store_that_never_set_one(): void
    {
        /*
         * كانت النسبة ترجع إلى افتراضيّ المنصّة والطريقةُ لا ترجع — وهما في
         * بطاقةٍ واحدة تعد بأنها «تُطبَّق على متجرٍ لم يضبط ضريبته».
         */
        Setting::create(['business_id' => null, 'key' => 'tax_mode', 'value' => 'inclusive']);

        $this->assertTrue(Vat::inclusive($this->business->id));
    }

    public function test_a_store_that_set_its_own_mode_is_not_overridden(): void
    {
        Setting::create(['business_id' => null, 'key' => 'tax_mode', 'value' => 'inclusive']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'tax_mode', 'value' => 'exclusive']);

        $this->assertFalse(Vat::inclusive($this->business->id));
    }
    public function test_the_platform_report_counts_orders_as_the_profile_screen_does(): void
    {
        $order = fn (string $status, int $n) => Order::create([
            'business_id' => $this->business->id, 'number' => $n, 'customer_name' => 'عميل نقدي',
            'subtotal' => 10, 'tax' => 0, 'discount' => 0, 'total' => 10,
            'payment_method' => 'نقدي', 'status' => $status, 'is_held' => false, 'ordered_at' => now(),
        ]);
        foreach ([1, 2, 3] as $n) { $order('مكتمل', $n); }
        $order(Order::CANCELLED, 4);

        $row = collect(Demo::businessPerformance())->firstWhere('id', $this->business->id);
        $counts = Demo::businessCounts($this->business->id);

        $this->assertSame($counts['orders'], $row['orders']);
        $this->assertSame(3, $row['orders']);
    }

    public function test_the_demo_store_is_out_of_the_platform_report(): void
    {
        $demo = $this->demoStore();

        $ids = collect(Demo::businessPerformance())->pluck('id')->all();

        $this->assertContains($this->business->id, $ids);
        $this->assertNotContains($demo->id, $ids);
    }

    /* ------------------- صفُّ المتجر لأوراق الطباعة ------------------- */

    public function test_a_demo_store_still_prints_under_its_own_name(): void
    {
        /*
         * `Demo::business()` كانت تُلتقط من قائمةٍ مقصورة على `real()`،
         * فترجع فارغةً لمتجرٍ تجريبيّ — وكلُّ ورقةٍ يطبعها تخرج باسم
         * «Event Plus OSS» لأن القوالب تسقط إلى ذلك عند غياب الاسم.
         */
        $demo = $this->demoStore();

        $row = Demo::business($demo->id);

        $this->assertSame($demo->name, $row['name'] ?? null);
    }

    public function test_the_tax_invoice_qr_names_the_actual_seller(): void
    {
        $demo = $this->demoStore();
        $order = Order::create([
            'business_id' => $demo->id, 'number' => 1, 'customer_name' => 'عميل نقدي',
            'subtotal' => 10, 'tax' => 0.5, 'discount' => 0, 'total' => 10.5,
            'payment_method' => 'نقدي', 'status' => 'مكتمل', 'is_held' => false, 'ordered_at' => now(),
        ]);

        $qr = \App\Support\EInvoice::forOrder($order, ['number' => 'OM1234567'], Demo::business($demo->id));

        $this->assertStringContainsString($demo->name, base64_decode($qr));
    }

    public function test_reading_one_store_does_not_read_them_all(): void
    {
        foreach (range(1, 10) as $i) {
            Business::create(['name' => "متجر {$i}", 'status' => 'نشط']);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        Demo::business($this->business->id);
        $rows = collect(\Illuminate\Support\Facades\DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'from "businesses"'))->count();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertSame(1, $rows);
    }

    public function test_the_platform_list_still_hides_the_demo_store(): void
    {
        // القائمة تبقى على `real()` — الإصلاح للصفّ الواحد لا للقائمة
        $demo = $this->demoStore();

        $this->assertNotContains($demo->id, collect(Demo::businesses())->pluck('id')->all());
    }
}
