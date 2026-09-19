<?php

namespace App\Support\Automation\Triggers;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Business;
use App\Support\Automation\Audience;
use App\Support\Automation\Brief;
use App\Support\Automation\Settings;
use App\Support\Automation\Trigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * الموجزُ الصباحيّ — من مركز العمليات، إلى موظّفين بأعينهم، مرّةً في اليوم.
 *
 * الهدفُ الشركةُ نفسُها والمرساةُ اليوم: مفتاحُ التكرار «قاعدة + شركة +
 * تاريخ» يضمن موجزًا واحدًا مهما تكرّر المجدول. وكلُّ متلقٍّ يقرأ الموجزَ
 * بصلاحيّاته هو — `CommandCenter::for` تُشكّل المالَ لمن يملكه.
 */
final class MorningBrief extends Trigger
{
    public function key(): string
    {
        return 'morning_brief';
    }

    public function label(): string
    {
        return __('الموجز الصباحي');
    }

    public function subjectType(): ?string
    {
        return null;
    }

    public function audiences(): array
    {
        return [Audience::MANAGEMENT, Audience::CUSTOM];
    }

    public function candidates(int $businessId, string $from, string $to, AutomationRule $rule): iterable
    {
        $business = Business::find($businessId);

        return $business === null ? [] : [$business];
    }

    public function anchor(Model $target): ?string
    {
        return Settings::for((int) $target->id)->now()->format('Y-m-d');
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        return null;
    }

    public function describe(Model $target, array $step): string
    {
        return __('الموجز الصباحي — :company', ['company' => (string) $target->name]);
    }

    public function url(Model $target): string
    {
        return route('admin.command.index');
    }

    public function subject(Model $target): ?Model
    {
        return null;
    }

    public function targetLabel(Model $target): string
    {
        return (string) $target->name;
    }

    public function handle(AutomationRun $run, AutomationRule $rule, Model $target, array $step, Collection $users, Settings $settings): ?array
    {
        return Brief::deliver((int) $target->id, $rule, $run, $users);
    }
}
