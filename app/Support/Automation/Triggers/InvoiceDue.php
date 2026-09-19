<?php

namespace App\Support\Automation\Triggers;

use App\Models\AutomationRule;
use App\Models\CustomerInvoice;
use App\Support\Automation\Audience;
use App\Support\Automation\Engine;
use App\Support\Automation\Trigger;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * فاتورةُ عميلٍ تستحقّ أو تأخّرت — المرساةُ `due_at`.
 *
 * وقبل كلّ خطوةٍ تُقرأ الفاتورةُ طازجةً: صادرةٌ وعليها رصيد؛ وما سُدّد أو
 * أُلغي أو غطّاه إشعارٌ دائن يُتخطّى. ولا تُمسّ الفاتورةُ ولا السدادُ أبدًا.
 */
final class InvoiceDue extends Trigger
{
    public function key(): string
    {
        return 'invoice_due';
    }

    public function label(): string
    {
        return __('استحقاق فاتورة العميل');
    }

    public function subjectType(): ?string
    {
        return 'customer_invoice';
    }

    public function audiences(): array
    {
        return [Audience::CUSTOMER, Audience::FINANCE, Audience::MANAGEMENT];
    }

    public function candidates(int $businessId, string $from, string $to, AutomationRule $rule): iterable
    {
        return CustomerInvoice::where('business_id', $businessId)->live()
            ->whereNotNull('due_at')->whereDate('due_at', '>=', $from)->whereDate('due_at', '<=', $to)
            ->whereRaw(CustomerInvoice::outstandingSql().' > 0.0005')
            ->with('customer')->orderBy('id')->lazy(200);
    }

    public function anchor(Model $target): ?string
    {
        return $target->due_at?->format('Y-m-d');
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        /** @var CustomerInvoice $target */
        if ($target->status !== CustomerInvoice::ISSUED) {
            return Engine::NOT_ELIGIBLE;
        }

        return $target->outstanding() > 0.0005 ? null : Engine::SETTLED;
    }

    public function describe(Model $target, array $step): string
    {
        /** @var CustomerInvoice $target */
        $offset = (int) $step['offset'];
        $amount = Money::format($target->outstanding(), Money::of((int) $target->business_id));
        $who = (string) ($target->customer?->name ?? $target->customer_name);

        return match (true) {
            $offset < 0 => __('الفاتورة :number — :customer تستحقّ بعد :n أيام (:amount)', ['number' => $target->number, 'customer' => $who, 'n' => -$offset, 'amount' => $amount]),
            $offset === 0 => __('الفاتورة :number — :customer تستحقّ اليوم (:amount)', ['number' => $target->number, 'customer' => $who, 'amount' => $amount]),
            default => __('الفاتورة :number — :customer متأخّرة منذ :n يومًا (:amount)', ['number' => $target->number, 'customer' => $who, 'n' => $offset, 'amount' => $amount]),
        };
    }

    public function url(Model $target): string
    {
        return route('admin.customerInvoices.show', $target->id);
    }
}
