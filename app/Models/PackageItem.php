<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * عضوٌ في باقة — «٥٠٠ × بطاقة» أو «يومان × فريق ميدانيّ».
 *
 * ولا جدولَ رأسٍ فوقه: الباقةُ منتجٌ في الكتالوج (`products.kind = package`)
 * وهذه صفوفُه. فلا تكون لمنتجٍ باقتان يُسأل أيّهما المعتبَرة.
 *
 * وهو **حزمةٌ تجاريّة تُسعَّر**، لا وصفةً تُخصم من الرفّ — انظر الهجرة
 * `a_package_lists_what_it_contains` لسبب انفصاله عن `recipe_items`.
 */
class PackageItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:3',
        'sort_order' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** الباقةُ نفسُها */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'package_id');
    }

    /** ما تحويه — صنفٌ أو خدمة */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'item_id');
    }
}
