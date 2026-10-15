<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * نطاقُ العمل — المستندُ الذي يوقّعه العميلُ قبل أن يبدأ المال والتنفيذ.
 *
 * ═══ ليس النطاقَ نفسَه ═══
 *
 * النطاقُ الحاليّ يقرؤه `ProjectScope` من العرض المقبول وأوامر التغيير —
 * وهذا ورقةٌ فوقه: البنودُ والمتطلّباتُ والقيمةُ **لقطةٌ** منه
 * (`scope_snapshot`)، ومعها ما لا يقوله العرض: وصفُ التنفيذ، وما يشمله
 * وما لا يشمله، ومسؤوليّاتُ الشركة والعميل.
 *
 * ═══ نسخةٌ لكلّ تعديل ═══
 *
 * مسودّةٌ تُكتب ← جاهزةٌ تُراجَع ← تُرسَل فتُجمَّد وتُبصَم ← يوقّعها العميل
 * أو يطلب تعديلها. وما وُقّع لا يُكتب فوقه: التعديلُ نسخةٌ جديدة تحلّ محلَّه
 * حين تُوقَّع هي — انظر `ScopesOfWork`.
 */
class ScopeOfWork extends Model
{
    protected $table = 'scopes_of_work';

    public const DRAFT = 'draft';

    /** رُوجعت داخليًّا — تنتظر الإرسال */
    public const READY = 'ready';

    /** خرجت إلى العميل — مجمَّدةٌ مبصومة */
    public const SENT = 'sent';

    /** وقّعها العميل — وهي النطاقُ المعتمَد */
    public const SIGNED = 'signed';

    /** طلب العميلُ تعديلَها */
    public const DECLINED = 'declined';

    /** حلّت محلَّها نسخةٌ أحدث — تبقى مقروءةً ولا تُوقَّع */
    public const SUPERSEDED = 'superseded';

    /** @var list<string> */
    public const STATUSES = [self::DRAFT, self::READY, self::SENT, self::SIGNED, self::DECLINED, self::SUPERSEDED];

    /** ما خرج — فلا يتغيّر محتواه */
    public const FROZEN = [self::SENT, self::SIGNED, self::DECLINED, self::SUPERSEDED];

    protected $guarded = [];

    protected $casts = [
        'scope_snapshot' => 'array',
        'document_snapshot' => 'array',
        'show_value' => 'boolean',
        'starts_at' => 'date',
        'ends_at' => 'date',
        'revision' => 'integer',
        'view_count' => 'integer',
        'ready_at' => 'datetime',
        'sent_at' => 'datetime',
        'first_viewed_at' => 'datetime',
        'last_viewed_at' => 'datetime',
        'decided_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** قرارُ العميل من رابطه — الجدولُ نفسُه الذي يحمل قرارَه على العرض وأمر التغيير */
    public function approval(): MorphOne
    {
        return $this->morphOne(DocumentApproval::class, 'approvable');
    }

    public function isEditable(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isFrozen(): bool
    {
        return in_array($this->status, self::FROZEN, true);
    }

    /** أيقبل توقيعًا الآن؟ — خرج ولم يُقرَّر فيه ولم تحلّ محلَّه نسخة */
    public function isDecidable(): bool
    {
        return $this->status === self::SENT;
    }

    /** أيراه العميلُ من رابطه؟ — ما خرج وحدَه، والمسودّةُ لا */
    public function isPubliclyVisible(): bool
    {
        return $this->isFrozen();
    }

    /** الحالُ كما تُعرض — و«فُتح» تُشتقّ من أوّل فتحٍ لا تُكتب */
    public function presentedState(): string
    {
        if ($this->status === self::SENT && $this->first_viewed_at !== null) {
            return 'opened';
        }

        return $this->status;
    }
}
