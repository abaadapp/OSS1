<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Activity;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لوحة المنصة تقول ما يُدار، وتقول من فعلها.
 *
 * كانت تعرض «إجمالي الشركات» و«المستخدمون» — أرقامٌ ترتفع وأنت تخسر ولا
 * تقول متى. وسجلّ النشاط كان يَنسب ما يفعله الدعم إلى التاجر نفسه.
 */
class PlatformInsightTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private Business $biz;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'super@eventplus.om',
            'password' => 'password', 'role' => 'super_admin', 'status' => 'نشط',
        ]);
        $this->biz = Business::create([
            'name' => 'متجر', 'type' => 'عام', 'status' => 'نشط',
            'starts_at' => now()->subMonths(6),
        ]);
        $this->owner = User::create([
            'business_id' => $this->biz->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => 'password', 'role' => 'admin', 'status' => 'نشط',
        ]);
    }
    /** وما يفعله التاجر بنفسه يبقى بلا علامة — وإلا صارت العلامة بلا معنى */
    public function test_a_normal_action_carries_no_support_marker(): void
    {
        $this->actingAs($this->owner);
        Activity::log('updated', 'عدّل منتجًا');

        $this->assertNull(ActivityLog::latest('id')->first()->impersonator_id);
    }
    /* ------------------ ١·ب بطاقة «أحدث الأنشطة» ------------------ */

    private function cashier(): User
    {
        return User::create([
            'business_id' => $this->biz->id, 'name' => 'كاشير', 'email' => 'c@eventplus.om',
            'password' => 'password', 'role' => 'cashier', 'status' => 'نشط',
        ]);
    }

    /**
     * البطاقة مراقبةٌ لا مرآة: لا تعرض مدير المنصة.
     *
     * كان يفتح لوحته فيقرأ «مدير المنصة — سجّل الدخول» ثماني مرّات، فتُدفع
     * أفعالُ من يجب أن يُراقَبوا خارج الشاشة.
     */
    public function test_the_feed_hides_the_platform_admin(): void
    {
        $this->actingAs($this->super);
        Activity::log('login', 'سجّل الدخول إلى النظام');

        $texts = collect(Demo::activities())->pluck('text')->implode(' ');
        $this->assertStringNotContainsString('مدير المنصة', $texts);
    }

    /** ولوحة التاجر تعرض موظفيه لا نفسه */
    public function test_the_merchant_feed_shows_employees_not_the_owner(): void
    {
        $cashier = $this->cashier();

        $this->actingAs($this->owner);
        Activity::log('login', 'سجّل الدخول إلى النظام');
        $this->actingAs($cashier);
        Activity::log('checkout', 'أتمّ بيعة');

        $this->actingAs($this->owner);
        $texts = collect(Demo::activities())->pluck('text')->implode(' ');

        $this->assertStringContainsString('كاشير', $texts);
        $this->assertStringNotContainsString('المالك', $texts);
    }
    /**
     * وسجلّ التاجر يتتبّع موظفيه لا نفسه.
     *
     * كان يفتحه فيقرأ أفعاله هو، فتُدفع أفعالُ من يجب أن يُراقَبوا خارج الصفحة.
     */
    public function test_the_merchant_log_lists_employees_not_the_owner(): void
    {
        $cashier = $this->cashier();

        $this->actingAs($this->owner);
        Activity::log('login', 'سجّل الدخول إلى النظام');
        $this->actingAs($cashier);
        Activity::log('checkout', 'أتمّ بيعة');

        $this->actingAs($this->owner)->get(route('admin.activity.index'))
            ->assertInertia(fn ($page) => $page
                ->where('logs.0.user', 'كاشير')
                ->count('logs', 1));
    }
    /* ------------------ ٢· الإيراد المتكرّر والفاقد ------------------ */

    /**
     * الاشتراك السنوي يُقسَّم على شهوره.
     *
     * جمعُ الفواتير يجعل شهرًا يبدو عظيمًا وأحد عشر شهرًا تبدو خرابًا —
     * والمتكرّر يقيس ما يتكرّر.
     */
    public function test_a_yearly_subscription_counts_as_its_monthly_share(): void
    {
        Subscription::create([
            'business_id' => $this->biz->id, 'amount' => 120, 'status' => 'نشط',
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonths(11),
        ]);

        $mrr = collect(Demo::superStats())->firstWhere('label', __('الإيراد الشهري المتكرّر'));

        $this->assertNotNull($mrr, 'بطاقة الإيراد المتكرّر غير موجودة');
        $this->assertStringContainsString('10', $mrr['value'], '١٢٠ على ١٢ شهرًا = ١٠');
    }

    /** واشتراكٌ لم يبدأ بعد لا يُحسب: وعدٌ لا إيراد */
    public function test_a_future_subscription_is_not_counted_yet(): void
    {
        Subscription::create([
            'business_id' => $this->biz->id, 'amount' => 120, 'status' => 'نشط',
            'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonths(13),
        ]);

        $mrr = collect(Demo::superStats())->firstWhere('label', __('الإيراد الشهري المتكرّر'));
        $this->assertStringStartsWith('0', $mrr['value']);
    }

    /** والفاقد يعدّ من خرج هذا الشهر */
    public function test_churn_counts_a_business_disabled_this_month(): void
    {
        $this->biz->update(['status' => 'معطل']);

        $churn = collect(Demo::superStats())->firstWhere('label', __('الفاقد هذا الشهر'));

        $this->assertNotNull($churn, 'بطاقة الفاقد غير موجودة');
        $this->assertStringStartsWith('1', $churn['value']);
    }
    /* ------------------ ٤· تقرير المنصة يشمل الجميع ------------------ */

    /**
     * تقرير أداء الشركات لا يقتصر على نوعٍ واحد.
     *
     * كان يقرأ «محلات الورود» وحدها — بقيّةٌ من كون النظام محلَّ ورودٍ يومًا —
     * فيسقط المخابز والمغاسل والورش. تقريرٌ ناقصٌ لا يقول إنه ناقص.
     */
    public function test_the_platform_report_covers_every_business_type(): void
    {
        Business::create(['name' => 'مخبز', 'type' => 'مخبز', 'status' => 'نشط']);
        Plan::create(['name' => 'أساسية', 'monthly_price' => 10, 'yearly_price' => 100]);

        $names = collect(Demo::businessPerformance())->pluck('name')->all();

        $this->assertContains('مخبز', $names);
        $this->assertContains('متجر', $names);
    }
}
