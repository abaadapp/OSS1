<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Expense;
use App\Models\JobTitle;
use App\Models\JournalEntry;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoicePayment;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Exports\Datasets;
use App\Support\Ledger;
use App\Support\ProjectWorkflow;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * كلُّ قائمةٍ تشغيليّة تُصدَّر بما تعرضه شاشتُها — لا أكثر ولا أقلّ.
 *
 * الحركة المالية، القيود، المصروفات، المستحقّات، الذمم، كشف البنك، الشيكات،
 * فواتير العملاء، أوامر الشراء، سندات المورّدين وسدادُها، المشاريع، الرواتب
 * وصرفُها، وسجلّ النشاط: كلٌّ بالاستعلام نفسِه الذي تقرؤه شاشتُه (`listQuery`)،
 * وبحارس قسمه وإذنه، وبعزل الشركة والفرع — ولا يُكتفى بإخفاء الزرّ.
 */
class EveryDailyListExportsWhatItsScreenShowsTest extends TestCase
{
    use RefreshDatabase;

    /** اسمُ مسار كلّ قائمة */
    private const ROUTES = [
        'transactions' => 'admin.finance.transactions.export', 'journal' => 'admin.finance.journal.export',
        'expenses' => 'admin.expenses.export', 'dues' => 'admin.finance.dues.export',
        'receivables' => 'admin.finance.receivables.export', 'bank-statement' => 'admin.finance.statement.export',
        'cheques' => 'admin.finance.cheques.export', 'customer-invoices' => 'admin.customerInvoices.export',
        'purchase-orders' => 'admin.purchases.orders.export', 'supplier-invoices' => 'admin.purchases.invoices.export',
        'supplier-payments' => 'admin.purchases.payments.export', 'projects' => 'admin.projects.export',
        'payroll' => 'admin.payroll.export', 'payroll-payments' => 'admin.payroll.payments.export',
        'activity' => 'admin.activity.export',
    ];

    private Business $business;

    private User $owner;

    private Branch $muscat;

    private Branch $salalah;

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
        $this->owner = User::create(['business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط']);
        Ledger::ensureSystemAccounts((int) $this->business->id);
        $this->actingAs($this->owner);
        $this->post(route('admin.finance.banks.store'), ['label' => 'مسقط', 'bank_name' => 'بنك مسقط', 'opening_balance' => 1000, 'opening_date' => '2026-01-01'])->assertSessionHasNoErrors();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(array $permissions, string $role = 'accountant'): User
    {
        return User::create(['business_id' => $this->business->id, 'name' => 'موظف', 'email' => 'staff'.uniqid().'@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions]);
    }

    private function csv(string $route, array $query = []): string
    {
        return $this->get(route($route, ['format' => 'csv'] + $query))->assertOk()->streamedContent();
    }

    private function invoice(string $number, string $day, float $total, ?Branch $branch = null, string $status = CustomerInvoice::ISSUED, ?Customer $customer = null): CustomerInvoice
    {
        return $this->sellThroughInvoice($this->business->id, [['quantity' => 1, 'unit_price' => $total]], array_filter([
            'number' => $number, 'issued_at' => $day, 'branch_id' => $branch?->id, 'status' => $status,
            'customer_id' => $customer?->id, 'customer_name' => $customer?->name,
        ], fn ($v) => $v !== null));
    }

    /* ─────────────────────────── كلُّ بابٍ يُفتح بصيغه الثلاث ─────────────────────────── */

    public function test_every_list_export_opens_in_all_three_formats(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(Datasets::ALL), array_keys(self::ROUTES), 'قائمةٌ مسجَّلةٌ بلا مسار — أو العكس');

        foreach (self::ROUTES as $key => $name) {
            $this->assertTrue(Route::has($name), $name);
            $xlsx = $this->get(route($name, 'xlsx'))->assertOk();
            $this->assertStringStartsWith('PK', $xlsx->streamedContent(), "{$key}: إكسل حقيقيّ (zip)");
            $csv = $this->get(route($name, 'csv'))->assertOk();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv->streamedContent(), "{$key}: CSV بعلامة UTF-8");
            $pdf = $this->get(route($name, 'pdf'))->assertOk();
            $this->assertStringStartsWith('application/pdf', (string) $pdf->headers->get('content-type'), "{$key}: PDF");
            $this->get(route($name, 'docx'))->assertNotFound();
        }
    }

