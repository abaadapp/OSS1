<?php

namespace App\Http\Controllers;

use App\Support\AlertMetrics;
use App\Support\DashboardMetrics;
use App\Support\Demo;
use App\Support\DocumentRecords;
use Inertia\Inertia;

/**
 * لوحات التحكم: صفحات Inertia + نقاط نهاية JSON لتحديث البطاقات لحظيًا.
 */
class DashboardController extends Controller
{
    public function adminStats()
    {
        return response()->json([
            'stats' => Demo::adminStats(),
            'updated_at' => now()->format('H:i:s'),
        ]);
    }

    /**
     * لوحة صاحب المتجر — كان جلب البيانات داخل القالب، فنُقل إلى هنا
     * لأن Inertia يمرّر البيانات من الخادم لا من داخل العرض.
     */
    public function admin()
    {
        return Inertia::render('Admin/Dashboard', [
            'stats' => Demo::adminStats(),
            // بطاقات اختيارية من مقاييس التقارير — تُرسل كاملةً وتختار الواجهة
            // منها: سبعة تجميعات خفيفة، وطلبُ الخادم عند كل إضافة أثقل منها
            'statCatalog' => AlertMetrics::catalog(Demo::bid()),
            // مخططات اللوحة تتبع الفرع المختار مثل البطاقات وأحدث الطلبات.
            // التقارير العامة تبقى مستقلة عن هذا العقد ولا نغيّر معناها هنا.
            'salesSeries' => DashboardMetrics::salesYear(),
            'paymentDistribution' => DashboardMetrics::paymentDistribution(),
            /*
             * أحدث ستّ فواتيرَ وأفضل خمسة أصنافٍ وأعلى خمسةِ موظّفين.
             *
             * والثلاثةُ كانت تُقتطع في PHP من جداولَ تُحمَّل كاملة: كلُّ طلبٍ
             * مباعٍ في المتجر ليُعرض ستّة، وكلُّ صنفٍ بمقاساته وإضافاته
             * ووصفاته ليُعرض خمسة.
             *
             * وأسوأُ من ثمنِها أنّ ثلاثًا منها لم تكن ما يقول اسمُها:
             * «أفضل المنتجات» كانت أقدمَ خمسةِ أصنافٍ بالمعرّف — لا تُرتَّب
             * ببيعٍ ولا تُذكر فيها كميّة — و«أداء الموظفين» أقدمَ خمسةِ
             * موظّفين. ودالّةُ الأفضل مبيعًا موجودةٌ في النظام تخدم التقارير
             * منذ بُنيت، ولم تكن اللوحة تناديها.
             *
             * و«أحدث الطلبات» كانت تقرأ جدولًا لا مسارَ يكتب فيه، فتبقى
             * فارغةً أبدًا على لوحةِ شركةٍ تفوتر كلَّ يوم.
             */
            // أحدثُ الفواتير لا أحدثُ الطلبات — انظر `Demo::recentInvoices`
            'recentInvoices' => Demo::recentInvoices(6),
            'topProducts' => Demo::topSellingProducts(5, 'month', Demo::currentBranchId()),
            /*
             * والترتيبُ بما حقّقه هذا الشهر — **في الفرع المختار**.
             *
             * كانت تقرأ المتجر كلَّه بينما بطاقةُ «مبيعات الشهر» فوقها تقرأ
             * الفرع، و«أفضل المنتجات» بجانبها تُنادى بالفرع صراحةً. فتقول
             * الشاشةُ الواحدة رقمين لشهرٍ واحد — ولا شيء فيها يقول أيُّهما
             * على أيّ نطاق.
             */
            'topEmployees' => collect(Demo::employees(Demo::currentBranchId()))
                ->sortByDesc('achieved')->take(5)->values()->all(),
            /* «مستنداتٌ تحتاج إجراء» — ما ينتهي خلال ثلاثين يومًا وما انتهى وأقربُ انتهاء؛ لمن يفتح المستندات */
            'documents' => auth()->user()?->allows('documents') ? DocumentRecords::summary(Demo::bid()) : null,
        ]);
    }
}
