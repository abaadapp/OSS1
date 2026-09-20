<?php

namespace App\Support\Intelligence;

use App\Models\ManagementDecision;
use App\Models\ManagementReview;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * المراجعاتُ الإداريّة — لقطةٌ وملاحظاتٌ وقراراتٌ تكتبها الإدارة. المرحلة 11.
 *
 * النظامُ يجمع الأرقامَ ويحفظها كما كانت يومَ المراجعة، والإدارةُ تكتب ما
 * تراه وتسجّل ما قرّرت. ولا توصيةَ يولّدها النظام: يسجّل ولا يقرّر.
 */
final class ManagementReviews
{
    public const STATUS_LABELS = [ManagementReview::DRAFT => 'مسودّة', ManagementReview::REVIEWED => 'رُوجعت', ManagementReview::CLOSED => 'مقفلة'];

    public const DECISION_LABELS = [ManagementDecision::OPEN => 'مفتوح', ManagementDecision::DONE => 'نُفّذ', ManagementDecision::DROPPED => 'أُلغي'];

    /** @param  array<string, mixed>  $data */
    public static function create(int $bid, array $data, ?User $actor = null): ManagementReview
    {
        [$start, $end] = self::bounds($data);
        $title = mb_substr(trim((string) ($data['title'] ?? '')), 0, 160) ?: __('مراجعة :from — :to', ['from' => $start->toDateString(), 'to' => $end->toDateString()]);

        $review = ManagementReview::create([
            'business_id' => $bid, 'title' => $title, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
            'status' => ManagementReview::DRAFT, 'prepared_by' => $actor?->id,
            'notes' => mb_substr(trim((string) ($data['notes'] ?? '')), 0, 20000) ?: null,
            'snapshot' => self::snapshot($bid, $start, $end, $actor),
        ]);

        Activity::log('created', 'أنشأ المراجعة الإدارية «'.$title.'»', [
            'business_id' => $bid, 'subject_id' => $review->id, 'subject_type' => 'management_review', 'icon' => 'clipboard-check',
        ]);

        return $review;
    }

    /** @param  array<string, mixed>  $data */
    public static function update(ManagementReview $review, array $data, ?User $actor = null): ManagementReview
    {
        if ($review->status === ManagementReview::CLOSED) {
            throw new RuntimeException(__('المراجعة مقفلة — لا تُعدَّل.'));
        }
        $update = [];
        if (array_key_exists('title', $data)) {
            $update['title'] = mb_substr(trim((string) $data['title']), 0, 160) ?: $review->title;
        }
        if (array_key_exists('notes', $data)) {
            $update['notes'] = mb_substr(trim((string) $data['notes']), 0, 20000) ?: null;
        }
        if (array_key_exists('sections', $data) && is_array($data['sections'])) {
            $allowed = ['financial', 'budget', 'forecast', 'cash', 'projects', 'sales', 'operations', 'risks', 'notes'];
            $sections = [];
            foreach ($data['sections'] as $k => $v) {
                if (in_array($k, $allowed, true)) {
                    $sections[$k] = mb_substr(trim((string) $v), 0, 5000);
                }
            }
            $update['sections'] = $sections;
        }
        $review->update($update);

        return $review->fresh();
    }

    /** إعادةُ التقاط الأرقام — للمسودّة وحدَها */
    public static function refreshSnapshot(ManagementReview $review, ?User $actor = null): ManagementReview
    {
        if ($review->status !== ManagementReview::DRAFT) {
            throw new RuntimeException(__('اللقطةُ تُجمَّد بعد المراجعة.'));
        }
        $review->update(['snapshot' => self::snapshot((int) $review->business_id, $review->period_start, $review->period_end, $actor)]);

        return $review->fresh();
    }

    public static function markReviewed(ManagementReview $review, ?User $actor = null): ManagementReview
    {
        if ($review->status !== ManagementReview::DRAFT) {
            return $review;
        }
        $review->update(['status' => ManagementReview::REVIEWED, 'reviewed_at' => now(), 'reviewed_by' => $actor?->id]);

        Activity::log('status', 'راجع المراجعة الإدارية «'.$review->title.'»', [
            'business_id' => (int) $review->business_id, 'subject_id' => $review->id, 'subject_type' => 'management_review',
        ]);

        return $review->fresh();
    }

    public static function close(ManagementReview $review, ?User $actor = null): ManagementReview
    {
        if ($review->status === ManagementReview::CLOSED) {
            return $review;
        }
        if ($review->status !== ManagementReview::REVIEWED) {
            throw new RuntimeException(__('تُراجَع أوّلًا ثمّ تُقفل.'));
        }
        $review->update(['status' => ManagementReview::CLOSED, 'closed_at' => now(), 'closed_by' => $actor?->id]);

        Activity::log('status', 'أقفل المراجعة الإدارية «'.$review->title.'» — '.$review->decisions()->count().' قرارًا', [
            'business_id' => (int) $review->business_id, 'subject_id' => $review->id, 'subject_type' => 'management_review', 'color' => 'success',
        ]);

        return $review->fresh();
    }

