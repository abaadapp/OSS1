<?php

namespace App\Support\Operations;

use App\Models\Asset;
use App\Models\AssetReservation;
use App\Models\Business;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * سجلُّ المعدّات — التسجيلُ والحالُ والإتاحة.
 *
 * ═══ الحالُ تُشتقّ ولا تُخمَّن ═══
 *
 * «متاحة» و«محجوزة» و«في الميدان» تُحسب من الحجوز: قطعةٌ عليها حجزٌ محجوزة،
 * وما سُلّم في الميدان، وما لا حجزَ عليه متاح. و«صيانة» و«تالفة» و«مفقودة»
 * و«مسحوبة» تُكتب بيدٍ وسبب — ولا تُمحى بمرور الوقت.
 */
final class Assets
{
    public const PREFIX = 'AST-';

    /** التصنيفاتُ المقترَحة — اقتراحٌ لا حصر */
    public const SUGGESTED_CATEGORIES = [
        'laptop', 'printer', 'badge_printer', 'scanner', 'handheld', 'tablet', 'router', 'screen', 'stand',
        'cable', 'power', 'network', 'pos', 'camera', 'accessory', 'other',
    ];

    public const CATEGORY_LABELS = [
        'laptop' => 'حاسوب محمول', 'printer' => 'طابعة', 'badge_printer' => 'طابعة بطاقات', 'scanner' => 'ماسح',
        'handheld' => 'جهاز محمول', 'tablet' => 'جهاز لوحي', 'router' => 'موجّه شبكة', 'screen' => 'شاشة', 'stand' => 'حامل',
        'cable' => 'كابل', 'power' => 'محوّل طاقة', 'network' => 'معدّات شبكة', 'pos' => 'جهاز نقاط بيع',
        'camera' => 'كاميرا', 'accessory' => 'ملحق', 'other' => 'أخرى',
    ];

    public const STATUS_LABELS = [
        Asset::AVAILABLE => 'متاحة', Asset::RESERVED => 'محجوزة', Asset::DEPLOYED => 'في الميدان',
        Asset::MAINTENANCE => 'في الصيانة', Asset::DAMAGED => 'تالفة', Asset::LOST => 'مفقودة', Asset::RETIRED => 'مسحوبة',
    ];

    public const CONDITION_LABELS = ['excellent' => 'ممتازة', 'good' => 'جيّدة', 'fair' => 'مقبولة', 'damaged' => 'تالفة'];

    /* ─────────────────────────── التسجيل ─────────────────────────── */

    /** @param  array<string, mixed>  $data */
    public static function create(int $businessId, array $data, ?User $actor = null): Asset
    {
        $name = EventOperations::text($data['name'] ?? null, 160);
        if ($name === null) {
            throw new RuntimeException(__('اسمُ القطعة مطلوب.'));
        }
        $category = self::category($data['category'] ?? null);

        $asset = DB::transaction(fn () => Asset::create([
            'business_id' => $businessId,
            'asset_code' => self::nextCode($businessId),
            'name' => $name,
            'category' => $category,
            'brand' => EventOperations::text($data['brand'] ?? null, 80),
            'model' => EventOperations::text($data['model'] ?? null, 80),
            'serial_number' => self::serial($businessId, $data['serial_number'] ?? null, null),
            'purchase_date' => filled($data['purchase_date'] ?? null) ? Carbon::parse((string) $data['purchase_date'])->toDateString() : null,
            'purchase_cost' => filled($data['purchase_cost'] ?? null) ? round((float) $data['purchase_cost'], 3) : null,
            'status' => Asset::AVAILABLE,
            'condition' => in_array($data['condition'] ?? null, Asset::CONDITIONS, true) ? $data['condition'] : 'good',
            'location' => EventOperations::text($data['location'] ?? null, 120),
            'notes' => EventOperations::text($data['notes'] ?? null, 5000),
            'metadata' => is_array($data['metadata'] ?? null) ? $data['metadata'] : null,
            'fixed_asset_id' => self::fixedAssetId($businessId, $data['fixed_asset_id'] ?? null),
            'created_by' => $actor?->id,
        ]));

        Activity::log('created', 'سجّل القطعة '.$asset->asset_code.' — '.$asset->name, [
            'subject_id' => $asset->id, 'subject_type' => 'asset', 'icon' => 'package',
        ]);

        return $asset;
    }

