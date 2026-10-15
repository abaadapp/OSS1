<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** تصنيفُ مستندٍ داخليّ — سجلٌّ تجاريّ، تأمين، عقد… يُدار من الشاشة */
class DocumentCategory extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    public function documents(): HasMany
    {
        return $this->hasMany(DocumentRecord::class);
    }
}
