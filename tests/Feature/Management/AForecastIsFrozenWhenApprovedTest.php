<?php

namespace Tests\Feature\Management;

use App\Models\FinancialForecast;
use App\Models\FinancialScenario;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\Intelligence\CashIntelligence;
use App\Support\Intelligence\Forecasting;
use App\Support\Intelligence\ScenarioPlanning;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * التوقّعُ النقديّ يُجمَّد حين يُعتمد — والسيناريو محاكاةٌ لا تمسّ قيدًا.
 *
 * الافتتاحيُّ من الدفتر · الملتزَمُ مستنداتٌ قائمة بمصادرها · المتوقَّعُ افتراضاتٌ
 * مكتوبة تُغيَّر بيد · السيناريو وخطُّ الأنابيب يُقالان جنبَ الرقم ولا يدخلان
 * الإقفال · اللقطةُ المعتمَدة لا تتغيّر حين تتغيّر الدفاتر · المعتمَدُ لا يُحذف ·
 * والمقارنةُ بالواقع تنتظر شهرًا منقضيًا · والمدرجُ يعترف حين لا يكفي التاريخ.
 */
class AForecastIsFrozenWhenApprovedTest extends ManagementTestCase
{
    use RefreshDatabase;

    private function cashIn(float $amount, string $at): void
    {
        Ledger::post((int) $this->business->id, 'إيداعٌ افتتاحي', [['account' => 'cash', 'debit' => $amount], ['account' => 'capital', 'credit' => $amount]], Carbon::parse($at), 'يدوي');
    }

    public function test_the_projection_opens_from_the_ledger_and_lists_every_committed_item_with_its_source(): void
    {
        $bid = (int) $this->business->id;
        $this->cashIn(5000, '2026-09-01');
        $invoice = $this->invoice(1000, '2026-09-10');            /* يستحقّ 10 أكتوبر — 1050 داخل */
        $this->supplierInvoice(400, 0, null, '2026-09-08');       /* 400 خارج */
        $this->expense(75, '2026-09-09', null, 'معلّق');          /* 75 خارج — غير مدفوع */

        $p = CashIntelligence::project($bid, 90, ['recurring_monthly_expenses' => 0, 'payroll_monthly' => 0]);

        $this->assertEqualsWithDelta(5000.0, $p['opening_cash'], 0.0005);
        $this->assertSame('2026-09-20', $p['base_date']);
        $this->assertEqualsWithDelta(1050.0, $p['totals']['inflows']['committed'], 0.0005);
        $this->assertEqualsWithDelta(475.0, $p['totals']['outflows']['committed'], 0.0005);
        $this->assertEqualsWithDelta(5575.0, $p['totals']['closing'], 0.0005);
        $this->assertSame($p['totals']['closing'], $p['totals']['closing_with_scenarios'], 'بلا سيناريو الرقمان واحد');

        $items = collect($p['months'])->flatMap(fn ($m) => $m['items']);
        $inv = $items->firstWhere('source.type', 'customer_invoice');
        $this->assertNotNull($inv);
        $this->assertSame($invoice->id, $inv['source']['id']);
        $this->assertSame('2026-10-10', $inv['at']);
        $this->assertStringContainsString('customer-invoices', $inv['source']['url']);
        $this->assertSame('committed', $inv['category']);
        $this->assertContains('expense', $items->pluck('source.type')->all());
    }

