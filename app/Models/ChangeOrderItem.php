<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بندٌ في أمر تغيير — فرقٌ موقَّعٌ على النطاق المعتمَد.
 *
 * `project_item_id` = تعديلُ كميّةٍ على بندٍ قائم (زيادةٌ أو نقص)، وnull =
 * بندٌ جديد. والنقصُ سالبُ الكميّة، وسعرُه سعرُ البند القائم لا سعرٌ يُرسَل:
 * ما يُرَدّ للعميل يُرَدّ بما دفعه به.
 */
class ChangeOrderItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:3',
        'list_price' => 'decimal:3',
        'unit_price' => 'decimal:3',
        'discount' => 'decimal:3',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:3',
        'line_total' => 'decimal:3',
        'discount_approved_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    public function changeOrder(): BelongsTo
    {
        return $this->belongsTo(ChangeOrder::class);
    }

    public function projectItem(): BelongsTo
    {
        return $this->belongsTo(ProjectItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discount_approved_by');
    }

    /** أنقصٌ على بندٍ قائم؟ */
    public function isReduction(): bool
    {
        return (float) $this->quantity < 0;
    }

    public function isOverridden(): bool
    {
        return ! $this->isReduction() && (float) $this->unit_price > (float) $this->list_price + 0.0005;
    }

    public function isDiscounted(): bool
    {
        return ! $this->isReduction() && (float) $this->unit_price < (float) $this->list_price - 0.0005;
    }

    /** نسبةُ الخصم — تُشتقّ كما في بند العرض */
    public function discountPercent(): float
    {
        $list = round((float) $this->list_price * (float) $this->quantity, 3);

        return $list <= 0 ? 0.0 : round((float) $this->discount / $list * 100, 2);
    }
}
