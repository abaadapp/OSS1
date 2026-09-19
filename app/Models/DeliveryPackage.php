<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * حزمةُ التسليم — ما تسلّمه الشركةُ رسميًّا للعميل، ويقبله أو يردّه.
 *
 * حاويةُ دليلٍ لا مستندٌ ماليّ: لا قيدَ يقع منها ولا ذمّةَ تنشأ بها.
 * والدورةُ صارمة — انظر `DeliveryPackages` — وما خرج منها إلى العميل
 * يُبصَم ولا يُمسّ.
 */
class DeliveryPackage extends Model
{
    /** تُجمَع بحرّية — بنودٌ تُضاف وتُحذف */
    public const DRAFT = 'draft';

    /** فُحصت وثبتت: بنودٌ وملفّاتٌ حاضرة — ولا تُعدَّل إلّا بإعادتها مسودّة */
    public const READY = 'ready';

    /** خرجت إلى العميل — **مجمَّدة** ومبصومة */
    public const SENT = 'sent';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    /** أُلغيت داخليًّا قبل أن يُقرَّر فيها */
    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::DRAFT, self::READY, self::SENT,
        self::ACCEPTED, self::DECLINED, self::CANCELLED,
    ];

    /**
     * ما خرج من الشركة أو انتهى — لا يتغيّر محتواه بصمت.
     *
     * @var list<string>
     */
    public const FROZEN = [self::SENT, self::ACCEPTED, self::DECLINED, self::CANCELLED];

    /** ما يقبل قرارًا — المرسَلُ وحدَه */
    public const DECIDABLE = [self::SENT];

    protected $guarded = [];

    protected $casts = [
        'document_snapshot' => 'array',
        'prepared_at' => 'datetime',
        'sent_at' => 'datetime',
        'first_viewed_at' => 'datetime',
        'last_viewed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'declined_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'view_count' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /** المشروعُ الذي تُسلَّم مخرجاتُه — والنطاقُ المعتمَد الذي تُقاس عليه الكميّات */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * بنودُها بترتيبها المكتوب — `sort_order` ثمّ `id`.
     *
     * PostgreSQL لا يضمن ترتيبًا بلا `ORDER BY`، والبصمةُ تقرأ البنودَ
     * بترتيبها: ترتيبٌ يتبدّل يجعل حزمةً لم تُمسّ تقول «لا يطابق».
     */
    public function items(): HasMany
    {
        return $this->hasMany(DeliveryPackageItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** الحزمةُ التي حلّت هذه محلَّها — بعد رفضٍ يبقى دليلًا */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
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

    public function isFrozen(): bool
    {
        return in_array($this->status, self::FROZEN, true);
    }

    /** أتقبل قرارًا من العميل الآن؟ */
    public function isDecidable(): bool
    {
        return in_array($this->status, self::DECIDABLE, true);
    }

    /** أقُرّر فيها — قبولًا أو رفضًا؟ */
    public function isDecided(): bool
    {
        return in_array($this->status, [self::ACCEPTED, self::DECLINED], true);
    }

    /** أتُفتح للعميل من رابطها؟ — ما خرج وما قُرّر فيه؛ لا المسودّةُ ولا الملغاة */
    public function isPubliclyVisible(): bool
    {
        return in_array($this->status, [self::SENT, self::ACCEPTED, self::DECLINED], true);
    }
}