    public function test_assumptions_are_explicit_editable_and_labelled_by_source(): void
    {
        $bid = (int) $this->business->id;
        $defaults = CashIntelligence::defaultAssumptions($bid);

        $this->assertSame(0, (int) $defaults['collection_delay_days']);
        $this->assertFalse((bool) $defaults['include_pipeline']);
        $this->assertArrayHasKey('recurring_source', $defaults);

        $p = CashIntelligence::project($bid, 60, ['recurring_monthly_expenses' => 300, 'payroll_monthly' => 1000, 'collection_delay_days' => 15]);
        $this->assertEqualsWithDelta(300.0, (float) $p['assumptions']['recurring_monthly_expenses'], 0.0005);
        $this->assertSame(15, (int) $p['assumptions']['collection_delay_days']);
        $this->assertStringContainsString('بيد', (string) $p['assumptions']['recurring_source']);
        $this->assertGreaterThan(0, $p['totals']['outflows']['expected'], 'المتوقَّع من الافتراضات لا من مستند');
        $this->assertEqualsWithDelta(0.0, $p['totals']['outflows']['committed'], 0.0005);
    }

    public function test_the_collection_delay_moves_a_committed_invoice_later(): void
    {
        $bid = (int) $this->business->id;
        $invoice = $this->invoice(1000, '2026-09-10');
        $late = CashIntelligence::project($bid, 90, ['recurring_monthly_expenses' => 0, 'payroll_monthly' => 0, 'collection_delay_days' => 25]);
        $item = collect($late['months'])->flatMap(fn ($m) => $m['items'])->firstWhere('source.id', $invoice->id);

        $this->assertSame('2026-11-04', $item['at'], '10 أكتوبر + 25');
    }

    public function test_a_scenario_and_the_pipeline_are_shown_beside_the_number_and_never_inside_the_closing(): void
    {
        $bid = (int) $this->business->id;
        $this->cashIn(1000, '2026-09-01');
        ScenarioPlanning::ensure($bid);
        $custom = ScenarioPlanning::create($bid, ['name' => 'شراء شاحنة', 'assumptions' => [
            ['key' => 'one_time_outflow', 'label' => 'شاحنة', 'amount' => 700, 'starts_on' => '2026-10-05'],
            ['key' => 'monthly_inflow', 'label' => 'عقدُ صيانة', 'amount' => 100, 'starts_on' => '2026-10-01', 'months' => 2],
        ]], $this->manager);

        $q = \App\Support\Quotations::create($bid, $this->customer, ['title' => 'عرضٌ مفتوح', 'issued_at' => '2026-09-15', 'valid_until' => '2026-10-15'],
            [['description' => 'تنظيم', 'quantity' => 1, 'unit_price' => 2000]], $this->owner);
        \App\Support\Quotations::send($q, $this->owner);

        $plain = CashIntelligence::project($bid, 90, ['recurring_monthly_expenses' => 0, 'payroll_monthly' => 0]);
        $with = CashIntelligence::project($bid, 90, ['recurring_monthly_expenses' => 0, 'payroll_monthly' => 0, 'include_pipeline' => true, 'pipeline_conversion_percent' => 50], $custom);

        $this->assertEqualsWithDelta(1000.0, $plain['totals']['closing'], 0.0005);
        $this->assertEqualsWithDelta(1000.0, $with['totals']['closing'], 0.0005, 'الإقفالُ لا يتغيّر بسيناريو');
        $this->assertEqualsWithDelta(700.0, $with['totals']['outflows']['scenario'], 0.0005);
        $this->assertEqualsWithDelta(200.0, $with['totals']['inflows']['scenario'], 0.0005);
        $this->assertEqualsWithDelta(1050.0, $with['totals']['inflows']['pipeline'], 0.0005, '2100 بضريبته × 50٪');
        $this->assertEqualsWithDelta(1000.0 + 200.0 + 1050.0 - 700.0, $with['totals']['closing_with_scenarios'], 0.0005);
        $this->assertEqualsWithDelta(0.0, $plain['totals']['inflows']['pipeline'], 0.0005, 'خطُّ الأنابيب لا يدخل إلّا بطلب');
        $this->assertSame('شراء شاحنة', $with['scenario']['name']);

        $this->assertSame(0, JournalEntry::where('business_id', $bid)->where('description', 'like', '%شاحنة%')->count(), 'لا قيدَ من سيناريو');
        $this->assertSame(3, FinancialScenario::where('business_id', $bid)->where('kind', '!=', FinancialScenario::CUSTOM)->count(), 'الأساسُ والمتحفّظُ والنموّ يُبذرون');
    }

