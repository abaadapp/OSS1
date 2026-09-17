<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سياسةٌ من سياسات الشركة — نصٌّ يراه العميل، ومعاملاتٌ يفحصها النظام.
 *
 * والمفتاحُ من قائمةٍ مغلقةٍ في `App\Support\Policy\Registry`: المديرُ يضبط
 * الأيّامَ والنسبةَ والنصَّ، ولا يكتب شرطًا. انظر §18.
 */
class CompanyPolicy extends Model
{
    public const GENERAL = null;

    public const SCOPE_CUSTOMER = 'customer';

    public const SCOPE_PRODUCT = 'product';

    public const SCOPE_CATEGORY = 'category';

    /** @var list<string> */
    public const SCOPES = [self::SCOPE_CUSTOMER, self::SCOPE_PRODUCT, self::SCOPE_CATEGORY];

    protected $guarded = [];

    protected $casts = [
        'params' => 'array',
        'active' => 'boolean',
        'effective_from' => 'date',
        'version' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** أتسري اليوم؟ — فعّالةٌ وبلغ تاريخُ سريانها */
    public function isInForce(): bool
    {
        return $this->active
            && ($this->effective_from === null || ! $this->effective_from->startOfDay()->isFuture());
    }

    /** معاملٌ بقيمته — أو الافتراضيّ */
    public function param(string $name, mixed $default = null): mixed
    {
        return $this->params[$name] ?? $default;
    }

    /** النصُّ بلغة القارئ — والعربيّةُ حين لا إنجليزيّة */
    public function text(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $locale === 'en' && filled($this->text_en) ? (string) $this->text_en : (string) $this->text_ar;
    }

    /** ما يسري منها الآن — من الأحدث سريانًا */
    public function scopeInForce($query)
    {
        return $query->where('active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')
                ->orWhereDate('effective_from', '<=', now()->toDateString()));
    }
}
