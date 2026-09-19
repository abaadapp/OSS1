<?php

namespace App\Support\Automation\Triggers;

use App\Models\AutomationRule;
use App\Models\DeliveryPackage;
use App\Support\Automation\Audience;
use App\Support\Automation\Engine;
use App\Support\Automation\Trigger;
use App\Support\DeliveryPackages;
use Illuminate\Database\Eloquent\Model;

/** حزمةُ تسليمٍ أُرسلت وتنتظر قرارَ العميل — المرساةُ يومُ إرسالها */
final class DeliveryAwaiting extends Trigger
{
    public function key(): string
    {
        return 'delivery_awaiting';
    }

    public function label(): string
    {
        return __('حزمة تسليم تنتظر قرار العميل');
    }

    public function subjectType(): ?string
    {
        return 'delivery_package';
    }

    public function audiences(): array
    {
        return [Audience::CUSTOMER, Audience::OWNER, Audience::MANAGEMENT];
    }

    public function candidates(int $businessId, string $from, string $to, AutomationRule $rule): iterable
    {
        return DeliveryPackage::where('business_id', $businessId)->where('status', DeliveryPackage::SENT)
            ->whereNotNull('sent_at')->whereDate('sent_at', '>=', $from)->whereDate('sent_at', '<=', $to)
            ->with('customer', 'project')->orderBy('id')->lazy(200);
    }

    public function anchor(Model $target): ?string
    {
        return $target->sent_at?->format('Y-m-d');
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        /** @var DeliveryPackage $target */
        return $target->isDecidable() ? null : Engine::NOT_ELIGIBLE;
    }

    public function describe(Model $target, array $step): string
    {
        return __('حزمة التسليم :label بانتظار قرار العميل منذ :n يومًا', [
            'label' => DeliveryPackages::label($target), 'n' => max(0, (int) $step['offset']),
        ]);
    }

    public function url(Model $target): string
    {
        return route('admin.deliveries.show', $target->id);
    }
}
