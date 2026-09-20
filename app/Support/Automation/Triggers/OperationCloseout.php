<?php

namespace App\Support\Automation\Triggers;

use App\Models\AutomationRule;
use App\Models\EventOperation;
use App\Support\Automation\Audience;
use App\Support\Automation\Engine;
use App\Support\Automation\Trigger;
use App\Support\Operations\EventOperations;
use Illuminate\Database\Eloquent\Model;

/**
 * فعاليّةٌ انتهت ولم تُتمّ — المرساةُ نهايتُها، والوصفُ يقول ما يمنع الإتمام:
 * قائمةُ إقفالٍ ناقصة، حادثٌ لم يُحلّ، معدّاتٌ لم تُردّ.
 */
final class OperationCloseout extends Trigger
{
    public function key(): string
    {
        return 'operation_closeout';
    }

    public function label(): string
    {
        return __('فعاليّة انتهت ولم تُتمّ');
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
            ->whereNotNull('event_ends_at')->whereDate('event_ends_at', '>=', $from)->whereDate('event_ends_at', '<=', $to)
            ->with('project', 'lead')->orderBy('id')->lazy(200);
    }

    public function anchor(Model $target): ?string
    {
        return $target->event_ends_at?->format('Y-m-d');
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        /** @var EventOperation $target */
        if ($target->isCompleted()) {
            return Engine::RESOLVED;
        }

        return $target->isActive() ? null : Engine::NOT_ELIGIBLE;
    }

    public function describe(Model $target, array $step): string
    {
        /** @var EventOperation $target */
        $blockers = EventOperations::completionBlockers($target);

        return __('الفعاليّة :code — :name انتهت ولم تُتمّ', ['code' => $target->code, 'name' => $target->name])
            .($blockers === [] ? ' — '.__('يمكن إتمامها الآن') : ': '.implode(' · ', array_slice($blockers, 0, 4)));
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
