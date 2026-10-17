<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\CustomAlert;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\InventoryMovement;
use App\Models\JobTitle;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BackupService;
use App\Support\CustomerInvoices;
use App\Support\Demo;
use App\Support\EmployeeAccess;
use App\Support\Ledger;
use App\Support\Permissions;
use App\Support\Reports;
use App\Support\TenantTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * «المبيعات» تخرج من النظام — وتاريخُ الشركة الماليّ يبقى كما هو.
 *
 * الطلباتُ القديمة (`Order`) نظامُ بيعٍ من أيّام متجر التجزئة: قائمةٌ وصفحةٌ
 * وتصحيحٌ وحالاتٌ وأوراقٌ وتصدير. خرج ذلك كلُّه بأبوابه — لا عنصرَ في
 * القائمة، ولا مسارَ يُفتح باليد، ولا قسمَ صلاحيات، ولا شيءَ يُنشئ طلبًا.
 *
 * وفواتيرُ العملاء لا تخرج معه: هي مستندُ المشاريع الماليّ (الدفعةُ المقدّمة
 * والفاتورةُ النهائيّة والتحصيلُ والإشعارُ الدائن). كانت تتبع قسمَ «المبيعات»
 * (`orders`) وصارت تتبع «المالية» — القسمُ يفتح الشاشة، والإصدارُ والتحصيلُ
 * أفعالٌ تُمنح بأسمائها.
 *
 * ولا يُمحى ما وقع: صفوفُ الطلبات وقيودُها وحركاتُ مخزونها باقيةٌ لا تُعكس.
 */
