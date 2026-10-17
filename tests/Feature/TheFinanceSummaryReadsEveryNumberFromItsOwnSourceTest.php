<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\PayrollRun;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Bank;
use App\Support\CustomerInvoices;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\MonthlyExpenses;
use App\Support\Receivables;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * الملخّصُ الماليّ — كلُّ رقمٍ فيه من مصدره، أيًّا كان شكلُ الشاشة.
 *
 * الواجهةُ تُرتَّب وتعود ولا تمسّ الحساب: الصندوقُ من الدفتر، والبنكُ من
 * `Bank::total`، و«لك الآن» من `Receivables::totals`، و«عليك الآن» من مجاميع
 * «المبالغ المستحقة» نفسها، ومصروفاتُ الشهر من `MonthlyExpenses`، وأرقامُ المدّة
 * من `Demo::reportSummary`. وإجماليُّ الفواتير يصل باسمه `period.invoice_total`
 * — الرقمُ نفسه الذي كان يُسمّى `sales`.
 */
class TheFinanceSummaryReadsEveryNumberFromItsOwnSourceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', 'Asia/Muscat'));

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create(['business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@hadath.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط', 'basic_salary' => 0, 'allowances' => 0]);
        Ledger::ensureSystemAccounts((int) $this->business->id);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** شركةٌ في منتصف شهر: بنكان، ودخلٌ في الصندوق، وفاتورةُ عميل، ومصروفات، وسندُ مورّد، ومسيرةٌ معتمدة */
    private function aMonthOfWork(): void
    {
        $bid = (int) $this->business->id;

        foreach ([['مسقط', 3000], ['ظفار', 400]] as [$label, $opening]) {
            $this->post(route('admin.finance.banks.store'), ['label' => $label, 'bank_name' => $label, 'opening_balance' => $opening])
                ->assertSessionHasNoErrors();
        }

        $this->post(route('admin.finance.store'), ['kind' => 'other_income', 'amount' => 250, 'side' => 'cash', 'description' => 'تعويض'])
            ->assertSessionHasNoErrors();

        $customer = Customer::create(['business_id' => $bid, 'name' => 'وزارة الثقافة']);
        $invoice = CustomerInvoices::create($bid, $customer, ['issued_at' => '2026-10-01', 'due_at' => '2026-10-10'], [
            ['description' => 'تجهيز قاعة', 'quantity' => 1, 'unit_price' => 1000],
        ], $this->owner->id);
        CustomerInvoices::issue($invoice, $this->owner->id);

        $this->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'description' => 'إيجار المستودع', 'amount' => 120, 'method' => 'نقدي', 'spent_at' => '2026-10-05', 'status' => 'مدفوع',
        ])->assertSessionHasNoErrors();
        $this->post(route('admin.expenses.store'), [
            'type' => 'كهرباء', 'description' => 'فاتورة الكهرباء', 'amount' => 300, 'method' => 'نقدي', 'spent_at' => '2026-10-06',
            'status' => 'غير مدفوع', 'due_date' => '2026-10-15',
        ])->assertSessionHasNoErrors();

        $supplier = Supplier::create(['business_id' => $bid, 'name' => 'شركة النور']);
        $bill = SupplierInvoices::create($bid, [
            'supplier_id' => $supplier->id, 'supplier_ref' => 'INV-4001', 'issued_at' => '2026-10-08', 'due_at' => '2026-11-08',
            'subtotal' => 400, 'tax' => 0, 'cost_kind' => 'direct',
        ], $this->owner);
        SupplierInvoices::approve($bill->fresh(), $this->owner);

        User::create(['business_id' => $bid, 'name' => 'سالم', 'password' => bcrypt('password'),
            'role' => 'sales', 'status' => 'نشط', 'basic_salary' => 500, 'allowances' => 0]);
        $this->post(route('admin.payroll.store'), ['period' => '2026-10'])->assertSessionHasNoErrors();
        $run = PayrollRun::where('business_id', $bid)->latest('id')->firstOrFail();
        $this->post(route('admin.payroll.approve', $run->id))->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        return $this->get(route('admin.finance.summary'))->assertOk()->viewData('page')['props'];
    }

    public function test_every_card_reads_the_source_it_read_before(): void
    {
        $this->aMonthOfWork();
        $bid = (int) $this->business->id;
        $props = $this->summary();

        // الصندوقُ والبنكُ و«لك الآن»
        $this->assertEqualsWithDelta(round(Ledger::account($bid, 'cash')->balance(), 3), $props['cash'], 0.0005, 'الصندوقُ من الدفتر');
        $this->assertEqualsWithDelta(Bank::total($bid), $props['bank'], 0.0005, 'البنكُ من Bank::total');
        $this->assertEqualsWithDelta(3400.0, $props['bank'], 0.0005);
        $this->assertCount(2, $props['accounts']);
        $this->assertEquals(Receivables::totals($bid), $props['receivables'], '«لك الآن» من Receivables::totals');
        $this->assertEqualsWithDelta(1000.0, $props['receivables']['total'], 0.0005);
        $this->assertEqualsWithDelta(1000.0, $props['receivables']['overdue'], 0.0005);

        // عليك الآن — المجاميعُ نفسُها التي تقرؤها «المبالغ المستحقة»، وكلُّ مستندٍ مرّةً واحدة
        $dues = $this->get(route('admin.finance.dues'))->assertOk()->viewData('page')['props']['totals'];
        $this->assertEquals($dues, $props['dues']);
        $this->assertEqualsWithDelta(300.0, $props['dues']['expenses'], 0.0005, 'المصروفُ غيرُ المسدَّد');
        $this->assertEqualsWithDelta(400.0, $props['dues']['invoices'], 0.0005, 'سندُ المورّد');
        $this->assertEqualsWithDelta(500.0, $props['dues']['payroll'], 0.0005, 'المسيرةُ المعتمدة');
        $this->assertEqualsWithDelta(1200.0, $props['dues']['total'], 0.0005);
        $this->assertSame(1, $props['dues']['overdue']);

        // مصروفاتُ الشهر من الدفتر
        $this->assertEquals(MonthlyExpenses::for($bid), $props['monthly']);

        // ما جرى في المدة
        $report = Demo::reportSummary('month');
        $this->assertEqualsWithDelta(round((float) $report['sales'], 3), $props['period']['invoice_total'], 0.0005, 'إجماليُّ الفواتير هو reportSummary.sales');
        $this->assertEqualsWithDelta(1000.0, $props['period']['invoice_total'], 0.0005);
        $this->assertEqualsWithDelta(round((float) $report['profit'], 3), $props['period']['profit'], 0.0005);
        $this->assertEqualsWithDelta(round((float) $report['tax'], 3), $props['period']['tax'], 0.0005);
        $this->assertEqualsWithDelta(round((float) $report['expenses'], 3), $props['period']['expenses'], 0.0005);
        $this->assertEqualsWithDelta(120.0, $props['period']['expenses'], 0.0005, 'المسدَّدُ من سجلّ المصروفات وحده');
    }

    public function test_only_the_name_of_the_invoice_total_changed(): void
    {
        $props = $this->summary();

        $this->assertSame(['invoice_total', 'expenses', 'profit', 'tax', 'in', 'out', 'transfers'], array_keys($props['period']));
        $this->assertArrayNotHasKey('sales', $props['period']);
        foreach (['range', 'cash', 'bank', 'accounts', 'period', 'dues', 'receivables', 'monthly', 'monthly_links'] as $key) {
            $this->assertArrayHasKey($key, $props);
        }
    }

    public function test_every_finance_tab_still_opens_with_its_own_permission(): void
    {
        foreach (['admin.finance.summary', 'admin.finance.transactions', 'admin.finance.receivables', 'admin.finance.dues',
            'admin.finance.index', 'admin.finance.cheques', 'admin.expenses.index', 'admin.finance.journal',
            'admin.finance.chart', 'admin.finance.assets'] as $route) {
            $this->get(route($route))->assertOk();
        }

        // من يملك «المالية» دون «المصروفات» يفتح شاشاتها ولا يفتح سجلَّ المصروفات
        $clerk = User::create(['business_id' => $this->business->id, 'name' => 'محاسب', 'email' => 'clerk@hadath.om',
            'password' => bcrypt('password'), 'role' => 'accountant', 'status' => 'نشط', 'permissions' => ['dashboard', 'finance']]);
        $this->actingAs($clerk);
        foreach (['admin.finance.journal', 'admin.finance.chart', 'admin.finance.assets', 'admin.finance.receivables', 'admin.finance.dues'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('admin.expenses.index'))->assertForbidden();
        $this->assertSame(0, BankAccount::where('business_id', $this->business->id)->count(), 'فتحُ الشاشات لا يُنشئ حسابًا');
    }
}
