<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Models\User;
use App\Support\Loyalty;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * أدوات التسويق تفي بما تعرضه على الشاشة.
 *
 * وهذا القسم أخطر ما يُخلف الوعد فيه: مقبضٌ لا يُدير شيئًا في المخزون يُكتشف
 * عند الجرد، ومقبضٌ لا يُدير شيئًا هنا يظنّه التاجر يعمل شهورًا — يكتب نصّ
 * رسالةٍ ويعاينها ويحفظها، ولا تصل الزبونَ منها كلمة.
 *
 * والمال يخرج من هذا الباب لا البضاعة: نقطةٌ تُصرف مرّتين، وكوبونٌ محدودٌ
 * بمرّةٍ يُستهلك مرّتين، ونسبةُ اكتسابٍ تعيد للزبون أكثر ممّا دفع. ولا يظهر
 * نقصُ شيءٍ من ذلك في جرد.
 */
class MarketingKeepsItsPromisesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'زبون', 'phone' => '90000000', 'points' => 100000,
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف',
            'price' => 100, 'cost' => 40, 'quantity' => 1000, 'alert_qty' => 1, 'active' => true,
        ]);

        $this->set('vat_enabled', '0');
        $this->set('loyalty_enabled', '1');
        $this->set('loyalty_redeem_max_pct', '50');
        $this->set('loyalty_redeem_min', '100');
    }

    private function set(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => $key], ['value' => $value]);
    }
    /* ==================== واتساب: مقابض تُدير شيئًا ==================== */

    public function test_the_whatsapp_screen_keeps_no_switch_that_switches_nothing(): void
    {
        $dead = ['wa_enabled', 'wa_number', 'wa_template_order', 'wa_template_ready', 'wa_template_delivered'];

        $left = array_intersect($dead, array_keys(MarketingSettings::GROUPS['whatsapp']));

        $this->assertSame(
            [],
            array_values($left),
            'مفاتيحُ لا يقرؤها أحد ما زالت تُعرض مقابضَ في الشاشة: '.implode('، ', $left),
        );
    }

    /*
     * وتطابقُ القائمتين — المقابضُ المعروضة وما يقرؤه المُرسِل — يُقاس في
     * `EveryMarketingLeverReachesItsReaderTest`، وفي **الاتّجاهين** معًا.
     *
     * وكان هنا فحصٌ باتّجاهٍ واحد: «كلُّ مفتاحٍ معروضٍ يقرؤه المُرسِل». وهو
     * صادقٌ ولا يرى العطب — النقصُ كان في الاتّجاه الآخر: حدثان يقرؤهما
     * المُرسِل ولا مفتاحَ لهما يُعرض. ونُقل مع أخيه إلى موضعٍ واحد: فحصان
     * لسؤالٍ واحد في ملفّين يفترقان يوم يُبدَّل أحدهما.
     */

    /* ==================== الكوبون: كودٌ واحد لا كودان ==================== */

    private function coupon(array $attrs = []): Coupon
    {
        return Coupon::create(array_merge([
            'business_id' => $this->business->id, 'code' => 'SAVE10', 'type' => 'مبلغ',
            'value' => 10, 'min_order' => 0, 'active' => true, 'used_count' => 0,
        ], $attrs));
    }
    /* ============ الكوبون: ما نفد لا يُبتلع صمتًا ============ */

    public function test_a_coupon_spent_between_the_quote_and_the_payment_is_said_not_swallowed(): void
    {
        $this->coupon(['max_uses' => 1, 'used_count' => 1]);

        $res = $this->sell(['coupon_code' => 'SAVE10']);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors('coupon_code');
        $this->assertSame(0, Order::count(), 'ولا تُكتب فاتورةٌ بسعرٍ غير الذي قيل للزبون');
    }

    public function test_a_coupon_still_good_passes_and_is_counted_once(): void
    {
        $this->coupon(['max_uses' => 1]);

        $this->sell(['coupon_code' => 'save10'])->assertOk();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame('SAVE10', $order->coupon_code);
        $this->assertSame(10.0, round((float) $order->coupon_discount, 3));
        $this->assertSame(1, (int) Coupon::first()->used_count);
    }

    public function test_the_last_use_of_a_coupon_is_counted_under_a_lock(): void
    {
        /*
         * التزامنُ الحقيقيّ لا يُصنع في عمليةٍ واحدة: طلبان على عاملَي PHP
         * لا يُشغَّلان من اختبار. فيُقرأ الحارس من مصدره — القفل موجود،
         * وقبل الزيادة لا بعدها.
         */
        $src = file_get_contents(app_path('Http/Controllers/Pos/PosController.php'));

        $this->assertStringContainsString('lockForUpdate()', $src);

        $lock = strpos($src, "Coupon::where('business_id'");
        $this->assertNotFalse($lock, 'لم يعد الكوبون يُعاد قراءته عند الدفع');
        $this->assertStringContainsString(
            'lockForUpdate',
            substr($src, $lock, 400),
            'الكوبون يُقرأ بلا قفلٍ ثم يُزاد عدّاده — فمحدودٌ بمرّةٍ يُستهلك مرّتين',
        );
    }

    /* ==================== النقاط: مالٌ لا عدّاد ==================== */

    public function test_points_leave_the_balance_only_if_they_are_in_it(): void
    {
        $this->customer->update(['points' => 5000]);

        $this->sell(['customer_id' => $this->customer->id, 'redeem_points' => 5000])->assertOk();

        $this->assertGreaterThanOrEqual(0, (int) $this->customer->fresh()->points);
    }

    public function test_the_balance_is_written_as_a_condition_not_as_a_subtraction(): void
    {
        $this->customer->update(['points' => 5000]);

        $statements = [];
        DB::listen(function ($q) use (&$statements) {
            $statements[] = $q->sql;
        });

        $this->sell(['customer_id' => $this->customer->id, 'redeem_points' => 3000])->assertOk();

        $guarded = array_values(array_filter(
            $statements,
            fn ($s) => str_contains($s, 'update') && str_contains($s, 'points')
                && str_contains($s, 'points') && preg_match('/points"?\s*>=\s*\?/', $s),
        ));

        $this->assertNotSame(
            [],
            $guarded,
            'الخصم يُكتب طرحًا بلا شرط: قراءتان متزامنتان تريان الرصيد نفسه فيُصرف مرّتين',
        );
    }
    public function test_the_points_a_riyal_buys_is_written_in_one_place(): void
    {
        $this->assertSame(100, Loyalty::POINTS_PER_UNIT);
    }

    /* ==================== التقييمات ==================== */

    public function test_the_review_search_is_not_blind_in_production(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Admin/Marketing/ReviewController.php'));

        $this->assertStringNotContainsString(
            "'like'",
            $src,
            'البحث بـlike أعمى على PostgreSQL: اسمٌ بحروفٍ لاتينية لا يُوجد إلا بحالة أحرفه',
        );
        $this->assertStringContainsString('Search::like()', $src);
    }
}
