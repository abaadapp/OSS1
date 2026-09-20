<?php

namespace App\Support\Automation\Triggers;

use App\Models\AutomationRule;
use App\Models\Business;
use App\Support\Automation\Audience;
use App\Support\Automation\Trigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** شهرٌ انتهى وتقريرُه الإداريّ جاهزٌ للقراءة — المرساةُ آخرُ يومٍ فيه. المرحلة 11 */
final class ManagementReportReady extends Trigger
{
    public function key(): string
    {
        return 'management_report_ready';
    }

    public function label(): string
    {
        return __('التقرير الإداري الشهري جاهز');
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
        $anchor = self::lastMonthEnd();
        if ($anchor->toDateString() < $from || $anchor->toDateString() > $to) {
            return [];
        }
        $business = Business::find($businessId);

        return $business === null ? [] : [$business];
    }

    public function anchor(Model $target): ?string
    {
        return self::lastMonthEnd()->toDateString();
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        return null;
    }

    public function describe(Model $target, array $step): string
    {
        return __('التقرير الإداري لشهر :m جاهز — الإيراد والتحصيل والمصروفات والنقد والذمم والمشاريع', ['m' => self::lastMonthEnd()->format('Y-m')]);
    }

    public function url(Model $target): string
    {
        return route('admin.management.reports', ['report' => 'management_monthly', 'period' => 'last_month']);
    }

    public function subject(Model $target): ?Model
    {
        return null;
    }

    public function targetLabel(Model $target): string
    {
        return __('شهر :m', ['m' => self::lastMonthEnd()->format('Y-m')]);
    }

    public static function lastMonthEnd(): Carbon
    {
        return now()->startOfMonth()->subDay()->startOfDay();
    }
}
