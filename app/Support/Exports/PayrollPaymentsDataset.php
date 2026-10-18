<?php

namespace App\Support\Exports;

use App\Models\PayrollLine;
use App\Support\Permissions;
use Illuminate\Http\Request;

/**
 * صرف الرواتب — ما صُرف فعلًا، بتاريخ الصرف (`payroll_lines.paid_at`).
 *
 * راتبُ يناير المصروفُ في فبراير يقع هنا في فبراير. والمجموعُ ما خرج من المال
 * في المدّة — لا صافي المسيرات. ولمن يقرأ الرواتب وحده (`PAYROLL_VIEW`).
 */
final class PayrollPaymentsDataset extends Dataset
{
    public static function slug(): string
    {
        return 'payroll-payments';
    }

    public function title(): string
    {
        return __('صرف الرواتب');
    }

    public function authorize(Request $request): void
    {
        abort_unless(auth()->user()?->may(Permissions::PAYROLL_VIEW), 403);
    }

    public function columns(Request $request): array
    {
        return [
            self::col('paid_at', 'تاريخ الصرف'), self::col('run', 'المسيرة'), self::col('period', 'فترة الراتب'),
            self::col('employee', 'الموظف'), self::col('method', 'طريقة الدفع'), self::col('net', 'المبلغ المصروف', 'money'),
        ];
    }

    public function data(Request $request, int $bid, int $limit): array
    {
        $query = PayrollLine::query()->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_lines.payroll_run_id')
            ->where('payroll_runs.business_id', $bid)
            ->where('payroll_lines.paid', true)
            ->when((int) $request->query('run'), fn ($q, $id) => $q->where('payroll_runs.id', $id))
            ->when(self::day($request, 'from'), fn ($q, $d) => $q->whereDate('payroll_lines.paid_at', '>=', $d))
            ->when(self::day($request, 'to'), fn ($q, $d) => $q->whereDate('payroll_lines.paid_at', '<=', $d))
            ->select('payroll_lines.*', 'payroll_runs.number as run_number', 'payroll_runs.period as run_period')
            ->orderByDesc('payroll_lines.paid_at')->orderBy('payroll_lines.id');

        return $this->fromQuery($query, $limit, fn (PayrollLine $l) => [
            'paid_at' => optional($l->paid_at)->format('Y-m-d'),
            'run' => $l->run_number,
            'period' => $l->run_period ? substr((string) $l->run_period, 0, 7) : null,
            'employee' => $l->employee_name,
            'method' => $l->payment_method,
            'net' => round((float) $l->net, 3),
        ]) + ['totals' => ['net' => round((float) (clone $query)->reorder()->toBase()->select([])->selectRaw('COALESCE(SUM(payroll_lines.net),0) n')->value('n'), 3)]];
    }

    public function periodLabel(Request $request): string
    {
        return __('تاريخ الصرف').': '.parent::periodLabel($request);
    }
}