    /* ─────────────────────────── الملفُّ بمرشّحات الشاشة ─────────────────────────── */

    public function test_the_transactions_file_follows_the_search_and_the_dates_on_the_screen(): void
    {
        foreach ([['TX-KEEP-1', '2026-01-10 10:00:00'], ['TX-KEEP-2', '2026-01-31 23:50:00'], ['TX-LATE', '2026-02-01 00:05:00'], ['TX-OTHER', '2026-01-15 09:00:00']] as [$ref, $at]) {
            Transaction::create(['business_id' => $this->business->id, 'reference' => $ref, 'description' => str_contains($ref, 'KEEP') ? 'إيجار القاعة' : 'شيءٌ آخر',
                'method' => 'نقدي', 'type' => 'دخل', 'amount' => 10, 'occurred_at' => $at, 'employee_name' => 'المالك']);
        }
        $query = ['q' => 'TX-KEEP', 'from' => '2026-01-01', 'to' => '2026-01-31'];

        $screen = collect($this->get(route('admin.finance.transactions', $query))->viewData('page')['props']['rows'])->pluck('reference')->sort()->values()->all();
        $this->assertSame(['TX-KEEP-1', 'TX-KEEP-2'], $screen);

        $csv = $this->csv('admin.finance.transactions.export', $query);
        foreach ($screen as $ref) {
            $this->assertStringContainsString($ref, $csv);
        }
        $this->assertStringNotContainsString('TX-LATE', $csv, 'ما بعد منتصف ليل ٣١ يناير خارج');
        $this->assertStringNotContainsString('TX-OTHER', $csv, 'والبحثُ يُحمل إلى الملفّ');
        $this->assertStringContainsString('من 2026-01-01 إلى 2026-01-31', $csv, 'والمدّةُ في الترويسة');
        $this->assertStringContainsString('البحث: TX-KEEP', $csv, 'والمرشّحُ في الترويسة');
    }

    public function test_the_invoice_list_file_follows_customer_status_and_totals_issued_only(): void
    {
        $energy = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة الطاقة']);
        $culture = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة الثقافة']);
        $this->invoice('INV-A', '2026-01-05', 100, null, CustomerInvoice::ISSUED, $energy);
        $this->invoice('INV-B', '2026-01-06', 300, null, CustomerInvoice::ISSUED, $energy);
        $this->invoice('INV-X', '2026-01-07', 999, null, CustomerInvoice::CANCELLED, $energy);
        $this->invoice('INV-D', '2026-01-08', 555, null, CustomerInvoice::DRAFT, $energy);
        $this->invoice('INV-C', '2026-01-09', 700, null, CustomerInvoice::ISSUED, $culture);

        $query = ['customer_id' => $energy->id, 'from' => '2026-01-01', 'to' => '2026-01-31'];
        $screen = collect($this->get(route('admin.customerInvoices.index', $query))->viewData('page')['props']['invoices'])->pluck('number')->sort()->values()->all();
        $this->assertSame(['INV-A', 'INV-B', 'INV-D', 'INV-X'], $screen);

        $csv = $this->csv('admin.customerInvoices.export', $query);
        foreach ($screen as $number) {
            $this->assertStringContainsString($number, $csv);
        }
        $this->assertStringNotContainsString('INV-C', $csv, 'عميلٌ آخر خارج الملفّ');
        $total = collect(explode("\n", $csv))->first(fn ($l) => str_contains($l, 'الإجمالي (الصادرة فقط)'));
        $this->assertNotNull($total);
        $this->assertSame(['400', '0', '400'], array_slice(array_map(fn ($v) => (string) (0 + (float) $v), str_getcsv($total, escape: '')), -3), 'الإجمالي والمدفوع والمتبقّي للصادرة وحدها');
        $this->assertStringContainsString('العميل: وزارة الطاقة', $csv);
    }

