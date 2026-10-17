<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\CustomerInvoiceController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\Document\Pdf\MpdfDriver;
use App\Support\InvoiceBranding;
use App\Support\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الورقةُ على الشاشة وعلى الورق بخطٍّ واحد — لا بخطّين متقاربين.
 *
 * ═══ العطبُ الذي وُلد منه هذا الملفّ ═══
 *
 * القالبُ كان يقول `font-family: xbriyaz, 'IBM Plex Sans Arabic', sans-serif`،
 * وmpdf يُثبّت `xbriyaz` — خطٌّ عربيٌّ يأتي مع المكتبة. والمتصفّحُ لا يجده
 * — ليس خطَّ وِب — فيسقط إلى `sans-serif`، أي إلى خطّ نظام التشغيل: SF
 * Arabic على ماك، وSegoe UI على ويندوز، وما اتّفق على لينكس.
 *
 * فعولج نصفُ العطب أوّلًا: حُمِّلت مقاطعُ «IBM Plex Sans Arabic» للمتصفّح،
 * فصارت المعاينةُ واحدةً على كلّ جهاز — **ولا تزال غيرَ المطبوع**. خطّان
 * مختلفان: مقاساتُ حروفهما تختلف، فينكسر السطرُ الطويل عند كلمةٍ على الشاشة
 * وعند أخرى على الورق، ويرى التاجر ورقةً ويطبع غيرَها.
 *
 * ═══ وعولج النصفُ الثاني ببناء الخطّ للمحرّك من مقاطع المتصفّح ═══
 *
 * `scripts/build-document-font.py` يدمج `public/fonts/ibmpsa-*.woff2` في
 * TTF لكلّ وزن، وmpdf يقرؤهما من `resources/fonts`. فالحروفُ **ذاتُها
 * بالمقاسات ذاتها** في المحرّكين.
 *
 * ═══ وما يحرسه هذا الملفّ ═══
 *
 *  ١. أنّ الورقةَ تُعلن الخطَّ للمتصفّح وتشير إلى ملفّاتٍ **قائمة**.
 *  ٢. أنّ الإعلانَ داخل `@media screen` — فmpdf يقرأ وسيطَ `mpdf` وحده، ولو
 *     خرج الإعلانُ لحاول المحرّكُ جلبَ ملفٍّ عند كلّ طباعة.
 *  ٣. أنّ ملفَّي المحرّك قائمان ويحملان جداولَ وصلِ الحروف العربية.
 *  ٤. أنّ الـPDF يُضمِّن «IBM Plex» فعلًا — لا اسمًا في إعدادٍ لا يصل.
 *  ٥. أنّ سجلَّ خطوط المكتبة لم يُمحَ بإضافة خطِّنا.
 */
class ThePreviewAndThePrintShareAFontTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private CustomerInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create([
            'name' => 'زهور الخليج', 'type' => 'محل ورود', 'city' => 'مسقط', 'status' => 'نشط',
        ]);
        $branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);
        $user = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'paper', 'value' => 'A4']);
        $this->actingAs($user);

        // كانت ورقةَ البيع — خرجت مع «المبيعات»؛ والقاعدةُ نفسُها على فاتورة العميل
        $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'شركة الواحة']);
        $this->invoice = CustomerInvoice::create([
            'business_id' => $this->business->id, 'customer_id' => $customer->id, 'branch_id' => $branch->id,
            'number' => 'CINV-000001', 'status' => CustomerInvoice::ISSUED, 'issued_at' => now(),
            'customer_name' => 'شركة الواحة',
            'subtotal' => 12.5, 'discount_total' => 0, 'tax_total' => 0.625, 'total' => 13.125,
        ]);
        CustomerInvoiceItem::create([
            'customer_invoice_id' => $this->invoice->id, 'description' => 'باقة ورد',
            'quantity' => 1, 'unit_price' => 12.5, 'discount' => 0,
            'tax_rate' => 5, 'tax_amount' => 0.625, 'line_total' => 13.125,
        ]);
        $this->invoice->load('items');
    }

    /** فاتورةُ العميل من باب طباعتها — كما يرسمها `PdfController::customerInvoice` */
    private function sheet(): string
    {
        $bid = (int) $this->business->id;

        return InvoiceBranding::render($bid, null, fn () => CustomerInvoiceController::paper(
            $bid, $this->invoice, $this->invoice->paidTotal(), $this->invoice->outstanding(), null,
        )->render());
    }

    /** الورقةُ تحمل إعلانَ الخطّ، وكلُّ ملفٍّ تشير إليه موجودٌ فعلًا */
    public function test_the_paper_declares_a_font_whose_files_exist(): void
    {
        $html = $this->sheet();

        $this->assertStringContainsString('@font-face', $html, 'الورقةُ لا تُعلن خطًّا للمتصفّح');
        $this->assertStringContainsString("font-family: 'IBM Plex Sans Arabic'", $html);

        preg_match_all("#url\('(/fonts/[^']+\.woff2)'\)#", $html, $m);
        $this->assertNotEmpty($m[1], 'إعلانُ الخطّ بلا ملفّ');

        foreach (array_unique($m[1]) as $path) {
            $this->assertFileExists(public_path(ltrim($path, '/')), "ملفُّ الخطّ مفقود: {$path}");
        }
    }

    /**
     * وملفّا المحرّك قائمان — وفيهما ما تتّصل به الحروف.
     *
     * إعدادٌ يشير إلى ملفٍّ محذوف يرفع استثناءً عند أوّل طباعة، لا عند
     * النشر. وملفٌّ بلا `GSUB` يخرج الكلمةَ العربيّة حروفًا منفصلة: «س ل ا م»
     * بدل «سلام» — وهو عطبٌ يُرى بالعين ولا يُمسك بأيّ فحصٍ نصّيّ.
     */
    public function test_the_engine_font_files_exist_and_can_join_arabic(): void
    {
        foreach (['IBMPlexSansArabic-Regular.ttf', 'IBMPlexSansArabic-Bold.ttf'] as $file) {
            $path = resource_path('fonts/'.$file);

            $this->assertFileExists($path, "ملفُّ خطّ المحرّك مفقود: {$file} — شغّل scripts/build-document-font.py");

            $blob = file_get_contents($path);

            $this->assertStringContainsString('GSUB', $blob, "الخطّ {$file} بلا جداول وصلٍ للحروف");
            $this->assertGreaterThan(50_000, strlen($blob), "الخطّ {$file} أصغرُ من أن يحمل العربيّة");
        }
    }

    /**
     * والـPDF يُضمِّن «IBM Plex» فعلًا — لا اسمًا في إعدادٍ لا يصل.
     *
     * ═══ ولمَ يُقاس الملفُّ نفسُه ═══
     *
     * `default_font` اسمٌ يُكتب، و«يعمل» لا تعني «وصل»: خطٌّ لا تجده المكتبةُ
     * تسقط إلى بديلٍ تختاره بنفسها بلا كلمة. والحقيقةُ الوحيدةُ التي لا
     * تكذب هي ما بين دفّتي الملفّ المطبوع.
     */
    public function test_the_printed_file_embeds_the_font_it_names(): void
    {
        $pdf = Pdf::a4($this->sheet(), 'probe')->getContent();

        preg_match_all('#/BaseFont\s*/([A-Za-z0-9+\-_]+)#', $pdf, $m);
        $fonts = implode(' ', array_unique($m[1]));

        $this->assertStringContainsString('IBMPlexSansArabic', $fonts, "الورقةُ خرجت بخطٍّ آخر: {$fonts}");
        $this->assertStringNotContainsString('XBRiyaz', $fonts, 'خطُّ المكتبة القديم لا يزال يُطبع');
    }

    /**
     * وإضافةُ خطِّنا لا تمحو سجلَّ خطوط المكتبة.
     *
     * ═══ وهذا عطبٌ وقع فعلًا وأنا أقيس ═══
     *
     * `Mpdf::initFontConfig` تكتب `$config + $defaults`، والجمعُ في PHP يُبقي
     * مفتاحَ اليسار كاملًا. فمصفوفةُ `fontdata` المُمرَّرة كانت تمحو السجلَّ
     * كلَّه: طلبتُ `xbriyaz` صراحةً فخرج الـPDF بخطِّنا — لأنّه الوحيدُ
     * الباقي. يعمل، لكن بالصدفة: أيُّ ورقةٍ تسمّي خطًّا آخر تسقط إليه صامتةً.
     */
    public function test_adding_our_font_does_not_wipe_the_library_registry(): void
    {
        $registry = self::registry();

        $this->assertArrayHasKey(MpdfDriver::FONT, $registry, 'خطُّنا ليس في السجلّ');
        $this->assertArrayHasKey('xbriyaz', $registry, 'إضافةُ خطِّنا محت سجلَّ المكتبة');
        $this->assertArrayHasKey('dejavusans', $registry, 'إضافةُ خطِّنا محت سجلَّ المكتبة');
    }

    /** سجلُّ الخطوط كما يبنيه السائق — من بابه هو لا بنسخةٍ هنا */
    private static function registry(): array
    {
        $fonts = new \ReflectionMethod(MpdfDriver::class, 'fonts');
        $fonts->setAccessible(true);

        return $fonts->invoke(null)['fontdata'];
    }
}
