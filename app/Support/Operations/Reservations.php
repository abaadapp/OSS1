<?php

namespace App\Support\Operations;

use App\Models\Asset;
use App\Models\AssetReservation;
use App\Models\EventOperation;
use App\Models\OperationEquipmentRequirement;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * حجزُ القطع وتسليمُها وردُّها — والقطعةُ الواحدة لا تُحجز مرّتين في وقت.
 *
 * ═══ الحارسُ قفلٌ لا فهرس ═══
 *
 * التداخلُ الزمنيّ لا يُصاغ قيدًا فريدًا. فيُقفل صفُّ القطعة (`FOR UPDATE`)
 * داخل معاملة الحجز، ثمّ يُفحص التداخل، ثمّ يُكتب. طلبان يصلان معًا يقف
 * ثانيهما على القفل حتى يكتب الأوّل — فيرى حجزَه ويُردّ. وعلى SQLite (حيث
 * لا قفلَ صفوف) الكتابةُ متسلسلةٌ أصلًا.
 *
 * ═══ والردُّ لا يقع مرّتين ═══
 *
 * `giveBack()` على حجزٍ رُدَّ من قبل يعيده كما هو: لا حالَ تُكتب ثانيةً ولا
 * سجلَّ يتكرّر. والقطعةُ لا تصير متاحةً لأنّ الفعاليّة انتهت — بل لأنّ أحدًا
 * ردّها وقال بأيّ حال.
 */
final class Reservations
{
    /** ما تُقبل به القطعةُ عند الردّ — الحالاتُ الأربع و«مفقودة» */
    public const RETURN_CONDITIONS = ['excellent', 'good', 'fair', 'damaged', 'lost'];

    /* ─────────────────────────── الحجز ─────────────────────────── */

    /**
     * @param  array{from?: mixed, until?: mixed, requirement_id?: mixed, notes?: mixed}  $data
     */
    public static function reserve(EventOperation $operation, Asset $asset, array $data = [], ?User $actor = null): AssetReservation
    {
        if (! $operation->isActive()) {
            throw new RuntimeException(__('لا يُحجز لفعاليّةٍ خُتمت.'));
        }
        if ((int) $asset->business_id !== (int) $operation->business_id) {
            throw new RuntimeException(__('هذه القطعة ليست من شركتك.'));
        }

        $from = EventOperations::datetime($data['from'] ?? null) ?? $operation->windowStart();
        $until = EventOperations::datetime($data['until'] ?? null) ?? $operation->windowEnd();
        if ($from === null || $until === null) {
            throw new RuntimeException(__('حدّد موعد الفعاليّة أو نافذة الحجز أوّلًا.'));
        }
        if ($until->lte($from)) {
            throw new RuntimeException(__('نهاية الحجز قبل بدايته.'));
        }

        $requirement = self::requirementFor($operation, $asset, $data['requirement_id'] ?? null);

        $reservation = DB::transaction(function () use ($operation, $asset, $from, $until, $requirement, $data, $actor) {
            /* القفلُ أوّلًا — ثمّ الفحصُ على الحال الطازجة */
            $locked = Asset::where('business_id', $operation->business_id)->lockForUpdate()->findOrFail($asset->id);

            if ($locked->isOutOfService()) {
                throw new RuntimeException(__('القطعة :asset خارج الخدمة (:status) — لا تُحجز.', ['asset' => $locked->asset_code, 'status' => __(Assets::STATUS_LABELS[$locked->status] ?? $locked->status)]));
            }

            $clash = Assets::overlapping($locked->id, $from, $until)->with('operation:id,code,name')->first();
            if ($clash !== null) {
                throw new RuntimeException(__('القطعة :asset محجوزة للفعاليّة :op من :from إلى :until.', [
                    'asset' => $locked->asset_code, 'op' => (string) $clash->operation?->code,
                    'from' => $clash->reserved_from->format('Y-m-d H:i'), 'until' => $clash->reserved_until->format('Y-m-d H:i'),
                ]));
            }

            $reservation = AssetReservation::create([
                'business_id' => $operation->business_id,
                'asset_id' => $locked->id,
                'event_operation_id' => $operation->id,
                'requirement_id' => $requirement?->id,
                'reserved_from' => $from,
                'reserved_until' => $until,
                'status' => AssetReservation::RESERVED,
                'assigned_by' => $actor?->id,
                'notes' => EventOperations::text($data['notes'] ?? null, 2000),
            ]);

            Assets::sync($locked);
            if ($requirement !== null) {
                Requirements::recount($requirement);
            }

            return $reservation;
        });

        Activity::log('created', 'حجز القطعة '.$asset->asset_code.' للفعاليّة '.$operation->code.' من '.$from->format('Y-m-d H:i').' إلى '.$until->format('Y-m-d H:i'), [
            'subject_id' => $reservation->id, 'subject_type' => 'asset_reservation', 'icon' => 'calendar-plus',
        ]);

        return $reservation;
    }

