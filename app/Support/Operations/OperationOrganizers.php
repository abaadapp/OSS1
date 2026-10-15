<?php

namespace App\Support\Operations;

use App\Models\Business;
use App\Models\EventOperation;
use App\Models\OperationOrganizer;
use App\Models\Organizer;
use App\Models\OrganizerCategory;
use App\Models\User;
use App\Support\Activity;
use App\Support\Organizers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * المنظّمون على الفعاليّة — ومسؤولُهم.
 *
 * ═══ على الفعاليّة لا على المشروع ═══
 *
 * المنظّمُ يقف في مكانٍ ووقتٍ بعينهما، والفعاليّةُ هي التي لها مكانٌ ووقت.
 * ولكلّ تعيينٍ تصنيفُه هنا (قد يكون استقبالًا في فعاليّةٍ وتسجيلًا في أخرى)
 * ووقتُه — نافذةُ الفعاليّة ما لم يُكتب غيرُها — وحالُه.
 *
 * ═══ والتعارضُ بسياسة الفريق نفسِها ═══
 *
 * لا نظامَ تعارضٍ ثانٍ: ما يفعله `Crew` بالموظّف يُفعل هنا بالمنظّم. التداخلُ
 * الزمنيُّ مع فعاليّةٍ قائمةٍ أخرى **يُردّ** عند التعيين تحت قفل صفّ الشركة
 * (فلا يمرّ تعيينان معًا من الفحص)، والفجوةُ الضيّقة بين فعاليّتين **تحذير**
 * يظهر مع تعارضات الفعاليّة (`ResourceConflicts::all`). وما تسرّب رغم ذلك
 * يُقال مانعًا.
 *
 * ═══ ومسؤولُ المنظّمين موظّف ═══
 *
 * `organizers_lead_user_id` موظّفٌ من الشركة يقود المنظّمين في الميدان —
 * غيرُ قائد العمليّات وقد يكونه. ولا يُفرض في الجاهزيّة: الفعاليّةُ بلا
 * منظّمين لا تحتاج مسؤولهم.
 */
final class OperationOrganizers
{
    public const STATUS_LABELS = [
        OperationOrganizer::ASSIGNED => 'معيَّن',
        OperationOrganizer::CONFIRMED => 'أكّد',
        OperationOrganizer::ATTENDED => 'حضر',
        OperationOrganizer::ABSENT => 'غاب',
        OperationOrganizer::CANCELLED => 'أُلغي',
    ];

    /** @param  array<string, mixed>  $data */
    public static function assign(EventOperation $operation, array $data, ?User $actor = null): OperationOrganizer
    {
        if (! $operation->isActive()) {
            throw new RuntimeException(__('لا يُعيَّن منظّمون لفعاليّةٍ خُتمت.'));
        }

        $organizer = Organizer::where('business_id', $operation->business_id)->find((int) ($data['organizer_id'] ?? 0))
            ?? throw new RuntimeException(__('هذا المنظّم ليس من سجلّ شركتك.'));

        if ($organizer->status !== Organizer::AVAILABLE) {
            throw new RuntimeException(__('المنظّم :name «:status» — لا يُعيَّن على فعاليّة.', [
                'name' => $organizer->name, 'status' => __(Organizers::STATUS_LABELS[$organizer->status] ?? $organizer->status),
            ]));
        }

        $category = self::categoryOf((int) $operation->business_id, $data['organizer_category_id'] ?? null);
        [$starts, $ends] = self::window($operation, $data);

        return DB::transaction(function () use ($operation, $organizer, $category, $starts, $ends, $data, $actor) {
            /* قفلُ صفّ الشركة يُسلسل التعيينات — كما في `Crew::assign` */
            Business::whereKey($operation->business_id)->lockForUpdate()->first();

            self::refuseOverlap($organizer, $starts, $ends, $operation->id);

            $existing = OperationOrganizer::where('event_operation_id', $operation->id)->where('organizer_id', $organizer->id)->first();
            if ($existing !== null && $existing->isExpected()) {
                throw new RuntimeException(__('المنظّم معيَّن في هذه الفعاليّة أصلًا.'));
            }

            $fields = [
                'organizer_category_id' => $category?->id,
                'starts_at' => $starts, 'ends_at' => $ends,
                'status' => OperationOrganizer::ASSIGNED,
                'notes' => EventOperations::text($data['notes'] ?? null, 2000),
                'updated_by' => $actor?->id,
            ];

            /* ومن أُلغي تعيينُه أو غاب ثمّ أُعيد — يعود صفُّه لا صفٌّ ثانٍ (قيدُ الفعاليّة والمنظّم) */
            $assignment = $existing !== null
                ? tap($existing)->update($fields)
                : OperationOrganizer::create($fields + [
                    'business_id' => $operation->business_id, 'event_operation_id' => $operation->id,
                    'organizer_id' => $organizer->id, 'created_by' => $actor?->id,
                ]);

            Activity::log('updated', 'عيّن المنظّم '.$organizer->name.($category ? ' («'.$category->name.'»)' : '').' في الفعاليّة '.$operation->code, [
                'subject_id' => $operation->id, 'subject_type' => 'event_operation', 'icon' => 'user-plus',
            ]);

            return $assignment->fresh(['organizer', 'category']);
        });
    }

