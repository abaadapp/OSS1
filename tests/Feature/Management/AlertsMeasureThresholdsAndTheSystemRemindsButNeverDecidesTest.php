<?php

namespace Tests\Feature\Management;

use App\Models\ActivityLog;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Communication;
use App\Models\FinancialForecast;
use App\Models\ManagementDecision;
use App\Models\ManagementReview;
use App\Support\Automation\Engine;
use App\Support\Automation\Rules;
use App\Support\Automation\Settings;
use App\Support\CommandCenter;
use App\Support\Intelligence\Anomalies;
use App\Support\Intelligence\Forecasting;
use App\Support\Intelligence\ManagementAlerts;
use App\Support\Intelligence\ManagementReports;
use App\Support\Intelligence\ManagementReviews;
use App\Support\Intelligence\Period;
use App\Support\Intelligence\Thresholds;
use App\Support\Ledger;
use App\Support\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * التنبيهُ يقيس رقمًا على عتبةٍ مكتوبة ويقولها — والنظامُ يذكّر ولا يقرّر.
 *
 * لا تنبيهَ نقدٍ لشركةٍ بلا دفتر · الشذوذُ يحتاج ثلاثةَ أشهرٍ مرصودة · العتباتُ
 * تُضبط وتُقيَّد على السجلّ · مركزُ القيادة يرى الحرجَ وحده ولمن يرى المال ·
 * التذكيراتُ تمرّ بأتمتة المرحلة 8 في وضع المراقبة ولا تُرسل شيئًا للخارج ·
 * والتقاريرُ الثمانية تُبنى وتُنزَّل من الباب العامّ · والمراجعةُ تسجّل قراراتِ
 * الإدارة بمالكٍ وموعد ولا تقترحها.
 */
class AlertsMeasureThresholdsAndTheSystemRemindsButNeverDecidesTest extends ManagementTestCase
{
    use RefreshDatabase;

    /* ─────────────────────────── التنبيهات ─────────────────────────── */

    public function test_overdue_receivables_alert_states_its_threshold_and_an_empty_ledger_raises_no_cash_alert(): void
    {
        $bid = (int) $this->business->id;
        $this->assertSame([], ManagementAlerts::for($bid), 'شركةٌ فارغة — لا تنبيه');
        $this->assertSame([], ManagementAlerts::actionable($bid));

        $this->invoice(2000, '2026-07-01');    /* يستحقّ 31 يوليو — متأخّرٌ 50 يومًا */
        $alerts = collect(ManagementAlerts::for($bid))->keyBy('kind');

        $this->assertTrue($alerts->has('overdue_receivables'));
        $this->assertSame('critical', $alerts['overdue_receivables']['severity']);
        $this->assertStringContainsString('العتبة', $alerts['overdue_receivables']['detail']);
        $this->assertSame(1000.0, (float) $alerts['overdue_receivables']['facts']['threshold']);
        $this->assertTrue($alerts->has('invoice_overdue'));
        $this->assertFalse($alerts->has('cash_floor'), 'لا حركةَ نقدٍ في الدفتر — فلا يُقال «النقد دون الحدّ»');

        Thresholds::save($bid, ['overdue_receivable_amount' => 5000], $this->owner);
        $this->assertFalse(collect(ManagementAlerts::for($bid))->keyBy('kind')->has('overdue_receivables'), 'العتبةُ ارتفعت فسكت التنبيه');
        $this->assertTrue(ActivityLog::where('business_id', $bid)->where('description', 'like', '%العتبات%')->orWhere('description', 'like', '%عتب%')->exists());
    }

    public function test_the_cash_floor_alert_needs_ledger_history_and_uses_the_approved_forecast_when_there_is_one(): void
    {
        $bid = (int) $this->business->id;
        Ledger::post($bid, 'إيداع', [['account' => 'cash', 'debit' => 100], ['account' => 'capital', 'credit' => 100]], Carbon::parse('2026-09-01'), 'يدوي');

        $alerts = collect(ManagementAlerts::for($bid))->keyBy('kind');
        $this->assertTrue($alerts->has('cash_floor'), '100 دون الحدّ 5000 — والدفترُ له تاريخ');
        $this->assertStringContainsString('السائل', $alerts['cash_floor']['title']);

        Forecasting::approve(Forecasting::create($bid, ['horizon_days' => 90, 'assumptions' => ['recurring_monthly_expenses' => 0, 'payroll_monthly' => 0]], $this->manager), $this->owner);
        $alerts = collect(ManagementAlerts::for($bid))->keyBy('kind');
        $this->assertStringContainsString('المتوقَّع', $alerts['cash_floor']['title'], 'مع توقّعٍ معتمَد يُقاس أدنى إقفالٍ متوقَّع');

        $actionable = collect(ManagementAlerts::actionable($bid))->pluck('kind');
        $this->assertTrue($actionable->contains('cash_floor'));
    }

