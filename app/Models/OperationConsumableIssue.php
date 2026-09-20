<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صرفُ مستهلَكٍ لفعاليّة — ما خرج من الرفّ وما عاد منه. المرحلة 11.
 *
 * والمستهلَكُ = المصروفُ − المُعاد. والتكلفةُ لحظةَ الصرف.
 */
class OperationConsumableIssue extends Model
{
    public const ISSUED = 'issued';

    public const CLOSED = 'closed';

    protected $guarded = [];

    protected $casts = ['unit_cost' => 'decimal:3', 'issued_at' => 'datetime', 'returned_at' => 'datetime'];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(EventOperation::class, 'event_operation_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(OperationEquipmentRequirement::class, 'requirement_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function consumedQuantity(): int
    {
        return max(0, (int) $this->issued_quantity - (int) $this->returned_quantity);
    }

    public function consumedCost(): float
    {
        return round($this->consumedQuantity() * (float) $this->unit_cost, 3);
    }
}
