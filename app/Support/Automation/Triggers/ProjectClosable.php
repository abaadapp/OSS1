<?php

namespace App\Support\Automation\Triggers;

use App\Models\AutomationRule;
use App\Models\DeliveryPackage;
use App\Models\Project;
use App\Support\Automation\Audience;
use App\Support\Automation\Engine;
use App\Support\Automation\Trigger;
use App\Support\DeliveryReadiness;
use Illuminate\Database\Eloquent\Model;

/** مشروعٌ قُبل تسليمُه وما زال مفتوحًا — يُذكَّر صاحبُه بالإقفال، ولا يُقفل وحده */
final class ProjectClosable extends Trigger
{
    public function key(): string
    {
        return 'project_closable';
    }

    public function label(): string
    {
        return __('مشروع جاهز للإقفال');
    }

    public function subjectType(): ?string
    {
        return null;
    }

    public function audiences(): array
    {
        return [Audience::OWNER, Audience::MANAGEMENT];
    }

    public function candidates(int $businessId, string $from, string $to, AutomationRule $rule): iterable
    {
        $ids = DeliveryPackage::where('business_id', $businessId)->where('status', DeliveryPackage::ACCEPTED)
            ->whereNotNull('accepted_at')->whereDate('accepted_at', '>=', $from)->whereDate('accepted_at', '<=', $to)
            ->whereNotNull('project_id')->distinct()->pluck('project_id');

        return Project::where('business_id', $businessId)->open()->whereIn('id', $ids)->with('customer', 'owner')->orderBy('id')->lazy(100);
    }

    public function anchor(Model $target): ?string
    {
        return DeliveryPackage::where('project_id', $target->id)->where('status', DeliveryPackage::ACCEPTED)->max('accepted_at')
            ? substr((string) DeliveryPackage::where('project_id', $target->id)->where('status', DeliveryPackage::ACCEPTED)->max('accepted_at'), 0, 10)
            : null;
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        /** @var Project $target */
        if (! $target->isOpen()) {
            return Engine::NOT_ELIGIBLE;
        }

        return DeliveryReadiness::forProject($target)->ready ? null : Engine::NOT_ELIGIBLE;
    }

    public function describe(Model $target, array $step): string
    {
        return __('المشروع :code — :name: التسليم مقبول وما زال مفتوحًا — أقفله', ['code' => $target->code, 'name' => $target->name]);
    }

    public function url(Model $target): string
    {
        return route('admin.projects.show', $target->id);
    }

    public function subject(Model $target): ?Model
    {
        return null;
    }
}
