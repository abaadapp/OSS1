<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ما تحتاجه الفعاليّة من معدّات — قبل تعيين القطع بعينها.
 *
 * «٤ حواسيب» متطلَّبٌ؛ و«AST-000012» حجز. والفرقُ بين «ماذا نحتاج» و«أيَّ
 * قطعٍ نأخذ». وقد يشير إلى صنفِ أصولٍ (قطعٌ ذاتُ هويّة)، أو إلى منتجٍ من
 * المخزون (بطاقاتٌ وحبال)، أو نصٍّ حرٍّ لما يُستأجر أو يجيء من العميل.
 */
class OperationEquipmentRequirement extends Model
{
    public const ASSET_CATEGORY = 'asset_category';

    public const PRODUCT = 'product';

    public const EXTERNAL = 'external';

    /** @var list<string> */
    public const KINDS = [self::ASSET_CATEGORY, self::PRODUCT, self::EXTERNAL];

    /** @var list<string> */
    public const SOURCES = ['owned', 'inventory', 'rented', 'client', 'third_party'];

    protected $guarded = [];

    protected $casts = ['critical' => 'boolean', 'backup' => 'boolean'];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(EventOperation::class, 'event_operation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(AssetReservation::class, 'requirement_id');
    }

    public function shortfall(): int
    {
        return max(0, (int) $this->required_quantity - (int) $this->assigned_quantity);
    }
}
