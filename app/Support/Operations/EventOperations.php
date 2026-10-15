<?php

namespace App\Support\Operations;

use App\Models\AssetReservation;
use App\Models\Business;
use App\Models\EventOperation;
use App\Models\OperationChecklistItem;
use App\Models\OperationChecklistTemplate;
use App\Models\OperationCrewAssignment;
use App\Models\OperationIncident;
use App\Models\OperationScheduleItem;
use App\Models\Project;
use App\Models\User;
use App\Support\Activity;
use App\Support\Automation\Settings as AutomationSettings;
use App\Support\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * الفعاليّاتُ التشغيليّة — الإنشاءُ من المشروع، والدورةُ، والإتمام.
 *
 * ═══ الحالُ لا تُكتب من متحكّم ═══
 *
 * `transition()` وحدُها تنقل الحال، وتسأل الجاهزيّةَ قبل «جاهزة» و«على
 * الهواء». ومن أراد التجاوزَ فبإذنٍ باسمه وسببٍ يُكتب ويبقى — لا تجاوزَ
 * صامتًا.
 *
 * ═══ والإتمامُ ليس مرورَ الوقت ═══
 *
 * فعاليّةٌ انتهى وقتُها لا تصير «مكتملة»: الفكُّ تمّ، والمعدّاتُ عادت، والحوادثُ
 * الحرجة حُلّت أو أُقرّ بحملها، وقائمةُ الإقفال أُنجزت. وإتمامُها لا يُقفل
 * المشروعَ ماليًّا — ذاك بابُه `Projects::close`.
 */
final class EventOperations
{
    public const PREFIX = 'OP-';

    /** الأقلُّ بين تعيينين لموظّفٍ واحد قبل أن يُقال «ضيّق» — دقائق */
    public const TIGHT_GAP_MINUTES = 60;

    public const STATUS_LABELS = [
        EventOperation::PLANNING => 'تخطيط', EventOperation::PREPARING => 'تجهيز مسبق', EventOperation::READY => 'جاهزة',
        EventOperation::SETUP => 'تجهيز في الموقع', EventOperation::LIVE => 'على الهواء', EventOperation::TEARDOWN => 'فكّ',
        EventOperation::COMPLETED => 'مكتملة', EventOperation::CANCELLED => 'ملغاة',
    ];

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    /* ─────────────────────────── الإنشاء ─────────────────────────── */

    /**
     * @param  array<string, mixed>  $data
     */
    public static function create(Project $project, array $data, ?User $actor = null): EventOperation
    {
        if (! $project->isOpen()) {
            throw new RuntimeException(__('لا تُنشأ فعاليّةٌ إلّا على مشروعٍ قائم.'));
        }

        $settings = AutomationSettings::for((int) $project->business_id);

        $operation = DB::transaction(function () use ($project, $data, $actor, $settings) {
            $dates = self::dates($data, null);

            $operation = EventOperation::create([
                'business_id' => $project->business_id,
                'project_id' => $project->id,
                'code' => self::nextCode((int) $project->business_id),
                'name' => self::text($data['name'] ?? null, 255) ?: $project->name,
                'status' => EventOperation::PLANNING,
                'venue_name' => self::text($data['venue_name'] ?? $project->location, 160),
                'venue_address' => self::text($data['venue_address'] ?? null, 255),
                'venue_notes' => self::text($data['venue_notes'] ?? null, 5000),
                'timezone' => self::timezone($data['timezone'] ?? null, $settings->timezone),
                'operations_lead_id' => self::userIdOf((int) $project->business_id, $data['operations_lead_id'] ?? $project->owner_id),
                'notes' => self::text($data['notes'] ?? null, 20000),
                'created_by' => $actor?->id,
            ] + $dates);

            Checklists::attachDefaults($operation, $actor);

            return $operation;
        });

        Activity::log('created', 'أنشأ الفعاليّة '.$operation->code.' — '.$operation->name.' على المشروع '.$project->code, [
            'subject_id' => $operation->id, 'subject_type' => 'event_operation', 'icon' => 'calendar-check',
        ]);

        return $operation;
    }

