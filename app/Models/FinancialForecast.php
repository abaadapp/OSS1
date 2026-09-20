<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * توقّعٌ نقديّ — لقطةٌ بافتراضاتها. المرحلة 11.
 *
 * المعتمَدُ لا يُعدَّل: يُقارَن لاحقًا بما وقع فعلًا ليُعرف كم كنّا دقيقين.
 */
class FinancialForecast extends Model
{
    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const SUPERSEDED = 'superseded';

    /** @var list<int> */
    public const HORIZONS = [30, 60, 90, 180, 365];

    protected $guarded = [];

    protected $casts = [
        'base_date' => 'date', 'assumptions' => 'array', 'projection' => 'array', 'totals' => 'array',
        'opening_cash' => 'decimal:3', 'approved_at' => 'datetime', 'superseded_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(FinancialScenario::class, 'scenario_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }
}
