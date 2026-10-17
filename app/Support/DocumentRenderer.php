<?php

namespace App\Support;

use App\Http\Controllers\Admin\CustomerInvoiceController;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\GoodsReceiptNote;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Support\Document\Branding;
use App\Support\Document\PaperSize;
use App\Support\Document\Version;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;

/**
 * الرسمُ — قالبٌ واحد يخدم الطباعة والمعاينة معًا.
 *
 * والمعاينةُ في المحرّر تُرسم بالقالب الذي يُطبع لا بنسخةٍ ثانية منه في
 * الشاشة. وكانت الشاشة ترسم إيصالًا بيدها في JSX: صندوقٌ يشبه الورقة ولا
 * يقرأ ملفَّ الرسم — فيُصلَح سطرٌ في الورقة ولا يتغيّر في المعاينة، ويضبط
 * التاجر قالبَه على شكلٍ لا يخرج من الطابعة.
 */
class DocumentRenderer
{
    /**
     * معاملُ حجم الخطّ من اسمه — في موضعٍ واحد لا في كلّ قالب.
     *
     * ومعاملٌ لا مقاسٌ بالبكسل: الورقة تُقاس بالنقطة، ومقاسٌ واحد للجسد
     * كان يكبر وحده فيصير الجدول والترويسة أصغر ممّا حولهما. والمعامل
     * يضرب المقاسات كلَّها فتكبر الورقة معًا — انظر pdf/partials/style.
     */
    public static function scale(string $font): float
    {
        return match ($font) {
            'صغير' => 0.9, 'كبير' => 1.14, default => 1.0
        };
    }

    /**
     * ورقةٌ عامّة مرسومةً — أمرُ شراءٍ أو سندُ استلامٍ أو نقلٍ أو تسليم.
     *
     * @param  array<string,mixed>  $doc  بيانُ المستند من `DocumentPaper`
     * @param  array<string,mixed>|null  $override  قيمٌ لم تُحفظ بعد — للمعاينة
     * @param  Model|null  $source  الصفُّ الذي رُسمت منه — لبناء رابطها العامّ
     */
    public static function generic(int $businessId, string $type, array $doc, ?array $override = null, ?Model $source = null, ?string $version = null): string
    {
        $tpl = DocumentTemplates::settings($businessId, $type, $override);
        $business = DocumentPaper::business($businessId);
        $scale = self::scale((string) $tpl['font']);

        /*
         * و«إظهار الشعار» يُطفئ الشعار فعلًا.
         *
         * الترويسةُ تقرأ الشعار من صفّ المتجر مباشرةً، فمقبضٌ مطفأٌ كان
         * يُطبع معه الشعار ولا رسالةَ تقول لماذا. والصفُّ يُنسَخ إلى مصفوفةٍ
         * بلا شعار ولا يُعدَّل: تعديلُ النموذج يُغيّر ما تقرؤه أوراقٌ أخرى
         * في الطلب نفسه.
         */
        if (! ($tpl['show_logo'] ?? false) && $business !== null) {
            $business = collect(['name', 'type', 'city', 'phone', 'email', 'address'])
                ->mapWithKeys(fn (string $k) => [$k => $business->{$k} ?? ''])
                ->all();
        }

        return self::html(view(Version::views($version).'.'.self::template($type), [
            /* والأوراقُ العامّة على A4: أمرُ شراءٍ لا يُطبع على شريطٍ حراريّ */
            'paper' => PaperSize::A4,
            'tokens' => Branding::tokens($businessId, $scale),
            'coverImage' => Branding::cover($businessId),
            'doc' => $doc,
            'tpl' => $tpl,
            'business' => $business,
            'headerNote' => trim((string) ($tpl['header'] ?? '')),
            'scale' => $scale,
            'vatNumber' => Paper::vatNumber($businessId),
            /*
             * ولا رمزَ ورقةٍ أونلاين في الأوراق العامّة: أمرُ الشراء وسندُ
             * الاستلام يحملان تكلفةَ البضاعة ويمضيان إلى المورّد، فلا يُفتح
             * لهما بابٌ عامّ. وكان سندُ تسليم الطلب وحدَه يحمله — وخرج مع
             * «المبيعات».
             */
            'paperUrl' => '',
            /*
             * والسعرُ علمٌ واحد يحكم العمودين والمجموع معًا: لو حُسب في كلٍّ
             * منها لخرجت ورقةٌ بلا أسعارٍ في السطور وبمجموعٍ في أسفلها.
             */
            'showPrices' => (bool) ($tpl['show_prices'] ?? true),
            'showParties' => (bool) (($tpl['show_customer'] ?? false) || ($tpl['show_supplier'] ?? false)),
        ]));
    }

