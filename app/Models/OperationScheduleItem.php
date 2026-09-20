<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** بندٌ في الجدول التشغيليّ للفعاليّة — ماذا يقع ومتى ومن مسؤولُه */
class OperationScheduleItem extends Model
{
    /** @var list<string> */
    public const TYPES = ['logistics', 'setup', 'technical', 'registration', 'briefing', 'live', 'teardown', 'return', 'other'];

    public const PENDING = 'pending';

    public const READY = 'ready';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    public const SKIPPED = 'skipped';

    public const BLOCKED = 'blocked';

    /** @var list<string> */
    public const STATUSES = [self::PENDING, self::READY, self::IN_PROGRESS, self::COMPLETED, self::SKIPPED, self::BLOCKED];

    protected $guarded = [];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'completed_at' => 'datetime'];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(EventOperation::class, 'event_operation_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::SKIPPED], true);
    }
}
