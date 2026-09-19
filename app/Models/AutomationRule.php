<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * قاعدةُ أتمتة — ما قرّرته الشركةُ أن يُتابَع وحده، وكيف.
 *
 * ═══ لا كودَ فيها ═══
 *
 * المُطلِقُ اسمٌ من قائمة `Automation\Triggers`، والخطواتُ أرقامُ أيّامٍ
 * وجمهورٌ من قائمة `Automation\Audience`، والقالبُ مفتاحٌ من كتالوج
 * المرحلة 6. ولا شرطَ يُكتب ولا تعبيرَ يُنفَّذ: المحرّكُ يقرأ القيمَ ويفعل
 * ما يعرفه.
 *
 * ═══ وضعان ═══
 *
 * `observe` يسجّل ما كان سيفعل ولا يفعل؛ و`live` يفعل. والفعليُّ هو أضيقُ
 * وضعَي الشركةِ والقاعدة — انظر `Automation\Settings::effectiveMode`.
 */
class AutomationRule extends Model
{
    public const INTERNAL = 'internal';

    public const EXTERNAL = 'external';

    public const OBSERVE = 'observe';

    public const LIVE = 'live';

    public const MODES = [self::OBSERVE, self::LIVE];

    public const CHANNELS = ['internal', 'email', 'whatsapp'];

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
        'business_hours' => 'boolean',
        'retry_once' => 'boolean',
        'steps' => 'array',
        'recipients' => 'array',
        'metadata' => 'array',
        'last_run_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    public function isExternal(): bool
    {
        return $this->kind === self::EXTERNAL;
    }

    /** @return list<array{offset: int, audience: string}> */
    public function steps(): array
    {
        return collect($this->steps ?? [])
            ->filter(fn ($s) => is_array($s) && isset($s['offset'], $s['audience']))
            ->map(fn ($s) => ['offset' => (int) $s['offset'], 'audience' => (string) $s['audience']])
            ->values()->all();
    }
}
