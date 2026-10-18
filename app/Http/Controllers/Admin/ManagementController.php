<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FinancialForecast;
use App\Models\FinancialScenario;
use App\Support\Demo;
use App\Support\Intelligence\CashIntelligence;
use App\Support\Intelligence\CustomerIntelligence;
use App\Support\Intelligence\ExecutiveMetrics;
use App\Support\Intelligence\FinancialPerformance;
use App\Support\Intelligence\Forecasting;
use App\Support\Intelligence\ManagementAlerts;
use App\Support\Intelligence\ManagementReports;
use App\Support\Intelligence\OperationalIntelligence;
use App\Support\Intelligence\Period;
use App\Support\Intelligence\ProjectProfitability;
use App\Support\Intelligence\RevenueIntelligence;
use App\Support\Intelligence\ScenarioPlanning;
use App\Support\Intelligence\ServiceIntelligence;
use App\Support\Intelligence\Thresholds;
use App\Support\Operations\EventOperations;
use App\Support\Operations\Incidents;
use App\Support\Permissions;
use App\Support\Receivables;
use App\Support\ReportColumns;
use App\Support\Reports;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * الإدارة — الشاشات. المرحلة 11.
 *
 * ولا حسابَ هنا: كلُّ رقمٍ من `Intelligence\*`. وما في هذا الملفّ: قراءةُ
 * المدّة من الطلب، وسؤالُ الإذن **فعلًا فعلًا** (القسمُ يفتح الشاشة، والمالُ
 * بفعله)، وردُّ الشاشة.
 */
class ManagementController extends Controller
{
    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    /** @return array<string, bool> */
    private function may(): array
    {
        $user = auth()->user();

        return [
            'financial' => (bool) $user?->may(Permissions::ANALYTICS_FINANCIAL),
            'projects' => (bool) $user?->may(Permissions::ANALYTICS_PROJECTS),
            'customers' => (bool) $user?->may(Permissions::ANALYTICS_CUSTOMERS),
            'operations' => (bool) $user?->may(Permissions::ANALYTICS_OPERATIONS),
            'budgets' => (bool) $user?->may(Permissions::BUDGETS_VIEW),
            'budgets_manage' => (bool) $user?->may(Permissions::BUDGETS_MANAGE),
            'budgets_approve' => (bool) $user?->may(Permissions::BUDGETS_APPROVE),
            'forecasts' => (bool) $user?->may(Permissions::FORECASTS_VIEW),
            'forecasts_manage' => (bool) $user?->may(Permissions::FORECASTS_MANAGE),
            'forecasts_approve' => (bool) $user?->may(Permissions::FORECASTS_APPROVE),
            'scenarios' => (bool) $user?->may(Permissions::SCENARIOS_MANAGE),
            'reviews' => (bool) $user?->may(Permissions::MANAGEMENT_REVIEWS_MANAGE),
            'thresholds' => (bool) $user?->may(Permissions::MANAGEMENT_THRESHOLDS),
            'reports' => (bool) $user?->allows('reports'),
        ];
    }

    private function gate(string $action): void
    {
        abort_if(! auth()->user()?->may($action), 403);
    }

