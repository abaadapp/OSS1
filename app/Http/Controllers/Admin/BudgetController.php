<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Support\Demo;
use App\Support\Intelligence\Budgeting;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** الموازنات — الشاشة. المرحلة 11. الحسابُ في `Budgeting` */
class BudgetController extends Controller
{
    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    private function find(int|string $id): Budget
    {
        return Budget::where('business_id', $this->bid())->whereKey($id)->firstOrFail();
    }

    private function gate(string $action): void
    {
        abort_if(! auth()->user()?->may($action), 403);
    }

    public function index(Request $request): Response
    {
        $this->gate(Permissions::BUDGETS_VIEW);
        $bid = $this->bid();
        $user = auth()->user();

        $budgets = Budget::where('business_id', $bid)->with('creator:id,name', 'approver:id,name')->orderByDesc('fiscal_year')->orderByDesc('version')->limit(50)->get();
        $selected = $request->integer('budget') > 0 ? $budgets->firstWhere('id', $request->integer('budget')) : ($budgets->firstWhere('status', Budget::APPROVED) ?? $budgets->first());
        $grouping = in_array($request->string('grouping')->toString(), ['month', 'quarter', 'year'], true) ? $request->string('grouping')->toString() : 'month';

        return Inertia::render('Admin/Management/Budgets', [
            'budgets' => $budgets->map(fn (Budget $b) => [
                'id' => $b->id, 'name' => $b->name, 'fiscal_year' => $b->fiscal_year, 'version' => $b->version, 'status' => $b->status,
                'status_label' => __(Budgeting::STATUS_LABELS[$b->status] ?? $b->status), 'created_by' => $b->creator?->name, 'approved_by' => $b->approver?->name,
                'approved_at' => $b->approved_at?->format('Y-m-d H:i'), 'revision_reason' => $b->revision_reason, 'revised_from_id' => $b->revised_from_id,
            ])->all(),
            'selected' => $selected === null ? null : [
                'id' => $selected->id, 'name' => $selected->name, 'fiscal_year' => $selected->fiscal_year, 'version' => $selected->version, 'status' => $selected->status, 'notes' => $selected->notes,
                'lines' => $selected->lines()->with('account:id,code,name')->get()->map(fn ($l) => ['account_id' => $l->account_id, 'code' => $l->account?->code, 'name' => $l->account?->name, 'kind' => $l->kind, 'month' => $l->month, 'planned_amount' => (float) $l->planned_amount, 'notes' => $l->notes])->all(),
                'versus_actual' => Budgeting::versusActual($selected, $grouping),
            ],
            'grouping' => $grouping,
            'accounts' => Budgeting::accounts($bid),
            'reading_labels' => collect(Budgeting::READING_LABELS)->map(fn ($l) => __($l))->all(),
            'may' => [
                'manage' => (bool) $user?->may(Permissions::BUDGETS_MANAGE),
                'approve' => (bool) $user?->may(Permissions::BUDGETS_APPROVE),
                'financial' => (bool) $user?->may(Permissions::ANALYTICS_FINANCIAL),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->gate(Permissions::BUDGETS_MANAGE);
        $data = $request->validate([
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'name' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['nullable', 'array', 'max:2000'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.month' => ['required', 'integer', 'min:1', 'max:12'],
            'lines.*.planned_amount' => ['required', 'numeric', 'min:0'],
            'lines.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $budget = Budgeting::create($this->bid(), $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['budget' => $e->getMessage()]);
        }

        return redirect()->route('admin.management.budgets', ['budget' => $budget->id])->with('toast', ['msg' => __('أُنشئت الموازنة'), 'type' => 'success']);
    }

    public function update(Request $request, int|string $id)
    {
        $this->gate(Permissions::BUDGETS_MANAGE);
        $budget = $this->find($id);
        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'lines' => ['sometimes', 'array', 'max:2000'],
            'lines.*.account_id' => ['required_with:lines', 'integer'],
            'lines.*.month' => ['required_with:lines', 'integer', 'min:1', 'max:12'],
            'lines.*.planned_amount' => ['required_with:lines', 'numeric', 'min:0'],
            'lines.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->attempt(fn () => Budgeting::update($budget, $data, auth()->user()), __('حُفظت الموازنة'));
    }

    public function approve(int|string $id)
    {
        $this->gate(Permissions::BUDGETS_APPROVE);

        $budget = $this->find($id); /* خارج المحاولة — غيابُه 404 لا رسالةٌ حمراء */

        return $this->attempt(fn () => Budgeting::approve($budget, auth()->user()), __('اعتُمدت الموازنة'));
    }

    public function revise(Request $request, int|string $id)
    {
        $this->gate(Permissions::BUDGETS_MANAGE);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $copy = Budgeting::revise($this->find($id), $data['reason'], auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['budget' => $e->getMessage()]);
        }

        return redirect()->route('admin.management.budgets', ['budget' => $copy->id])->with('toast', ['msg' => __('أُنشئت نسخةٌ منقَّحة'), 'type' => 'success']);
    }

    public function archive(int|string $id)
    {
        $this->gate(Permissions::BUDGETS_MANAGE);

        $budget = $this->find($id);

        return $this->attempt(fn () => Budgeting::archive($budget, auth()->user()), __('أُرشفت الموازنة'));
    }

    private function attempt(callable $fn, string $message)
    {
        try {
            $fn();
        } catch (RuntimeException $e) {
            return back()->withErrors(['budget' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => $message, 'type' => 'success']);
    }
}
