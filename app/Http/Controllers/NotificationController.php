<?php

namespace App\Http\Controllers;

use App\Models\DismissedNotification;
use App\Models\InternalNotification;
use App\Support\Demo;
use Illuminate\Http\Request;

/**
 * تغذية الإشعارات (JSON) يستطلعها الجرس من المتصفح.
 *
 * وكانت تحمل معها «آخرَ طلب» ليُعلَن البيعُ الجديد فورًا — ولا يقرؤه الجرسُ
 * منذ زمن، وخرجت «المبيعات» من النظام فخرج معها.
 */
class NotificationController extends Controller
{
    public function feed()
    {
        return response()->json([
            'count' => Demo::notificationsCount(),
            'items' => Demo::notifications(),
        ]);
    }

    /** حذف (إخفاء) تنبيه واحد للمستخدم الحالي */
    public function dismiss(Request $request)
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:191']]);

        DismissedNotification::firstOrCreate([
            'user_id' => auth()->id(),
            'notif_key' => $data['key'],
        ]);

        /* وتنبيهُ الأتمتة يُطوى بقراءته — فلا يبقى «غيرَ مقروء» في سجلّه بعد أن أُخفي */
        if (str_starts_with($data['key'], 'internal-')) {
            InternalNotification::where('user_id', auth()->id())
                ->whereKey((int) substr($data['key'], 9))->whereNull('read_at')->update(['read_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    /** حذف جميع التنبيهات المعروضة حاليًا للمستخدم */
    public function clear()
    {
        foreach (Demo::allNotifications() as $n) {
            DismissedNotification::firstOrCreate([
                'user_id' => auth()->id(),
                'notif_key' => $n['key'],
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
