<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** مراجعةٌ إداريّة لمدّة — لقطةٌ وملاحظاتٌ وقراراتٌ تكتبها الإدارة. المرحلة 11 */
class ManagementReview extends Model
{
    public const DRAFT = 'draft';

    public const REVIEWED = 'reviewed';

    public const CLOSED = 'closed';

    /** @var list<string> */
    public const STATUSES = [self::DRAFT, self::REVIEWED, self::CLOSED];

    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date', 'period_end' => 'date', 'reviewed_at' => 'datetime', 'closed_at' => 'datetime',
        'snapshot' => 'array', 'sections' => 'array',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ManagementDecision::class)->orderBy('id');
    }
}
