<?php

namespace App\Support\Automation\Triggers;

use App\Models\AssetReservation;
use App\Models\AutomationRule;
use App\Support\Automation\Audience;
use App\Support\Automation\Engine;
use App\Support\Automation\Trigger;
use Illuminate\Database\Eloquent\Model;

/** قطعةٌ محجوزة يحين خروجُها — المرساةُ بدايةُ الحجز؛ وما سُلّم يُتخطّى */
final class EquipmentCheckout extends Trigger
{
    public function key(): string
    {
        return 'equipment_checkout';
    }

    public function label(): string
    {
        return __('تذكير بتسليم المعدّات');
    }

    public function subjectType(): ?string
    {
        return null;
    }

    public function audiences(): array
    {
        return [Audience::LEAD, Audience::MANAGEMENT, Audience::CUSTOM];
    }

    public function candidates(int $businessId, string $from, string $to, AutomationRule $rule): iterable
    {
        return AssetReservation::where('business_id', $businessId)->where('status', AssetReservation::RESERVED)
            ->whereDate('reserved_from', '>=', $from)->whereDate('reserved_from', '<=', $to)
            ->whereHas('operation', fn ($q) => $q->active())
            ->with('asset', 'operation.project')->orderBy('id')->lazy(200);
    }

    public function anchor(Model $target): ?string
    {
        return $target->reserved_from?->format('Y-m-d');
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        /** @var AssetReservation $target */
        if ($target->status !== AssetReservation::RESERVED) {
            return Engine::RESOLVED;
        }

        return ($target->operation?->isActive() ?? false) ? null : Engine::NOT_ELIGIBLE;
    }

    public function describe(Model $target, array $step): string
    {
        /** @var AssetReservation $target */
        return __('القطعة :asset محجوزة للفعاليّة :code ولم تُسلَّم بعد — تخرج :when', [
            'asset' => (string) $target->asset?->label(), 'code' => (string) $target->operation?->code,
            'when' => (int) $step['offset'] < 0 ? __('غدًا') : __('اليوم'),
        ]);
    }

    public function url(Model $target): string
    {
        return route('admin.operations.show', $target->event_operation_id);
    }

    public function subject(Model $target): ?Model
    {
        return $target->operation?->project;
    }

    public function targetLabel(Model $target): string
    {
        return (string) $target->asset?->label().' — '.(string) $target->operation?->code;
    }
}
