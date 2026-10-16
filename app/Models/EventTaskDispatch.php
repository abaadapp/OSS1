<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ما أُرسل إلى موظّفٍ عن مهامّه في فعاليّة — صفٌّ لكلّ (مُطلِق · خطوة).
 *
 * مفتاحُه الفريد (الشركة · الفعاليّة · الموظّف · المُطلِق · الخطوة) هو منعُ
 * التكرار: عاملان يصلان معًا يكتب أحدُهما. والحالُ ما جرى: أُرسل، أو فشل
 * (القناةُ مطفأة، لا قالب، لا رقم)، أو لم يُرسَل لأنّ لا مهامَّ مفتوحة.
 */
class EventTaskDispatch extends Model
{
    public const SENT = 'sent';

    public const FAILED = 'failed';

    /** نُبّه داخل النظام وحده — القاعدةُ على «تنبيه داخلي» لا واتساب */
    public const NOTIFIED = 'notified';

    public const NOTHING_OPEN = 'nothing_open';

    protected $guarded = [];

    protected $casts = ['sent_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class);
    }
}
