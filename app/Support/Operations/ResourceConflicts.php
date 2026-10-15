<?php

namespace App\Support\Operations;

use App\Models\Asset;
use App\Models\AssetReservation;
use App\Models\EventOperation;
use App\Models\OperationEquipmentRequirement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * تعارضاتُ الموارد — قاعدةٌ واحدة تُحسب في موضعٍ واحد.
 *
 * تُسأل من شاشة الفعاليّة، ومن تبويب المشروع، ومن مركز العمليات، ومن
 * الجاهزيّة — ولا تُعاد كتابتُها في متحكّم. وكلُّ تعارضٍ يحمل شدّتَه:
 * `critical` يمنع الجاهزيّة، و`warning` يُقال، و`info` يُذكر.
 *
 * ما تكشفه عن المعدّات:
 *  - قطعةٌ محجوزة لفعاليّتين تتداخلان (لا يقع من الباب الصحيح — ويُكشف إن وقع)
 *  - قطعةٌ محجوزة وهي خارج الخدمة (صيانةٌ · تلفٌ · فقدٌ · سحب)
 *  - نقصٌ في متطلَّب — حرجٌ أو غيرُ حرج
 *  - حجزٌ لا يغطّي نافذة الفعاليّة
 *  - قطعةٌ لم تُردّ من فعاليّةٍ سابقة وهذه تحتاجها
 *  - قطعةٌ تعود قبل التجهيز بأقلَّ من يوم
 *
 * وتعارضاتُ الفريق عند `Crew::conflicts` — وتُضمّ هنا في `all()`.
 */
final class ResourceConflicts
{
    public const CRITICAL = 'critical';

    public const WARNING = 'warning';

    public const INFO = 'info';

    /** أقلُّ ما بين عودة القطعة من فعاليّةٍ وبدء التالية قبل أن يُقال «ضيّق» */
    public const TIGHT_RETURN_HOURS = 24;

    /**
     * تعارضاتُ المعدّات لفعاليّةٍ واحدة.
     *
     * @return list<array{severity: string, kind: string, message: string, asset_id: ?int, requirement_id: ?int}>
     */
    public static function equipment(EventOperation $operation): array
    {
        $out = [];
        $operation->loadMissing('requirements', 'reservations.asset');

        $reservations = $operation->reservations->filter(fn (AssetReservation $r) => $r->isOccupying());
        $windowStart = $operation->windowStart();
        $windowEnd = $operation->windowEnd();

        /* حجوزُ القطع نفسِها في فعاليّاتٍ أخرى — استعلامٌ واحد */
        $assetIds = $reservations->pluck('asset_id')->unique()->values()->all();
        $others = $assetIds === [] ? collect() : AssetReservation::whereIn('asset_id', $assetIds)
            ->where('event_operation_id', '!=', $operation->id)
            ->whereIn('status', AssetReservation::OCCUPYING)
            ->with('operation:id,code,name')->get()->groupBy('asset_id');

        foreach ($reservations as $r) {
            $asset = $r->asset;
            if ($asset === null) {
                continue;
            }
            $label = $asset->label();

            if ($asset->isOutOfService()) {
                $out[] = self::row(self::CRITICAL, 'asset_out_of_service', __('القطعة :asset محجوزة وهي :status.', ['asset' => $label, 'status' => __(Assets::STATUS_LABELS[$asset->status] ?? $asset->status)]), $asset->id, $r->requirement_id);
            }

            foreach ($others->get($asset->id, collect()) as $o) {
                if ($o->reserved_from->lt($r->reserved_until) && $o->reserved_until->gt($r->reserved_from)) {
                    $out[] = self::row(self::CRITICAL, 'asset_double_booked', __('القطعة :asset محجوزة أيضًا للفعاليّة :op في الوقت نفسه.', ['asset' => $label, 'op' => (string) $o->operation?->code]), $asset->id, $r->requirement_id);

                    continue;
                }
                if ($o->reserved_until->lte($r->reserved_from)) {
                    if ($o->status === AssetReservation::CHECKED_OUT) {
                        $out[] = self::row(self::CRITICAL, 'asset_unreturned', __('القطعة :asset لم تُردّ بعد من الفعاليّة :op.', ['asset' => $label, 'op' => (string) $o->operation?->code]), $asset->id, $r->requirement_id);
                    } elseif ($o->reserved_until->diffInHours($r->reserved_from) < self::TIGHT_RETURN_HOURS) {
                        $out[] = self::row(self::WARNING, 'asset_tight_return', __('القطعة :asset تعود من الفعاليّة :op قبل هذه بأقلّ من يوم.', ['asset' => $label, 'op' => (string) $o->operation?->code]), $asset->id, $r->requirement_id);
                    }
                }
            }

            if ($windowStart !== null && $windowEnd !== null && ($r->reserved_from->gt($windowStart) || $r->reserved_until->lt($windowEnd))) {
                $out[] = self::row(self::WARNING, 'reservation_short', __('حجز القطعة :asset لا يغطّي نافذة الفعاليّة كاملةً.', ['asset' => $label]), $asset->id, $r->requirement_id);
            }
        }

        foreach ($operation->requirements as $req) {
            /** @var OperationEquipmentRequirement $req */
            if ($req->shortfall() <= 0) {
                continue;
            }
            $out[] = self::row($req->critical ? self::CRITICAL : self::WARNING, $req->critical ? 'equipment_missing' : 'equipment_optional_missing',
                __('ينقص :n من «:what» (:assigned من :required).', ['n' => $req->shortfall(), 'what' => $req->description, 'assigned' => (int) $req->assigned_quantity, 'required' => (int) $req->required_quantity]),
                null, $req->id);
        }

        return $out;
    }