    /** @param  array<string, mixed>  $data */
    public static function update(OperationOrganizer $assignment, array $data, ?User $actor = null): OperationOrganizer
    {
        $operation = $assignment->operation()->firstOrFail();

        $status = $data['status'] ?? $assignment->status;
        if (! in_array($status, OperationOrganizer::STATUSES, true)) {
            throw new RuntimeException(__('حالةٌ غير معروفة.'));
        }

        $category = array_key_exists('organizer_category_id', $data)
            ? self::categoryOf((int) $operation->business_id, $data['organizer_category_id'])
            : $assignment->category;
        [$starts, $ends] = array_key_exists('starts_at', $data) || array_key_exists('ends_at', $data)
            ? self::window($operation, $data + ['starts_at' => $assignment->starts_at, 'ends_at' => $assignment->ends_at])
            : [$assignment->starts_at, $assignment->ends_at];

        return DB::transaction(function () use ($assignment, $operation, $status, $category, $starts, $ends, $data, $actor) {
            Business::whereKey($operation->business_id)->lockForUpdate()->first();
            $locked = OperationOrganizer::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $organizer = $locked->organizer()->firstOrFail();

            /* من يعود إلى ما يشغل وقتَه، أو يتغيّر وقتُه، يُسأل عن التداخل من جديد — تحت القفل نفسِه */
            if (in_array($status, OperationOrganizer::EXPECTED, true)
                && (! $locked->isExpected() || ! self::same($locked->starts_at, $starts) || ! self::same($locked->ends_at, $ends))) {
                self::refuseOverlap($organizer, $starts, $ends, $operation->id);
            }

            $before = $locked->status;
            $locked->update([
                'organizer_category_id' => $category?->id,
                'starts_at' => $starts, 'ends_at' => $ends, 'status' => $status,
                'notes' => array_key_exists('notes', $data) ? EventOperations::text($data['notes'], 2000) : $locked->notes,
                'updated_by' => $actor?->id,
            ]);

            Activity::log($before !== $status ? 'status' : 'updated', ($before !== $status
                ? 'غيّر حال المنظّم '.$organizer->name.' في الفعاليّة '.$operation->code.' إلى «'.self::STATUS_LABELS[$status].'»'
                : 'عدّل تعيين المنظّم '.$organizer->name.' في الفعاليّة '.$operation->code), [
                    'subject_id' => $operation->id, 'subject_type' => 'event_operation',
                ]);

            return $locked->fresh(['organizer', 'category']);
        });
    }

    /** تعيينُ مسؤول المنظّمين — موظّفٌ من الشركة، أو لا أحد */
    public static function setLead(EventOperation $operation, mixed $userId, ?User $actor = null): EventOperation
    {
        $id = EventOperations::userIdOf((int) $operation->business_id, $userId);

        if ((int) $operation->organizers_lead_user_id === (int) $id) {
            return $operation;
        }

        $operation->update(['organizers_lead_user_id' => $id]);
        $name = $id === null ? null : User::find($id)?->name;

        Activity::log('updated', $id === null
            ? 'أزال مسؤول المنظّمين من الفعاليّة '.$operation->code
            : 'عيّن '.$name.' مسؤولًا عن المنظّمين في الفعاليّة '.$operation->code, [
                'subject_id' => $operation->id, 'subject_type' => 'event_operation', 'icon' => 'shield-check',
            ]);

        return $operation->fresh();
    }

    /** الفعاليّةُ أُلغيت — فتعييناتُ من لم يحضر تُلغى معها، كما يُلغى الفريق */
    public static function cancelWith(EventOperation $operation): void
    {
        OperationOrganizer::where('event_operation_id', $operation->id)
            ->whereIn('status', [OperationOrganizer::ASSIGNED, OperationOrganizer::CONFIRMED])
            ->update(['status' => OperationOrganizer::CANCELLED]);
    }

    /* ─────────────────────────── التعارض ─────────────────────────── */

