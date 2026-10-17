<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\InventoryMovement;
use App\Models\JobTitle;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger;
use App\Support\Permissions;
use App\Support\Reports;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * خرج قسمُ «المبيعات» — فلا تبقى كلمتُه على شاشة.
 *
 * الأرقامُ التي كانت تُسمّى «مبيعات» تُقرأ من فواتير العملاء الصادرة
 * (`Revenue`) أو من الدفتر، فتُسمّى بما تحمله: «الإيرادات» و«إجمالي الفواتير»
 * و«الإيرادات الخاضعة للضريبة». والدورُ `sales` يبقى مفتاحُه كما هو في
 * القاعدة والصلاحيات، واسمُه الظاهر «موظف عروض ومشاريع».
 *
 * وما بقي من الكلمة في الكود اسمٌ داخليّ أو تاريخيّ: مفاتيحُ (`sales`،
 * `salesSeries`) ومسارٌ (`admin.reports.sales`) ومصدرا قيود الطلبات القديمة
 * في `Books` — ولا شيء منها يُكتب على شاشة.
 */
class NoScreenSaysSalesAfterTheSectionLeftTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_17_150000_the_names_that_said_sales_say_what_they_hold.php';

    private const WORD = 'مبيع';

    /**
     * ما يجوز أن يبقى في الكود وفيه الكلمة: مصدرا قيود الطلبات القديمة.
     * قيمتان محفوظتان في `journal_entries` لصفوفٍ وقعت — لا يُعرضان ولا يُترجمان.
     */
    private const HISTORICAL = [
        'app/Support/Books.php' => ["'مبيعات'", "'تكلفة مبيعات'"],
    ];

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);
        Ledger::seedChart($this->business->id);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /** ما في الملفّ بعد نزع تعليقاته — التعليقُ يشرح التاريخ ولا يراه أحد */
    private function withoutComments(string $source): string
    {
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        $source = preg_replace('#/\*.*?\*/#s', '', $source);

        return preg_replace('#(^|\s)//[^\n]*#', '$1', $source);
    }

    /** صفحةُ Inertia كما تصل المتصفّح — نصًّا واحدًا بلا هروبٍ للعربية */
    private function page(string $route, array $params = []): string
    {
        $page = $this->actingAs($this->owner)->get(route($route, $params))->assertOk()->viewData('page');

        return json_encode($page, JSON_UNESCAPED_UNICODE);
    }

    public function test_no_screen_template_says_sales_outside_its_comments(): void
    {
        $found = [];
        foreach (['resources/js', 'resources/views'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (! preg_match('/\.(tsx?|php)$/', $file->getFilename())) {
                    continue;
                }
                foreach (explode("\n", $this->withoutComments(file_get_contents($file->getPathname()))) as $line) {
                    if (str_contains($line, self::WORD)) {
                        $found[] = str_replace(base_path().'/', '', $file->getPathname()).': '.trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $found, 'نصٌّ على الشاشة ما زال يقول «المبيعات»');
    }

    /**
     * والخادمُ يكتب نصوصًا تصل الشاشة: تسمياتُ التقارير والبطاقات والتنبيهات
     * والأدوار والحسابات. فكلُّ نصٍّ حرفيٍّ في `app` — لا التعليقات — يُقرأ.
     */
    public function test_no_label_written_by_the_server_says_sales(): void
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace(base_path().'/', '', $file->getPathname());
            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }
                if (str_contains($token[1], self::WORD) && ! in_array($token[1], self::HISTORICAL[$relative] ?? [], true)) {
                    $found[] = "{$relative}: {$token[1]}";
                }
            }
        }

        $this->assertSame([], $found, 'تسميةٌ من الخادم ما زالت تقول «المبيعات»');
    }

    public function test_the_english_dictionary_says_neither_word(): void
    {
        $dictionary = json_decode(file_get_contents(base_path('lang/en.json')), true);

        $arabic = array_filter(array_keys($dictionary), fn ($k) => str_contains($k, self::WORD));
        // `:sales` اسمُ متغيّرٍ في النصّ لا كلمةٌ تُقرأ
        $english = array_filter($dictionary, fn ($v) => preg_match('/\bsales\b/i', preg_replace('/:\w+/', '', (string) $v)));

        $this->assertSame([], array_values($arabic), 'مفتاحُ ترجمةٍ لنصٍّ يقول «المبيعات»');
        $this->assertSame([], $english, 'ترجمةٌ إنجليزية تقول Sales');
    }

    public function test_the_sales_role_keeps_its_key_and_shows_its_new_name(): void
    {
        $this->assertSame('موظف عروض ومشاريع', Roles::label('sales'));
        $this->assertContains('sales', Roles::keys());
        $this->assertContains('sales', Roles::STAFF);
        $this->assertArrayHasKey('sales', Permissions::MAP);

        $seller = User::create([
            'business_id' => $this->business->id, 'name' => 'سارة', 'email' => 's@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
        ]);
        $this->assertSame('sales', $seller->fresh()->role, 'المفتاحُ لا يُرحَّل');
        $this->assertSame('موظف عروض ومشاريع', $seller->roleLabel());

        // والوظيفةُ الافتراضيّة لنشاطٍ جديد تحمل الاسمَ الجديد — من `Roles` نفسها
        $this->assertSame('موظف عروض ومشاريع', JobTitle::roles()['sales']);

        $list = $this->page('admin.employees.index');
        $this->assertStringContainsString('موظف عروض ومشاريع', $list);
        $this->assertStringNotContainsString(self::WORD, $list);

        app()->setLocale('en');
        $this->assertSame('Quotations & projects staff', Roles::label('sales'));
    }

    /**
     * الشاشاتُ التي كانت تحمل الكلمة — كما تصل المتصفّح.
     *
     * لوحةُ التحكم وبطاقاتُها، وفهرسُ التقارير وملخّصُ الإيرادات وأداءُ
     * الموظفين والضريبة، وملخّصُ المالية، وملفُّ الموظف، ومركزُ القيادة.
     */
    public function test_the_screens_that_carried_the_word_now_say_what_they_count(): void
    {
        $employee = User::create([
            'business_id' => $this->business->id, 'name' => 'سارة', 'email' => 's@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
        ]);

        $pages = [
            'admin.dashboard' => [], 'admin.reports.index' => [], 'admin.reports.sales' => [],
            'admin.reports.staff' => [], 'admin.reports.vat' => [], 'admin.finance.summary' => [],
            'admin.employees.show' => ['id' => $employee->id], 'admin.command.index' => [],
            'admin.settings.index' => [],
        ];
        foreach ($pages as $route => $params) {
            $this->assertStringNotContainsString(self::WORD, $this->page($route, $params), "«{$route}» ما زالت تقول «المبيعات»");
        }

        $this->assertStringContainsString('إيرادات اليوم', $this->page('admin.dashboard'));
        $this->assertSame('ملخّص الإيرادات', collect(Reports::ALL)->firstWhere('key', 'sales')['title']);
        $this->assertStringContainsString('ملخّص الإيرادات', $this->page('admin.reports.index'));
    }

    /** والملفّاتُ التي تخرج من ملخّص الإيرادات: اسمُها ومحتواها */
    public function test_the_revenue_report_files_are_named_for_revenue(): void
    {
        $this->actingAs($this->owner);

        $csv = $this->get(route('admin.export.reports'))->assertOk();
        $this->assertStringContainsString('revenue-report', (string) $csv->headers->get('content-disposition'));
        $this->assertStringNotContainsString(self::WORD, $csv->streamedContent());
        $this->assertStringContainsString('— الإيرادات —', $csv->streamedContent());

        foreach (['admin.reports.xlsx', 'admin.reports.pdf'] as $route) {
            $file = $this->get(route($route))->assertOk();
            $this->assertStringContainsString('revenue-report', (string) $file->headers->get('content-disposition'), $route);
        }
    }

    /**
     * تسميةُ ما في القاعدة: الافتراضيُّ وحده، والمفاتيحُ والتاريخُ كما هي.
     */
    public function test_the_migration_renames_the_defaults_and_leaves_keys_custom_names_and_history_alone(): void
    {
        $bid = $this->business->id;

        // نشاطٌ رُحّل قبل اليوم: الوظيفةُ والحسابان بأسمائهم القديمة
        $title = JobTitle::create(['business_id' => $bid, 'name' => 'موظف مبيعات', 'role' => 'sales']);
        $custom = JobTitle::create(['business_id' => $bid, 'name' => 'مسؤول مبيعات', 'role' => 'sales']);
        $seller = User::create([
            'business_id' => $bid, 'name' => 'سارة', 'email' => 's@eventplus.om', 'password' => bcrypt('password'),
            'role' => 'sales', 'status' => 'نشط', 'job_title' => 'موظف مبيعات',
        ]);
        $other = User::create([
            'business_id' => $bid, 'name' => 'هدى', 'email' => 'h@eventplus.om', 'password' => bcrypt('password'),
            'role' => 'sales', 'status' => 'نشط', 'job_title' => 'مسؤول مبيعات',
        ]);
        Account::where('business_id', $bid)->where('system_key', 'sales')->update(['name' => 'إيراد المبيعات']);
        Account::where('business_id', $bid)->where('system_key', 'sales_returns')->update(['name' => 'مردودات المبيعات']);

        // ونشاطٌ ثانٍ سمّى حسابَه بنفسه، وعنده الوظيفةُ الجديدة باسمها
        $second = Business::create(['name' => 'حدث', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($second->id);
        Account::where('business_id', $second->id)->where('system_key', 'sales')->update(['name' => 'إيرادات الخدمات']);
        JobTitle::create(['business_id' => $second->id, 'name' => 'موظف مبيعات', 'role' => 'sales']);
        JobTitle::create(['business_id' => $second->id, 'name' => 'موظف عروض ومشاريع', 'role' => 'sales']);

        // وطلبٌ قديمٌ بقيده وحركته — التاريخُ الذي لا يُمسّ
        $product = Product::create(['business_id' => $bid, 'name' => 'كرسي', 'price' => 5, 'cost' => 2, 'quantity' => 8]);
        $order = Order::create([
            'business_id' => $bid, 'number' => 'INV-000777', 'customer_name' => 'زبون',
            'status' => 'مكتمل', 'payment_status' => 'مدفوع', 'payment_method' => 'نقدي',
            'subtotal' => 10, 'tax' => 0, 'total' => 10, 'ordered_at' => now()->subYear(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'name' => 'كرسي', 'price' => 5, 'cost' => 2, 'quantity' => 2, 'total' => 10]);
        Transaction::create([
            'business_id' => $bid, 'order_id' => $order->id, 'reference' => $order->number,
            'description' => 'مبيعات نقطة البيع', 'method' => 'نقدي', 'type' => 'دخل', 'amount' => 10, 'occurred_at' => now()->subYear(),
        ]);
        InventoryMovement::create(['business_id' => $bid, 'product_id' => $product->id, 'product_name' => 'كرسي', 'type' => 'بيع', 'quantity' => -2]);
        Ledger::post($bid, 'مبيعات '.$order->number, [
            ['account' => 'cash', 'debit' => 10],
            ['account' => 'sales', 'credit' => 10],
        ], now()->subYear(), 'مبيعات', null, null, $order);

        $history = fn () => [
            'orders' => DB::table('orders')->get()->toArray(),
            'order_items' => DB::table('order_items')->get()->toArray(),
            'transactions' => DB::table('transactions')->get()->toArray(),
            'inventory' => DB::table('inventory_movements')->get()->toArray(),
            'entries' => DB::table('journal_entries')->get()->toArray(),
            'lines' => DB::table('journal_lines')->get()->toArray(),
        ];
        $before = $history();
        $salesAccount = Ledger::account($bid, 'sales');
        $balance = $salesAccount->balance();

        $migration = require database_path('migrations/'.self::MIGRATION);
        $migration->up();

        $this->assertSame('موظف عروض ومشاريع', $title->fresh()->name);
        $this->assertSame('sales', $title->fresh()->role);
        $this->assertSame('موظف عروض ومشاريع', $seller->fresh()->job_title, 'الموظفُ يتبع وظيفتَه باسمها');
        $this->assertSame('sales', $seller->fresh()->role, 'والمفتاحُ لا يُرحَّل');
        $this->assertSame('مسؤول مبيعات', $custom->fresh()->name, 'ما سمّاه صاحبُ العمل يبقى');
        $this->assertSame('مسؤول مبيعات', $other->fresh()->job_title);

        $this->assertSame('إيرادات الفواتير', Ledger::account($bid, 'sales')->name);
        $this->assertSame('مردودات الفواتير', Ledger::account($bid, 'sales_returns')->name);
        $this->assertSame($salesAccount->id, Ledger::account($bid, 'sales')->id);
        $this->assertEqualsWithDelta($balance, Ledger::account($bid, 'sales')->balance(), 0.0005, 'الرصيدُ لا يتحرّك بتسمية');

        $this->assertSame('إيرادات الخدمات', Ledger::account($second->id, 'sales')->name);
        $this->assertSame(1, JobTitle::where('business_id', $second->id)->where('name', 'موظف مبيعات')->count(), 'نشاطٌ فيه الاسمُ الجديد يُترك');

        $this->assertEquals($before, $history(), 'الطلباتُ القديمة وقيودُها وحركاتُها كما كانت');
        $this->assertSame(1, JournalEntry::where('business_id', $bid)->where('source', 'مبيعات')->count());

        $migration->down();
        $this->assertSame('موظف مبيعات', $title->fresh()->name);
        $this->assertSame('موظف مبيعات', $seller->fresh()->job_title);
        $this->assertSame('إيراد المبيعات', Ledger::account($bid, 'sales')->name);
        $this->assertSame('إيرادات الخدمات', Ledger::account($second->id, 'sales')->name);
    }
}
