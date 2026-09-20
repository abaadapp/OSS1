<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** قرارٌ إداريّ مسجَّل في مراجعة — النظامُ يسجّله ولا يتّخذه */
class ManagementDecision extends Model
{
    public const OPEN = 'open';

    public const DONE = 'done';

    public const DROPPED = 'dropped';

    /** @var list<string> */
    public const STATUSES = [self::OPEN, self::DONE, self::DROPPED];

    protected $guarded = [];

    protected $casts = ['due_at' => 'date', 'completed_at' => 'datetime'];

    public function review(): BelongsTo
    {
        return $this->belongsTo(ManagementReview::class, 'management_review_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
