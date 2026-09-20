<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** قائمةُ تحقّقٍ لفعاليّةٍ بعينها — نسخةٌ من قالبٍ تعيش حياتَها وحدَها */
class OperationChecklist extends Model
{
    protected $guarded = [];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(EventOperation::class, 'event_operation_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(OperationChecklistTemplate::class, 'template_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OperationChecklistItem::class, 'checklist_id')->orderBy('sort_order')->orderBy('id');
    }
}
