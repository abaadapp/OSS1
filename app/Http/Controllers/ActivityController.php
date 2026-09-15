<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Demo;
use App\Support\Pagination;
use App\Support\Search;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ActivityController extends Controller
{
    private static function shape($q, Request $request)
    {
        if ($s = Search::term($request)) {
            $like = Search::like();
            $q->where(fn ($w) => $w->where('description', $like, "%{$s}%")->orWhere('user_name', $like, "%{$s}%"));
        }
        if ($a = $request->query('action')) {
            $q->where('action', $a);
        }

        return $q->latest('id')->paginate(15)->withQueryString()->through(fn ($l) => [
            'user' => $l->user_name, 'action' => $l->action, 'description' => $l->description,
            'icon' => $l->icon, 'color' => $l->color, 'ip' => $l->ip,
            'time' => optional($l->created_at)->format('Y-m-d H:i'),
            'ago' => optional($l->created_at)?->diffForHumans(),
        ]);
    }

    public function adminIndex(Request $request)
    {
        return Inertia::render('Admin/Activity', self::adminData($request));
    }

    /**
     * بيانات قسم سجلّ النشاط — تُقرأ من صفحته المستقلّة ومن لوحة الإعدادات
     * حيث يُفتح مكانها، فلا تفترق النسختان.
     *
     * @return array<string, mixed>
     */
    public static function adminData(Request $request): array
    {
        $bid = auth()->user()->business_id ?? Demo::bid();

        /*
         * السجلّ يتتبّع الموظفين وحدهم.
         *
         * كان صاحبُ العمل يفتحه فيقرأ أفعاله هو — «سجّل الدخول»، «عدّل
         * منتجًا» — تدفع أفعالَ من يجب أن يُراقَبوا خارج الصفحة الأولى.
         * والغرض من الشاشة أن يعرف ما جرى في غيابه، فيُستثنى هو.
         */
        $notStaff = User::where('role', 'admin')->select('id');

        $logs = self::shape(
            ActivityLog::where('business_id', $bid)->whereNotIn('user_id', $notStaff),
            $request,
        );

        return [
            'logs' => $logs->items(),
            'pagination' => Pagination::meta($logs),
            'filters' => $request->only('q', 'action'),
        ];
    }
}
