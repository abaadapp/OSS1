<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * عرضُ سعر — وعدٌ بثمنٍ، لا التزامٌ ماليّ.
 *
 * ولا يكتب قيدًا ولا يُنشئ ذمّةً ولا يدخل إقرارًا: هو مستندٌ تجاريّ يخرج
 * إلى العميل ويُقبل أو يُردّ. والإيرادُ يقع يومَ تُصدَر الفاتورة لا يومَ
 * يُقبل العرض — انظر `App\Support\Revenue`.
 */
class Quotation extends Model
{
    /** تُكتب وتُعدَّل بحرّية — ولا رقمَ لها بعد */
    public const DRAFT = 'draft';

    /** خصمٌ تجاوز حدَّ كاتبه — لا تخرج حتى تُعتمد */
    public const PENDING_APPROVAL = 'pending_approval';

    /** خرجت من الشركة — **مجمَّدة**. لا تتغيّر إلّا بتنقيح */
    public const SENT = 'sent';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    /** أُلغي داخليًّا قبل أن يُقرَّر فيه */
    public const CANCELLED = 'cancelled';

    /** حلّ محلَّه تنقيحٌ أحدث */
    public const SUPERSEDED = 'superseded';

    /**
     * الحالاتُ السبع المخزَّنة — مصدرٌ واحد تقرأ منه الشاشةُ والتحقّق.
     *
     * وما ليس فيها لا يُخزَّن: «منتهية» و«لم تُفتح» و«تحتاج متابعة»
     * تُشتقّ — انظر `presentedState()`.
     *
     * @var list<string>
     */
    public const STATUSES = [
        self::DRAFT, self::PENDING_APPROVAL, self::SENT,
        self::ACCEPTED, self::REJECTED, self::CANCELLED, self::SUPERSEDED,
    ];

    /**
     * ما خرج من الشركة — فلا يتغيّر محتواه التجاريّ بصمت.
     *
     * والسبيلُ الوحيد إلى تغييره تنقيحٌ جديد يحفظ سابقَه. انظر
     * `Quotations::revise`.
     *
     * @var list<string>
     */
    public const FROZEN = [
        self::SENT, self::ACCEPTED, self::REJECTED,
        self::CANCELLED, self::SUPERSEDED,
    ];

    protected $guarded = [];

    protected $casts = [
        'document_snapshot' => 'array',
        'policy_snapshot' => 'array',
        'issued_at' => 'date',
        'valid_until' => 'date',
        'event_starts_at' => 'date',
        'event_ends_at' => 'date',
        'approved_at' => 'datetime',
        'sent_at' => 'datetime',
        'first_viewed_at' => 'datetime',
        'last_viewed_at' => 'datetime',
        'decided_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'view_count' => 'integer',
        'revision' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** من اختير من أشخاص اتصال الجهة — مرجعٌ، والأسماءُ لقطةٌ في المستند */
    public function customerContact(): BelongsTo
    {
        return $this->belongsTo(CustomerContact::class);
    }

    /**
     * بنودُه بترتيبها المكتوب.
     *
     * و`sort_order` ثمّ `id`: PostgreSQL لا يضمن ترتيبًا بلا `ORDER BY`،
     * وSQLite يردّ بترتيب الإدخال فتبدو مرتّبةً بلا طلب. فورقةٌ تُطبع
     * ببنودٍ مبعثرة ولا خطأَ يقول شيئًا — انظر `CustomerInvoice::items`.
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /** جذرُ السلسلة — ونفسُه إن كان هو الأصل */
    public function root(): BelongsTo
    {
        return $this->belongsTo(self::class, 'root_id');
    }

    /** تنقيحاتُ هذه السلسلة كلُّها — الأصلُ ليس منها */
    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'root_id')->orderBy('revision');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * قرارُ العميل من رابطه — دليلٌ يُكتب مرّةً. انظر `DocumentApproval`.
     *
     * وnull لما قرّره موظّفٌ نيابةً: ذاك خبرٌ في `decided_by_name` لا دليل.
     */
    public function approval(): MorphOne
    {
        return $this->morphOne(DocumentApproval::class, 'approvable');
    }

