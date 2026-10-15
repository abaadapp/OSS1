<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** نسخةٌ رُفعت لمستند — لا تُمحى حين تأتي أحدثُ منها */
class DocumentRecordFile extends Model
{
    protected $guarded = [];

    public function document(): BelongsTo
    {
        return $this->belongsTo(DocumentRecord::class, 'document_record_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
