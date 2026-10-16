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
 * تذكيرُ الفريق بمهامّه قبل الفعاليّة — بالمفتوح وحدَه.
 *
 * المرساةُ يومُ بدء الفعاليّة، والخطواتُ من القاعدة (قبل يوم · صباحَ الفعاليّة).
 * ولا يُذكَّر إلّا من نُشرت مهامُّ فعاليّته؛ ومن أنجز كلَّ مهامّه لا يبلغه شيء.
 * والخطوةُ في مفتاح التكرار مقرونةٌ بتاريخ الفعاليّة: إن تأخّرت فتذكيرُها الجديد
 * غيرُ القديم.
 */
final class EventTasksReminder extends Trigger
{
    public function key(): string
    {
        return EventTasks::REMINDER;
    }

    public function label(): string
    {
        return __('تذكير الفريق بمهامّه قبل الفعاليّة');
    }

    public function subjectType(): ?string
    {
        return null;
    }

    public function audiences(): array
    {
        return [Audience::ASSIGNEE];
    }

    public function candidates(int $businessId, string $from, string $to, AutomationRule $rule): iterable
    {
        return EventOperation::where('business_id', $businessId)->active()->whereNotNull('tasks_published_at')
            ->whereNotNull('event_starts_at')->whereDate('event_starts_at', '>=', $from)->whereDate('event_starts_at', '<=', $to)
            ->orderBy('id')->lazy(200);
    }

    public function anchor(Model $target): ?string
    {
        /** @var EventOperation $target */
        return $target->event_starts_at?->format('Y-m-d');
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        /** @var EventOperation $target */
        if (! $target->isActive() || $target->tasks_published_at === null) {
            return Engine::NOT_ELIGIBLE;
        }

        return EventTasks::assigneeIds($target) === [] ? Engine::RESOLVED : null;
    }

    public function describe(Model $target, array $step): string
    {
        /** @var EventOperation $target */
        $days = -(int) $step['offset'];

        return match (true) {
            $days <= 0 => __('تذكير الفريق بمهامّ الفعاليّة :code — اليوم', ['code' => $target->code]),
            $days === 1 => __('تذكير الفريق بمهامّ الفعاليّة :code — غدًا', ['code' => $target->code]),
            default => __('تذكير الفريق بمهامّ الفعاليّة :code — بعد :n أيام', ['code' => $target->code, 'n' => $days]),
        };
    }

    public function url(Model $target): string
    {
        return route('admin.operations.show', ['id' => $target->getKey(), 'tab' => 'tasks']);
    }

    public function handle(AutomationRun $run, AutomationRule $rule, Model $target, array $step, Collection $users, Settings $settings): ?array
    {
        /** @var EventOperation $target */
        return EventTasks::dispatchAll($target, $users, $this->key(), (int) $step['offset'].'@'.substr((string) $run->occurrence, 0, 10), $rule, $run);
    }
}
