<?php

namespace Tests\Feature\Management;

use App\Models\ActivityLog;
use App\Models\Budget;
use App\Support\Intelligence\Budgeting;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;

/**
 * الموازنةُ خطّةٌ بنسخٍ لا تُمحى.
 *
 * مسودّةٌ تُحرَّر · تُعتمد فتُجمَّد · لا معتمَدةٌ إلّا واحدةٌ للسنة · التنقيحُ نسخةٌ
 * جديدةٌ بسببٍ مكتوب ومَن ومتى · الفعليُّ من سطور اليوميّة على الحساب والشهر ·
 * والانحرافُ يُقال بالوقائع: «إنفاقٌ فوق الخطّة» لا «سيّئ» · والغريبُ لا يرى شيئًا.
 */
class ABudgetIsAPlanWithVersionsTest extends ManagementTestCase
{
    use RefreshDatabase;

    private function account(string $key): int
    {
        return (int) Ledger::account((int) $this->business->id, $key)->id;
    }

    private function draft(int $year = 2026, array $lines = []): Budget
    {
        return Budgeting::create((int) $this->business->id, ['fiscal_year' => $year, 'lines' => $lines], $this->manager);
    }

    public function test_a_draft_is_versioned_named_and_editable_until_approved(): void
    {
        $sales = $this->account('sales');
        $b = $this->draft(2026, [['account_id' => $sales, 'month' => 1, 'planned_amount' => 1000]]);

        $this->assertSame(1, $b->version);
        $this->assertSame('موازنة 2026', $b->name);
        $this->assertSame(Budget::DRAFT, $b->status);
        $this->assertCount(1, $b->lines);

        Budgeting::update($b, ['name' => 'خطّة النموّ', 'lines' => [['account_id' => $sales, 'month' => 1, 'planned_amount' => 1500.125], ['account_id' => $sales, 'month' => 2, 'planned_amount' => 200]]], $this->manager);
        $b = $b->fresh('lines');
        $this->assertSame('خطّة النموّ', $b->name);
        $this->assertCount(2, $b->lines);
        $this->assertSame('1500.125', (string) $b->lines->firstWhere('month', 1)->planned_amount);

        $this->assertTrue(ActivityLog::where('subject_type', 'budget')->where('subject_id', $b->id)->exists(), 'كلُّ خطوةٍ على السجلّ');
    }

