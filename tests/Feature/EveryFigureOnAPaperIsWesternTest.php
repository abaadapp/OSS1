<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\CustomerInvoiceController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\GoodsReceiptNote;
use App\Models\GoodsReceiptNoteItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Digits;
use App\Support\DocumentPaper;
use App\Support\DocumentRenderer;
use App\Support\DocumentTemplates;
use App\Support\InvoiceBranding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * كلُّ رقمٍ على الورقة بخانةٍ غربيّة — ولو كانت الورقة عربيّة.
 *
 * ═══ ولمَ قاعدةً لا ذوقًا ═══
 *
 * المستندُ التجاريُّ يُقرأ خارج المتجر: مورّدٌ يقابل أمرَ الشراء بعرض سعره،
 * ومحاسبٌ يُدخل رقمَ الفاتورة في دفتره، وجهةٌ حكوميّة تبحث عن المبلغ في
 * ملفّ. و«٥٠٠» تُنسخ فلا تُطابق «500»، ولا يجدها بحث.
 *
 * ═══ وما يكتبه النظامُ غربيٌّ أصلًا — والعطبُ فيما يكتبه التاجر ═══
 *
 * التواريخُ من `->format()` والمبالغُ من `number_format`: غربيّةٌ كلُّها.
 * لكنّ التاجر يكتب بيده — اسمَ صنفٍ فيه «٥٠٠ جرام»، وملاحظةً فيها تاريخ،
 * وتذييلَ ورقةٍ في «قوالب الأوراق». وهذه تبلغ الورقةَ كما كُتبت.
 *
 * فالتحويلُ على النصّ المرسوم في مخرجٍ واحد لكلّ عائلة:
 * `DocumentRenderer::html` لأوراق السجلّ، و`InvoiceBranding::render` لفاتورة
 * العميل. وهذا الملفّ يقيسها من أبوابها لا من المخرج — فحارسٌ يقيس
 * الدالّةَ التي كتبها يمرّ ولو لم تُستدعَ. (وكانت بينها ورقةُ البيع
 * وشريطُها وسندُ التسليم — خرجت مع «المبيعات».)
 *
 * ولا يُمسّ المحفوظ: `Digits::western` تحوّل ما يُعرض. ومن كتب «٥٠٠»
 * يجدها كما كتبها في شاشة التعديل.
 */
class EveryFigureOnAPaperIsWesternTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $user;

    /** ما يكتبه التاجر بيده بخاناتٍ عربيّة — يبلغ كلَّ ورقةٍ في النظام */
    private const TYPED = 'باقة ورد ٥٠٠ جرام';

    private const NOTE = 'يُسلَّم قبل ٢٠٢٦-٠٩-٣٠';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create([
            'name' => 'زهور الخليج', 'type' => 'محل ورود', 'city' => 'مسقط', 'status' => 'نشط',
        ]);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);
        $this->user = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'paper', 'value' => 'A4']);
        $this->actingAs($this->user);
    }

    /** لا خانةَ عربيّةً في هذا الرسم — ويُقال أيُّ ورقةٍ إن وُجدت */
    private function assertWestern(string $html, string $paper): void
    {
        preg_match_all('/[٠-٩۰-۹]/u', $html, $m);

        $this->assertSame(
            [],
            $m[0],
            "خاناتٌ عربيّة على «{$paper}»: ".implode(' ', array_unique($m[0])),
        );
    }

    /** فاتورةُ عميلٍ كتب التاجرُ بندَها وملاحظتَها بيده بخاناتٍ عربيّة */
    private function invoice(): CustomerInvoice
    {
        $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'شركة الواحة']);

        $invoice = CustomerInvoice::create([
            'business_id' => $this->business->id, 'customer_id' => $customer->id,
            'branch_id' => $this->branch->id, 'number' => 'CINV-000001', 'status' => CustomerInvoice::ISSUED,
            'issued_at' => now(), 'customer_name' => 'شركة الواحة', 'notes' => self::NOTE,
            'subtotal' => 12.5, 'discount_total' => 0, 'tax_total' => 0.625, 'total' => 13.125,
        ]);
        CustomerInvoiceItem::create([
            'customer_invoice_id' => $invoice->id, 'description' => self::TYPED,
            'quantity' => 1, 'unit_price' => 12.5, 'discount' => 0,
            'tax_rate' => 5, 'tax_amount' => 0.625, 'line_total' => 13.125,
        ]);

        return $invoice->load('items');
    }

    /** فاتورةُ العميل من باب طباعتها — كما يرسمها `PdfController::customerInvoice` */
    private function printed(CustomerInvoice $invoice): string
    {
        $bid = (int) $this->business->id;

        return InvoiceBranding::render($bid, null, fn () => CustomerInvoiceController::paper(
            $bid, $invoice, $invoice->paidTotal(), $invoice->outstanding(), null,
        )->render());
    }

    /**
     * فاتورةُ عميلٍ مكتوبةٌ بيد التاجر — من باب طباعتها.
     *
     * كانت ورقةَ البيع على A4 — خرجت مع «المبيعات»؛ والقاعدةُ نفسُها على
     * فاتورة العميل، ببندٍ وملاحظةٍ فيهما خاناتٌ عربيّة.
     */
    public function test_a_typed_customer_invoice_carries_no_eastern_figure(): void
    {
        $html = $this->printed($this->invoice());

        $this->assertStringContainsString('باقة ورد 500 جرام', $html, 'البندُ المكتوب لم يبلغ الورقة محوَّلًا');
        $this->assertStringContainsString('2026-09-30', $html, 'الملاحظةُ المكتوبة لم تبلغ الورقة محوَّلةً');
        $this->assertWestern($html, 'فاتورة العميل المطبوعة');
    }

    /** وأمرُ الشراء — يمضي إلى مورّدٍ خارج المتجر */
    public function test_the_purchase_order_carries_no_eastern_figure(): void
    {
        $this->assertWestern(
            DocumentRenderer::generic(
                (int) $this->business->id,
                'purchase',
                DocumentPaper::forPurchase($this->purchaseOrder()),
            ),
            'أمر الشراء',
        );
    }

    /** وسندُ الاستلام — يُوقَّع عند باب المخزن */
    public function test_the_goods_receipt_carries_no_eastern_figure(): void
    {
        $po = $this->purchaseOrder();

        $note = GoodsReceiptNote::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'supplier_id' => $po->supplier_id, 'purchase_order_id' => $po->id,
            'number' => 'GRN-000001', 'received_at' => now()->toDateString(),
            'receiver' => 'سالم', 'notes' => self::NOTE, 'status' => 'معتمد',
        ]);
        GoodsReceiptNoteItem::create([
            'goods_receipt_note_id' => $note->id, 'name' => self::TYPED,
            'quantity' => 3, 'cost' => 4.0,
        ]);

        $this->assertWestern(
            DocumentRenderer::generic(
                (int) $this->business->id,
                'grn',
                DocumentPaper::forGrn($note->load('items', 'supplier', 'branch', 'purchaseOrder')),
            ),
            'سند الاستلام',
        );
    }

    /**
     * وفاتورةُ العميل — وهي التي تمضي إلى وزارةٍ أو شركة.
     *
     * ومخرجُها غيرُ مخرج أخواتها: تُبنى في متحكّمها وتُرسم عبر
     * `InvoiceBranding::render` — فتُقاس من بابها هي.
     */
    public function test_the_customer_invoice_carries_no_eastern_figure(): void
    {
        $this->assertWestern(
            DocumentRenderer::customerInvoice((int) $this->business->id),
            'فاتورة العميل',
        );
    }

    /**
     * وتذييلُ الورقة يكتبه التاجر — وهو أشدُّ ما يحمل خاناتٍ عربيّة.
     *
     * «للاستفسار اتصل بـ٩١٢٣٤٥٦٧» سطرٌ يُكتب مرّةً ويُطبع على كلّ ورقة.
     */
    public function test_a_footer_typed_in_eastern_figures_is_converted(): void
    {
        DocumentTemplates::save((int) $this->business->id, 'purchase', [
            'footer' => 'للاستفسار: ٩١٢٣٤٥٦٧',
        ]);

        $html = DocumentRenderer::generic(
            (int) $this->business->id,
            'purchase',
            DocumentPaper::forPurchase($this->purchaseOrder()),
        );

        $this->assertStringContainsString('91234567', $html, 'التذييلُ لم يُحوَّل');
        $this->assertWestern($html, 'أمر الشراء بتذييلٍ مكتوب');
    }

    /**
     * والقيمةُ المحفوظة لا تُمسّ — التحويلُ للعرض لا للبيانات.
     *
     * من كتب «٥٠٠ جرام» في اسم صنفه يجدها كما كتبها حين يفتح شاشة التعديل.
     * وتصحيحُ ما يكتبه الناس في قاعدتهم بلا طلبهم يُفسد أكثر ممّا يُصلح.
     */
    public function test_the_stored_value_is_left_as_the_merchant_typed_it(): void
    {
        // كانت ورقةَ البيع — خرجت مع «المبيعات»؛ والقاعدةُ نفسُها على فاتورة العميل
        $invoice = $this->invoice();

        $this->printed($invoice);

        $this->assertSame(self::TYPED, $invoice->items->first()->fresh()->description);
        $this->assertSame(self::NOTE, $invoice->fresh()->notes);
    }

    /** ولا يُفسد التحويلُ ما ليس نصًّا — عددٌ يبقى عددًا و`null` يبقى فراغًا */
    public function test_the_converter_leaves_non_text_alone(): void
    {
        $this->assertSame(
            ['n' => 5, 'ok' => true, 'nothing' => null, 'text' => '500'],
            Digits::western(['n' => 5, 'ok' => true, 'nothing' => null, 'text' => '٥٠٠']),
        );

        // وشجرةٌ بعمق: بيانُ المستند بنودٌ داخل مصفوفة
        $this->assertSame(
            ['items' => [['name' => 'وردة 12']]],
            Digits::western(['items' => [['name' => 'وردة ١٢']]]),
        );
    }

    private function purchaseOrder(): PurchaseOrder
    {
        $supplier = Supplier::create([
            'business_id' => $this->business->id, 'name' => 'مشتل الربيع',
        ]);

        $po = PurchaseOrder::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name,
            'number' => 'PO-000001', 'status' => 'مسودة', 'notes' => self::NOTE,
            'items_subtotal' => 12.0, 'total' => 12.0, 'ordered_at' => now(),
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'name' => self::TYPED,
            'quantity' => 3, 'cost' => 4.0, 'line_total' => 12.0,
        ]);

        return $po->load('items', 'supplier');
    }
}
