<?php

namespace App\Support\Automation\Triggers;

use App\Models\AssetReservation;
use App\Models\AutomationRule;
use App\Support\Automation\Audience;
use App\Support\Automation\Engine;
use App\Support\Automation\Trigger;
use Illuminate\Database\Eloquent\Model;

/** قطعةٌ خرجت ومضى موعدُ عودتها — المرساةُ نهايةُ الحجز؛ وما رُدّ يُتخطّى */
final class EquipmentUnreturned extends Trigger
{
    public function key(): string
    {
        return 'equipment_unreturned';
    }

    public function label(): string
    {
        return __('معدّات لم تُردّ');
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
        return AssetReservation::where('business_id', $businessId)->where('status', AssetReservation::CHECKED_OUT)
            ->whereDate('reserved_until', '>=', $from)->whereDate('reserved_until', '<=', $to)
            ->with('asset', 'operation.project', 'custodian')->orderBy('id')->lazy(200);
    }

    public function anchor(Model $target): ?string
    {
        return $target->reserved_until?->format('Y-m-d');
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        /** @var AssetReservation $target */
        return $target->status === AssetReservation::CHECKED_OUT ? null : Engine::RESOLVED;
    }

    public function describe(Model $target, array $step): string
    {
        /** @var AssetReservation $target */
        $offset = (int) $step['offset'];

        return __('القطعة :asset لم تُردّ من الفعاليّة :code — متأخّرة :n يومًا · في عهدة :who', [
            'asset' => (string) $target->asset?->label(), 'code' => (string) $target->operation?->code,
            'n' => max(0, $offset), 'who' => (string) ($target->custodian?->name ?? '—'),
        ]);
    }

    public function url(Model $target): string
    {
        return route('admin.assets.show', $target->asset_id);
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
