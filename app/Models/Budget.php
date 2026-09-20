<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * موازنةُ سنةٍ ماليّة — بنسخها. المرحلة 11.
 *
 * المعتمَدةُ لا تُعدَّل: من أراد تغييرَها أنشأ نسخةً منها (`revised_from_id`)
 * فتُعتمد الجديدةُ وتصير القديمةُ «محلولةً محلَّها» — وتبقى مقروءةً كما
 * كانت. وواحدةٌ معتمَدةٌ لكلّ سنة.
 */
class Budget extends Model
{
    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const SUPERSEDED = 'superseded';

    public const ARCHIVED = 'archived';

    /** @var list<string> */
    public const STATUSES = [self::DRAFT, self::APPROVED, self::SUPERSEDED, self::ARCHIVED];

    protected $guarded = [];

    protected $casts = ['approved_at' => 'datetime', 'superseded_at' => 'datetime', 'archived_at' => 'datetime'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class)->orderBy('account_id')->orderBy('month');
    }

    public function revisedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revised_from_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    public function label(): string
    {
        return $this->name.' — v'.$this->version;
    }
}
