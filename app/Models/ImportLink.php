<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * أثرُ كيانٍ إلى مصدره — «DATA-0001#ROW-42 → Customer#17» — المرحلة 9.
 *
 * صفٌّ لكلّ كيانٍ أنشأه الاستيرادُ أو ربطه. وقيدُه الفريد (المجموعة · الصفّ ·
 * النوع) هو حارسُ التكرار: الاستيرادُ الثاني يجد الأثرَ فلا يكتب ثانيةً.
 */
class ImportLink extends Model
{
    public const CREATED = 'created';

    public const LINKED = 'linked';

    protected $guarded = [];

    protected $casts = ['rolled_back_at' => 'datetime', 'row_number' => 'integer', 'linkable_id' => 'integer'];

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(ImportDataset::class, 'dataset_id');
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
