{{--
    رابطُ مهامٍّ لا يفتح — منتهٍ أو مجدَّد أو مكتوبٌ خطأ.

    ولا يقول أيّها: من يجرّب رموزًا لا يُعطى ما يفرّق به بين رمزٍ كان صحيحًا
    ورمزٍ لم يكن قطّ.
--}}
@php $rtl = \App\Support\Paper::rtl(); @endphp
<!DOCTYPE html>
<html lang="{{ $rtl ? 'ar' : 'en' }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('الرابط لا يفتح') }}</title>
    <style>
        html, body { margin: 0; padding: 0; background: #f5f5f4; font-family: Tahoma, Arial, sans-serif; color: #1f2937; }
        .card { max-width: 480px; margin: 48px auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 24px; text-align: center; }
        h1 { font-size: 18px; margin: 0 0 8px; color: #111; }
        p { font-size: 13px; color: #6b7280; line-height: 1.8; margin: 0; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ __('الرابط لا يفتح') }}</h1>
        <p>{{ __('قد يكون الرابط منتهيًا أو جُدّد. اطلب من مسؤول العملية رابط مهامك الحالي.') }}</p>
    </div>
</body>
</html>
