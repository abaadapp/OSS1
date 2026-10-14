<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رسالةٌ في محادثة واتساب — واردةٌ من العميل أو صادرةٌ من الشركة.
 *
 * والحالُ حالُ المراسلات نفسُها (`Communication::STATUSES`) وترتيبُها:
 * لا تتراجع — خبرُ «وصلت» بعد «قُرئت» لا يمحو القراءة.
 */
class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    public const MEDIA = ['image', 'video', 'audio', 'document', 'sticker'];

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }

    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class);
    }

    public function hasMedia(): bool
    {
        return in_array($this->type, self::MEDIA, true) && filled($this->media_id);
    }
}
