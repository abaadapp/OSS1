<?php

namespace App\Support\Messaging;

use App\Mail\DocumentMail;
use App\Support\Communication\Communications;
use App\Support\Mailer;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * البريدُ — القناةُ الأولى.
 *
 * ولا طابور: من يضغط «أرسل بالبريد» واقفٌ أمام الشاشة، والخطأُ إن وقع
 * يجب أن يراه هو لا ملفُّ السجلّ بعد ساعة — كما في `PasswordResetMail`.
 *
 * ═══ ومعرّفُ المزوّد — المرحلة 6 ═══
 *
 * `Mail::send` تعيد `SentMessage`، ومعرّفُها هو ما يكتبه المزوّد على الرسالة
 * (Postmark: `MessageID`؛ SMTP: `Message-ID`). يُحفظ في القيد ليُطابَق يومَ
 * يعود المزوّدُ بخبر الوصول أو الارتداد — انظر `PostmarkWebhookController`.
 * وتحت `Mail::fake()` لا يعود شيء، فيبقى القيدُ بلا معرّف.
 */
final class EmailNotifier implements Notifier
{
    public function channel(): string
    {
        return 'email';
    }

    public function configured(): bool
    {
        return Mailer::configured();
    }

    public function provider(): string
    {
        $driver = (string) config('mail.default');

        return (string) (config("mail.mailers.{$driver}.transport") ?: $driver);
    }

    public function send(MessageIntent $intent): MessageResult
    {
        if (! $this->configured()) {
            return $this->done($intent, MessageResult::failed(__('إرسالُ البريد غير مضبوط على الخادم.')));
        }

        try {
            $sent = Mail::to($intent->to)->send(new DocumentMail($intent));
        } catch (Throwable $e) {
            report($e);

            return $this->done($intent, MessageResult::failed(__('تعذّر إرسال البريد — :reason', ['reason' => $e->getMessage()])));
        }

        $id = $sent?->getMessageId();

        return $this->done($intent, MessageResult::ok(filled($id) ? trim((string) $id, '<>') : null));
    }

    /** ويُقيَّد ما جرى — نجح أو فشل — ثمّ يُعاد كما هو */
    private function done(MessageIntent $intent, MessageResult $result): MessageResult
    {
        Communications::record($this->channel(), $this->provider(), $intent, $result);

        return $result;
    }
}
