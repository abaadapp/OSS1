<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * أمرُ تغيير — تعديلٌ على نطاقٍ اعتمده العميل، يقبله العميل.
 *
 * ═══ ليس مستندًا ماليًّا ═══
 *
 * لا يُصدر فاتورةً ولا إشعارَ دائنٍ ولا قيدًا. يحمل **فروقًا** على ما
 * اعتُمد: بنودٌ تزيد أو تنقص، ومتطلّباتٌ تُضاف أو تُزال، وموعدٌ جديد.
 * والعرضُ المقبول لا يُمسّ حرفًا — هو الأصل، وهذا ما تغيّر عليه.
 *
 * ═══ ومرآةُ العرض في دورته ═══
 *
 * يُكتب، ويُعتمد إن تجاوز خصمٌ حدَّه أو خُولفت سياسة، ويُرسَل فيُختم ويُبصَم
 * ويُقطع رقمُه، ويُقبل من الرابط أو يُسجَّل قبولُه — والقبولُ **يُطبَّق**
 * في اللحظة نفسِها من بابٍ واحد: `ChangeOrders::apply`.
 */
class ChangeOrder extends Model
{
    public const DRAFT = 'draft';

    public const PENDING_APPROVAL = 'pending_approval';

    /** خرج إلى العميل — مجمَّد */
    public const SENT = 'sent';

    /** قَبِله العميل — وطُبّق */
    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::DRAFT, self::PENDING_APPROVAL, self::SENT,
        self::ACCEPTED, self::DECLINED, self::CANCELLED,
    ];

    /** ما خرج — فلا يتغيّر محتواه */
    public const FROZEN = [self::SENT, self::ACCEPTED, self::DECLINED, self::CANCELLED];

    public const BY_CUSTOMER = 'customer';

    public const BY_COMPANY = 'company';

    /** @var list<string> */
    public const PARTIES = [self::BY_CUSTOMER, self::BY_COMPANY];

    protected $guarded = [];

    protected $casts = [
        'document_snapshot' => 'array',
        'policy_snapshot' => 'array',
        'new_starts_at' => 'date',
        'new_ends_at' => 'date',
        'valid_until' => 'date',
        'subtotal_delta' => 'decimal:3',
        'discount_delta' => 'decimal:3',
        'tax_delta' => 'decimal:3',
        'total_delta' => 'decimal:3',
        'approved_at' => 'datetime',
        'sent_at' => 'datetime',
        'first_viewed_at' => 'datetime',
        'last_viewed_at' => 'datetime',
        'decided_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'view_count' => 'integer',
        'sequence' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChangeOrderItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /** ما يقترح إضافتَه من متطلّبات */
    public function addedRequirements(): HasMany
    {
        return $this->hasMany(ProjectRequirement::class, 'change_order_id')->orderBy('sort_order')->orderBy('id');
    }

    /** ما يقترح إزالتَه من متطلّبات */
    public function removedRequirements(): HasMany
    {
        return $this->hasMany(ProjectRequirement::class, 'removed_by_change_order_id')->orderBy('sort_order')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** قرارُ العميل من رابطه — الصفُّ نفسُه الذي يحمل قرارَه على العرض */
    public function approval(): MorphOne
    {
        return $this->morphOne(DocumentApproval::class, 'approvable');
    }

    public function policyExceptions(): MorphMany
    {
        return $this->morphMany(PolicyException::class, 'exceptionable')->orderBy('id');
    }

    public function isFrozen(): bool
    {
        return in_array($this->status, self::FROZEN, true);
    }

    public function isExpired(): bool
    {
        return $this->status === self::SENT
            && $this->valid_until !== null
            && $this->valid_until->endOfDay()->isPast();
    }

    /** أيقبل قرارًا الآن؟ — أُرسل ولم تنقضِ صلاحيّتُه */
    public function isDecidable(): bool
    {
        return $this->status === self::SENT && ! $this->isExpired();
    }

    /** الحالُ كما تُعرض — و«منتهٍ» يُشتقّ كما في العرض */
    public function presentedState(): string
    {
        if ($this->status !== self::SENT) {
            return $this->status;
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        return $this->first_viewed_at === null ? 'unopened' : 'awaiting_client';
    }

    /** أيحمل بنودًا؟ — فهو تغييرٌ تجاريّ */
    public function hasItems(): bool
    {
        return $this->relationLoaded('items') ? $this->items->isNotEmpty() : $this->items()->exists();
    }

    /** أيحمل موعدًا جديدًا؟ — فهو تأجيلٌ أو تقديم */
    public function hasDates(): bool
    {
        return $this->new_starts_at !== null || $this->new_ends_at !== null;
    }

    /** أيمسّ المتطلّبات؟ */
    public function hasRequirements(): bool
    {
        return $this->addedRequirements()->exists() || $this->removedRequirements()->exists();
    }

    /** أهو فارغٌ من كلّ تغيير؟ — فلا يُرسَل */
    public function isEmpty(): bool
    {
        return ! $this->hasItems() && ! $this->hasDates() && ! $this->hasRequirements();
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', self::ACCEPTED);
    }
}
