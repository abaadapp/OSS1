<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بندٌ في عرض سعر — لقطةٌ لا إحالة.
 *
 * ما كُتب فيه هو ما وُعد به العميل: الوصفُ والسعران والضريبة. وتغييرُ
 * الكتالوج بعده لا يعيد كتابة ورقةٍ خرجت.
 */
class QuotationItem extends Model
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

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discount_approved_by');
    }

    /**
     * نسبةُ الخصم عن سعر الكتالوج — **تُشتقّ ولا تُخزَّن**.
     *
     * عمودٌ بالقيمة وعمودٌ بالنسبة يقولان الشيء نفسه ويفترقان عند أوّل
     * تعديلٍ يُحدَّث فيه أحدُهما. والمقسومُ عليه `list_price` لا `unit_price`:
     * «خُصم عشرون بالمئة» تُقاس من السعر المعتمد لا من المعروض.
     */
    public function discountPercent(): float
    {
        $list = round((float) $this->list_price * (float) $this->quantity, 3);

        return $list <= 0 ? 0.0 : round((float) $this->discount / $list * 100, 2);
    }

    /** أبيع فوق سعر الكتالوج؟ — زيادةٌ تحتاج إذنًا كما يحتاجه الخصم */
    public function isOverridden(): bool
    {
        return (float) $this->unit_price > (float) $this->list_price + 0.0005;
    }

    /** أعليه خصم؟ */
    public function isDiscounted(): bool
    {
        return (float) $this->unit_price < (float) $this->list_price - 0.0005;
    }

    /** بندٌ حرٌّ لا يشير إلى الكتالوج — لا سعرَ معتمدًا له فلا خصمَ عليه */
    public function isFreeText(): bool
    {
        return $this->product_id === null;
    }
}