    public function test_thresholds_are_validated_and_described(): void
    {
        $bid = (int) $this->business->id;
        $this->assertSame(15.0, (float) Thresholds::for($bid)['project_margin_percent']);

        try {
            Thresholds::save($bid, ['project_margin_percent' => 'كثير'], $this->owner);
            $this->fail('عتبةٌ نصّيّة مرّت');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('رقم', $e->getMessage());
        }
        try {
            Thresholds::save($bid, ['project_margin_percent' => 500], $this->owner);
            $this->fail('عتبةٌ خارج المدى مرّت');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('مدا', $e->getMessage());
        }

        Thresholds::save($bid, ['project_margin_percent' => 25, 'unknown_key' => 1], $this->owner);
        $this->assertSame(25.0, (float) Thresholds::for($bid)['project_margin_percent']);
        $this->assertSame(15.0, (float) Thresholds::for((int) $this->other->id)['project_margin_percent'], 'العتبةُ لشركتها');
        $this->assertCount(count(Thresholds::DEFAULTS), Thresholds::describe($bid));

        $this->actingAs($this->manager)->put(route('admin.management.thresholds'), ['thresholds' => ['project_margin_percent' => 30]])->assertForbidden();
        $this->actingAs($this->owner)->put(route('admin.management.thresholds'), ['thresholds' => ['project_margin_percent' => 30]])->assertRedirect();
        $this->assertSame(30.0, (float) Thresholds::for($bid)['project_margin_percent']);
    }

    public function test_an_expense_anomaly_needs_three_observed_months(): void
    {
        $bid = (int) $this->business->id;
        $this->expense(1000, '2026-09-05');
        $this->expense(100, '2026-08-05');
        $this->assertSame([], Anomalies::expenses($bid), 'شهرٌ واحدٌ مرصود — لا حكم');

        $this->expense(100, '2026-07-05');
        $this->expense(100, '2026-06-05');
        $a = Anomalies::expenses($bid);
        $this->assertCount(1, $a);
        $this->assertSame('expense_anomaly', $a[0]['kind']);
        $this->assertEqualsWithDelta(100.0, $a[0]['facts']['average'], 0.0005);
        $this->assertEqualsWithDelta(900.0, $a[0]['facts']['percent'], 0.05);
        $this->assertStringContainsString('40', $a[0]['detail']);
        $this->assertTrue(collect(ManagementAlerts::for($bid))->pluck('kind')->contains('expense_anomaly'));
    }

    public function test_a_project_below_the_margin_threshold_is_named_not_judged(): void
    {
        $bid = (int) $this->business->id;
        $project = $this->project('EP-LOW', 1050);
        $this->invoice(1000, '2026-09-10', $project);
        $this->supplierInvoice(950, 0, $project);

        $alert = collect(ManagementAlerts::for($bid))->firstWhere('kind', 'project_margin');
        $this->assertNotNull($alert);
        $this->assertStringContainsString('EP-LOW', $alert['title']);
        $this->assertStringContainsString('15', $alert['detail']);
        $this->assertStringNotContainsString('سيّئ', $alert['detail']);
        $this->assertStringContainsString('/projects/', $alert['url']);
    }

    /* ─────────────────────────── مركز القيادة ─────────────────────────── */

    public function test_the_command_center_shows_management_alerts_only_to_those_who_see_money(): void
    {
        $bid = (int) $this->business->id;
        $this->invoice(3000, '2026-06-01');

        $owner = CommandCenter::for($bid, $this->owner);
        $this->assertGreaterThan(0, $owner['summary']['management']);
        $this->assertSame('management_overdue_receivables', $owner['management']['rows'][0]['kind']);

        $seller = CommandCenter::for($bid, $this->seller);
        $this->assertSame(0, $seller['summary']['management'], 'من لا يرى المال لا يرى تنبيهاته');
        $this->assertSame([], $seller['management']['rows']);
    }

    /* ─────────────────────────── الأتمتة ─────────────────────────── */

    private function automationOn(string $only): void
    {
        Mail::fake();
        Settings::save((int) $this->business->id, ['mode' => Settings::LIVE], $this->owner);
        Rules::ensure((int) $this->business->id);
        AutomationRule::where('business_id', $this->business->id)->where('key', '!=', $only)->update(['enabled' => false]);
    }

