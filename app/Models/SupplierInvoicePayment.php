<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * دفعةٌ واحدة على سند مورّد — مستندُها وإيصالُها.
 *
 * المبلغُ والتاريخُ نسخةُ ما في قيدها (`journal_entry_id`)، والقيدُ هو الحقيقة:
 * يُكتبان معًا في `SupplierInvoices::settle` تحت قفل السند، فلا دفعةَ بلا قيد ولا
 * قيدَ سدادٍ جديد بلا دفعة. والإيصالُ على القرص الخاصّ ولا يُفتح إلّا من
 * `FinancialAttachmentController::supplierInvoicePayment`.
 */
class SupplierInvoicePayment extends Model
{
    public const CASH = 'cash';

    public const BANK = 'bank';

    /** @var list<string> */
    public const SOURCES = [self::CASH, self::BANK];

    /** @var list<string> صيغُ الإيصال — سياسةُ المرفقات الماليّة نفسُها */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf', 'webp', 'heic'];

    /**
     * @var list<string> وما يُقرأ من محتوى الملفّ — فالامتدادُ اسمٌ يكتبه المُرسِل.
     *
     * و`heif` أخو `heic`: الصورةُ نفسُها يُسمّيها كاشفُ المحتوى بأيٍّ منهما.
     */
    public const CONTENT = ['jpg', 'jpeg', 'png', 'pdf', 'webp', 'heic', 'heif'];

    public const MAX_KB = 10240;

    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:3', 'paid_at' => 'date'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
