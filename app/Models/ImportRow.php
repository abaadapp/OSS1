<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * نتيجةُ صفٍّ واحد في تنفيذٍ واحد — المرحلة 9.
 *
 * ما صار إليه الصفّ (أُنشئ · رُبط · تُخطّي · تعارض · خطأ)، وما قيل فيه —
 * رسائلُ محدودةٌ لا الصفُّ نفسُه: لا تُكتب بياناتُ الأشخاص هنا.
 */
class ImportRow extends Model
{
    public const CREATED = 'created';

    public const LINKED = 'linked';

    public const SKIPPED = 'skipped';

    public const CONFLICT = 'conflict';

    public const ERROR = 'error';

    /** ما ستفعله التجربةُ لو كانت استيرادًا */
    public const WOULD_CREATE = 'would_create';

    public const WOULD_LINK = 'would_link';

    public const WOULD_SKIP = 'would_skip';

    public const EXACT = 'exact';

    public const PROBABLE = 'probable';

    public const NEW = 'new';

    public const MATCH_CONFLICT = 'conflict';

    protected $guarded = [];

    protected $casts = ['messages' => 'array', 'row_number' => 'integer', 'entity_id' => 'integer'];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'run_id');
    }
}
