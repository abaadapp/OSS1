<?php

namespace App\Support\Automation\Triggers;

use App\Models\AutomationRule;
use App\Models\SupplierInvoice;
use App\Support\Automation\Audience;
use App\Support\Automation\Engine;
use App\Support\Automation\Trigger;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/** سندُ مورّدٍ معتمَد يستحقّ أو تأخّر — داخليٌّ للمالية؛ ولا يُسدَّد شيءٌ وحده */
final class SupplierInvoiceDue extends Trigger
{
    public function key(): string
    {
        return 'supplier_invoice_due';
    }

    public function label(): string
    {
        return __('استحقاق سند مورّد');
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
        return SupplierInvoice::where('business_id', $businessId)->owed()
            ->whereNotNull('due_at')->whereDate('due_at', '>=', $from)->whereDate('due_at', '<=', $to)
            ->whereRaw('(total - paid) > 0.0005')
            ->with('supplier')->orderBy('id')->lazy(200);
    }

    public function anchor(Model $target): ?string
    {
        return $target->due_at?->format('Y-m-d');
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        /** @var SupplierInvoice $target */
        if (! $target->owed()) {
            return Engine::NOT_ELIGIBLE;
        }

        return $target->outstanding() > 0.0005 ? null : Engine::SETTLED;
    }

    public function describe(Model $target, array $step): string
    {
        /** @var SupplierInvoice $target */
        $offset = (int) $step['offset'];
        $amount = Money::format($target->outstanding(), Money::of((int) $target->business_id));
        $who = (string) ($target->supplier?->name ?? '');

        return $offset > 0
            ? __('سند المورّد :ref — :supplier متأخّر منذ :n يومًا (:amount)', ['ref' => $target->supplier_ref, 'supplier' => $who, 'n' => $offset, 'amount' => $amount])
            : __('سند المورّد :ref — :supplier يستحقّ :when (:amount)', ['ref' => $target->supplier_ref, 'supplier' => $who,
                'when' => $offset === 0 ? __('اليوم') : __('بعد :n أيام', ['n' => -$offset]), 'amount' => $amount]);
    }

    public function url(Model $target): string
    {
        return route('admin.purchases.invoices.show', $target->id);
    }

    public function subject(Model $target): ?Model
    {
        return null;
    }
}
