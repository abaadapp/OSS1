<?php

namespace App\Models;

use App\Support\Receivables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** إشعارُ دائن — يُنقص الذمّة ولا يُعيد كتابة الفاتورة */
class CustomerCreditNote extends Model
{
    /** أيُّ كتابةٍ هنا تُسقط ما حفظته الذممُ في الذاكرة — انظر `Receivables::openInvoices` */
    protected static function booted(): void
    {
        static::saved(fn () => Receivables::flush());
        static::deleted(fn () => Receivables::flush());
    }

    protected $guarded = [];

    protected $casts = ['issued_at' => 'date'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }
}