    /** @param  array<string, mixed>  $data */
    public static function decide(ManagementReview $review, array $data, ?User $actor = null): ManagementDecision
    {
        if ($review->status === ManagementReview::CLOSED) {
            throw new RuntimeException(__('المراجعة مقفلة — لا قرارَ بعدها.'));
        }
        $decision = mb_substr(trim((string) ($data['decision'] ?? '')), 0, 255);
        if ($decision === '') {
            throw new RuntimeException(__('اكتب القرار.'));
        }

        return ManagementDecision::create([
            'business_id' => $review->business_id, 'management_review_id' => $review->id, 'decision' => $decision,
            'owner_id' => filled($data['owner_id'] ?? null) ? \App\Support\Operations\EventOperations::userIdOf((int) $review->business_id, $data['owner_id']) : null,
            'due_at' => filled($data['due_at'] ?? null) ? Carbon::parse((string) $data['due_at'])->toDateString() : null,
            'status' => ManagementDecision::OPEN, 'notes' => mb_substr(trim((string) ($data['notes'] ?? '')), 0, 2000) ?: null, 'created_by' => $actor?->id,
        ]);
    }

    public static function setDecisionStatus(ManagementDecision $decision, string $status, ?User $actor = null): ManagementDecision
    {
        if (! in_array($status, ManagementDecision::STATUSES, true)) {
            throw new RuntimeException(__('حالُ القرار غير معروفة.'));
        }
        $decision->update(['status' => $status, 'completed_at' => $status === ManagementDecision::DONE ? ($decision->completed_at ?? now()) : null]);

        return $decision->fresh();
    }

    /* ─────────────────────────── اللقطة ─────────────────────────── */

    /** الأرقامُ كما هي الآن للمدّة — تُحفظ ولا تُعاد قراءتُها من الحاضر */
    public static function snapshot(int $bid, Carbon $start, Carbon $end, ?User $viewer = null): array
    {
        $period = Period::between($bid, $start->copy()->startOfDay(), $end->copy()->startOfDay()->addDay());
        $financial = FinancialPerformance::snapshot($bid, $period);
        $projects = ProjectProfitability::list($bid, $period, 'margin_asc', 50);

        return [
            'captured_at' => now()->format('Y-m-d H:i'),
            'period' => $period->toArray(),
            'financial' => [
                'revenue' => $financial['revenue']['net'], 'collections' => $financial['collections']['total'], 'direct_costs' => $financial['direct_costs']['total'],
                'expenses' => $financial['expenses']['total'], 'payroll' => $financial['payroll'], 'gross_contribution' => $financial['gross_contribution'],
                'operating_result' => $financial['operating_result'], 'basis' => $financial['basis'],
            ],
            'cash' => FinancialPerformance::cashPosition($bid),
            'receivables' => FinancialPerformance::receivables($bid),
            'funnel' => RevenueIntelligence::funnel($bid, $period),
            'projects' => ['totals' => $projects['totals'], 'rows' => array_map(fn ($r) => ['code' => $r['project']['code'], 'name' => $r['project']['name'], 'revenue' => $r['revenue'],
                'direct_costs' => $r['direct_costs']['total'], 'margin_percent' => $r['margin_percent'], 'completeness' => $r['completeness']['status']], array_slice($projects['rows'], 0, 20))],
            'operations' => (fn ($o) => ['events' => $o['events'], 'readiness' => $o['readiness'], 'incidents' => ['total' => $o['incidents']['total'], 'severe' => $o['incidents']['severe']]])(OperationalIntelligence::summary($bid, $period)),
            'alerts' => array_map(fn ($a) => ['kind' => $a['kind'], 'severity' => $a['severity'], 'title' => $a['title'], 'detail' => $a['detail']], ManagementAlerts::for($bid, $period)),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private static function bounds(array $data): array
    {
        try {
            $start = Carbon::parse((string) ($data['period_start'] ?? ''))->startOfDay();
            $end = Carbon::parse((string) ($data['period_end'] ?? ''))->startOfDay();
        } catch (\Throwable) {
            throw new RuntimeException(__('حدّد بداية المدّة ونهايتها.'));
        }
        if ($end->lt($start)) {
            throw new RuntimeException(__('نهاية المدّة قبل بدايتها.'));
        }
        if ($start->diffInDays($end) > 400) {
            throw new RuntimeException(__('المراجعة لمدّةٍ لا تتجاوز سنة.'));
        }

        return [$start, $end];
    }
}
