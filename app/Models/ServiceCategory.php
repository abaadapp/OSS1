<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** خدمةٌ يقدّمها مورّد — صوتيات · تصوير · أمن … كتالوجٌ يُبحث به */
class ServiceCategory extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean', 'sort_order' => 'integer'];

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'service_category_supplier');
    }
}
