<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\Order;
use App\Models\User;
use App\Support\Books;
use App\Support\Ledger;
use App\Support\Search;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ثلاثةُ عيوبٍ بقيت معلّقةً من جولاتٍ سابقة — كُنست معًا.
 *
 * لوحةُ التجهيز كانت تقطع عند سقفها بلا كلمة، والبحثُ في ستّ شاشاتٍ أعمى عن
 * حالة الحرف في الإنتاج، والمصروفاتُ كلّها تُرحَّل إلى «مصروفات أخرى» إلّا
 * الإيجار. وثلاثتها صامتة: لا رسالةَ خطأ ولا شاشةً حمراء — تعمل كلّ يومٍ
 * وتعطي جوابًا ناقصًا.
 */
class SweptDefectsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /* ======================= لوحة التجهيز لا تبتلع ======================= */

    private function awaiting(int $n, int $daysAhead = 1): void
    {
        for ($i = 0; $i < $n; $i++) {
            Order::create([
                'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
                'branch' => $this->branch->name, 'number' => 'P-'.$i.'-'.uniqid(),
                'status' => 'قيد التجهيز', 'is_held' => false,
                'customer_name' => 'زبون', 'employee_name' => 'موظف',
                'payment_method' => 'نقدي', 'subtotal' => 10, 'total' => 10,
                'ordered_at' => now(), 'scheduled_for' => now()->addDays($daysAhead)->addMinutes($i),
            ]);
        }
    }
    /* ==================== البحث يعرف محرّكه ==================== */

    public function test_the_operator_follows_the_engine_not_the_test_bench(): void
    {
        // SQLite لا تفرّق بين حرفٍ كبيرٍ وصغير، وPostgreSQL تفرّق — والإنتاج عليها
        $this->assertSame('ilike', Search::operatorFor('pgsql'));
        $this->assertSame('like', Search::operatorFor('sqlite'));
    }
    public function test_an_unknown_type_still_falls_somewhere_rather_than_nowhere(): void
    {
        // والافتراض حسابٌ قائم لا فراغ: قيدٌ بلا حساب يُسقط الترحيل كلّه
        $this->assertSame('other_expenses', Books::expenseAccount('شيءٌ كتبه التاجر'));
        $this->assertSame('other_expenses', Books::expenseAccount(null));
    }

    public function test_salaries_are_left_out_on_purpose(): void
    {
        // مسيرةُ الرواتب تُرحّل بنفسها، وربطُ نوعٍ مكتوبٍ باليد بها يُقيّد الراتب مرّتين
        $this->assertSame('other_expenses', Books::expenseAccount('رواتب'));
    }

    public function test_the_merchants_own_choice_beats_the_name(): void
    {
        /*
         * النوع يكتبه التاجر بيده، فقائمةٌ في الكود لا تكفي مهما طالت: من
         * كتب «كهرباء» بدل «كهرباء وماء» يسقط منها. فيُحفظ الحساب على النوع.
         */
        ExpenseType::create([
            'business_id' => $this->business->id, 'name' => 'كهرباء', 'account_key' => 'utilities',
        ]);

        $this->assertSame('utilities', Books::expenseAccount('كهرباء', $this->business->id));
        // وبلا معرّفٍ يبقى الاسمُ وحده الدليل — فلا يسقط الترحيل لأجل معرّفٍ غائب
        $this->assertSame('other_expenses', Books::expenseAccount('كهرباء'));
    }

    public function test_an_account_outside_the_closed_list_is_refused(): void
    {
        // ربطُ مصروفٍ بحساب إيرادٍ يقلب القيد: يتوازن الدفتر ويكذب
        $this->actingAs($this->owner)->post(route('admin.expenseTypes.store'), [
            'name' => 'اشتراكات', 'account_key' => 'sales',
        ])->assertSessionHasErrors('account_key');

        $this->assertDatabaseMissing('expense_types', ['name' => 'اشتراكات']);
    }
    public function test_renaming_a_type_takes_its_history_with_it(): void
    {
        /*
         * المصروفات تحمل اسم النوع نصًّا لا معرّفًا، فتغييرُ الاسم كان يترك
         * التاجر بنوعٍ فارغٍ وتاريخٍ معلّقٍ باسمٍ لا وجود له في القائمة.
         */
        $type = ExpenseType::create(['business_id' => $this->business->id, 'name' => 'اشتراكات']);
        Expense::create([
            'business_id' => $this->business->id, 'type' => 'اشتراكات',
            'amount' => 20, 'spent_at' => now(), 'description' => 'برمجيات',
        ]);

        $this->actingAs($this->owner)->put(route('admin.expenseTypes.update', $type->id), [
            'name' => 'اشتراكات وبرمجيات',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('expenses', [
            'business_id' => $this->business->id, 'type' => 'اشتراكات وبرمجيات',
        ]);
        $this->assertDatabaseMissing('expenses', [
            'business_id' => $this->business->id, 'type' => 'اشتراكات',
        ]);
    }

    public function test_a_neighbours_expense_type_is_out_of_reach(): void
    {
        $neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = ExpenseType::create(['business_id' => $neighbour->id, 'name' => 'نوعهم']);

        $this->actingAs($this->owner)
            ->put(route('admin.expenseTypes.update', $theirs->id), ['name' => 'صار لي'])
            ->assertNotFound();

        $this->assertSame('نوعهم', $theirs->fresh()->name);
    }

    public function test_the_chart_grows_the_accounts_the_map_points_at(): void
    {
        /*
         * وخريطةٌ تشير إلى حسابٍ ليس في الشجرة تُسقط الترحيل برسالة «حسابٌ
         * غير موجود» لا يفهمها أحد. والمتاجر القديمة تستدرك الناقص وحده —
         * انظر `Ledger::ensureSystemAccounts`.
         */
        Ledger::seedChart($this->business->id);
        Ledger::ensureSystemAccounts($this->business->id);

        foreach (Books::EXPENSE_ACCOUNTS as $key) {
            $this->assertNotNull(
                Ledger::account($this->business->id, $key),
                "الحساب «{$key}» تشير إليه الخريطة وليس في الشجرة",
            );
        }
    }

    public function test_a_paid_expense_lands_in_the_account_its_type_names(): void
    {
        // والفحص على القيد لا على الدالّة: بينهما استدعاءٌ قد يُنسى تمريرُ متجره
        Ledger::seedChart($this->business->id);

        $expense = Expense::create([
            'business_id' => $this->business->id, 'type' => 'كهرباء وماء',
            'amount' => 30, 'spent_at' => now(), 'method' => 'نقدي', 'description' => 'فاتورة',
        ]);

        Books::recordExpense($expense);

        $account = Ledger::account($this->business->id, 'utilities');
        $this->assertNotNull($account);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $account->id, 'debit' => 30]);
    }
}
