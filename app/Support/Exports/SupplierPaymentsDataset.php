<?php

namespace App\Support\Exports;

use App\Models\Supplier;
use App\Models\SupplierInvoicePayment;
use App\Support\BranchAccess;
use App\Support\Demo;
use App\Support\Permissions;
use Illuminate\Http\Request;

/**
 * سداد المورّدين — دفعةً دفعة بتاريخ الدفع (`paid_at`)، لا بتاريخ السند.
 *
 * كلُّ دفعةٍ لها قيدُها وإيصالُها، فالسندُ المسدَّدُ على ثلاث دفعاتٍ ثلاثةُ صفوف.
 * والمجموعُ ما خرج فعلًا في المدّة — لا قيمةُ السندات التي دُفع منها. ولمن يقرأ
 * السندات وحده، وبفروع أوامرها لمن قُيّد بفروعه.
 */
final class SupplierPaymentsDataset extends Dataset
{
    public static function slug(): string
    {
        return 'supplier-payments';
    }

    public function title(): string
    {
        return __('سداد المورّدين');
    }

    public function authorize(Request $request): void
    {
        abort_unless(auth()->user()?->may(Permissions::INVOICE_VIEW), 403);
    }

    public function columns(Request $request): array
    {
        return [
            self::col('paid_at', 'تاريخ الدفع'), self::col('supplier', 'المورّد'), self::col('reference', 'رقم السند'),
            self::col('project', 'المشروع'), self::col('source', 'من'), self::col('amount', 'المبلغ', 'money'),
        ];
    }

    public function data(Request $request, int $bid): array
    {
        $query = BranchAccess::limitThrough(SupplierInvoicePayment::where('supplier_invoice_payments.business_id', $bid), 'invoice.purchaseOrder')
            ->with(['invoice.supplier:id,name', 'invoice.project:id,code,name'])
            ->when(self::param($request, 'supplier'), fn ($q, $s) => $q->whereHas('invoice', fn ($i) => $i->where('supplier_id', (int) $s)))
            ->when(self::day($request, 'from'), fn ($q, $d) => $q->whereDate('paid_at', '>=', $d))
            ->when(self::day($request, 'to'), fn ($q, $d) => $q->whereDate('paid_at', '<=', $d))
            ->orderByDesc('paid_at')->orderByDesc('id');

        return $this->fromQuery($query, fn (SupplierInvoicePayment $p) => [
            'paid_at' => optional($p->paid_at)->format('Y-m-d'),
            'supplier' => $p->invoice?->supplier?->name,
            'reference' => $p->invoice?->supplier_ref,
            'project' => $p->invoice?->project ? $p->invoice->project->code.' — '.$p->invoice->project->name : null,
            'source' => $p->paid_from === SupplierInvoicePayment::BANK ? __('البنك') : __('الصندوق'),
            'amount' => round((float) $p->amount, 3),
        ]) + ['totals' => ['amount' => round((float) (clone $query)->reorder()->sum('amount'), 3)]];
    }

    public function periodLabel(Request $request): string
    {
        return __('تاريخ الدفع').': '.parent::periodLabel($request);
    }

    public function filterLines(Request $request): array
    {
        $bid = (int) (auth()->user()->business_id ?? Demo::bid());

        return array_values(array_filter([
            ($s = self::param($request, 'supplier')) ? __('المورّد').': '.(Supplier::where('business_id', $bid)->whereKey((int) $s)->value('name') ?? $s) : null,
        ]));
    }
}
