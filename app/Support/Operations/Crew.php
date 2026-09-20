<?php

namespace App\Support\Operations;

use App\Models\Business;
use App\Models\EventOperation;
use App\Models\InternalNotification;
use App\Models\OperationCrewAssignment;
use App\Models\User;
use App\Support\Activity;
use App\Support\Messaging\MessageIntent;
use App\Support\Messaging\Notifiers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * فريقُ الفعاليّة — موظّفون بحساباتهم ومتعاقدون بأسمائهم.
 *
 * ═══ قاعدتان صريحتان ═══
 *
 * **مانعٌ:** موظّفٌ واحد لا يُعيَّن في فعاليّتين تتداخل نافذتاهما — يُردّ
 * التعيينُ الثاني عند كتابته. **تحذيرٌ:** أقلُّ من ساعةٍ بين تعيينين له،
 * أو تعيينٌ يخرج عن نافذة الفعاليّة — يُقال ولا يمنع. والمتعاقدُ بلا حساب
 * لا يُفحص تداخلُه: لا هويّةَ تُطابَق عليها.
 */
final class Crew
{
    public const ROLE_LABELS = [
        'operations_lead' => 'قائد العمليات', 'registration_lead' => 'مسؤول التسجيل', 'registration_staff' => 'موظّف تسجيل',
        'technical_support' => 'دعم فنّي', 'printer_operator' => 'مشغّل طابعات', 'scanner_operator' => 'مشغّل ماسحات',
        'runner' => 'منسّق ميداني', 'supervisor' => 'مشرف', 'photographer' => 'مصوّر', 'external_contractor' => 'متعاقد خارجي', 'other' => 'أخرى',
    ];

    public const STATUS_LABELS = [
        OperationCrewAssignment::PLANNED => 'مخطَّط', OperationCrewAssignment::CONFIRMED => 'مؤكَّد',
        OperationCrewAssignment::CHECKED_IN => 'حضر', OperationCrewAssignment::CHECKED_OUT => 'انصرف',
        OperationCrewAssignment::NO_SHOW => 'لم يحضر', OperationCrewAssignment::CANCELLED => 'ملغى',
    ];

    /* ─────────────────────────── التعيين ─────────────────────────── */

    /**
     * @param  array<string, mixed>  $data
     */
    public static function assign(EventOperation $operation, array $data, ?User $actor = null): OperationCrewAssignment
    {
        if (! $operation->isActive()) {
            throw new RuntimeException(__('لا يُعيَّن فريقٌ لفعاليّةٍ خُتمت.'));
        }

        $role = (string) ($data['role'] ?? 'other');
        if (! in_array($role, OperationCrewAssignment::ROLES, true)) {
            throw new RuntimeException(__('الدور غير معروف.'));
        }

        $userId = EventOperations::userIdOf((int) $operation->business_id, $data['user_id'] ?? null);
        $externalName = EventOperations::text($data['external_name'] ?? null, 120);
        if ($userId === null && $externalName === null) {
            throw new RuntimeException(__('اختر موظّفًا أو اكتب اسم المتعاقد.'));
        }

        $starts = EventOperations::datetime($data['starts_at'] ?? null) ?? $operation->windowStart();
        $ends = EventOperations::datetime($data['ends_at'] ?? null) ?? $operation->windowEnd();
        if ($starts !== null && $ends !== null && $ends->lte($starts)) {
            throw new RuntimeException(__('نهاية التعيين قبل بدايته.'));
        }

        return DB::transaction(function () use ($operation, $userId, $externalName, $role, $starts, $ends, $data, $actor) {
            if ($userId !== null) {
                /* قفلُ صفّ الشركة يُسلسل التعيينات — فلا يمرّ اثنان معًا من فحص التداخل */
                Business::whereKey($operation->business_id)->lockForUpdate()->first();

                $clash = self::overlappingFor($userId, $starts, $ends, $operation->id)->first();
                if ($clash !== null) {
                    throw new RuntimeException(__('الموظّف معيَّن في الفعاليّة :op من :from إلى :until — لا يُعيَّن في وقتين معًا.', [
                        'op' => (string) $clash->operation?->code,
                        'from' => $clash->starts_at?->format('Y-m-d H:i') ?? '—', 'until' => $clash->ends_at?->format('Y-m-d H:i') ?? '—',
                    ]));
                }
                if (OperationCrewAssignment::where('event_operation_id', $operation->id)->where('user_id', $userId)
                    ->whereIn('status', OperationCrewAssignment::EXPECTED)->exists()) {
                    throw new RuntimeException(__('الموظّف معيَّن في هذه الفعاليّة أصلًا.'));
                }
            }

            $assignment = OperationCrewAssignment::create([
                'business_id' => $operation->business_id,
                'event_operation_id' => $operation->id,
                'user_id' => $userId,
                'external_name' => $userId === null ? $externalName : null,
                'external_phone' => $userId === null ? EventOperations::text($data['external_phone'] ?? null, 40) : null,
                'role' => $role,
                'starts_at' => $starts,
                'ends_at' => $ends,
                'status' => OperationCrewAssignment::PLANNED,
                'briefing_status' => in_array($data['briefing_status'] ?? null, OperationCrewAssignment::BRIEFING_STATUSES, true) ? $data['briefing_status'] : OperationCrewAssignment::BRIEFING_PENDING,
                'notes' => EventOperations::text($data['notes'] ?? null, 2000),
                'created_by' => $actor?->id,
            ]);

            if ($role === 'operations_lead' && $userId !== null && $operation->operations_lead_id === null) {
                $operation->update(['operations_lead_id' => $userId]);
            }

            Activity::log('updated', 'عيّن '.$assignment->displayName().' («'.__(self::ROLE_LABELS[$role]).'») في الفعاليّة '.$operation->code, [
                'subject_id' => $operation->id, 'subject_type' => 'event_operation', 'icon' => 'user-plus',
            ]);

            return $assignment;
        });
    }

