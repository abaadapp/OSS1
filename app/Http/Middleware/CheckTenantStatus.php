<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * لا يعمل موقوفٌ ولا معطَّلٌ ولا منتهي الاشتراك.
 *
 * الفحص عند الباب لا في كل شاشة: الجلسةُ تُنهى ويُعاد صاحبها إلى الدخول
 * برسالةٍ تقول السبب. وإنهاء الجلسة مقصود — إبقاؤها مفتوحة يعني أن الإيقاف
 * لا يسري إلا بعد أن يخرج المستخدم بنفسه، وهو ما لن يفعله.
 *
 */
class CheckTenantStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        /*
         * الصيانة تسبق كلّ فحص: هي حالة النظام لا حالة الحساب.
         *
         * ولا تُنهي الجلسة — الوقفة مؤقّتة، وإخراجُ الجميع يعني أن كلّ تاجر
         * يعود ليكتب كلمة سرّه بعد ترقيةٍ استغرقت دقيقتين. و503 مقصود:
         * يقول للمتصفّح وللمراقبة «موقوف مؤقّتًا» لا «تعطّل».
         */
        if ($user && Tenancy::maintenance()) {
            $message = __('النظام في وضع الصيانة مؤقّتًا. سنعود بعد قليل.');

            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'blocked' => 'maintenance', 'message' => $message], 503);
            }

            return \Inertia\Inertia::render('Auth/Maintenance', ['message' => $message])
                ->toResponse($request)->setStatusCode(503);
        }

        $reason = Tenancy::blockReason($user);
        if (! $reason) {
            return $next($request);
        }

        $message = Tenancy::message($reason);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'blocked' => $reason, 'message' => $message], 403);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