    /**
     * مخرجُ الرسم الواحد — كلُّ ورقةٍ تخرج من هنا.
     *
     * ═══ ولمَ الخاناتُ تُحوَّل على الرسم لا على البيان ═══
     *
     * المطلوبُ قاعدةٌ واحدة: **كلُّ** رقمٍ في المستند بخاناتٍ غربيّة. وبيانُ
     * الورقة يبلغ القالبَ من طرقٍ شتّى: مصفوفةُ `DocumentPaper`، وصفُّ الطلب
     * نفسُه في الشريط الحراريّ، ولقطةُ الهويّة، وسطورُ `Paper::brand`،
     * وتذييلٌ كتبه التاجر في «قوالب الأوراق». وتحويلُ كلٍّ منها في موضعه
     * يعني خمسةَ مواضعَ تُنسى واحدةٌ منها — وهو بعينه ما نهى عنه الطلب:
     * «بصورة مركزية بدل hacks موزعة داخل كل template».
     *
     * فالتحويلُ على النصّ المرسوم: لا يبلغه شيءٌ إلّا وقد مرّ به.
     *
     * ═══ وهو آمنٌ على الرسم ═══
     *
     * لا خانةَ عربيّةً في وسم HTML ولا في اسم صنفٍ من أصناف CSS ولا في
     * `data:` — كلُّها لاتينيّةٌ بحتة. فما يُحوَّل نصُّ البشر وحدَه.
     *
     * ولا تُمسّ قيمةٌ محسوبة: هذا تحويلُ خاناتٍ لا صياغةُ مبالغ، والمبالغُ
     * تخرج من `Money::format` غربيّةً أصلًا — انظر `Support\Digits`.
     */
    private static function html(View $view): string
    {
        return (string) Digits::western($view->render());
    }

    /**
     * ملفُّ رسمِ نوعٍ — من هنا وحده.
     *
     * والأسماءُ في السجلّ لا تصلح أسماءَ ملفّات: `grn` مفتاحُ إعداداتٍ
     * اختير حين كُتب السجلّ، و`customer_invoice` فيه شرطة سفليّة. وخريطةٌ
     * تُكتب في كلّ موضعٍ يرسم تُنسي التاليَ نوعًا.
     */
    private static function template(string $type): string
    {
        return match ($type) {
            'customer_invoice' => 'customer-invoice',
            'quotation' => 'quotation',
            'purchase' => 'purchase',
            'grn' => 'grn',
            'supplier_invoice' => 'supplier-invoice',
            'credit_note' => 'credit-note',
            'customer_receipt' => 'customer-receipt',
        };
    }

    /**
     * الورقة كما تُعاين في المحرّر — أيًّا كان نوعها.
     *
     * وكانت لورقة البيع وجهان يُعاينان (فاتورةٌ مقصوصة وإيصالُ صندوق) —
     * وخرجت مع «المبيعات»: كانت تُطبع من الطلب القديم وحده.
     */
    public static function preview(int $businessId, string $type, ?array $override = null): string
    {
        if ($type === CustomerInvoiceController::PAPER_TYPE) {
            return self::customerInvoice($businessId, $override);
        }

        return self::generic($businessId, $type, self::latestOrSample($businessId, $type), $override);
    }