    /** @param  array<string, mixed>  $data */
    public static function update(OperationCrewAssignment $assignment, array $data, ?User $actor = null): OperationCrewAssignment
    {
        $update = [];
        if (array_key_exists('role', $data) && in_array($data['role'], OperationCrewAssignment::ROLES, true)) {
            $update['role'] = $data['role'];
        }
        if (array_key_exists('briefing_status', $data) && in_array($data['briefing_status'], OperationCrewAssignment::BRIEFING_STATUSES, true)) {
            $update['briefing_status'] = $data['briefing_status'];
        }
        if (array_key_exists('status', $data)) {
            $status = (string) $data['status'];
            if (! in_array($status, [OperationCrewAssignment::PLANNED, OperationCrewAssignment::CONFIRMED, OperationCrewAssignment::NO_SHOW], true)) {
                throw new RuntimeException(__('الحضورُ والانصراف يُسجَّلان من شاشة الموقع.'));
            }
            $update['status'] = $status;
        }
        if (array_key_exists('notes', $data)) {
            $update['notes'] = EventOperations::text($data['notes'], 2000);
        }
        if (array_key_exists('external_phone', $data) && $assignment->isExternal()) {
            $update['external_phone'] = EventOperations::text($data['external_phone'], 40);
        }
        if (array_key_exists('starts_at', $data) || array_key_exists('ends_at', $data)) {
            $starts = array_key_exists('starts_at', $data) ? EventOperations::datetime($data['starts_at']) : $assignment->starts_at;
            $ends = array_key_exists('ends_at', $data) ? EventOperations::datetime($data['ends_at']) : $assignment->ends_at;
            if ($starts !== null && $ends !== null && $ends->lte($starts)) {
                throw new RuntimeException(__('نهاية التعيين قبل بدايته.'));
            }
            if ($assignment->user_id !== null) {
                $clash = self::overlappingFor((int) $assignment->user_id, $starts, $ends, (int) $assignment->event_operation_id)->first();
                if ($clash !== null) {
                    throw new RuntimeException(__('الموظّف معيَّن في الفعاليّة :op من :from إلى :until — لا يُعيَّن في وقتين معًا.', [
                        'op' => (string) $clash->operation?->code,
                        'from' => $clash->starts_at?->format('Y-m-d H:i') ?? '—', 'until' => $clash->ends_at?->format('Y-m-d H:i') ?? '—',
                    ]));
                }
            }
            $update['starts_at'] = $starts;
            $update['ends_at'] = $ends;
        }

        $assignment->update($update);

        return $assignment->fresh();
    }

