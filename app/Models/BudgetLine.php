<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** بندُ موازنة — حسابٌ من الشجرة في شهرٍ بمبلغٍ مخطَّط */
class BudgetLine extends Model
{
    public const REVENUE = 'revenue';

    public const EXPENSE = 'expense';

    protected $guarded = [];

    protected $casts = ['planned_amount' => 'decimal:3', 'month' => 'integer'];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
