<?php

namespace App\Models;

use App\Support\Numbering;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

/**
 * قيدٌ في دفتر اليومية.
 *
 * القاعدة الوحيدة التي لا يُتساهل فيها: **لا يُرحَّل قيدٌ غير متوازن**. وهي
 * هنا في النموذج لا في الشاشة، لأن القيود تأتي من ثلاثة أبواب — الشاشة،
 * والترحيل التلقائي من المبيعات والمشتريات، ومسيرة الرواتب — وحارسٌ في بابٍ
 * واحد يترك البابين الآخرين مفتوحين. ودفترٌ يقبل قيدًا مختلًّا لا يُكتشف
 * خلله إلا في ميزان المراجعة بعد شهور، ولا يُعرف حينها أيّ قيدٍ أفسده.
 */
class JournalEntry extends Model
{
    protected $guarded = [];

    protected $casts = [
        'entry_date' => 'date',
        'posted' => 'boolean',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }

    public function lines(): HasMany { return $this->hasMany(JournalLine::class)->orderBy('id'); }

    /** دفعةُ سند المورّد التي كُتب هذا القيدُ لها — إن كان قيدَ سدادٍ بعد جدول الدفعات */
    public function supplierInvoicePayment(): HasOne { return $this->hasOne(SupplierInvoicePayment::class); }

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function sourceable() { return $this->morphTo(); }

    public function totalDebit(): float
    {
        return (float) $this->lines()->sum('debit');
    }

    public function totalCredit(): float
    {
        return (float) $this->lines()->sum('credit');
    }

    /**
     * متوازنٌ ضمن نصف بيسة.
     *
     * المقارنة الحرفية بين عددين عشريّين تفشل على فروقٍ لا وجود لها في المال
     * (0.1 + 0.2 ≠ 0.3)، فيُرفض قيدٌ صحيح. والعملة ثلاث خانات، فما دون
     * النصف بيسة ليس مالًا.
     */
    public function isBalanced(): bool
    {
        return abs($this->totalDebit() - $this->totalCredit()) < 0.0005;
    }

    /**
     * الترحيل — بعده يصير القيد جزءًا من الدفتر ولا يُعدَّل.
     *
     * @throws RuntimeException إن اختلّ التوازن أو نقصت السطور
     */
    public function post(): void
    {
        if ($this->lines()->count() < 2) {
            throw new RuntimeException(__('القيد يحتاج سطرين على الأقل: مدين ودائن'));
        }

        if (! $this->isBalanced()) {
            throw new RuntimeException(__('القيد غير متوازن: المدين :d والدائن :c', [
                'd' => number_format($this->totalDebit(), 3),
                'c' => number_format($this->totalCredit(), 3),
            ]));
        }

        $this->update(['posted' => true, 'posted_at' => now()]);
    }

    /** مرجعٌ متسلسل لكل نشاط — لا عشوائيٌّ يتكرّر */
    /** تحت قفل الشركة — قيدان معًا لا يتقاسمان رقمًا (المرحلة 12) */
    public static function nextNumber(int $businessId): string
    {
        return Numbering::next($businessId, static::class, 'JV-');
    }
}
