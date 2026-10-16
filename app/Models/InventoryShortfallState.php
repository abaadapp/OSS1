<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * آخرُ نقصِ مخزونٍ نُبّه عنه في مشروع — بصمتُه وبنودُه.
 *
 * ليس حجزًا ولا رصيدًا: التوقّعُ يُحسب (`App\Support\InventoryForecast`)، وهذا
 * ما قيل عنه وحده — لئلّا يُعاد التنبيهُ نفسُه. انظر `ProjectInventoryShortfall`.
 */
class InventoryShortfallState extends Model
{
    protected $guarded = [];

    protected $casts = ['items' => 'array', 'notified_audiences' => 'array', 'notified_at' => 'datetime', 'resolved_at' => 'datetime'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