    /** «OP-000012» — داخل قفل صفّ الشركة، فلا يقطع مستخدمان الرقمَ نفسَه */
    public static function nextCode(int $businessId): string
    {
        return DB::transaction(function () use ($businessId) {
            Business::whereKey($businessId)->lockForUpdate()->first();

            $highest = (int) EventOperation::where('business_id', $businessId)
                ->where('code', 'like', self::PREFIX.'%')
                ->get(['code'])
                ->map(fn ($r) => (int) substr((string) $r->code, strlen(self::PREFIX)))
                ->max();

            return self::PREFIX.str_pad((string) ($highest + 1), 6, '0', STR_PAD_LEFT);
        });
    }

    /* ─────────────────────────── التعديل ─────────────────────────── */

    /** @param  array<string, mixed>  $data */
    public static function update(EventOperation $operation, array $data, ?User $actor = null): EventOperation
    {
        if (! $operation->isActive()) {
            throw new RuntimeException(__('لا تُعدَّل فعاليّةٌ خُتمت.'));
        }

        $update = [];
        foreach (['name' => 255, 'venue_name' => 160, 'venue_address' => 255, 'venue_notes' => 5000, 'notes' => 20000] as $field => $max) {
            if (array_key_exists($field, $data)) {
                $update[$field] = self::text($data[$field], $max) ?? ($field === 'name' ? $operation->name : null);
            }
        }
        if (array_key_exists('timezone', $data)) {
            $update['timezone'] = self::timezone($data['timezone'], $operation->timezone);
        }
        if (array_key_exists('operations_lead_id', $data)) {
            $update['operations_lead_id'] = self::userIdOf((int) $operation->business_id, $data['operations_lead_id']);
        }

        $update += self::dates($data, $operation);

        $before = $operation->only(array_keys($update));
        $operation->update($update);
        $operation->refresh();

        $changed = array_keys(array_filter($before, fn ($v, $k) => (string) $v !== (string) $operation->{$k}, ARRAY_FILTER_USE_BOTH));
        if ($changed !== []) {
            Activity::log('updated', 'عدّل الفعاليّة '.$operation->code.' ('.implode('، ', $changed).')', [
                'subject_id' => $operation->id, 'subject_type' => 'event_operation',
            ]);
        }

        return $operation;
    }

    /* ─────────────────────────── الدورة ─────────────────────────── */

    /**
     * ما يُسمح بالانتقال إليه من كلّ حال.
     *
     * @return list<string>
     */
    public static function allowedFrom(string $status): array
    {
        return match ($status) {
            EventOperation::PLANNING => [EventOperation::PREPARING, EventOperation::CANCELLED],
            EventOperation::PREPARING => [EventOperation::READY, EventOperation::PLANNING, EventOperation::CANCELLED],
            EventOperation::READY => [EventOperation::SETUP, EventOperation::PREPARING, EventOperation::CANCELLED],
            EventOperation::SETUP => [EventOperation::LIVE, EventOperation::READY, EventOperation::CANCELLED],
            EventOperation::LIVE => [EventOperation::TEARDOWN],
            EventOperation::TEARDOWN => [EventOperation::COMPLETED, EventOperation::LIVE],
            default => [],
        };
    }

