<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * قالبُ رسالةٍ تملكه الشركة — فوق كتالوج القوالب المكتوب في الكود.
 *
 * ═══ ما يُكتب هنا وما لا ═══
 *
 * الكتالوج (`Communication\Templates`) يقول أيُّ رسائلَ يعرفها النظام
 * وبأيّ متغيّرات. وهذا الصفُّ يقول ما قرّرته الشركة في قالبٍ منها: اسمُه
 * عندها، وهل هو فعّال، وللواتساب اسمُ القالب المعتمَد عند Meta ولغتُه
 * وترتيبُ المتغيّرات في خاناته.
 *
 * ═══ والحالُ ═══
 *
 * `active` يُرسَل به · `inactive` أوقفته الشركة · `pending` يُنتظر اعتمادُ
 * Meta له · `rejected` رفضته. ولا يُرسَل واتساب إلّا بقالبٍ فعّالٍ له اسمٌ
 * معتمَد — Meta لا تقبل غيرَه أصلًا.
 */
class CommunicationTemplate extends Model
{
    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public const PENDING = 'pending';

    public const REJECTED = 'rejected';

    public const STATUSES = [self::ACTIVE, self::INACTIVE, self::PENDING, self::REJECTED];

    public const LANGUAGES = ['ar', 'en'];

    protected $guarded = [];

    protected $casts = [
        'variables' => 'array',
        'header_variables' => 'array',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** هل يُرسَل به واتساب؟ — فعّالٌ وله اسمٌ معتمَد */
    public function isSendable(): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        return $this->channel !== Communication::WHATSAPP || filled($this->provider_template);
    }
}
