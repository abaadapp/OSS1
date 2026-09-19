<?php

namespace App\Support\Automation\Triggers;

use App\Models\AutomationRule;
use App\Models\ProjectTask;
use App\Support\Automation\Audience;
use App\Support\Automation\Engine;
use App\Support\Automation\Trigger;
use Illuminate\Database\Eloquent\Model;

/** مهمّةٌ يحين موعدُها أو فات — المرساةُ `due_at`، والمسؤولُ أوّلُ من يُنبَّه */
final class TaskDue extends Trigger
{
    public function key(): string
    {
        return 'task_due';
    }

    public function label(): string
    {
        return __('موعد المهمّة');
    }

    public function subjectType(): ?string
    {
        return null;
    }

    public function audiences(): array
    {
        return [Audience::ASSIGNEE, Audience::OWNER, Audience::MANAGEMENT];
    }

    public function candidates(int $businessId, string $from, string $to, AutomationRule $rule): iterable
    {
        return ProjectTask::where('status', ProjectTask::OPEN)
            ->whereNotNull('due_at')->whereDate('due_at', '>=', $from)->whereDate('due_at', '<=', $to)
            ->whereHas('project', fn ($p) => $p->where('business_id', $businessId)->open())
            ->with('project', 'assignee')->orderBy('id')->lazy(200);
    }

    public function anchor(Model $target): ?string
    {
        return $target->due_at?->format('Y-m-d');
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        /** @var ProjectTask $target */
        if ($target->isDone()) {
            return Engine::NOT_ELIGIBLE;
        }

        return $target->project?->isOpen() ? null : Engine::NOT_ELIGIBLE;
    }

    public function describe(Model $target, array $step): string
    {
        /** @var ProjectTask $target */
        $offset = (int) $step['offset'];
        $where = __('المشروع :code', ['code' => (string) $target->project?->code]);

        return match (true) {
            $offset < 0 => __('المهمّة «:title» تستحقّ غدًا — :where', ['title' => $target->title, 'where' => $where]),
            $offset === 0 => __('المهمّة «:title» تستحقّ اليوم — :where', ['title' => $target->title, 'where' => $where]),
            default => __('المهمّة «:title» متأخّرة منذ :n يومًا — :where', ['title' => $target->title, 'n' => $offset, 'where' => $where]),
        };
    }

    public function url(Model $target): string
    {
        return route('admin.projects.show', $target->project_id);
    }

    public function subject(Model $target): ?Model
    {
        return $target->project;
    }
}
