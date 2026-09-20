<?php

namespace App\Support\Operations;

use App\Models\AssetReservation;
use App\Models\EventOperation;
use App\Models\OperationEquipmentRequirement;
use App\Models\Product;
use App\Models\User;
use App\Support\Activity;
use RuntimeException;

/**
 * متطلّباتُ المعدّات — «ماذا نحتاج» قبل «أيَّ قطعٍ نأخذ».
 *
 * صنفُ أصولٍ يُعدّ من حجوزه؛ ومنتجٌ من المخزون أو نصٌّ حرٌّ يُكتب المُعَدُّ
 * منه بيد (`assigned_quantity`) — فلا نظامَ مخزونٍ ثانيًا يُبنى هنا.
 */
final class Requirements
{
    public const SOURCE_LABELS = [
        'owned' => 'مملوك', 'inventory' => 'من المخزون', 'rented' => 'مستأجَر', 'client' => 'من العميل', 'third_party' => 'طرف ثالث',
    ];

    /** @param  array<string, mixed>  $data */
    public static function add(EventOperation $operation, array $data, ?User $actor = null): OperationEquipmentRequirement
    {
        if (! $operation->isActive()) {
            throw new RuntimeException(__('لا تُعدَّل متطلّباتُ فعاليّةٍ خُتمت.'));
        }

        $requirement = OperationEquipmentRequirement::create(self::attributes($operation, $data, null) + [
            'business_id' => $operation->business_id,
            'event_operation_id' => $operation->id,
            'assigned_quantity' => 0,
            'sort_order' => (int) OperationEquipmentRequirement::where('event_operation_id', $operation->id)->max('sort_order') + 1,
        ]);

        if ($requirement->kind !== OperationEquipmentRequirement::ASSET_CATEGORY && array_key_exists('assigned_quantity', $data)) {
            $requirement->update(['assigned_quantity' => max(0, (int) $data['assigned_quantity'])]);
        }

        Activity::log('updated', 'أضاف متطلَّبًا للفعاليّة '.$operation->code.': '.$requirement->required_quantity.' × '.$requirement->description, [
            'subject_id' => $operation->id, 'subject_type' => 'event_operation',
        ]);

        return $requirement->fresh();
    }

    /** @param  array<string, mixed>  $data */
    public static function update(OperationEquipmentRequirement $requirement, array $data, ?User $actor = null): OperationEquipmentRequirement
    {
        $operation = $requirement->operation;
        if (! $operation->isActive()) {
            throw new RuntimeException(__('لا تُعدَّل متطلّباتُ فعاليّةٍ خُتمت.'));
        }

        $requirement->update(self::attributes($operation, $data, $requirement));

        if ($requirement->kind === OperationEquipmentRequirement::ASSET_CATEGORY) {
            self::recount($requirement);
        } elseif (array_key_exists('assigned_quantity', $data)) {
            $requirement->update(['assigned_quantity' => max(0, (int) $data['assigned_quantity'])]);
        }

        return $requirement->fresh();
    }

    public static function remove(OperationEquipmentRequirement $requirement, ?User $actor = null): void
    {
        $operation = $requirement->operation;
        if (! $operation->isActive()) {
            throw new RuntimeException(__('لا تُعدَّل متطلّباتُ فعاليّةٍ خُتمت.'));
        }
        if (AssetReservation::where('requirement_id', $requirement->id)->whereIn('status', AssetReservation::OCCUPYING)->exists()) {
            throw new RuntimeException(__('على هذا المتطلَّب حجوزٌ قائمة — ألغِها أوّلًا.'));
        }

        $description = $requirement->description;
        $requirement->delete();

        Activity::log('updated', 'حذف متطلَّبًا من الفعاليّة '.$operation->code.': '.$description, [
            'subject_id' => $operation->id, 'subject_type' => 'event_operation',
        ]);
    }