    /** ما خالفه هذا العرضُ من سياسات الشركة — وسببُ كلٍّ ومن اعتمده */
    public function policyExceptions(): MorphMany
    {
        return $this->morphMany(PolicyException::class, 'exceptionable')->orderBy('id');
    }

    /** معرّفُ جذر السلسلة — ونفسُه إن كان الأصل */
    public function rootId(): int
    {
        return (int) ($this->root_id ?? $this->id);
    }

    /** أهو مجمَّد؟ — فلا يُعدَّل محتواه التجاريّ */
    public function isFrozen(): bool
    {
        return in_array($this->status, self::FROZEN, true);
    }

    /** أانقضت صلاحيّتُه؟ — يُشتقّ من التاريخ ولا يُخزَّن */
    public function isExpired(): bool
    {
        return $this->status === self::SENT
            && $this->valid_until !== null
            && $this->valid_until->endOfDay()->isPast();
    }

    /**
     * الحالُ كما تُعرض — لا كما تُخزَّن.
     *
     * و«منتهية» محسوبةٌ من التاريخ لا حالةٌ في عمود: حالةٌ تُخزَّن تحتاج
     * مهمّةً ليليّة تُحدّثها، وأوّلُ ليلةٍ تسقط فيها تجعل الشاشة تقول
     * «بانتظار العميل» عن عرضٍ انتهى منذ شهر. وهي القاعدةُ نفسُها في
     * `CustomerInvoice::paymentState()`.
     */
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

    /** كم يومًا بقي في صلاحيّته — وسالبٌ إن انقضت، وnull إن لم تُكتب */
    public function daysLeft(): ?int
    {
        if ($this->valid_until === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->valid_until->startOfDay(), absolute: false);
    }

    /**
     * أيقبل قرارًا من العميل الآن؟ — أُرسل ولم تنقضِ صلاحيّتُه ولم يُقرَّر.
     *
     * والصفحةُ العامّة تقرأ هذا وحدَه لترسم زرَّي القرار أو تُخفيهما،
     * و`Quotations::decide` يقرؤه ثانيةً تحت قفلٍ قبل أن يكتب.
     */
    public function isDecidable(): bool
    {
        return $this->status === self::SENT && ! $this->isExpired();
    }

    /** ما رُبح أو خُصم عن سعر الكتالوج — مجموعُ خصوم بنوده */
    public function discountFromList(): float
    {
        return round((float) $this->items->sum('discount'), 3);
    }

    /** هل على أحد بنوده خصمٌ ينتظر اعتمادًا؟ */
    public function scopeAwaitingApproval($query)
    {
        return $query->where('status', self::PENDING_APPROVAL);
    }

    /** ما خرج ولم يُقرَّر فيه بعد ولم تنقضِ صلاحيّتُه */
    public function scopeAwaitingClient($query)
    {
        return $query->where('status', self::SENT)
            ->where(fn ($q) => $q->whereNull('valid_until')
                ->orWhereDate('valid_until', '>=', now()->toDateString()));
    }

    /** ما خرج وانقضت صلاحيّتُه بلا قرار */
    public function scopeExpired($query)
    {
        return $query->where('status', self::SENT)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', now()->toDateString());
    }

    /**
     * ترشيحٌ بالحال المعروضة — بالقاعدة، وبالقواعد التي تقرأها الشاشة.
     *
     * ولا يُرشَّح بعد الجلب: صفحةٌ من عشرين صفًّا من أربعمئة تقول «لا
     * منتهية» ولها عشرون في الصفحة الثالثة — وهو العطبُ الذي عولج في
     * `CustomerInvoice::scopePaymentState`.
     */
    public function scopePresented($query, string $state)
    {
        return match ($state) {
            'expired' => $query->expired(),
            'awaiting_client' => $query->awaitingClient(),
            'unopened' => $query->where('status', self::SENT)->whereNull('first_viewed_at'),
            default => in_array($state, self::STATUSES, true)
                ? $query->where('status', $state)
                // وحالٌ لا تُعرف لا تُرجع الجدولَ كلَّه — تُرجع لا شيء
                : $query->whereRaw('1 = 0'),
        };
    }
}
