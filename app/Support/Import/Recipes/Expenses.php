<?php

namespace App\Support\Import\Recipes;

use App\Models\Expense;
use App\Support\Books;
use App\Support\Import\Index;
use App\Support\Import\Provenance;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * المصروفاتُ التاريخيّة — والمسدَّدُ يكتب قيدَه بتاريخه من `Books::postExpense`.
 *
 * غيرُ المسدَّد يبقى التزامًا (ويُقرأ ملتزَمًا على مشروعه إن رُبط). ولا يُستورد
 * مصروفٌ مسدَّد قبل تاريخ التحويل: قيدُه في الدفتر القديم؛ وغيرُ المسدَّد قبله
 * يُستورد التزامًا قائمًا — دَينٌ لم يُسدَّد بعدُ حقيقةٌ لم تنتهِ.
 */
final class Expenses extends Recipe
{
    public function type(): string
    {
        return 'expense';
    }

    public function onExact(): string
    {
        return 'skip';
    }

    public function check(array $unit): array
    {
        $r = $unit[0];
        $errors = [];
        $warnings = [];
        $paid = ($r['status'] ?? 'paid') === 'paid';

        if (self::money($r['amount'] ?? 0) <= 0) {
            $errors[] = __('مبلغُ المصروف يجب أن يكون أكبر من صفر');
        }
        if ($paid && $this->ctx->beforeCutover($r['spent_at'] ?? null)) {
            $errors[] = __('مصروفٌ مسدَّد بتاريخ :d قبل تاريخ التحويل — قيدُه في الدفتر القديم', ['d' => $r['spent_at']]);
        }
        if (! empty($r['project'])) {
            try {
                $this->ref('project', $r['project'], __('المشروع'));
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if (empty($r['description'])) {
            $warnings[] = __('بلا وصف');
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    public function match(array $unit): array
    {
        $r = $unit[0];

        return $this->matchBy('expense', [
            'external' => Index::code($r['external_id'] ?? null),
        ], ['type_date_amount' => Index::name($r['type'] ?? null).'|'.$r['spent_at'].'|'.Index::money($r['amount'] ?? 0)]);
    }

    public function impact(array $unit): void
    {
        $r = $unit[0];
        $this->ctx->addFinancial('expenses', self::money($r['amount'] ?? 0));
        if (($r['status'] ?? 'paid') !== 'paid') {
            $this->ctx->addFinancial('payables', self::money($r['amount'] ?? 0));
        }
    }

    public function create(array $unit, int $row): Model
    {
        $r = $unit[0];
        $project = empty($r['project']) ? null : $this->ref('project', $r['project'], __('المشروع'));
        $paid = ($r['status'] ?? 'paid') === 'paid';

        $expense = Expense::create([
            'business_id' => $this->ctx->businessId(),
            'type' => $r['type'],
            'description' => $r['description'] ?? null,
            'amount' => self::money($r['amount']),
            'method' => $r['method'] ?? 'نقدي',
            'status' => $paid ? Expense::PAID : Expense::UNPAID,
            'spent_at' => $r['spent_at'],
            'due_date' => $r['due_date'] ?? null,
            'reference' => 'HIST-'.($r['external_id'] ?? $this->ctx->dataset->code.'-'.$row),
            'employee_name' => $this->ctx->actor?->name ?? __('استيراد تاريخيّ'),
            'project_id' => $project?->id,
            'origin' => Provenance::HISTORICAL,
        ]);

        if ($paid) {
            Books::postExpense($expense);
        }

        return $expense->fresh();
    }

    public function keys(Model $model): array
    {
        /** @var Expense $model */
        return ['reference' => Index::code($model->reference),
            'type_date_amount' => Index::name($model->type).'|'.optional($model->spent_at)->format('Y-m-d').'|'.Index::money($model->amount)];
    }
}
