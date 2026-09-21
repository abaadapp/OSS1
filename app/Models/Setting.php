<?php

namespace App\Models;

use App\Support\Demo;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $guarded = [];

    /**
     * ما قُرئ من الإعدادات في هذه العمليّة يُمسح مع كلّ كتابة.
     *
     * العملةُ والضريبةُ تُحفظان في الذاكرة بعد أوّل قراءة (المرحلة 12 §57 —
     * كانتا تُقرآن من القاعدة أربعمئة مرّةٍ في شاشةٍ واحدة). وأيُّ صفٍّ يُكتب
     * أو يُمحى يُسقط المحفوظ، فلا تقرأ الشاشةُ ضريبةَ أمس بعد أن غُيّرت.
     */
    protected static function booted(): void
    {
        static::saved(fn () => Demo::flushCurrency());
        static::deleted(fn () => Demo::flushCurrency());
    }
}