    /**
     * نقلُ الحال — والجاهزيّةُ تُسأل قبل «جاهزة» و«على الهواء».
     *
     * `$overrideReason` يُقبل لمن يملك `operations.override` وحدَه، ويُكتب في
     * الفعاليّة وفي السجلّ ولا يُمحى. ولا يُقبل فارغًا.
     */
    public static function transition(EventOperation $operation, string $to, ?User $actor = null, ?string $reason = null, ?string $overrideReason = null): EventOperation
    {
        if ($to === EventOperation::COMPLETED) {
            return self::complete($operation, $actor);
        }
        if ($to === EventOperation::CANCELLED) {
            return self::cancel($operation, (string) $reason, $actor);
        }

        return DB::transaction(function () use ($operation, $to, $actor, $reason, $overrideReason) {
            $locked = EventOperation::where('business_id', $operation->business_id)->lockForUpdate()->findOrFail($operation->id);

            if ($locked->status === $to) {
                return $locked; /* ضغطةٌ ثانية — لا شيء يتغيّر */
            }
            if (! in_array($to, self::allowedFrom($locked->status), true)) {
                throw new RuntimeException(__('لا تنتقل الفعاليّة من «:from» إلى «:to».', ['from' => $locked->status, 'to' => $to]));
            }

            $override = null;
            $gate = match ($to) {
                EventOperation::READY => OperationReadiness::for($locked),
                EventOperation::LIVE => OperationReadiness::live($locked),
                default => null,
            };

            if ($gate !== null && ! $gate->ready) {
                $overrideReason = trim((string) $overrideReason);
                if ($overrideReason === '') {
                    throw new RuntimeException(__('الفعاليّة ليست جاهزة: :blockers', ['blockers' => implode(' · ', $gate->blockerMessages())]));
                }
                if (! ($actor?->may(Permissions::OPERATIONS_OVERRIDE) ?? false)) {
                    throw new RuntimeException(__('تجاوزُ الجاهزيّة يحتاج صلاحيّة التجاوز.'));
                }
                $override = [
                    'to' => $to, 'reason' => mb_substr($overrideReason, 0, 255), 'by' => $actor?->name, 'by_id' => $actor?->id,
                    'at' => now()->toDateTimeString(), 'blockers' => $gate->blockerMessages(),
                ];
            }

            $update = ['status' => $to];
            if ($to === EventOperation::LIVE) {
                $update['went_live_at'] = $locked->went_live_at ?? now();
            }
            if ($override !== null) {
                $update['readiness_override'] = array_values(array_merge((array) ($locked->readiness_override ?? []), [$override]));
            }
            $locked->update($update);

            if ($override !== null) {
                Activity::log('status', 'تجاوز جاهزيّة الفعاليّة '.$locked->code.' إلى «'.$to.'» — '.$override['reason'].' · الموانع: '.implode(' · ', $override['blockers']), [
                    'subject_id' => $locked->id, 'subject_type' => 'event_operation', 'icon' => 'shield-alert', 'color' => 'danger',
                ]);
            }

            Activity::log('status', 'نقل الفعاليّة '.$locked->code.' إلى «'.$to.'»'.($reason ? ' — '.$reason : ''), [
                'subject_id' => $locked->id, 'subject_type' => 'event_operation',
                'color' => $to === EventOperation::LIVE ? 'success' : 'primary',
            ]);

            return $locked->fresh();
        });
    }

