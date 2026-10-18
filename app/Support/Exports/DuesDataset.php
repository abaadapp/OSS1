<?php

namespace App\Support\Exports;

use App\Models\Expense;
use App\Models\PayrollRun;
use App\Models\SupplierInvoice;
use Illuminate\Http\Request;

/**
 * المبالغ المستحقة — ما على الشركة الآن، كما تقرؤه شاشتُها: مصروفاتٌ لم تُدفع،
 * وسنداتُ مورّدين لم تُسدَّد كاملة، ومسيراتُ رواتب معتمدة لم تُصرف.
 *
 * لقطةٌ حاليّة لا مدّة، وكلُّ مستندٍ صفٌّ مرّةً واحدة بنوعه. والمسيرةُ بصافيها
 * غيرِ المصروف لا موظّفًا موظّفًا — كما على الشاشة.
 */
final class DuesDataset extends Dataset
{
    public static function slug(): string
    {
        return 'dues';
    }

    public function title(): string
    {
        return __('المبالغ المستحقة');
    }

    public function validate(Request $request): void {}

    public function columns(Request $request): array
    {
        return [
            self::col('kind', 'النوع'), self::col('reference', 'المرجع'), self::col('party', 'الجهة / البيان'),
            self::col('due', 'الاستحقاق'), self::col('late', 'متأخّر'), self::col('amount', 'المبلغ', 'money'),
        ];
    }

    public function data(Request $request, int $bid, int $limit): array
    {
        $today = now()->startOfDay();
        $rows = [];

        foreach (Expense::where('business_id', $bid)->unpaid()->orderByRaw('due_date is null')->orderBy('due_date')->orderByDesc('id')->get() as $e) {
            $rows[] = [
                'kind' => __('مصروف'), 'reference' => $e->reference, 'party' => $e->description ?: $e->type,
                'due' => optional($e->due_date)->format('Y-m-d'),
                'late' => $e->due_date !== null && $e->due_date->lt($today) ? __('نعم') : null,
                'amount' => round((float) $e->amount, 3),
            ];
        }
        foreach (SupplierInvoice::where('business_id', $bid)->with('supplier')->whereColumn('paid', '<', 'total')
            ->orderByRaw('due_at is null')->orderBy('due_at')->orderByDesc('id')->get() as $i) {
            $rows[] = [
                'kind' => __('سند مورّد'), 'reference' => $i->supplier_ref, 'party' => $i->supplier?->name ?? '—',
                'due' => optional($i->due_at)->format('Y-m-d'),
                'late' => $i->isOverdue() ? __('نعم') : null,
                'amount' => $i->outstanding(),
            ];
        }
        foreach (PayrollRun::where('business_id', $bid)->with('lines')->where('status', 'معتمدة')->orderByDesc('period')->get() as $r) {
            $net = round((float) $r->lines->where('paid', false)->sum('net'), 3);
            if ($net > 0) {
                $rows[] = [
                    'kind' => __('مسيرة رواتب'), 'reference' => $r->number,
                    'party' => __('رواتب :period — :n موظفين', ['period' => $r->period->format('Y-m'), 'n' => $r->lines->where('paid', false)->count()]),
                    'due' => null, 'late' => null, 'amount' => $net,
                ];
            }
        }

        $total = count($rows);

        return [
            'rows' => array_slice($rows, 0, $limit),
            'total' => $total,
            'totals' => ['amount' => round(array_sum(array_column($rows, 'amount')), 3)],
        ];
    }

    public function periodLabel(Request $request): string
    {
        return __('لقطة حاليّة حتى :date', ['date' => now()->toDateString()]);
    }
}
