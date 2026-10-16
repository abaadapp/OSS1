<?php

namespace App\Models;

use App\Support\DocumentRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * مستندٌ داخليٌّ للشركة — غيرُ أوراق العملاء (`DocumentLink`).
 *
 * والحالُ تُحسب من التاريخ لا تُخزَّن (`status()`): منتهٍ إن مضى انتهاؤه، وقريبُ
 * الانتهاء إن بقي له `DocumentRecords::EXPIRING_DAYS` يومًا فأقلّ، ونشطٌ ما سواهما
 * أو بلا انتهاء. والأرشفةُ وحدها تُخزَّن. والربطُ بمفتاحٍ قصير من قائمةٍ مغلقة
 * (`DocumentRecords::LINKS`) — انظر `linked()`.
 */
class DocumentRecord extends Model
{
    public const ACTIVE = 'active';

    public const EXPIRING = 'expiring';

    public const EXPIRED = 'expired';

    public const ARCHIVED = 'archived';

    /** @var list<string> */
    public const STATUSES = [self::ACTIVE, self::EXPIRING, self::EXPIRED, self::ARCHIVED];

    protected $guarded = [];

    protected $casts = ['issued_at' => 'date', 'expires_at' => 'date', 'archived_at' => 'datetime', 'file_size' => 'integer'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'document_category_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(DocumentRecordFile::class)->orderByDesc('id');
    }

    /** الحالُ اليوم — بتوقيت الشركة */
    public function status(?Carbon $today = null): string
    {
        if ($this->archived_at !== null) {
            return self::ARCHIVED;
        }
        if ($this->expires_at === null) {
            return self::ACTIVE;
        }

        $today ??= DocumentRecords::today((int) $this->business_id);
        $expires = Carbon::parse($this->expires_at->toDateString(), $today->getTimezone());

        return match (true) {
            $expires->lessThan($today) => self::EXPIRED,
            $expires->lessThanOrEqualTo($today->copy()->addDays(DocumentRecords::EXPIRING_DAYS)) => self::EXPIRING,
            default => self::ACTIVE,
        };
    }

    /** ما يُربط به — من القائمة المغلقة وداخل الشركة، أو null للشركة نفسِها */
    public function linked(): ?Model
    {
        $class = DocumentRecords::LINKS[$this->documentable_type ?? ''] ?? null;

        return $class === null || $this->documentable_id === null
            ? null
            : $class::where('business_id', $this->business_id)->find($this->documentable_id);
    }

    /** ما يحتاج إجراءً: غيرُ مؤرشفٍ وانتهى أو قرُب انتهاؤه */
    public function scopeNeedsAction(Builder $query, Carbon $today): Builder
    {
        return $query->whereNull('archived_at')->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', $today->copy()->addDays(DocumentRecords::EXPIRING_DAYS)->toDateString());
    }
}