    /** @param  array<string, mixed>  $data */
    public static function update(Asset $asset, array $data, ?User $actor = null): Asset
    {
        $update = [];
        foreach (['name' => 160, 'brand' => 80, 'model' => 80, 'location' => 120, 'notes' => 5000] as $f => $max) {
            if (array_key_exists($f, $data)) {
                $update[$f] = EventOperations::text($data[$f], $max) ?? ($f === 'name' ? $asset->name : null);
            }
        }
        if (array_key_exists('category', $data)) {
            $update['category'] = self::category($data['category']);
        }
        if (array_key_exists('serial_number', $data)) {
            $update['serial_number'] = self::serial((int) $asset->business_id, $data['serial_number'], $asset->id);
        }
        if (array_key_exists('purchase_date', $data)) {
            $update['purchase_date'] = filled($data['purchase_date']) ? Carbon::parse((string) $data['purchase_date'])->toDateString() : null;
        }
        if (array_key_exists('purchase_cost', $data)) {
            $update['purchase_cost'] = filled($data['purchase_cost']) ? round((float) $data['purchase_cost'], 3) : null;
        }
        if (array_key_exists('condition', $data) && in_array($data['condition'], Asset::CONDITIONS, true)) {
            $update['condition'] = $data['condition'];
        }
        if (array_key_exists('fixed_asset_id', $data)) {
            $update['fixed_asset_id'] = self::fixedAssetId((int) $asset->business_id, $data['fixed_asset_id']);
        }
        if (array_key_exists('metadata', $data)) {
            $update['metadata'] = is_array($data['metadata']) ? $data['metadata'] : null;
        }

        $asset->update($update);

        Activity::log('updated', 'عدّل القطعة '.$asset->asset_code.' — '.$asset->name, [
            'subject_id' => $asset->id, 'subject_type' => 'asset',
        ]);

        return $asset->fresh();
    }

    /** «AST-000123» — داخل قفل صفّ الشركة */
    public static function nextCode(int $businessId): string
    {
        return DB::transaction(function () use ($businessId) {
            Business::whereKey($businessId)->lockForUpdate()->first();

            $highest = (int) Asset::where('business_id', $businessId)
                ->where('asset_code', 'like', self::PREFIX.'%')
                ->get(['asset_code'])
                ->map(fn ($r) => (int) substr((string) $r->asset_code, strlen(self::PREFIX)))
                ->max();

            return self::PREFIX.str_pad((string) ($highest + 1), 6, '0', STR_PAD_LEFT);
        });
    }

    /* ─────────────────────────── الحال ─────────────────────────── */

    /**
     * تغييرُ الحال بيد: صيانةٌ · تلفٌ · فقدٌ · سحبٌ · إعادةٌ إلى الخدمة.
     *
     * ما في الميدان لا يُسحب ولا يُفقد من هنا قبل أن يُردَّ أو يُقفل حجزُه —
     * وإلّا بقيت عهدةٌ مفتوحة على قطعةٍ «مسحوبة». والتلفُ يُكتب في أيّ حال:
     * قطعةٌ تعطّلت في الميدان تالفةٌ وهي هناك.
     */
    public static function setStatus(Asset $asset, string $status, ?string $reason, ?User $actor = null): Asset
    {
        if (! in_array($status, [...Asset::OUT_OF_SERVICE, Asset::AVAILABLE], true)) {
            throw new RuntimeException(__('لا تُكتب هذه الحالُ بيد — تُشتقّ من الحجوز.'));
        }

        return DB::transaction(function () use ($asset, $status, $reason, $actor) {
            $locked = Asset::where('business_id', $asset->business_id)->lockForUpdate()->findOrFail($asset->id);

            $outstanding = AssetReservation::where('asset_id', $locked->id)->where('status', AssetReservation::CHECKED_OUT)->exists();
            if (in_array($status, [Asset::RETIRED, Asset::LOST], true) && $outstanding) {
                throw new RuntimeException(__('القطعة في الميدان — رُدَّها أو أقفل حجزها قبل سحبها.'));
            }
            if ($status === Asset::RETIRED && AssetReservation::where('asset_id', $locked->id)->where('status', AssetReservation::RESERVED)->exists()) {
                throw new RuntimeException(__('على القطعة حجزٌ قائم — ألغِه قبل سحبها.'));
            }

            /* والعودةُ إلى الخدمة تعود إلى ما تقوله الحجوز: متاحةٌ أو محجوزةٌ لمن بعدها */
            $update = ['status' => $status === Asset::AVAILABLE ? self::derivedStatus($locked, true) : $status];
            if ($status === Asset::DAMAGED) {
                $update['condition'] = 'damaged';
            }
            if ($status === Asset::RETIRED) {
                $update['retired_at'] = now();
            }
            if ($status === Asset::AVAILABLE) {
                $update['retired_at'] = null;
                if ($locked->condition === 'damaged') {
                    $update['condition'] = 'good';
                }
            }
            $locked->update($update);

            Activity::log('status', 'غيّر حال القطعة '.$locked->asset_code.' إلى «'.__(self::STATUS_LABELS[$status] ?? $status).'»'.($reason ? ' — '.$reason : ''), [
                'subject_id' => $locked->id, 'subject_type' => 'asset',
                'color' => in_array($status, [Asset::DAMAGED, Asset::LOST], true) ? 'danger' : 'warning',
            ]);

            return $locked->fresh();
        });
    }

