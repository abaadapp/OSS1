<?php

namespace App\Support;

use App\Models\EventOperation;
use App\Models\OperationEquipmentRequirement;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectItem;
use App\Models\Setting;
use App\Support\Automation\Settings as AutomationSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * توقّعُ المخزون للمشاريع القادمة — قراءةٌ لا حركة.
 *
 * ═══ لا خصمَ ولا حجز ═══
 *
 * لا يُكتب هنا شيء: لا `StockLedger` ولا حركةٌ وهميّة. الكميّةُ تتحرّك حين تُصرف
 * فعلًا (`Consumables::issue`) أو تُستلم — والتوقّعُ يقرأ الرصيدَ بعدها كما هو.
 *
 * ═══ ما يدخل التوقّع ═══
 *
 * المنتجُ المخزونُ وحده (`Product::isStocked`): لا خدمة ولا باقة، ولا قطعةٌ ذاتُ
 * هويّة (للأصول حجزُها في `AssetReservation`)، ولا متطلّبٌ خارجيّ أو مستأجَر أو من
 * العميل — هذه لا تمسّ رفَّنا.
 *
 * ═══ ومن أين الاحتياج — مصدرٌ واحد لكلّ (مشروع · منتج) ═══
 *
 *  • **مؤكَّدٌ تشغيليًّا:** متطلّبُ منتجٍ من مخزوننا على فعاليّةٍ قائمة للمشروع —
 *    والباقي منه ما لم يُصرف بعد (`required − issued`)؛ فما صُرف خرج من الرصيد.
 *  • **مبدئيّ — «من نطاق المشروع، غير مؤكَّد تشغيليًّا»:** بندُ المشروع بمنتجٍ مخزون
 *    ناقصًا ما سُلّم — **حين لا متطلّبَ تشغيليًّا للمنتج نفسِه في المشروع**. فلا
 *    يُجمع البندُ والمتطلّبُ عن منتجٍ واحد مرّتين.
 *
 * ═══ والخطُّ الزمنيّ ═══
 *
 * المشاريعُ المفتوحة التي يبدأ تاريخُها (أوّلُ نافذة فعاليّةٍ قائمة، وإلّا بدءُ
 * المشروع) خلال `days()` يومًا — أو بدأت وما زالت جارية. ولكلّ منتجٍ: المتوفّرُ الآن،
 * ثمّ يُطرح احتياجُ كلّ مشروعٍ بترتيب تاريخه؛ فـ«المحجوزُ قبله» مجموعُ من سبقه،
 * و«المتوقَّعُ بعده» = المتوفّر − ما قبله − احتياجُه. والسالبُ «نقص».
 */
final class InventoryForecast
{
    public const SETTING = 'inventory_forecast_days';

    public const DEFAULT_DAYS = 14;

    public const MAX_DAYS = 60;

    public const CONFIRMED = 'operation';

    public const PROVISIONAL = 'scope';

    /** مصادرُ متطلّب المنتج التي تأخذ من رفّنا — والمستأجَرُ ومن العميل والطرفُ الثالث لا */
    private const OWN_SOURCES = ['inventory', 'owned'];

    /** نافذةُ التوقّع بالأيّام — إعدادُ الشركة، أو ١٤ */
    public static function days(int $businessId): int
    {
        $value = Setting::where('business_id', $businessId)->where('key', self::SETTING)->value('value');

        return is_numeric($value) ? max(1, min(self::MAX_DAYS, (int) $value)) : self::DEFAULT_DAYS;
    }

    /**
     * توقّعُ الشركة كلُّه — منتجاتٌ بخطّها الزمنيّ، ومشاريعُ بسطورها.
     *
     * يُحسب لكلّ طلبٍ من جديد (استعلاماتٌ مجمَّعة لا لكلّ مشروع)، ومن يحتاجه مرّاتٍ
     * في طلبٍ واحد يحسبه مرّةً ويمرّره.
     *
     * @return array{days: int, from: string, to: string, products: array<int, array<string, mixed>>, projects: array<int, array<string, mixed>>}
     */
    public static function for(int $businessId): array
    {
        return self::build($businessId);
    }

