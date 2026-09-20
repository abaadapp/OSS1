<?php

namespace Tests\Feature\Management;

use App\Models\CustomerInvoice;
use App\Support\CustomerInvoices;
use App\Support\Intelligence\FinancialPerformance;
use App\Support\Intelligence\Period;
use App\Support\Intelligence\RevenueIntelligence;
use App\Support\Quotations;
use App\Support\Receivables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * الدفترُ يجيب بحقيقةٍ واحدة — الإيرادُ فواتيرُ صادرة صافية، والتحصيلُ دفعاتٌ قُبضت،
 * والتكلفةُ سنداتٌ معتمَدة، والمصروفُ ما دُفع — وكلٌّ بتاريخه ومدّته.
 *
 * ═══ ما يُفحص ═══
 *
 * الفاتورةُ الملغاة لا تُحسب · إشعارُ الدائن ينقص الصافي · الدفعةُ الجزئيّة
 * تحصيلٌ لا إيراد · حدودُ المدّة نصفُ مفتوحة بتوقيت الشركة · المدّةُ السابقة
 * بلا أساسٍ تقول «لا بيانات كافية» لا «+100٪» · الضريبةُ تُطرح ممّن يستردّها ·
 * وأعمارُ الذمم على حدودها · وسلّمُ الإيراد خمسُ درجاتٍ لا تُجمع · والمسودّةُ
 * في القمع ليست التزامًا · والأرقامُ إلى ثلاث منازل.
 */
class TheBooksAnswerWithOneTruthTest extends ManagementTestCase
{
    use RefreshDatabase;

    private function period(string $key = 'this_month', ?string $from = null, ?string $to = null): Period
    {
        return Period::resolve((int) $this->business->id, $key, $from, $to);
    }

    /* ─────────────────────────── الإيراد ─────────────────────────── */

    public function test_revenue_is_issued_invoices_net_of_vat_and_a_cancelled_invoice_does_not_count(): void
    {
        $this->invoice(1000, '2026-09-10');
        $this->invoice(250.5, '2026-09-15');
        $cancelled = $this->invoice(9999, '2026-09-16');
        CustomerInvoices::cancel($cancelled, 'خطأ', $this->owner->id);

        $s = FinancialPerformance::snapshot((int) $this->business->id, $this->period());

        $this->assertEqualsWithDelta(1250.5, $s['revenue']['net'], 0.0005);
        $this->assertEqualsWithDelta(62.525, $s['revenue']['tax'], 0.0005, 'ضريبةُ 5٪ على الصافي');
        $this->assertEqualsWithDelta(1313.025, $s['revenue']['gross'], 0.0005);
        $this->assertSame(2, $s['revenue']['invoices']);
        $this->assertSame('accrual_documents', $s['direct_costs']['basis']);
    }

    public function test_a_credit_note_reduces_net_revenue_but_never_rewrites_the_invoice(): void
    {
        $invoice = $this->invoice(1000, '2026-09-10');
        CustomerInvoices::creditNote($invoice, 210, 10, 'نقصُ النطاق', $this->owner->id);

        $s = FinancialPerformance::snapshot((int) $this->business->id, $this->period());

        $this->assertEqualsWithDelta(1050.0, (float) $invoice->fresh()->total, 0.0005, 'الفاتورةُ كما صدرت');
        $this->assertEqualsWithDelta(210.0, $s['revenue']['credited'], 0.0005, 'الإشعارُ بإجماليّه كما كُتب');
        $this->assertEqualsWithDelta(800.0, $s['revenue']['net'], 0.0005, 'والصافي ينقص بوعائه (200) لا بإجماليّه');
        $this->assertEqualsWithDelta(40.0, $s['revenue']['tax'], 0.0005);
    }

    public function test_a_partial_payment_is_a_collection_not_revenue(): void
    {
        $invoice = $this->invoice(1000, '2026-09-10');
        $this->pay($invoice, 400, '2026-09-12');

        $s = FinancialPerformance::snapshot((int) $this->business->id, $this->period());

        $this->assertEqualsWithDelta(1000.0, $s['revenue']['net'], 0.0005);
        $this->assertEqualsWithDelta(400.0, $s['collections']['total'], 0.0005);
        $this->assertSame(1, $s['collections']['count']);

        $this->pay($invoice, 650, '2026-09-13');
        $s = FinancialPerformance::snapshot((int) $this->business->id, $this->period());
        $this->assertEqualsWithDelta(1050.0, $s['collections']['total'], 0.0005, 'التحصيلُ بالإجمالي — الضريبةُ تُقبض');

        $r = FinancialPerformance::receivables((int) $this->business->id);
        $this->assertEqualsWithDelta(0.0, $r['outstanding'], 0.0005);
    }