    public static function cancel(AssetReservation $reservation, ?string $reason, ?User $actor = null): AssetReservation
    {
        return DB::transaction(function () use ($reservation, $reason, $actor) {
            $locked = AssetReservation::where('business_id', $reservation->business_id)->lockForUpdate()->findOrFail($reservation->id);

            if ($locked->status === AssetReservation::CANCELLED) {
                return $locked;
            }
            if ($locked->status !== AssetReservation::RESERVED) {
                throw new RuntimeException(__('لا يُلغى حجزٌ سُلّمت قطعتُه — رُدَّها.'));
            }

            $locked->update(['status' => AssetReservation::CANCELLED, 'cancelled_at' => now(), 'cancellation_reason' => EventOperations::text($reason, 255)]);
            self::after($locked);

            Activity::log('deleted', 'ألغى حجز القطعة '.(string) $locked->asset?->asset_code.' للفعاليّة '.(string) $locked->operation?->code.($reason ? ' — '.$reason : ''), [
                'subject_id' => $locked->id, 'subject_type' => 'asset_reservation', 'icon' => 'calendar-x', 'color' => 'warning',
            ]);

            return $locked->fresh();
        });
    }

    /* ─────────────────────────── التسليم والردّ ─────────────────────────── */

    /**
     * التسليمُ — خرجت القطعة من المكتب إلى عهدة أحد.
     *
     * @param  array{custodian_id?: mixed, condition?: mixed, notes?: mixed}  $data
     */
    public static function checkOut(AssetReservation $reservation, array $data = [], ?User $actor = null): AssetReservation
    {
        return DB::transaction(function () use ($reservation, $data, $actor) {
            $locked = AssetReservation::where('business_id', $reservation->business_id)->lockForUpdate()->findOrFail($reservation->id);

            if ($locked->status === AssetReservation::CHECKED_OUT) {
                return $locked;
            }
            if ($locked->status !== AssetReservation::RESERVED) {
                throw new RuntimeException(__('لا يُسلَّم إلّا حجزٌ قائم.'));
            }
            $asset = Asset::where('business_id', $locked->business_id)->lockForUpdate()->findOrFail($locked->asset_id);
            if ($asset->isOutOfService()) {
                throw new RuntimeException(__('القطعة :asset خارج الخدمة — لا تُسلَّم.', ['asset' => $asset->asset_code]));
            }
            if (! $locked->operation?->isActive()) {
                throw new RuntimeException(__('الفعاليّة خُتمت — لا تُسلَّم لها قطعة.'));
            }

            $condition = in_array($data['condition'] ?? null, Asset::CONDITIONS, true) ? $data['condition'] : $asset->condition;

            $locked->update([
                'status' => AssetReservation::CHECKED_OUT,
                'checked_out_at' => now(),
                'checked_out_by' => $actor?->id,
                'custodian_id' => EventOperations::userIdOf((int) $locked->business_id, $data['custodian_id'] ?? null),
                'checkout_condition' => $condition,
                'checkout_notes' => EventOperations::text($data['notes'] ?? null, 2000),
            ]);
            Assets::sync($asset);

            Activity::log('checkout', 'سلّم القطعة '.$asset->asset_code.' للفعاليّة '.(string) $locked->operation?->code.' — الحال: '.__(Assets::CONDITION_LABELS[$condition] ?? $condition), [
                'subject_id' => $locked->id, 'subject_type' => 'asset_reservation', 'icon' => 'package-open',
            ]);

            return $locked->fresh();
        });
    }

