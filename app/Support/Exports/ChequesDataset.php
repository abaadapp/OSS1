<?php

namespace App\Support\Exports;

use App\Http\Controllers\Admin\ChequeController;
use App\Models\CustomerPayment;
use Illuminate\Http\Request;

/**
 * الشيكات — بحالها (تحت التحصيل · محصَّل · مرتجع) وبيوم استلامها.
 *
 * الشاشةُ تعرض ثلاثمئة شيكٍ وتقول إن كان أكثر، والملفُّ يحمل كلَّ ما طابق.
 * والمجموعُ قيمةُ الشيكات في الحال المختارة — ورقٌ في اليد، لا مالٌ في بنك.
 */
final class ChequesDataset extends Dataset
{
    public static function slug(): string
    {
        return 'cheques';
    }

    public function title(): string
    {
        return __('الشيكات');
    }

    public function columns(Request $request): array
    {
        return [
            self::col('number', 'رقم السند'), self::col('customer', 'العميل'), self::col('reference', 'رقم الشيك'),
            self::col('received_at', 'تاريخ الاستلام'), self::col('due_at', 'تاريخ الاستحقاق'), self::col('status', 'الحالة'),
            self::col('late', 'متأخّر'), self::col('settled_at', 'تاريخ التسوية'), self::col('amount', 'المبلغ', 'money'),
        ];
    }

    public function data(Request $request, int $bid): array
    {
        $query = ChequeController::listQuery($request, $bid);

        return $this->fromQuery($query, fn (CustomerPayment $p) => ($row = ChequeController::listRow($p)) + [
            'late' => $row['overdue'] ? __('نعم') : null,
        ]) + ['totals' => ['amount' => round((float) (clone $query)->reorder()->sum('amount'), 3)]];
    }

    public function periodLabel(Request $request): string
    {
        return __('تاريخ الاستلام').': '.parent::periodLabel($request);
    }

    public function filterLines(Request $request): array
    {
        return [__('الحالة').': '.ChequeController::status($request)];
    }
}
