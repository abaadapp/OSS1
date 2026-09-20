<?php

namespace App\Support\Automation\Triggers;

use App\Models\AutomationRule;
use App\Models\OperationCrewAssignment;
use App\Support\Automation\Audience;
use App\Support\Automation\Engine;
use App\Support\Automation\Trigger;
use App\Support\Operations\Crew;
use Illuminate\Database\Eloquent\Model;

/** تعيينُ فريقٍ يقترب — المرساةُ بدايةُ التعيين، والمعيَّنُ أوّلُ من يُذكَّر (وبالإحاطة إن لم تتمّ) */
final class CrewAssignment extends Trigger
{
    public function key(): string
    {
        return 'crew_assignment';
    }

    public function label(): string
    {
        return __('تذكير الفريق بتعيينه');
    }

    public function subjectType(): ?string
    {
        return null;
    }

    public function audiences(): array
    {
        return [Audience::ASSIGNEE, Audience::LEAD, Audience::CUSTOM];
    }

    public function candidates(int $businessId, string $from, string $to, AutomationRule $rule): iterable
    {
        return OperationCrewAssignment::where('business_id', $businessId)
            ->whereNotNull('user_id')->whereIn('status', OperationCrewAssignment::EXPECTED)
            ->whereNotNull('starts_at')->whereDate('starts_at', '>=', $from)->whereDate('starts_at', '<=', $to)
            ->whereHas('operation', fn ($q) => $q->active())
            ->with('operation.project', 'user')->orderBy('id')->lazy(200);
    }

    public function anchor(Model $target): ?string
    {
        return $target->starts_at?->format('Y-m-d');
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        /** @var OperationCrewAssignment $target */
        if (! $target->isExpected() || ! ($target->operation?->isActive() ?? false)) {
            return Engine::NOT_ELIGIBLE;
        }

        return null;
    }

    public function describe(Model $target, array $step): string
    {
        /** @var OperationCrewAssignment $target */
        $op = $target->operation;
        $offset = (int) $step['offset'];
        $when = $offset < 0 ? __('غدًا') : __('اليوم');
        $line = __('تعيينك :when في الفعاليّة :code — :name (:role) من :from', [
            'when' => $when, 'code' => (string) $op?->code, 'name' => (string) $op?->name,
            'role' => __(Crew::ROLE_LABELS[$target->role] ?? $target->role), 'from' => $target->starts_at?->format('H:i') ?? '—',
        ]);
        if ($op?->venue_name) {
            $line .= ' · '.$op->venue_name;
        }
        if ($target->briefing_status === OperationCrewAssignment::BRIEFING_PENDING) {
            $line .= ' — '.__('لم تتمّ الإحاطة بعد');
        }

        return $line;
    }

    public function url(Model $target): string
    {
        return route('admin.operations.onsite', $target->event_operation_id);
    }

    public function subject(Model $target): ?Model
    {
        return $target->operation?->project;
    }

    public function targetLabel(Model $target): string
    {
        /** @var OperationCrewAssignment $target */
        return $target->displayName().' — '.(string) $target->operation?->code;
    }
}
