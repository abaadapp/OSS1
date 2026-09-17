<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * استثناءٌ من سياسة — ما رصده النظام، وسببُ الموظّف، ومن اعتمده.
 *
 * وبلا `status`: «ينتظر» هي `approved_at IS NULL`. وعرضٌ عليه استثناءٌ
 * ينتظر لا يخرج إلى العميل — انظر `Quotations::send`.
 */
class PolicyException extends Model
{
    protected $guarded = [];

    protected $casts = [
        'detected' => 'array',
        'approved_at' => 'datetime',
        'policy_version' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function exceptionable(): MorphTo
    {
        return $this->morphTo();
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(CompanyPolicy::class, 'company_policy_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function scopePending($query)
    {
        return $query->whereNull('approved_at');
    }
}
