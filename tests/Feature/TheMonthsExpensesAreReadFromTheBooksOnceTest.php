<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\PayrollRun;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Intelligence\FinancialPerformance;
use App\Support\Intelligence\Period;
use App\Support\Ledger;
use App\Support\MonthlyExpenses;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * مصروفاتُ الشهر تُقرأ من الدفتر مرّةً واحدة — بطاقةٌ شهريّة في الملخّص الماليّ.
 *
 * الإجماليُّ صافي حسابات «المصروف» في قيود الشهر فيطابق اليوميّة: سندُ المورّد
 * يُعدّ مرّةً (لا أمرُ شرائه ولا سدادُه)، والراتبُ مرّةً يوم الاعتماد (لا يومَ
 * الصرف)، والمخزونُ أصلٌ لا مصروف. والمدفوعُ والمستحقُّ والمتأخّر من مستندات
 * الشهر. والشهرُ يُختار بنفسه، والشركةُ لا ترى مصروفَ غيرها.
 */
class TheMonthsExpensesAreReadFromTheBooksOnceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', 'Asia/Muscat'));

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true]);
        $this->owner = User::create(['business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@hadath.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط', 'basic_salary' => 0, 'allowances' => 0]);
        Ledger::ensureSystemAccounts((int) $this->business->id);
        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'شركة النور']);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function expense(float $amount, string $date, string $status = 'مدفوع', array $over = []): void
    {
        $this->post(route('admin.expenses.store'), $over + [
            'type' => 'إيجار', 'description' => 'إيجار المستودع', 'amount' => $amount, 'method' => 'نقدي', 'spent_at' => $date, 'status' => $status,
        ])->assertSessionHasNoErrors();
    }

    private function invoice(float $subtotal, string $issued, ?PurchaseOrder $po = null, ?string $due = null, string $costKind = 'direct'): SupplierInvoice
    {
        $invoice = SupplierInvoices::create((int) $this->business->id, [
            'supplier_id' => $this->supplier->id, 'purchase_order_id' => $po?->id, 'supplier_ref' => 'INV-'.random_int(1000, 9999),
            'issued_at' => $issued, 'due_at' => $due, 'subtotal' => $subtotal, 'tax' => 0, 'cost_kind' => $costKind,
        ], $this->owner);
        SupplierInvoices::approve($invoice->fresh(), $this->owner);

        return $invoice->fresh();
    }

    private function payroll(string $period, float $salary): PayrollRun
    {
        User::create(['business_id' => $this->business->id, 'name' => 'سالم '.$period, 'password' => bcrypt('password'),
            'role' => 'sales', 'status' => 'نشط', 'basic_salary' => $salary, 'allowances' => 0]);
        $this->post(route('admin.payroll.store'), ['period' => $period])->assertSessionHasNoErrors();
        $run = PayrollRun::where('business_id', $this->business->id)->latest('id')->firstOrFail();
        $this->post(route('admin.payroll.approve', $run->id))->assertSessionHasNoErrors();

        return $run->fresh('lines');
    }

    /** صافي سطور القيود على حسابات «المصروف» في مدّةٍ — بحسابٍ مستقلّ عن الصنف */
    private function journalNet(string $from, string $to): float
    {
        $row = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.business_id', $this->business->id)->where('accounts.type', 'مصروف')
            ->where('journal_entries.entry_date', '>=', $from)->where('journal_entries.entry_date', '<', $to)
            ->selectRaw('COALESCE(SUM(journal_lines.debit),0) d, COALESCE(SUM(journal_lines.credit),0) c')->first();

        return round((float) $row->d - (float) $row->c, 3);
    }

    public function test_the_months_total_is_the_ledger_net_of_expense_accounts_and_reconciles(): void
    {
        $this->expense(300, '2026-10-05');
        $this->invoice(400, '2026-10-08');
        $this->payroll('2026-10', 500);

        $card = MonthlyExpenses::for((int) $this->business->id, '2026-10');

        $this->assertEqualsWithDelta(1200.0, $card['total'], 0.0005);
        $this->assertEqualsWithDelta($this->journalNet('2026-10-01', '2026-11-01'), $card['total'], 0.0005, 'يطابق سطورَ اليوميّة');

        /* ويطابق صافيَ كلّ حسابٍ بالصيغة الإداريّة نفسِها (`FinancialPerformance::accountNet`) */
        $period = MonthlyExpenses::month((int) $this->business->id, '2026-10');
        foreach (collect($card['groups'])->flatMap(fn ($g) => $g['accounts']) as $row) {
            $account = Account::find($row['account_id']);
            $this->assertEqualsWithDelta(FinancialPerformance::accountNet($account, $period), $row['total'], 0.0005, $row['name']);
        }

        $groups = collect($card['groups'])->pluck('total', 'key')->all();
        $this->assertEqualsWithDelta(300.0, $groups['operating'], 0.0005);
        $this->assertEqualsWithDelta(400.0, $groups['suppliers'], 0.0005);
        $this->assertEqualsWithDelta(500.0, $groups['payroll'], 0.0005);
        $this->assertSame('أكتوبر 2026', $card['label']);
    }

    public function test_a_supplier_invoice_counts_once_not_its_purchase_order_nor_its_payment(): void
    {
        $po = PurchaseOrder::create(['business_id' => $this->business->id, 'number' => 'PO-000001', 'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name, 'status' => 'مُرسل', 'items_subtotal' => 400, 'tax' => 0, 'total' => 400, 'ordered_at' => '2026-10-02']);
        $this->assertEqualsWithDelta(0.0, MonthlyExpenses::for((int) $this->business->id, '2026-10')['total'], 0.0005, 'أمرُ الشراء التزامٌ لا مصروف');

        $invoice = $this->invoice(400, '2026-10-08', $po, '2026-10-15');
        $before = MonthlyExpenses::for((int) $this->business->id, '2026-10');
        $this->assertEqualsWithDelta(400.0, $before['total'], 0.0005);
        $this->assertEqualsWithDelta(400.0, $before['obligations']['due'], 0.0005);
        $this->assertEqualsWithDelta(400.0, $before['obligations']['overdue'], 0.0005, 'فات موعدُه (15) وما زال مستحقًّا');

        SupplierInvoices::settle($invoice->fresh(), 150, 'cash', Carbon::parse('2026-10-18'), $this->owner->id);
        $after = MonthlyExpenses::for((int) $this->business->id, '2026-10');
        $this->assertEqualsWithDelta(400.0, $after['total'], 0.0005, 'السدادُ لا يُضيف مصروفًا ثانيًا');
        $this->assertEqualsWithDelta(150.0, $after['obligations']['paid'], 0.0005);
        $this->assertEqualsWithDelta(250.0, $after['obligations']['due'], 0.0005);
        $this->assertSame(1, $after['obligations']['documents']['supplier_invoices']);

        /* وسندٌ إلى المخزون أصلٌ لا مصروف — يُعرض معلومةً */
        $this->invoice(600, '2026-10-10', null, null, 'inventory');
        $stock = MonthlyExpenses::for((int) $this->business->id, '2026-10');
        $this->assertEqualsWithDelta(400.0, $stock['total'], 0.0005);
        $this->assertEqualsWithDelta(600.0, $stock['inventory_purchases'], 0.0005);
    }

    public function test_payroll_counts_once_on_approval_and_paying_it_adds_nothing(): void
    {
        $run = $this->payroll('2026-10', 700);
        $this->assertEqualsWithDelta(700.0, MonthlyExpenses::for((int) $this->business->id, '2026-10')['total'], 0.0005);
        $this->assertEqualsWithDelta(700.0, MonthlyExpenses::for((int) $this->business->id, '2026-10')['obligations']['due'], 0.0005);

        $this->post(route('admin.payroll.pay', $run->id), ['lines' => $run->lines->pluck('id')->all(), 'paid_at' => '2026-10-31', 'from' => 'cash'])
            ->assertSessionHasNoErrors();

        $card = MonthlyExpenses::for((int) $this->business->id, '2026-10');
        $this->assertEqualsWithDelta(700.0, $card['total'], 0.0005, 'الصرفُ يمسّ الذمّة والنقد لا المصروف');
        $this->assertEqualsWithDelta(700.0, $card['obligations']['paid'], 0.0005);
        $this->assertEqualsWithDelta(0.0, $card['obligations']['due'], 0.0005);
    }

    public function test_a_chosen_month_reads_its_own_entries_and_compares_with_the_one_before(): void
    {
        $this->expense(200, '2026-09-10');
        $this->expense(300, '2026-10-05');
        $this->expense(90, '2026-10-12', 'غير مدفوع', ['due_date' => '2026-10-14']);

        $october = MonthlyExpenses::for((int) $this->business->id, '2026-10');
        $september = MonthlyExpenses::for((int) $this->business->id, '2026-09');

        $this->assertEqualsWithDelta(300.0, $october['total'], 0.0005, 'غيرُ المسدَّد لا قيدَ له بعد');
        $this->assertEqualsWithDelta(200.0, $september['total'], 0.0005);
        $this->assertEqualsWithDelta(200.0, $october['previous']['total'], 0.0005);
        $this->assertEqualsWithDelta(50.0, $october['change']['percent'], 0.05);
        $this->assertEqualsWithDelta(90.0, $october['obligations']['due'], 0.0005);
        $this->assertEqualsWithDelta(90.0, $october['obligations']['overdue'], 0.0005);
        $this->assertNull($september['change']['percent'], 'لا شهرَ قبله للمقارنة — لا «+100٪»');

        /* وشهرٌ لم يبدأ لا يُختار، ومدخلٌ غريب يعود إلى الشهر الجاري */
        $this->assertSame('2026-10', MonthlyExpenses::for((int) $this->business->id, '2027-03')['month']);
        $this->assertSame('2026-10', MonthlyExpenses::for((int) $this->business->id, 'not-a-month')['month']);

        /* وبابُ الملخّص يقرأ الشهرَ من الرابط — مستقلًّا عن تبويب المدّة */
        $this->get(route('admin.finance.summary', ['range' => 'year', 'expense_month' => '2026-09']))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('range', 'year')->where('monthly.month', '2026-09')
                ->where('monthly.total', fn ($v) => abs((float) $v - 200) < 0.0005)->has('monthly.months', MonthlyExpenses::MONTHS_BACK));
    }

    public function test_a_cancelled_invoice_nets_to_zero_in_its_month(): void
    {
        $invoice = $this->invoice(400, '2026-10-08');
        SupplierInvoices::cancel($invoice->fresh(), 'سند مكرّر', $this->owner);

        $card = MonthlyExpenses::for((int) $this->business->id, '2026-10');
        $this->assertEqualsWithDelta(0.0, $card['total'], 0.0005);
        $this->assertEqualsWithDelta($this->journalNet('2026-10-01', '2026-11-01'), $card['total'], 0.0005);
        $this->assertSame(0, $card['obligations']['documents']['supplier_invoices'], 'الملغى ليس دَينًا');
    }

    public function test_another_company_reads_only_its_own_month_and_the_card_follows_the_finance_section(): void
    {
        $this->expense(300, '2026-10-05');

        $other = Business::create(['name' => 'شركة أخرى', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $other->id, 'name' => 'الرئيسي']);
        $stranger = User::create(['business_id' => $other->id, 'name' => 'غريب', 'email' => 'x@other.om', 'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط']);
        Ledger::ensureSystemAccounts((int) $other->id);

        $this->assertEqualsWithDelta(0.0, MonthlyExpenses::for((int) $other->id, '2026-10')['total'], 0.0005);
        $this->actingAs($stranger)->get(route('admin.finance.summary', ['expense_month' => '2026-10']))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('monthly.total', fn ($v) => abs((float) $v) < 0.0005)->where('monthly.entries', 0));

        $seller = User::create(['business_id' => $this->business->id, 'name' => 'بائع', 'email' => 's@hadath.om', 'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط']);
        $this->actingAs($seller)->get(route('admin.finance.summary'))->assertForbidden();
        $this->assertSame(1, Expense::where('business_id', $this->business->id)->count());
        $this->assertInstanceOf(Period::class, MonthlyExpenses::month((int) $this->business->id, '2026-10'));
    }
}
