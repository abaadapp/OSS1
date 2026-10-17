<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerCreditNote;
use App\Models\CustomerPayment;
use App\Models\GoodsReceiptNote;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Support\Activity;
use App\Support\BranchAccess;
use App\Support\Demo;
use App\Support\DocumentPaper;
use App\Support\DocumentRenderer;

/**
 * أوراقُ النظام تخرج على ورق.
 *
 * وكانت تُنشأ ولا تُطبع: أمرُ شراءٍ يُرسل إلى مورّد بالهاتف، وسندُ استلامٍ
 * يُوقَّع على ورقةٍ تُكتب باليد. فما في النظام لا يُثبت شيئًا عند خلاف.
 *
 * وسندُ تسليم الطلب خرج مع «المبيعات»: كان يُطبع من الطلب القديم وحده.
 *
 * والقيدُ في كلّ دالّة واحد: `business_id` في الاستعلام لا في الشاشة. ورقمٌ
 * مُخمَّن في العنوان يفتح ورقةَ جارٍ إن غاب — ولا يظهر ذلك في أيّ سجلّ.
 */
class DocumentPrintController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    public function purchase(int $id)
    {
        $bid = $this->bid();
        $po = BranchAccess::limit(PurchaseOrder::where('business_id', $bid))->whereKey($id)
            ->with('items', 'supplier')->firstOrFail();

        Activity::log('report', 'طبع أمر الشراء: '.$po->number, ['subject_id' => $po->id]);

        return DocumentRenderer::pdf(
            DocumentRenderer::generic($bid, 'purchase', DocumentPaper::forPurchase($po)),
            'purchase-'.$po->number,
            __('أمر شراء').' '.$po->number,
        );
    }

    /** فاتورة مورّد — سندُ ما على المتجر لمورّده */
    public function supplierInvoice(int $id)
    {
        $bid = $this->bid();
        $invoice = BranchAccess::limitThrough(SupplierInvoice::where('business_id', $bid), 'purchaseOrder')->whereKey($id)
            ->with('supplier', 'purchaseOrder')->firstOrFail();

        Activity::log('report', 'طبع فاتورة المورّد: '.$invoice->supplier_ref, ['subject_id' => $invoice->id]);

        return DocumentRenderer::pdf(
            DocumentRenderer::generic($bid, 'supplier_invoice', DocumentPaper::forSupplierInvoice($invoice)),
            'supplier-invoice-'.$invoice->id,
            __('فاتورة مورّد').' '.$invoice->supplier_ref,
        );
    }

    /**
     * إشعار دائن — ورقةُ ما رُدّ من فاتورةٍ صدرت.
     *
     * والقيدُ على `business_id` كأخواته: الإشعارُ معلَّقٌ بفاتورة، ورقمٌ
     * مُخمَّن في العنوان يفتح إشعارَ جارٍ إن لم يُسأل عن المتجر.
     */
    public function creditNote(int $note)
    {
        $bid = $this->bid();
        $note = BranchAccess::limitThrough(CustomerCreditNote::where('business_id', $bid), 'invoice', null, false, true)->whereKey($note)
            ->with('invoice')->firstOrFail();

        Activity::log('report', 'طبع إشعار دائن: '.$note->number, ['subject_id' => $note->id]);

        return DocumentRenderer::pdf(
            DocumentRenderer::generic($bid, 'credit_note', DocumentPaper::forCreditNote($note)),
            'credit-note-'.$note->number,
            __('إشعار دائن').' '.$note->number,
        );
    }

    /** سند قبض — إقرارٌ بقبض مبلغٍ من عميل */
    public function customerReceipt(int $id)
    {
        $bid = $this->bid();
        $payment = CustomerPayment::where('business_id', $bid)->whereKey($id)
            ->with('customer', 'bankAccount', 'allocations.invoice')->firstOrFail();

        Activity::log('report', 'طبع سند قبض: '.$payment->number, ['subject_id' => $payment->id]);

        return DocumentRenderer::pdf(
            DocumentRenderer::generic($bid, 'customer_receipt', DocumentPaper::forCustomerReceipt($payment)),
            'receipt-'.$payment->number,
            __('سند قبض').' '.$payment->number,
        );
    }

    public function grn(int $id)
    {
        $bid = $this->bid();
        $note = BranchAccess::limit(GoodsReceiptNote::where('business_id', $bid))->whereKey($id)
            ->with('items', 'supplier', 'branch', 'purchaseOrder')->firstOrFail();

        Activity::log('report', 'طبع سند الاستلام: '.$note->number, ['subject_id' => $note->id]);

        return DocumentRenderer::pdf(
            DocumentRenderer::generic($bid, 'grn', DocumentPaper::forGrn($note)),
            'grn-'.$note->number,
            __('سند استلام بضاعة').' '.$note->number,
        );
    }
}
