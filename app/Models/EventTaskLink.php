<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رابطُ موظّفٍ إلى مهامّه في فعاليّة — لا يُخمَّن، وله انتهاء.
 *
 * يُبحث بالبصمة (`token_hash`) لا بالرمز، والرمزُ نفسُه محفوظٌ مشفّرًا ليُعاد
 * بناءُ الرابط نفسِه في كلّ تذكير — فلا يتعطّل زرُّ رسالةٍ سابقة. انظر
 * `App\Support\Operations\EventTasks::link`.
 */
class EventTaskLink extends Model
{
    protected $guarded = [];

    protected $hidden = ['token', 'token_hash'];

    protected $casts = [
        'token' => 'encrypted',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(EventOperation::class, 'event_operation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
