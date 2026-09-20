<?php

namespace App\Support\Automation\Triggers;

use App\Models\AutomationRule;
use App\Models\FinancialForecast;
use App\Support\Automation\Audience;
use App\Support\Automation\Engine;
use App\Support\Automation\Trigger;
use Illuminate\Database\Eloquent\Model;

/** توقّعٌ معتمَد مضى عليه شهرٌ — يُذكَّر بتحديثه؛ وما حلّ محلَّه أحدثُ يُتخطّى. المرحلة 11 */
final class ForecastStale extends Trigger
{
    public function key(): string
    {
        return 'forecast_stale';
    }

    public function label(): string
    {
        return __('التوقّع النقدي يحتاج تحديثًا');
    }

    public function subjectType(): ?string
    {
        return null;
    }

    public function audiences(): array
    {
        return [Audience::MANAGEMENT, Audience::FINANCE, Audience::CUSTOM];
    }

    public function candidates(int $businessId, string $from, string $to, AutomationRule $rule): iterable
    {
        return FinancialForecast::where('business_id', $businessId)->where('status', FinancialForecast::APPROVED)
            ->whereNotNull('approved_at')->whereDate('approved_at', '>=', $from)->whereDate('approved_at', '<=', $to)
            ->orderBy('id')->lazy(100);
    }

    public function anchor(Model $target): ?string
    {
        return $target->approved_at?->format('Y-m-d');
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        /** @var FinancialForecast $target */
        return $target->isApproved() ? null : Engine::RESOLVED;
    }

    public function describe(Model $target, array $step): string
    {
        /** @var FinancialForecast $target */
        return __('التوقّع «:name» اعتُمد قبل :n يومًا — حدّثه بتوقّعٍ جديد أو أكّد افتراضاته', ['name' => $target->name, 'n' => (int) $step['offset']]);
    }

    public function url(Model $target): string
    {
        return route('admin.management.cash', ['forecast' => $target->id]);
    }

    public function subject(Model $target): ?Model
    {
        return null;
    }

    public function targetLabel(Model $target): string
    {
        return (string) $target->name;
    }
}
