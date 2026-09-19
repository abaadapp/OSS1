<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بندُ مشروع — الكميّةُ المعتمَدةُ الحاليّة وما سُلِّم منها.
 *
 * يُنسخ من بند العرض يومَ القبول، ولا تتغيّر كميّتُه بعدها إلّا من بابٍ
 * واحد: `ChangeOrders::apply`. والحارسُ: `quantity` = كميّةُ العرض + مجموعُ
 * فروق أوامر التغيير المقبولة — انظر `ProjectScope::verify`.
 */
class ProjectItem extends Model
{
    public const PENDING = 'pending';

    public const IN_PROGRESS = 'in_progress';

    public const DELIVERED = 'delivered';

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:3',
        'tax_rate' => 'decimal:2',
        'line_total' => 'decimal:3',
        'delivered_quantity' => 'decimal:3',
        'sort_order' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** أصلُه في العرض — وnull لما أضافه أمرُ تغيير */
    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class);
    }

    /** الأمرُ الذي أضافه — وnull لما جاء من العرض */
    public function changeOrder(): BelongsTo
    {
        return $this->belongsTo(ChangeOrder::class);
    }

    /** سطرُ الأمر الذي ولّده بعينه — أصلُ كميّته */
    public function changeOrderItem(): BelongsTo
    {
        return $this->belongsTo(ChangeOrderItem::class);
    }

    /** إجماليُّ السطر بكميّته — الصافي بضريبته */
    public static function lineTotal(float $quantity, float $unitPrice, float $taxRate): float
    {
        $net = round($quantity * $unitPrice, 3);

        return round($net + round($net * $taxRate / 100, 3), 3);
    }
}
