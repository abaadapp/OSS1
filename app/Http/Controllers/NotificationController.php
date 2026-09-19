<?php

namespace App\Http\Controllers;

use App\Models\DismissedNotification;
use App\Models\InternalNotification;
use App\Models\Order;
use App\Support\Demo;
use Illuminate\Http\Request;

/**
 * تغذية الإشعارات (JSON) لاستطلاعها من المتصفح وإظهار إشعارات فورية للطلبات الجديدة.
 */
class NotificationController extends Controller
{
    public function feed()
    {
        $bid = auth()->user()->business_id ?? Demo::bid();

        $latest = Order::where('business_id', $bid)->sold()
            ->when(Demo::currentBranchId(), fn ($q) => $q->where('branch_id', Demo::currentBranchId()))
            ->orderByDesc('id')->first();

        return response()->json([
            'count' => Demo::notificationsCount(),
            'items' => Demo::notifications(),
            'latest_order' => $latest ? [
                'id' => $latest->id,
                'number' => $latest->number,
                'customer' => \App\Support\Demo::customerLabel($latest->customer_name),
                'total' => (float) $latest->total,
                'url' => route('admin.orders.show', $latest->number),
            ] : null,
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