    public function test_a_restricted_clerk_never_exports_another_branchs_invoices(): void
    {
        $this->invoice('INV-MUSCAT', '2026-01-05', 100, $this->muscat);
        $this->invoice('INV-SALALAH', '2026-01-06', 200, $this->salalah);
        $clerk = $this->staff(['dashboard', 'finance']);
        $clerk->branches()->sync([$this->muscat->id]);
        $this->actingAs($clerk);

        $csv = $this->csv('admin.customerInvoices.export');
        $this->assertStringContainsString('INV-MUSCAT', $csv);
        $this->assertStringNotContainsString('INV-SALALAH', $csv, 'فرعٌ لم يُمنحه خارج الملفّ');

        /* وكتابةُ فرعٍ آخر في الرابط لا تفتحه */
        $response = $this->get(route('admin.customerInvoices.export', ['format' => 'csv', 'branch_id' => $this->salalah->id]));
        $this->assertTrue($response->status() === 403 || ! str_contains((string) $response->streamedContent(), 'INV-SALALAH'));
    }

    public function test_the_projects_file_reads_the_start_date_the_manual_stage_and_hides_money_without_permission(): void
    {
        $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة الطاقة']);
        foreach ([['EP-IN', '2026-01-20', ProjectWorkflow::DELIVERY], ['EP-OUT', '2026-02-02', ProjectWorkflow::SCOPE]] as [$code, $start, $stage]) {
            Project::create(['business_id' => $this->business->id, 'customer_id' => $customer->id, 'code' => $code, 'name' => 'مشروع '.$code,
                'status' => Project::CONFIRMED, 'starts_at' => $start, 'approved_value' => 1000, 'quoted_value' => 1000, 'workflow_stage' => $stage]);
        }

        $csv = $this->csv('admin.projects.export', ['from' => '2026-01-01', 'to' => '2026-01-31']);
        $this->assertStringContainsString('EP-IN', $csv);
        $this->assertStringNotContainsString('EP-OUT', $csv);
        $this->assertStringContainsString('التسليم', $csv, 'المرحلةُ اليدويّة كما اختارها الفريق');
        $this->assertStringContainsString('القيمة المعتمدة', $csv);
        $this->assertSame(ProjectWorkflow::DELIVERY, Project::where('code', 'EP-IN')->value('workflow_stage'), 'والتصديرُ لا يحرّك المرحلة');

        $this->actingAs($this->staff(['dashboard', 'projects'], 'sales'));
        $plain = $this->csv('admin.projects.export');
        $this->assertStringNotContainsString('القيمة المعتمدة', $plain, 'المالُ التجاريّ لمن يقرؤه وحده');
        $this->assertStringNotContainsString('1000', $plain);
    }

