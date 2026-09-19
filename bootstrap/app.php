<?php

use App\Http\Middleware\CheckAbility;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\CheckTenantStatus;
use App\Http\Middleware\EntersPanel;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NormalizeNumbers;
use App\Http\Middleware\RequireAction;
use App\Http\Middleware\RequiresBusiness;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => CheckRole::class,
            'ability' => CheckAbility::class,
            // وحارسُ فعلٍ بعينه داخل قسمٍ يُمنح كاملًا — انظر RequireAction
            'may' => RequireAction::class,
            'panel' => EntersPanel::class,
            'business' => RequiresBusiness::class,
            'tenant' => CheckTenantStatus::class,
        ]);
        $middleware->web(append: [
            SecurityHeaders::class,
            SetLocale::class,
            NormalizeNumbers::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        /*
         * أخبارُ المزوّدين تأتي من خادمٍ لا متصفّح — لا جلسةَ لها ولا رمزَ
         * CSRF. ويحرسها سرٌّ أو توقيعٌ في متحكّمها — انظر `Webhooks\*`.
         */
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * لا توجد مسارات api/* في هذا التطبيق، فقصرُ JSON عليها كان يعني أن
         * كل نداءات fetch تتلقّى تحويلة 302 إلى صفحة HTML بدل 422 بأخطائها —
         * فتُبتلع رسالة الخطأ ويبدو الطلب وكأنه نجح.
         * expectsJson() لا يشمل طلبات Inertia (تقبل text/html)، فتبقى
         * تحويلاتها ورسائلها في الجلسة كما هي.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
