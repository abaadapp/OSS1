<?php

namespace App\Support\Operations;

use App\Models\AssetReservation;
use App\Models\EventOperation;
use App\Models\OperationChecklistItem;
use App\Models\OperationChecklistTemplate;
use App\Models\OperationCrewAssignment;
use App\Models\OperationEquipmentRequirement;
use App\Models\OperationIncident;

/**
 * جاهزيّةُ الفعاليّة — «هل تبدأ بأمان؟» جوابًا مركَّبًا لا نعم/لا.
 *
 * ═══ مرحلتان ═══
 *
 * `for()` تسأل عن الجاهزيّة **قبل** الفعاليّة (بابُ «جاهزة»): موعدٌ ومكانٌ
 * وقائدٌ وفريقٌ ومعدّاتٌ حرجة وقائمةُ ما قبل الفعاليّة. و`live()` تسأل عند
 * البثّ (بابُ «على الهواء»): المعدّاتُ الحرجة **سُلّمت** لا حُجزت، وقائمةُ
 * التجهيز أُنجزت، والقائدُ موجود.
 *
 * ═══ ومصدرٌ واحد لكلّ قاعدة ═══
 *
 * تعارضاتُ المعدّات من `ResourceConflicts`، وتعارضاتُ الفريق من `Crew`،
 * والتقدّمُ من `Checklists`. وهنا يُجمع ويُصنَّف: مانعٌ أو تحذير.
 */
final class OperationReadiness
{
    public const PRE = 'pre';

    public const LIVE = 'live';

    public static function for(EventOperation $operation): OperationReadinessVerdict
    {
        return self::evaluate($operation, self::PRE);
    }

    public static function live(EventOperation $operation): OperationReadinessVerdict
    {
        return self::evaluate($operation, self::LIVE);
    }

