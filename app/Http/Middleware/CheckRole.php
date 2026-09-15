<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /** التحقق من دور المستخدم. الاستخدام: ->middleware('role:admin,manager') */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }
        if (in_array($user->role, $roles, true)) {
            return $next($request);
        }
        abort(403, __('ليس لديك صلاحية الوصول إلى هذه الصفحة.'));
    }
}