    private function period(Request $request, string $default = 'this_month'): Period
    {
        try {
            return Period::resolve($this->bid(), $request->string('period')->toString() ?: $default, $request->query('from'), $request->query('to'));
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function shared(Period $period): array
    {
        return [
            'period' => $period->toArray(),
            'periods' => collect(Period::LABELS)->map(fn ($l, $k) => ['key' => $k, 'label' => __($l)])->values()->all(),
            'may' => $this->may(),
        ];
    }

    /* ─────────────────────────── الشاشات ─────────────────────────── */

    public function index(Request $request): Response
    {
        $period = $this->period($request);

        return Inertia::render('Admin/Management/Overview', $this->shared($period) + [
            'overview' => ExecutiveMetrics::overview($this->bid(), $period, auth()->user()),
        ]);
    }

    public function financial(Request $request): Response
    {
        $this->gate(Permissions::ANALYTICS_FINANCIAL);
        $period = $this->period($request);
        $bid = $this->bid();

        return Inertia::render('Admin/Management/Financial', $this->shared($period) + [
            'snapshot' => FinancialPerformance::snapshot($bid, $period),
            'monthly' => FinancialPerformance::monthly($bid, Period::resolve($bid, 'last_12_months')),
            'ladder' => RevenueIntelligence::ladder($bid, $period),
            'aging' => Receivables::aging($bid),
            'unallocated' => FinancialPerformance::unallocatedCosts($bid, $period),
            'alerts' => ManagementAlerts::for($bid, $period),
            'thresholds' => Thresholds::describe($bid),
        ]);
    }

    public function projects(Request $request): Response
    {
        $this->gate(Permissions::ANALYTICS_PROJECTS);
        $all = $request->string('period')->toString() === 'all';
        $period = $all ? null : $this->period($request, 'last_12_months');
        $sort = in_array($request->string('sort')->toString(), ['margin', 'margin_asc', 'revenue', 'contribution'], true) ? $request->string('sort')->toString() : 'margin_asc';

        return Inertia::render('Admin/Management/Projects', $this->shared($period ?? Period::resolve($this->bid(), 'last_12_months')) + [
            'all' => $all,
            'sort' => $sort,
            'list' => ProjectProfitability::list($this->bid(), $period, $sort, 200),
        ]);
    }

    public function customers(Request $request): Response
    {
        $this->gate(Permissions::ANALYTICS_CUSTOMERS);
        $period = $this->period($request, 'last_12_months');
        $bid = $this->bid();

        return Inertia::render('Admin/Management/Customers', $this->shared($period) + [
            'list' => CustomerIntelligence::list($bid, $period),
            'concentration' => CustomerIntelligence::concentration($bid, $period),
            'average_payment_delay' => CustomerIntelligence::averagePaymentDelay($bid, $period),
        ]);
    }

    public function services(Request $request): Response
    {
        $this->gate(Permissions::ANALYTICS_CUSTOMERS);
        $period = $this->period($request, 'last_12_months');
        $bid = $this->bid();

        return Inertia::render('Admin/Management/Services', $this->shared($period) + [
            'list' => ServiceIntelligence::list($bid, $period),
            'funnel' => RevenueIntelligence::funnel($bid, $period),
        ]);
    }

    /** النقدُ والتوقّعُ والسيناريوهات — شاشةٌ واحدة */
    public function cash(Request $request): Response
    {
        $this->gate(Permissions::ANALYTICS_FINANCIAL);
        $bid = $this->bid();
        $may = $this->may();
        ScenarioPlanning::ensure($bid);

        $horizon = in_array((int) $request->integer('horizon'), CashIntelligence::HORIZONS, true) ? (int) $request->integer('horizon') : 90;
        $scenario = $request->integer('scenario') > 0 ? FinancialScenario::where('business_id', $bid)->whereKey($request->integer('scenario'))->with('assumptions')->first() : null;
        $assumptions = (array) $request->query('assumptions', []);

        $forecasts = $may['forecasts'] ? FinancialForecast::where('business_id', $bid)->with('creator:id,name', 'approver:id,name')->orderByDesc('id')->limit(30)->get() : collect();
        $approved = $forecasts->firstWhere('status', FinancialForecast::APPROVED);
        $selected = $request->integer('forecast') > 0 ? $forecasts->firstWhere('id', $request->integer('forecast')) : $approved;

        return Inertia::render('Admin/Management/Cash', $this->shared(Period::resolve($bid, 'this_month')) + [
            'current' => CashIntelligence::current($bid),
            'runway' => CashIntelligence::runway($bid),
            'horizon' => $horizon,
            'horizons' => CashIntelligence::HORIZONS,
            'defaults' => CashIntelligence::defaultAssumptions($bid),
            'projection' => $may['forecasts'] ? CashIntelligence::project($bid, $horizon, $assumptions, $scenario) : null,
            'scenarios' => FinancialScenario::where('business_id', $bid)->with('assumptions')->orderBy('id')->get()->map(fn (FinancialScenario $s) => [
                'id' => $s->id, 'name' => $s->name, 'kind' => $s->kind, 'kind_label' => __(ScenarioPlanning::KIND_LABELS[$s->kind] ?? $s->kind), 'description' => $s->description, 'active' => (bool) $s->active,
                'assumptions' => $s->assumptions->map(fn ($a) => ['id' => $a->id, 'key' => $a->key, 'key_label' => __(ScenarioPlanning::KEY_LABELS[$a->key] ?? $a->key), 'label' => $a->label,
                    'amount' => $a->amount === null ? null : (float) $a->amount, 'percent' => $a->percent === null ? null : (float) $a->percent,
                    'starts_on' => $a->starts_on?->toDateString(), 'months' => $a->months, 'notes' => $a->notes])->all(),
            ])->all(),
            'scenario_id' => $scenario?->id,
            'assumption_keys' => collect(ScenarioPlanning::KEY_LABELS)->map(fn ($l, $k) => ['key' => $k, 'label' => __($l), 'percent' => str_ends_with($k, '_pct')])->values()->all(),
            'forecasts' => $forecasts->map(fn (FinancialForecast $f) => [
                'id' => $f->id, 'name' => $f->name, 'status' => $f->status, 'horizon_days' => $f->horizon_days, 'base_date' => $f->base_date->toDateString(),
                'opening_cash' => (float) $f->opening_cash, 'closing' => (float) ($f->totals['closing'] ?? 0), 'lowest_closing' => (float) ($f->totals['lowest_closing'] ?? 0),
                'created_by' => $f->creator?->name, 'approved_by' => $f->approver?->name, 'approved_at' => $f->approved_at?->format('Y-m-d H:i'), 'created_at' => $f->created_at?->format('Y-m-d H:i'),
                'scenario' => $f->projection['scenario'] ?? null,
            ])->all(),
            'selected' => $selected === null ? null : [
                'id' => $selected->id, 'name' => $selected->name, 'status' => $selected->status, 'assumptions' => $selected->assumptions,
                'projection' => $selected->projection, 'totals' => $selected->totals, 'opening_cash' => (float) $selected->opening_cash,
                'versus_actual' => Forecasting::versusActual($selected),
            ],
        ]);
    }

    public function operations(Request $request): Response
    {
        $this->gate(Permissions::ANALYTICS_OPERATIONS);
        $period = $this->period($request, 'last_12_months');
        $bid = $this->bid();

        return Inertia::render('Admin/Management/Operations', $this->shared($period) + [
            'summary' => OperationalIntelligence::summary($bid, $period),
            'utilization' => OperationalIntelligence::utilization($bid, $period),
            'labels' => [
                'status' => collect(EventOperations::STATUS_LABELS)->map(fn ($l) => __($l))->all(),
                'incident_category' => collect(Incidents::CATEGORY_LABELS)->map(fn ($l) => __($l))->all(),
            ],
        ]);
    }

    /** التقاريرُ الإداريّة — تُعرض هنا وتُنزَّل من الباب العامّ */
    public function reports(Request $request): Response
    {
        $bid = $this->bid();
        $may = $this->may();
        $key = $request->string('report')->toString();
        $filters = $request->query();

        abort_if($key !== '' && ! in_array($key, ManagementReports::KEYS, true), 404);
        if ($key !== '') {
            $this->gate(ManagementReports::action($key));
        }

        /*
         * والمدّةُ المخصَّصةُ الخاطئة تُردّ بـ422 بسببها — كأخواتها في `period()`.
         * كانت تسقط خطأَ خادمٍ (500): تاريخٌ نهايتُه قبل بدايته ليس عطبًا في النظام.
         */
        try {
            $data = $key === '' ? null : ManagementReports::build($key, $bid, $filters);
            $period = Period::resolve($bid, (string) ($filters['period'] ?? 'this_month'), $filters['from'] ?? null, $filters['to'] ?? null);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return Inertia::render('Admin/Management/Reports', $this->shared($period) + [
            'catalog' => collect(Reports::ALL)->where('category', 'management')->map(fn ($r) => ['key' => $r['key'], 'title' => __($r['title']), 'desc' => __($r['desc'])])->values()->all(),
            'report' => $key ?: null,
            'title' => $key ? __(ManagementReports::TITLES[$key]) : null,
            'filters' => $filters,
            'data' => $data === null ? null : [
                'periodLabel' => $data['periodLabel'],
                'sections' => collect($data['sections'])->map(fn ($rows, $section) => [
                    'title' => __($section),
                    'columns' => ReportColumns::sectionColumns($key, $section),
                    'rows' => array_map(fn ($row) => ReportColumns::sectionCells($key, $section, $row), $rows),
                ])->values()->all(),
            ],
            'currency' => ManagementReports::currency($bid),
            'export' => $key && $may['reports'] ? [
                'xlsx' => route('admin.reports.export.xlsx', ['report' => $key] + $filters),
                'csv' => route('admin.reports.export.csv', ['report' => $key] + $filters),
                'pdf' => route('admin.reports.export.pdf', ['report' => $key] + $filters),
            ] : null,
        ]);
    }

    /* ─────────────────────────── العتبات ─────────────────────────── */

    public function thresholds(Request $request)
    {
        $this->gate(Permissions::MANAGEMENT_THRESHOLDS);
        $data = $request->validate(['thresholds' => ['required', 'array']]);

        try {
            Thresholds::save($this->bid(), (array) $data['thresholds'], auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['thresholds' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('حُفظت العتبات'), 'type' => 'success']);
    }
}