    public static function remove(OperationCrewAssignment $assignment, ?User $actor = null): void
    {
        $operation = $assignment->operation;
        $name = $assignment->displayName();

        if ($assignment->status === OperationCrewAssignment::CHECKED_IN) {
            throw new RuntimeException(__('الموظّف حاضرٌ في الموقع — سجّل انصرافه أوّلًا.'));
        }

        $assignment->update(['status' => OperationCrewAssignment::CANCELLED]);

        Activity::log('updated', 'أزال '.$name.' من فريق الفعاليّة '.$operation->code, [
            'subject_id' => $operation->id, 'subject_type' => 'event_operation', 'icon' => 'user-minus', 'color' => 'warning',
        ]);
    }

    /* ─────────────────────────── الحضور ─────────────────────────── */

    public static function checkIn(OperationCrewAssignment $assignment, ?User $actor = null): OperationCrewAssignment
    {
        if ($assignment->status === OperationCrewAssignment::CHECKED_IN) {
            return $assignment;
        }
        if (! in_array($assignment->status, [OperationCrewAssignment::PLANNED, OperationCrewAssignment::CONFIRMED, OperationCrewAssignment::NO_SHOW], true)) {
            throw new RuntimeException(__('لا يُسجَّل حضورٌ لتعيينٍ ملغًى أو منصرف.'));
        }

        $assignment->update(['status' => OperationCrewAssignment::CHECKED_IN, 'checked_in_at' => now()]);

        Activity::log('updated', 'سجّل حضور '.$assignment->displayName().' في الفعاليّة '.(string) $assignment->operation?->code, [
            'subject_id' => $assignment->event_operation_id, 'subject_type' => 'event_operation', 'icon' => 'log-in', 'color' => 'success',
        ]);

        return $assignment->fresh();
    }

    public static function checkOut(OperationCrewAssignment $assignment, ?User $actor = null): OperationCrewAssignment
    {
        if ($assignment->status === OperationCrewAssignment::CHECKED_OUT) {
            return $assignment;
        }
        if ($assignment->status !== OperationCrewAssignment::CHECKED_IN) {
            throw new RuntimeException(__('لا انصرافَ بلا حضور.'));
        }

        $assignment->update(['status' => OperationCrewAssignment::CHECKED_OUT, 'checked_out_at' => now()]);

        return $assignment->fresh();
    }

    /* ─────────────────────────── التعارض ─────────────────────────── */

    /** تعييناتُ الموظّف المتوقَّعة التي تتداخل مع النافذة — في فعاليّاتٍ أخرى قائمة */
    public static function overlappingFor(int $userId, ?Carbon $starts, ?Carbon $ends, ?int $ignoreOperationId = null)
    {
        return OperationCrewAssignment::where('user_id', $userId)
            ->whereIn('status', OperationCrewAssignment::EXPECTED)
            ->when($ignoreOperationId, fn ($q, $id) => $q->where('event_operation_id', '!=', $id))
            ->whereHas('operation', fn ($q) => $q->whereIn('status', EventOperation::ACTIVE))
            ->when($starts !== null && $ends !== null, fn ($q) => $q->where('starts_at', '<', $ends)->where('ends_at', '>', $starts))
            ->with('operation:id,code,name')
            ->orderBy('starts_at');
    }

