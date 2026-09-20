<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حجزُ قطعةٍ لفعاليّةٍ في نافذةٍ زمنيّة — ثمّ تسليمُها وردُّها.
 *
 * حجزٌ ثمّ تسليمٌ (خرجت من المكتب) ثمّ ردٌّ (عادت وبأيّ حال). وما لم يُردّ
 * يبقى معلَّقًا مهما انتهت الفعاليّة: القطعةُ لا تصير متاحةً لأنّ الوقت مضى.
 */
class AssetReservation extends Model
{
    public const RESERVED = 'reserved';

    public const CHECKED_OUT = 'checked_out';

    public const RETURNED = 'returned';

    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [self::RESERVED, self::CHECKED_OUT, self::RETURNED, self::CANCELLED];

    /** ما يشغل القطعة — يُحسب في التداخل */
    public const OCCUPYING = [self::RESERVED, self::CHECKED_OUT];

    protected $guarded = [];

    protected $casts = [
        'reserved_from' => 'datetime',
        'reserved_until' => 'datetime',
        'checked_out_at' => 'datetime',
        'returned_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(EventOperation::class, 'event_operation_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(OperationEquipmentRequirement::class, 'requirement_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    public function isOccupying(): bool
    {
        return in_array($this->status, self::OCCUPYING, true);
    }

    /** خرجت ولم تعد */
    public function isOutstanding(): bool
    {
        return $this->status === self::CHECKED_OUT;
    }
}
