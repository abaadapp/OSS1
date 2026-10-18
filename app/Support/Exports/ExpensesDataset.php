<?php

namespace App\Support\Exports;

use App\Http\Controllers\Admin\ExpenseController;
use App\Models\Expense;
use App\Support\Demo;
use App\Support\ListFilters;
use App\Support\ReportPeriod;
use Illuminate\Http\Request;

/**
 * المصروفات — بمرشّحات شاشتها (الشهر أو الفترة المخصَّصة · البحث · النوع · الحالة).
 *
 * والإجماليُّ عمودُ المبلغ كلُّه، وبطاقتا «المدفوع» و«المستحقّ» فوقه: المستحقُّ
 * التزامٌ لم يُدفع، ويُقال إلى جانب المدفوع لا يذوب فيه.
 */
final class ExpensesDataset extends Dataset
{
    public static function slug(): string
    {
        return 'expenses';
    }

    public function title(): string
    {
        return __('المصروفات');
    }

    public function columns(Request $request): array
    {
        return [
            self::col('date', 'التاريخ'), self::col('reference', 'المرجع'), self::col('type', 'النوع'),
            self::col('description', 'البيان'), self::col('project', 'المشروع'), self::col('method', 'الوسيلة'),
            self::col('status', 'الحالة'), self::col('due', 'الاستحقاق'), self::col('amount', 'المبلغ', 'money'),
        ];
    }

    public function data(Request $request, int $bid): array
    {
        $query = ExpenseController::listQuery($request, $bid)->with('project:id,code,name');
        $paid = (float) (clone $query)->reorder()->paid()->sum('amount');
        $unpaid = (float) (clone $query)->reorder()->unpaid()->sum('amount');

        return $this->fromQuery($query, fn (Expense $e) => [
            'date' => optional($e->spent_at)->format('Y-m-d'),
            'reference' => $e->reference,
            'type' => $e->type,
            'description' => $e->description,
            'project' => $e->project ? $e->project->code.' — '.$e->project->name : null,
            'method' => $e->method,
            'status' => $e->status,
            'due' => optional($e->due_date)->format('Y-m-d'),
            'amount' => round((float) $e->amount, 3),
        ]) + [
            'totals' => ['amount' => round($paid + $unpaid, 3)],
            'cards' => [
                ['label' => __('المدفوع'), 'value' => Demo::moneyBase($paid)],
                ['label' => __('مستحقّ عليك'), 'value' => Demo::moneyBase($unpaid)],
            ],
        ];
    }

    public function periodLabel(Request $request): string
    {
        if (ListFilters::expenseRange($request) !== null) {
            return ReportPeriod::openLabel(self::param($request, 'from'), self::param($request, 'to'));
        }

        $span = ListFilters::expenseSpan($request);

        return $span ? __('شهر :month', ['month' => $span[0]->format('Y-m')]) : __('كل الشهور');
    }

    public function filterLines(Request $request): array
    {
        return array_values(array_filter([
            ($q = self::param($request, 'q')) ? __('البحث').': '.$q : null,
            ($type = self::param($request, 'type')) ? __('النوع').': '.$type : null,
            ($status = self::param($request, 'status')) ? __('الحالة').': '.$status : null,
        ]));
    }
}
