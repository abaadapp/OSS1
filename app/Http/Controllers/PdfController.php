<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\CustomerInvoiceController;
use App\Http\Controllers\Admin\QuotationController;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Quotation;
use App\Support\Activity;
use App\Support\BranchAccess;
use App\Support\Demo;
use App\Support\InvoiceBranding;
use App\Support\Pdf;
use App\Support\PublicDocument;
use App\Support\Quotations;
use App\Support\Receivables;
use App\Support\Reports;
use Illuminate\Http\Request;

class PdfController extends Controller
{
    /** ملخّص المبيعات (PDF) — من فواتير العملاء الصادرة (`Reports::salesReport`) */
    public function salesReport()
    {
        // الفترة تُورَث من الشاشة وتُطبع في الترويسة: ورقةٌ مطبوعة لا مبدّل
        // فوقها، فإن لم تقل فترتها قُرئت على أنها فترة قارئها
        // الورقة من حمولة الشاشة نفسها — انظر Support\Reports::salesReport
        $report = Reports::salesReport(request()->query('range'));
        $range = $report['range'];

        $html = view('pdf.sales-report', [
            'business' => Demo::business(auth()->user()->business_id ?? Demo::bid()),
            'branch' => Demo::scopeName(false),
            'stats' => Reports::summaryRows($report['summary']),
            'salesSeries' => $report['salesSeries'],
            'payments' => Demo::paymentBreakdown($range),
            'topProducts' => $report['topSellingProducts'],
            'rangeLabel' => Demo::rangeLabel($range),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'صدّر تقرير الإيرادات (PDF)');

        return $this->pdf($html, 'revenue-report-'.$range.'-'.now()->format('Y-m-d'));
    }

    /** تقرير المنتجات (PDF) */
    public function productsReport()
    {
        $products = Demo::products(null, request());
        $html = view('pdf.products-report', [
            'business' => Demo::business(auth()->user()->business_id ?? Demo::bid()),
            'branch' => Demo::scopeName(false),
            'products' => $products,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'صدّر قائمة المنتجات (PDF)');

        return $this->pdf($html, 'products-report-'.now()->format('Y-m-d'));
    }

    /** تقرير جرد المخزون (PDF) */
    public function inventoryReport()
    {
        $inventory = Demo::inventory();
        $html = view('pdf.inventory-report', [
            'business' => Demo::business(auth()->user()->business_id ?? Demo::bid()),
            'branch' => Demo::scopeName(true),
            'inventory' => $inventory,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'صدّر جرد المخزون (PDF)');

        return $this->pdf($html, 'inventory-report-'.now()->format('Y-m-d'));
    }

    /** تقرير المصروفات (PDF) */
    public function expensesReport()
    {
        $expenses = Demo::expenses(request());
        $html = view('pdf.expenses-report', [
            'business' => Demo::business(auth()->user()->business_id ?? Demo::bid()),
            'branch' => Demo::scopeName(false),
            'expenses' => $expenses,
            'total' => array_sum(array_map(fn ($e) => (float) $e['amount'], $expenses)),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'صدّر المصروفات (PDF)');

        return $this->pdf($html, 'expenses-report-'.now()->format('Y-m-d'));
    }

    

    /**
     * فاتورةُ عميل (PDF) — بالبنية القائمة لا بمحرّكٍ ثانٍ.
     *
     * ولا معرّفاتٍ محاسبيّةً على الورق: الزبونُ يقرأ رقمَ الفاتورة لا رقمَ
     * القيد ولا معرّفَ الصفّ.
     */
    public function customerInvoice($id)
    {
        $bid = auth()->user()->business_id ?? Demo::bid();
        $invoice = BranchAccess::invoices(CustomerInvoice::where('business_id', $bid))->whereKey($id)
            ->with('items')->firstOrFail();

        /*
         * والورقةُ تُبنى حيث تُبنى المعاينة — لا نسخةٌ ثانية هنا.
         *
         * كانت قائمةُ المتغيّرات مكتوبةً في الموضعين، فما يُضاف لأحدهما لا
         * يبلغ الآخر: يُضبط الشعارُ فيُرى في الشاشة ويغيب عن الطابعة.
         * وباللغةِ التي اختارها صاحبُ المحلّ لورقته، لا بلغة من ضغط الزرّ.
         */
        $html = InvoiceBranding::render($bid, null, fn () => CustomerInvoiceController::paper(
            $bid,
            $invoice,
            $invoice->paidTotal(),
            $invoice->outstanding(),
            BankAccount::where('business_id', $bid)->orderBy('id')->first(),
            /*
             * ورمزُ التحقّق يُبنى عند الطباعة لا عند المعاينة.
             *
             * الورقةُ تُرسَل إلى جهةٍ تراجعها، فتحمل طريقَها إلى سجلّ
             * المتجر. والمعاينةُ قد تُرسم لمسودّةٍ لا صفَّ لها — ورمزٌ لها
             * يقود إلى ٤٠٤ في يد التاجر.
             */
            null,
            ['paperUrl' => PublicDocument::url($invoice) ?? ''],
        )->render());

        Activity::log('report', 'صدّر فاتورة عميل: '.$invoice->number, ['subject_id' => $invoice->id]);

        return $this->pdf($html, 'customer-invoice-'.$invoice->number);
    }

    /**
     * عرضُ سعر (PDF) — الورقةُ التي تُرسَل إلى العميل.
     *
     * وتُبنى حيث تُبنى المعاينة (`QuotationController::paper`) لا نسخةً
     * ثانية هنا: قائمتان تفترقان عند أوّل متغيّرٍ يُضاف.
     *
     * وباللغة التي اختارها صاحبُ الشركة لورقتها، لا بلغة من ضغط الزرّ:
     * من يمسح الرمزَ يحمل ورقةً مطبوعة، وصفحةٌ بغير لغتها تجعله يقارن
     * نصّين مختلفين ليعرف أنّهما الشيءُ نفسُه.
     */
    public function quotation($id)
    {
        $bid = auth()->user()->business_id ?? Demo::bid();
        $quotation = Quotation::where('business_id', $bid)->whereKey($id)
            ->with('items')->firstOrFail();

        $html = InvoiceBranding::render($bid, null, fn () => QuotationController::paper(
            $bid,
            $quotation,
            null,
            /*
             * ورمزُ الورقة يُبنى عند الطباعة لا عند المعاينة — ولا لمسودّة:
             * رمزٌ لمسودّةٍ يفتح للعميل ورقةً لم تخرج إليه بعد.
             */
            ['paperUrl' => $quotation->status === Quotation::DRAFT
                ? '' : (PublicDocument::url($quotation) ?? '')],
        )->render());

        Activity::log('report', 'صدّر عرض سعر: '.Quotations::label($quotation), [
            'subject_id' => $quotation->id, 'subject_type' => 'quotation',
        ]);

        return $this->pdf($html, 'quotation-'.($quotation->number ?: $quotation->id));
    }

    /** كشفُ حساب عميل (PDF) — رصيدٌ افتتاحيٌّ ثمّ حركةٌ برصيدٍ جارٍ */
    public function customerAccountStatement(Request $request, $customer)
    {
        $bid = auth()->user()->business_id ?? Demo::bid();
        $customer = Customer::where('business_id', $bid)->whereKey($customer)->firstOrFail();

        $from = $request->date('from') ?? now()->startOfYear();
        $to = $request->date('to') ?? now()->endOfDay();

        $html = view('pdf.customer-account-statement', [
            'customer' => $customer,
            'business' => Demo::business($bid),
            'statement' => Receivables::statement($bid, (int) $customer->id, $from, $to),
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'صدّر كشف حساب العميل: '.$customer->name, ['subject_id' => $customer->id]);

        return $this->pdf($html, 'customer-account-'.$customer->id);
    }

    /** ورقةُ A4 — بالمحرّك الواحد لا بإعدادٍ يخصّ هذا الملفّ */
    private function pdf(string $html, string $name)
    {
        return Pdf::a4($html, $name);
    }

    /** تقرير مالية التاجر (PDF) */
    public function financeReport()
    {
        // فترةٌ واحدة لكل ما في الورقة، وتُكتب فيها — انظر financeXlsx
        $range = Demo::range(request()->query('range'));

        $html = view('pdf.finance-report', [
            'business' => Demo::business(auth()->user()->business_id ?? Demo::bid()),
            'branch' => Demo::scopeName(false),
            'stats' => Demo::financeStats($range),
            'payments' => Demo::paymentMethods($range),
            'transactions' => Demo::transactions($range, null),
            'rangeLabel' => Demo::rangeLabel($range),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'صدّر التقرير المالي (PDF)');

        return $this->pdf($html, 'finance-report-'.now()->format('Y-m-d'));
    }
}