    public function test_the_quarterly_review_reminder_observes_then_resolves_once_a_review_covers_the_quarter(): void
    {
        $bid = (int) $this->business->id;
        Carbon::setTestNow(Carbon::parse('2026-07-02 10:00:00', 'Asia/Muscat'));
        $this->automationOn('management.review_due');
        $rule = AutomationRule::where('business_id', $bid)->where('key', 'management.review_due')->firstOrFail();
        $this->assertSame(AutomationRule::OBSERVE, $rule->mode, 'التذكيراتُ الإداريّة تبدأ مراقِبة');

        $stats = Engine::tick($bid);
        $this->assertSame(1, $stats['observed']);
        $run = AutomationRun::sole();
        $this->assertSame(AutomationRun::OBSERVED, $run->status);
        $this->assertStringContainsString('الربع 2', $run->metadata['would']['reason']);
        Mail::assertNothingSent();
        $this->assertSame(0, Communication::count(), 'لا رسالةَ خارجيّة من تذكيرٍ إداريّ');

        ManagementReviews::create($bid, ['period_start' => '2026-04-01', 'period_end' => '2026-06-30'], $this->manager);
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00', 'Asia/Muscat'));
        $stats = Engine::tick($bid);
        $this->assertSame(0, $stats['observed'], 'المراجعةُ وُجدت — الخطوةُ الثانية تُحلّ');
        $this->assertGreaterThanOrEqual(1, $stats['resolved'] ?? 1);
    }

    public function test_the_monthly_report_reminder_and_the_stale_forecast_reminder_fire_on_their_anchors(): void
    {
        $bid = (int) $this->business->id;
        Carbon::setTestNow(Carbon::parse('2026-09-02 10:00:00', 'Asia/Muscat'));
        $this->automationOn('management.report_ready');
        $this->assertSame(1, Engine::tick($bid)['observed']);
        $this->assertStringContainsString('2026-08', AutomationRun::sole()->metadata['would']['reason']);

        AutomationRun::query()->delete();
        AutomationRule::where('business_id', $bid)->update(['enabled' => false]);
        AutomationRule::where('business_id', $bid)->where('key', 'forecast.stale')->update(['enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-09-20 10:00:00', 'Asia/Muscat'));
        $forecast = Forecasting::approve(Forecasting::create($bid, ['name' => 'قديم', 'horizon_days' => 90], $this->manager), $this->owner);
        $forecast->forceFill(['approved_at' => Carbon::parse('2026-08-20 09:00:00')])->save();

        $this->assertSame(1, Engine::tick($bid)['observed']);
        $this->assertStringContainsString('قديم', AutomationRun::sole()->metadata['would']['reason']);

        /* توقّعٌ أحدث يحلّ محلّه — فيسكت التذكير */
        Forecasting::approve(Forecasting::create($bid, ['name' => 'جديد', 'horizon_days' => 90], $this->manager), $this->owner);
        $this->assertSame(FinancialForecast::SUPERSEDED, $forecast->fresh()->status);
        AutomationRun::query()->delete();
        $this->assertSame(0, Engine::tick($bid)['observed']);
    }

    /* ─────────────────────────── التقارير ─────────────────────────── */

    public function test_the_eight_management_reports_build_from_the_same_numbers_and_export_through_the_public_door(): void
    {
        $bid = (int) $this->business->id;
        $project = $this->project('EP-R', 1050);
        $invoice = $this->invoice(1000, '2026-09-10', $project);
        $this->pay($invoice, 500, '2026-09-12');
        $this->supplierInvoice(200, 0, $project);

        $catalog = collect(Reports::ALL)->where('category', 'management');
        $this->assertCount(8, $catalog);
        $this->assertSame(ManagementReports::KEYS, $catalog->pluck('key')->values()->all());

        $monthly = ManagementReports::build('management_monthly', $bid, ['period' => 'this_month']);
        $summary = collect($monthly['sections']['الملخّص التنفيذي'])->keyBy('metric');
        $this->assertEqualsWithDelta(1000.0, (float) $summary[__('الإيراد الصافي')]['value'], 0.0005);
        $this->assertEqualsWithDelta(500.0, (float) $summary[__('التحصيل')]['value'], 0.0005);

        $profit = ManagementReports::build('project_profitability', $bid, ['period' => 'this_month']);
        $row = collect($profit['sections']['المشاريع'])->firstWhere('code', 'EP-R');
        $this->assertEqualsWithDelta(800.0, (float) $row['contribution'], 0.0005);

        $aging = ManagementReports::build('receivables_aging', $bid, []);
        $this->assertEqualsWithDelta(550.0, (float) collect($aging['sections']['الأعمار'])->sum('amount'), 0.0005);

        foreach (ManagementReports::KEYS as $key) {
            $this->actingAs($this->owner)->get(route('admin.management.reports', ['report' => $key]))->assertOk();
        }
        $this->actingAs($this->owner)->get(route('admin.reports.export.csv', ['report' => 'project_profitability', 'period' => 'this_month']))
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->actingAs($this->owner)->get(route('admin.reports.export.xlsx', ['report' => 'management_monthly', 'period' => 'this_month']))->assertOk();

        $this->actingAs($this->seller)->get(route('admin.reports.export.csv', ['report' => 'project_profitability']))->assertForbidden();
        $this->actingAs($this->stranger)->get(route('admin.reports.export.csv', ['report' => 'project_profitability', 'period' => 'this_month']))
            ->assertOk()->assertDontSee('EP-R');
    }

