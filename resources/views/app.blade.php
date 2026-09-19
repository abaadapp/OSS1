<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'en' ? 'ltr' : 'rtl' }}">
<head>
    <meta charset="utf-8">
    {{-- viewport-fit=cover كي تصير env(safe-area-inset-*) قيمًا حقيقية على الآيباد --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'Event Plus OSS') }}</title>
    {{-- ورقمُ الإصدار يُسقط أيقونةَ العلامة القديمة من ذاكرة المتصفّح --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}?v=ep1" sizes="any">

    @routes
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="font-sans antialiased">
    @inertia
</body>
</html>
