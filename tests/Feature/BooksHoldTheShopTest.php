<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Books;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * دفتر الأستاذ يحمل عملَ المحلّ — لا نصفَه.
 *
 * كان في النظام دفتران لا يلتقيان: `transactions` تكتب فيه نقطةُ البيع
 * وتقرأ منه التقارير، و`journal_entries` تكتب فيه سنداتُ الموردين والرواتب
 * والأصول. فالمبيعات لم تصل إلى الثاني أبدًا: شجرةٌ فيها «إيراد المبيعات»
 * صفر وقد باع صاحبُها، ومخزونٌ يزيد بالشراء ولا ينقص بالبيع.
 *
 * وميزانُ المراجعة كان يقول «متوازن» — وهو متوازنٌ فعلًا لأنّ كلّ قيدٍ كُتب
 * متوازن — فيطمئنّ التاجر إلى دفترٍ ليس فيه عملُه.
 */
class BooksHoldTheShopTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل الورد', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة', 'price' => 10, 'cost' => 4,
            'quantity' => 100, 'alert_qty' => 5, 'active' => true,
        ]);

        Ledger::seedChart($this->business->id);

        $this->actingAs($this->owner);
        session(['current_branch' => $this->branch->id]);
    }
    /** رصيدُ حسابٍ بمفتاحه النظاميّ من ميزان المراجعة */
    private function balance(string $key): float
    {
        $account = Ledger::account($this->business->id, $key);
        $row = collect(Ledger::trialBalance($this->business->id)['accounts'])
            ->firstWhere('id', $account->id);

        return (float) ($row['balance'] ?? 0);
    }
    /* ------------------- المصروف كذلك ------------------- */

    private function spend(array $over = []): Expense
    {
        $this->post(route('admin.expenses.store'), array_merge([
            'type' => 'إيجار', 'amount' => 300, 'method' => 'نقدي',
        ], $over))->assertSessionHasNoErrors();

        return Expense::latest('id')->firstOrFail();
    }

    public function test_a_paid_expense_reaches_the_ledger(): void
    {
        $this->spend();

        $this->assertSame(300.0, round($this->balance('rent'), 3));
        $this->assertSame(-300.0, round($this->balance('cash'), 3));
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    public function test_an_unfamiliar_expense_type_lands_in_other_expenses(): void
    {
        $this->spend(['type' => 'صيانة المكيّف']);

        $this->assertSame(300.0, round($this->balance('other_expenses'), 3));
    }

    /**
     * والرواتب ليست منها عمدًا: مسيرةُ الرواتب تُرحّل بنفسها، فربطُ نوعٍ
     * مكتوبٍ باليد بالحساب نفسه يجعل راتبًا واحدًا يُقيَّد مرّتين.
     */
    public function test_an_expense_typed_salaries_does_not_touch_the_payroll_account(): void
    {
        $this->spend(['type' => 'رواتب']);

        $this->assertSame(0.0, round($this->balance('salaries'), 3));
        $this->assertSame(300.0, round($this->balance('other_expenses'), 3));
    }

    public function test_an_unpaid_bill_is_not_money_out_yet(): void
    {
        $this->spend(['status' => 'غير مدفوع', 'due_date' => now()->addWeek()->toDateString()]);

        $this->assertSame(0.0, round($this->balance('rent'), 3));
        $this->assertSame(0.0, round($this->balance('cash'), 3));
    }

    public function test_deleting_an_expense_reverses_its_entry(): void
    {
        $expense = $this->spend();

        $this->delete(route('admin.expenses.destroy', $expense->id));

        $this->assertSame(0.0, round($this->balance('rent'), 3));
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);

        // والقاعدة واحدة في الدفتر: المصروف يُعكس كما تُعكس البيعة
        $entries = JournalEntry::where('sourceable_type', Expense::class)
            ->where('sourceable_id', $expense->id)->get();

        $this->assertCount(2, $entries, 'حُذف قيد المصروف بدل أن يُعكس');
        $this->assertNotNull($entries->firstWhere('reverses_id', '!=', null));
    }

    public function test_restoring_it_brings_the_entry_back_once(): void
    {
        $expense = $this->spend();
        $this->delete(route('admin.expenses.destroy', $expense->id));
        $this->post(route('admin.expenses.restore', $expense->id));

        $this->assertSame(300.0, round($this->balance('rent'), 3));
        $this->assertSame(1, JournalEntry::where('sourceable_type', Expense::class)
            ->whereNull('reversed_at')->whereNull('reverses_id')
            ->where('sourceable_id', $expense->id)->count());
    }

    /* ------------------- الصندوق لا يتوقّف ------------------- */
}
