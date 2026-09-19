<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * تنفيذٌ على مجموعة — تجربةٌ جافّة أو استيرادٌ أو تراجع — المرحلة 9.
 *
 * التجربةُ تقرأ وتربط وتفحص وتطابق وتحاكي المالَ **ولا تكتب كيانًا**؛
 * والاستيرادُ يكتب. وكلاهما يترك صفوفَه (`ImportRow`) وحصيلتَه وتقريرَه.
 */
class ImportRun extends Model
{
    public const DRY_RUN = 'dry_run';

    public const IMPORT = 'import';

    public const ROLLBACK = 'rollback';

    public const RUNNING = 'running';

    public const SUCCESS = 'success';

    public const PARTIAL = 'partial';

    public const FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'totals' => 'array',
        'financial' => 'array',
        'findings' => 'array',
        'reconciliation' => 'array',
        'report' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'mapping_version' => 'integer',
    ];

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(ImportDataset::class, 'dataset_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'run_id')->orderBy('row_number');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isDryRun(): bool
    {
        return $this->kind === self::DRY_RUN;
    }
}
