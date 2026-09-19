<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** عضوٌ في فريق مشروع — ودورُه فيه نصٌّ حرّ: «منسّق ميدانيّ» · «مصوّر» */
class ProjectMember extends Model
{
    protected $guarded = [];

    protected $casts = ['assigned_at' => 'datetime'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
