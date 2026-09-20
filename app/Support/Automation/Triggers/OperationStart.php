<?php

namespace App\Support\Automation\Triggers;

use App\Models\AutomationRule;
use App\Models\EventOperation;
use App\Support\Automation\Audience;
use App\Support\Automation\Engine;
use App\Support\Automation\Trigger;
use App\Support\Operations\OperationReadiness;
use Illuminate\Database\Eloquent\Model;

/**
 * فعاليّةٌ تقترب — المرساةُ يومُ بدئها، والوصفُ يقول ما ينقص جاهزيّتَها.
 *
 * T-7 تحذيرُ جاهزيّة، وT-3 موانعُ لم تُحلّ، وT-1 الجاهزيّةُ الأخيرة، وصباحُ
 * الفعاليّة موجزُها. داخليٌّ كلُّه: لا يبلغ العميل.
 */
final class OperationStart extends Trigger
{
    public function key(): string
    {
        return 'operation_start';
    }

    public function label(): string
    {
        return __('اقتراب موعد الفعاليّة');
    }

    public function subjectType(): ?string
    {
        return null;
    }

    public function audiences(): array
    {
        return [Audience::LEAD, Audience::OWNER, Audience::MANAGEMENT, Audience::CUSTOM];
    }

    public function candidates(int $businessId, string $from, string $to, AutomationRule $rule): iterable
    {
        return EventOperation::where('business_id', $businessId)->active()
            ->whereNotNull('event_starts_at')->whereDate('event_starts_at', '>=', $from)->whereDate('event_starts_at', '<=', $to)
            ->with('project', 'lead')->orderBy('id')->lazy(200);
    }

    public function anchor(Model $target): ?string
    {
        return $target->event_starts_at?->format('Y-m-d');
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        /** @var EventOperation $target */
        if (! $target->isActive()) {
            return Engine::NOT_ELIGIBLE;
        }
        /* والجاهزةُ تمامًا لا تُذكَّر بموانعَ ليست لها — إلّا صباحَ الفعاليّة */
        if ((int) $step['offset'] < 0 && OperationReadiness::for($target)->ready) {
            return Engine::RESOLVED;
        }

        return null;
    }

    public function describe(Model $target, array $step): string
    {
        /** @var EventOperation $target */
        $days = -(int) $step['offset'];
        $verdict = OperationReadiness::for($target);

        $head = match (true) {
            $days <= 0 => __('الفعاليّة :code — :name اليوم', ['code' => $target->code, 'name' => $target->name]),
            $days === 1 => __('الفعاليّة :code — :name غدًا', ['code' => $target->code, 'name' => $target->name]),
            default => __('الفعاليّة :code — :name بعد :n أيام', ['code' => $target->code, 'name' => $target->name, 'n' => $days]),
        };

        if ($verdict->ready) {
            return $head.' — '.__('جاهزة').(count($verdict->warnings) > 0 ? ' · '.__(':n تحذيرات', ['n' => count($verdict->warnings)]) : '');
        }

        return $head.' — '.__('غير جاهزة').': '.implode(' · ', array_slice($verdict->blockerMessages(), 0, 4));
    }

    public function url(Model $target): string
    {
        return route('admin.operations.show', $target->id);
    }

    public function subject(Model $target): ?Model
    {
        return $target->project;
    }
}