    /**
     * توقّعُ المخزون لمشروعٍ واحد — سطورُه وخطُّ كلّ منتجٍ فيها، أو فراغٌ يقول لماذا.
     *
     * @return array<string, mixed>
     */
    public static function forProject(int $businessId, int $projectId): array
    {
        $forecast = self::for($businessId);
        $project = $forecast['projects'][$projectId] ?? null;

        return [
            'days' => $forecast['days'], 'from' => $forecast['from'], 'to' => $forecast['to'],
            'in_window' => $project !== null,
            'date' => $project['date'] ?? null,
            'lines' => $project['lines'] ?? [],
            'shortages' => $project['shortages'] ?? 0,
            'timelines' => collect($project['lines'] ?? [])->mapWithKeys(fn ($l) => [$l['product_id'] => $forecast['products'][$l['product_id']]['timeline'] ?? []])->all(),
        ];
    }

    /** @return array<int, int> عددُ المنتجات الناقصة لكلّ مشروع — من توقّعٍ محسوب */
    public static function shortages(array $forecast): array
    {
        return array_map(fn (array $p) => $p['shortages'], array_filter($forecast['projects'], fn (array $p) => $p['shortages'] > 0));
    }

    /** بصمةُ نقص المشروع — تتغيّر بتغيّر المنتجات الناقصة أو مقاديرها، لا بغيرها */
    public static function signature(array $project): ?string
    {
        $short = collect($project['lines'])->filter(fn ($l) => $l['shortage'] > 0)
            ->mapWithKeys(fn ($l) => [$l['product_id'] => $l['shortage']])->sortKeys()->all();

        return $short === [] ? null : hash('sha256', json_encode($short));
    }

    /** @return array<string, mixed> */
    private static function build(int $businessId): array
    {
        $tz = AutomationSettings::for($businessId)->timezone;
        $today = Carbon::now($tz)->startOfDay();
        $days = self::days($businessId);
        $until = $today->copy()->addDays($days);

        $projects = Project::where('business_id', $businessId)->open()->get(['id', 'code', 'name', 'starts_at', 'ends_at']);
        $operations = EventOperation::where('business_id', $businessId)->whereIn('project_id', $projects->pluck('id'))
            ->whereIn('status', EventOperation::ACTIVE)->get(['id', 'project_id', 'code', 'setup_starts_at', 'event_starts_at', 'event_ends_at', 'teardown_ends_at']);

        /* تاريخُ المشروع في الخطّ: أوّلُ نافذة فعاليّةٍ قائمة، وإلّا بدءُ المشروع */
        $dated = [];
        foreach ($projects as $project) {
            $windows = $operations->where('project_id', $project->id);
            $start = $windows->map(fn (EventOperation $o) => $o->windowStart())->filter()->sort()->first();
            $end = $windows->map(fn (EventOperation $o) => $o->windowEnd())->filter()->sortDesc()->first();
            $date = $start ? $start->copy()->setTimezone($tz)->startOfDay() : ($project->starts_at ? Carbon::parse($project->starts_at->toDateString(), $tz) : null);
            $last = $end ? $end->copy()->setTimezone($tz)->startOfDay() : ($project->ends_at ? Carbon::parse($project->ends_at->toDateString(), $tz) : $date);

            if ($date === null || $date->greaterThan($until) || ($last ?? $date)->lessThan($today)) {
                continue;
            }

            $dated[$project->id] = ['project' => $project, 'date' => $date->lessThan($today) ? $today->copy() : $date];
        }

        $ids = array_keys($dated);
        $demand = self::demand($businessId, $ids, $operations->whereIn('project_id', $ids)->pluck('project_id', 'id')->all());

        $productIds = collect($demand)->flatMap(fn ($rows) => array_keys($rows))->unique()->values();
        $products = Product::where('business_id', $businessId)->stocked()->whereIn('id', $productIds)
            ->get(['id', 'name', 'sku', 'quantity', 'alert_qty', 'kind'])->keyBy('id');

        /* الخطُّ الزمنيّ: بترتيب التاريخ ثمّ المعرّف — ترتيبٌ ثابتٌ لا يتأرجح */
        uasort($dated, fn ($a, $b) => [$a['date']->timestamp, $a['project']->id] <=> [$b['date']->timestamp, $b['project']->id]);

        $running = [];
        $productRows = [];
        $projectRows = [];

        foreach ($dated as $projectId => $row) {
            $lines = [];
            foreach ($demand[$projectId] ?? [] as $productId => $need) {
                $product = $products->get($productId);
                if ($product === null || $need['quantity'] <= 0) {
                    continue;
                }

                $available = (int) $product->quantity;
                $committed = $running[$productId] ?? 0.0;
                $projected = round($available - $committed - $need['quantity'], 3);
                $running[$productId] = $committed + $need['quantity'];

                $line = [
                    'product_id' => $productId, 'name' => $product->name, 'sku' => $product->sku,
                    'available' => $available, 'committed' => round($committed, 3), 'required' => round($need['quantity'], 3),
                    'projected' => $projected, 'shortage' => $projected < 0 ? round(-$projected, 3) : 0.0,
                    'source' => $need['source'],
                ];
                $lines[] = $line;

                $productRows[$productId] ??= ['id' => $productId, 'name' => $product->name, 'sku' => $product->sku,
                    'available' => $available, 'alert_qty' => (int) $product->alert_qty, 'timeline' => [], 'shortage' => 0.0];
                $productRows[$productId]['timeline'][] = [
                    'project_id' => $projectId, 'code' => $row['project']->code, 'name' => $row['project']->name,
                    'date' => $row['date']->toDateString(), 'required' => $line['required'], 'source' => $need['source'],
                    'remaining' => $projected, 'shortage' => $line['shortage'],
                ];
                $productRows[$productId]['shortage'] = max($productRows[$productId]['shortage'], $line['shortage']);
            }

            usort($lines, fn ($a, $b) => [$b['shortage'], $a['name']] <=> [$a['shortage'], $b['name']]);
            $projectRows[$projectId] = [
                'id' => $projectId, 'code' => $row['project']->code, 'name' => $row['project']->name, 'date' => $row['date']->toDateString(),
                'lines' => $lines, 'shortages' => count(array_filter($lines, fn ($l) => $l['shortage'] > 0)),
            ];
        }

        uasort($productRows, fn ($a, $b) => [$b['shortage'], $a['name']] <=> [$a['shortage'], $b['name']]);

        return ['days' => $days, 'from' => $today->toDateString(), 'to' => $until->toDateString(), 'products' => $productRows, 'projects' => $projectRows];
    }