    /** تعييناتُ المنظّم المتوقَّعة المتداخلة مع النافذة — في فعاليّاتٍ أخرى قائمة */
    public static function overlappingFor(int $organizerId, ?Carbon $starts, ?Carbon $ends, ?int $ignoreOperationId = null)
    {
        return OperationOrganizer::where('organizer_id', $organizerId)
            ->whereIn('status', OperationOrganizer::EXPECTED)
            ->when($ignoreOperationId, fn ($q, $id) => $q->where('event_operation_id', '!=', $id))
            ->whereHas('operation', fn ($q) => $q->whereIn('status', EventOperation::ACTIVE))
            ->when($starts !== null && $ends !== null, fn ($q) => $q->where('starts_at', '<', $ends)->where('ends_at', '>', $starts))
            ->with('operation:id,code,name')
            ->orderBy('starts_at');
    }

    /**
     * تعارضاتُ منظّمي الفعاليّة — بالأنواع والدرجات التي يقولها `Crew::conflicts`.
     *
     * @return list<array{severity: string, kind: string, message: string, assignment_id: ?int}>
     */
    public static function conflicts(EventOperation $operation): array
    {
        $assignments = OperationOrganizer::where('event_operation_id', $operation->id)
            ->whereIn('status', OperationOrganizer::EXPECTED)->with('organizer:id,name')->get();

        if ($assignments->isEmpty()) {
            return [];
        }

        $others = OperationOrganizer::whereIn('organizer_id', $assignments->pluck('organizer_id')->unique()->all())
            ->whereIn('status', OperationOrganizer::EXPECTED)
            ->where('event_operation_id', '!=', $operation->id)
            ->whereHas('operation', fn ($q) => $q->whereIn('status', EventOperation::ACTIVE))
            ->with('operation:id,code')->get()->groupBy('organizer_id');

        $out = [];
        foreach ($assignments as $a) {
            $name = (string) $a->organizer?->name;
            foreach ($others->get($a->organizer_id, collect()) as $o) {
                if ($a->starts_at === null || $a->ends_at === null || $o->starts_at === null || $o->ends_at === null) {
                    continue;
                }
                if ($o->starts_at->lt($a->ends_at) && $o->ends_at->gt($a->starts_at)) {
                    $out[] = ['severity' => ResourceConflicts::CRITICAL, 'kind' => 'organizer_double_booked', 'assignment_id' => $a->id,
                        'message' => __('المنظّم :name معيَّن في الوقت نفسه في الفعاليّة :op.', ['name' => $name, 'op' => (string) $o->operation?->code])];

                    continue;
                }
                $gap = $o->starts_at->gte($a->ends_at)
                    ? (int) $a->ends_at->diffInMinutes($o->starts_at)
                    : (int) $o->ends_at->diffInMinutes($a->starts_at);
                if ($gap < EventOperations::TIGHT_GAP_MINUTES) {
                    $out[] = ['severity' => ResourceConflicts::WARNING, 'kind' => 'organizer_tight_gap', 'assignment_id' => $a->id,
                        'message' => __('المنظّم :name بينه وبين الفعاليّة :op :n دقيقةً فقط.', ['name' => $name, 'op' => (string) $o->operation?->code, 'n' => $gap])];
                }
            }
        }

        return $out;
    }

    /* ─────────────────────────── أدوات ─────────────────────────── */

    private static function refuseOverlap(Organizer $organizer, ?Carbon $starts, ?Carbon $ends, int $operationId): void
    {
        $clash = self::overlappingFor($organizer->id, $starts, $ends, $operationId)->first();

        if ($clash !== null) {
            throw new RuntimeException(__('المنظّم :name معيَّن في الفعاليّة :op من :from إلى :until — لا يُعيَّن في وقتين معًا.', [
                'name' => $organizer->name, 'op' => (string) $clash->operation?->code,
                'from' => $clash->starts_at?->format('Y-m-d H:i') ?? '—', 'until' => $clash->ends_at?->format('Y-m-d H:i') ?? '—',
            ]));
        }
    }

    private static function categoryOf(int $businessId, mixed $id): ?OrganizerCategory
    {
        if ($id === null || $id === '') {
            return null;
        }

        return OrganizerCategory::where('business_id', $businessId)->find((int) $id)
            ?? throw new RuntimeException(__('هذا التصنيف ليس من تصنيفات شركتك.'));
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private static function window(EventOperation $operation, array $data): array
    {
        $starts = EventOperations::datetime($data['starts_at'] ?? null) ?? $operation->windowStart();
        $ends = EventOperations::datetime($data['ends_at'] ?? null) ?? $operation->windowEnd();

        if ($starts !== null && $ends !== null && $ends->lte($starts)) {
            throw new RuntimeException(__('نهاية التعيين قبل بدايته.'));
        }

        return [$starts, $ends];
    }

    private static function same(?Carbon $a, ?Carbon $b): bool
    {
        return $a?->toDateTimeString() === $b?->toDateTimeString();
    }
}
