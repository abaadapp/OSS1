<?php

namespace App\Support\Exports;

use App\Models\PayrollLine;
use App\Support\Permissions;
use Illuminate\Http\Request;

/**
 * مسيرات الرواتب — موظّفًا موظّفًا، بفترة الراتب (`payroll_runs.period`) لا بتاريخ الصرف.
 *
 * فترةُ الراتب وتاريخُ صرفه شيئان: راتبُ يناير قد يُصرف في فبراير. وهذا الملفّ
 * يقرأ الأولى؛ والثاني في «صرف الرواتب» (`PayrollPaymentsDataset`).
 *
 * والأجورُ والبدلاتُ والخصوماتُ لمن يقرأ الرواتب وحده (`PAYROLL_VIEW`) — يُسأل
 * هنا فوق قسم «الموظفين» كما تُسأل شاشتُها.
 */
final class PayrollDataset extends Dataset
{
    public static function slug(): string
    {
        return 'payroll';
    }

    public function title(): string
    {
        return __('مسيرات الرواتب');
    }

    public function authorize(Request $request): void
    {
        abort_unless(auth()->user()?->may(Permissions::PAYROLL_VIEW), 403);
    }

    public function columns(Request $request): array
    {
        return [
            self::col('run', 'المسيرة'), self::col('period', 'فترة الراتب'), self::col('run_status', 'حال المسيرة'),
            self::col('employee', 'الموظف'), self::col('basic', 'الأساسي', 'money'), self::col('allowances', 'البدلات', 'money'),
            self::col('overtime', 'الإضافي', 'money'), self::col('deductions', 'الخصومات', 'money'), self::col('net', 'الصافي', 'money'),
            self::col('method', 'طريقة الدفع'), self::col('paid_at', 'تاريخ الصرف'),
        ];
    }

    public function data(Request $request, int $bid): array
    {
        $query = PayrollLine::query()->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_lines.payroll_run_id')
            ->where('payroll_runs.business_id', $bid)
            ->when((int) $request->query('run'), fn ($q, $id) => $q->where('payroll_runs.id', $id))
            ->when(self::param($request, 'status'), fn ($q, $s) => $q->where('payroll_runs.status', $s))
            ->when(self::day($request, 'from'), fn ($q, $d) => $q->whereDate('payroll_runs.period', '>=', $d))
            ->when(self::day($request, 'to'), fn ($q, $d) => $q->whereDate('payroll_runs.period', '<=', $d))
            ->select('payroll_lines.*', 'payroll_runs.number as run_number', 'payroll_runs.period as run_period', 'payroll_runs.status as run_status')
            ->orderByDesc('payroll_runs.period')->orderBy('payroll_lines.employee_name')->orderBy('payroll_lines.id');

        $sums = (clone $query)->reorder()->toBase()->select([])
            ->selectRaw('COALESCE(SUM(payroll_lines.basic),0) b, COALESCE(SUM(payroll_lines.allowances),0) a, COALESCE(SUM(payroll_lines.overtime),0) o, COALESCE(SUM(payroll_lines.deductions),0) d, COALESCE(SUM(payroll_lines.net),0) n')
            ->first();

        return $this->fromQuery($query, fn (PayrollLine $l) => [
            'run' => $l->run_number,
            'period' => $l->run_period ? substr((string) $l->run_period, 0, 7) : null,
            'run_status' => $l->run_status,
            'employee' => $l->employee_name,
            'basic' => round((float) $l->basic, 3),
            'allowances' => round((float) $l->allowances, 3),
            'overtime' => round((float) $l->overtime, 3),
            'deductions' => round((float) $l->deductions, 3),
            'net' => round((float) $l->net, 3),
            'method' => $l->payment_method,
            'paid_at' => $l->paid && $l->paid_at ? optional($l->paid_at)->format('Y-m-d') : null,
        ]) + ['totals' => [
            'basic' => round((float) $sums->b, 3), 'allowances' => round((float) $sums->a, 3),
            'overtime' => round((float) $sums->o, 3), 'deductions' => round((float) $sums->d, 3), 'net' => round((float) $sums->n, 3),
        ]];
    }

    public function periodLabel(Request $request): string
    {
        return __('فترة الراتب').': '.parent::periodLabel($request);
    }
}
