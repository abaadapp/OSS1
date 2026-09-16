<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    /*
     * لا زرّ يحذف عميلًا اليوم — وهذا حارسٌ ليوم يُضاف.
     *
     * العميل يحمل نقاط ولاءٍ وعناوين وتاريخ شراء، ولا يجوز أن يُمحى ذلك
     * بضغطةٍ لا رجعة فيها. والمحو النهائي يبقى في مكانٍ واحد: مسح المتجر قبل
     * استعادة نسخةٍ احتياطية (BackupController) — وهناك يُطلب المحو صراحةً.
     */
    use SoftDeletes;

    protected $guarded = [];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * طلباتُه — مفهومٌ متقاعد.
     *
     * لا مسارَ يُنشئ طلبًا منذ رُفعت نقطة البيع، والعلاقةُ باقيةٌ لشاشة
     * «المبيعات» التي تقرأ ما بُذر وحده. وسجلُّ تعامل العميل يُقرأ من
     * `invoices` — انظر `Revenue`.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** فواتيرُه — سجلُّ تعامله مع الشركة */
    public function invoices(): HasMany
    {
        return $this->hasMany(CustomerInvoice::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }
}
