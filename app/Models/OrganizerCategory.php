<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** تصنيفُ منظّم — يُدار من الشاشة، لا قائمةٌ مكتوبةٌ في الواجهة */
class OrganizerCategory extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean', 'sort_order' => 'integer'];

    public function organizers(): BelongsToMany
    {
        return $this->belongsToMany(Organizer::class, 'organizer_category_organizer');
    }
}
