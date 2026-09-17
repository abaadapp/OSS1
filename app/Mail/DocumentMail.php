<?php

namespace App\Mail;

use App\Support\Messaging\MessageIntent;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * رسالةُ مستندٍ — رابطُ عرض سعرٍ، أو تذكيرٌ به، أو خبرُ قرارٍ.
 *
 * وقالبٌ واحدٌ لكلّ نيّة: العنوانُ والفقراتُ والزرُّ من `MessageIntent`،
 * فلا تُكتب Mailable لكلّ رسالةٍ جديدة. ولا طابور: من يضغط «أرسل» ينتظر
 * أمام الشاشة — انظر `PasswordResetMail`.
 */
class DocumentMail extends Mailable
{
    public function __construct(public MessageIntent $intent) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->intent->subject,
            replyTo: $this->intent->replyTo ? [new Address($this->intent->replyTo)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.document', with: [
            'paragraphs' => $this->intent->paragraphs,
            'url' => $this->intent->url,
            'action' => $this->intent->action,
            'senderName' => $this->intent->senderName,
            'footer' => $this->intent->footer,
        ]);
    }
}
