<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\JobTitle;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Billing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * سلطة المنصّة على مستأجريها.
 *
 * كانت لوحة المنصة عارضةً لا لوحة تحكّم: تقرأ وتعدّل، ولا تفرض ما بِيع، ولا
 * تقطع عمّن لم يدفع، ولا تُعين على دعم تاجرٍ اتصل. «موقوف» و«معطل» و«منتهي»
 * كلماتٌ في أعمدة لا يقرؤها أحد، و`max_branches` رقمٌ لا وجود له خارج البذور.
 */
class PlatformControlTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'super@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    private function tenant(array $business = [], array $user = []): array
    {
        $biz = Business::create(array_merge([
            'name' => 'متجري', 'type' => 'عام', 'status' => 'نشط',
        ], $business));

        JobTitle::create(['business_id' => $biz->id, 'name' => 'كاشير', 'role' => 'cashier']);

        $owner = User::create(array_merge([
            'business_id' => $biz->id, 'name' => 'المالك', 'email' => 'o'.uniqid().'@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ], $user));

        return [$biz, $owner];
    }

    /* ------------------- ١· الإيقاف يوقف فعلًا ------------------- */

    public function test_a_suspended_user_cannot_log_in(): void
    {
        [, $owner] = $this->tenant(user: ['status' => 'موقوف', 'email' => 's@eventplus.om']);

        $this->post(route('login.attempt'), ['email' => 's@eventplus.om', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_disabled_business_locks_its_people_out(): void
    {
        [, $owner] = $this->tenant(['status' => 'معطل'], ['email' => 'd@eventplus.om']);

        $this->post(route('login.attempt'), ['email' => 'd@eventplus.om', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }
    /** ويوم الانتهاء نفسه يبقى مسموحًا: من دفع حتى اليوم يعمل اليوم كاملًا */
    public function test_the_last_day_of_the_subscription_still_works(): void
    {
        [, $owner] = $this->tenant(['ends_at' => now()]);

        $this->actingAs($owner)->get(route('admin.dashboard'))->assertOk();
    }

    /** وبلا تاريخ انتهاء لا يُقفل أحد — حسابٌ قديم لا يُعاقَب على حقلٍ فارغ */
    public function test_a_business_without_an_end_date_is_never_blocked(): void
    {
        [, $owner] = $this->tenant(['ends_at' => null]);

        $this->actingAs($owner)->get(route('admin.dashboard'))->assertOk();
    }
    /* ------------------- ٤· الانتهاء التلقائي ------------------- */

    public function test_the_daily_command_flips_expired_businesses(): void
    {
        $expired = Business::create([
            'name' => 'منتهية', 'type' => 'عام', 'status' => 'نشط', 'ends_at' => now()->subDay(),
        ]);
        $living = Business::create([
            'name' => 'حيّة', 'type' => 'عام', 'status' => 'نشط', 'ends_at' => now()->addMonth(),
        ]);

        $this->artisan('subscriptions:expire')->assertSuccessful();

        $this->assertSame('منتهي', $expired->fresh()->status);
        $this->assertSame('نشط', $living->fresh()->status);
    }

    public function test_the_dry_run_writes_nothing(): void
    {
        $b = Business::create([
            'name' => 'منتهية', 'type' => 'عام', 'status' => 'نشط', 'ends_at' => now()->subDay(),
        ]);

        $this->artisan('subscriptions:expire --dry')->assertSuccessful();

        $this->assertSame('نشط', $b->fresh()->status);
    }

    /** والتاجر يُحذَّر قبل الانتهاء لا بعده */
    public function test_the_merchant_is_warned_before_the_end(): void
    {
        [, $owner] = $this->tenant(['ends_at' => now()->addDays(3)]);

        $this->actingAs($owner)->get(route('admin.dashboard'))
            ->assertInertia(fn ($page) => $page->where('context.subscription.daysLeft', 3));
    }

    /* -------------------- ٣· حدود الباقة -------------------- */

    private function planned(array $limits): array
    {
        $plan = Plan::create(array_merge([
            'name' => 'الأساسية', 'monthly_price' => 10, 'yearly_price' => 100,
            'max_branches' => 1, 'max_employees' => 1, 'max_products' => 1,
        ], $limits));

        return $this->tenant(['plan_id' => $plan->id]);
    }

    public function test_the_branch_limit_is_enforced(): void
    {
        [$biz, $owner] = $this->planned(['max_branches' => 1]);
        Branch::create(['business_id' => $biz->id, 'name' => 'الفرع الرئيسي']);

        $this->actingAs($owner)->post(route('admin.branches.store'), ['name' => 'فرع ثانٍ'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Branch::where('business_id', $biz->id)->count());
    }

    public function test_the_employee_limit_is_enforced(): void
    {
        // المالك نفسه مستخدمٌ في المتجر، فسقف واحدٍ مبلوغٌ سلفًا
        [$biz, $owner] = $this->planned(['max_employees' => 1]);

        $this->actingAs($owner)->post(route('admin.employees.store'), [
            'name' => 'موظف', 'login_username' => 'new', 'job_title' => 'كاشير',
        ])->assertSessionHasErrors('name');

        $this->assertSame(1, User::where('business_id', $biz->id)->count());
    }

    public function test_the_product_limit_is_enforced(): void
    {
        [$biz, $owner] = $this->planned(['max_products' => 1]);
        Product::create([
            'business_id' => $biz->id, 'name' => 'وردة', 'price' => 5, 'quantity' => 1, 'active' => true,
        ]);

        $this->actingAs($owner)->post(route('admin.products.store'), ['name' => 'ثانية', 'price' => 5])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Product::where('business_id', $biz->id)->count());
    }
    /**
     * والتجديد المبكر لا يُهدر ما تبقّى.
     *
     * لو مُدّد من اليوم لخسر من جدّد قبل أسبوع ذلك الأسبوع — فيصير التجديد
     * المبكّر عقوبةً، ولا يجدّد أحدٌ إلا متأخّرًا.
     */
    public function test_renewing_early_keeps_the_remaining_days(): void
    {
        [$biz] = $this->planned([]);
        $biz->update(['ends_at' => now()->addDays(20)]);

        Billing::renew($biz->fresh(), 'monthly');

        $this->assertTrue($biz->fresh()->ends_at->isSameDay(now()->addDays(20)->addMonth()));
    }

    /** والمنتهي يُجدَّد من اليوم لا من ماضٍ مضى */
    public function test_renewing_an_expired_one_starts_today(): void
    {
        [$biz] = $this->planned([]);
        $biz->update(['ends_at' => now()->subMonths(3), 'status' => 'منتهي']);

        Billing::renew($biz->fresh(), 'monthly');

        $this->assertTrue($biz->fresh()->ends_at->isSameDay(now()->addMonth()));
        $this->assertSame('نشط', $biz->fresh()->status);
    }

    public function test_invoice_numbers_do_not_repeat(): void
    {
        [$biz] = $this->planned([]);

        Billing::renew($biz->fresh(), 'monthly');
        Billing::renew($biz->fresh(), 'monthly');
        Invoice::latest('id')->first()->delete();
        Billing::renew($biz->fresh(), 'monthly');

        $this->assertSame(
            Invoice::count(),
            Invoice::distinct()->count('number'),
            'تكرّر رقم فاتورة بعد حذف واحدة',
        );
    }
}