    /**
     * الردُّ — عادت القطعة وبأيّ حال. آمنٌ عند التكرار.
     *
     * تالفةٌ تصير «تالفة» في السجلّ حتى يعيدها أحدٌ إلى الخدمة بيد؛ ومفقودةٌ
     * تصير «مفقودة». وما عدا ذلك تعود متاحةً — أو محجوزةً لمن بعدها.
     *
     * @param  array{condition?: mixed, missing_accessories?: mixed, damage_notes?: mixed, notes?: mixed}  $data
     */
    public static function giveBack(AssetReservation $reservation, array $data = [], ?User $actor = null): AssetReservation
    {
        return DB::transaction(function () use ($reservation, $data, $actor) {
            $locked = AssetReservation::where('business_id', $reservation->business_id)->lockForUpdate()->findOrFail($reservation->id);

            if ($locked->status === AssetReservation::RETURNED) {
                return $locked; /* رُدَّت من قبل — طلبٌ مكرَّر لا يُفسد شيئًا */
            }
            if ($locked->status !== AssetReservation::CHECKED_OUT) {
                throw new RuntimeException(__('لا تُردّ قطعةٌ لم تُسلَّم — ألغِ الحجز.'));
            }

            $condition = (string) ($data['condition'] ?? 'good');
            if (! in_array($condition, self::RETURN_CONDITIONS, true)) {
                throw new RuntimeException(__('حالُ الردّ غير معروفة.'));
            }

            $asset = Asset::where('business_id', $locked->business_id)->lockForUpdate()->findOrFail($locked->asset_id);

            $locked->update([
                'status' => AssetReservation::RETURNED,
                'returned_at' => now(),
                'returned_by' => $actor?->id,
                'return_condition' => $condition,
                'missing_accessories' => EventOperations::text($data['missing_accessories'] ?? null, 2000),
                'damage_notes' => EventOperations::text($data['damage_notes'] ?? null, 2000),
            ]);

            if ($condition === 'lost') {
                $asset->update(['status' => Asset::LOST]);
            } elseif ($condition === 'damaged') {
                $asset->update(['status' => Asset::DAMAGED, 'condition' => 'damaged']);
            } else {
                $asset->update(['condition' => $condition]);
                Assets::sync($asset);
            }
            if ($locked->requirement_id !== null) {
                Requirements::recount($locked->requirement);
            }

            $severity = in_array($condition, ['damaged', 'lost'], true) ? 'danger' : 'success';
            Activity::log('return', 'ردّ القطعة '.$asset->asset_code.' من الفعاليّة '.(string) $locked->operation?->code.' — الحال: '.__(self::conditionLabel($condition))
                .(filled($data['missing_accessories'] ?? null) ? ' · ناقص: '.$data['missing_accessories'] : '')
                .(filled($data['damage_notes'] ?? null) ? ' · تلف: '.$data['damage_notes'] : ''), [
                    'subject_id' => $locked->id, 'subject_type' => 'asset_reservation', 'icon' => 'package-check', 'color' => $severity,
                ]);

            return $locked->fresh();
        });
    }

    /* ─────────────────────────── القراءة ─────────────────────────── */

    /**
     * ما خرج ولم يعد — للشركة كلِّها، مرتَّبًا بالأقدم.
     *
     * @return Collection<int, AssetReservation>
     */
    public static function outstanding(int $businessId, int $limit = 100): Collection
    {
        return AssetReservation::where('business_id', $businessId)
            ->where('status', AssetReservation::CHECKED_OUT)
            ->with('asset', 'operation:id,code,name,status,event_ends_at', 'custodian:id,name')
            ->orderBy('reserved_until')->limit($limit)->get();
    }

    public static function conditionLabel(string $condition): string
    {
        return $condition === 'lost' ? 'مفقودة' : (Assets::CONDITION_LABELS[$condition] ?? $condition);
    }

    /* ─────────────────────────── أدوات ─────────────────────────── */

    /** المتطلَّبُ الذي يخصّه الحجز — المعطى، أو أوّلُ متطلَّبٍ من صنف القطعة لم يكتمل */
    private static function requirementFor(EventOperation $operation, Asset $asset, mixed $id): ?OperationEquipmentRequirement
    {
        if ($id !== null && $id !== '') {
            $req = OperationEquipmentRequirement::where('event_operation_id', $operation->id)->whereKey((int) $id)->first();
            if ($req === null) {
                throw new RuntimeException(__('المتطلَّب ليس من هذه الفعاليّة.'));
            }

            return $req;
        }

        $candidates = OperationEquipmentRequirement::where('event_operation_id', $operation->id)
            ->where('kind', OperationEquipmentRequirement::ASSET_CATEGORY)->where('asset_category', $asset->category)
            ->orderBy('sort_order')->orderBy('id')->get();

        return $candidates->first(fn ($r) => $r->shortfall() > 0) ?? $candidates->first();
    }

    /** بعد إلغاءٍ — الحالُ المشتقّة والعدّ */
    private static function after(AssetReservation $reservation): void
    {
        $asset = Asset::find($reservation->asset_id);
        if ($asset !== null) {
            Assets::sync($asset);
        }
        if ($reservation->requirement_id !== null && $reservation->requirement !== null) {
            Requirements::recount($reservation->requirement);
        }
    }

    /** نافذةٌ تُقرأ للشاشة */
    public static function window(AssetReservation $r): array
    {
        return ['from' => $r->reserved_from?->format('Y-m-d H:i'), 'until' => $r->reserved_until?->format('Y-m-d H:i')];
    }

    /** ما يشغل القطعةَ من هذه اللحظة فصاعدًا — للتقويم وبطاقة القطعة */
    public static function upcomingFor(Asset $asset, ?Carbon $from = null, int $limit = 50): Collection
    {
        return AssetReservation::where('asset_id', $asset->id)
            ->whereIn('status', AssetReservation::OCCUPYING)
            ->where('reserved_until', '>=', $from ?? now())
            ->with('operation:id,code,name,status', 'custodian:id,name')
            ->orderBy('reserved_from')->limit($limit)->get();
    }
}
