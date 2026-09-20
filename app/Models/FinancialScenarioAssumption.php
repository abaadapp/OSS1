<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** افتراضٌ في سيناريو — مفتاحٌ من قائمةٍ مغلقة ومبلغٌ أو نسبة */
class FinancialScenarioAssumption extends Model
{
    /** @var list<string> */
    public const KEYS = [
        'revenue_change_pct', 'collection_delay_days', 'expense_change_pct',
        'one_time_outflow', 'monthly_outflow', 'monthly_inflow', 'expected_contract',
    ];

    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:3', 'percent' => 'decimal:3', 'starts_on' => 'date', 'months' => 'integer'];

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(FinancialScenario::class, 'scenario_id');
    }
}
