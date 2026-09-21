<?php

namespace App\Support\Intelligence;

use App\Models\Asset;
use App\Models\AssetReservation;
use App\Models\EventOperation;
use App\Models\OperationEquipmentRequirement;
use App\Models\OperationIncident;
use App\Support\Operations\Assets;
use App\Support\Operations\ResourceConflicts;
use Illuminate\Support\Carbon;

/**
 * التشغيلُ بالأرقام — من بيانات المرحلة 10. المرحلة 11.
 *
 * فعاليّاتٌ ونسبةُ جاهزيّةٍ عند البثّ (بلا تجاوز) وحوادثُ لكلّ فعاليّة وحرجةٌ
 * وتعارضاتٌ وتأخّرُ إرجاعٍ وتأخّرُ بدء. ولا ترتيبَ للموظّفين بالحوادث: الحادثُ
 * حدثٌ على الفعاليّة لا وصمةٌ على شخص.
 *
 * والمعدّاتُ: أيّامُ حجزٍ وأيّامُ ميدانٍ وعددُ فعاليّاتٍ ونسبةُ استخدام — وإشاراتٌ
 * (نقصُ صنفٍ، تأجيرٌ متكرّر) تُعرض وقائعَ، والقرارُ بالشراء للإدارة.
 */
final class OperationalIntelligence
{
    /** @return array<string, mixed> */
    public static function summary(int $bid, Period $period): array
    {
        $ops = EventOperation::where('business_id', $bid)
            ->whereNotNull('event_starts_at')->where('event_starts_at', '>=', $period->startAt())->where('event_starts_at', '<', $period->endAt())
            ->with('requirements', 'reservations.asset', 'reservations.requirement', 'crew.user')
            ->orderBy('event_starts_at')->limit(300)->get();

        $ids = $ops->pluck('id');
        $incidents = OperationIncident::whereIn('event_operation_id', $ids)->toBase()
            ->selectRaw('event_operation_id, COUNT(*) AS c')
            ->selectRaw("SUM(CASE WHEN severity IN ('high','critical') THEN 1 ELSE 0 END) AS severe")
            ->selectRaw("SUM(CASE WHEN status IN ('open','investigating') THEN 1 ELSE 0 END) AS open")
            ->groupBy('event_operation_id')->get()->keyBy('event_operation_id');

        $byCategory = OperationIncident::whereIn('event_operation_id', $ids)->toBase()
            ->selectRaw('category, COUNT(*) AS c')->groupBy('category')->orderByDesc('c')->get()
            ->map(fn ($r) => ['category' => (string) $r->category, 'count' => (int) $r->c])->values()->all();

        $lateReturns = AssetReservation::whereIn('event_operation_id', $ids)->where('status', AssetReservation::RETURNED)
            ->whereColumn('returned_at', '>', 'reserved_until')->get(['reserved_until', 'returned_at']);
        $stillOut = AssetReservation::whereIn('event_operation_id', $ids)->where('status', AssetReservation::CHECKED_OUT)->count();

        $rows = [];
        $overrides = 0;
        $conflictsCritical = 0;
        $conflictsWarning = 0;
        $setupDelays = [];

        foreach ($ops as $op) {
            $inc = $incidents[$op->id] ?? null;
            /* التعارضاتُ تُقاس للفعاليّات القائمة وحدَها — المختومةُ لا تتعارض مع شيء، ولا تُسأل عنها القاعدة (المرحلة 12 §57) */
            $conf = in_array($op->status, [EventOperation::COMPLETED, EventOperation::CANCELLED], true) ? [] : ResourceConflicts::all($op);
            $critical = count(array_filter($conf, fn ($c) => $c['severity'] === ResourceConflicts::CRITICAL));
            $conflictsCritical += $critical;
            $conflictsWarning += count($conf) - $critical;
            $overrode = ! empty($op->readiness_override);
            $overrides += $overrode ? 1 : 0;

            $delay = null;
            if ($op->went_live_at !== null && $op->event_starts_at !== null) {
                $delay = (int) $op->event_starts_at->diffInMinutes($op->went_live_at, false);
                $setupDelays[] = $delay;
            }

            $rows[] = [
                'id' => $op->id, 'code' => $op->code, 'name' => $op->name, 'status' => $op->status,
                'starts_at' => $op->event_starts_at?->format('Y-m-d'),
                'incidents' => (int) ($inc->c ?? 0), 'severe_incidents' => (int) ($inc->severe ?? 0), 'open_incidents' => (int) ($inc->open ?? 0),
                'conflicts_critical' => $critical, 'conflicts_warning' => count($conf) - $critical,
                'readiness_overridden' => $overrode,
                'went_live_delay_minutes' => $delay,
                'url' => route('admin.operations.show', $op->id),
            ];
        }

        $count = $ops->count();
        $completed = $ops->where('status', EventOperation::COMPLETED)->count();
        $live = $ops->filter(fn ($o) => $o->went_live_at !== null)->count();
        $totalIncidents = (int) $incidents->sum('c');
        $severe = (int) $incidents->sum('severe');

        return [
            'period' => $period->toArray(),
            'events' => ['total' => $count, 'completed' => $completed, 'cancelled' => $ops->where('status', EventOperation::CANCELLED)->count(), 'went_live' => $live],
            'readiness' => [
                'went_live' => $live,
                'overridden' => $overrides,
                'clean_percent' => $live > 0 ? round(($live - $overrides) / $live * 100, 1) : null,
                'basis' => __('نسبةُ ما بلغ البثَّ بلا تجاوزٍ للجاهزيّة'),
            ],
            'incidents' => [
                'total' => $totalIncidents, 'severe' => $severe, 'open' => (int) $incidents->sum('open'),
                'per_event' => $count > 0 ? round($totalIncidents / $count, 2) : null,
                'severe_per_event' => $count > 0 ? round($severe / $count, 2) : null,
                'by_category' => $byCategory,
            ],
            'conflicts' => ['critical' => $conflictsCritical, 'warning' => $conflictsWarning],
            'returns' => [
                'late' => $lateReturns->count(),
                'still_out' => $stillOut,
                'average_delay_days' => $lateReturns->isEmpty() ? null : round((float) $lateReturns->avg(fn ($r) => Carbon::parse($r->reserved_until)->diffInHours(Carbon::parse($r->returned_at)) / 24), 1),
            ],
            'setup' => [
                'measured' => count($setupDelays),
                'average_delay_minutes' => $setupDelays === [] ? null : (int) round(array_sum($setupDelays) / count($setupDelays)),
                'late_starts' => count(array_filter($setupDelays, fn ($d) => $d > 15)),
                'basis' => __('الفرقُ بين موعد البداية ولحظة «على الهواء» — يُقاس لما بُثّ فعلًا'),
            ],
            'rows' => $rows,
            'monthly' => self::monthly($bid, $period),
        ];
    }