    /**
     * موانعُ الإتمام — تُقرأ قبل أن يُضغط الزرّ.
     *
     * @return list<string>
     */
    public static function completionBlockers(EventOperation $operation): array
    {
        $blockers = [];

        if ($operation->status !== EventOperation::TEARDOWN) {
            $blockers[] = __('لم تبلغ الفعاليّة مرحلة الفكّ بعد.');
        }

        $teardown = OperationScheduleItem::where('event_operation_id', $operation->id)
            ->whereIn('type', ['teardown', 'return'])
            ->whereNotIn('status', [OperationScheduleItem::COMPLETED, OperationScheduleItem::SKIPPED])->count();
        if ($teardown > 0) {
            $blockers[] = __(':n بندًا من الفكّ والإرجاع لم يكتمل.', ['n' => $teardown]);
        }

        $outstanding = AssetReservation::where('event_operation_id', $operation->id)
            ->where('status', AssetReservation::CHECKED_OUT)->with('asset')->get();
        foreach ($outstanding as $r) {
            $blockers[] = __('القطعة :asset لم تُردّ بعد.', ['asset' => (string) $r->asset?->label()]);
        }

        $reserved = AssetReservation::where('event_operation_id', $operation->id)->where('status', AssetReservation::RESERVED)->count();
        if ($reserved > 0) {
            $blockers[] = __(':n حجزًا لم يُسلَّم ولم يُلغَ — ألغِه أو سلّمه ثمّ ردّه.', ['n' => $reserved]);
        }

        $incidents = OperationIncident::where('event_operation_id', $operation->id)
            ->whereIn('status', OperationIncident::UNRESOLVED)
            ->whereIn('severity', [OperationIncident::HIGH, OperationIncident::CRITICAL])
            ->where('carried_forward', false)->get();
        foreach ($incidents as $i) {
            $blockers[] = __('الحادث «:title» (:severity) لم يُحلّ ولم يُقرّ بحمله.', ['title' => $i->title, 'severity' => __(Incidents::SEVERITY_LABELS[$i->severity] ?? $i->severity)]);
        }

        $closing = OperationChecklistItem::where('business_id', $operation->business_id)
            ->whereHas('checklist', fn ($q) => $q->where('event_operation_id', $operation->id)->where('phase', OperationChecklistTemplate::CLOSING))
            ->where('required', true)->whereNotIn('status', [OperationChecklistItem::DONE, OperationChecklistItem::WAIVED])->count();
        if ($closing > 0) {
            $blockers[] = __(':n بندًا إلزاميًّا في قائمة الإقفال لم يُنجَز.', ['n' => $closing]);
        }

        return $blockers;
    }

    /** الإتمامُ — آمنٌ عند التكرار، ولا يُقفل المشروع */
    public static function complete(EventOperation $operation, ?User $actor = null): EventOperation
    {
        return DB::transaction(function () use ($operation, $actor) {
            $locked = EventOperation::where('business_id', $operation->business_id)->lockForUpdate()->findOrFail($operation->id);

            if ($locked->isCompleted()) {
                return $locked;
            }

            $blockers = self::completionBlockers($locked);
            if ($blockers !== []) {
                throw new RuntimeException(__('لا تُتمّ الفعاليّة: :blockers', ['blockers' => implode(' · ', $blockers)]));
            }

            $locked->update(['status' => EventOperation::COMPLETED, 'completed_at' => now(), 'completed_by' => $actor?->id]);

            OperationCrewAssignment::where('event_operation_id', $locked->id)
                ->whereIn('status', [OperationCrewAssignment::CHECKED_IN])
                ->update(['status' => OperationCrewAssignment::CHECKED_OUT, 'checked_out_at' => now()]);

            Activity::log('status', 'أتمّ الفعاليّة '.$locked->code.' — '.$locked->name, [
                'subject_id' => $locked->id, 'subject_type' => 'event_operation', 'icon' => 'flag', 'color' => 'success',
            ]);

            return $locked->fresh();
        });
    }