    public function test_a_payment_dated_in_another_period_belongs_to_that_period(): void
    {
        $invoice = $this->invoice(1000, '2026-08-20');
        $this->pay($invoice, 1050, '2026-09-02');

        $august = FinancialPerformance::snapshot((int) $this->business->id, $this->period('last_month'));
        $september = FinancialPerformance::snapshot((int) $this->business->id, $this->period('this_month'));

        $this->assertEqualsWithDelta(1000.0, $august['revenue']['net'], 0.0005);
        $this->assertEqualsWithDelta(0.0, $august['collections']['total'], 0.0005);
        $this->assertEqualsWithDelta(0.0, $september['revenue']['net'], 0.0005);
        $this->assertEqualsWithDelta(1050.0, $september['collections']['total'], 0.0005);
    }

    /* ─────────────────────────── المدد ─────────────────────────── */

    public function test_period_bounds_are_half_open_on_the_company_calendar(): void
    {
        $this->invoice(100, '2026-08-31');
        $this->invoice(200, '2026-09-01');
        $this->invoice(300, '2026-09-30');
        $this->invoice(400, '2026-10-01');

        $s = FinancialPerformance::snapshot((int) $this->business->id, $this->period('this_month'));
        $this->assertEqualsWithDelta(500.0, $s['revenue']['net'], 0.0005, 'أوّلُ الشهر داخلٌ وآخرُه داخل — وما قبله وبعده خارج');

        $custom = FinancialPerformance::snapshot((int) $this->business->id, $this->period('custom', '2026-09-01', '2026-09-30'));
        $this->assertEqualsWithDelta(500.0, $custom['revenue']['net'], 0.0005, 'الحدُّ الأعلى المخصَّص شاملٌ لليوم');

        $p = $this->period('this_month');
        $this->assertSame('Asia/Muscat', $p->timezone);
        $this->assertSame('2026-09-01', $p->startDate());
        $this->assertSame('2026-10-01', $p->endDate());
    }

    public function test_the_previous_period_has_the_same_length_and_reports_insufficient_data_without_a_basis(): void
    {
        $this->invoice(1000, '2026-09-10');

        $s = FinancialPerformance::snapshot((int) $this->business->id, $this->period('this_month'));

        $this->assertSame('2026-08-01', $s['previous']['period']['from']);
        $this->assertSame('insufficient_data', $s['previous']['change']['revenue']['status']);
        $this->assertNull($s['previous']['change']['revenue']['percent'], 'لا «+100٪» من لا شيء');

        $this->invoice(500, '2026-08-10');
        $s = FinancialPerformance::snapshot((int) $this->business->id, $this->period('this_month'));
        $this->assertSame('ok', $s['previous']['change']['revenue']['status']);
        $this->assertEqualsWithDelta(100.0, $s['previous']['change']['revenue']['percent'], 0.05);
    }

    public function test_year_to_date_and_a_custom_range_are_bounded(): void
    {
        $ytd = $this->period('ytd');
        $this->assertSame('2026-01-01', $ytd->startDate());
        $this->assertSame('2026-09-21', $ytd->endDate(), 'حتى نهاية اليوم');

        $this->expectException(RuntimeException::class);
        $this->period('custom', '2020-01-01', '2026-09-20');
    }