    /**
     * احتياجُ كلّ (مشروع · منتج) — مصدرٌ واحد: التشغيليُّ إن وُجد، وإلّا النطاق.
     *
     * @param  list<int>  $projectIds
     * @param  array<int, int>  $operationProject  معرّفُ الفعاليّة ← مشروعها
     * @return array<int, array<int, array{quantity: float, source: string}>>
     */
    private static function demand(int $businessId, array $projectIds, array $operationProject): array
    {
        $out = [];
        if ($projectIds === []) {
            return $out;
        }

        $stocked = fn (Collection $rows) => Product::where('business_id', $businessId)->stocked()
            ->whereIn('id', $rows->pluck('product_id')->filter()->unique())->pluck('id')->flip();

        $requirements = OperationEquipmentRequirement::where('business_id', $businessId)
            ->whereIn('event_operation_id', array_keys($operationProject))
            ->where('kind', OperationEquipmentRequirement::PRODUCT)->whereNotNull('product_id')
            ->whereIn('source', self::OWN_SOURCES)
            ->get(['event_operation_id', 'product_id', 'required_quantity', 'issued_quantity']);
        $ok = $stocked($requirements);

        foreach ($requirements as $r) {
            if (! isset($ok[$r->product_id])) {
                continue;
            }
            $projectId = $operationProject[$r->event_operation_id];
            $pending = max(0, (int) $r->required_quantity - (int) $r->issued_quantity);
            $out[$projectId][$r->product_id] ??= ['quantity' => 0.0, 'source' => self::CONFIRMED];
            $out[$projectId][$r->product_id]['quantity'] += $pending;
        }

        $items = ProjectItem::where('business_id', $businessId)->whereIn('project_id', $projectIds)->whereNotNull('product_id')
            ->get(['project_id', 'product_id', 'quantity', 'delivered_quantity']);
        $ok = $stocked($items);

        foreach ($items as $item) {
            /* ما له متطلّبٌ تشغيليّ في مشروعه لا يُعدّ من النطاق ثانيةً */
            if (! isset($ok[$item->product_id]) || (($out[$item->project_id][$item->product_id]['source'] ?? null) === self::CONFIRMED)) {
                continue;
            }
            $pending = max(0.0, (float) $item->quantity - (float) $item->delivered_quantity);
            $out[$item->project_id][$item->product_id] ??= ['quantity' => 0.0, 'source' => self::PROVISIONAL];
            $out[$item->project_id][$item->product_id]['quantity'] += $pending;
        }

        return $out;
    }
}
