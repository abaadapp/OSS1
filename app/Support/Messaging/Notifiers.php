<?php

namespace App\Support\Messaging;

use RuntimeException;

/**
 * القنواتُ المتاحة — بريدٌ وواتساب.
 *
 * ومن يناديها يطلب قناةً ويرسل نيّةً: `Quotations::email` لا تعرف SMTP من
 * Postmark، و`Communications::send` لا تعرف Graph API — كلٌّ خلف بابه.
 */
class Notifiers
{
    public static function email(): Notifier
    {
        return new EmailNotifier;
    }

    public static function whatsapp(): WhatsAppNotifier
    {
        return new WhatsAppNotifier;
    }

    /** القناةُ باسمها — `email` · `whatsapp` */
    public static function for(string $channel): Notifier
    {
        return match ($channel) {
            'email' => self::email(),
            'whatsapp' => self::whatsapp(),
            default => throw new RuntimeException(__('قناةٌ غير معروفة.')),
        };
    }
}
