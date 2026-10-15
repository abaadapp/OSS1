<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * المشروع / الفعاليّة — حاويةٌ تشغيليّةٌ وبُعدٌ تحليليّ، لا مستندٌ ماليّ.
 *
 * لا يُقيَّد في الدفتر أبدًا. تُقيَّد مستنداتُه (فاتورةُ العميل، سندُ
 * المورّد، المصروف) وهو يجمعها ويقرؤها — انظر `ProjectFinancials`.
 *
 * و`approved_value` **الأصلُ** لحظةَ القبول: لا يُكتب فيه بعدها. والقيمةُ
 * الحاليّة تُشتقّ منه ومن أوامر التغيير المقبولة — انظر `ProjectScope`.
 */
class Project extends Model
{
    /** أُنشئ يدويًّا قبل قبولٍ — لا شيء يترتّب عليه */
    public const DRAFT = 'draft';

    /** قُبل عرضُه — البنودُ نُسخت والقيمةُ ثُبّتت */
    public const CONFIRMED = 'confirmed';

    public const IN_PROGRESS = 'in_progress';

    public const ON_HOLD = 'on_hold';

    /** أُقفل — لا مستندَ ماليٌّ جديدٌ عليه، والربحيّةُ مجمَّدة */
    public const CLOSED = 'closed';

    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::DRAFT, self::CONFIRMED, self::IN_PROGRESS, self::ON_HOLD, self::CLOSED, self::CANCELLED,
    ];

    /** ما يقبل عملًا عليه: أمرَ تغييرٍ ومهمّةً ومستندًا */
    public const OPEN = [self::CONFIRMED, self::IN_PROGRESS, self::ON_HOLD];

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'quoted_value' => 'decimal:3',
        'approved_value' => 'decimal:3',
        'requirements_baselined_at' => 'datetime',
        'closed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'financials_frozen' => 'array',
        'cancellation_policy' => 'array',
        'deposit_waived_at' => 'datetime',
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

    /** العرضُ المعتمَد — الأصلُ التجاريُّ الذي لا يُمسّ */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function baseliner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requirements_baselined_by');
    }

    /** البنودُ بترتيبها — و`sort_order` ثمّ `id` كما في العرض */
    public function items(): HasMany
    {
        return $this->hasMany(ProjectItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /** المتطلّباتُ كلُّها — الحيُّ والمزال، ومن أضاف ومن أزال */
    public function requirements(): HasMany
    {
        return $this->hasMany(ProjectRequirement::class)->orderBy('sort_order')->orderBy('id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class)->orderBy('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class)->orderBy('id');
    }

    public function changeOrders(): HasMany
    {
        return $this->hasMany(ChangeOrder::class)->orderBy('sequence');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(CustomerInvoice::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function supplierInvoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    public function stockAdjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class);
    }

    public function policyExceptions(): MorphMany
    {
        return $this->morphMany(PolicyException::class, 'exceptionable')->orderBy('id');
    }

    /** حزمُ التسليم — ما خرج للعميل من مخرجاته، وما قَبِله (المرحلة 5) */
    public function deliveryPackages(): HasMany
    {
        return $this->hasMany(DeliveryPackage::class)->orderBy('id');
    }

    /** نسخُ نطاق العمل — الأحدثُ أخيرًا، وما وُقّع منها لا يُكتب فوقه */
    public function sowRevisions(): HasMany
    {
        return $this->hasMany(ScopeOfWork::class)->orderBy('revision');
    }

    /** فاتورةُ رسم الإلغاء — واحدةٌ إن لزمت */
    public function cancellationFeeInvoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'cancellation_fee_invoice_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }

    /** أثُبّتت متطلّباتُه؟ — فما كان منها أصلًا لا يُمسّ بعدها إلّا بأمر تغيير */
    public function isBaselined(): bool
    {
        return $this->requirements_baselined_at !== null;
    }

    /**
     * المفوتَر على هذا المشروع — الفواتيرُ الصادرة وحدها.
     *
     * ويُقارَن بالقيمة المعتمَدة **الحاليّة** لا بالأصل: مشروعٌ زِيد نطاقُه
     * بأمر تغييرٍ يُفوتَر أكثر ممّا اعتُمد أصلًا، وهذا مشروع — §7.
     */
    public function invoicedTotal(): float
    {
        return round((float) $this->invoices()->where('status', CustomerInvoice::ISSUED)->sum('total'), 3);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN);
    }
}