    public function test_a_custom_range_that_ends_before_it_starts_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->period('custom', '2026-09-20', '2026-09-01');
    }

    /* ─────────────────────────── التكلفة والنتيجة ─────────────────────────── */

    public function test_direct_costs_are_approved_supplier_invoices_net_of_recoverable_vat_and_pending_ones_wait(): void
    {
        $this->supplierInvoice(400, 20, null, '2026-09-08');
        $this->supplierInvoice(999, 0, null, '2026-09-08', approve: false);

        $registered = FinancialPerformance::directCosts((int) $this->business->id, $this->period(), true);
        $unregistered = FinancialPerformance::directCosts((int) $this->business->id, $this->period(), false);

        $this->assertEqualsWithDelta(400.0, $registered['supplier_invoices'], 0.0005, 'الضريبةُ تُستردّ فلا تُحمَّل');
        $this->assertEqualsWithDelta(420.0, $unregistered['supplier_invoices'], 0.0005, 'ومن لا يستردّها يحملها');
    }

    public function test_the_operating_result_is_revenue_minus_costs_expenses_and_payroll_with_margins_null_on_zero_revenue(): void
    {
        $empty = FinancialPerformance::snapshot((int) $this->business->id, $this->period());
        $this->assertNull($empty['gross_margin_percent']);
        $this->assertNull($empty['operating_margin_percent']);
        $this->assertEqualsWithDelta(0.0, $empty['operating_result'], 0.0005);

        $this->invoice(1000, '2026-09-10');
        $this->supplierInvoice(300, 15, null, '2026-09-08');
        $this->expense(120.25, '2026-09-09');
        $this->expense(999, '2026-09-09', null, 'معلّق');

        $s = FinancialPerformance::snapshot((int) $this->business->id, $this->period());

        $this->assertEqualsWithDelta(700.0, $s['gross_contribution'], 0.0005);
        $this->assertEqualsWithDelta(70.0, $s['gross_margin_percent'], 0.005);
        $this->assertEqualsWithDelta(120.25, $s['expenses']['total'], 0.0005, 'المدفوعُ وحده');
        $this->assertEqualsWithDelta(579.75, $s['operating_result'], 0.0005);
        $this->assertEqualsWithDelta(57.98, $s['operating_margin_percent'], 0.005);
    }

    public function test_money_keeps_three_decimals_without_float_drift(): void
    {
        foreach ([0.001, 0.002, 0.003, 10.333, 20.667] as $v) {
            $this->invoice($v, '2026-09-10');
        }

        $s = FinancialPerformance::snapshot((int) $this->business->id, $this->period());
        $this->assertSame(31.006, $s['revenue']['net']);
    }

    /* ─────────────────────────── الذمم ─────────────────────────── */

    public function test_receivables_age_on_their_due_dates_with_exact_bucket_edges(): void
    {
        $bid = (int) $this->business->id;
        /*
         * اليومُ 20 سبتمبر — الاستحقاقُ من تاريخ الإصدار + 30، والتأخّرُ أيّامٌ كاملة
         * بعد نهاية يوم الاستحقاق (`daysOverdue`) — فالمستحقّ أمس ليس متأخّرًا بعد.
         */
        $this->invoice(100, '2026-09-01');          /* يستحقّ 1 أكتوبر — لم يحلّ */
        $this->invoice(200, '2026-08-19');          /* يستحقّ 18 سبتمبر — متأخّرٌ يومًا */
        $this->invoice(300, '2026-07-21');          /* يستحقّ 20 أغسطس — 30 يومًا */
        $this->invoice(400, '2026-07-20');          /* يستحقّ 19 أغسطس — 31 يومًا */
        $this->invoice(500, '2026-05-01');          /* يستحقّ 31 مايو — فوق 90 */

        $aging = Receivables::aging($bid);
        $byKey = collect($aging['buckets'])->keyBy('key');

        $this->assertEqualsWithDelta(105.0, $byKey['not_due']['amount'], 0.0005);
        $this->assertEqualsWithDelta(210.0 + 315.0, $byKey['1_30']['amount'], 0.0005, 'يومٌ وثلاثون يومًا كلاهما في الدرج الأوّل');
        $this->assertEqualsWithDelta(420.0, $byKey['31_60']['amount'], 0.0005, 'الحادي والثلاثون يفتح الدرجَ الثاني');
        $this->assertEqualsWithDelta(525.0, $byKey['90_plus']['amount'], 0.0005);

        $r = FinancialPerformance::receivables($bid);
        $this->assertEqualsWithDelta(1575.0, $r['outstanding'], 0.0005);
        $this->assertEqualsWithDelta(1470.0, $r['overdue'], 0.0005);
    }

    /* ─────────────────────────── السلّم والقمع ─────────────────────────── */

    public function test_the_revenue_ladder_keeps_five_definitions_apart(): void
    {
        $bid = (int) $this->business->id;
        $q = Quotations::create($bid, $this->customer, ['title' => 'مؤتمر', 'issued_at' => '2026-09-03'],
            [['description' => 'تنظيم', 'quantity' => 1, 'unit_price' => 2000]], $this->owner);
        Quotations::send($q, $this->owner);
        Quotations::recordDecision($q->fresh(), 'accepted', ['decided_by_name' => 'سالم'], $this->owner);

        $draft = Quotations::create($bid, $this->customer, ['title' => 'مسودّة', 'issued_at' => '2026-09-04'],
            [['description' => 'تنظيم', 'quantity' => 1, 'unit_price' => 5000]], $this->owner);

        $invoice = $this->invoice(1000, '2026-09-10');
        $this->pay($invoice, 300, '2026-09-12');

        $ladder = RevenueIntelligence::ladder($bid, $this->period());

        $this->assertEqualsWithDelta(2000.0, $ladder['quoted']['value'], 0.0005, 'المُرسَل صافيًا من ضريبته — والمسودّةُ ليست عرضًا');
        $this->assertEqualsWithDelta(2000.0, $ladder['contracted']['value'], 0.0005, 'والمتعاقَد على الوعاء نفسِه');
        $this->assertEqualsWithDelta(1000.0, $ladder['invoiced']['value'], 0.0005);
        $this->assertEqualsWithDelta(300.0, $ladder['collected']['value'], 0.0005);
        $this->assertEqualsWithDelta(750.0, $ladder['outstanding']['value'], 0.0005);

        $funnel = RevenueIntelligence::funnel($bid, $this->period());
        $stages = collect($funnel['stages'])->keyBy('key');
        $this->assertSame(1, $stages['draft']['count']);
        $this->assertFalse($stages['draft']['committed']);
        $this->assertTrue($stages['accepted']['committed']);
        $this->assertEqualsWithDelta(100.0, $funnel['acceptance_rate'], 0.05, 'مقبولٌ واحدٌ من محسومٍ واحد');
        $this->assertSame($draft->id, $draft->fresh()->id);
    }

    public function test_an_empty_business_answers_with_zeros_and_nulls_never_with_errors(): void
    {
        $bid = (int) $this->other->id;
        $s = FinancialPerformance::snapshot($bid, Period::resolve($bid, 'last_12_months'));

        $this->assertEqualsWithDelta(0.0, $s['revenue']['net'], 0.0005);
        $this->assertNull($s['gross_margin_percent']);
        $this->assertSame('insufficient_data', $s['previous']['change']['revenue']['status']);
        $this->assertCount(12, FinancialPerformance::monthly($bid, Period::resolve($bid, 'last_12_months')));
        $this->assertNull(RevenueIntelligence::funnel($bid, Period::resolve($bid, 'this_month'))['acceptance_rate']);
    }

    public function test_one_business_never_reads_another_businesss_invoices(): void
    {
        $this->invoice(1000, '2026-09-10');
        $theirs = CustomerInvoice::query()->count();

        $s = FinancialPerformance::snapshot((int) $this->other->id, Period::resolve((int) $this->other->id, 'this_month'));

        $this->assertSame(1, $theirs);
        $this->assertEqualsWithDelta(0.0, $s['revenue']['net'], 0.0005);
        $this->assertEqualsWithDelta(0.0, FinancialPerformance::receivables((int) $this->other->id)['outstanding'], 0.0005);
    }

    public function test_timezone_edges_keep_a_late_night_payment_in_its_local_day(): void
    {
        /* 23:30 بتوقيت مسقط في 30 سبتمبر = 19:30 UTC — يبقى في سبتمبر */
        $invoice = $this->invoice(100, '2026-09-10');
        $payment = $this->pay($invoice, 105, '2026-09-30');
        $payment->forceFill(['occurred_at' => Carbon::parse('2026-09-30 23:30:00', 'Asia/Muscat')->utc()])->save();

        $september = FinancialPerformance::snapshot((int) $this->business->id, $this->period('this_month'));
        $this->assertEqualsWithDelta(105.0, $september['collections']['total'], 0.0005);
    }
}
