<?php

namespace App\Support\Exports;

use App\Http\Controllers\Admin\Purchasing\SupplierInvoiceController;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Support\Demo;
use App\Support\Permissions;
use App\Support\SupplierInvoices;
use Illuminate\Http\Request;

/**
 * سندات المورّدين — بتاريخ السند، وبمرشّحات شاشتها (البحث · الحال · الاعتماد · المورّد).
 *
 * ولمن يقرأ السندات وحده (`INVOICE_VIEW`) كما في شاشتها. والإجماليُّ للمعتمَدة
 * وحدها: السندُ لا يصير ذمّةً إلا باعتماده، فالمعلَّقُ والمرفوضُ والملغى لا
 * تدخل «القيمة» ولا «المتبقّي» — كما تقرؤها بطاقةُ «المستحقّ» في الشاشة.
 */
final class SupplierInvoicesDataset extends Dataset
{
    public static function slug(): string
    {
        return 'supplier-invoices';
    }

    public function title(): string
    {
        return __('سندات المورّدين');
    }

    public function authorize(Request $request): void
    {
        abort_unless(auth()->user()?->may(Permissions::INVOICE_VIEW), 403);
    }

    public function columns(Request $request): array
    {
        return [
            self::col('reference', 'رقم السند'), self::col('issued_at', 'التاريخ'), self::col('supplier', 'المورّد'),
            self::col('project', 'المشروع'), self::col('order', 'أمر الشراء'), self::col('due_at', 'الاستحقاق'),
            self::col('approval', 'الاعتماد'), self::col('total', 'القيمة', 'money'), self::col('paid', 'المسدَّد', 'money'),
            self::col('outstanding', 'المتبقّي', 'money'),
        ];
    }

    public function data(Request $request, int $bid, int $limit): array
    {
        $query = SupplierInvoiceController::listQuery($request, $bid);
        $owed = (clone $query)->reorder()->where('approval_status', SupplierInvoices::APPROVED);
        $total = round((float) (clone $owed)->sum('total'), 3);
        $paid = round((float) (clone $owed)->sum('paid'), 3);

        return $this->fromQuery($query, $limit, fn (SupplierInvoice $i) => [
            'reference' => $i->supplier_ref,
            'issued_at' => optional($i->issued_at)->format('Y-m-d'),
            'supplier' => $i->supplier?->name,
            'project' => $i->project ? $i->project->code.' — '.$i->project->name : null,
            'order' => $i->purchaseOrder?->number,
            'due_at' => optional($i->due_at)->format('Y-m-d'),
            'approval' => $i->approval_status,
            'total' => round((float) $i->total, 3),
            'paid' => round((float) $i->paid, 3),
            'outstanding' => $i->outstanding(),
        ]) + [
            'totals' => [
                '_label' => __('الإجمالي (المعتمَدة فقط)'),
                'total' => $total, 'paid' => $paid,
                // المتبقّي سندًا سندًا لا يقلّ عن صفر — فيُجمع كذلك لا بطرح المجموعين
                'outstanding' => round((float) (clone $owed)->get(['total', 'paid'])->sum(fn ($i) => max(0, (float) $i->total - (float) $i->paid)), 3),
            ],
        ];
    }

    public function filterLines(Request $request): array
    {
        $bid = (int) (auth()->user()->business_id ?? Demo::bid());

        return array_values(array_filter([
            ($q = self::param($request, 'q')) ? __('البحث').': '.$q : null,
            ($s = self::param($request, 'supplier')) ? __('المورّد').': '.(Supplier::where('business_id', $bid)->whereKey((int) $s)->value('name') ?? $s) : null,
            ($st = self::param($request, 'status')) ? __('الحالة').': '.$st : null,
            ($a = self::param($request, 'approval')) ? __('الاعتماد').': '.$a : null,
        ]));
    }
}
