<?php

namespace App\Models;

use App\Support\PurchaseOrderTotals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    /** صنفٌ من الكتالوج يدخل المخزونَ عند اعتماد الاستلام */
    public const PRODUCT = 'product';

    /** خدمةٌ — تصويرٌ أو أمنٌ أو نقل: تُستلم ولا تبلغ الرفّ */
    public const SERVICE = 'service';

    /** بندٌ مخصّص بنصٍّ حرّ — لا صنفَ له */
    public const CUSTOM = 'custom';

    /** @var list<string> */
    public const LINE_KINDS = [self::PRODUCT, self::SERVICE, self::CUSTOM];

    protected $guarded = [];

    protected $casts = [
        'cost' => 'decimal:3',
        'units_per_purchase_unit' => 'decimal:3',
        'line_total' => 'decimal:3',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** الصنفُ من الكتالوج إن كان البندُ عليه — والبندُ الحرّ بلا صنف */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** المتبقّي — بوحدة الشراء كما طُلب، لا بوحدة التخزين */
    public function getRemainingAttribute(): int
    {
        return max(0, (int) $this->quantity - (int) $this->received_quantity);
    }

    /**
     * كم وحدةَ تخزينٍ يعني هذا البند — وهو ما يدخل الرفَّ عند الاعتماد.
     *
     * وبندٌ قديمٌ بلا معامل يُقرأ بواحد: كلُّ ما مضى اشتُري بوحدة التخزين.
     */
    public function getBaseQuantityAttribute(): float
    {
        return PurchaseOrderTotals::baseQuantity(
            (float) $this->quantity,
            (float) $this->units_per_purchase_unit,
        );
    }
}
