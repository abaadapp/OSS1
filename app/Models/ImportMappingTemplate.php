<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * قالبُ ربطٍ يُعاد استعمالُه — «عملاء الإكسل القديم» — المرحلة 9.
 *
 * خريطةٌ (عمودُ مصدر → حقلُ هدفٍ من القائمة المسموحة + تحويلاتٌ مسمّاة)
 * لا كودَ فيها. ورقمُ الإصدار يرتفع مع كلّ تعديل.
 */
class ImportMappingTemplate extends Model
{
    protected $guarded = [];

    protected $casts = ['mapping' => 'array', 'version' => 'integer'];
}
