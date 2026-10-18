<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Document\Pdf\Driver;
use App\Support\Exports\Rows;
use App\Support\Ledger;
use App\Support\Pdf;
use App\Support\TableExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use ZipArchive;

/**
 * الملفُّ يحمل كلَّ ما طابق — أو يقول لماذا لا، قبل أن يُنزَّل.
 *
 * • CSV وإكسل بلا سقف: عشرون ألفًا وخمسمئة صفٍّ (فوق ألفٍ، وفوق سقف إكسل القديم
 *   عشرة آلاف، وفوق سقف CSV القديم عشرين ألفًا) تخرج كلُّها — من قائمة الشاشة
 *   ومن التقرير — لا يسقط صفٌّ ولا يتكرّر، والذاكرةُ دفعةٌ لا الجدولُ كلّه.
 * • والصفوفُ تُقرأ من لقطة مفاتيح: صفٌّ يُكتب أثناء التصدير لا يُكرّر غيرَه.
 * • PDF له حدّ (ألف صفّ): يُسأل عنه قبل التنزيل، وما فوقه يُردّ بشرحٍ إلّا أن
 *   يُطلب جزؤه صراحةً — فيقول ذلك في رأس ورقته.
 * • والمخزونُ رصيدٌ حاليّ: «الرصيد الحالي بتاريخ …» لا «الفترة: هذا الشهر».
 */
class AnExportCarriesEveryRowOrSaysWhyNotTest extends TestCase
{
    use RefreshDatabase;

    /** فوق ألف، وفوق عشرة آلاف، وفوق عشرين ألفًا */
    private const MANY = 20500;

