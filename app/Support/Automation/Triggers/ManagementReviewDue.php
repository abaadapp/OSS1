<?php

namespace App\Support\Automation\Triggers;

use App\Models\AutomationRule;
use App\Models\Business;
use App\Models\ManagementReview;
use App\Support\Automation\Audience;
use App\Support\Automation\Engine;
use App\Support\Automation\Trigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ربعٌ انتهى ولا مراجعةَ إداريّة له — المرساةُ آخرُ يومٍ في الربع. المرحلة 11.
 *
 * الهدفُ الشركةُ نفسُها (لا مستندَ للربع)، والمرساةُ آخرُ ربعٍ اكتمل. يذكّر
 * ولا يُنشئ مراجعةً: الإدارةُ تكتبها بيدها.
 */
final class ManagementReviewDue extends Trigger
{
    public function key(): string
    {
        return 'management_review_due';
    }

    public function label(): string
    {
        return __('موعد المراجعة الإدارية الربعية');
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
        $anchor = self::lastQuarterEnd();
        if ($anchor->toDateString() < $from || $anchor->toDateString() > $to) {
            return [];
        }
        $business = Business::find($businessId);

        return $business === null ? [] : [$business];
    }

    public function anchor(Model $target): ?string
    {
        return self::lastQuarterEnd()->toDateString();
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        $end = self::lastQuarterEnd();
        $start = $end->copy()->startOfQuarter();

        $covered = ManagementReview::where('business_id', $rule->business_id)
            ->whereDate('period_start', '<=', $start->toDateString())->whereDate('period_end', '>=', $end->toDateString())->exists();

        return $covered ? Engine::RESOLVED : null;
    }

    public function describe(Model $target, array $step): string
    {
        $end = self::lastQuarterEnd();

        return __('انتهى الربع :q من :y ولا مراجعةَ إداريّة له بعد — أنشئها ولُقط أرقامَها', ['q' => $end->quarter, 'y' => $end->year]);
    }

    public function url(Model $target): string
    {
        return route('admin.management.reviews');
    }

    public function subject(Model $target): ?Model
    {
        return null;
    }

    public function targetLabel(Model $target): string
    {
        return __('الربع :q من :y', ['q' => self::lastQuarterEnd()->quarter, 'y' => self::lastQuarterEnd()->year]);
    }

    public static function lastQuarterEnd(): Carbon
    {
        return now()->startOfQuarter()->subDay()->startOfDay();
    }
}