    /**
     * تعارضاتُ فريق الفعاليّة — مانعٌ وتحذير.
     *
     * @return list<array{severity: string, kind: string, message: string, assignment_id: ?int}>
     */
    public static function conflicts(EventOperation $operation): array
    {
        $out = [];
        $operation->loadMissing('crew.user');
        $crew = $operation->crew->filter(fn (OperationCrewAssignment $a) => $a->isExpected());

        $windowStart = $operation->windowStart();
        $windowEnd = $operation->windowEnd();

        /* تعييناتُ الموظّفين أنفسِهم في فعاليّاتٍ أخرى — استعلامٌ واحد */
        $userIds = $crew->pluck('user_id')->filter()->unique()->values()->all();
        $others = $userIds === [] ? collect() : OperationCrewAssignment::whereIn('user_id', $userIds)
            ->whereIn('status', OperationCrewAssignment::EXPECTED)
            ->where('event_operation_id', '!=', $operation->id)
            ->whereHas('operation', fn ($q) => $q->whereIn('status', EventOperation::ACTIVE))
            ->with('operation:id,code,name')->get()->groupBy('user_id');

        foreach ($crew as $a) {
            $name = $a->displayName();

            if ($a->user_id !== null) {
                foreach ($others->get($a->user_id, collect()) as $o) {
                    if ($a->starts_at === null || $a->ends_at === null || $o->starts_at === null || $o->ends_at === null) {
                        continue;
                    }
                    if ($o->starts_at->lt($a->ends_at) && $o->ends_at->gt($a->starts_at)) {
                        $out[] = ['severity' => 'critical', 'kind' => 'crew_double_booked', 'assignment_id' => $a->id,
                            'message' => __(':name معيَّن في الوقت نفسه في الفعاليّة :op.', ['name' => $name, 'op' => (string) $o->operation?->code])];

                        continue;
                    }
                    /* لا تداخل — فأحدُهما بعد الآخر: الفجوةُ بين نهاية الأوّل وبداية الثاني */
                    $gap = $o->starts_at->gte($a->ends_at)
                        ? (int) $a->ends_at->diffInMinutes($o->starts_at)
                        : (int) $o->ends_at->diffInMinutes($a->starts_at);
                    if ($gap < EventOperations::TIGHT_GAP_MINUTES) {
                        $out[] = ['severity' => 'warning', 'kind' => 'crew_tight_gap', 'assignment_id' => $a->id,
                            'message' => __(':name بينه وبين الفعاليّة :op :n دقيقةً فقط.', ['name' => $name, 'op' => (string) $o->operation?->code, 'n' => (int) $gap])];
                    }
                }
            }

            if ($windowStart !== null && $a->starts_at !== null && $a->starts_at->lt($windowStart->copy()->subHours(6))) {
                $out[] = ['severity' => 'warning', 'kind' => 'crew_outside_window', 'assignment_id' => $a->id,
                    'message' => __('تعيين :name يبدأ قبل نافذة الفعاليّة بكثير.', ['name' => $name])];
            }
            if ($windowEnd !== null && $a->ends_at !== null && $a->ends_at->gt($windowEnd->copy()->addHours(6))) {
                $out[] = ['severity' => 'warning', 'kind' => 'crew_outside_window', 'assignment_id' => $a->id,
                    'message' => __('تعيين :name ينتهي بعد نافذة الفعاليّة بكثير.', ['name' => $name])];
            }
        }

        return $out;
    }

    /* ─────────────────────────── التبليغ ─────────────────────────── */

    /**
     * تبليغُ الموظّف بتعيينه — جرسٌ داخليّ، وبريدٌ إن كانت القناةُ موصولة.
     *
     * ويمرّ البريدُ بالمرحلة 6 (`Notifiers::email`) فيُقيَّد في المراسلات موسومًا
     * داخليًّا عن المشروع. ولا واتساب ولا رسالةَ للعميل من هنا.
     */
    public static function notify(OperationCrewAssignment $assignment, ?User $actor = null): array
    {
        $user = $assignment->user;
        if ($user === null) {
            throw new RuntimeException(__('المتعاقد الخارجيّ بلا حساب — بلّغه بنفسك.'));
        }

        $operation = $assignment->operation;
        $project = $operation->project;
        $business = Business::find((int) $operation->business_id);
        $title = __('تعيينك في الفعاليّة :name — :role', ['name' => $operation->name, 'role' => __(self::ROLE_LABELS[$assignment->role] ?? $assignment->role)]);
        $body = implode("\n", array_filter([
            $operation->venue_name ? __('المكان: :venue', ['venue' => $operation->venue_name]) : null,
            $assignment->starts_at ? __('من :from إلى :until', ['from' => $assignment->starts_at->format('Y-m-d H:i'), 'until' => $assignment->ends_at?->format('Y-m-d H:i') ?? '—']) : null,
            $assignment->notes,
        ]));
        $url = route('admin.operations.onsite', $operation->id);

        InternalNotification::create([
            'business_id' => (int) $operation->business_id,
            'user_id' => $user->id,
            'kind' => 'crew_assignment',
            'title' => mb_substr($title, 0, 255),
            'body' => $body ?: null,
            'url' => $url,
            'subject_type' => $project?->getMorphClass(),
            'subject_id' => $project?->getKey(),
        ]);

        $emailed = false;
        if (filled($user->email) && Notifiers::email()->configured()) {
            $result = Notifiers::email()->send(new MessageIntent(
                to: (string) $user->email,
                subject: mb_substr($title, 0, 200),
                paragraphs: array_values(array_filter([$title, $body])),
                url: $url,
                action: __('افتح شاشة الموقع'),
                templateKey: 'operation.crew_assignment',
                senderName: $business?->name,
                footer: $business?->name,
                about: $project,
                businessId: (int) $operation->business_id,
                recipientName: $user->name,
            ));
            $emailed = $result->sent;
        }

        $assignment->update(['notified_at' => now()]);

        Activity::log('updated', 'بلّغ '.$user->name.' بتعيينه في الفعاليّة '.$operation->code.($emailed ? ' — وبالبريد' : ''), [
            'subject_id' => $operation->id, 'subject_type' => 'event_operation', 'icon' => 'bell',
        ]);

        return ['notified' => true, 'emailed' => $emailed];
    }

