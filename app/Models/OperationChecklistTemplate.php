<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** قالبُ قائمة تحقّق — يُستنسخ للفعاليّة ولا يُربط بها */
class OperationChecklistTemplate extends Model
{
    public const PRE_EVENT = 'pre_event';

    public const SETUP = 'setup';

    public const CLOSING = 'closing';

    public const CUSTOM = 'custom';

    /** @var list<string> */
    public const PHASES = [self::PRE_EVENT, self::SETUP, self::CLOSING, self::CUSTOM];

    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    public function items(): HasMany
    {
        return $this->hasMany(OperationChecklistTemplateItem::class, 'template_id')->orderBy('sort_order')->orderBy('id');
    }
}
