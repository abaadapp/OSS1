<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BankStatementLine;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Transaction;
use App\Models\User;
use App\Support\ReportColumns;
use App\Support\ReportPeriod;
use App\Support\SupplierInvoices;
use App\Support\TableExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * كلُّ تقريرٍ زمنيٍّ في الفهرس يقرأ فترةً مخصَّصة — في شاشته وفي ملفّاته الثلاثة.
 *
 * شركةٌ لها في كلّ تقريرٍ صفٌّ داخلَ يناير ٢٠٢٦ (آخرَ يومٍ فيه، قبيل منتصف الليل)
 * وصفّان خارجه (أوّلَ فبراير بعد منتصف الليل، وآخرَ ديسمبر). وكلُّ تقريرٍ يُفتح
 * بـ`range=custom&from=2026-01-01&to=2026-01-31` فيحمل الداخلَ وحده: في الشاشة،
 * وفي إكسل، وفي CSV، وفي PDF. وهذه مصفوفةُ التغطية — لا عيّنةٌ من تقريرين.
 */
class EveryReportReadsACustomPeriodTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = ['range' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'];

    /**
     * التقاريرُ الزمنيّةُ المسطَّحة، وما يُعرف به صفُّها الداخل في الشاشة والملفّ.
     * والتجميعيّةُ (الضريبة · الوسائل · الموظفون · العملاء · المورّدون · المنتجات)
     * تُعرف بمجاميعها — انظر `test_the_aggregate_reports_sum_only_the_period`.
     */
    private const FLAT = [
        'finance' => ['IN-TX', 'OUT-TX'],
        'expenses' => ['مصروف IN', 'مصروف OUT'],
        'bank' => ['IN-BANK', 'OUT-BANK'],
        'invoices' => ['INV-IN', 'INV-OUT'],
        'purchases' => ['PO-IN', 'PO-OUT'],
        'activity' => ['نشاط داخل الفترة', 'نشاط خارج الفترة'],
        'stocktake' => ['ADJ-IN', 'ADJ-OUT'],
    ];

    private Business $business;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow(Carbon::parse('2026-03-10 12:00:00', 'Asia/Muscat'));

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '1']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($this->owner);

        $this->seedEveryReport($this->business->id, $this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** لكلّ تقريرٍ صفٌّ داخلَ يناير وصفّان خارجه — في شركةٍ بعينها */
    private function seedEveryReport(int $bid, User $by, string $tag = ''): void
    {
        $branch = Branch::where('business_id', $bid)->first();
        $customer = Customer::create(['business_id' => $bid, 'name' => 'عميل الفترة'.$tag]);
        $supplier = Supplier::create(['business_id' => $bid, 'name' => 'مورّد الفترة'.$tag]);
        $product = Product::create(['business_id' => $bid, 'name' => 'صنف الفترة'.$tag, 'price' => 50, 'cost' => 20, 'quantity' => 10]);

        foreach ([['IN', '2026-01-31 23:30:00'], ['OUT', '2026-02-01 00:15:00'], ['OLD', '2025-12-31 22:00:00']] as [$k, $at]) {
            Transaction::create(['business_id' => $bid, 'reference' => $k.'-TX'.$tag, 'description' => 'حركة '.$k, 'method' => 'نقدي',
                'type' => 'دخل', 'amount' => 100, 'occurred_at' => $at, 'employee_name' => 'المالك']);
            ActivityLog::create(['business_id' => $bid, 'user_id' => $by->id, 'user_name' => $by->name, 'action' => 'created',
                'description' => match ($k) {
                    'IN' => 'نشاط داخل الفترة', default => 'نشاط خارج الفترة'
                }.$tag, 'created_at' => $at, 'updated_at' => $at]);
            PurchaseOrder::create(['business_id' => $bid, 'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name,
                'number' => 'PO-'.$k.$tag, 'status' => 'مسودة', 'total' => 300, 'ordered_at' => $at]);
            StockAdjustment::create(['business_id' => $bid, 'branch_id' => $branch->id, 'product_id' => $product->id, 'number' => 'ADJ-'.$k.$tag,
                'reason' => StockAdjustment::STOCKTAKE_LOSS, 'quantity_delta' => -2, 'cost_at_time' => 5, 'adjusted_at' => $at]);
        }

        foreach ([['IN', '2026-01-31'], ['OUT', '2026-02-01'], ['OLD', '2025-12-31']] as [$k, $day]) {
            Expense::create(['business_id' => $bid, 'reference' => $k.'-EXP'.$tag, 'type' => 'إيجار', 'description' => 'مصروف '.$k,
                'amount' => 40, 'method' => 'نقدي', 'employee_name' => 'المالك', 'spent_at' => $day, 'status' => 'مدفوع']);
            BankStatementLine::create(['business_id' => $bid, 'date' => $day, 'description' => 'سطر '.$k, 'reference' => $k.'-BANK'.$tag,
                'amount' => 25, 'match_status' => BankStatementLine::MATCHED]);
            $this->sellThroughInvoice($bid, [['description' => 'بند '.$k, 'quantity' => 1, 'unit_price' => 200, 'tax_amount' => 10, 'product' => $product]], [
                'number' => 'INV-'.$k.$tag, 'customer_id' => $customer->id, 'customer_name' => $customer->name,
                'issued_at' => $day, 'created_by' => $by->id, 'branch_id' => $branch->id,
            ]);
            CustomerPayment::create(['business_id' => $bid, 'customer_id' => $customer->id, 'number' => 'RCPT-'.$k.$tag,
                'amount' => 70, 'method' => 'تحويل', 'occurred_at' => $day]);
            SupplierInvoice::create(['business_id' => $bid, 'supplier_id' => $supplier->id, 'supplier_ref' => 'SI-'.$k.$tag,
                'issued_at' => $day, 'subtotal' => 100, 'tax' => 5, 'total' => 105, 'paid' => 0, 'status' => 'غير مدفوع',
                'approval_status' => SupplierInvoices::APPROVED]);
        }
    }

    private function props(string $report, array $query = self::PERIOD): array
    {
        return $this->get(route('admin.reports.'.$report, $query))->assertOk()->viewData('page')['props'];
    }

    private function csv(string $report, array $query = self::PERIOD): string
    {
        $response = $this->get(route('admin.reports.export.csv', ['report' => $report] + $query))->assertOk();

        return $response->streamedContent();
    }

    private function xlsxText(string $report, array $query = self::PERIOD): string
    {
        $response = $this->get(route('admin.reports.export.xlsx', ['report' => $report] + $query))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $text = '';
        foreach (IOFactory::load($path)->getAllSheets() as $sheet) {
            foreach ($sheet->toArray(null, false, false, false) as $row) {
                $text .= implode('|', array_map(fn ($c) => (string) $c, $row))."\n";
            }
        }
        @unlink($path);

        return $text;
    }

    /* ─────────────────────────── مصفوفةُ التقارير المسطَّحة ─────────────────────────── */

    public function test_every_flat_time_report_shows_and_exports_only_the_custom_period(): void
    {
        foreach (self::FLAT as $report => [$inside, $outside]) {
            $props = $this->props($report);
            $screen = json_encode($props['rows'], JSON_UNESCAPED_UNICODE);
            $this->assertStringContainsString($inside, $screen, "{$report}: الشاشةُ تحمل يومَ النهاية كاملًا");
            $this->assertStringNotContainsString($outside, $screen, "{$report}: والشاشةُ لا تحمل ما بعد منتصف الليل");
            $this->assertSame('custom', $props['period']['range'], $report);
            $this->assertNull($props['period']['error'], $report);

            $csv = $this->csv($report);
            $this->assertStringContainsString($inside, $csv, "{$report} CSV");
            $this->assertStringNotContainsString($outside, $csv, "{$report} CSV");
            $this->assertStringContainsString('2026-01-01 — 2026-01-31', $csv, "{$report}: الفترةُ في ترويسة الملفّ");

            $xlsx = $this->xlsxText($report);
            $this->assertStringContainsString($inside, $xlsx, "{$report} XLSX");
            $this->assertStringNotContainsString($outside, $xlsx, "{$report} XLSX");

            $pdf = $this->get(route('admin.reports.export.pdf', ['report' => $report] + self::PERIOD))->assertOk();
            $this->assertStringStartsWith('application/pdf', (string) $pdf->headers->get('content-type'), "{$report} PDF");
        }
    }

    public function test_the_aggregate_reports_sum_only_the_period(): void
    {
        /* الضريبة: ضريبةُ فاتورة يناير وحدها، ومدخلاتُ سند يناير وحده */
        $vat = $this->props('vat');
        $this->assertEqualsWithDelta(10.0, $vat['summary']['output'], 0.0005);
        $this->assertEqualsWithDelta(5.0, $vat['summary']['input'], 0.0005);
        $this->assertCount(1, $vat['rows']);

        /* الوسائل: تحصيلُ يناير وحده */
        $payments = $this->props('payments');
        $this->assertEqualsWithDelta(70.0, $payments['summary']['total'], 0.0005);
        $this->assertSame(1, $payments['summary']['count']);

        /* الموظفون والعملاء: فاتورةُ يناير وحدها */
        $this->assertEqualsWithDelta(210.0, $this->props('staff')['summary']['total'], 0.0005);
        $this->assertEqualsWithDelta(210.0, $this->props('customers')['summary']['total'], 0.0005);

        /* المورّدون: أمرُ يناير وحده */
        $this->assertSame(1, $this->props('suppliers')['summary']['orders']);

        /* المنتجات: ما بيع في يناير وحده */
        $products = $this->props('products');
        $this->assertEqualsWithDelta(200.0, $products['summary']['revenue'], 0.0005);

        /* والملفُّ يقول المجاميعَ نفسَها في سطر الإجمالي */
        $this->assertStringContainsString('70', $this->csv('payments'));
        foreach (['vat', 'payments', 'staff', 'customers', 'suppliers', 'products'] as $report) {
            $this->assertStringContainsString('2026-01-01 — 2026-01-31', $this->csv($report), $report);
        }
    }

    public function test_the_invoice_report_totals_in_the_file_equal_the_screen_and_skip_cancelled_and_drafts(): void
    {
        $this->sellThroughInvoice($this->business->id, [['quantity' => 1, 'unit_price' => 999]], [
            'number' => 'INV-CANCEL', 'issued_at' => '2026-01-20', 'status' => CustomerInvoice::CANCELLED,
        ]);
        $this->sellThroughInvoice($this->business->id, [['quantity' => 1, 'unit_price' => 888]], [
            'number' => 'INV-DRAFT', 'issued_at' => '2026-01-21', 'status' => CustomerInvoice::DRAFT,
        ]);

        $props = $this->props('invoices');
        $this->assertEqualsWithDelta(210.0, $props['summary']['total'], 0.0005, 'الصادرةُ وحدها');

        $csv = $this->csv('invoices');
        $totals = collect(explode("\n", $csv))->first(fn ($line) => str_contains($line, 'الإجمالي (الصادرة فقط)'));
        $this->assertNotNull($totals, 'سطرُ الإجمالي');
        $this->assertStringContainsString('210', $totals);
        $this->assertStringNotContainsString('2097', $totals, 'لا جمعٌ أعمى يُدخل الملغاة والمسودّة');
        $this->assertStringContainsString('INV-CANCEL', $csv, 'والملغاةُ صفٌّ موسوم لا يُحذف');
    }

    /* ─────────────────────────── الحدّان والتوقيت ─────────────────────────── */

    public function test_the_end_day_is_included_whole_and_the_next_midnight_is_not(): void
    {
        $rows = collect($this->props('activity')['rows']);
        $this->assertSame(['نشاط داخل الفترة'], $rows->pluck('description')->all());

        /* ويومٌ واحد: من = إلى */
        $one = collect($this->props('finance', ['range' => 'custom', 'from' => '2026-01-31', 'to' => '2026-01-31'])['rows']);
        $this->assertSame(['IN-TX'], $one->pluck('reference')->all());
    }

    public function test_the_day_boundary_follows_the_company_timezone(): void
    {
        /*
         * شركةٌ بتوقيت UTC والخادمُ بتوقيت مسقط (+4): يومُ ٣١ يناير عندها ينتهي
         * عند الرابعة فجرَ ١ فبراير بتوقيت الخادم — فحركةُ 00:15 من ١ فبراير
         * (بتوقيت الخادم) ما زالت في ٣١ يناير عند الشركة وتدخل.
         */
        Setting::create(['business_id' => $this->business->id, 'key' => 'automation_timezone', 'value' => 'UTC']);

        $period = ReportPeriod::of(self::PERIOD, 'month', $this->business->id);
        $this->assertSame('2026-02-01 04:00:00', $period->end->format('Y-m-d H:i:s'));

        $rows = collect($this->props('finance')['rows'])->pluck('reference')->all();
        $this->assertContains('OUT-TX', $rows, 'منتصفُ ليل مسقط ما زال يومَ ٣١ بتوقيت الشركة');
        $this->assertContains('IN-TX', $rows);
        $this->assertNotContains('OLD-TX', $rows);

        /* وعمودُ التاريخ يُقرأ يومًا لا لحظة — لا يتحرّك بفرق التوقيت */
        $expenses = collect($this->props('expenses')['rows'])->pluck('id')->count();
        $this->assertSame(1, $expenses);
    }

    /* ─────────────────────────── الرفضُ والسلامة ─────────────────────────── */

    public function test_a_wrong_custom_period_is_said_on_the_screen_and_refused_at_the_download(): void
    {
        $bad = [
            ['range' => 'custom', 'from' => '2026-02-10', 'to' => '2026-02-01'],
            ['range' => 'custom', 'from' => '2026-02-30', 'to' => '2026-03-01'],
            ['range' => 'custom', 'from' => 'أمس', 'to' => '2026-03-01'],
            ['range' => 'custom', 'from' => '2026-01-01'],
            ['range' => 'custom', 'from' => '1990-01-01', 'to' => '2026-01-01'],
        ];

        foreach ($bad as $query) {
            $props = $this->props('expenses', $query);
            $this->assertNotNull($props['period']['error'], json_encode($query));
            $this->assertSame('month', $props['period']['range'], 'تعود الشاشةُ إلى الشهر وتقول لماذا');

            foreach (['xlsx', 'csv', 'pdf'] as $format) {
                $this->get(route('admin.reports.export.'.$format, ['report' => 'expenses'] + $query))->assertStatus(422);
            }
        }
    }

    public function test_an_empty_period_exports_an_empty_file_that_says_its_period(): void
    {
        $empty = ['range' => 'custom', 'from' => '2024-05-01', 'to' => '2024-05-31'];
        $this->assertSame([], $this->props('expenses', $empty)['rows']);

        $csv = $this->csv('expenses', $empty);
        $this->assertStringContainsString('2024-05-01 — 2024-05-31', $csv);
        foreach (['مصروف IN', 'مصروف OUT', 'مصروف OLD'] as $row) {
            $this->assertStringNotContainsString($row, $csv);
        }
        $this->assertStringContainsString('لا بيانات في هذه الفترة', $csv);

        $this->assertStringContainsString('لا بيانات في هذه الفترة', $this->xlsxText('expenses', $empty));
    }

    public function test_the_presets_still_read_what_they_read_before(): void
    {
        /* «الشهر» = مارس ٢٠٢٦ حتى الآن — ولا شيء في مارس */
        $this->assertSame([], $this->props('finance', ['range' => 'month'])['rows']);
        $this->assertSame('month', $this->props('finance', ['range' => 'month'])['period']['range']);

        /* «السنة» = ٢٠٢٦ حتى الآن — يناير وفبراير معًا، لا ديسمبر */
        $year = collect($this->props('finance', ['range' => 'year'])['rows'])->pluck('reference')->sort()->values()->all();
        $this->assertSame(['IN-TX', 'OUT-TX'], $year);

        /* «الكل» = العمر كلّه */
        $this->assertCount(3, $this->props('finance', ['range' => 'all'])['rows']);

        /* وفترةٌ مجهولة تسقط إلى الشهر لا إلى «الكل» — كما كانت */
        $this->assertSame('month', $this->props('finance', ['range' => 'decade'])['period']['range']);
    }

    public function test_the_file_carries_every_matching_row_not_the_first_five_hundred(): void
    {
        foreach (range(1, 520) as $i) {
            Expense::create(['business_id' => $this->business->id, 'reference' => 'BULK-'.$i, 'type' => 'وقود', 'description' => 'BULK-'.$i,
                'amount' => 1, 'method' => 'نقدي', 'employee_name' => 'المالك', 'spent_at' => '2026-01-15', 'status' => 'مدفوع']);
        }

        $props = $this->props('expenses', self::PERIOD + ['type' => 'وقود']);
        $this->assertCount(500, $props['rows'], 'الشاشةُ تعرض سقفها');
        $this->assertSame(['shown' => 500, 'total' => 520], $props['truncated'], 'وتقوله');

        $csv = $this->csv('expenses', self::PERIOD + ['type' => 'وقود']);
        $this->assertSame(520, substr_count($csv, 'BULK-'), 'والملفُّ يحمل كلَّ ما طابق');
        $this->assertStringNotContainsString('مصروف IN', $csv, 'وبمرشّح النوع نفسِه');
        $this->assertStringContainsString('النوع: وقود', $csv, 'والمرشّحُ مكتوبٌ في الترويسة');
        $this->assertSame(520, collect(explode("\n", $csv))->filter(fn ($l) => str_contains($l, 'BULK-'))->unique()->count(), 'ولا صفٌّ مكرَّر');
        $this->assertLessThanOrEqual(TableExport::SHEET_LIMIT, 520);
    }

    public function test_another_company_never_leaks_into_the_period(): void
    {
        $other = Business::create(['name' => 'شركة أخرى', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $other->id, 'name' => 'فرعها']);
        $stranger = User::create(['business_id' => $other->id, 'name' => 'غريب', 'email' => 'x@other.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط']);
        $this->seedEveryReport($other->id, $stranger, '-OTHER');

        foreach (array_keys(self::FLAT) as $report) {
            $this->assertStringNotContainsString('-OTHER', $this->csv($report), $report);
        }
    }

    public function test_a_download_without_the_section_is_refused_even_by_its_link(): void
    {
        $clerk = User::create(['business_id' => $this->business->id, 'name' => 'محاسب', 'email' => 'clerk@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'accountant', 'status' => 'نشط', 'permissions' => ['dashboard', 'reports']]);
        $this->actingAs($clerk);

        foreach (['finance', 'expenses', 'invoices', 'activity'] as $report) {
            $this->get(route('admin.reports.export.csv', ['report' => $report] + self::PERIOD))->assertForbidden();
        }
    }

    public function test_every_time_report_in_the_catalog_is_covered_here(): void
    {
        /* التقاريرُ اللحظيّة (المخزون) خارج الفترة عمدًا — لقطةُ رصيدٍ لا حركة */
        $timeless = ['inventory'];
        $covered = array_merge(array_keys(self::FLAT), ['vat', 'payments', 'staff', 'customers', 'suppliers', 'products']);

        $this->assertEqualsCanonicalizing(
            array_values(array_diff(array_keys(ReportColumns::MAP), $timeless)),
            $covered,
            'تقريرٌ زمنيٌّ جديد في ReportColumns بلا صفٍّ في هذه المصفوفة',
        );
    }
}