    private const JANUARY = ['from' => '2026-01-01', 'to' => '2026-01-31'];

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
        Ledger::ensureSystemAccounts((int) $this->business->id);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * حركاتٌ كثيرةٌ في جملٍ قليلة — مرجعٌ فريدٌ لكلّ حركة، وثلاثُ لحظاتٍ فقط:
     * آلافُ الصفوف تتعادل في ترتيبها، وهناك يسقط الصفُّ أو يتكرّر إن لم يُحرس.
     */
    private function seedTransactions(int $count, int $from = 1, string $moment = ''): void
    {
        $moments = ['2026-01-05 09:00:00', '2026-01-15 12:00:00', '2026-01-25 18:00:00'];
        $now = now()->toDateTimeString();
        foreach (array_chunk(range($from, $from + $count - 1), 1000) as $chunk) {
            DB::table('transactions')->insert(array_map(fn ($i) => [
                'business_id' => $this->business->id, 'reference' => sprintf('BULK-%05d', $i), 'description' => 'حركة '.$i,
                'method' => 'نقدي', 'type' => 'دخل', 'amount' => 1, 'employee_name' => 'المالك',
                'occurred_at' => $moment !== '' ? $moment : $moments[$i % 3], 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
    }

    /** المراجعُ المتوقَّعة بعينها */
    private function expected(int $count): array
    {
        return array_map(fn ($i) => sprintf('BULK-%05d', $i), range(1, $count));
    }

    /** مراجعُ الصفوف في نصّ CSV */
    private function refs(string $text): array
    {
        preg_match_all('/BULK-\d{5}/', $text, $m);

        return $m[0];
    }

    /** مراجعُ الصفوف في ملفّ إكسل — من نصوصه المشتركة مباشرةً، لا ببناء الملفّ كلّه في الذاكرة */
    private function xlsxRefs(string $binary): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ep-xlsx');
        file_put_contents($path, $binary);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'إكسل حزمةٌ تُفتح');
        $strings = (string) $zip->getFromName('xl/sharedStrings.xml');
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        $this->assertStringContainsString('rightToLeft="1"', $sheet, 'من اليمين إلى اليسار');
        $this->assertStringContainsString('state="frozen"', $sheet, 'ورأسُ الجدول ثابت');
        $this->assertStringNotContainsString('<f>', $sheet, 'ولا معادلةَ في خليّة');

        return $this->refs($strings);
    }

    /** يُطابق المراجعَ بالمتوقَّع: لا ساقطَ ولا مكرَّر */
    private function assertWhole(array $expected, array $got, string $where): void
    {
        $this->assertCount(count($expected), $got, "{$where}: كلُّ الصفوف");
        $this->assertCount(count($expected), array_unique($got), "{$where}: ولا صفٌّ مكرَّر");
        $this->assertSame([], array_values(array_diff($expected, $got)), "{$where}: ولا صفٌّ ساقط");
    }

    /** سائقُ PDF يسجّل ما بلغه بدل أن يرسم — نصُّ PDF مضغوطٌ لا يُقرأ */
    private function capturePdf(callable $run): string
    {
        $fake = new class implements Driver
        {
            public string $html = '';

            public function sheet(string $html, string $name, array $preset, bool $landscape = false, ?string $runningHeader = null, ?string $context = null): Response
            {
                $this->html = $html;

                return response('FAKE', 200, ['Content-Type' => 'application/pdf']);
            }

            public function strip(string $html, string $name, int $widthMm): Response
            {
                return response('FAKE');
            }

            public function stripHeight(string $html, int $widthMm): float
            {
                return 100.0;
            }
        };

        $was = Pdf::swap($fake);
        try {
            $run();
        } finally {
            Pdf::swap($was);
        }

        return $fake->html;
    }

    /* ═══════════ CSV وإكسل: كلُّ ما طابق، من القائمة ومن التقرير ═══════════ */

    public function test_more_than_twenty_thousand_rows_leave_whole_in_csv_and_excel_from_the_list_and_the_report(): void
    {
        $this->seedTransactions(self::MANY);
        $expected = $this->expected(self::MANY);

        $files = [
            'القائمة CSV' => ['admin.finance.transactions.export', ['format' => 'csv'] + self::JANUARY],
            'القائمة إكسل' => ['admin.finance.transactions.export', ['format' => 'xlsx'] + self::JANUARY],
            'التقرير CSV' => ['admin.reports.export.csv', ['report' => 'finance', 'range' => 'custom'] + self::JANUARY],
            'التقرير إكسل' => ['admin.reports.export.xlsx', ['report' => 'finance', 'range' => 'custom'] + self::JANUARY],
        ];

        foreach ($files as $where => [$route, $query]) {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $before = memory_get_usage();
            $body = $this->get(route($route, $query))->assertOk()->streamedContent();
            $peak = memory_get_peak_usage() - $before;

            $xlsx = str_contains($where, 'إكسل');
            $this->assertWhole($expected, $xlsx ? $this->xlsxRefs($body) : $this->refs($body), $where);
            $this->assertStringNotContainsString('يحمل الملفّ', $body, "{$where}: ولا خبرَ بترٍ — لا بتر");
            /* الذاكرةُ دفعةٌ ومفاتيحُها والملفُّ نفسُه — لا عشرون ألفَ نموذجٍ معًا (كانت ~٥٠٠ م.ب لإكسل) */
            $this->assertLessThan(96 * 1024 * 1024, $peak, "{$where}: الذاكرةُ لا تنمو بالصفوف (".round($peak / 1048576).' م.ب)');
            unset($body);
        }

        /* وبطاقةُ العدد فوق الجدول تقول ما تحته */
        $csv = $this->get(route('admin.reports.export.csv', ['report' => 'finance', 'range' => 'custom'] + self::JANUARY))->streamedContent();
        $this->assertStringContainsString(__('العدد').','.self::MANY, $csv, 'العددُ عشرون ألفًا وخمسمئة كما في الصفوف');
    }

    public function test_rows_come_from_a_snapshot_so_a_write_during_the_export_neither_repeats_nor_drops_a_row(): void
    {
        // ألفٌ ومئتان في لحظةٍ واحدة، والترتيبُ بها وحدها — بلا فاصلٍ بالمفتاح
        $this->seedTransactions(1200, 1, '2026-01-10 10:00:00');
        $rows = Rows::each(
            Transaction::where('business_id', $this->business->id)->orderByDesc('occurred_at'),
            fn (Transaction $t) => $t->reference,
        );
        $this->assertCount(1200, $rows, 'يُعدّ قبل أن يُقرأ');

        $seen = [];
        foreach ($rows as $reference) {
            $seen[] = $reference;
            if (count($seen) === Rows::CHUNK) {
                /* حركاتٌ جديدةٌ في أعلى القائمة أثناء التصدير — كانت تدفع الإزاحةَ فيتكرّر آخرُ الدفعة */
                $this->seedTransactions(60, 5001, '2026-02-01 10:00:00');
            }
        }

        $this->assertWhole($this->expected(1200), $seen, 'لقطةُ المفاتيح');
    }

    /* ═══════════ PDF: حدُّه يُقال قبل التنزيل ═══════════ */

    public function test_a_pdf_above_its_limit_is_announced_before_download_and_never_passes_as_whole(): void
    {
        $this->seedTransactions(self::MANY);
        $list = ['format' => 'pdf'] + self::JANUARY;
        $report = ['report' => 'finance', 'range' => 'custom'] + self::JANUARY;

        foreach ([[route('admin.finance.transactions.export', $list), 'القائمة'], [route('admin.reports.export.pdf', $report), 'التقرير']] as [$url, $where]) {
            $this->getJson($url.'&probe=1')->assertOk()
                ->assertExactJson(['rows' => self::MANY, 'limit' => TableExport::PDF_LIMIT, 'fits' => false]);

            $refused = $this->get($url)->assertStatus(422);
            $this->assertStringContainsString('20,500', $refused->getContent(), "{$where}: يقول كم النتائج");
            $this->assertStringContainsString('Excel', $refused->getContent(), "{$where}: ويقترح البديل");

            /* وجزؤه لمن طلبه صراحةً — يقول في رأسه إنّه جزء */
            $html = $this->capturePdf(fn () => $this->get($url.'&partial=1')->assertOk());
            $this->assertStringContainsString(__('ملفٌّ جزئيّ: يحمل أوّل :shown من :total صفًّا (حدّ ملفّ PDF). لكلّ النتائج صدّر Excel أو CSV.', ['shown' => '1,000', 'total' => '20,500']), $html, $where);
            $this->assertCount(TableExport::PDF_LIMIT, $this->refs($html), "{$where}: ألفُ صفٍّ لا أكثر");
            $this->assertLessThan(mb_strpos($html, 'BULK-'), mb_strpos($html, 'ملفٌّ جزئيّ'), "{$where}: والتنبيهُ قبل الجدول لا بعده");
        }

        /* والسؤالُ لا يُسجَّل تصديرًا */
        $this->assertSame(2, DB::table('activity_logs')->where('description', 'like', 'صدّر%PDF%')->count(), 'الجزءان وحدهما');
    }

    public function test_the_pdf_limit_is_exactly_one_thousand_rows(): void
    {
        $this->seedTransactions(TableExport::PDF_LIMIT);
        $url = route('admin.finance.transactions.export', ['format' => 'pdf'] + self::JANUARY);

        $this->getJson($url.'&probe=1')->assertExactJson(['rows' => 1000, 'limit' => 1000, 'fits' => true]);
        $html = $this->capturePdf(fn () => $this->get($url)->assertOk());
        $this->assertCount(1000, $this->refs($html), 'ألفٌ تتّسعها الورقة كاملةً');
        $this->assertStringNotContainsString('ملفٌّ جزئيّ', $html);

        $this->seedTransactions(1, 1001);
        $this->getJson($url.'&probe=1')->assertExactJson(['rows' => 1001, 'limit' => 1000, 'fits' => false]);
        $this->get($url)->assertStatus(422);
    }

    /* ═══════════ المخزون: رصيدٌ بتاريخه لا شهر ═══════════ */

    public function test_the_inventory_says_current_balance_as_of_today_not_the_month(): void
    {
        foreach ([['كراسي', 120, 2.5], ['طاولات', 30, 12.25], ['إضاءة', 4, 80]] as [$name, $quantity, $cost]) {
            Product::create(['business_id' => $this->business->id, 'name' => $name, 'price' => $cost * 2, 'cost' => $cost, 'quantity' => $quantity, 'alert_qty' => 5]);
        }
        $value = 120 * 2.5 + 30 * 12.25 + 4 * 80;
        $asOf = 'الرصيد الحالي بتاريخ 2026-03-10';

        /* الشاشة: تاريخُ الرصيد لا «هذا الشهر»، ولا فترةَ تحملها روابطُ تصديره */
        $props = $this->get(route('admin.reports.inventory', ['range' => 'month']))->assertOk()->viewData('page')['props'];
        $this->assertSame($asOf, $props['rangeLabel']);
        $this->assertNull($props['range']);
        $this->assertNull($props['period']);
        $this->assertEqualsWithDelta($value, (float) $props['summary']['value'], 0.0005, 'والأرقامُ كما هي');

        $csv = $this->get(route('admin.reports.export.csv', ['report' => 'inventory', 'range' => 'month']))->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString($asOf, $body);
        $this->assertStringNotContainsString(__('الفترة').':', $body, 'لا «الفترة:» فوق رصيد');
        $this->assertStringNotContainsString('هذا الشهر', $body);
        $this->assertStringContainsString('report-inventory-as-of-2026-03-10', (string) $csv->headers->get('content-disposition'));
        $lines = array_values(array_filter(explode("\n", trim($body))));
        $this->assertStringContainsString((string) round($value, 3), end($lines), 'وقيمةُ المخزون في الإجمالي كما في الشاشة');

        $xlsx = $this->get(route('admin.reports.export.xlsx', ['report' => 'inventory']))->assertOk()->streamedContent();
        $path = tempnam(sys_get_temp_dir(), 'ep-inv').'.xlsx';
        file_put_contents($path, $xlsx);
        $sheet = IOFactory::load($path)->getActiveSheet();
        @unlink($path);
        $this->assertSame($asOf, (string) $sheet->getCell('A3')->getValue(), 'سطرُ المدّة في إكسل');
        $this->assertTrue($sheet->getRightToLeft());

        $html = $this->capturePdf(fn () => $this->get(route('admin.reports.export.pdf', ['report' => 'inventory', 'range' => 'month']))->assertOk());
        $this->assertStringContainsString($asOf, $html);
        $this->assertStringNotContainsString('هذا الشهر', $html);

        /* ولا فترةَ تُفحص لما لا فترةَ له: مخصَّصةٌ خاطئةٌ في الرابط لا تُسقط رصيدًا */
        $this->get(route('admin.reports.export.csv', ['report' => 'inventory', 'range' => 'custom', 'from' => '2026-03-01', 'to' => '2026-02-01']))->assertOk();
    }

    public function test_the_current_balance_date_is_the_companys_day_not_the_servers(): void
    {
        // الثانية فجرًا في مسقط = العاشرة مساءَ اليوم السابق في لوس أنجلوس
        Carbon::setTestNow(Carbon::parse('2026-03-10 02:00:00', 'Asia/Muscat'));
        Setting::create(['business_id' => $this->business->id, 'key' => 'automation_timezone', 'value' => 'America/Los_Angeles']);

        $body = $this->get(route('admin.reports.export.csv', ['report' => 'inventory']))->assertOk()->streamedContent();
        $this->assertStringContainsString('الرصيد الحالي بتاريخ 2026-03-09', $body);
    }

    public function test_the_receivables_and_dues_lists_say_their_balance_date_too(): void
    {
        foreach (['admin.finance.receivables.export', 'admin.finance.dues.export'] as $route) {
            $body = $this->get(route($route, 'csv'))->assertOk()->streamedContent();
            $this->assertStringContainsString('الرصيد الحالي بتاريخ 2026-03-10', $body, $route);
            $this->assertStringNotContainsString(__('الفترة').':', $body, $route);
        }
    }
}