    /**
     * معاينةُ فاتورة العميل في محرّر القوالب.
     *
     * ═══ وببانيها هو لا بنسخةٍ هنا ═══
     *
     * الورقةُ تُبنى في `CustomerInvoiceController::paper` — تقرؤها منها
     * شاشةُ الإنشاء وزرُّ الطباعة. ولو بُنيت هنا ثالثةً لافترقت الثلاثةُ عند
     * أوّل متغيّرٍ يُضاف: يُضبط الشعارُ فيُرى في موضعين ويغيب عن ثالث.
     *
     * ولا مسودّةَ تُكتب: أحدثُ فاتورةٍ إن وُجدت، وإلّا **ورقةٌ غير محفوظة**
     * بلا صفٍّ ولا رقمٍ من التسلسل ولا قيد — انظر `CustomerInvoices::draft`.
     * ومحرّرُ قوالبَ يترك خلفه مسودّاتٍ في دفتر التاجر عطبٌ لا ميزة.
     *
     * @param  array<string,mixed>|null  $override  قيمٌ لم تُحفظ بعد
     */
    public static function customerInvoice(int $businessId, ?array $override = null): string
    {
        $invoice = BranchAccess::invoices(CustomerInvoice::where('business_id', $businessId))
            ->with('items', 'customer')->latest('id')->first()
            ?? CustomerInvoices::draft($businessId, Customer::where('business_id', $businessId)->first(), [
                'issued_at' => now()->toDateString(),
                'payment_terms_days' => 30,
                'notes' => __('يُرجى السداد قبل تاريخ الاستحقاق.'),
            ], [[
                'description' => __('بند تجريبي'),
                'quantity' => 2,
                'unit_price' => 75,
                'discount' => 0,
            ]]);

        return InvoiceBranding::render(
            $businessId,
            null,
            fn () => CustomerInvoiceController::paper(
                $businessId,
                $invoice,
                $invoice->exists ? $invoice->paidTotal() : 0.0,
                $invoice->exists ? $invoice->outstanding() : (float) $invoice->total,
                BankAccount::where('business_id', $businessId)->orderBy('id')->first(),
                $override,
            )->render(),
        );
    }

    /**
     * أحدثُ مستندٍ من نوعه — أو مثالٌ إن لم يُنشأ بعد.
     *
     * @return array<string,mixed>
     */
    private static function latestOrSample(int $businessId, string $type): array
    {
        $record = match ($type) {
            // والمثالُ من فروع من قُيّد بفروع — انظر BranchAccess
            'purchase' => BranchAccess::limit(PurchaseOrder::where('business_id', $businessId))
                ->with('items', 'supplier')->latest('id')->first(),
            'grn' => BranchAccess::limit(GoodsReceiptNote::where('business_id', $businessId))
                ->with('items', 'supplier', 'branch', 'purchaseOrder')->latest('id')->first(),
            'supplier_invoice' => BranchAccess::limitThrough(SupplierInvoice::where('business_id', $businessId), 'purchaseOrder')
                ->with('supplier', 'purchaseOrder')->latest('id')->first(),
            'credit_note' => BranchAccess::limitThrough(CustomerCreditNote::where('business_id', $businessId), 'invoice', null, false, true)
                ->with('invoice')->latest('id')->first(),
            'customer_receipt' => CustomerPayment::where('business_id', $businessId)
                ->with('customer', 'bankAccount', 'allocations.invoice')->latest('id')->first(),
            default => null,
        };

        if ($record === null) {
            return DocumentPaper::sample($businessId, $type);
        }

        return match ($type) {
            'purchase' => DocumentPaper::forPurchase($record),
            'grn' => DocumentPaper::forGrn($record),
            'supplier_invoice' => DocumentPaper::forSupplierInvoice($record),
            'credit_note' => DocumentPaper::forCreditNote($record),
            'customer_receipt' => DocumentPaper::forCustomerReceipt($record),
        };
    }

    /**
     * ملفُّ PDF من HTML — بالمحرّك الواحد.
     *
     * وكان يبني mpdf بيده بهوامش تخصّه: ١٢ مم هنا و١٤ هناك و١٥ في ثالث،
     * وستّةُ مواضع في النظام تفعل مثله. انظر App\Support\Pdf.
     */
    /**
     * @param  string|null  $context  سياقُ المستند في تذييل **كلّ** صفحة
     *
     * وصفحةٌ ثانيةٌ لا تحمل إلّا «٢ / ٣» ورقةٌ لا يُعرف إلى أيّ حزمةٍ تعود
     * إن سقطت من ملفّ. والتذييلُ في `MpdfDriver::frame` يقبل هذا السياق
     * ويطبعه إلى جانب رقم الصفحة — وكان يُترك فارغًا في أوراق المستندات
     * كلِّها، فتخرج صفحاتُها الثانيةُ عارية.
     */
    public static function pdf(string $html, string $name, ?string $context = null)
    {
        return Pdf::sheet($html, $name, PaperSize::A4, false, null, $context);
    }
}
