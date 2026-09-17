<?php

namespace App\Models;

use App\Support\Fingerprint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * قرارُ عميلٍ من رابطه — دليلٌ يُكتب مرّةً ولا يُعدَّل.
 *
 * ما يجعله دليلًا لا زرًّا (§19): البصمةُ واللقطةُ التي حُسبت منها،
 * والموقّعُ كما كتب نفسَه، ونصُّ الإقرار، والعنوانُ والمتصفّحُ والوقت.
 * و`verify()` تعيد حسابَ البصمة من اللقطة: دليلٌ لا يُعاد فحصُه شهادة.
 *
 * وهو **قبولٌ إلكترونيٌّ موثَّق** لا توقيعٌ معتمَدٌ قانونًا — يكفي بين
 * شركةٍ وعميلها، ولا يكفي لعقدٍ حكوميٍّ يشترط شهادة. ويومَ يلزم ذلك
 * يُكتب `provider` آخر و`external_id` في الصفّ نفسِه.
 */
class DocumentApproval extends Model
{
    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const INTERNAL = 'internal';

    protected $guarded = [];

    protected $casts = [
        'accepted_snapshot' => 'array',
        'decided_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isAccepted(): bool
    {
        return $this->decision === self::ACCEPTED;
    }

    /**
     * أما زالت البصمةُ تطابق اللقطة؟
     *
     * اللقطةُ هي الحمولةُ التي بُصمت — لا وصفٌ لها. فإن عُدّل صفٌّ في
     * القاعدة بيدٍ بعد القبول افترق الاثنان، وقالت هذه «لا» بلا أن يُسأل
     * أحد.
     */
    public function verify(): bool
    {
        return is_array($this->accepted_snapshot)
            && Fingerprint::matches($this->accepted_snapshot, $this->content_hash);
    }
}
