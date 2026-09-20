<?php

namespace App\Support\Intelligence;

use App\Models\FinancialForecast;
use App\Models\FinancialScenario;
use App\Models\User;
use App\Support\Activity;
use App\Support\Money;
use App\Support\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * التوقّعاتُ النقديّة — لقطاتٌ تُحفظ لتُقارَن بالواقع. المرحلة 11.
 *
 * ═══ المعتمَدُ لا يُمسّ ═══
 *
 * توقّعٌ اعتُمد تُجمَّد افتراضاتُه وأرقامُه ووقتُه وكاتبُه. ومن أراد تحديثَه
 * أنشأ توقّعًا جديدًا فاعتُمد وصار السابقُ «محلولًا محلَّه» — ويبقى كما كان
 * ليُقال بعد شهر: توقّعنا كذا ووقع كذا.
 */
final class Forecasting
{
    /** @param  array<string, mixed>  $data */
    public static function create(int $bid, array $data, ?User $actor = null): FinancialForecast
    {
        $horizon = (int) ($data['horizon_days'] ?? 90);
        if (! in_array($horizon, FinancialForecast::HORIZONS, true)) {
            throw new RuntimeException(__('الأفق من القائمة: 30 · 60 · 90 · 180 · 365 يومًا.'));
        }

        $scenario = null;
        if (filled($data['scenario_id'] ?? null)) {
            $scenario = FinancialScenario::where('business_id', $bid)->whereKey((int) $data['scenario_id'])->with('assumptions')->first();
            if ($scenario === null) {
                throw new RuntimeException(__('السيناريو ليس من شركتك.'));
            }
        }

        $projection = CashIntelligence::project($bid, $horizon, (array) ($data['assumptions'] ?? []), $scenario);

        $forecast = FinancialForecast::create([
            'business_id' => $bid,
            'name' => mb_substr(trim((string) ($data['name'] ?? '')) ?: __('توقّع :d — :n يومًا', ['d' => now()->format('Y-m-d'), 'n' => $horizon]), 0, 120),
            'horizon_days' => $horizon,
            'base_date' => $projection['base_date'],
            'status' => FinancialForecast::DRAFT,
            'scenario_id' => $scenario?->id,
            'assumptions' => $projection['assumptions'],
            'opening_cash' => $projection['opening_cash'],
            'projection' => ['months' => $projection['months'], 'completeness' => $projection['completeness'], 'basis' => $projection['basis'],
                'scenario' => $projection['scenario']],
            'totals' => $projection['totals'],
            'currency' => Money::of($bid)['code'] ?? null,
            'created_by' => $actor?->id,
            'notes' => mb_substr(trim((string) ($data['notes'] ?? '')), 0, 2000) ?: null,
        ]);

        Activity::log('created', 'أنشأ التوقّع النقدي «'.$forecast->name.'» ('.$horizon.' يومًا)', [
            'business_id' => $bid, 'subject_id' => $forecast->id, 'subject_type' => 'financial_forecast', 'icon' => 'trending-up',
        ]);

        return $forecast;
    }

    /** الاعتمادُ — يُجمِّد، ويحلّ محلَّ المعتمَد السابق بالأفق نفسِه */
    public static function approve(FinancialForecast $forecast, ?User $actor = null): FinancialForecast
    {
        if (! ($actor?->may(Permissions::FORECASTS_APPROVE) ?? false)) {
            throw new RuntimeException(__('اعتمادُ التوقّع يحتاج صلاحيّة الاعتماد.'));
        }
        if ($forecast->isApproved()) {
            return $forecast;
        }
        if ($forecast->status !== FinancialForecast::DRAFT) {
            throw new RuntimeException(__('لا يُعتمد إلّا توقّعٌ مسودّة.'));
        }

        return DB::transaction(function () use ($forecast, $actor) {
            FinancialForecast::where('business_id', $forecast->business_id)->where('status', FinancialForecast::APPROVED)
                ->where('horizon_days', $forecast->horizon_days)->whereKeyNot($forecast->id)
                ->update(['status' => FinancialForecast::SUPERSEDED, 'superseded_at' => now()]);

            $forecast->update(['status' => FinancialForecast::APPROVED, 'approved_at' => now(), 'approved_by' => $actor?->id]);

            Activity::log('status', 'اعتمد التوقّع النقدي «'.$forecast->name.'» — أُجمّدت افتراضاتُه وأرقامُه', [
                'business_id' => (int) $forecast->business_id, 'subject_id' => $forecast->id, 'subject_type' => 'financial_forecast', 'color' => 'success',
            ]);

            return $forecast->fresh();
        });
    }

