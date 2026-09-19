<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مهمّةٌ في مشروع — عملٌ داخليٌّ لشخصٍ وموعد، **لا نطاق**.
 *
 * تُكتب وتُعدَّل بحرّية في أيّ وقت: هي ما يفعله الفريق، لا ما وافق عليه
 * العميل. وما وافق عليه العميل في `ProjectItem` و`ProjectRequirement`.
 */
class ProjectTask extends Model
{
    public const OPEN = 'open';

    public const DONE = 'done';

    /** @var list<string> */
    public const STATUSES = [self::OPEN, self::DONE];

    /** @var list<string> */
    public const PRIORITIES = ['low', 'normal', 'high'];

    protected $guarded = [];

    protected $casts = ['due_at' => 'date', 'completed_at' => 'datetime'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function isDone(): bool
    {
        return $this->status === self::DONE;
    }
}
