<?php

namespace App\Support\Automation\Triggers;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\EventOperation;
use App\Support\Automation\Audience;
use App\Support\Automation\Engine;
use App\Support\Automation\Settings;
use App\Support\Automation\Trigger;
use App\Support\Operations\EventTasks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * نُشرت مهامُّ الفعاليّة على الفريق — رسالةٌ واحدة لكلّ موظّفٍ بمهامّه فيها.
 *
 * المرساةُ لحظةُ النشر (`tasks_published_at`)، فالخطوةُ فوريّة: تخرج في دورة
 * الأتمتة التالية لا في ساعة الإرسال. والجمعُ والإرسالُ ومنعُ التكرار لكلّ
 * موظّفٍ في `EventTasks::dispatchAll` — ومن أُرسل إليه مرّةً لا يُرسل إليه
 * ثانيةً إن أُعيد النشر؛ يصل الجديدُ إلى من لم يصله.
 */
final class EventTasksAssigned extends Trigger
{
    public function key(): string
    {
        return EventTasks::ASSIGNED;
    }

    public function label(): string
    {
        return __('نشر مهام الفعاليّة على الفريق');
    }

    public function subjectType(): ?string
    {
        return null;
    }

    public function audiences(): array
    {
        return [Audience::ASSIGNEE];
    }

    public function immediate(): bool
    {
        return true;
    }

    public function candidates(int $businessId, string $from, string $to, AutomationRule $rule): iterable
    {
        return EventOperation::where('business_id', $businessId)->active()->whereNotNull('tasks_published_at')
            ->whereDate('tasks_published_at', '>=', $from)->whereDate('tasks_published_at', '<=', $to)
            ->orderBy('id')->lazy(200);
    }

    public function anchor(Model $target): ?string
    {
        /** @var EventOperation $target */
        return $target->tasks_published_at?->format('Y-m-d H:i:s');
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        /** @var EventOperation $target */
        if (! $target->isActive()) {
            return Engine::NOT_ELIGIBLE;
        }

        return EventTasks::assigneeIds($target) === [] ? Engine::RESOLVED : null;
    }

    public function describe(Model $target, array $step): string
    {
        /** @var EventOperation $target */
        return __('مهامّ الفعاليّة :code — :name إلى :n موظّفين', [
            'code' => $target->code, 'name' => $target->name, 'n' => count(EventTasks::assigneeIds($target)),
        ]);
    }

    public function url(Model $target): string
    {
        return route('admin.operations.show', ['id' => $target->getKey(), 'tab' => 'tasks']);
    }

    public function handle(AutomationRun $run, AutomationRule $rule, Model $target, array $step, Collection $users, Settings $settings): ?array
    {
        /** @var EventOperation $target */
        return EventTasks::dispatchAll($target, $users, $this->key(), 'assigned', $rule, $run);
    }
}
