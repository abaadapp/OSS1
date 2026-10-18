<?php

namespace App\Support\Exports;

use App\Http\Controllers\Admin\FinanceController;
use App\Support\Books;
use App\Support\Demo;
use Illuminate\Http\Request;

/**
 * الحركة المالية — بمرشّحات شاشتها (البحث · الحركة · من · إلى · الترتيب).
 *
 * والمجاميعُ بطاقاتٌ لا سطرٌ واحد: الحركةُ دخلٌ ومصروفٌ وتحويل، وجمعُها في
 * عمودٍ واحد رقمٌ بلا معنى. والملغاةُ تبقى صفًّا موسومًا وتخرج من البطاقات.
 */
final class TransactionsDataset extends Dataset
{
    public static function slug(): string
    {
        return 'transactions';
    }

    public function title(): string
    {
        return __('الحركة المالية');
    }

    public function columns(Request $request): array
    {
        return [
            self::col('date', 'التاريخ'), self::col('reference', 'السند'), self::col('description', 'البيان'),
            self::col('kind_label', 'الحركة'), self::col('method', 'الوسيلة'), self::col('type', 'النوع'),
            self::col('amount', 'المبلغ', 'money'), self::col('employee', 'الموظف'), self::col('state', 'الحالة'),
        ];
    }

    public function data(Request $request, int $bid): array
    {
        $query = FinanceController::listQuery($request, $bid);
        $summary = FinanceController::listTotals($query);

        return $this->fromQuery($query, fn ($t) => FinanceController::listRow($t) + [
            'state' => $t->isCancelled() ? __('ملغاة') : __('سارية'),
        ]) + [
            'cards' => [
                ['label' => __('المقبوضات'), 'value' => Demo::moneyBase($summary['in'])],
                ['label' => __('المصروفات'), 'value' => Demo::moneyBase($summary['out'])],
                ['label' => __('التحويلات'), 'value' => Demo::moneyBase($summary['transfers'])],
            ],
        ];
    }

    public function filterLines(Request $request): array
    {
        return array_values(array_filter([
            ($q = self::param($request, 'q')) ? __('البحث').': '.$q : null,
            ($kind = self::param($request, 'kind')) ? __('الحركة').': '.__(Books::label($kind)) : null,
        ]));
    }
}
