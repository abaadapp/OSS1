{{--
    رسالةُ مستند — قالبٌ واحد لرابط العرض وتذكيره وخبر قراره.

    والشكلُ شكلُ `password-reset` نفسُه: ترويسةٌ داكنة، وفقرات، وزرٌّ،
    والرابطُ نصًّا تحته لمن يجرّد عميلُ بريده الأزرار.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'en' ? 'ltr' : 'rtl' }}">
<head><meta charset="utf-8"></head>
<body style="font-family: Tahoma, Arial, sans-serif; background:#f3f4f6; margin:0; padding:24px; color:#1f2937;">
    <div style="max-width:560px; margin:0 auto; background:#fff; border-radius:16px; overflow:hidden; border:1px solid #eee;">
        <div style="background:#111; color:#fff; padding:20px 24px;">
            <h1 style="margin:0; font-size:18px;">{{ $senderName ?: 'Event Plus OSS' }}</h1>
        </div>
        <div style="padding:24px;">
            @foreach ($paragraphs as $paragraph)
                <p style="font-size:14px; line-height:1.8; margin:0 0 16px; white-space:pre-line;">{{ $paragraph }}</p>
            @endforeach

            @if ($url)
                <p style="text-align:center; margin:8px 0 20px;">
                    <a href="{{ $url }}" style="display:inline-block; background:#111; color:#fff; text-decoration:none; padding:12px 28px; border-radius:10px; font-size:15px; font-weight:bold;">
                        {{ $action ?: __('فتح المستند') }}
                    </a>
                </p>

                {{-- الرابط نصًّا: عملاء بريدٍ كثيرون يجرّدون الأزرار --}}
                <p style="font-size:11px; color:#9ca3af; word-break:break-all; margin:0; padding-top:16px; border-top:1px solid #f3f4f6;" dir="ltr">
                    {{ $url }}
                </p>
            @endif
        </div>
        <div style="padding:16px 24px; background:#f9fafb; font-size:12px; color:#9ca3af; text-align:center;">
            {{ $footer ?: __('رسالة آلية من نظام Event Plus OSS') }}
        </div>
    </div>
</body>
</html>
