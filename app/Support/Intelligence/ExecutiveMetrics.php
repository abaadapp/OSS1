<?php

namespace App\Support\Intelligence;

use App\Models\Budget;
use App\Models\EventOperation;
use App\Models\FinancialForecast;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Money;
use App\Support\Permissions;

/**
 * لوحةُ الإدارة — «كيف تؤدّي الشركة؟» في أقلَّ من نصف دقيقة. المرحلة 11.
 *
 * تركيبٌ فوق الخدمات لا حسابٌ جديد: الأداءُ من `FinancialPerformance`، والنقدُ
 * من `CashIntelligence`، والقمعُ من `RevenueIntelligence`، والتنبيهاتُ من
 * `ManagementAlerts`. وكلُّ رقمٍ يخرج بمدّته وعملته وأساسه.
 *
 * والمالُ لمن يملك رؤيته: من فتح «الإدارة» بلا `analytics.financial` تصله
 * الحركةُ (مشاريعُ وعروضٌ وفعاليّات) وتصله الأرقامُ الماليّة `null`.
 */
final class ExecutiveMetrics
{
    /** @return array<string, mixed> */
    public static function overview(int $bid, Period $period, ?User $viewer): array
    {
        $financial = $viewer?->may(Permissions::ANALYTICS_FINANCIAL) ?? false;
        $projects = $viewer?->may(Permissions::ANALYTICS_PROJECTS) ?? false;
        $operations = $viewer?->may(Permissions::ANALYTICS_OPERATIONS) ?? false;

        $out = [
            'period' => $period->toArray(),
            'periods' => collect(Period::LABELS)->map(fn ($l, $k) => ['key' => $k, 'label' => __($l)])->values()->all(),
            'currency' => Money::of($bid),
            'activity' => self::activity($bid, $period),
            'generated_at' => now()->format('Y-m-d H:i'),
            'may' => ['financial' => $financial, 'projects' => $projects, 'operations' => $operations],
        ];

        if ($financial) {
            $snapshot = FinancialPerformance::snapshot($bid, $period);
            $cash = CashIntelligence::current($bid);
            $forecast = FinancialForecast::where('business_id', $bid)->where('status', FinancialForecast::APPROVED)->orderByDesc('approved_at')->first();
            $budget = Budget::where('business_id', $bid)->where('status', Budget::APPROVED)->where('fiscal_year', (int) now()->format('Y'))->first();

            $out['financial'] = $snapshot;
            $out['cash'] = $cash;
            $out['trend'] = FinancialPerformance::monthly($bid, Period::resolve($bid, 'last_12_months'));
            $out['funnel'] = RevenueIntelligence::funnel($bid, $period);
            $out['ladder'] = RevenueIntelligence::ladder($bid, $period);
            $out['forecast'] = $forecast === null ? null : [
                'id' => $forecast->id, 'name' => $forecast->name, 'horizon_days' => $forecast->horizon_days, 'base_date' => $forecast->base_date->toDateString(),
                'closing' => (float) ($forecast->totals['closing'] ?? 0), 'lowest_closing' => (float) ($forecast->totals['lowest_closing'] ?? 0),
                'inflows' => (float) ($forecast->totals['inflows']['total'] ?? 0), 'outflows' => (float) ($forecast->totals['outflows']['total'] ?? 0),
                'completeness' => $forecast->projection['completeness'] ?? null,
            ];
            $out['budget'] = $budget === null ? null : self::budgetStatus($budget);
            $out['runway'] = CashIntelligence::runway($bid);
            $out['alerts'] = ManagementAlerts::for($bid, $period);
        }

        if ($projects) {
            $list = ProjectProfitability::list($bid, null, 'margin_asc', 60);
            $out['projects'] = [
                'totals' => $list['totals'],
                'weakest' => array_slice($list['rows'], 0, 5),
                'strongest' => array_slice(array_reverse(array_values(array_filter($list['rows'], fn ($r) => $r['margin_percent'] !== null))), 0, 5),
            ];
        }

        if ($operations) {
            $out['operations'] = self::upcomingOperations($bid);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function activity(int $bid, Period $period): array
    {
        $q = fn () => Quotation::where('business_id', $bid);
        $p = fn () => Project::where('business_id', $bid);

        return [
            'active_projects' => (int) $p()->open()->count(),
            'projects_won' => (int) $p()->whereNotIn('status', [Project::DRAFT, Project::CANCELLED])
                ->whereDate('created_at', '>=', $period->startDate())->whereDate('created_at', '<', $period->endDate())->count(),
            'projects_completed' => (int) $p()->where('status', Project::CLOSED)
                ->where('closed_at', '>=', $period->startAt())->where('closed_at', '<', $period->endAt())->count(),
            'quotations_pending' => (int) $q()->whereIn('status', [Quotation::SENT, Quotation::PENDING_APPROVAL])
                ->where(fn ($w) => $w->whereNull('valid_until')->orWhereDate('valid_until', '>=', now()->toDateString()))->count(),
            'quotations_accepted' => (int) $q()->where('status', Quotation::ACCEPTED)
                ->where('decided_at', '>=', $period->startAt())->where('decided_at', '<', $period->endAt())->count(),
            'quotations_rejected' => (int) $q()->where('status', Quotation::REJECTED)
                ->where('decided_at', '>=', $period->startAt())->where('decided_at', '<', $period->endAt())->count(),
            'quotations_expired' => (int) $q()->where('status', Quotation::SENT)->whereNotNull('valid_until')
                ->whereDate('valid_until', '<', now()->toDateString())->count(),
            'upcoming_operations' => (int) EventOperation::where('business_id', $bid)->active()
                ->whereBetween('event_starts_at', [now(), now()->addDays(30)])->count(),
        ];
    }

    /** @return array<string, mixed> */
    private static function budgetStatus(Budget $budget): array
    {
        $bva = Budgeting::versusActual($budget, 'month');
        $month = (int) now()->format('n');
        $ytd = fn (string $kind, string $key) => round((float) collect($bva['rows'])->where('kind', $kind)
            ->sum(fn ($r) => array_sum(array_map(fn ($b) => $b[$key], array_slice($r['buckets'], 0, $month)))), 3);

        return [
            'id' => $budget->id, 'name' => $budget->name, 'version' => $budget->version, 'fiscal_year' => $budget->fiscal_year,
            'ytd' => [
                'revenue' => ['planned' => $ytd('revenue', 'planned'), 'actual' => $ytd('revenue', 'actual')],
                'expense' => ['planned' => $ytd('expense', 'planned'), 'actual' => $ytd('expense', 'actual')],
            ],
            'basis' => __('منذ بداية السنة حتى نهاية الشهر الحاليّ — الفعليُّ من الدفتر'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function upcomingOperations(int $bid): array
    {
        return EventOperation::where('business_id', $bid)->active()
            ->whereBetween('event_starts_at', [now()->startOfDay(), now()->addDays(30)])
            ->with('project:id,code', 'lead:id,name')->orderBy('event_starts_at')->limit(8)->get()
            ->map(fn (EventOperation $o) => ['id' => $o->id, 'code' => $o->code, 'name' => $o->name, 'status' => $o->status,
                'starts_at' => $o->event_starts_at?->format('Y-m-d'), 'lead' => $o->lead?->name, 'project' => $o->project?->code,
                'url' => route('admin.operations.show', $o->id)])->values()->all();
    }
}
