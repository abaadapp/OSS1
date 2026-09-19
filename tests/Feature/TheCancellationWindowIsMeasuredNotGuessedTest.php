<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CompanyPolicy;
use App\Support\CancellationWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مهلةُ الإلغاء تُقاس على موعد الفعاليّة من سياسة الشركة — ولا تُخمَّن.
 *
 * قاعدةٌ معزولة تُحسب ولا تكتب: لا فاتورةَ ولا حالةَ مشروع. وصلُها بمسار
 * إلغاء المشروع ورسمِه عملُ ما بعد دمج المرحلة 4 — انظر `CancellationWindow`.
 */
class TheCancellationWindowIsMeasuredNotGuessedTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط']);
    }

    private function policy(array $params = ['days' => 14, 'fee_percent' => 25], array $over = []): CompanyPolicy
    {
        return CompanyPolicy::create($over + [
            'business_id' => $this->business->id,
            'key' => CancellationWindow::KEY,
            'params' => $params,
            'text_ar' => 'يُقبل الإلغاء بلا رسوم قبل الموعد بأربعة عشر يومًا.',
            'active' => true,
        ]);
    }

    public function test_inside_the_window_is_free(): void
    {
        $this->policy();

        $v = CancellationWindow::evaluate((int) $this->business->id, '2026-10-30', 4000, '2026-10-01');

        $this->assertTrue($v->free);
        $this->assertFalse($v->requiresFee());
        $this->assertSame(29, $v->availableDays);
        $this->assertSame(14, $v->requiredDays);
        $this->assertSame(0.0, $v->feeAmount);
        $this->assertSame(25.0, $v->feePercent);
        $this->assertStringContainsString('داخل مهلة', $v->message());
    }

    public function test_outside_the_window_the_configured_fee_is_computed_from_the_base_amount(): void
    {
        $this->policy();

        $v = CancellationWindow::evaluate((int) $this->business->id, '2026-10-30', 4000, '2026-10-25');

        $this->assertFalse($v->free);
        $this->assertTrue($v->requiresFee());
        $this->assertSame(5, $v->availableDays);
        $this->assertSame(1000.0, $v->feeAmount);
        $this->assertStringContainsString('خارج مهلة', $v->message());
        $this->assertSame(1000.0, $v->toArray()['fee_amount']);
        $this->assertSame((int) $v->toArray()['policy_id'], CompanyPolicy::first()->id);
    }

    public function test_the_boundary_day_is_still_free_and_a_past_event_is_not(): void
    {
        $this->policy();

        $this->assertTrue(CancellationWindow::evaluate((int) $this->business->id, '2026-10-15', 100, '2026-10-01')->free);
        $this->assertFalse(CancellationWindow::evaluate((int) $this->business->id, '2026-10-14', 100, '2026-10-01')->free);

        $past = CancellationWindow::evaluate((int) $this->business->id, '2026-09-01', 100, '2026-10-01');
        $this->assertFalse($past->free);
        $this->assertLessThan(0, $past->availableDays);
        $this->assertSame(25.0, $past->feeAmount);
    }

    public function test_without_a_written_policy_nothing_is_charged(): void
    {
        $v = CancellationWindow::evaluate((int) $this->business->id, '2026-10-30', 4000, '2026-10-29');

        $this->assertNull($v->policy);
        $this->assertTrue($v->free);
        $this->assertFalse($v->requiresFee());
        $this->assertSame(1, $v->availableDays);
    }

    public function test_a_zero_fee_policy_flags_the_breach_without_an_amount(): void
    {
        $this->policy(['days' => 7, 'fee_percent' => 0]);

        $v = CancellationWindow::evaluate((int) $this->business->id, '2026-10-30', 4000, '2026-10-28');

        $this->assertFalse($v->free);
        $this->assertFalse($v->requiresFee());
        $this->assertSame(0.0, $v->feeAmount);
    }

    public function test_without_an_event_date_the_window_is_not_measured(): void
    {
        $this->policy();

        $v = CancellationWindow::evaluate((int) $this->business->id, null, 4000);

        $this->assertTrue($v->free);
        $this->assertNull($v->availableDays);
        $this->assertStringContainsString('لا موعد', $v->message());
    }

    public function test_inactive_or_future_or_scoped_policies_are_not_read_as_the_general_one(): void
    {
        $this->policy(['days' => 30, 'fee_percent' => 50], ['active' => false]);
        $this->policy(['days' => 30, 'fee_percent' => 50], ['effective_from' => now()->addYear()->toDateString()]);
        $this->policy(['days' => 30, 'fee_percent' => 50], ['scope_type' => CompanyPolicy::SCOPE_CUSTOMER, 'scope_id' => 1]);

        $this->assertNull(CancellationWindow::policy((int) $this->business->id));

        $other = Business::create(['name' => 'أخرى', 'type' => 'عام', 'status' => 'نشط']);
        CompanyPolicy::create([
            'business_id' => $other->id, 'key' => CancellationWindow::KEY, 'params' => ['days' => 30, 'fee_percent' => 50],
            'text_ar' => 'x', 'active' => true,
        ]);
        $this->assertNull(CancellationWindow::policy((int) $this->business->id), 'سياسةُ شركةٍ أخرى لا تُقرأ');

        $mine = $this->policy();
        $this->assertSame($mine->id, CancellationWindow::policy((int) $this->business->id)?->id);
    }
}
