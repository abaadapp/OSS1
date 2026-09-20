<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FinancialForecast;
use App\Models\FinancialScenario;
use App\Support\Demo;
use App\Support\Intelligence\Forecasting;
use App\Support\Intelligence\ScenarioPlanning;
use App\Support\Permissions;
use Illuminate\Http\Request;
use RuntimeException;

/** التوقّعاتُ والسيناريوهات — الكتابة. المرحلة 11. والقراءةُ في شاشة النقد */
class ForecastController extends Controller
{
    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    private function gate(string $action): void
    {
        abort_if(! auth()->user()?->may($action), 403);
    }

    public function store(Request $request)
    {
        $this->gate(Permissions::FORECASTS_MANAGE);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'horizon_days' => ['required', 'integer'],
            'scenario_id' => ['nullable', 'integer'],
            'assumptions' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $forecast = Forecasting::create($this->bid(), $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['forecast' => $e->getMessage()]);
        }

        return redirect()->route('admin.management.cash', ['forecast' => $forecast->id])->with('toast', ['msg' => __('حُفظ التوقّع مسودّةً'), 'type' => 'success']);
    }

    public function approve(int|string $id)
    {
        $this->gate(Permissions::FORECASTS_APPROVE);
        $forecast = FinancialForecast::where('business_id', $this->bid())->whereKey($id)->firstOrFail();

        return $this->attempt(fn () => Forecasting::approve($forecast, auth()->user()), __('اعتُمد التوقّع — وأُجمّد'), 'forecast');
    }

    public function destroy(int|string $id)
    {
        $this->gate(Permissions::FORECASTS_MANAGE);
        $forecast = FinancialForecast::where('business_id', $this->bid())->whereKey($id)->firstOrFail();

        return $this->attempt(fn () => Forecasting::discard($forecast, auth()->user()), __('حُذفت المسودّة'), 'forecast');
    }

    /* ─────────────────────────── السيناريوهات ─────────────────────────── */

    public function storeScenario(Request $request)
    {
        $this->gate(Permissions::SCENARIOS_MANAGE);
        $data = $this->scenarioData($request);

        return $this->attempt(fn () => ScenarioPlanning::create($this->bid(), $data, auth()->user()), __('أُنشئ السيناريو'), 'scenario');
    }

    public function updateScenario(Request $request, int|string $id)
    {
        $this->gate(Permissions::SCENARIOS_MANAGE);
        $scenario = FinancialScenario::where('business_id', $this->bid())->whereKey($id)->firstOrFail();
        $data = $this->scenarioData($request);

        return $this->attempt(fn () => ScenarioPlanning::update($scenario, $data, auth()->user()), __('حُفظ السيناريو'), 'scenario');
    }

    public function destroyScenario(int|string $id)
    {
        $this->gate(Permissions::SCENARIOS_MANAGE);
        $scenario = FinancialScenario::where('business_id', $this->bid())->whereKey($id)->firstOrFail();

        return $this->attempt(fn () => ScenarioPlanning::delete($scenario, auth()->user()), __('حُذف السيناريو'), 'scenario');
    }

    /** @return array<string, mixed> */
    private function scenarioData(Request $request): array
    {
        return $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
            'assumptions' => ['sometimes', 'array', 'max:50'],
            'assumptions.*.key' => ['required_with:assumptions', 'string', 'max:40'],
            'assumptions.*.label' => ['nullable', 'string', 'max:160'],
            'assumptions.*.amount' => ['nullable', 'numeric'],
            'assumptions.*.percent' => ['nullable', 'numeric'],
            'assumptions.*.starts_on' => ['nullable', 'date'],
            'assumptions.*.months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'assumptions.*.notes' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function attempt(callable $fn, string $message, string $bag)
    {
        try {
            $fn();
        } catch (RuntimeException $e) {
            return back()->withErrors([$bag => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => $message, 'type' => 'success']);
    }
}
