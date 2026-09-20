<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * قطعةُ معدّاتٍ ذاتُ هويّة — حاسوبٌ بعينه، طابعةٌ برقمها التسلسليّ.
 *
 * ليست مخزونًا ولا أصلًا محاسبيًّا: المخزونُ كمّيّاتٌ بلا هويّة (`products`)،
 * ودفترُ الإهلاك تكلفةٌ وعمر (`fixed_assets`). وهذه «أين هذه القطعة الآن
 * وبأيّ حال ومع من». وقد تشير إلى صفّ الإهلاك ولا تحلّ محلّه.
 */
class Asset extends Model
{
    public const AVAILABLE = 'available';

    public const RESERVED = 'reserved';

    public const DEPLOYED = 'deployed';

    public const MAINTENANCE = 'maintenance';

    public const DAMAGED = 'damaged';

    public const LOST = 'lost';

    public const RETIRED = 'retired';

    /** @var list<string> */
    public const STATUSES = [self::AVAILABLE, self::RESERVED, self::DEPLOYED, self::MAINTENANCE, self::DAMAGED, self::LOST, self::RETIRED];

    /** ما لا يُحجز ولا يُسلَّم — حتى تُعاد إلى الخدمة بيد */
    public const OUT_OF_SERVICE = [self::MAINTENANCE, self::DAMAGED, self::LOST, self::RETIRED];

    /** @var list<string> */
    public const CONDITIONS = ['excellent', 'good', 'fair', 'damaged'];

    protected $guarded = [];

    protected $casts = [
        'purchase_date' => 'date',
        'purchase_cost' => 'decimal:3',
        'metadata' => 'array',
        'retired_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(AssetReservation::class)->orderBy('reserved_from');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(OperationIncident::class)->orderByDesc('occurred_at');
    }

    public function isOutOfService(): bool
    {
        return in_array($this->status, self::OUT_OF_SERVICE, true);
    }

    public function label(): string
    {
        return $this->asset_code.' — '.$this->name;
    }
}
