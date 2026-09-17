<?php

namespace App\Support\Messaging;

/**
 * ما يُراد إيصالُه — بلا لغةِ قناةٍ بعينها.
 *
 * عنوانٌ ونصٌّ ورابطٌ وزرّ: يصلح بريدًا اليوم، ورسالةَ واتساب غدًا
 * (`templateKey` يُطابَق هناك بقالبٍ معتمَد). ولا Mailable هنا: الحمولةُ
 * التي تعرف أنّها بريدٌ لا تُرسَل من قناةٍ ثانية.
 */
final class MessageIntent
{
    /**
     * @param  list<string>  $paragraphs
     */
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly array $paragraphs,
        public readonly ?string $url = null,
        public readonly ?string $action = null,
        public readonly string $templateKey = 'generic',
        public readonly ?string $senderName = null,
        public readonly ?string $replyTo = null,
        public readonly ?string $footer = null,
    ) {}
}