    public function test_lines_are_validated_against_the_company_chart_and_the_calendar(): void
    {
        $sales = $this->account('sales');
        $foreign = Ledger::account((int) $this->other->id, 'sales')->id;

        try {
            $this->draft(2026, [['account_id' => $foreign, 'month' => 1, 'planned_amount' => 10]]);
            $this->fail('حسابُ شركةٍ أخرى مرّ');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('الحساب', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->draft(2027, [['account_id' => $sales, 'month' => 13, 'planned_amount' => 10]]);
    }

    public function test_only_the_approver_approves_and_an_empty_budget_never_is(): void
    {
        $empty = $this->draft(2026);
        try {
            Budgeting::approve($empty, $this->owner);
            $this->fail('موازنةٌ فارغة اعتُمدت');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('بلا بنود', $e->getMessage());
        }

        $b = $this->draft(2027, [['account_id' => $this->account('sales'), 'month' => 1, 'planned_amount' => 10]]);
        try {
            Budgeting::approve($b, $this->manager);
            $this->fail('المديرُ اعتمد بلا صلاحيّة');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('صلاحيّة', $e->getMessage());
        }

        $approved = Budgeting::approve($b, $this->owner);
        $this->assertSame(Budget::APPROVED, $approved->status);
        $this->assertSame($this->owner->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);

        $this->expectException(RuntimeException::class);
        Budgeting::update($approved, ['name' => 'تغييرٌ بعد الاعتماد'], $this->owner);
    }

    public function test_one_approved_budget_per_fiscal_year_and_revision_keeps_history(): void
    {
        $sales = $this->account('sales');
        $rent = $this->account('rent');
        $v1 = Budgeting::approve($this->draft(2026, [['account_id' => $sales, 'month' => 1, 'planned_amount' => 1000], ['account_id' => $rent, 'month' => 1, 'planned_amount' => 300]]), $this->owner);

        try {
            Budgeting::revise($v1, '', $this->manager);
            $this->fail('تنقيحٌ بلا سبب');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('سبب', $e->getMessage());
        }

        $v2 = Budgeting::revise($v1, 'عقدٌ جديد رفع الإيراد', $this->manager);
        $this->assertSame(2, $v2->version);
        $this->assertSame(Budget::DRAFT, $v2->status);
        $this->assertSame($v1->id, $v2->revised_from_id);
        $this->assertSame('عقدٌ جديد رفع الإيراد', $v2->revision_reason);
        $this->assertSame($this->manager->id, $v2->created_by);
        $this->assertCount(2, $v2->lines, 'البنودُ تُنسخ');
        $this->assertSame(Budget::APPROVED, $v1->fresh()->status, 'المعتمَدةُ تبقى حتى تُعتمد الجديدة');

        try {
            Budgeting::revise($v1->fresh(), 'مرّةً ثانية', $this->manager);
            $this->fail('مسودّتان للسنة نفسها');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('مسودّة', $e->getMessage());
        }

        Budgeting::update($v2, ['lines' => [['account_id' => $sales, 'month' => 1, 'planned_amount' => 5000]]], $this->manager);
        Budgeting::approve($v2->fresh(), $this->owner);

        $this->assertSame(Budget::SUPERSEDED, $v1->fresh()->status, 'الأولى حلّت محلَّها نسخة — ولم تُمحَ');
        $this->assertSame(Budget::APPROVED, $v2->fresh()->status);
        $this->assertSame($v2->id, Budgeting::approvedFor((int) $this->business->id, 2026)?->id);
        $this->assertCount(2, $v1->fresh()->lines, 'بنودُ التاريخ لا تُلمس');

        try {
            Budgeting::archive($v2->fresh(), $this->owner);
            $this->fail('المعتمَدة أُرشفت');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('لا تُؤرشف', $e->getMessage());
        }
        $this->assertSame(Budget::ARCHIVED, Budgeting::archive($v1->fresh(), $this->owner)->status);
    }

    public function test_budget_versus_actual_reads_journal_lines_by_account_and_month_in_plain_words(): void
    {
        $bid = (int) $this->business->id;
        $sales = $this->account('sales');
        $rent = $this->account('rent');
        $budget = Budgeting::approve($this->draft(2026, [
            ['account_id' => $sales, 'month' => 9, 'planned_amount' => 1000],
            ['account_id' => $rent, 'month' => 9, 'planned_amount' => 300],
            ['account_id' => $rent, 'month' => 10, 'planned_amount' => 300],
        ]), $this->owner);

        /* الفعليّ من الدفتر: فاتورةٌ صافيها 1200 على المبيعات، وإيجارُ 350 يُقيَّد بيد */
        $this->invoice(1200, '2026-09-10');
        Ledger::post($bid, 'إيجار سبتمبر', [['account' => 'rent', 'debit' => 350], ['account' => 'cash', 'credit' => 350]], now(), 'يدوي');

        $va = Budgeting::versusActual($budget, 'month');
        $salesRow = collect($va['rows'])->firstWhere('account_id', $sales);
        $rentRow = collect($va['rows'])->firstWhere('account_id', $rent);

        $sept = $salesRow['buckets'][8];
        $this->assertSame('2026-09', $sept['label']);
        $this->assertEqualsWithDelta(1000.0, $sept['planned'], 0.0005);
        $this->assertEqualsWithDelta(1200.0, $sept['actual'], 0.0005);
        $this->assertEqualsWithDelta(200.0, $sept['variance'], 0.0005);
        $this->assertSame('earned_above_plan', $sept['reading']);

        $this->assertSame('spent_above_plan', $rentRow['buckets'][8]['reading']);
        $this->assertEqualsWithDelta(50.0, $rentRow['buckets'][8]['variance'], 0.0005);
        $this->assertSame('spent_below_plan', $rentRow['buckets'][9]['reading'], 'أكتوبر لم يُنفَق فيه — دون الخطّة');
        $this->assertFalse($rentRow['buckets'][9]['elapsed'], 'أكتوبر لم ينقضِ');
        $this->assertSame('no_plan', $salesRow['buckets'][0]['reading']);

        $this->assertEqualsWithDelta(1000.0, $va['totals']['revenue']['planned'], 0.0005);
        $this->assertEqualsWithDelta(600.0, $va['totals']['expense']['planned'], 0.0005);
        $this->assertEqualsWithDelta(350.0, $va['totals']['expense']['actual'], 0.0005);

        $quarter = Budgeting::versusActual($budget, 'quarter');
        $this->assertCount(4, $quarter['rows'][0]['buckets']);
        $this->assertEqualsWithDelta(1000.0, collect($quarter['rows'])->firstWhere('account_id', $sales)['buckets'][2]['planned'], 0.0005);
        $year = Budgeting::versusActual($budget, 'year');
        $this->assertCount(1, $year['rows'][0]['buckets']);
        $this->assertEqualsWithDelta(600.0, collect($year['rows'])->firstWhere('account_id', $rent)['buckets'][0]['planned'], 0.0005);
    }

    public function test_the_screen_and_its_doors_respect_roles_and_tenants(): void
    {
        $sales = $this->account('sales');
        $budget = $this->draft(2026, [['account_id' => $sales, 'month' => 1, 'planned_amount' => 10]]);

        $this->actingAs($this->accountant)->get(route('admin.management.budgets'))->assertOk();
        $this->actingAs($this->seller)->get(route('admin.management.budgets'))->assertForbidden();
        $this->actingAs($this->accountant)->post(route('admin.management.budgets.store'), ['fiscal_year' => 2028])->assertForbidden();
        $this->actingAs($this->manager)->post(route('admin.management.budgets.approve', $budget->id))->assertForbidden();

        $this->actingAs($this->stranger)->get(route('admin.management.budgets', ['budget' => $budget->id]))->assertOk();
        $this->assertSame(0, Budget::where('business_id', $this->other->id)->count());
        $this->actingAs($this->stranger)->put(route('admin.management.budgets.update', $budget->id), ['name' => 'اختراق'])->assertNotFound();
        $this->actingAs($this->stranger)->post(route('admin.management.budgets.approve', $budget->id))->assertNotFound();
        $this->assertSame(Budget::DRAFT, $budget->fresh()->status);

        $this->actingAs($this->owner)->post(route('admin.management.budgets.approve', $budget->id))->assertRedirect();
        $this->assertSame(Budget::APPROVED, $budget->fresh()->status);
        $this->actingAs($this->manager)->post(route('admin.management.budgets.revise', $budget->id), ['reason' => 'تعديل'])->assertRedirect();
        $this->assertSame(2, Budget::where('business_id', $this->business->id)->count());
    }
}
