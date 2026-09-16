<?php

namespace App\Support;

/**
 * بيانات لوحة الشركة التي يجب أن تتبع الفرع المختار.
 *
 * التقارير العامة قد تقرأ النشاط كله عمدًا، أما لوحة التحكم فتجمع بطاقات
 * ومخططات في شاشة واحدة؛ لذلك يجب أن تشترك كلها في نطاق الفرع.
 *
 * ═══ وما تغيّر ═══
 *
 * كانت تقرأ `orders` — وهو جدولٌ لا مسارَ يكتب فيه منذ رُفعت نقطة البيع،
 * ففارغٌ أبدًا. فمهما فوترت الشركةُ وحصّلت رسم المخطّطُ خطًّا على الصفر.
 * فصارت من `Revenue` — فواتير العملاء الصادرة.
 *
 * و«توزيعُ وسائل الدفع» صار توزيعَ **التحصيل** لا المبيعات: الفاتورةُ
 * التزامٌ بلا وسيلةِ دفعٍ أصلًا، والوسيلةُ تُسأل عن سند القبض.
 */
class DashboardMetrics
{
    /** مخطط مبيعات السنة الجارية، مع فراغ الأشهر المستقبلية كما في Demo::salesTrend. */
    public static function salesYear(): array
    {
        $bid = Demo::bid();
        $branch = Demo::currentBranchId();
        $start = now()->startOfYear();
        $end = now()->endOfYear();
        $cutoff = now()->copy();

        $buckets = Revenue::series($bid, 'month', $start, $cutoff, $branch);

        $labels = [];
        $full = [];
        $data = [];
        $counts = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $key = $cursor->format('Y-m');
            $future = $cursor->copy()->startOfMonth()->gt($cutoff);
            $labels[] = $cursor->translatedFormat('M');
            $full[] = $cursor->translatedFormat('F Y');
            $data[] = $future ? null : ($buckets[$key]['sum'] ?? 0.0);
            $counts[] = $future ? null : ($buckets[$key]['count'] ?? 0);
            $cursor->addMonthNoOverflow();
        }

        return [
            'labels' => $labels,
            'full' => $full,
            'data' => $data,
            'counts' => $counts,
            'range' => 'year',
            'unit' => 'month',
        ];
    }

    /**
     * توزيعُ ما حُصِّل هذا الشهر على وسائله — ويتبع الفرعَ كبقيّة البطاقات.
     *
     * والسندُ بلا فرع، لكنّ تخصيصاته تعرف فرعَها — انظر `Revenue::byMethod`.
     */
    public static function paymentDistribution(): array
    {
        $rows = Revenue::byMethod(Demo::bid(), now()->startOfMonth(), null, Demo::currentBranchId());

        return [
            'labels' => $rows->map(fn ($r) => Demo::methodLabel($r['key']))->all(),
            'series' => $rows->pluck('total')->all(),
        ];
    }
}
