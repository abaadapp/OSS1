<?php

namespace App\Support\Intelligence;

use App\Models\Expense;
use App\Support\Money;

/**
 * الشذوذُ — حتميٌّ ومشروح، ولا يتكلّم قبل أن يرصد ما يكفي. المرحلة 11.
 *
 * «مصروفُ هذا الشهر فوق متوسّط الأشهر الستّة السابقة بـ٤٠٪» — رقمٌ ومتوسّطٌ
 * وعتبة. ولا نموذجَ يتعلّم، ولا دقّةَ مزيّفة: أقلُّ من ثلاثة أشهرٍ مرصودة لا
 * يُقال فيها شيء.
 */
final class Anomalies
{
    public const TRAILING_MONTHS = 6;

    /** @return list<array<string, mixed>> */
    public static function expenses(int $bid, ?array $thresholds = null): array
    {
        $thresholds ??= Thresholds::for($bid);
        $currency = Money::of($bid);
        $thisMonth = Period::resolve($bid, 'this_month');
        $trailing = Period::between($bid, $thisMonth->start->copy()->subMonths(self::TRAILING_MONTHS), $thisMonth->start->copy());

        $series = FinancialPerformance::monthlySeries(
            Expense::where('business_id', $bid)->paid()->whereDate('spent_at', '>=', $trailing->startDate())->whereDate('spent_at', '<', $trailing->endDate())->toBase(),
            'spent_at', 'amount', 'date');

        $observed = array_filter($series, fn ($m) => $m['sum'] > 0.0005);
        if (count($observed) < ManagementAlerts::MIN_OBSERVATIONS) {
            return [];
        }

        $average = round(array_sum(array_column($observed, 'sum')) / count($observed), 3);
        $current = FinancialPerformance::expenses($bid, $thisMonth)['total'];
        if ($average <= 0.0005 || $current <= 0.0005) {
            return [];
        }

        $percent = round(($current - $average) / $average * 100, 1);
        if ($percent < $thresholds['expense_anomaly_percent']) {
            return [];
        }

        return [[
            'kind' => 'expense_anomaly', 'severity' => ManagementAlerts::WARNING,
            'title' => __('مصروفاتُ الشهر فوق متوسّطها'),
            'detail' => __('المدفوعُ هذا الشهر :c مقابل متوسّطِ :n أشهر :a — فوقه بـ:p٪ والعتبة :t٪', [
                'c' => Money::format($current, $currency), 'n' => count($observed), 'a' => Money::format($average, $currency), 'p' => $percent, 't' => $thresholds['expense_anomaly_percent'],
            ]),
            'facts' => ['value' => $current, 'average' => $average, 'observations' => count($observed), 'percent' => $percent, 'threshold' => $thresholds['expense_anomaly_percent'], 'period' => $thisMonth->label()],
            'url' => route('admin.management.financial', ['period' => 'this_month']),
            'at' => now()->format('Y-m-d'),
        ]];
    }
}
