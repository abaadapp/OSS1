<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Support\BranchAccess;
use App\Support\Demo;
use App\Support\ReportPeriod;
use App\Support\Waste;
use App\Support\WasteInsights;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تحليلات الهالك — قراءةٌ فوق بياناتٍ قائمة، لا نظامُ هالكٍ جديد.
 *
 * تعديلات المخزون تُسجَّل منذ البداية، ولها سببٌ وتكلفةُ لحظة. وكان كلّ ما
 * يُقرأ منها مجموعَين في أعلى الجدول: كم خسرنا وكم كسبنا. وهذا يقول إنّ
 * الخسارة أربعون ولا يقول أين تقع ولا هل تزيد.
 *
 * فلا جدولَ جديد ولا عمودَ جديد: الصفوف نفسها، مقروءةً على ستّة افنت بلس.
 */
class WasteAnalyticsController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    public function index(Request $request): Response
    {
        $bid = $this->bid();

        /*
         * المدّة الافتراضية شهرٌ إلى اليوم.
         *
         * وتُثبَّت في الخادم لا تُترك فارغةً: مدّةٌ مفتوحة تجمع تاريخ المتجر
         * كلّه، فيقرأ التاجر رقمًا هائلًا يظنّه شهره — والمقارنة بالمدّة
         * السابقة تفقد معناها بلا حدّين.
         */
        /*
         * المدّةُ بحدّين يُفحصان قبل أن يُقرآ.
         *
         * كان تاريخٌ غير مقروء يُسقط الشاشة خطأَ خادم، وحقلٌ مُفرَّغٌ يجعل حدَّه
         * مفتوحًا فتقرأ الشاشةُ التاريخَ كلَّه وتقول «هذا الشهر». فالفارغُ يعود
         * إلى افتراضيّه، والخاطئُ يعود إلى الشهر ومعه سببُه يُقال فوق الأرقام.
         */
        $from = is_string($request->query('from')) && $request->query('from') !== '' ? (string) $request->query('from') : now()->startOfMonth()->toDateString();
        $to = is_string($request->query('to')) && $request->query('to') !== '' ? (string) $request->query('to') : now()->toDateString();
        $periodError = ReportPeriod::check($from, $to);
        if ($periodError !== null) {
            [$from, $to] = [now()->startOfMonth()->toDateString(), now()->toDateString()];
        }

        $filters = [
            'from' => $from,
            'to' => $to,
            'branch_id' => $request->query('branch_id') ?: null,
            'category_id' => $request->query('category_id') ?: null,
            'product_id' => $request->query('product_id') ?: null,
            'reason' => $request->query('reason') ?: null,
        ];

        $totals = Waste::totals($bid, $filters);
        $previous = Waste::totals($bid, array_merge($filters, Waste::previousWindow($from, $to)));

        return Inertia::render('Admin/Reports/Waste', [
            'totals' => $totals,
            'previous' => $previous,
            'change' => $previous['value'] > 0
                ? round(($totals['value'] - $previous['value']) / $previous['value'] * 100, 1)
                : null,
            'byProduct' => Waste::groupedBy($bid, 'product', $filters),
            'byCategory' => Waste::groupedBy($bid, 'category', $filters),
            'byBranch' => Waste::groupedBy($bid, 'branch', $filters),
            'byReason' => Waste::groupedBy($bid, 'reason', $filters),
            'overTime' => Waste::overTime($bid, $filters),
            'versusConsumption' => Waste::versusConsumption($bid, $filters),
            'insights' => WasteInsights::all($bid, $filters),
            // صفوفٌ قديمة تخالف القاعدة — تُعرض ولا تُصلَح
            'suspicious' => Waste::suspiciousRows($bid),
            'filters' => $filters,
            'periodError' => $periodError,
            'options' => [
                'branches' => BranchAccess::limit(Branch::where('business_id', $bid), null, 'id')->orderBy('id')
                    ->get(['id', 'name'])->map(fn ($b) => ['value' => $b->id, 'label' => $b->name])->all(),
                'categories' => Category::where('business_id', $bid)->orderBy('name')
                    ->get(['id', 'name'])->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->all(),
                'products' => Product::where('business_id', $bid)->orderBy('name')
                    ->get(['id', 'name'])->map(fn ($p) => ['value' => $p->id, 'label' => $p->name])->all(),
                // القديمة معها: صفوفٌ في القاعدة بأسبابٍ لم تعد تُكتب، وحذفُها
                // من المنتقي يجعلها تُعدّ في المجموع ولا يمكن عزلها
                'reasons' => Waste::all(),
            ],
        ]);
    }
}