    private static function evaluate(EventOperation $operation, string $phase): OperationReadinessVerdict
    {
        $operation->loadMissing('project', 'requirements', 'reservations.asset', 'reservations.requirement', 'crew.user', 'checklists.items', 'scheduleItems');

        $checks = [];
        $blockers = [];
        $warnings = [];

        $add = function (string $key, string $label, bool $ok, string $severity, ?string $detail = null) use (&$checks, &$blockers, &$warnings) {
            $checks[] = ['key' => $key, 'label' => $label, 'ok' => $ok, 'severity' => $severity, 'detail' => $detail];
            if ($ok) {
                return;
            }
            $row = ['key' => $key, 'message' => $detail ?? $label];
            if ($severity === ResourceConflicts::CRITICAL) {
                $blockers[] = $row;
            } else {
                $warnings[] = $row;
            }
        };

        /* ─ المشروع ─ */
        $add('project_open', __('المشروع قائم'), (bool) $operation->project?->isOpen(), ResourceConflicts::CRITICAL,
            $operation->project?->isOpen() ? null : __('المشروع ليس قائمًا — أُقفل أو أُلغي.'));

        /* ─ الموعد والمكان ─ */
        $add('event_date', __('موعد الفعاليّة محدَّد'), $operation->event_starts_at !== null, ResourceConflicts::CRITICAL, __('موعد الفعاليّة غير محدَّد.'));
        $add('venue', __('المكان محدَّد'), filled($operation->venue_name), ResourceConflicts::CRITICAL, __('مكان الفعاليّة غير محدَّد.'));
        $add('setup_time', __('وقت التجهيز محدَّد'), $operation->setup_starts_at !== null, ResourceConflicts::WARNING, __('لم يُحدَّد وقت بدء التجهيز.'));
        $add('teardown_time', __('وقت الفكّ محدَّد'), $operation->teardown_starts_at !== null, ResourceConflicts::WARNING, __('لم يُحدَّد وقت الفكّ.'));

        /* ─ القائد والفريق ─ */
        $crew = $operation->crew->filter(fn (OperationCrewAssignment $a) => $a->isExpected());
        $add('lead', __('قائد العمليات معيَّن'), $operation->operations_lead_id !== null, ResourceConflicts::CRITICAL, __('لا قائد عمليات للفعاليّة.'));
        $add('crew', __('الفريق معيَّن'), $crew->isNotEmpty(), ResourceConflicts::CRITICAL, __('لا فريق معيَّن للفعاليّة.'));

        $unbriefed = $crew->where('briefing_status', OperationCrewAssignment::BRIEFING_PENDING)->count();
        $add('briefing', __('الفريق أُحيط'), $unbriefed === 0, ResourceConflicts::WARNING, $unbriefed > 0 ? __(':n من الفريق لم يُحاطوا بعد.', ['n' => $unbriefed]) : null);

        if ($phase === self::LIVE) {
            $leadIn = $operation->operations_lead_id !== null && $crew->contains(fn (OperationCrewAssignment $a) => $a->user_id === $operation->operations_lead_id && $a->status === OperationCrewAssignment::CHECKED_IN);
            $add('lead_on_site', __('قائد العمليات في الموقع'), $leadIn, ResourceConflicts::CRITICAL, __('قائد العمليات لم يسجّل حضوره في الموقع.'));
            $missing = $crew->whereIn('status', [OperationCrewAssignment::PLANNED, OperationCrewAssignment::CONFIRMED])->count();
            $add('crew_on_site', __('الفريق حاضر'), $missing === 0, ResourceConflicts::WARNING, $missing > 0 ? __(':n من الفريق لم يسجّلوا حضورهم.', ['n' => $missing]) : null);
        }

        foreach (Crew::conflicts($operation) as $c) {
            $add($c['kind'], __('تعارض فريق'), false, $c['severity'], $c['message']);
        }

        /* ─ المعدّات ─ */
        $requirements = $operation->requirements;
        $add('requirements', __('المتطلّبات مكتوبة'), $requirements->isNotEmpty(), ResourceConflicts::WARNING, __('لم تُكتب متطلّبات المعدّات.'));

        foreach (ResourceConflicts::equipment($operation) as $c) {
            $add($c['kind'], __('تعارض معدّات'), false, $c['severity'], $c['message']);
        }

        $hasBackup = $requirements->contains(fn (OperationEquipmentRequirement $r) => (bool) $r->backup);
        $add('backup', __('جهاز احتياطي مذكور'), $hasBackup || $requirements->isEmpty(), ResourceConflicts::WARNING, __('لا جهاز احتياطيّ في المتطلّبات.'));

        if ($phase === self::LIVE) {
            /* الحرجُ يجب أن يكون في الميدان لا في المكتب */
            $notOut = $operation->reservations->filter(fn (AssetReservation $r) => $r->status === AssetReservation::RESERVED
                && ($r->requirement === null || $r->requirement->critical));
            $add('equipment_deployed', __('المعدّات الحرجة سُلّمت'), $notOut->isEmpty(), ResourceConflicts::CRITICAL,
                $notOut->isEmpty() ? null : __(':n قطعةً محجوزة لم تُسلَّم للميدان بعد.', ['n' => $notOut->count()]));
        }

        /* ─ قوائمُ التحقّق ─ */
        foreach (Checklists::progress($operation) as $p) {
            $checklist = $operation->checklists->firstWhere('id', $p['id']);
            $waived = $p['waived'];
            $pendingOptional = $checklist === null ? 0 : $checklist->items->filter(fn (OperationChecklistItem $i) => ! $i->required && ! $i->isSettled())->count();

            $severity = match (true) {
                $p['phase'] === OperationChecklistTemplate::PRE_EVENT => $phase === self::PRE ? ResourceConflicts::CRITICAL : ResourceConflicts::WARNING,
                $p['phase'] === OperationChecklistTemplate::SETUP => $phase === self::LIVE ? ResourceConflicts::CRITICAL : ResourceConflicts::WARNING,
                $p['phase'] === OperationChecklistTemplate::CLOSING => ResourceConflicts::INFO,
                default => ResourceConflicts::WARNING,
            };
            if ($severity === ResourceConflicts::INFO) {
                continue; /* الإقفالُ يُسأل عنه عند الإتمام لا قبل البدء */
            }

            $add('checklist_'.$p['phase'].'_'.$p['id'], __('قائمة «:name» — الإلزاميّ مكتمل', ['name' => $p['name']]), $p['required_pending'] === 0, $severity,
                $p['required_pending'] > 0 ? __(':n بندًا إلزاميًّا في «:name» لم يُنجَز.', ['n' => $p['required_pending'], 'name' => $p['name']]) : null);
            if ($pendingOptional > 0) {
                $add('checklist_optional_'.$p['id'], __('قائمة «:name» — الاختياريّ', ['name' => $p['name']]), false, ResourceConflicts::WARNING, __(':n بندًا اختياريًّا في «:name» لم يُنجَز.', ['n' => $pendingOptional, 'name' => $p['name']]));
            }
            if ($waived > 0) {
                $add('checklist_waived_'.$p['id'], __('قائمة «:name» — إعفاءات', ['name' => $p['name']]), false, ResourceConflicts::WARNING, __(':n بندًا في «:name» أُعفي بسبب.', ['n' => $waived, 'name' => $p['name']]));
            }
        }

        /* ─ الجدول ─ */
        $add('schedule', __('الجدول التشغيليّ مكتوب'), $operation->scheduleItems->isNotEmpty(), ResourceConflicts::WARNING, __('لا بنود في الجدول التشغيليّ.'));

        /* ─ الحوادث ─ عند البثّ: حادثٌ حرجٌ مفتوح يوقف */
        if ($phase === self::LIVE) {
            $critical = $operation->incidents()->whereIn('status', OperationIncident::UNRESOLVED)->where('severity', OperationIncident::CRITICAL)->count();
            $add('incidents', __('لا حوادث حرجة مفتوحة'), $critical === 0, ResourceConflicts::CRITICAL, $critical > 0 ? __(':n حادثًا حرجًا مفتوحًا.', ['n' => $critical]) : null);
        }

        return new OperationReadinessVerdict($phase, $blockers === [], $blockers, $warnings, $checks);
    }
}
