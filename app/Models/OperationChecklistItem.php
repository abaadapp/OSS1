<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بندُ تحقّق — يُنجَز أو يُعطَّل أو يُعفى بسبب.
 *
 * والإعفاءُ (`waived`) ليس إنجازًا: بندٌ إلزاميٌّ قيل عنه «نمضي بدونه» بيدٍ
 * مأذونة وسببٍ مكتوب — يُقرأ في الجاهزيّة تحذيرًا لا مانعًا.
 */
class OperationChecklistItem extends Model
{
    public const PENDING = 'pending';

    public const DONE = 'done';

    public const BLOCKED = 'blocked';

    public const WAIVED = 'waived';

    /** @var list<string> */
    public const STATUSES = [self::PENDING, self::DONE, self::BLOCKED, self::WAIVED];

    protected $guarded = [];

    protected $casts = ['required' => 'boolean', 'due_at' => 'datetime', 'completed_at' => 'datetime'];

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(OperationChecklist::class, 'checklist_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /** مُنجَزٌ أو مُعفًى — لا يقف في طريق شيء */
    public function isSettled(): bool
    {
        return in_array($this->status, [self::DONE, self::WAIVED], true);
    }
}