    /**
     * فعاليّاتٌ وحوادثُ شهرًا بشهر.
     *
     * @return list<array{month: string, events: int, incidents: int, severe: int}>
     */
    public static function monthly(int $bid, Period $period): array
    {
        $events = FinancialPerformance::monthlySeries(
            EventOperation::where('business_id', $bid)->whereNotNull('event_starts_at')
                ->where('event_starts_at', '>=', $period->startAt())->where('event_starts_at', '<', $period->endAt())->toBase(),
            'event_starts_at', '1', 'ts');
        $incidents = OperationIncident::where('business_id', $bid)
            ->where('occurred_at', '>=', $period->startAt())->where('occurred_at', '<', $period->endAt())->toBase();
        $inc = FinancialPerformance::monthlySeries(clone $incidents, 'occurred_at', '1', 'ts');
        $severe = FinancialPerformance::monthlySeries((clone $incidents)->whereIn('severity', ['high', 'critical']), 'occurred_at', '1', 'ts');

        $out = [];
        foreach ($period->months() as $m) {
            $k = $m->format('Y-m');
            $out[] = ['month' => $k, 'events' => (int) ($events[$k]['count'] ?? 0), 'incidents' => (int) ($inc[$k]['count'] ?? 0), 'severe' => (int) ($severe[$k]['count'] ?? 0)];
        }

        return $out;
    }

    /* ─────────────────────────── المعدّات ─────────────────────────── */