    /** المسودّةُ وحدَها تُحذف */
    public static function discard(FinancialForecast $forecast, ?User $actor = null): void
    {
        if ($forecast->status !== FinancialForecast::DRAFT) {
            throw new RuntimeException(__('التوقّع المعتمَد لا يُحذف — يبقى ليُقارَن بالواقع.'));
        }
        $forecast->delete();
    }

    /**
     * التوقّعُ مقابلَ الواقع — لكلّ شهرٍ انقضى من الأفق.
     *
     * الواقعُ من الدفتر: ما دخل النقدَ وما خرج منه (`CashIntelligence::cashMovement`)،
     * والإقفالُ الفعليّ رصيدُ النقد في نهاية الشهر.
     *
     * @return array<string, mixed>
     */
    public static function versusActual(FinancialForecast $forecast): array
    {
        $bid = (int) $forecast->business_id;
        $rows = [];
        $now = now();

        foreach ($forecast->projection['months'] ?? [] as $m) {
            $start = Carbon::parse($m['month'].'-01')->startOfMonth();
            $end = $start->copy()->addMonth();
            if ($end->gt($now)) {
                $rows[] = ['month' => $m['month'], 'status' => 'pending', 'forecast' => self::pick($m)];

                continue;
            }
            $actual = CashIntelligence::cashMovement($bid, $start, $end);
            $closing = FinancialPerformance::cashPosition($bid, $end->copy()->subDay())['liquid'];
            $f = self::pick($m);

            $rows[] = [
                'month' => $m['month'],
                'status' => 'elapsed',
                'forecast' => $f,
                'actual' => ['inflows' => $actual['inflows'], 'outflows' => $actual['outflows'], 'net' => $actual['net'], 'closing' => $closing],
                'variance' => [
                    'inflows' => round($actual['inflows'] - $f['inflows'], 3),
                    'outflows' => round($actual['outflows'] - $f['outflows'], 3),
                    'net' => round($actual['net'] - $f['net'], 3),
                    'closing' => round($closing - $f['closing'], 3),
                ],
            ];
        }

        $elapsed = array_values(array_filter($rows, fn ($r) => $r['status'] === 'elapsed'));

        return [
            'forecast' => ['id' => $forecast->id, 'name' => $forecast->name, 'status' => $forecast->status, 'horizon_days' => $forecast->horizon_days,
                'base_date' => $forecast->base_date->toDateString(), 'approved_at' => $forecast->approved_at?->format('Y-m-d H:i'), 'opening_cash' => (float) $forecast->opening_cash],
            'rows' => $rows,
            'elapsed_months' => count($elapsed),
            'accuracy' => $elapsed === [] ? ['status' => 'insufficient_data', 'reason' => __('لم ينقضِ شهرٌ من الأفق بعد')] : [
                'status' => 'ok',
                'inflows_forecast' => round(array_sum(array_map(fn ($r) => $r['forecast']['inflows'], $elapsed)), 3),
                'inflows_actual' => round(array_sum(array_map(fn ($r) => $r['actual']['inflows'], $elapsed)), 3),
                'outflows_forecast' => round(array_sum(array_map(fn ($r) => $r['forecast']['outflows'], $elapsed)), 3),
                'outflows_actual' => round(array_sum(array_map(fn ($r) => $r['actual']['outflows'], $elapsed)), 3),
            ],
            'basis' => __('الواقعُ من الدفتر: ما دخل الصندوقَ والبنوك وما خرج منهما في الشهر، والإقفالُ رصيدُهما آخرَ الشهر — ويُقارَن بالتوقّع المعتمَد بلا السيناريو'),
        ];
    }

    /** @return array{inflows: float, outflows: float, net: float, closing: float} */
    private static function pick(array $m): array
    {
        return [
            'inflows' => round((float) ($m['inflows']['total'] ?? 0), 3),
            'outflows' => round((float) ($m['outflows']['total'] ?? 0), 3),
            'net' => round((float) ($m['net'] ?? 0), 3),
            'closing' => round((float) ($m['closing'] ?? 0), 3),
        ];
    }
}