    /**
     * الإلغاءُ — يُحرّر الحجوزَ التي لم تُسلَّم، ويُبقي ما خرج معلَّقًا حتى يُردّ.
     */
    public static function cancel(EventOperation $operation, string $reason, ?User $actor = null): EventOperation
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException(__('اكتب سبب الإلغاء.'));
        }

        return DB::transaction(function () use ($operation, $reason, $actor) {
            $locked = EventOperation::where('business_id', $operation->business_id)->lockForUpdate()->findOrFail($operation->id);

            if ($locked->status === EventOperation::CANCELLED) {
                return $locked;
            }
            if (in_array($locked->status, [EventOperation::LIVE, EventOperation::TEARDOWN, EventOperation::COMPLETED], true)) {
                throw new RuntimeException(__('لا تُلغى فعاليّةٌ على الهواء أو بعده — أتمّها.'));
            }

            foreach (AssetReservation::where('event_operation_id', $locked->id)->where('status', AssetReservation::RESERVED)->get() as $r) {
                Reservations::cancel($r, __('أُلغيت الفعاليّة — :reason', ['reason' => $reason]), $actor);
            }

            OperationCrewAssignment::where('event_operation_id', $locked->id)
                ->whereIn('status', [OperationCrewAssignment::PLANNED, OperationCrewAssignment::CONFIRMED])
                ->update(['status' => OperationCrewAssignment::CANCELLED]);
            OperationOrganizers::cancelWith($locked);

            $locked->update(['status' => EventOperation::CANCELLED, 'cancelled_at' => now(), 'cancellation_reason' => mb_substr($reason, 0, 255)]);

            Activity::log('status', 'ألغى الفعاليّة '.$locked->code.' — '.$reason, [
                'subject_id' => $locked->id, 'subject_type' => 'event_operation', 'color' => 'danger',
            ]);

            return $locked->fresh();
        });
    }

    /* ─────────────────────────── القراءة ─────────────────────────── */

    public static function label(EventOperation $operation): string
    {
        return $operation->code.' — '.$operation->name;
    }

    /** أين نحن من الفعاليّة الآن — قبلها بكذا، أو فيها، أو بعدها */
    public static function relativeNow(EventOperation $operation, ?Carbon $now = null): array
    {
        $now ??= now();
        $start = $operation->event_starts_at;
        $end = $operation->event_ends_at ?? $start;

        if ($start === null) {
            return ['phase' => 'unscheduled', 'minutes' => null];
        }
        if ($now->lt($start)) {
            return ['phase' => 'before', 'minutes' => (int) $now->diffInMinutes($start)];
        }
        if ($end !== null && $now->gt($end)) {
            return ['phase' => 'after', 'minutes' => (int) $end->diffInMinutes($now)];
        }

        return ['phase' => 'during', 'minutes' => (int) $start->diffInMinutes($now)];
    }

    /* ─────────────────────────── أدوات ─────────────────────────── */

    /**
     * التواريخُ الستّة — تُقرأ من الطلب وتُفحص أنّ النهاية بعد البداية.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, ?Carbon>
     */
    private static function dates(array $data, ?EventOperation $current): array
    {
        $fields = ['setup_starts_at', 'doors_open_at', 'event_starts_at', 'event_ends_at', 'teardown_starts_at', 'teardown_ends_at'];
        $out = [];

        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $out[$f] = self::datetime($data[$f]);
            }
        }

        $merged = [];
        foreach ($fields as $f) {
            $merged[$f] = array_key_exists($f, $out) ? $out[$f] : $current?->{$f};
        }

        $pairs = [
            ['event_starts_at', 'event_ends_at', 'نهاية الفعاليّة قبل بدايتها.'],
            ['setup_starts_at', 'event_starts_at', 'التجهيز يبدأ بعد بداية الفعاليّة.'],
            ['teardown_starts_at', 'teardown_ends_at', 'نهاية الفكّ قبل بدايته.'],
            ['event_ends_at', 'teardown_ends_at', 'الفكّ ينتهي قبل نهاية الفعاليّة.'],
            ['doors_open_at', 'event_ends_at', 'فتح الأبواب بعد نهاية الفعاليّة.'],
        ];
        foreach ($pairs as [$a, $b, $message]) {
            if ($merged[$a] !== null && $merged[$b] !== null && $merged[$b]->lt($merged[$a])) {
                throw new RuntimeException(__($message));
            }
        }

        return $out;
    }

    public static function datetime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof Carbon) {
            return $value;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw new RuntimeException(__('تاريخٌ غير مقروء.'));
        }
    }

    public static function text(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function timezone(mixed $value, string $fallback): string
    {
        $tz = trim((string) ($value ?? ''));

        return $tz !== '' && in_array($tz, timezone_identifiers_list(), true) ? $tz : $fallback;
    }

    /** موظّفٌ من الشركة — أو لا أحد؛ ومن خارجها يُردّ */
    public static function userIdOf(int $businessId, mixed $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }

        $user = User::where('business_id', $businessId)->whereKey((int) $id)->first();
        if ($user === null) {
            throw new RuntimeException(__('هذا الموظّف ليس من شركتك.'));
        }

        return (int) $user->id;
    }
}
