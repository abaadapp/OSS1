<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** حادثٌ في الميدان — طابعةٌ تعطّلت، إنترنت انقطع، موظّفٌ غاب */
class OperationIncident extends Model
{
    /** @var list<string> */
    public const CATEGORIES = [
        'equipment', 'network', 'consumables', 'attendee', 'venue', 'staff', 'damage', 'delay', 'safety', 'other',
    ];

    public const LOW = 'low';

    public const MEDIUM = 'medium';

    public const HIGH = 'high';

    public const CRITICAL = 'critical';

    /** @var list<string> */
    public const SEVERITIES = [self::LOW, self::MEDIUM, self::HIGH, self::CRITICAL];

    public const OPEN = 'open';

    public const INVESTIGATING = 'investigating';

    public const RESOLVED = 'resolved';

    public const CLOSED = 'closed';

    /** @var list<string> */
    public const STATUSES = [self::OPEN, self::INVESTIGATING, self::RESOLVED, self::CLOSED];

    /** ما لم يُحلّ بعد */
    public const UNRESOLVED = [self::OPEN, self::INVESTIGATING];

    protected $guarded = [];

    protected $casts = ['occurred_at' => 'datetime', 'resolved_at' => 'datetime', 'carried_forward' => 'boolean', 'metadata' => 'array'];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(EventOperation::class, 'event_operation_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function isUnresolved(): bool
    {
        return in_array($this->status, self::UNRESOLVED, true);
    }

    public function isSevere(): bool
    {
        return in_array($this->severity, [self::HIGH, self::CRITICAL], true);
    }
}