    /**
     * كلُّ التعارضات — المعدّاتُ والفريقُ والمنظّمون.
     *
     * والمنظّمون بسياسة الفريق نفسِها (`OperationOrganizers::conflicts`): التداخلُ
     * مانعٌ والفجوةُ الضيّقة تحذير. ولا يدخلون الجاهزيّة — تلك تسأل الفريقَ والمعدّات.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(EventOperation $operation): array
    {
        return array_merge(self::equipment($operation), Crew::conflicts($operation), OperationOrganizers::conflicts($operation));
    }

    /**
     * أشدُّ تعارضٍ في القائمة — أو null.
     */
    public static function worst(array $conflicts): ?string
    {
        $severities = array_column($conflicts, 'severity');

        return in_array(self::CRITICAL, $severities, true) ? self::CRITICAL : (in_array(self::WARNING, $severities, true) ? self::WARNING : ($severities === [] ? null : self::INFO));
    }

    /**
     * تعارضاتُ الشركة في الفعاليّات القادمة — لمركز العمليات، محدودةً.
     *
     * @return list<array{operation: EventOperation, conflicts: list<array<string, mixed>>}>
     */
    public static function upcoming(int $businessId, int $days = 14, int $limit = 25): array
    {
        $from = now()->startOfDay();
        $until = $from->copy()->addDays($days)->endOfDay();

        $operations = EventOperation::where('business_id', $businessId)->active()
            ->where(fn ($q) => $q->whereBetween('event_starts_at', [$from, $until])->orWhereIn('status', EventOperation::ON_SITE))
            ->with('requirements', 'reservations.asset', 'crew.user')
            ->orderBy('event_starts_at')->limit($limit)->get();

        $out = [];
        foreach ($operations as $op) {
            $conflicts = self::all($op);
            if ($conflicts !== []) {
                $out[] = ['operation' => $op, 'conflicts' => $conflicts];
            }
        }

        return $out;
    }

    /** الأصولُ التي عليها حجزٌ وهي خارج الخدمة — للتنبيه في سجلّ المعدّات */
    public static function unserviceableReserved(int $businessId): Collection
    {
        return Asset::where('business_id', $businessId)->whereIn('status', Asset::OUT_OF_SERVICE)
            ->whereHas('reservations', fn ($q) => $q->whereIn('status', AssetReservation::OCCUPYING)->where('reserved_until', '>=', now()))
            ->get();
    }

    private static function row(string $severity, string $kind, string $message, ?int $assetId, ?int $requirementId): array
    {
        return ['severity' => $severity, 'kind' => $kind, 'message' => $message, 'asset_id' => $assetId, 'requirement_id' => $requirementId];
    }

    /** ساعاتٌ بين لحظتين — للقراءة */
    public static function hoursBetween(Carbon $a, Carbon $b): int
    {
        return (int) $a->diffInHours($b);
    }
}
