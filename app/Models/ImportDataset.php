<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مجموعةُ بياناتٍ تاريخيّة — ملفٌّ واحد برقمٍ لا يتغيّر (`DATA-0001`) — المرحلة 9.
 *
 * ═══ المسارُ واحد ═══
 *
 * مسجَّلة → مقروءة → تحتاج ربطًا → مربوطة → (فشل الفحص |) جاهزة → معتمَدة →
 * تُستورد → مستورَدة → مطابَقة. وتخرج منه إلى «ملغاة» أو «فشلت». ولا
 * انتقالَ إلّا من `Datasets` — الشاشةُ لا تكتب الحالَ.
 *
 * ═══ والملفُّ ليس المعرِّف ═══
 *
 * الملفُّ على القرص الخاصّ ومساره لا يُعرض؛ والبصمةُ (`checksum`) هي ما
 * يُقارَن: ملفٌّ استُبدل بعد الاعتماد يُسقط الاعتمادَ ولو حمل الاسمَ نفسَه.
 */
class ImportDataset extends Model
{
    public const REGISTERED = 'registered';

    public const PROFILED = 'profiled';

    public const NEEDS_MAPPING = 'needs_mapping';

    public const MAPPED = 'mapped';

    public const VALIDATION_FAILED = 'validation_failed';

    /** تجربةٌ بلا أخطاء لكن فيها تكراراتٌ محتمَلة تنتظر قرارًا */
    public const NEEDS_REVIEW = 'needs_review';

    public const READY = 'ready';

    public const APPROVED = 'approved';

    public const IMPORTING = 'importing';

    public const IMPORTED = 'imported';

    public const VERIFIED = 'verified';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::REGISTERED, self::PROFILED, self::NEEDS_MAPPING, self::MAPPED, self::VALIDATION_FAILED, self::NEEDS_REVIEW,
        self::READY, self::APPROVED, self::IMPORTING, self::IMPORTED, self::VERIFIED, self::FAILED, self::CANCELLED,
    ];

    /** ما لم يُستورد بعدُ ويقبل عملًا */
    public const OPEN = [
        self::REGISTERED, self::PROFILED, self::NEEDS_MAPPING, self::MAPPED, self::VALIDATION_FAILED, self::NEEDS_REVIEW, self::READY, self::APPROVED,
    ];

    /** ما انتهى — لا يُعدَّل ربطُه ولا ملفُّه */
    public const CLOSED = [self::IMPORTING, self::IMPORTED, self::VERIFIED, self::FAILED, self::CANCELLED];

    /** استراتيجيّةُ المال: مستنداتٌ كاملة أو رصيدٌ افتتاحيٌّ فقط (§17) */
    public const HISTORICAL = 'historical';

    public const OPENING_BALANCE = 'opening_balance';

    protected $guarded = [];

    protected $casts = [
        'columns' => 'array',
        'profile' => 'array',
        'sample' => 'array',
        'mapping' => 'array',
        'validation' => 'array',
        'resolutions' => 'array',
        'reconciliation' => 'array',
        'rollback' => 'array',
        'metadata' => 'array',
        'approved_at' => 'datetime',
        'imported_at' => 'datetime',
        'verified_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'mapping_version' => 'integer',
        'row_count' => 'integer',
        'column_count' => 'integer',
        'size' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ImportRun::class, 'dataset_id')->orderByDesc('id');
    }

    public function dryRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'dry_run_id');
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'import_run_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(ImportLink::class, 'dataset_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ImportMappingTemplate::class, 'template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isImported(): bool
    {
        return in_array($this->status, [self::IMPORTED, self::VERIFIED], true);
    }

    /** مرجعُ صفٍّ في هذه المجموعة — «DATA-0001#ROW-42» */
    public function sourceRef(int $row): string
    {
        return $this->code.'#ROW-'.$row;
    }
}
