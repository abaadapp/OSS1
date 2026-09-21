<?php

namespace App\Models;

use App\Support\Receivables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** حصّةُ فاتورةٍ من دفعة */
class CustomerPaymentAllocation extends Model
{
    /** أيُّ كتابةٍ هنا تُسقط ما حفظته الذممُ في الذاكرة — انظر `Receivables::openInvoices` */
    protected static function booted(): void
    {
        static::saved(fn () => Receivables::flush());
        static::deleted(fn () => Receivables::flush());
    }

    protected $guarded = [];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class, 'customer_payment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }
}
