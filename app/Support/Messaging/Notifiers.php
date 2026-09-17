<?php

namespace App\Support\Messaging;

/**
 * القنواتُ المتاحة — بريدٌ وحدَه اليوم.
 *
 * و`for('whatsapp')` يومَ يُبنى يعيد تنفيذًا آخر من هنا، ولا يتغيّر من
 * يناديه: `Quotations::email` تطلب قناةً وترسل نيّةً.
 */
class Notifiers
{
    public static function email(): Notifier
    {
        return new EmailNotifier;
    }
}
