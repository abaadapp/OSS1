<?php

namespace App\Support\Exports;

use App\Http\Controllers\Admin\CustomerInvoiceController;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Support\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * فواتير العملاء — بتاريخ إصدارها، وبمرشّحات شاشتها (العميل · الفرع · الحال ·
 * السداد · المتأخّرة · الإصدار والاستحقاق).
 *
 * ═══ والإجماليُّ للصادرة وحدها ═══
 *
 * المسودّةُ لم تصدر، والملغاةُ عُكس قيدُها، فلا تدخلان في «الإجمالي» ولا
 * «المدفوع» ولا «المتبقّي». والمتبقّي بعد إشعارات الدائن (`outstandingSql`)
 * والشيكِ المرتجع (`paidSql`) — بالتعريف نفسِه الذي تقرؤه الذممُ والدفتر.
 */
final class CustomerInvoicesDataset extends Dataset
{
    public static function slug(): string
    {
        return 'customer-invoices';
    }

    public function title(): string
    {
        return __('فواتير العملاء');
    }

    public function validate(Request $request): void
    {
        parent::validate($request);
        $error = ReportPeriod::checkOpen(self::param($request, 'due_from'), self::param($request, 'due_to'));
        abort_if($error !== null, 422, (string) $error);
    }

    public function columns(Request $request): array
    {
        return [
            self::col('number', 'رقم الفاتورة'), self::col('issued_at', 'التاريخ'), self::col('customer', 'العميل'),
            self::col('project', 'المشروع'), self::col('status', 'الحالة'), self::col('state', 'السداد'),
            self::col('due_at', 'الاستحقاق'), self::col('total', 'الإجمالي', 'money'),
            self::col('paid', 'المدفوع', 'money'), self::col('outstanding', 'المتبقّي', 'money'),
        ];
    }

    public function data(Request $request, int $bid, int $limit): array
    {
        $query = CustomerInvoiceController::listQuery($request, $bid);
        $issued = (clone $query)->reorder()->where('customer_invoices.status', CustomerInvoice::ISSUED);
        $sum = fn (string $sql) => round((float) (clone $issued)->select(DB::raw('COALESCE(SUM('.$sql.'), 0) as v'))->value('v'), 3);

        return $this->fromQuery($query, $limit, fn (CustomerInvoice $i) => [
            'project' => $i->project ? $i->project->code.' — '.$i->project->name : null,
        ] + CustomerInvoiceController::listRow($i)) + [
            'totals' => [
                '_label' => __('الإجمالي (الصادرة فقط)'),
                'total' => $sum('customer_invoices.total'),
                'paid' => $sum(CustomerInvoice::paidSql()),
                'outstanding' => $sum(CustomerInvoice::outstandingSql()),
            ],
        ];
    }

    public function periodLabel(Request $request): string
    {
        $label = __('الإصدار').': '.ReportPeriod::openLabel(self::param($request, 'from'), self::param($request, 'to'));
        if (self::param($request, 'due_from') || self::param($request, 'due_to')) {
            $label .= ' · '.__('الاستحقاق').': '.ReportPeriod::openLabel(self::param($request, 'due_from'), self::param($request, 'due_to'));
        }

        return $label;
    }

    public function filterLines(Request $request): array
    {
        $bid = (int) (auth()->user()->business_id ?? 0);

        return array_values(array_filter([
            ($q = self::param($request, 'q')) ? __('البحث').': '.$q : null,
            ($c = self::param($request, 'customer_id')) ? __('العميل').': '.(Customer::where('business_id', $bid)->whereKey((int) $c)->value('name') ?? $c) : null,
            ($b = self::param($request, 'branch_id')) ? __('الفرع').': '.(Branch::where('business_id', $bid)->whereKey((int) $b)->value('name') ?? $b) : null,
            ($s = self::param($request, 'status')) ? __('الحالة').': '.$s : null,
            ($st = self::param($request, 'state')) ? __('السداد').': '.$st : null,
            $request->boolean('overdue') ? __('المتأخّرة وحدها') : null,
        ]));
    }
}
