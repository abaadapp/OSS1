<?php

namespace Tests\Feature;

use App\Mail\SubscriptionNoticeMail;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * انتهاء الاشتراك: مهلةٌ، ثم بابٌ يُقال فيه ما العمل، وخبرٌ يسبق الاثنين.
 *
 * كان الانتهاء إقفالًا لحظيًّا بلا إنذارٍ يصل وبلا سبيلٍ لصاحبه: يُردّ عند
 * الباب برسالةٍ في حقل البريد، فيعيد كتابة كلمة المرور ظنًّا أنه أخطأها، ثم
 * يتّصل ليسأل «لماذا لا أدخل؟» قبل أن يسأل «كيف أجدّد؟».
 */
class SubscriptionGraceTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $endsAt, int $graceDays = 7): User
    {
        Setting::create(['business_id' => null, 'key' => 'grace_days', 'value' => (string) $graceDays]);

        $b = Business::create([
            'name' => 'متجر الورود', 'type' => 'عام', 'status' => 'نشط',
            'email' => 'shop@eventplusapp.om', 'ends_at' => $endsAt,
        ]);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);

        return User::create([
            'business_id' => $b->id, 'name' => 'المالك', 'email' => 'owner@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    // ————— ١· المهلة —————

    public function test_the_shop_keeps_working_during_the_grace_period(): void
    {
        /*
         * أهمّ ما في الملفّ: تأخّر حوالةٍ يومًا كان يوقف صندوقًا في يوم عيد.
         * والمهلة ليست تساهلًا — هي الفرق بين عميلٍ يجدّد متأخّرًا وعميلٍ
         * يتّصل غاضبًا.
         */
        $owner = $this->tenant(now()->subDays(3)->toDateString());

        $this->actingAs($owner)->get(route('admin.dashboard'))->assertOk();
    }
    public function test_a_business_without_an_end_date_is_still_never_locked(): void
    {
        $b = Business::create(['name' => 'بلا مدّة', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);
        $owner = User::create([
            'business_id' => $b->id, 'name' => 'المالك', 'email' => 'x@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($owner)->get(route('admin.dashboard'))->assertOk();
    }

    // ————— ٢· الحجز لا الطرد —————

    public function test_a_locked_merchant_can_still_sign_in(): void
    {
        /*
         * الدخول لا يُرفض: يدخل ويقف عند صفحةٍ تقول له كم عليه وبمن يتّصل.
         * ورفضُه عند الباب كان يجعله يجرّب كلمة المرور ثانيةً وثالثة.
         */
        $this->tenant(now()->subDays(30)->toDateString());

        $this->post(route('login.attempt'), [
            'email' => 'owner@eventplusapp.om', 'password' => 'password',
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticated();
    }
    public function test_a_disabled_business_is_still_thrown_out_not_held(): void
    {
        /*
         * التعطيل غير الانتهاء: أمرُه قرارٌ من المنصة لا موعدٌ مرّ، ولا شيء
         * يفعله صاحبه. فتُنهى جلسته ويُردّ إلى الدخول كما كان.
         */
        $owner = $this->tenant(now()->addMonth()->toDateString());
        $owner->business->update(['status' => 'معطل']);

        $this->actingAs($owner)->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ————— ٣· الإنذار —————

    public function test_it_warns_a_week_before(): void
    {
        Mail::fake();
        $this->tenant(now()->addDays(7)->toDateString());

        $this->artisan('subscriptions:notify')->assertSuccessful();

        Mail::assertSent(SubscriptionNoticeMail::class, fn ($m) => $m->stage === 'before');
    }

    public function test_it_warns_on_the_last_working_day(): void
    {
        Mail::fake();
        $this->tenant(now()->toDateString());

        $this->artisan('subscriptions:notify')->assertSuccessful();

        Mail::assertSent(SubscriptionNoticeMail::class, fn ($m) => $m->stage === 'today');
    }

    public function test_it_warns_the_day_the_system_stops(): void
    {
        // اليوم الذي يلي آخر يوم عمل — لا اليوم الأخير ولا كل يومٍ بعده
        Mail::fake();
        $this->tenant(now()->subDays(8)->toDateString(), graceDays: 7);

        $this->artisan('subscriptions:notify')->assertSuccessful();

        Mail::assertSent(SubscriptionNoticeMail::class, fn ($m) => $m->stage === 'locked');
    }

    public function test_it_does_not_nag_every_single_day(): void
    {
        /*
         * إنذارٌ يصل كل يومٍ يُقرأ زخرفةً في ثالثه، ثم يُصنَّف مزعجًا فلا
         * يُقرأ يوم يجب أن يُقرأ.
         */
        Mail::fake();
        $this->tenant(now()->addDays(4)->toDateString());

        $this->artisan('subscriptions:notify')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_a_long_locked_store_is_not_mailed_forever(): void
    {
        Mail::fake();
        $this->tenant(now()->subYear()->toDateString());

        $this->artisan('subscriptions:notify')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_a_disabled_business_gets_no_renewal_notice(): void
    {
        // إنذارُ تجديدٍ لمن عُطّل بقرارٍ تضليل: التجديد لن يفتح بابه
        Mail::fake();
        $owner = $this->tenant(now()->toDateString());
        $owner->business->update(['status' => 'معطل']);

        $this->artisan('subscriptions:notify')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_the_notice_carries_the_platform_contact(): void
    {
        Mail::fake();
        Setting::create(['business_id' => null, 'key' => 'phone', 'value' => '+968 90000000']);
        $this->tenant(now()->addDay()->toDateString());

        $this->artisan('subscriptions:notify')->assertSuccessful();

        Mail::assertSent(
            SubscriptionNoticeMail::class,
            fn ($m) => ($m->contact['phone'] ?? null) === '+968 90000000',
        );
    }

    // ————— الشريط يعدّ المهلة —————

    public function test_the_banner_knows_how_many_days_are_left(): void
    {
        $owner = $this->tenant(now()->subDays(2)->toDateString(), graceDays: 7);

        $props = $this->actingAs($owner)
            ->get(route('admin.dashboard'))
            ->viewData('page')['props'];

        $this->assertSame(5, $props['context']['subscription']['graceLeft']);
    }

    public function test_a_living_subscription_has_no_grace_number(): void
    {
        $owner = $this->tenant(now()->addMonth()->toDateString());

        $props = $this->actingAs($owner)
            ->get(route('admin.dashboard'))
            ->viewData('page')['props'];

        $this->assertNull($props['context']['subscription']['graceLeft']);
    }
}
