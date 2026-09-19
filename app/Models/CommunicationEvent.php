<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * خبرٌ من المزوّد عن رسالة — وصلت، قُرئت، ارتدّت، فشلت.
 *
 * ولكلّ خبرٍ معرّفٌ عند المزوّد (`event_id`)، والقيدُ الفريد عليه يجعل
 * النداءَ المكرَّر يُردّ بلا أثر — انظر `Communications::applyEvent`.
 */
class CommunicationEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class);
    }
}
