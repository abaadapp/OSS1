<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * منظّمٌ على فعاليّة — بتصنيفه فيها ووقته وحاله.
 *
 * وما يشغل وقتَه (`EXPECTED`) هو ما يُسأل عنه التعارض: المعيَّنُ والمؤكِّدُ
 * والحاضر. والغائبُ والملغى لا يحجزان أحدًا — كما في الفريق (`OperationCrewAssignment`).
 */
class OperationOrganizer extends Model
{
    public const ASSIGNED = 'assigned';

    public const CONFIRMED = 'confirmed';

    public const ATTENDED = 'attended';

    public const ABSENT = 'absent';

    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [self::ASSIGNED, self::CONFIRMED, self::ATTENDED, self::ABSENT, self::CANCELLED];

    /** @var list<string> */
    public const EXPECTED = [self::ASSIGNED, self::CONFIRMED, self::ATTENDED];

    protected $guarded = [];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime'];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(EventOperation::class, 'event_operation_id');
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(OrganizerCategory::class, 'organizer_category_id');
    }

    public function isExpected(): bool
    {
        return in_array($this->status, self::EXPECTED, true);
    }
}
