<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Demo;
use App\Support\Exports\Dataset;
use App\Support\Pagination;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ActivityController extends Controller
{
    /**
     * السجلُّ المرشَّح — تقرؤه الشاشةُ وملفُّ تصديرها معًا (`Exports\ActivityDataset`).
     *
     * والمالكون خارجه كما كانوا (`role = admin`)، والمدّةُ بيوم الحدث (`created_at`).
     */
    public static function listQuery(Request $request, int $bid): Builder
    {
        $notStaff = User::where('role', 'admin')->select('id');
        $q = ActivityLog::where('business_id', $bid)->whereNotIn('user_id', $notStaff);

        if ($s = Search::term($request)) {
            $like = Search::like();
            $q->where(fn ($w) => $w->where('description', $like, "%{$s}%")->orWhere('user_name', $like, "%{$s}%"));
        }
        if ($a = $request->query('action')) {
            $q->where('action', $a);
        }
        if ($from = Dataset::day($request, 'from')) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = Dataset::day($request, 'to')) {
            $q->whereDate('created_at', '<=', $to);
        }

        return $q->latest('id');
    }

    private static function shape($q)
    {
        return $q->paginate(15)->withQueryString()->through(fn ($l) => [
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
        $logs = self::shape(self::listQuery($request, (int) $bid));

        return [
            'logs' => $logs->items(),
            'pagination' => Pagination::meta($logs),
            'filters' => $request->only('q', 'action', 'from', 'to'),
        ];
    }
}
