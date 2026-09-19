<?php

namespace App\Support\Automation\Triggers;

use App\Models\AutomationRule;
use App\Models\PurchaseOrder;
use App\Support\Automation\Audience;
use App\Support\Automation\Engine;
use App\Support\Automation\Trigger;
use App\Support\PurchaseOrders;
use Illuminate\Database\Eloquent\Model;

/** أمرُ شراءٍ تجاوز موعدَ تسليمه ولم يُستلم كاملًا — داخليّ */
final class PurchaseOrderLate extends Trigger
{
    public function key(): string
    {
        return 'purchase_order_late';
    }

    public function label(): string
    {
        return __('تأخّر تسليم أمر شراء');
    }

    public function subjectType(): ?string
    {
        return null;
    }

    public function audiences(): array
    {
        return [Audience::MANAGEMENT, Audience::FINANCE];
    }

    public function candidates(int $businessId, string $from, string $to, AutomationRule $rule): iterable
    {
        return PurchaseOrder::where('business_id', $businessId)->whereIn('status', [PurchaseOrders::SENT, PurchaseOrders::PARTIAL])
            ->whereNotNull('expected_delivery_at')->whereDate('expected_delivery_at', '>=', $from)->whereDate('expected_delivery_at', '<=', $to)
            ->with('supplier')->orderBy('id')->lazy(200);
    }

    public function anchor(Model $target): ?string
    {
        return $target->expected_delivery_at ? substr((string) $target->expected_delivery_at, 0, 10) : null;
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        return in_array($target->status, [PurchaseOrders::SENT, PurchaseOrders::PARTIAL], true) ? null : Engine::NOT_ELIGIBLE;
    }

    public function describe(Model $target, array $step): string
    {
        return __('أمر الشراء :number — :supplier تجاوز موعد التسليم منذ :n يومًا', [
            'number' => (string) $target->number, 'supplier' => (string) ($target->supplier?->name ?? $target->supplier_name), 'n' => max(0, (int) $step['offset']),
        ]);
    }

    public function url(Model $target): string
    {
        return route('admin.purchases.show', $target->id);
    }

    public function subject(Model $target): ?Model
    {
        return null;
    }
}
