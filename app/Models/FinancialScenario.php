<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * سيناريو ماليّ — افتراضاتٌ تُطبَّق على التوقّع ولا تمسّ قيدًا. المرحلة 11.
 */
class FinancialScenario extends Model
{
    public const BASE = 'base';

    public const CONSERVATIVE = 'conservative';

    public const GROWTH = 'growth';

    public const CUSTOM = 'custom';

    /** @var list<string> */
    public const KINDS = [self::BASE, self::CONSERVATIVE, self::GROWTH, self::CUSTOM];

    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function assumptions(): HasMany
    {
        return $this->hasMany(FinancialScenarioAssumption::class, 'scenario_id')->orderBy('sort_order')->orderBy('id');
    }
}
