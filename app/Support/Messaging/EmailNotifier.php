<?php

namespace App\Support\Messaging;

use App\Mail\DocumentMail;
use App\Support\Mailer;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * البريدُ — القناةُ الوحيدةُ التي تُوصِل اليوم.
 *
 * ولا طابور: من يضغط «أرسل بالبريد» واقفٌ أمام الشاشة، والخطأُ إن وقع
 * يجب أن يراه هو لا ملفُّ السجلّ بعد ساعة — كما في `PasswordResetMail`.
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

    public function send(MessageIntent $intent): MessageResult
    {
        if (! $this->configured()) {
            return MessageResult::failed(__('إرسالُ البريد غير مضبوط على الخادم.'));
        }

        try {
            Mail::to($intent->to)->send(new DocumentMail($intent));
        } catch (Throwable $e) {
            report($e);

            return MessageResult::failed(__('تعذّر إرسال البريد — :reason', ['reason' => $e->getMessage()]));
        }

        return MessageResult::ok();
    }
}
