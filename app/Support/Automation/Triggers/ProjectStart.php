<?php

namespace App\Support\Automation\Triggers;

use App\Models\AutomationRule;
use App\Models\ChangeOrder;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Support\Automation\Audience;
use App\Support\Automation\Engine;
use App\Support\Automation\Trigger;
use Illuminate\Database\Eloquent\Model;

/** مشروعٌ يبدأ — المرساةُ يومُ بدئه، والوصفُ يقول ما ينقصه */
final class ProjectStart extends Trigger
{
    public function key(): string
    {
        return 'project_start';
    }

    public function label(): string
    {
        return __('اقتراب موعد المشروع');
    }

    public function subjectType(): ?string
    {
        return 'project';
    }

    public function audiences(): array
    {
        return [Audience::OWNER, Audience::MANAGEMENT, Audience::CUSTOMER];
    }

    public function candidates(int $businessId, string $from, string $to, AutomationRule $rule): iterable
    {
        return Project::where('business_id', $businessId)->open()
            ->whereNotNull('starts_at')->whereDate('starts_at', '>=', $from)->whereDate('starts_at', '<=', $to)
            ->with('customer', 'owner')->orderBy('id')->lazy(200);
    }

    public function anchor(Model $target): ?string
    {
        return $target->starts_at?->format('Y-m-d');
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        /** @var Project $target */
        return $target->isOpen() ? null : Engine::NOT_ELIGIBLE;
    }

    public function describe(Model $target, array $step): string
    {
        /** @var Project $target */
        $days = -(int) $step['offset'];
        $gaps = [];

        if (! $target->isBaselined()) {
            $gaps[] = __('المتطلّبات لم تُثبَّت');
        }
        $late = ProjectTask::where('project_id', $target->id)->where('status', ProjectTask::OPEN)->whereDate('due_at', '<', now()->toDateString())->count();
        if ($late > 0) {
            $gaps[] = __(':n مهامّ متأخّرة', ['n' => $late]);
        }
        if (ChangeOrder::where('project_id', $target->id)->whereIn('status', [ChangeOrder::PENDING_APPROVAL, ChangeOrder::SENT])->exists()) {
            $gaps[] = __('أمر تغيير لم يُحسم');
        }
        if (! $target->members()->exists()) {
            $gaps[] = __('لا فريق مسنَد');
        }

        $head = $days <= 0
            ? __('المشروع :code — :name يبدأ اليوم', ['code' => $target->code, 'name' => $target->name])
            : ($days === 1
                ? __('المشروع :code — :name يبدأ غدًا', ['code' => $target->code, 'name' => $target->name])
                : __('المشروع :code — :name يبدأ بعد :n أيام', ['code' => $target->code, 'name' => $target->name, 'n' => $days]));

        return $gaps === [] ? $head.' — '.__('كلّ شيء جاهز') : $head.' — '.implode(' · ', $gaps);
    }

    public function url(Model $target): string
    {
        return route('admin.projects.show', $target->id);
    }
}
