<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * رسالةٌ من الشركة إلى عميل — بريدًا أو واتساب — وما صار إليها.
 *
 * ═══ الحالُ الداخليّة ═══
 *
 * `queued → sent → delivered → read`، و`failed` و`bounced` نهايتان. والمزوّدُ
 * يقول بلغته (Postmark: Delivery/Bounce/Open · Meta: sent/delivered/read/
 * failed) ويُترجَم إلى هذه الستّ في موضعٍ واحد — `Communications::applyEvent`.
 * ولا تتراجع الحالُ: خبرُ «وصلت» بعد «قُرئت» لا يمحو القراءة.
 *
 * ═══ والصفُّ محاولةٌ لا رسالة ═══
 *
 * ما فشل يبقى فاشلًا، والإعادةُ صفٌّ جديدٌ يشير إلى أصله — انظر
 * `Communications::retry`.
 */
class Communication extends Model
{
    public const EMAIL = 'email';

    public const WHATSAPP = 'whatsapp';

    public const CHANNELS = [self::EMAIL, self::WHATSAPP];

    public const OUTBOUND = 'outbound';

    public const INBOUND = 'inbound';

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const READ = 'read';

    public const FAILED = 'failed';

    public const BOUNCED = 'bounced';

    public const STATUSES = [self::QUEUED, self::SENT, self::DELIVERED, self::READ, self::FAILED, self::BOUNCED];

    /** ما لا يُنتظر بعده خبر — ويُعاد إرسالُه بيد */
    public const TERMINAL = [self::FAILED, self::BOUNCED];

    /** ترتيبُ التقدّم: لا تعود الحالُ إلى ما قبلها */
    public const RANK = [
        self::QUEUED => 0, self::SENT => 1, self::DELIVERED => 2, self::READ => 3,
        self::FAILED => 9, self::BOUNCED => 9,
    ];

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** الموضوعُ: عرضٌ أو مشروعٌ أو فاتورةٌ أو حزمةُ تسليم أو أمرُ تغيير أو عميل */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    /** الأصلُ الذي أُعيد إرسالُه — إن كانت هذه إعادة */
    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }

    /** ما أُعيد من هذه — سلسلةُ الإعادة تُقرأ من هنا */
    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'retry_of_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CommunicationEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    /** هل يُعاد إرسالُها؟ — ما فشل أو ارتدّ، ولم تنجح إعادةٌ له بعد */
    public function isRetryable(): bool
    {
        return $this->isTerminal()
            && ! $this->retries()->whereNotIn('status', self::TERMINAL)->exists();
    }
}