class TheSalesSectionLeavesAndTheBooksKeepItsHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_17_133000_the_sales_section_leaves_and_its_keys_leave_with_it.php';

    private Business $business;

    private Branch $branch;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
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
        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة الثقافة']);
    }

    private function staff(string $email, string $role, ?array $permissions = null): User
    {
        return User::create([
            'business_id' => $this->business->id, 'name' => 'موظّف', 'email' => $email,
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
            'permissions' => $permissions,
        ]);
    }

    private function draft(): CustomerInvoice
    {
        return CustomerInvoices::create($this->business->id, $this->customer, [], [
            ['description' => 'تجهيز قاعة', 'quantity' => 1, 'unit_price' => 400],
        ], $this->owner->id);
    }

    /** بيعةٌ قديمة كما كتبها الصندوق: صفٌّ وبندٌ وحركةُ صندوقٍ ومخزونٍ وقيد */
    private function oldSale(): Order
    {
        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'كرسي', 'price' => 5, 'cost' => 2, 'quantity' => 8,
        ]);
        $order = Order::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id, 'number' => 'INV-000777',
            'customer_id' => $this->customer->id, 'customer_name' => $this->customer->name,
            'status' => 'مكتمل', 'payment_status' => 'مدفوع', 'payment_method' => 'نقدي',
            'subtotal' => 10, 'tax' => 0, 'total' => 10, 'ordered_at' => now()->subYear(),
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'name' => 'كرسي',
            'price' => 5, 'cost' => 2, 'quantity' => 2, 'total' => 10,
        ]);
        Transaction::create([
            'business_id' => $this->business->id, 'order_id' => $order->id, 'reference' => $order->number,
            'description' => 'مبيعات نقطة البيع', 'method' => 'نقدي', 'type' => 'دخل', 'amount' => 10,
            'occurred_at' => now()->subYear(),
        ]);
        InventoryMovement::create([
            'business_id' => $this->business->id, 'product_id' => $product->id, 'product_name' => 'كرسي',
            'type' => 'بيع', 'quantity' => -2,
        ]);
        Ledger::post($this->business->id, 'مبيعات '.$order->number, [
            ['account' => 'cash', 'debit' => 10],
            ['account' => 'sales', 'credit' => 10],
        ], now()->subYear(), 'مبيعات', null, null, $order);

        return $order;
    }

    private function migrate(): void
    {
        (require database_path('migrations/'.self::MIGRATION))->up();
    }

    /* ═══════════════ ١–٤: خرجت من المنتج بأبوابها ═══════════════ */

    public function test_the_sidebar_has_no_sales_item_and_invoices_sit_under_finance(): void
    {
        $nav = (string) file_get_contents(resource_path('js/lib/nav.ts'));

        $this->assertStringNotContainsString("label: 'المبيعات'", $nav);
        $this->assertStringNotContainsString("route: 'admin.orders.index'", $nav);
        $this->assertStringNotContainsString("section: 'orders'", $nav);
        $this->assertMatchesRegularExpression(
            "/label: 'فواتير العملاء',\\s*icon: FileText,\\s*route: 'admin.customerInvoices.index',\\s*section: 'finance'/",
            $nav,
        );
        $this->assertFileDoesNotExist(resource_path('js/Pages/Admin/Orders/Index.tsx'));
        $this->assertFileDoesNotExist(resource_path('js/Pages/Admin/Orders/Show.tsx'));
    }

    public function test_no_route_manages_an_old_order(): void
    {
        $left = collect(Route::getRoutes()->getRoutesByName())->keys()
            ->filter(fn ($name) => str_starts_with($name, 'admin.orders.') || str_starts_with($name, 'pos.'))
            ->values()->all();

        $this->assertSame([], $left, 'بقي مسارٌ للطلبات القديمة');
        $this->assertFalse(Route::has('admin.export.orders'));
        $this->assertFalse(Route::has('admin.customers.statement'), 'كشفُ العميل من الطلبات القديمة');

        foreach (['OrderController', 'OrderDetailController', 'OrderEditController'] as $controller) {
            $this->assertFileDoesNotExist(app_path("Http/Controllers/Admin/{$controller}.php"));
        }
    }

    public function test_the_old_addresses_answer_not_found_even_typed_by_hand(): void
    {
        $order = $this->oldSale();
        $this->actingAs($this->owner);

        foreach ([
            '/admin/orders', '/admin/orders/export-xlsx', '/admin/orders/export-pdf', '/admin/export/orders',
            "/admin/orders/{$order->number}", "/admin/orders/{$order->number}/pdf", "/admin/orders/{$order->number}/receipt",
            "/admin/orders/{$order->number}/tax-invoice", "/admin/orders/{$order->number}/delivery-note",
            "/admin/customers/{$this->customer->id}/statement",
        ] as $url) {
            $this->get($url)->assertNotFound();
        }

        $this->put("/admin/orders/{$order->number}/details", ['recipient_name' => 'س'])->assertNotFound();
        $this->post("/admin/orders/{$order->number}/status", ['status' => 'ملغي'])->assertNotFound();
        $this->put("/admin/orders/{$order->number}/payment", ['payment_method' => 'بطاقة'])->assertNotFound();

        $this->assertSame('مكتمل', $order->fresh()->status, 'تغيّرت حالُ طلبٍ قديم');
    }

    public function test_nothing_in_the_code_creates_or_rewrites_an_order(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            // `PurchaseOrder::create` و`ChangeOrder::create` ليسا طلبَ بيع
            if (preg_match('/(?<![A-Za-z])(Order|OrderItem)::(create|insert|updateOrCreate|firstOrCreate)\(|new Order\(/', $code)) {
                $offenders[] = str_replace(app_path().'/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'ما زال في الكود ما يكتب طلبًا');

        foreach (['OrderCorrection', 'OrderTransition', 'FlowerOrder', 'AddonStock', 'OrderStatus'] as $gone) {
            $this->assertFileDoesNotExist(app_path("Support/{$gone}.php"));
        }
    }

    /* ═══════════════ ٥–١١: فواتيرُ العملاء تتبع «المالية» ═══════════════ */

    public function test_no_permission_section_is_called_sales_anymore(): void
    {
        $this->assertNotContains('orders', Permissions::SECTIONS);
        $this->assertArrayNotHasKey('orders', Permissions::sectionLabels());
        $this->assertArrayNotHasKey('order.edit', Permissions::ACTIONS);
        $this->assertArrayNotHasKey('orders', Permissions::ROUTES);

        foreach (Permissions::MAP as $role => $sections) {
            $this->assertNotContains('orders', $sections, "الدور {$role} ما زال يملك «المبيعات»");
        }

        $keys = collect(EmployeeAccess::catalog())->flatMap(fn ($g) => array_column($g['items'], 'key'))->all();
        $this->assertNotContains('orders', $keys, 'شاشةُ الصلاحيات تعرض قسمًا ميّتًا');
        $this->assertNotContains('order.edit', $keys);
        $labels = collect(EmployeeAccess::catalog())->pluck('label')->all();
        $this->assertNotContains('العملاء والمبيعات', $labels);

        foreach (EmployeeAccess::PARENTS as $action => $parents) {
            $this->assertNotContains('orders', $parents, "الفعل {$action} يقترح قسمًا خرج");
        }
    }

    public function test_customer_invoices_and_collections_follow_finance(): void
    {
        foreach ([
            'admin.customerInvoices.index', 'admin.customerInvoices.create', 'admin.customerInvoices.show',
            'admin.customerInvoices.store', 'admin.customerInvoices.issue', 'admin.customerInvoices.cancel',
            'admin.customerInvoices.creditNote', 'admin.customerInvoices.pdf',
            'admin.customerPayments.store', 'admin.customerPayments.cancel',
        ] as $route) {
            $this->assertTrue(Route::has($route), $route);
            $this->assertSame('finance', Permissions::sectionFromRoute($route), $route);
        }

        $this->assertSame('finance', collect(Reports::ALL)->firstWhere('key', 'invoices')['section']);
        $this->assertSame('finance', CustomAlert::METRICS['overdue_invoices']['section']);
    }

    public function test_the_accountant_opens_customer_invoices_by_role(): void
    {
        $accountant = $this->staff('acc@eventplus.om', 'accountant');

        $this->actingAs($accountant)->get(route('admin.customerInvoices.index'))->assertOk();
        $this->actingAs($accountant)->get(route('admin.customerInvoices.create'))->assertOk();
    }

    public function test_finance_opens_the_screen_but_issuing_still_needs_its_action(): void
    {
        $clerk = $this->staff('clerk@eventplus.om', 'employee', ['finance']);
        $draft = $this->draft();

        $this->actingAs($clerk)->get(route('admin.customerInvoices.index'))->assertOk();
        $this->actingAs($clerk)->post(route('admin.customerInvoices.issue', $draft->id))->assertForbidden();
        $this->assertSame(CustomerInvoice::DRAFT, $draft->fresh()->status);

        $clerk->update(['permissions' => ['finance', Permissions::CUSTOMER_INVOICE_ISSUE]]);
        $this->actingAs($clerk->fresh())->post(route('admin.customerInvoices.issue', $draft->id))->assertSessionHasNoErrors();
        $this->assertSame(CustomerInvoice::ISSUED, $draft->fresh()->status);
    }

    public function test_finance_opens_the_screen_but_collecting_and_cancelling_still_need_their_actions(): void
    {
        $clerk = $this->staff('clerk@eventplus.om', 'employee', ['finance']);
        $invoice = CustomerInvoices::issue($this->draft(), $this->owner->id);

        $this->actingAs($clerk)->post(route('admin.customerPayments.store'), [
            'customer_id' => $this->customer->id, 'amount' => 100, 'method' => 'نقدي',
            'occurred_at' => now()->toDateString(),
        ])->assertForbidden();
        $this->actingAs($clerk)->post(route('admin.customerInvoices.cancel', $invoice->id), ['reason' => 'خطأ'])
            ->assertForbidden();
        $this->actingAs($clerk)->post(route('admin.customerInvoices.creditNote', $invoice->id), ['amount' => 10, 'reason' => 'خصم'])
            ->assertForbidden();

        $this->assertSame(400.0, $invoice->fresh()->outstanding());
        $this->assertSame(CustomerInvoice::ISSUED, $invoice->fresh()->status);
    }

    public function test_holding_the_old_sales_key_alone_does_not_open_customer_invoices(): void
    {
        $former = $this->staff('former@eventplus.om', 'employee', ['orders', 'customers', Permissions::CUSTOMER_INVOICE_ISSUE]);
        $seller = $this->staff('seller@eventplus.om', 'sales');

        foreach ([$former, $seller] as $who) {
            $this->actingAs($who)->get(route('admin.customerInvoices.index'))->assertForbidden();
            $this->actingAs($who)->get(route('admin.customerInvoices.create'))->assertForbidden();
        }

        // والبائعُ يبقى على ما يحتاجه: العروضُ والمشاريعُ والعملاءُ والمنتجات — بلا مالية
        $this->assertSame(['dashboard', 'quotations', 'projects', 'customers', 'products'], Permissions::MAP['sales']);
        $this->assertFalse($seller->allows('finance'));
        $this->actingAs($seller)->get(route('admin.quotations.index'))->assertOk();
        $this->actingAs($seller)->get(route('admin.projects.index'))->assertOk();
        $this->actingAs($seller)->get(route('admin.customers.index'))->assertOk();
        $this->actingAs($seller)->get(route('admin.products.index'))->assertOk();
    }

    /* ═══════════════ ١٥–١٧: الدفترُ والتاريخُ والمخزون لا تتحرّك ═══════════════ */

    public function test_the_books_the_old_rows_and_the_stock_history_stay_exactly_as_they_were(): void
    {
        $order = $this->oldSale();
        $product = Product::where('business_id', $this->business->id)->sole();

        $before = [
            'entries' => JournalEntry::where('business_id', $this->business->id)->count(),
            'sales' => Ledger::balance($this->business->id, 'sales'),
            'cash' => Ledger::balance($this->business->id, 'cash'),
            'orders' => Order::count(), 'items' => OrderItem::count(),
            'transactions' => Transaction::count(), 'movements' => InventoryMovement::count(),
            'qty' => (int) $product->quantity,
        ];

        $this->migrate();
        $this->actingAs($this->owner)->get("/admin/orders/{$order->number}")->assertNotFound();
        $this->actingAs($this->owner)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($this->owner)->get(route('admin.finance.index'))->assertOk();

        $this->assertSame($before, [
            'entries' => JournalEntry::where('business_id', $this->business->id)->count(),
            'sales' => Ledger::balance($this->business->id, 'sales'),
            'cash' => Ledger::balance($this->business->id, 'cash'),
            'orders' => Order::count(), 'items' => OrderItem::count(),
            'transactions' => Transaction::count(), 'movements' => InventoryMovement::count(),
            'qty' => (int) $product->fresh()->quantity,
        ], 'خروجُ الشاشة حرّك دفترًا أو محا تاريخًا');

        // والجداولُ باقيةٌ في النسخ الاحتياطيّ وعزلِ المستأجر — بلا شاشة
        foreach (['orders', 'order_items', 'order_item_addons', 'order_edits'] as $table) {
            $this->assertContains($table, TenantTables::all());
        }
    }

    /* ═══════════════ ١٨–١٩: لا رابطَ ميّت ═══════════════ */

    public function test_search_gives_no_link_to_an_old_order(): void
    {
        $order = $this->oldSale();

        $res = $this->actingAs($this->owner)->getJson(route('admin.search', ['q' => $order->number]));

        $this->assertStringNotContainsString('/admin/orders', $res->getContent());
        $this->assertNotContains('الطلبات', array_column($res->json('groups') ?? [], 'title'));
    }

    public function test_reports_alerts_and_bells_carry_no_sales_link(): void
    {
        foreach (Reports::ALL as $report) {
            $this->assertTrue(Route::has($report['route']), $report['route']);
            $this->assertStringStartsNotWith('admin.orders.', $report['route']);
            $this->assertContains($report['section'], Permissions::SECTIONS, $report['key']);
        }

        foreach (CustomAlert::METRICS as $key => $metric) {
            $this->assertContains($metric['section'], Permissions::SECTIONS, $key);
        }

        // وتراجعُ الإيراد يفتح الفواتيرَ التي قيس منها — لا «المبيعات»
        $this->actingAs($this->owner);
        $invoice = CustomerInvoices::issue($this->draft(), $this->owner->id);
        $invoice->update(['issued_at' => now()->subMonthNoOverflow()->startOfMonth()->toDateString()]);
        $urls = array_column(Demo::smartAlertsFor($this->business->id), 'url');
        $this->assertNotEmpty($urls);
        foreach ($urls as $url) {
            $this->assertStringNotContainsString('/admin/orders', $url);
        }

        $feed = $this->getJson(route('admin.notifications.feed'))->assertOk()->json();
        $this->assertArrayNotHasKey('latest_order', $feed);
    }

    /* ═══════════════ ٢٠: قائمةٌ قديمة تحمل «المبيعات» لا تكسر الحساب ═══════════════ */

    public function test_an_old_hand_written_list_with_the_sales_key_is_cleaned_and_the_account_keeps_working(): void
    {
        $old = $this->staff('old@eventplus.om', 'sales', ['dashboard', 'orders', 'order.edit', 'customers']);
        $old->update(['job_title' => 'مدير']);
        CustomAlert::create([
            'business_id' => $this->business->id, 'type' => 'reminder', 'message' => 'راجع الفواتير',
            'section' => 'orders', 'due_at' => now()->addDay(),
        ]);

        $this->migrate();

        $this->assertSame(['dashboard', 'customers'], $old->fresh()->permissions);
        $this->assertSame('finance', CustomAlert::sole()->section);

        // ومن يتبع دورَه لا يُمسّ
        $heir = $this->staff('heir@eventplus.om', 'sales');
        $this->migrate();
        $this->assertNull($heir->fresh()->permissions);

        // ونموذجُ الموظّف يُفتح ويُحفظ — بلا مفتاحٍ لا يراه أحد
        $this->actingAs($this->owner)->get(route('admin.employees.edit', $old->id))->assertOk()
            ->assertInertia(fn ($p) => $p->where('employee.permissions', ['dashboard', 'customers']));
        $this->actingAs($this->owner)->put(route('admin.employees.update', $old->id), [
            'name' => 'موظّفٌ قديم', 'email' => $old->email, 'job_title' => $old->job_title, 'branch' => '',
            'manual_permissions' => 1, 'permissions' => ['dashboard', 'customers'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('موظّفٌ قديم', $old->fresh()->name);

        // ويدخل إلى ما بقي له
        auth()->logout();
        $this->post(route('login.attempt'), ['email' => 'old@eventplus.om', 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_an_older_backup_does_not_bring_the_sales_key_back(): void
    {
        $order = $this->oldSale();
        $payload = json_encode([
            'meta' => ['app' => 'EventPlusOSS', 'version' => BackupService::VERSION],
            'business' => ['name' => 'افنت بلس'],
            'users' => [[
                'email' => 'restored@eventplus.om', 'name' => 'مستعاد', 'role' => 'employee', 'status' => 'نشط',
                'permissions' => ['orders', 'order.edit', 'finance'],
            ]],
            'orders' => [$order->only(['id', 'business_id', 'branch_id', 'number', 'status', 'payment_status', 'subtotal', 'tax', 'total'])],
        ], JSON_UNESCAPED_UNICODE);

        $this->actingAs($this->owner)->post(route('admin.backup.restore'), [
            'backup' => UploadedFile::fake()->createWithContent('backup.json', $payload),
        ])->assertSessionHas('toast.type', 'success');

        $this->assertSame(['finance'], User::where('email', 'restored@eventplus.om')->firstOrFail()->permissions);
        // والطلبُ يعود صفًّا تاريخيًّا — ولا بابَ يعود معه
        $this->assertSame(1, Order::where('number', 'INV-000777')->count());
        $this->get("/admin/orders/{$order->number}")->assertNotFound();
    }
}