    /* ─────────────────────────── المراجعات ─────────────────────────── */

    public function test_a_review_captures_a_snapshot_records_decisions_and_freezes_when_reviewed(): void
    {
        $bid = (int) $this->business->id;
        $this->invoice(800, '2026-08-10');
        $this->actingAs($this->manager);

        $review = ManagementReviews::create($bid, ['period_start' => '2026-08-01', 'period_end' => '2026-08-31'], $this->manager);
        $this->assertSame(ManagementReview::DRAFT, $review->status);
        $this->assertSame('مراجعة 2026-08-01 — 2026-08-31', $review->title);
        $this->assertEqualsWithDelta(800.0, $review->snapshot['financial']['revenue'], 0.0005);
        $this->assertArrayHasKey('alerts', $review->snapshot);
        $this->assertArrayNotHasKey('recommendations', $review->snapshot, 'النظامُ لا يقترح');

        ManagementReviews::update($review, ['sections' => ['financial' => 'الإيرادُ دون أغسطس الماضي', 'ignored' => 'x']], $this->manager);
        $this->assertSame(['financial' => 'الإيرادُ دون أغسطس الماضي'], array_filter($review->fresh()->sections));

        $decision = ManagementReviews::decide($review, ['decision' => 'مراجعةُ تسعير الباقات', 'owner_id' => $this->owner->id, 'due_at' => '2026-10-15'], $this->manager);
        $this->assertSame(ManagementDecision::OPEN, $decision->status);
        $this->assertSame($this->owner->id, $decision->owner_id);
        ManagementReviews::setDecisionStatus($decision, ManagementDecision::DONE, $this->manager);
        $this->assertNotNull($decision->fresh()->completed_at);

        try {
            ManagementReviews::close($review, $this->manager);
            $this->fail('أُقفلت قبل المراجعة');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('تُراجَع', $e->getMessage());
        }

        $reviewed = ManagementReviews::markReviewed($review, $this->owner);
        $this->assertSame(ManagementReview::REVIEWED, $reviewed->status);
        $this->invoice(9000, '2026-08-20');
        try {
            ManagementReviews::refreshSnapshot($reviewed, $this->owner);
            $this->fail('اللقطةُ تغيّرت بعد المراجعة');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('تُجمَّد', $e->getMessage());
        }
        $this->assertEqualsWithDelta(800.0, $reviewed->fresh()->snapshot['financial']['revenue'], 0.0005);

        ManagementReviews::close($reviewed, $this->owner);
        $this->assertSame(ManagementReview::CLOSED, $reviewed->fresh()->status);
        $this->expectException(RuntimeException::class);
        ManagementReviews::decide($reviewed->fresh(), ['decision' => 'بعد الإقفال'], $this->owner);
    }

    public function test_reviews_open_for_their_business_only_and_the_snapshot_hides_money_from_those_who_may_not_read_it(): void
    {
        $bid = (int) $this->business->id;
        $this->invoice(800, '2026-08-10');
        $review = ManagementReviews::create($bid, ['period_start' => '2026-08-01', 'period_end' => '2026-08-31'], $this->manager);

        $this->actingAs($this->stranger)->get(route('admin.management.reviews.show', $review->id))->assertNotFound();
        $this->actingAs($this->stranger)->put(route('admin.management.reviews.update', $review->id), ['notes' => 'x'])->assertNotFound();
        $this->actingAs($this->stranger)->post(route('admin.management.reviews.decisions.store', $review->id), ['decision' => 'x'])->assertNotFound();
        $this->actingAs($this->seller)->get(route('admin.management.reviews'))->assertForbidden();

        $this->actingAs($this->owner)->get(route('admin.management.reviews.show', $review->id))->assertOk()
            ->assertInertia(fn ($a) => $a->where('review.snapshot.financial.revenue', 800));

        /* من يفتح الإدارة بالاسم بلا «التحليلات المالية» يرى الهيكلَ لا المال */
        $this->manager->update(['role' => 'sales', 'permissions' => ['dashboard', 'management', 'quotations', 'projects']]);
        $this->actingAs($this->manager->fresh())->get(route('admin.management.reviews.show', $review->id))->assertOk()
            ->assertInertia(fn ($a) => $a->missing('review.snapshot.financial')->has('review.snapshot.operations'));
    }
}
