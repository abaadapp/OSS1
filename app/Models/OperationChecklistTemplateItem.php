<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** بندٌ في قالب قائمة تحقّق */
class OperationChecklistTemplateItem extends Model
{
    protected $guarded = [];

    protected $casts = ['required' => 'boolean'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(OperationChecklistTemplate::class, 'template_id');
    }
}
