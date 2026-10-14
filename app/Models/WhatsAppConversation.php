<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * محادثةُ واتساب — رقمُ عميلٍ واحدٌ في شركةٍ واحدة.
 *
 * والنافذةُ مفتوحةٌ يومًا من آخر ما كتبه العميل: فيه يُجاب نصًّا حرًّا،
 * وبعده لا يُرسَل إلّا قالبٌ معتمَد — انظر `App\Support\WhatsApp\Inbox`.
 */
class WhatsAppConversation extends Model
{
    protected $table = 'whatsapp_conversations';

    protected $guarded = [];

    protected $casts = [
        'last_message_at' => 'datetime',
        'last_inbound_at' => 'datetime',
        'unread_count' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'conversation_id');
    }

    /** هل يُجاب نصًّا حرًّا الآن؟ */
    public function windowOpen(): bool
    {
        return $this->last_inbound_at !== null && $this->last_inbound_at->gt(now()->subDay());
    }
}