    /**
     * استخدامُ المعدّات في المدّة — قطعةً قطعة وصنفًا صنفًا.
     *
     * أيّامُ الحجز = مجموعُ نوافذ الحجوز (المحجوزة والمسلَّمة والمردودة) مقصوصةً
     * على المدّة؛ وأيّامُ الميدان = من التسليم إلى الردّ (أو الآن). والاستخدامُ
     * = أيّامُ الميدان ÷ أيّامِ المدّة. والتوقّفُ (صيانةٌ/تلف) يُقال كحالٍ الآن —
     * لا تاريخَ لحالات القطعة يُقاس منه.
     *
     * @return array<string, mixed>
     */
    public static function utilization(int $bid, Period $period, ?array $thresholds = null): array
    {
        $thresholds ??= Thresholds::for($bid);
        $start = $period->startAt();
        $end = $period->endAt();
        $days = $period->days();

        $assets = Asset::where('business_id', $bid)->orderBy('asset_code')->limit(500)->get();
        $reservations = AssetReservation::where('business_id', $bid)->whereIn('asset_id', $assets->pluck('id'))
            ->where('status', '!=', AssetReservation::CANCELLED)
            ->where('reserved_from', '<', $end)->where('reserved_until', '>', $start)
            ->get()->groupBy('asset_id');

        $rows = [];
        $byCategory = [];
        foreach ($assets as $a) {
            $rs = $reservations->get($a->id, collect());
            $reservedDays = 0.0;
            $deployedDays = 0.0;
            $events = [];
            foreach ($rs as $r) {
                $from = $r->reserved_from->copy()->max($start);
                $until = $r->reserved_until->copy()->min($end);
                if ($until->gt($from)) {
                    $reservedDays += $from->diffInHours($until) / 24;
                }
                if ($r->checked_out_at !== null) {
                    $dFrom = $r->checked_out_at->copy()->max($start);
                    $dUntil = ($r->returned_at ?? now())->copy()->min($end);
                    if ($dUntil->gt($dFrom)) {
                        $deployedDays += $dFrom->diffInHours($dUntil) / 24;
                    }
                }
                $events[$r->event_operation_id] = true;
            }
            $row = [
                'asset_id' => $a->id, 'code' => $a->asset_code, 'name' => $a->name, 'category' => $a->category, 'category_label' => Assets::categoryLabel($a->category),
                'status' => $a->status, 'reserved_days' => round($reservedDays, 1), 'deployed_days' => round($deployedDays, 1), 'events' => count($events),
                'utilization_percent' => round($deployedDays / $days * 100, 1),
                'out_of_service_now' => $a->isOutOfService(),
                'url' => route('admin.assets.show', $a->id),
            ];
            $rows[] = $row;
            $c = &$byCategory[$a->category];
            $c ??= ['category' => $a->category, 'label' => Assets::categoryLabel($a->category), 'assets' => 0, 'deployed_days' => 0.0, 'events' => 0, 'out_of_service' => 0, 'idle' => 0];
            $c['assets']++;
            $c['deployed_days'] = round($c['deployed_days'] + $deployedDays, 1);
            $c['events'] += count($events);
            $c['out_of_service'] += $a->isOutOfService() ? 1 : 0;
            $c['idle'] += count($events) === 0 ? 1 : 0;
            unset($c);
        }

        /* إشاراتُ الأصناف: نقصٌ عند الفعاليّات، وتأجيرٌ، وتعارضات */
        $reqs = OperationEquipmentRequirement::query()->toBase()
            ->join('event_operations', 'event_operations.id', '=', 'operation_equipment_requirements.event_operation_id')
            ->where('event_operations.business_id', $bid)
            ->where('event_operations.event_starts_at', '>=', $start)->where('event_operations.event_starts_at', '<', $end)
            ->where('operation_equipment_requirements.kind', OperationEquipmentRequirement::ASSET_CATEGORY)
            ->selectRaw('operation_equipment_requirements.asset_category AS cat')
            ->selectRaw('COUNT(DISTINCT CASE WHEN operation_equipment_requirements.assigned_quantity < operation_equipment_requirements.required_quantity THEN event_operations.id END) AS short_events')
            ->selectRaw("COUNT(DISTINCT CASE WHEN operation_equipment_requirements.source IN ('rented','third_party') THEN event_operations.id END) AS rented_events")
            ->selectRaw('COUNT(DISTINCT event_operations.id) AS events')
            ->groupBy('operation_equipment_requirements.asset_category')->get()->keyBy('cat');

        foreach ($byCategory as $cat => &$c) {
            $r = $reqs[$cat] ?? null;
            $c['required_in_events'] = (int) ($r->events ?? 0);
            $c['short_events'] = (int) ($r->short_events ?? 0);
            $c['rented_events'] = (int) ($r->rented_events ?? 0);
            $c['utilization_percent'] = $c['assets'] > 0 ? round($c['deployed_days'] / ($c['assets'] * $days) * 100, 1) : null;
            $c['signal'] = $c['short_events'] >= $thresholds['asset_shortage_events'] ? 'repeated_shortage'
                : ($c['rented_events'] > 0 && $c['idle'] > 0 ? 'rented_while_idle' : null);
        }
        unset($c);
        foreach ($reqs as $cat => $r) {
            if (! isset($byCategory[$cat])) {
                $byCategory[$cat] = ['category' => $cat, 'label' => Assets::categoryLabel($cat), 'assets' => 0, 'deployed_days' => 0.0, 'events' => 0, 'out_of_service' => 0, 'idle' => 0,
                    'required_in_events' => (int) $r->events, 'short_events' => (int) $r->short_events, 'rented_events' => (int) $r->rented_events, 'utilization_percent' => null,
                    'signal' => (int) $r->short_events >= $thresholds['asset_shortage_events'] ? 'repeated_shortage' : ((int) $r->rented_events > 0 ? 'rented_no_owned' : null)];
            }
        }

        usort($rows, fn ($a, $b) => $b['deployed_days'] <=> $a['deployed_days']);

        return [
            'period' => $period->toArray(),
            'assets' => count($rows),
            'rows' => $rows,
            'categories' => array_values($byCategory),
            'threshold_shortage_events' => $thresholds['asset_shortage_events'],
            'basis' => __('أيّامُ الميدان من التسليم إلى الردّ مقصوصةً على المدّة ÷ أيّامِ المدّة؛ والنقصُ متطلَّبٌ لم يُغطَّ عند فعاليّةٍ في المدّة؛ والتأجيرُ متطلَّبٌ مصدرُه مستأجَر أو طرفٌ ثالث'),
            'completeness' => (count($rows) === 0 ? Completeness::insufficient([__('لا معدّاتٍ مسجَّلة')]) : Completeness::partial([__('التوقّفُ للصيانة والتلف يُقال كحالٍ الآن — لا تاريخَ للحالات')]))->toArray(),
        ];
    }
}
