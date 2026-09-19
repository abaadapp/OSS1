<?php

namespace App\Support\Messaging;

use Illuminate\Database\Eloquent\Model;

/**
 * ما يُراد إيصالُه — بلا لغةِ قناةٍ بعينها.
 *
 * عنوانٌ ونصٌّ ورابطٌ وزرّ: يصلح بريدًا اليوم، ورسالةَ واتساب غدًا
 * (`templateKey` يُطابَق هناك بقالبٍ معتمَد). ولا Mailable هنا: الحمولةُ
 * التي تعرف أنّها بريدٌ لا تُرسَل من قناةٍ ثانية.
 *
 * ═══ وعمّا تتكلّم — المرحلة 6 ═══
 *
 * `about` هو المستندُ الذي تخصّه الرسالة (عرضٌ، مشروعٌ، فاتورةٌ، حزمةُ
 * تسليم…)، و`variables` قيمُها التي تملأ خاناتِ قالب واتساب بالترتيب.
 * وكلاهما اختياريّ: من يرسل بلا موضوعٍ يُقيَّد إرسالُه بلا موضوع — ولا
 * يُترك بلا قيد. انظر `Communications::record`.
 */
final class MessageIntent
{
    /**
     * @param  list<string>  $paragraphs
     * @param  array<string, string|int|float|null>  $variables
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
        public readonly ?Model $about = null,
        public readonly ?int $customerId = null,
        public readonly ?int $businessId = null,
        public readonly array $variables = [],
        public readonly ?string $language = null,
        public readonly ?string $recipientName = null,
    ) {}
}
