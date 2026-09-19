<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * متطلَّبُ مشروع — شرطٌ يجب أن يصدق عند التسليم. بلا سعرٍ ولا كميّة.
 *
 * ═══ ثابتٌ بعد التثبيت ═══
 *
 * قبل `requirements_baselined_at` يُكتب ويُعدَّل بحرّية. وبعده يُجمَّد: لا
 * يُعدَّل ولا يُحذف — يُضاف بأمر تغييرٍ (`change_order_id`) أو يُزال بأمر
 * تغييرٍ (`removed_by_change_order_id`). والصفُّ يبقى.
 *
 * ═══ و«قائمٌ» يُشتقّ من حال الأمر ═══
 *
 * ما أضافه أمرٌ لم يُقبل بعدُ مقترَحٌ لا نطاق، وما أزاله أمرٌ لم يُقبل
 * بعدُ ما زال قائمًا. فالحيُّ: أصلٌ أو أضافه أمرٌ **مقبول**، ولم يُزله أمرٌ
 * **مقبول** — انظر `scopeActive`.
 */
class ProjectRequirement extends Model
{
    protected $guarded = [];

    protected $casts = ['sort_order' => 'integer'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ProjectItem::class, 'project_item_id');
    }

    /** الأمرُ الذي أضافه — وnull لما كان في الأصل */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(ChangeOrder::class, 'change_order_id');
    }

    /** الأمرُ الذي أزاله — وnull لما لم يُزَل */
    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(ChangeOrder::class, 'removed_by_change_order_id');
    }

    /** أمن الأصل المثبَّت؟ */
    public function isBaseline(): bool
    {
        return $this->change_order_id === null;
    }

    /** أهو في النطاق الآن؟ — بحسب حال أوامر التغيير لا بعمودٍ هنا */
    public function isActive(): bool
    {
        $this->loadMissing('addedBy', 'removedBy');

        $added = $this->addedBy === null || $this->addedBy->status === ChangeOrder::ACCEPTED;
        $removed = $this->removedBy !== null && $this->removedBy->status === ChangeOrder::ACCEPTED;

        return $added && ! $removed;
    }

    /** ما في النطاق الآن: أصلٌ أو أضافه أمرٌ مقبول، ولم يُزله أمرٌ مقبول */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where(fn ($q) => $q->whereNull('change_order_id')
                ->orWhereHas('addedBy', fn ($c) => $c->where('status', ChangeOrder::ACCEPTED)))
            ->where(fn ($q) => $q->whereNull('removed_by_change_order_id')
                ->orWhereDoesntHave('removedBy', fn ($c) => $c->where('status', ChangeOrder::ACCEPTED)));
    }

    /** الأصلُ المثبَّت — ما لم يُضفه أمرُ تغيير، أُزيل بعدها أم لا */
    public function scopeBaseline(Builder $query): Builder
    {
        return $query->whereNull('change_order_id');
    }
}
