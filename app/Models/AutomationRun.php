<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * محاولةُ المحرّك على هدفٍ في خطوة — ماذا وجد وماذا قرّر وماذا فعل.
 *
 * `idempotency_key` فريدٌ في القاعدة: قاعدةٌ + هدفٌ + مرساةٌ + خطوةٌ +
 * جمهور. عاملان يقيّمان القاعدةَ نفسَها في اللحظة نفسِها يكتب أحدُهما
 * الصفَّ ويُردّ الآخر — قبل أن يُرسَل شيء.
 */
class AutomationRun extends Model
{
    public const PENDING = 'pending';

    public const EXECUTING = 'executing';

    public const EXECUTED = 'executed';

    public const OBSERVED = 'observed';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::PENDING, self::EXECUTING, self::EXECUTED, self::OBSERVED, self::SKIPPED, self::FAILED, self::CANCELLED];

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'evaluated_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class);
    }

    public function isDone(): bool
    {
        return in_array($this->status, [self::EXECUTED, self::OBSERVED, self::SKIPPED, self::FAILED, self::CANCELLED], true);
    }
}
