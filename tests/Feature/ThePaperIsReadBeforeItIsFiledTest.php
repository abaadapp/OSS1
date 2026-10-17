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
use App\Support\Document\Pdf\Driver;
use App\Support\Document\Theme;
use App\Support\DocumentPaper;
use App\Support\DocumentRenderer;
use App\Support\InvoiceBranding;
use App\Support\Paper;
use App\Support\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Tests\TestCase;

/**
 * الورقةُ تُقرأ قبل أن تُحفظ — وما يحرسه هذا الملفّ هو قابليّتُها للقراءة.
 *
 * وهي الأشياءُ التي لا يكشفها فتحُ فاتورةٍ واحدة بالعين: من يجرّب بصنفين
 * عربيّين لا يرى ما يقع لاسمٍ إنجليزيّ في ورقةٍ عربيّة، ومن يطبع فاتورةً
 * من صفحةٍ لا يرى الصفحةَ الثانية حين تسقط من الحزمة.
 */
class ThePaperIsReadBeforeItIsFiledTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create([
            'name' => 'زهور الخليج', 'type' => 'محل ورود', 'city' => 'مسقط', 'status' => 'نشط',
            'address' => 'شارع السلطان قابوس، الخوير', 'phone' => '+968 9123 4567', 'email' => 'sales@zohoor.om',
        ]);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'فرع الخوير']);

        $u = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($u);
    }

    /**
     * فاتورةُ عميلٍ ببنودٍ بأسمائها.
     *
     * وكانت قواعدُ هذا الملفّ تُقاس على ورقة البيع — وخرجت مع «المبيعات».
     * وفاتورةُ العميل تُرسم بالتخطيط نفسِه وجزئيّاتِه، فتُقاس عليها.
     */
    private function invoice(array $names = ['باقة ورد']): CustomerInvoice
    {
        $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'شركة الواحة']);

        $invoice = CustomerInvoice::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'customer_id' => $customer->id, 'customer_name' => 'شركة الواحة',
            'number' => 'CI-2026-000412', 'status' => CustomerInvoice::ISSUED,
            'issued_at' => now(), 'due_at' => now()->addDays(30), 'payment_terms_days' => 30,
            'subtotal' => 12 * count($names), 'discount_total' => 0, 'tax_total' => 0, 'total' => 12 * count($names),
        ]);

        foreach ($names as $i => $n) {
            CustomerInvoiceItem::create([
                'customer_invoice_id' => $invoice->id, 'description' => $n,
                'quantity' => 1, 'unit_price' => 12, 'discount' => 0,
                'tax_rate' => 0, 'tax_amount' => 0, 'line_total' => 12, 'sort_order' => $i,
            ]);
        }

        return $invoice->load('items');
    }

    /**
     * ورقةُ فاتورة العميل مرسومةً — من الباب الذي يطبعها.
     *
     * @param  array<string,mixed>  $over  قيمُ قالبٍ لم تُحفظ
     */
    private function sheet(?CustomerInvoice $invoice = null, array $over = []): string
    {
        $bid = $this->business->id;
        $invoice ??= $this->invoice();

        return InvoiceBranding::render($bid, null, fn () => CustomerInvoiceController::paper(
            $bid,
            $invoice,
            $invoice->paidTotal(),
            $invoice->outstanding(),
            null,
            $over ?: null,
        )->render());
    }

    /* ═══════════ الطرفان متقابلان ═══════════ */

    /**
     * الورقةُ تحمل البائعَ والمشتري معًا — لا المشتريَ وحده.
     *
     * وكانت بياناتُ البائع خمسةَ أسطرٍ رماديّةٍ في ركن الترويسة، ومقابلَها
     * نصفُ سطرٍ خالٍ تحت «فاتورة إلى». وجهةٌ تراجع الورقةَ تبحث عن عنوان
     * البائع ورقمه الضريبيّ حيث تجدهما في كلّ فاتورة: كتلةً معنونة.
     */
    public function test_the_paper_names_both_sides(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_number', 'value' => 'OM1100234567']);

        $html = $this->sheet(null, ['show_vat_no' => true]);

        $this->assertStringContainsString(__('البائع'), $html, 'الورقةُ لا تعرّف بائعَها');
        $this->assertStringContainsString(__('العميل'), $html, 'الورقةُ لا تعرّف مشتريَها');
        /*
         * كانت ورقةَ البيع، وكتلةُ بائعها تحمل عنوانَ المبنى — خرجت مع
         * «المبيعات». وفاتورةُ العميل لا تطبع عنوانَ المبنى بشرط صاحب النظام
         * (يحرسه `TheInvoiceCarriesTheShopsIdentityTest`)، فيُقاس هاتفُ
         * البائع مكانَه: ما يُثبت البائعَ في كتلته المعنونة.
         */
        $this->assertStringContainsString('+968 9123 4567', $html);
        $this->assertStringContainsString('OM1100234567', $html);

        /*
         * والاسمُ مرّتان: مرّةً في كتلة الهويّة ومرّةً في تذييل الصفحة.
         *
         * وكان مرّةً واحدة حين لم يكن للورقة تذييلٌ يحمله. والمرجعُ
         * المعتمد يضع اسمَ المُصدِر في أسفل كلّ صفحةٍ صغيرًا هادئًا: صفحةٌ
         * تسقط من حزمةٍ تقول لمن هي. فليست تكرارًا — هي وظيفةٌ ثانية.
         */
        $this->assertSame(2, substr_count($html, 'زهور الخليج'), 'اسمُ المتجر في غير موضعيه');
    }

    /* ═══════════ نصُّ البشر يُقرأ كما كُتب ═══════════ */

    /**
     * نصٌّ بلغةٍ غير لغة الورقة يحمل اتّجاهَه هو.
     *
     * اسمُ صنفٍ إنجليزيٍّ في ورقةٍ عربيّة — أو ملاحظةٌ عربيّةٌ في ورقةٍ
     * إنجليزيّة — تنقلب علاماتُ ترقيمه فتخرج النقطةُ إلى أوّل السطر.
     * و`dir="auto"` لا يحلّها: mpdf لا يعرفه.
     */
    public function test_text_carries_its_own_direction(): void
    {
        $this->assertSame('rtl', Paper::dirOf('يرجى التسليم قبل الظهر.'));
        $this->assertSame('ltr', Paper::dirOf('Premium Gift Box — صندوق'));
        $this->assertSame('ltr', Paper::dirOf('INV-2026-000412'));

        /* ورقمٌ عربيٌّ وحده محايد: لا يقلب سطرًا ليس فيه حرف */
        $this->assertSame('rtl', Paper::dirOf('٢٠٢٦'), 'في ورقةٍ عربيّة يتبع الورقة');

        $html = $this->sheet($this->invoice(['Premium Gift Box', 'باقة ورد']));

        $this->assertStringContainsString('dir="ltr"', $html, 'الاسمُ الإنجليزيُّ لا يحمل اتّجاهَه');
        $this->assertStringContainsString('dir="rtl"', $html);
    }

    /**
     * والاتّجاهُ لا يجرّ المحاذاةَ معه.
     *
     * `dir="rtl"` على خليّةٍ يصحّح ترتيبَ حروفها **ويزيحها إلى يمينها**،
     * فيخرج عمودُ الأصناف مبعثرًا: عربيُّه في طرفٍ وإنجليزيُّه في آخر.
     */
    public function test_direction_does_not_drag_alignment_with_it(): void
    {
        $html = $this->sheet($this->invoice(['Premium Gift Box']));

        /*
         * و`bidi` أوّلُ أصنافِ الخليّة لا وحدَها: يجاورها `item` الذي يُثقل
         * اسمَ الصنف. والمحروسُ اقترانُ الصنف بالسمة لا نصُّ السطر حرفًا حرفًا.
         */
        $this->assertMatchesRegularExpression(
            '/<td class="bidi[^"]*" dir="ltr">/',
            $html,
            'خليّةُ الاسم لا تفصل المحاذاةَ عن الاتّجاه',
        );
        $this->assertStringContainsString('.bidi { text-align: right;', $html, 'المحاذاةُ لا تتبع الورقة');
    }

    /* ═══════════ ولا فراغَ ميّت ولا قيمةَ خاوية ═══════════ */

    /**
     * حقلٌ بلا قيمةٍ لا يُطبع عنوانًا فارغًا.
     *
     * «الفرع: —» و«الموظف: —» سطران يشغلان مكانًا ولا يقولان شيئًا، ويجعلان
     * من يقرأ يظنّ أنّ بياناتٍ سقطت.
     *
     * وكانا يُقاسان على ورقة البيع — وخرجت مع «المبيعات». ونظيراهما على
     * سند الاستلام: «المخزن / الفرع» و«المستلِم» — يُطبعان من صفّه إن مُلئا.
     */
    public function test_an_empty_field_leaves_no_hole(): void
    {
        $bare = Business::create(['name' => 'مخبز الحيّ', 'type' => '', 'city' => '', 'status' => 'نشط']);
        $branch = Branch::create(['business_id' => $bare->id, 'name' => '']);
        $supplier = Supplier::create(['business_id' => $bare->id, 'name' => 'مطحنة الوادي']);

        $note = GoodsReceiptNote::create([
            'business_id' => $bare->id, 'branch_id' => $branch->id, 'supplier_id' => $supplier->id,
            'number' => 'GRN-000007', 'received_at' => now()->toDateString(),
            'receiver' => '', 'status' => 'معتمد',
        ]);
        GoodsReceiptNoteItem::create(['goods_receipt_note_id' => $note->id, 'name' => 'طحين', 'quantity' => 3, 'cost' => 0.3]);

        $html = DocumentRenderer::generic(
            $bare->id,
            'grn',
            DocumentPaper::forGrn($note->load('items', 'supplier', 'branch', 'purchaseOrder')),
        );

        $this->assertStringNotContainsString('>—<', $html, 'الورقةُ تطبع شرطةً مكانَ قيمةٍ غائبة');
        /* والعنوانُ كاملًا لا الحرف: «الفرع» تقع داخل غيرها */
        $this->assertStringNotContainsString('>'.__('المخزن / الفرع').'<', $html, 'عنوانُ الفرع يُطبع بلا فرع');
        $this->assertStringNotContainsString('>'.__('المستلِم').'<', $html, 'عنوانُ المستلِم يُطبع بلا مستلِم');
        $this->assertStringContainsString('مخبز الحيّ', $html, 'الورقةُ الأدنى لا تحمل اسمَ متجرها');

        /* والحارسُ يقيس شيئًا: الحقلان نفسُهما يُطبعان حين يُملآن */
        $branch->update(['name' => 'المخزن الرئيسي']);
        $note->update(['receiver' => 'سالم']);

        $filled = DocumentRenderer::generic(
            $bare->id,
            'grn',
            DocumentPaper::forGrn($note->fresh()->load('items', 'supplier', 'branch', 'purchaseOrder')),
        );

        $this->assertStringContainsString('>'.__('المخزن / الفرع').'<', $filled);
        $this->assertStringContainsString('>'.__('المستلِم').'<', $filled);
    }

    /**
     * وشريطُ التعريف أعمدةٌ لا قائمةٌ بنصف سطرٍ خالٍ.
     *
     * وأربعةٌ في السطر لا أكثر: ستّةٌ تجعل كلَّ عمودٍ بعرض ثلاثة سنتيمترات
     * فينكسر «وسيلة الدفع» سطرين ويلتصق بجاره.
     */
    public function test_the_meta_is_a_column_of_named_fields(): void
    {
        $html = $this->sheet();

        /*
         * ═══ وصارت عمودًا بعد أن كانت شريطًا ═══
         *
         * كانت أربعةَ حقولٍ في شريطٍ رماديٍّ بعرض الورقة تحت الأطراف. وفي
         * المرجع المعتمد عمودٌ في الترويسة نفسِها، بلا أرضيّةٍ ولا خطوط:
         * كلُّ حقلٍ ثلاثةُ أسطرٍ — تسميةٌ فوق تسميةٍ فوق قيمة — والفراغُ
         * بينها هو ما يقسمها.
         */
        $this->assertStringNotContainsString('table.metastrip', $html, 'عاد شريطُ التعريف ذو الأرضيّة');
        $this->assertStringNotContainsString('class="meta"', $html, 'بقيت قائمةُ العمودين القديمة');
        $this->assertStringContainsString('table.fields', $html, 'الحقولُ بلا بنيةٍ مشتركة');

        /* وكلُّ حقلٍ ثلاثةُ أسطر: تسميةٌ، فتسميةٌ باللغة الأخرى، فقيمة */
        $this->assertMatchesRegularExpression(
            '#<tr><td class="lbl"[^>]*>[^<]+</td></tr>\s*<tr><td class="lbl2"[^>]*>[^<]+</td></tr>#',
            $html,
            'التسميةُ بلغةٍ واحدة — والتسميتان شرطُ التصميم',
        );
    }

    /* ═══════════ والصفحةُ الثانية تُعرَف إن سقطت ═══════════ */

    /**
     * كلُّ صفحةٍ تحمل ما يعرّفها — رقمَ الورقة واسمَ متجرها.
     *
     * وصفحةٌ تحمل «٢ / ٣» وحدها لا تقول لأيّ حزمةٍ تعود، وهو ما يقع في
     * مكاتب المحاسبة حين تُفكّ الحزمةُ وتُصوَّر.
     */
    public function test_every_page_says_which_document_it_belongs_to(): void
    {
        /*
         * وكانت ورقةَ البيع على A4 (`admin.orders.pdf`) — خرجت مع «المبيعات».
         * والقاعدةُ نفسُها على أمر الشراء: ورقةُ A4 تُطبع من بابها وتحمل
         * سياقَها إلى تذييل كلّ صفحة.
         */
        $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مشتل الربيع']);

        $po = PurchaseOrder::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name,
            'number' => 'PO-2026-000412', 'status' => 'مسودة',
            'items_subtotal' => 720.0, 'total' => 720.0, 'ordered_at' => now(),
        ]);

        for ($i = 1; $i <= 60; $i++) {
            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id, 'name' => 'صنف رقم '.$i,
                'quantity' => 1, 'cost' => 12.0, 'line_total' => 12.0,
            ]);
        }

        $pdf = $this->get(route('admin.purchases.pdf', $po->id));
        $pdf->assertOk();

        $raw = $pdf->getContent();

        $this->assertStringStartsWith('%PDF', $raw, 'الناتجُ ليس ملفَّ PDF');

        $pages = substr_count($raw, '/Type /Page') - substr_count($raw, '/Type /Pages');
        $this->assertGreaterThan(1, $pages, 'الورقةُ لم تتجاوز صفحةً واحدة، فلا يُختبر ما يقع عند الثانية');

        /*
         * والسياقُ في التذييل: رقمُ الورقة على كلّ صفحة.
         *
         * نصُّ الـPDF مضغوطٌ لا يُقرأ حرفًا حرفًا، لكنّ المحرّك يُدرج التذييلَ
         * مرّةً لكلّ صفحة من السياق الذي يُعطاه. فيُقرأ ما بلغ السائقَ من
         * الباب نفسِه — بسائقٍ يسجّل بدل أن يرسم. انظر `MpdfDriver::pageNumbers`.
         */
        $fake = new class implements Driver
        {
            public ?string $context = null;

            public function sheet(string $html, string $name, array $preset, bool $landscape = false, ?string $runningHeader = null, ?string $context = null): Response
            {
                $this->context = $context;

                return response('FAKE');
            }

            public function strip(string $html, string $name, int $widthMm): Response
            {
                return response('FAKE');
            }

            public function stripHeight(string $html, int $widthMm): float
            {
                return 100.0;
            }
        };

        $was = Pdf::swap($fake);

        try {
            $this->get(route('admin.purchases.pdf', $po->id))->assertOk();
        } finally {
            Pdf::swap($was);
        }

        $this->assertStringContainsString('PO-2026-000412', (string) $fake->context, 'سياقُ التذييل لا يحمل رقمَ الورقة');
        $this->assertStringContainsString(__('أمر شراء'), (string) $fake->context, 'سياقُ التذييل لا يقول أيَّ ورقةٍ هي');
    }

    /* ═══════════ والرموزُ في موضعٍ واحد ═══════════ */

    /** إيقاعُ الأقسام من رمزٍ واحد — لا رقمٍ مكتوبٍ في عشر قواعد */
    public function test_the_rhythm_of_the_page_has_one_home(): void
    {
        $this->assertArrayHasKey('section_gap', Theme::GEOMETRY);

        $html = $this->sheet();
        $gap = Theme::GEOMETRY['section_gap'];

        $this->assertStringContainsString('margin-bottom: '.round($gap, 2).'pt', $html);

        /* ولا يُخفَّض الخطُّ الأساس «لأنّ الأقلَّ أنيق»: عشرُ نقاطٍ أدنى ما يُقرأ مطبوعًا */
        $this->assertGreaterThanOrEqual(10.0, Theme::GEOMETRY['text_base']);
    }
}