    /**
     * تنبيهٌ تشغيليٌّ إلى فريق الفعاليّة كلِّه — «تأخّر التجهيز ساعةً»، «الدخول من الباب الخلفيّ».
     *
     * جرسٌ لكلّ موظّفٍ معيَّن بحساب، وبريدٌ عبر المرحلة 6 إن كانت القناةُ
     * موصولة — مقيَّدٌ داخليًّا عن المشروع. والمتعاقدُ بلا حساب لا يبلغه.
     *
     * @return array{count: int, emailed: int}
     */
    public static function alert(EventOperation $operation, string $message, ?User $actor = null): array
    {
        $message = trim($message);
        if ($message === '') {
            throw new RuntimeException(__('اكتب نصّ التنبيه.'));
        }

        $operation->loadMissing('crew.user', 'project');
        $users = $operation->crew->filter(fn (OperationCrewAssignment $a) => $a->isExpected() && $a->user !== null)->pluck('user')->unique('id');
        if ($users->isEmpty()) {
            throw new RuntimeException(__('لا موظّفَ بحسابٍ في الفريق ليُبلَّغ.'));
        }

        $business = Business::find((int) $operation->business_id);
        $title = __('تنبيه — :name', ['name' => $operation->name]);
        $url = route('admin.operations.onsite', $operation->id);
        $emailed = 0;

        foreach ($users as $user) {
            InternalNotification::create([
                'business_id' => (int) $operation->business_id, 'user_id' => $user->id, 'kind' => 'operation_alert',
                'title' => mb_substr($title, 0, 255), 'body' => mb_substr($message, 0, 2000), 'url' => $url,
                'subject_type' => $operation->project?->getMorphClass(), 'subject_id' => $operation->project?->getKey(),
            ]);

            if (filled($user->email) && Notifiers::email()->configured()) {
                $result = Notifiers::email()->send(new MessageIntent(
                    to: (string) $user->email, subject: mb_substr($title, 0, 200), paragraphs: [$message], url: $url,
                    action: __('افتح شاشة الموقع'), templateKey: 'operation.alert', senderName: $business?->name, footer: $business?->name,
                    about: $operation->project, businessId: (int) $operation->business_id, recipientName: $user->name,
                ));
                $emailed += $result->sent ? 1 : 0;
            }
        }

        Activity::log('updated', 'نبّه فريق الفعاليّة '.$operation->code.' ('.$users->count().') — '.mb_substr($message, 0, 120), [
            'subject_id' => $operation->id, 'subject_type' => 'event_operation', 'icon' => 'megaphone', 'color' => 'warning',
        ]);

        return ['count' => $users->count(), 'emailed' => $emailed];
    }

    /** الفريقُ المتوقَّع لفعاليّةٍ — الحاضرُ والغائب */
    public static function attendance(EventOperation $operation): array
    {
        $crew = $operation->crew->filter(fn (OperationCrewAssignment $a) => $a->isExpected());

        return [
            'expected' => $crew->count(),
            'checked_in' => $crew->where('status', OperationCrewAssignment::CHECKED_IN)->count(),
            'checked_out' => $crew->where('status', OperationCrewAssignment::CHECKED_OUT)->count(),
            'missing' => $crew->whereIn('status', [OperationCrewAssignment::PLANNED, OperationCrewAssignment::CONFIRMED])->count(),
            'no_show' => $operation->crew->where('status', OperationCrewAssignment::NO_SHOW)->count(),
        ];
    }

    /** @return Collection<int, OperationCrewAssignment> */
    public static function forUser(User $user, Carbon $from, Carbon $until): Collection
    {
        return OperationCrewAssignment::where('user_id', $user->id)->whereIn('status', OperationCrewAssignment::EXPECTED)
            ->where('starts_at', '<', $until)->where('ends_at', '>', $from)
            ->with('operation:id,code,name,status,venue_name')->orderBy('starts_at')->get();
    }
}
