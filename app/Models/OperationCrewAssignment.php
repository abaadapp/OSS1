<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * من يعمل في هذه الفعاليّة — موظّفٌ بحسابه أو متعاقدٌ باسمه وهاتفه.
 *
 * ولا يُخترع حسابٌ لمتعاقدٍ ليومٍ واحد: `user_id` فارغٌ و`external_name`
 * مكتوب. وليست `ProjectMember`: تلك فريقُ المشروع بلا وقت، وهذه من يقف
 * عند الطاولة من الثامنة إلى الرابعة.
 */
class OperationCrewAssignment extends Model
{
    /** @var list<string> */
    public const ROLES = [
        'operations_lead', 'registration_lead', 'registration_staff', 'technical_support', 'printer_operator',
        'scanner_operator', 'runner', 'supervisor', 'photographer', 'external_contractor', 'other',
    ];

    public const PLANNED = 'planned';

    public const CONFIRMED = 'confirmed';

    public const CHECKED_IN = 'checked_in';

    public const CHECKED_OUT = 'checked_out';

    public const NO_SHOW = 'no_show';

    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [self::PLANNED, self::CONFIRMED, self::CHECKED_IN, self::CHECKED_OUT, self::NO_SHOW, self::CANCELLED];

    /** ما يُعدّ في الفريق — يشغل وقتَ صاحبه */
    public const EXPECTED = [self::PLANNED, self::CONFIRMED, self::CHECKED_IN, self::CHECKED_OUT];

    public const BRIEFING_PENDING = 'pending';

    public const BRIEFED = 'briefed';

    public const BRIEFING_NOT_REQUIRED = 'not_required';

    /** @var list<string> */
    public const BRIEFING_STATUSES = [self::BRIEFING_PENDING, self::BRIEFED, self::BRIEFING_NOT_REQUIRED];

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'checked_in_at' => 'datetime',
        'checked_out_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(EventOperation::class, 'event_operation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExternal(): bool
    {
        return $this->user_id === null;
    }

    public function isExpected(): bool
    {
        return in_array($this->status, self::EXPECTED, true);
    }

    public function displayName(): string
    {
        return $this->user?->name ?? (string) $this->external_name;
    }
}
