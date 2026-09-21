<?php

namespace App\Support\Intelligence;

use App\Models\CustomerInvoice;
use App\Models\Expense;
use App\Models\Project;
use App\Models\StockAdjustment;
use App\Models\SupplierInvoice;
use App\Support\Money;
use App\Support\ProjectFinancials;
use App\Support\SupplierInvoices;
use App\Support\Vat;
use Illuminate\Support\Collection;

/**
 * ربحيّةُ المشروع — الصيغةُ في `ProjectFinancials` وحدَها، وهنا الاكتمالُ والقائمة.
 *
 * ═══ الهامشُ خطرٌ إن نقصت التكلفة ═══
 *
 * مشروعٌ عليه ثلاثةُ سنداتٍ وأحدُها بلا مشروع يقرأ هامشًا أعلى ممّا هو.
 * فكلُّ صفٍّ هنا يحمل اكتمالَه: `complete` حين لا تكلفةَ بلا مشروعٍ في نافذته،
 * و`partial` حين توجد، و`insufficient` حين لا إيرادَ ولا تكلفةَ بعد. والشاشةُ
 * تقول «الهامشُ على التكاليف المخصَّصة حاليًّا» حين يلزم.
 *
 * ═══ التعريفات ═══
 *
 * • المتعاقَد = القيمةُ المعتمَدة الحاليّة (الأصلُ + أوامرُ التغيير المقبولة).
 * • المفوتَر / المحصَّل / الذمم — من الفواتير الصادرة على المشروع.
 * • التكلفةُ المباشرة = سندُ المورّد المعتمَد + المصروفُ المدفوع + المستهلَك من الرفّ.
 * • المساهمة = الإيرادُ الصافي − التكلفةُ المباشرة. والهامش = المساهمة ÷ الإيراد.
 */
final class ProjectProfitability
{
    /** نافذةُ البحث عن تكاليفَ بلا مشروع حول موعد المشروع — أيّامًا */
    public const WINDOW_DAYS = 30;