    /** إعادةُ عدّ المُعَدّ من الحجوز — لصنف الأصول وحدَه */
    public static function recount(OperationEquipmentRequirement $requirement): void
    {
        if ($requirement->kind !== OperationEquipmentRequirement::ASSET_CATEGORY) {
            return;
        }

        $n = AssetReservation::where('requirement_id', $requirement->id)->whereIn('status', AssetReservation::OCCUPYING)->count();
        if ($n !== (int) $requirement->assigned_quantity) {
            $requirement->update(['assigned_quantity' => $n]);
        }
    }

    /**
     * النقصُ: ما لم يُعَدّ من كلّ متطلَّب.
     *
     * @return list<array{id: int, description: string, required: int, assigned: int, shortfall: int, critical: bool}>
     */
    public static function gaps(EventOperation $operation): array
    {
        return $operation->requirements
            ->filter(fn (OperationEquipmentRequirement $r) => $r->shortfall() > 0)
            ->map(fn (OperationEquipmentRequirement $r) => [
                'id' => $r->id, 'description' => $r->description, 'required' => (int) $r->required_quantity,
                'assigned' => (int) $r->assigned_quantity, 'shortfall' => $r->shortfall(), 'critical' => (bool) $r->critical,
            ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function attributes(EventOperation $operation, array $data, ?OperationEquipmentRequirement $current): array
    {
        $kind = $data['kind'] ?? $current?->kind ?? OperationEquipmentRequirement::ASSET_CATEGORY;
        if (! in_array($kind, OperationEquipmentRequirement::KINDS, true)) {
            throw new RuntimeException(__('نوعُ المتطلَّب غير معروف.'));
        }

        $out = ['kind' => $kind];

        if ($kind === OperationEquipmentRequirement::PRODUCT) {
            $productId = (int) ($data['product_id'] ?? $current?->product_id ?? 0);
            $product = Product::where('business_id', $operation->business_id)->whereKey($productId)->first();
            if ($product === null) {
                throw new RuntimeException(__('المنتج ليس من كتالوج شركتك.'));
            }
            $out['product_id'] = $product->id;
            $out['asset_category'] = null;
            $out['description'] = EventOperations::text($data['description'] ?? null, 200) ?? $product->name;
        } elseif ($kind === OperationEquipmentRequirement::ASSET_CATEGORY) {
            $category = EventOperations::text($data['asset_category'] ?? $current?->asset_category, 60);
            if ($category === null) {
                throw new RuntimeException(__('صنفُ الأصول مطلوب.'));
            }
            $out['asset_category'] = mb_strtolower($category);
            $out['product_id'] = null;
            $out['description'] = EventOperations::text($data['description'] ?? null, 200) ?? Assets::categoryLabel($out['asset_category']);
        } else {
            $out['asset_category'] = null;
            $out['product_id'] = null;
            $description = EventOperations::text($data['description'] ?? $current?->description, 200);
            if ($description === null) {
                throw new RuntimeException(__('وصفُ المتطلَّب مطلوب.'));
            }
            $out['description'] = $description;
        }

        if (array_key_exists('required_quantity', $data) || $current === null) {
            $qty = (int) ($data['required_quantity'] ?? 1);
            if ($qty < 1 || $qty > 100000) {
                throw new RuntimeException(__('الكميّة المطلوبة بين 1 و100000.'));
            }
            $out['required_quantity'] = $qty;
        }
        if (array_key_exists('source', $data) || $current === null) {
            $source = $data['source'] ?? 'owned';
            if (! in_array($source, OperationEquipmentRequirement::SOURCES, true)) {
                throw new RuntimeException(__('مصدرُ المتطلَّب غير معروف.'));
            }
            $out['source'] = $source;
        }
        if (array_key_exists('critical', $data) || $current === null) {
            $out['critical'] = (bool) ($data['critical'] ?? true);
        }
        if (array_key_exists('backup', $data) || $current === null) {
            $out['backup'] = (bool) ($data['backup'] ?? false);
        }
        if (array_key_exists('notes', $data)) {
            $out['notes'] = EventOperations::text($data['notes'], 2000);
        }

        return $out;
    }
}