    public function test_supplier_payments_are_read_by_payment_date_and_bills_by_bill_date(): void
    {
        $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'شركة النور']);
        $bill = SupplierInvoice::create(['business_id' => $this->business->id, 'supplier_id' => $supplier->id, 'supplier_ref' => 'SI-DEC',
            'issued_at' => '2025-12-20', 'subtotal' => 500, 'tax' => 0, 'total' => 500, 'paid' => 0, 'status' => 'غير مدفوع',
            'approval_status' => SupplierInvoices::APPROVED]);
        $entry = JournalEntry::create(['business_id' => $this->business->id, 'number' => 'JE-1', 'entry_date' => '2026-01-15', 'description' => 'سداد', 'source' => 'يدوي']);
        SupplierInvoicePayment::create(['business_id' => $this->business->id, 'supplier_invoice_id' => $bill->id, 'journal_entry_id' => $entry->id,
            'amount' => 200, 'paid_at' => '2026-01-15', 'paid_from' => SupplierInvoicePayment::BANK]);

        $january = ['from' => '2026-01-01', 'to' => '2026-01-31'];
        $this->assertStringNotContainsString('SI-DEC', $this->csv('admin.purchases.invoices.export', $january), 'سندُ ديسمبر ليس في يناير');
        $payments = $this->csv('admin.purchases.payments.export', $january);
        $this->assertStringContainsString('SI-DEC', $payments, 'لكنّ دفعتَه في يناير');
        $this->assertStringContainsString('تاريخ الدفع', $payments);
    }

    public function test_payroll_reads_the_salary_period_and_its_payments_the_paid_date(): void
    {
        $salem = $this->staff(['dashboard']);
        $run = PayrollRun::create(['business_id' => $this->business->id, 'number' => 'PR-2026-01', 'period' => '2026-01-01', 'status' => 'مصروفة', 'gross' => 500, 'deductions' => 0, 'net' => 500]);
        PayrollLine::create(['payroll_run_id' => $run->id, 'user_id' => $salem->id, 'employee_name' => 'سالم', 'basic' => 500, 'allowances' => 0, 'overtime' => 0,
            'deductions' => 0, 'net' => 500, 'payment_method' => 'تحويل', 'paid' => true, 'paid_at' => '2026-02-03 10:00:00']);

        $this->assertStringContainsString('سالم', $this->csv('admin.payroll.export', ['from' => '2026-01-01', 'to' => '2026-01-31']), 'راتبُ يناير في فترة يناير');
        $this->assertStringNotContainsString('سالم', $this->csv('admin.payroll.payments.export', ['from' => '2026-01-01', 'to' => '2026-01-31']), 'وصرفُه ليس في يناير');
        $this->assertStringContainsString('سالم', $this->csv('admin.payroll.payments.export', ['from' => '2026-02-01', 'to' => '2026-02-28']), 'بل في فبراير');
    }

    public function test_the_bank_statement_period_keeps_the_running_balance(): void
    {
        foreach ([['B-1', '2026-01-05', 'دخل', 100], ['B-2', '2026-02-05', 'مصروف', -30], ['B-3', '2026-02-20', 'دخل', 50]] as [$ref, $at, $type, $amount]) {
            Transaction::create(['business_id' => $this->business->id, 'reference' => $ref, 'description' => $ref, 'method' => 'تحويل بنكي',
                'type' => $type, 'amount' => $amount, 'occurred_at' => $at.' 10:00:00', 'employee_name' => 'المالك']);
        }

        $csv = $this->csv('admin.finance.statement.export', ['from' => '2026-02-01', 'to' => '2026-02-28']);
        $this->assertStringNotContainsString('B-1', $csv);
        $this->assertStringContainsString('B-2', $csv);
        $this->assertStringContainsString('رصيد أوّل المدّة', $csv);
        $opening = collect(explode("\n", $csv))->first(fn ($l) => str_contains($l, 'رصيد أوّل المدّة'));
        $this->assertStringContainsString('1,100', $opening, 'الافتتاحيّ + حركةُ يناير — رصيدٌ لا مجموعُ حركات');
    }

    /* ─────────────────────────── الأبواب والعزل ─────────────────────────── */

    public function test_payroll_and_supplier_bill_exports_ask_for_their_own_permission(): void
    {
        $this->actingAs($this->staff(['dashboard', 'employees', 'purchases']));
        foreach (['admin.payroll.export', 'admin.payroll.payments.export', 'admin.purchases.invoices.export', 'admin.purchases.payments.export'] as $name) {
            $this->get(route($name, 'csv'))->assertForbidden();
        }
        $this->get(route('admin.purchases.orders.export', 'csv'))->assertOk();

        /* ومن لا يملك القسمَ يُردّ عند باب المسار — ولو كتبه بيده */
        $this->actingAs($this->staff(['dashboard']));
        foreach (self::ROUTES as $name) {
            $this->get(route($name, 'csv'))->assertForbidden();
        }
    }

    public function test_a_wrong_date_is_refused_with_422_not_answered_with_another_period(): void
    {
        foreach (['transactions' => ['from' => '2026-02-30'], 'projects' => ['from' => '2026-02-10', 'to' => '2026-02-01'], 'activity' => ['to' => 'غدًا']] as $key => $query) {
            $this->get(route(self::ROUTES[$key], ['format' => 'csv'] + $query))->assertStatus(422);
        }
        $this->get(route('admin.customerInvoices.export', ['format' => 'csv', 'due_from' => '2026-13-01']))->assertStatus(422);

        /* والشاشةُ تتجاهل التاريخَ الخاطئ ولا تسقط به */
        $this->get(route('admin.finance.transactions', ['from' => '2026-02-30']))->assertOk();
        $this->get(route('admin.projects.index', ['from' => 'أمس']))->assertOk();
    }

    public function test_another_company_never_appears_in_a_list_export(): void
    {
        $other = Business::create(['name' => 'شركة أخرى', 'type' => 'عام', 'status' => 'نشط']);
        Transaction::create(['business_id' => $other->id, 'reference' => 'TX-NEIGHBOUR', 'description' => 'جار', 'method' => 'نقدي', 'type' => 'دخل', 'amount' => 1, 'occurred_at' => now()]);
        Expense::create(['business_id' => $other->id, 'reference' => 'EXP-NEIGHBOUR', 'type' => 'إيجار', 'description' => 'EXP-NEIGHBOUR', 'amount' => 1, 'method' => 'نقدي', 'spent_at' => now(), 'status' => 'غير مدفوع']);
        ActivityLog::create(['business_id' => $other->id, 'user_name' => 'جار', 'action' => 'created', 'description' => 'نشاط الجار']);
        PurchaseOrder::create(['business_id' => $other->id, 'number' => 'PO-NEIGHBOUR', 'status' => 'مسودة', 'total' => 1, 'ordered_at' => now()]);
        $this->sellThroughInvoice($other->id, [['quantity' => 1, 'unit_price' => 5]], ['number' => 'INV-NEIGHBOUR']);

        foreach (self::ROUTES as $name) {
            $csv = $this->csv($name);
            $this->assertStringNotContainsString('NEIGHBOUR', $csv, $name);
            $this->assertStringNotContainsString('الجار', $csv, $name);
        }
    }

    public function test_the_activity_file_leaves_owners_out_like_its_screen(): void
    {
        $clerk = $this->staff(['dashboard', 'settings']);
        ActivityLog::create(['business_id' => $this->business->id, 'user_id' => $this->owner->id, 'user_name' => 'المالك', 'action' => 'created', 'description' => 'فعلُ المالك']);
        ActivityLog::create(['business_id' => $this->business->id, 'user_id' => $clerk->id, 'user_name' => 'موظف', 'action' => 'updated', 'description' => 'فعلُ الموظف']);

        $csv = $this->csv('admin.activity.export');
        $this->assertStringContainsString('فعلُ الموظف', $csv);
        $this->assertStringNotContainsString('فعلُ المالك', $csv);
    }

    public function test_the_expense_file_counts_each_expense_once_and_reads_a_custom_range(): void
    {
        foreach ([['E-1', '2026-01-03', 'مدفوع'], ['E-2', '2026-01-31', 'غير مدفوع'], ['E-3', '2026-02-01', 'مدفوع']] as [$ref, $day, $status]) {
            Expense::create(['business_id' => $this->business->id, 'reference' => $ref, 'type' => 'إيجار', 'description' => $ref,
                'amount' => 50, 'method' => 'نقدي', 'employee_name' => 'المالك', 'spent_at' => $day, 'status' => $status]);
        }

        $query = ['from' => '2026-01-01', 'to' => '2026-01-31'];
        $props = $this->get(route('admin.expenses.index', $query))->viewData('page')['props'];
        $this->assertSame(2, $props['monthCount'], 'الشاشةُ تجمع الفترةَ المخصَّصة');

        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($path, $this->get(route('admin.expenses.export', ['format' => 'xlsx'] + $query))->streamedContent());
        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, false, false, false);
        @unlink($path);
        $refs = collect($rows)->pluck(1)->filter(fn ($v) => is_string($v) && str_starts_with($v, 'E-'))->values()->all();
        $this->assertSame(['E-2', 'E-1'], $refs, 'كلُّ مصروفٍ مرّةً، والثالثُ خارج');
        $total = collect($rows)->first(fn ($r) => ($r[0] ?? null) === 'الإجمالي' && is_numeric($r[8] ?? null));
        $this->assertEqualsWithDelta(100.0, (float) $total[8], 0.0005, 'والإجماليُّ رقمٌ يُجمع');
    }
}