    /** الحالُ المشتقّة من الحجوز — لما ليس خارج الخدمة، أو لما يُعاد إليها */
    public static function derivedStatus(Asset $asset, bool $returningToService = false): string
    {
        if ($asset->isOutOfService() && ! $returningToService) {
            return $asset->status;
        }
        if (AssetReservation::where('asset_id', $asset->id)->where('status', AssetReservation::CHECKED_OUT)->exists()) {
            return Asset::DEPLOYED;
        }
        if (AssetReservation::where('asset_id', $asset->id)->where('status', AssetReservation::RESERVED)->exists()) {
            return Asset::RESERVED;
        }

        return Asset::AVAILABLE;
    }

    /** يعيد كتابةَ الحال المشتقّة — تُنادى بعد كلّ حركةِ حجز */
    public static function sync(Asset $asset): void
    {
        $status = self::derivedStatus($asset);
        if ($status !== $asset->status) {
            $asset->update(['status' => $status]);
        }
    }

    /* ─────────────────────────── الإتاحة ─────────────────────────── */

    /** هل القطعةُ حرّةٌ بين اللحظتين؟ — لا حجزَ يشغلها ولا خروجَ عن الخدمة */
    public static function isFree(Asset $asset, Carbon $from, Carbon $until, ?int $ignoreReservationId = null): bool
    {
        if ($asset->isOutOfService()) {
            return false;
        }

        return ! self::overlapping($asset->id, $from, $until, $ignoreReservationId)->exists();
    }

    /** الحجوزُ التي تشغل القطعة بين اللحظتين */
    public static function overlapping(int $assetId, Carbon $from, Carbon $until, ?int $ignoreReservationId = null)
    {
        return AssetReservation::where('asset_id', $assetId)
            ->whereIn('status', AssetReservation::OCCUPYING)
            ->where('reserved_from', '<', $until)
            ->where('reserved_until', '>', $from)
            ->when($ignoreReservationId, fn ($q, $id) => $q->whereKeyNot($id));
    }

    /**
     * ما يُمكن حجزُه من صنفٍ بين اللحظتين — مرتَّبًا بالرمز.
     *
     * استعلامان لا حلقةُ استعلامات: القطعُ في الخدمة، ثمّ المشغولةُ منها في
     * النافذة تُطرح.
     *
     * @return Collection<int, Asset>
     */
    public static function availableFor(int $businessId, ?string $category, Carbon $from, Carbon $until): Collection
    {
        $assets = Asset::where('business_id', $businessId)
            ->whereNotIn('status', Asset::OUT_OF_SERVICE)
            ->when($category, fn ($q, $c) => $q->where('category', $c))
            ->orderBy('asset_code')->get();

        $busy = AssetReservation::whereIn('asset_id', $assets->pluck('id'))
            ->whereIn('status', AssetReservation::OCCUPYING)
            ->where('reserved_from', '<', $until)->where('reserved_until', '>', $from)
            ->pluck('asset_id')->flip();

        return $assets->reject(fn (Asset $a) => isset($busy[$a->id]))->values();
    }

    /* ─────────────────────────── أدوات ─────────────────────────── */

    private static function category(mixed $value): string
    {
        $c = mb_strtolower(trim((string) ($value ?? '')));
        $c = preg_replace('/[^a-z0-9_\p{Arabic}]+/u', '_', $c) ?? '';
        $c = trim($c, '_');

        if ($c === '' || mb_strlen($c) > 60) {
            throw new RuntimeException(__('صنفُ القطعة مطلوب — حتى 60 حرفًا.'));
        }

        return $c;
    }

    private static function serial(int $businessId, mixed $value, ?int $ignoreId): ?string
    {
        $serial = EventOperations::text($value, 120);
        if ($serial === null) {
            return null;
        }

        $taken = Asset::where('business_id', $businessId)->where('serial_number', $serial)
            ->when($ignoreId, fn ($q, $id) => $q->whereKeyNot($id))->exists();
        if ($taken) {
            throw new RuntimeException(__('رقمٌ تسلسليٌّ مسجَّل على قطعةٍ أخرى.'));
        }

        return $serial;
    }

    private static function fixedAssetId(int $businessId, mixed $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }
        $found = \App\Models\FixedAsset::where('business_id', $businessId)->whereKey((int) $id)->value('id');
        if ($found === null) {
            throw new RuntimeException(__('صفُّ الإهلاك ليس من شركتك.'));
        }

        return (int) $found;
    }

    public static function categoryLabel(string $category): string
    {
        return isset(self::CATEGORY_LABELS[$category]) ? __(self::CATEGORY_LABELS[$category]) : $category;
    }
}
