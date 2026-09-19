<?php

namespace App\Support\Automation\Triggers;

use App\Models\AutomationRule;
use App\Models\Expense;
use App\Support\Automation\Audience;
use App\Support\Automation\Engine;
use App\Support\Automation\Trigger;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/** مصروفٌ غيرُ مسدَّد بلغ استحقاقَه — داخليٌّ للمالية */
final class ExpenseUnpaid extends Trigger
{
    public function key(): string
    {
        return 'expense_unpaid';
    }

    public function label(): string
    {
        return __('مصروف غير مسدَّد');
    }

    public function subjectType(): ?string
    {
        return null;
    }

    public function audiences(): array
    {
        return [Audience::FINANCE, Audience::MANAGEMENT];
    }

    public function candidates(int $businessId, string $from, string $to, AutomationRule $rule): iterable
    {
        return Expense::where('business_id', $businessId)->unpaid()
            ->whereNotNull('due_date')->whereDate('due_date', '>=', $from)->whereDate('due_date', '<=', $to)
            ->orderBy('id')->lazy(200);
    }

    public function anchor(Model $target): ?string
    {
        return $target->due_date?->format('Y-m-d');
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        /** @var Expense $target */
        return $target->isPaid() ? Engine::SETTLED : null;
    }

    public function describe(Model $target, array $step): string
    {
        /** @var Expense $target */
        $amount = Money::format((float) $target->amount, Money::of((int) $target->business_id));

        return __('المصروف «:what» غير مسدَّد ومستحقّ منذ :n يومًا (:amount)', [
            'what' => (string) ($target->description ?: $target->type), 'n' => max(0, (int) $step['offset']), 'amount' => $amount,
        ]);
    }

    public function url(Model $target): string
    {
        return route('admin.expenses.index');
    }

    public function subject(Model $target): ?Model
    {
        return null;
    }
}
