<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\JobTitle;
use App\Models\Setting;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * التقاريرُ التي لها مدّتُها الخاصّة — ملخّصُ الإيرادات وتقاريرُ الإدارة والهالك.
 *
 * • ملخّصُ الإيرادات صار يقرأ فترةً مخصَّصة في شاشته وتغذيتها وملفّاته الثلاثة
 *   (`Reports::salesReport`) — والمصروفاتُ فيه تُقصر من طرفيها كالإيراد.
 * • تقاريرُ الإدارة كانت تقرأ مدّةً مخصَّصة (`period=custom&from&to`) — والآن
 *   تردّ الخاطئةَ بـ422 لا بخطأ خادم، وتنزيلُها يسأل عن إذن التحليل كشاشتها.
 * • والهالكُ بحدّيه (`from` · `to`): الخاطئُ يُقال في الشاشة ويُردّ في التنزيل.
 */
class TheSpecialReportsKeepTheirOwnPeriodsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow(Carbon::parse('2026-03-10 12:00:00', 'Asia/Muscat'));

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create(['business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط']);
        $this->actingAs($this->owner);

        foreach ([['2026-01-31', 300], ['2026-02-01', 5000], ['2025-12-31', 7000]] as $i => [$day, $total]) {
            $this->sellThroughInvoice($this->business->id, [['quantity' => 1, 'unit_price' => $total]], ['number' => 'INV-'.$i, 'issued_at' => $day]);
            Expense::create(['business_id' => $this->business->id, 'type' => 'إيجار', 'description' => 'م'.$i, 'amount' => $total / 10,
                'method' => 'نقدي', 'spent_at' => $day, 'status' => 'مدفوع']);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private const JANUARY = ['range' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'];

    public function test_the_revenue_summary_reads_a_custom_period_on_screen_feed_and_files(): void
    {
        $props = $this->get(route('admin.reports.sales', self::JANUARY))->assertOk()->viewData('page')['props'];
        $this->assertEqualsWithDelta(300.0, (float) $props['summary']['sales'], 0.0005, 'فاتورةُ يناير وحدها');
        $this->assertEqualsWithDelta(30.0, (float) $props['summary']['expenses'], 0.0005, 'ومصروفُ يناير وحده — لا فبراير');
        $this->assertSame('custom', $props['period']['range']);
        $this->assertSame('day', $props['salesSeries']['unit'], 'شهرٌ مخصَّص بالأيّام');
        $this->assertCount(31, $props['salesSeries']['labels']);

        $feed = $this->getJson(route('admin.reports.feed', self::JANUARY))->assertOk()->json();
        $this->assertEqualsWithDelta(300.0, (float) $feed['summary']['sales'], 0.0005, 'والتغذيةُ لا تقلب الفترةَ إلى الشهر');

        $csv = $this->get(route('admin.export.reports', self::JANUARY))->assertOk();
        $this->assertStringContainsString('2026-01-01 — 2026-01-31', $csv->streamedContent());
        $this->assertStringContainsString('2026-01-01_2026-01-31', (string) $csv->headers->get('content-disposition'));
        $this->get(route('admin.reports.xlsx', self::JANUARY))->assertOk();
        $this->get(route('admin.reports.pdf', self::JANUARY))->assertOk();

        /* ومخصَّصةٌ خاطئةٌ تُردّ عند التنزيل، وتُقال في الشاشة */
        $bad = ['range' => 'custom', 'from' => '2026-03-01', 'to' => '2026-02-01'];
        foreach (['admin.export.reports', 'admin.reports.xlsx', 'admin.reports.pdf'] as $name) {
            $this->get(route($name, $bad))->assertStatus(422);
        }
        $this->assertNotNull($this->get(route('admin.reports.sales', $bad))->viewData('page')['props']['period']['error']);
    }

    public function test_a_long_custom_period_draws_months_and_a_single_day_draws_hours(): void
    {
        $year = $this->get(route('admin.reports.sales', ['range' => 'custom', 'from' => '2025-01-01', 'to' => '2026-01-31']))->viewData('page')['props'];
        $this->assertSame('month', $year['salesSeries']['unit']);
        $this->assertEqualsWithDelta(7300.0, (float) $year['summary']['sales'], 0.0005, 'ديسمبر ويناير — لا فبراير');

        $day = $this->get(route('admin.reports.sales', ['range' => 'custom', 'from' => '2026-01-31', 'to' => '2026-01-31']))->viewData('page')['props'];
        $this->assertSame('hour', $day['salesSeries']['unit']);
    }

    public function test_the_presets_of_the_revenue_summary_did_not_move(): void
    {
        $year = $this->get(route('admin.reports.sales', ['range' => 'year']))->viewData('page')['props'];
        $this->assertEqualsWithDelta(5300.0, (float) $year['summary']['sales'], 0.0005, 'السنةُ حتى الآن: يناير وفبراير');
        $all = $this->get(route('admin.reports.sales', ['range' => 'all']))->viewData('page')['props'];
        $this->assertEqualsWithDelta(12300.0, (float) $all['summary']['sales'], 0.0005);
    }

    public function test_a_management_report_download_asks_for_its_analytics_permission_like_its_screen(): void
    {
        $reader = User::create(['business_id' => $this->business->id, 'name' => 'قارئ', 'email' => 'reader@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'accountant', 'status' => 'نشط', 'permissions' => ['dashboard', 'management', 'reports']]);
        $this->actingAs($reader);

        $this->get(route('admin.management.reports', ['report' => 'management_monthly']))->assertForbidden();
        $this->get(route('admin.reports.export.csv', ['report' => 'management_monthly']))->assertForbidden();

        $reader->update(['permissions' => ['dashboard', 'management', 'reports', Permissions::ANALYTICS_FINANCIAL]]);
        $this->get(route('admin.reports.export.csv', ['report' => 'management_monthly', 'period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31']))->assertOk();
    }

    public function test_a_wrong_management_period_is_a_422_not_a_server_error(): void
    {
        $bad = ['report' => 'customer_revenue', 'period' => 'custom', 'from' => '2026-03-01', 'to' => '2026-02-01'];
        $this->get(route('admin.management.reports', $bad))->assertStatus(422);
        $this->get(route('admin.reports.export.csv', $bad))->assertStatus(422);

        $good = ['report' => 'customer_revenue', 'period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'];
        $this->get(route('admin.management.reports', $good))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('period.from', '2026-01-01')->where('period.to', '2026-01-31'));
        $file = $this->get(route('admin.reports.export.csv', $good))->assertOk();
        $this->assertStringContainsString('2026-01-01_2026-01-31', (string) $file->headers->get('content-disposition'));
    }

    public function test_every_management_report_and_the_timeless_ones_export_in_all_three_formats(): void
    {
        $periods = [
            'management_monthly' => ['period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'],
            'project_profitability' => ['period' => 'all'],
            'receivables_aging' => [],
            'budget_vs_actual' => ['grouping' => 'quarter'],
            'cash_forecast' => ['horizon' => 90],
            'customer_revenue' => ['period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'],
            'service_performance' => ['period' => 'last_quarter'],
            'asset_utilization' => ['period' => 'this_month'],
        ];

        foreach ($periods as $report => $query) {
            $this->get(route('admin.management.reports', ['report' => $report] + $query))->assertOk();
            $xlsx = $this->get(route('admin.reports.export.xlsx', ['report' => $report] + $query))->assertOk();
            $this->assertStringStartsWith('PK', $xlsx->streamedContent(), $report);
            $csv = $this->get(route('admin.reports.export.csv', ['report' => $report] + $query))->assertOk();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv->streamedContent(), $report);
            $pdf = $this->get(route('admin.reports.export.pdf', ['report' => $report] + $query))->assertOk();
            $this->assertStringStartsWith('application/pdf', (string) $pdf->headers->get('content-type'), $report);
        }

        /* والمخزونُ لقطةُ رصيد بلا فترة — يُصدَّر بصيغه الثلاث كما هو */
        foreach (['xlsx', 'csv', 'pdf'] as $format) {
            $this->get(route('admin.reports.export.'.$format, ['report' => 'inventory']))->assertOk();
            $this->get(route('admin.reports.export.'.$format, ['report' => 'waste', 'from' => '2026-01-01', 'to' => '2026-01-31']))->assertOk();
        }
    }

    public function test_the_waste_period_is_checked_before_it_is_read(): void
    {
        $props = $this->get(route('admin.reports.waste', ['from' => '2026-02-20', 'to' => '2026-02-01']))->assertOk()->viewData('page')['props'];
        $this->assertNotNull($props['periodError'], 'الخاطئةُ تُقال');
        $this->assertSame(now()->startOfMonth()->toDateString(), $props['filters']['from'], 'وتعود الشاشةُ إلى الشهر');

        $this->get(route('admin.reports.export.csv', ['report' => 'waste', 'from' => '2026-02-20', 'to' => '2026-02-01']))->assertStatus(422);
        $this->get(route('admin.reports.waste', ['from' => 'أمس']))->assertOk();

        /* والحقلُ المُفرَّغ يعود إلى افتراضيّه لا إلى التاريخ كلّه */
        $empty = $this->get(route('admin.reports.waste', ['from' => '', 'to' => '']))->viewData('page')['props'];
        $this->assertSame(now()->startOfMonth()->toDateString(), $empty['filters']['from']);
    }
}