    public function test_an_approved_forecast_is_a_frozen_snapshot_and_supersedes_its_predecessor(): void
    {
        $bid = (int) $this->business->id;
        $this->cashIn(2000, '2026-09-01');
        $first = Forecasting::create($bid, ['name' => 'خريف', 'horizon_days' => 90, 'assumptions' => ['recurring_monthly_expenses' => 0, 'payroll_monthly' => 0]], $this->manager);

        $this->assertSame(FinancialForecast::DRAFT, $first->status);
        $this->assertEqualsWithDelta(2000.0, $first->opening_cash, 0.0005);

        try {
            Forecasting::approve($first, $this->manager);
            $this->fail('المديرُ اعتمد بلا صلاحيّة');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('صلاحيّة', $e->getMessage());
        }
        $approved = Forecasting::approve($first, $this->owner);
        $this->assertSame(FinancialForecast::APPROVED, $approved->status);
        $frozenTotals = $approved->totals;

        /* الدفاترُ تتغيّر — فاتورةٌ جديدة — واللقطةُ لا */
        $this->invoice(5000, '2026-09-18');
        $this->assertSame($frozenTotals, $approved->fresh()->totals);
        $this->assertEqualsWithDelta(2000.0, $approved->fresh()->totals['closing'], 0.0005);

        try {
            Forecasting::discard($approved->fresh(), $this->owner);
            $this->fail('المعتمَد حُذف');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('لا يُحذف', $e->getMessage());
        }

        $second = Forecasting::approve(Forecasting::create($bid, ['name' => 'خريف 2', 'horizon_days' => 90], $this->manager), $this->owner);
        $this->assertSame(FinancialForecast::SUPERSEDED, $approved->fresh()->status, 'الأفقُ نفسُه — يحلّ محلّه');
        $this->assertSame(FinancialForecast::APPROVED, $second->status);
        $this->assertNotNull($approved->fresh()->superseded_at);

        $other = Forecasting::approve(Forecasting::create($bid, ['name' => 'سنة', 'horizon_days' => 365], $this->manager), $this->owner);
        $this->assertSame(FinancialForecast::APPROVED, $second->fresh()->status, 'أفقٌ آخر لا يزاحم');
        $this->assertSame(FinancialForecast::APPROVED, $other->status);

        $this->expectException(RuntimeException::class);
        Forecasting::create($bid, ['horizon_days' => 45], $this->manager);
    }

    public function test_forecast_versus_actual_waits_for_an_elapsed_month_then_reads_the_ledger(): void
    {
        $bid = (int) $this->business->id;
        $this->cashIn(1000, '2026-08-01');
        $forecast = Forecasting::approve(Forecasting::create($bid, ['horizon_days' => 90, 'assumptions' => ['recurring_monthly_expenses' => 0, 'payroll_monthly' => 0]], $this->manager), $this->owner);

        $va = Forecasting::versusActual($forecast);
        $this->assertSame('insufficient_data', $va['accuracy']['status']);
        $this->assertSame('pending', $va['rows'][0]['status']);

        /* ينقضي سبتمبر — ويدخل 300 ويخرج 120 فعلًا */
        $this->cashIn(300, '2026-09-25');
        Ledger::post($bid, 'إيجار', [['account' => 'rent', 'debit' => 120], ['account' => 'cash', 'credit' => 120]], Carbon::parse('2026-09-26'), 'يدوي');
        Carbon::setTestNow(Carbon::parse('2026-10-03 10:00:00', 'Asia/Muscat'));

        $va = Forecasting::versusActual($forecast->fresh());
        $sept = collect($va['rows'])->firstWhere('month', '2026-09');
        $this->assertSame('elapsed', $sept['status']);
        $this->assertEqualsWithDelta(300.0, $sept['actual']['inflows'], 0.0005);
        $this->assertEqualsWithDelta(120.0, $sept['actual']['outflows'], 0.0005);
        $this->assertEqualsWithDelta(1180.0, $sept['actual']['closing'], 0.0005);
        $this->assertSame('ok', $va['accuracy']['status']);
        $this->assertSame(1, $va['elapsed_months']);
    }

