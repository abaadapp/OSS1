<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\JobTitle;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Document\Pdf\Driver;
use App\Support\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * البحثُ في أوامر الشراء يصل إلى ملفّ تصديرها.
 *
 * كان البحثُ محلّيًّا في جدول الشاشة: يكتب التاجرُ اسمَ مورّدٍ فيرى أمرين، ويضغط
 * «تصدير» فيخرج الملفُّ بالأوامر كلّها. صار البحثُ في الرابط (`q`) ويقرؤه الخادمُ
 * للقائمة وللملفّ بالاستعلام نفسِه (`Exports\PurchaseOrdersDataset::query`): برقم
 * الأمر، أو بالمورّد كما يُعرض، أو بالفرع — ومعه المدّةُ والحالُ والمورّدُ والفروع.
 */
class APurchaseOrderSearchReachesItsExportTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['PO-2026-0101', 'PO-2026-0102', 'PO-2026-0103', 'PO-2026-0201', 'PO-2026-0301'];

    private Business $business;

    private Branch $muscat;

    private Branch $salalah;

    private Supplier $gulf;

    private Supplier $noor;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow(Carbon::parse('2026-03-10 12:00:00', 'Asia/Muscat'));

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط']);
        $this->muscat = Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
        $this->salalah = Branch::create(['business_id' => $this->business->id, 'name' => 'صلالة']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);
        $owner = User::create(['business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط']);
        $this->actingAs($owner);

        $this->gulf = Supplier::create(['business_id' => $this->business->id, 'name' => 'مؤسسة الخليج للتجهيزات']);
        $this->noor = Supplier::create(['business_id' => $this->business->id, 'name' => 'شركة النور']);

        foreach ([
            // الرقم · المورّدُ المرتبط · الاسمُ المكتوب على الأمر · الفرع · الحال · التاريخ
            ['PO-2026-0101', $this->gulf, null, $this->muscat, 'مُرسل', '2026-01-10'],
            ['PO-2026-0102', null, 'مؤسسة الخليج للتجهيزات', $this->salalah, 'مستلم', '2026-02-05'],
            ['PO-2026-0103', $this->noor, null, $this->salalah, 'مُرسل', '2026-01-20'],
            ['PO-2026-0201', $this->noor, null, $this->muscat, 'مسودة', '2026-01-25'],
            // يُعرض باسمه المكتوب لا باسم مورّده المرتبط — فلا يطابق «الخليج»
            ['PO-2026-0301', $this->gulf, 'مورّد نقديّ', $this->muscat, 'مُرسل', '2026-01-28'],
        ] as [$number, $supplier, $name, $branch, $status, $day]) {
            PurchaseOrder::create(['business_id' => $this->business->id, 'number' => $number, 'supplier_id' => $supplier?->id,
                'supplier_name' => $name, 'branch_id' => $branch->id, 'status' => $status, 'total' => 100, 'ordered_at' => $day]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** أرقامُ الأوامر في نصّ */
    private function numbers(string $text): array
    {
        preg_match_all('/PO-2026-\d{4}/', $text, $m);
        $found = array_values(array_unique($m[0]));
        sort($found);

        return $found;
    }

    /** أرقامُ الأوامر التي تعرضها الشاشة */
    private function screen(array $query): array
    {
        $orders = $this->get(route('admin.purchases.orders', $query))->assertOk()->viewData('page')['props']['orders'];
        $found = array_column($orders, 'number');
        sort($found);

        return $found;
    }

    private function csv(array $query): string
    {
        return $this->get(route('admin.purchases.orders.export', ['format' => 'csv'] + $query))->assertOk()->streamedContent();
    }

    private function xlsx(array $query): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ep-po').'.xlsx';
        file_put_contents($path, $this->get(route('admin.purchases.orders.export', ['format' => 'xlsx'] + $query))->assertOk()->streamedContent());
        $text = '';
        foreach (IOFactory::load($path)->getActiveSheet()->toArray() as $row) {
            $text .= implode(' ', array_map('strval', $row))."\n";
        }
        @unlink($path);

        return $this->numbers($text);
    }

    /** نصُّ PDF مضغوطٌ لا يُقرأ — فيُقرأ ما بلغ السائقَ بدل أن يُرسم */
    private function pdf(array $query): array
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
            $this->get(route('admin.purchases.orders.export', ['format' => 'pdf'] + $query))->assertOk();
        } finally {
            Pdf::swap($was);
        }

        return $this->numbers($fake->html);
    }

    /** الشاشةُ والصيغُ الثلاث تحمل الأوامرَ نفسَها — المطابقةَ وحدها */
    private function assertEverywhere(array $expected, array $query, string $why): void
    {
        sort($expected);
        $this->assertSame($expected, $this->screen($query), "{$why}: الشاشة");
        $this->assertSame($expected, $this->numbers($this->csv($query)), "{$why}: CSV");
        $this->assertSame($expected, $this->xlsx($query), "{$why}: إكسل");
        $this->assertSame($expected, $this->pdf($query), "{$why}: PDF");
    }

    public function test_search_then_export_returns_only_the_matching_orders_in_all_three_formats(): void
    {
        /* بلا بحثٍ: الأوامرُ كلُّها — والفرقُ أدناه من البحث لا من غيره */
        $this->assertEverywhere(self::ALL, [], 'بلا بحث');

        $this->assertEverywhere(['PO-2026-0101', 'PO-2026-0102'], ['q' => 'الخليج'], 'بالمورّد كما يُعرض');
        $this->assertStringContainsString('البحث: الخليج', $this->csv(['q' => 'الخليج']), 'والبحثُ مكتوبٌ في ترويسة الملفّ');
    }

    public function test_search_finds_an_order_by_its_number_or_its_branch(): void
    {
        $this->assertEverywhere(['PO-2026-0103'], ['q' => '0103'], 'برقم الأمر');
        $this->assertEverywhere(['PO-2026-0201'], ['q' => 'po-2026-0201'], 'وبرقمه بأحرفٍ صغيرة');
        $this->assertEverywhere(['PO-2026-0102', 'PO-2026-0103'], ['q' => 'صلالة'], 'بالفرع');
        $this->assertEverywhere([], ['q' => 'لا أحد'], 'ولا شيءَ لبحثٍ لا يطابق');
    }

    public function test_the_search_keeps_the_dates_the_status_and_the_supplier(): void
    {
        $this->assertEverywhere(['PO-2026-0101'], ['q' => 'الخليج', 'status' => 'مُرسل'], 'مع الحال');
        $this->assertEverywhere(['PO-2026-0102'], ['q' => 'الخليج', 'from' => '2026-02-01', 'to' => '2026-02-28'], 'مع المدّة');
        $this->assertEverywhere(['PO-2026-0103', 'PO-2026-0201'], ['q' => 'PO-2026', 'supplier_id' => $this->noor->id], 'مع المورّد');

        /* والشاشةُ تُعيد المرشّحاتِ كما وصلت — فيبقى البحثُ إذا تغيّرت الحالُ أو المدّة */
        $props = $this->get(route('admin.purchases.orders', ['q' => 'الخليج', 'status' => 'مُرسل', 'supplier_id' => $this->gulf->id]))->viewData('page')['props'];
        $this->assertSame('الخليج', $props['q']);
        $this->assertSame(['status' => 'مُرسل', 'supplier_id' => (string) $this->gulf->id], $props['filters']);
    }

    public function test_a_clerk_limited_to_a_branch_never_finds_another_branchs_orders(): void
    {
        $clerk = User::create(['business_id' => $this->business->id, 'name' => 'أمين المشتريات', 'email' => 'clerk@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'accountant', 'status' => 'نشط', 'permissions' => ['dashboard', 'purchases']]);
        $clerk->branches()->sync([$this->muscat->id]);
        $this->actingAs($clerk);

        $this->assertSame([], $this->screen(['q' => 'صلالة']), 'البحثُ باسم فرعٍ آخر لا يفتحه');
        $this->assertSame([], $this->numbers($this->csv(['q' => 'صلالة'])));
        $this->assertSame(['PO-2026-0101'], $this->screen(['q' => 'الخليج']), 'وأوامرُ فرعه وحدها تطابق');
        $this->assertSame(['PO-2026-0101'], $this->numbers($this->csv(['q' => 'الخليج'])));
    }
}