    /** @return array<string, mixed> */
    public static function forProject(Project $project, ?array $f = null, ?array $pool = null): array
    {
        $f ??= ProjectFinancials::summary($project);
        $completeness = self::completeness($project, $f, $pool);
        $revenue = (float) $f['revenue'];

        return [
            'project' => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name, 'status' => $project->status,
                'customer' => $project->customer?->name, 'customer_id' => $project->customer_id,
                'starts_at' => $project->starts_at?->toDateString(), 'ends_at' => $project->ends_at?->toDateString()],
            'currency' => Money::of((int) $project->business_id),
            'baseline' => $f['approved_value'],
            'change_orders_delta' => round($f['current_value'] - $f['approved_value'], 3),
            'contracted' => $f['current_value'],
            'invoiced' => $f['invoiced'],
            'credited' => $f['credited'],
            'net_invoiced' => $f['net_invoiced'],
            'revenue' => $revenue,
            'collected' => $f['collected'],
            'receivable' => $f['customer_outstanding'],
            'direct_costs' => [
                'supplier_invoices' => $f['supplier_actual'],
                'expenses' => $f['expenses_paid'],
                'stock_consumed' => $f['stock_consumed'],
                'total' => $f['actual'],
            ],
            'committed' => $f['committed'],
            'expected_cost' => $f['expected_cost'],
            'contribution' => $f['realized_gross_profit'],
            'margin_percent' => $revenue > 0.0005 ? round($f['realized_gross_profit'] / $revenue * 100, 2) : null,
            'forecast_margin' => $f['forecast_margin'],
            'forecast_margin_percent' => $f['forecast_margin_percent'],
            'invoiced_above_approved' => $f['invoiced_above_approved'],
            'completeness' => $completeness->toArray(),
            'basis' => __('الإيرادُ صافٍ من الضريبة بعد إشعارات الدائن؛ والتكلفةُ سنداتُ مورّدين معتمَدة ومصروفاتٌ مدفوعة وما استُهلك من الرفّ — على المشروع وحده'),
        ];
    }

    /**
     * اكتمالُ التكلفة: أثمّة تكاليفُ بلا مشروعٍ في نافذة هذا المشروع؟
     */
    public static function completeness(Project $project, array $f, ?array $pool = null): Completeness
    {
        $bid = (int) $project->business_id;
        $reasons = [];

        if ($f['invoiced'] <= 0.0005 && $f['actual'] <= 0.0005) {
            return Completeness::insufficient([__('لا فواتيرَ ولا تكاليفَ على المشروع بعد')]);
        }

        [$from, $to] = self::window($project);
        if ($from !== null) {
            $pool ??= self::unallocatedPool($bid);
            $inWindow = fn (array $rows) => array_values(array_filter($rows, fn ($r) => $r[0] >= $from && $r[0] <= $to));
            $si = $inWindow($pool['si']);
            $ex = $inWindow($pool['ex']);

            if ($si !== []) {
                $reasons[] = __(':n سند مورّد بلا مشروع في نافذة المشروع (:v)', ['n' => count($si),
                    'v' => Money::format(round((float) array_sum(array_column($si, 1)), 3), Money::of($bid))]);
            }
            if ($ex !== []) {
                $reasons[] = __(':n مصروف بلا مشروع في نافذة المشروع (:v)', ['n' => count($ex),
                    'v' => Money::format(round((float) array_sum(array_column($ex, 1)), 3), Money::of($bid))]);
            }
        }

        if ($f['committed'] > 0.0005) {
            $reasons[] = __('التزاماتٌ لم تصر تكلفةً بعد: :v', ['v' => Money::format((float) $f['committed'], Money::of($bid))]);
        }
        if ($project->isOpen() && $f['invoiced'] < $f['current_value'] - 0.0005) {
            $reasons[] = __('لم يُفوتَر المشروع كاملًا بعد');
        }
        /* مشروعٌ قائم له إيرادٌ ولا تكلفةَ عليه — أقربُ إلى «لم تُسجَّل تكاليفُه» منه إلى هامشِ مئة (المرحلة 12 §22) */
        if ($project->isOpen() && $f['actual'] <= 0.0005 && $f['invoiced'] > 0.0005) {
            $reasons[] = __('لا تكاليفَ مسجَّلة على المشروع بعد');
        }

        return $reasons === [] ? Completeness::complete() : Completeness::partial($reasons);
    }

    /**
     * التكاليفُ بلا مشروعٍ في الشركة كلِّها — مرّةً واحدة، ثمّ تُقصّ على نافذة كلّ مشروع.
     *
     * كان الاكتمالُ يسأل القاعدةَ أربعَ مرّاتٍ لكلّ مشروع (المرحلة 12 §57).
     *
     * @return array{si: list<array{0: string, 1: float}>, ex: list<array{0: string, 1: float}>}
     */
    public static function unallocatedPool(int $bid): array
    {
        $registered = Vat::enabled($bid);
        $basis = $registered ? '(total - tax)' : 'total';

        $si = SupplierInvoice::where('business_id', $bid)->where('approval_status', SupplierInvoices::APPROVED)
            ->where('is_opening_balance', false)->whereNull('project_id')->toBase()
            ->selectRaw('issued_at, '.$basis.' AS v')->get()
            ->map(fn ($r) => [substr((string) $r->issued_at, 0, 10), round((float) $r->v, 3)])->all();
        $ex = Expense::where('business_id', $bid)->paid()->whereNull('project_id')->toBase()
            ->selectRaw('spent_at, amount AS v')->get()
            ->map(fn ($r) => [substr((string) $r->spent_at, 0, 10), round((float) $r->v, 3)])->all();

        return ['si' => $si, 'ex' => $ex];
    }

    /**
     * قائمةُ المشاريع بربحيّتها — لمدّةٍ (بتاريخ بدء المشروع) أو كلِّها؛ محدودةً ومرتَّبة.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public static function list(int $bid, ?Period $period, string $sort = 'margin', int $limit = 100): array
    {
        $projects = Project::where('business_id', $bid)->whereNotIn('status', [Project::DRAFT])
            ->when($period, fn ($q) => $q->whereDate('starts_at', '>=', $period->startDate())->whereDate('starts_at', '<', $period->endDate()))
            ->with('customer:id,name')->orderByDesc('starts_at')->orderByDesc('id')->limit($limit)->get();

        /* الصيغةُ الواحدة لكلّ المشاريع معًا — لا عشرةُ استعلاماتٍ لكلّ مشروع */
        $summaries = ProjectFinancials::summaries($bid, $projects);
        $pool = self::unallocatedPool($bid);
        $rows = $projects->map(fn (Project $p) => self::forProject($p, $summaries[(int) $p->id] ?? null, $pool));

        $rows = match ($sort) {
            'revenue' => $rows->sortByDesc('revenue'),
            'contribution' => $rows->sortByDesc('contribution'),
            'margin_asc' => $rows->sortBy(fn ($r) => $r['margin_percent'] ?? PHP_FLOAT_MAX),
            default => $rows->sortByDesc(fn ($r) => $r['margin_percent'] ?? -PHP_FLOAT_MAX),
        };

        $revenue = round((float) $rows->sum('revenue'), 3);
        $cost = round((float) $rows->sum(fn ($r) => $r['direct_costs']['total']), 3);

        return [
            'rows' => $rows->values()->all(),
            'totals' => [
                'projects' => $rows->count(),
                'contracted' => round((float) $rows->sum('contracted'), 3),
                'revenue' => $revenue,
                'collected' => round((float) $rows->sum('collected'), 3),
                'receivable' => round((float) $rows->sum('receivable'), 3),
                'direct_costs' => $cost,
                'contribution' => round($revenue - $cost, 3),
                'margin_percent' => $revenue > 0.0005 ? round(($revenue - $cost) / $revenue * 100, 2) : null,
                'partial' => $rows->filter(fn ($r) => $r['completeness']['status'] === Completeness::PARTIAL)->count(),
                'insufficient' => $rows->filter(fn ($r) => $r['completeness']['status'] === Completeness::INSUFFICIENT)->count(),
                'negative' => $rows->filter(fn ($r) => $r['contribution'] < -0.0005)->count(),
            ],
        ];
    }

    /**
     * الخطُّ الزمنيّ الماليّ للمشروع — كلُّ مستندٍ بتاريخه.
     *
     * @return list<array{at: string, kind: string, label: string, amount: float, direction: string, url: ?string}>
     */
    public static function timeline(Project $project): array
    {
        $bid = (int) $project->business_id;
        $rows = collect();

        if ($project->quotation) {
            $rows->push(['at' => optional($project->quotation->decided_at ?? $project->quotation->issued_at)->format('Y-m-d') ?? '', 'kind' => 'quotation',
                'label' => __('قبول العرض'), 'amount' => (float) $project->approved_value, 'direction' => 'in', 'url' => route('admin.quotations.show', $project->quotation_id)]);
        }
        foreach ($project->changeOrders()->where('status', 'accepted')->get() as $co) {
            $rows->push(['at' => optional($co->decided_at)->format('Y-m-d') ?? '', 'kind' => 'change_order', 'label' => __('أمر تغيير مقبول'),
                'amount' => (float) $co->total_delta, 'direction' => 'in', 'url' => route('admin.changeOrders.show', $co->id)]);
        }
        foreach (CustomerInvoice::where('business_id', $bid)->where('project_id', $project->id)->where('status', CustomerInvoice::ISSUED)->get() as $inv) {
            $rows->push(['at' => optional($inv->issued_at)->format('Y-m-d') ?? '', 'kind' => 'invoice', 'label' => __('فاتورة :number', ['number' => $inv->number ?: '#'.$inv->id]),
                'amount' => (float) $inv->total, 'direction' => 'in', 'url' => route('admin.customerInvoices.show', $inv->id)]);
            $rows->push(['at' => optional($inv->issued_at)->format('Y-m-d') ?? '', 'kind' => 'collection', 'label' => __('محصَّل على الفاتورة :number', ['number' => $inv->number ?: '#'.$inv->id]),
                'amount' => $inv->paidTotal(), 'direction' => 'cash', 'url' => route('admin.customerInvoices.show', $inv->id)]);
        }
        foreach (SupplierInvoice::where('business_id', $bid)->where('project_id', $project->id)->owed()->get() as $si) {
            $rows->push(['at' => optional($si->issued_at)->format('Y-m-d') ?? '', 'kind' => 'supplier_invoice', 'label' => __('سند مورّد :ref', ['ref' => $si->supplier_ref]),
                'amount' => (float) $si->total, 'direction' => 'out', 'url' => route('admin.purchases.invoices', ['q' => $si->supplier_ref])]);
        }
        foreach (Expense::where('business_id', $bid)->where('project_id', $project->id)->paid()->get() as $ex) {
            $rows->push(['at' => optional($ex->spent_at)->format('Y-m-d') ?? '', 'kind' => 'expense', 'label' => __('مصروف: :d', ['d' => $ex->description ?: $ex->type]),
                'amount' => (float) $ex->amount, 'direction' => 'out', 'url' => route('admin.expenses.index')]);
        }
        foreach (StockAdjustment::where('business_id', $bid)->where('project_id', $project->id)->get() as $sa) {
            $rows->push(['at' => optional($sa->adjusted_at)->format('Y-m-d') ?? '', 'kind' => 'stock',
                'label' => (float) $sa->quantity_delta < 0 ? __('صرف مستهلَكات: :n', ['n' => $sa->number]) : __('إرجاع مستهلَكات: :n', ['n' => $sa->number]),
                'amount' => abs($sa->valueImpact()), 'direction' => (float) $sa->quantity_delta < 0 ? 'out' : 'in', 'url' => null]);
        }

        return $rows->filter(fn ($r) => $r['amount'] > 0.0005)->sortBy('at')->values()->all();
    }

    /* ─────────────────────────── أدوات ─────────────────────────── */

    /** @return array{0: ?string, 1: ?string} */
    private static function window(Project $project): array
    {
        $start = $project->starts_at ?? $project->created_at?->copy();
        $end = $project->ends_at ?? $project->starts_at ?? $project->created_at?->copy();
        if ($start === null || $end === null) {
            return [null, null];
        }

        return [$start->copy()->subDays(self::WINDOW_DAYS)->toDateString(), $end->copy()->addDays(self::WINDOW_DAYS)->toDateString()];
    }

    /** الأرقامُ التي تُخفى عمّن لا يملك المال — يبقى الهيكل ويُفرَّغ المبلغ */
    public static function withoutMoney(array $row): array
    {
        foreach (['baseline', 'change_orders_delta', 'contracted', 'invoiced', 'credited', 'net_invoiced', 'revenue', 'collected', 'receivable', 'committed', 'expected_cost', 'contribution', 'margin_percent', 'forecast_margin', 'forecast_margin_percent'] as $k) {
            $row[$k] = null;
        }
        $row['direct_costs'] = ['supplier_invoices' => null, 'expenses' => null, 'stock_consumed' => null, 'total' => null];

        return $row;
    }

    /** @param  Collection<int, Project>  $projects */
    public static function many(Collection $projects): Collection
    {
        return $projects->map(fn (Project $p) => self::forProject($p));
    }
}
