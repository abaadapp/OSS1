<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\User;
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
}