    public function test_runway_needs_three_full_months_and_names_its_definition(): void
    {
        $bid = (int) $this->business->id;
        $this->cashIn(9000, '2026-05-05');
        $r = CashIntelligence::runway($bid);
        $this->assertSame('insufficient_data', $r['status']);
        $this->assertStringContainsString('ثلاثة', $r['definition']);

        foreach (['2026-06-20', '2026-07-20', '2026-08-20'] as $at) {
            Ledger::post($bid, 'إيجار', [['account' => 'rent', 'debit' => 1000], ['account' => 'cash', 'credit' => 1000]], Carbon::parse($at), 'يدوي');
        }
        $r = CashIntelligence::runway($bid);
        $this->assertSame('ok', $r['status']);
        $this->assertEqualsWithDelta(6000.0, $r['liquid'], 0.0005);
        $this->assertEqualsWithDelta(-1000.0, $r['average_net'], 0.0005);
        $this->assertEqualsWithDelta(6.0, $r['runway_months'], 0.05, '6000 ÷ 1000 شهريًّا');
    }

    public function test_runway_is_cash_positive_when_the_company_does_not_burn(): void
    {
        $bid = (int) $this->business->id;
        foreach (['2026-06-20', '2026-07-20', '2026-08-20'] as $at) {
            $this->cashIn(500, $at);
            Ledger::post($bid, 'إيجار', [['account' => 'rent', 'debit' => 100], ['account' => 'cash', 'credit' => 100]], Carbon::parse($at), 'يدوي');
        }
        $this->assertSame('cash_positive', CashIntelligence::runway($bid)['status']);
    }

    public function test_scenarios_and_forecasts_stay_inside_their_business(): void
    {
        $bid = (int) $this->business->id;
        $scenario = ScenarioPlanning::create($bid, ['name' => 'خاصّ'], $this->manager);
        $forecast = Forecasting::create($bid, ['horizon_days' => 30], $this->manager);

        try {
            Forecasting::create((int) $this->other->id, ['horizon_days' => 30, 'scenario_id' => $scenario->id], $this->stranger);
            $this->fail('سيناريو شركةٍ أخرى مرّ');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('شركتك', $e->getMessage());
        }

        $this->actingAs($this->stranger)->post(route('admin.management.forecasts.approve', $forecast->id))->assertNotFound();
        $this->actingAs($this->stranger)->delete(route('admin.management.forecasts.destroy', $forecast->id))->assertNotFound();
        $this->actingAs($this->stranger)->put(route('admin.management.scenarios.update', $scenario->id), ['name' => 'اختراق'])->assertNotFound();
        $this->actingAs($this->stranger)->get(route('admin.management.cash', ['forecast' => $forecast->id]))->assertOk();
        $this->assertSame(FinancialForecast::DRAFT, $forecast->fresh()->status);
        $this->assertSame('خاصّ', $scenario->fresh()->name);

        $this->actingAs($this->accountant)->get(route('admin.management.cash'))->assertOk();
        $this->actingAs($this->accountant)->post(route('admin.management.forecasts.store'), ['horizon_days' => 30])->assertForbidden();
        $this->actingAs($this->seller)->get(route('admin.management.cash'))->assertForbidden();
        $this->actingAs($this->manager)->post(route('admin.management.forecasts.store'), ['name' => 'من الشاشة', 'horizon_days' => 60])->assertRedirect();
        $this->assertSame(2, FinancialForecast::where('business_id', $bid)->count());
        $this->assertSame(0, JournalLine::query()->count(), 'لا قيدَ من توقّع');
    }
}
