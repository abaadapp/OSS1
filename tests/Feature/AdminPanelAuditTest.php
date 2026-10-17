<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use App\Support\DocumentRenderer;
use App\Support\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * لوحة النشاط: تنبيهٌ يُطلق حين يجب، وقالبٌ يحكم ورقتَه كما يَعِد.
 */
class AdminPanelAuditTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // الأوراق تُختبر بالعربية: التسميات هي ما يُبحث عنه في الناتج
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@eventplusapp.om',
            'password' => bcrypt('secret12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /*
     * فاتورةُ عميلٍ لا طلب: «آخرُ شراء» صار آخرَ فاتورةٍ صدرت له — انظر
     * `Demo::smartAlertsFor`. وكان آخرَ طلبٍ قديم، وخرجت «المبيعات».
     */
    private function invoice(Customer $c, string $status, string $when, int $n): CustomerInvoice
    {
        $invoice = CustomerInvoice::create([
            'business_id' => $this->business->id, 'customer_id' => $c->id, 'number' => 'CI-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'customer_name' => $c->name, 'status' => $status, 'issued_at' => $when,
            'subtotal' => 10, 'discount_total' => 0, 'tax_total' => 0.5, 'total' => 10.5,
        ]);
        CustomerInvoiceItem::create([
            'customer_invoice_id' => $invoice->id, 'description' => 'صنف',
            'quantity' => 1, 'unit_price' => 10, 'discount' => 0,
            'tax_rate' => 5, 'tax_amount' => 0.5, 'line_total' => 10.5,
        ]);

        return $invoice;
    }

    /* --------------------- تنبيه العميل المتعثّر --------------------- */

    public function test_a_cancelled_invoice_does_not_make_a_lapsed_customer_look_active(): void
    {
        /*
         * التنبيه الذي لا يُطلق أسوأ من غيابه: صاحبه يقرأ سكوت الشاشة
         * «لا متعثّر عندي».
         *
         * وكان الطلبُ الملغى — خرج مع «المبيعات»؛ والقاعدةُ نفسُها على فاتورةٍ ملغاة.
         */
        $c = Customer::create(['business_id' => $this->business->id, 'name' => 'سالم', 'phone' => '90000001']);
        $this->invoice($c, CustomerInvoice::ISSUED, now()->subDays(120)->toDateTimeString(), 1);
        $this->invoice($c, CustomerInvoice::CANCELLED, now()->toDateTimeString(), 2);

        $alerts = collect(Demo::smartAlertsFor($this->business->id));

        $this->assertTrue($alerts->contains(fn ($a) => str_contains($a['text'], 'سالم')));
    }

    public function test_a_customer_who_really_bought_recently_is_not_flagged(): void
    {
        $c = Customer::create(['business_id' => $this->business->id, 'name' => 'ناصر', 'phone' => '90000002']);
        $this->invoice($c, CustomerInvoice::ISSUED, now()->subDays(120)->toDateTimeString(), 1);
        $this->invoice($c, CustomerInvoice::ISSUED, now()->subDay()->toDateTimeString(), 2);

        $alerts = collect(Demo::smartAlertsFor($this->business->id));

        $this->assertFalse($alerts->contains(fn ($a) => str_contains($a['text'], 'ناصر')));
    }

    public function test_a_customer_who_never_bought_is_not_called_lapsed(): void
    {
        // «متعثّر» وصفٌ لمن كان يشتري فتوقّف — لا لمن لم يبدأ
        Customer::create(['business_id' => $this->business->id, 'name' => 'جديد', 'phone' => '90000003']);

        $alerts = collect(Demo::smartAlertsFor($this->business->id));

        $this->assertFalse($alerts->contains(fn ($a) => str_contains($a['text'], 'جديد')));
    }

    public function test_the_lapsed_scan_is_one_query_not_one_per_customer(): void
    {
        foreach (range(1, 15) as $i) {
            $c = Customer::create([
                'business_id' => $this->business->id, 'name' => "عميل {$i}", 'phone' => '9000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            ]);
            $this->invoice($c, CustomerInvoice::ISSUED, now()->subDays(120)->toDateTimeString(), $i);
        }

        DB::enableQueryLog();
        Demo::smartAlertsFor($this->business->id);
        $reads = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'from "customer_invoices"'))->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(4, $reads, 'صفُّ كل عميلٍ يُقرأ على حدة');
    }

    /* ------------------------ القالب يحكم ورقتَه ------------------------ */

    /*
     * وكانت تُفحص على فاتورة البيع A4 — والقالبُ يومها يحكم «الأوراق الثلاث»
     * (الإيصال وA4 والضريبيّة). خرجت مع «المبيعات»؛ والقاعدةُ نفسُها على
     * فاتورة العميل: ما يَعِد به المحرّرُ يبلغ الورقةَ المرسومة.
     *
     * وخرج معها فحصا «إخفاء اسم العميل» و«عدد الأصناف»: لا مقبضَ لهما في
     * فاتورة العميل عمدًا (فاتورةٌ بلا جهةٍ ليست فاتورة) — انظر `DocumentTemplates::TYPES`.
     */

    /** فاتورةُ العميل كما تُرسم في المحرّر — القالب هو ما يُختبر لا ملفّ الـPDF */
    private int $paper = 100;

    private function a4(array $override = []): string
    {
        $n = ++$this->paper;
        $c = Customer::create(['business_id' => $this->business->id, 'name' => 'شركة النور', 'phone' => '9000'.$n]);
        $this->invoice($c, CustomerInvoice::ISSUED, now()->toDateTimeString(), $n);

        return DocumentRenderer::customerInvoice($this->business->id, $override);
    }

    public function test_the_a4_invoice_prints_the_header_line_the_card_promises_it_obeys(): void
    {
        $this->assertStringContainsString('سطرٌ تحت الاسم', $this->a4(['header' => 'سطرٌ تحت الاسم']));
    }

    public function test_the_seller_tax_number_is_hideable_on_a_plain_invoice(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '1']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_number', 'value' => 'OM1234567']);

        $with = $this->a4(['show_vat_no' => true]);
        $without = $this->a4(['show_vat_no' => false]);

        $this->assertStringContainsString('OM1234567', $with);
        $this->assertStringNotContainsString('OM1234567', $without);
    }

    /**
     * ومقاسُ الخطّ يصل الورقة — نسبةً لا رقمًا مثبَّتًا.
     *
     * كان الفحص على «font-size: 14px» حرفيًّا، فسقط يوم صارت الورقة تُقاس
     * بالنقطة لا بالبكسل — والمقبض يعمل كما كان. وحارسٌ يكسره تغييرُ وحدةٍ
     * يُدفع إلى التعطيل، والسؤالُ الحقيقيّ واحد: هل يكبر ما اختار التاجر
     * تكبيرَه؟
     */
    public function test_the_font_size_setting_reaches_the_a4_invoice(): void
    {
        $size = function (string $font): float {
            $this->assertSame(1, preg_match(
                '/font-size:\s*([\d.]+)pt/',
                $this->a4(['font' => $font]),
                $m,
            ), 'الورقة لا تقول مقاس خطّها');

            return (float) $m[1];
        };

        $this->assertGreaterThan($size('صغير'), $size('عادي'));
        $this->assertGreaterThan($size('عادي'), $size('كبير'));
    }

    /* ------------------ مفاتيح الإعدادات مغلقة ------------------ */

    public function test_a_key_the_screen_does_not_own_is_not_stored(): void
    {
        /*
         * كان الحفظ حرَّ المفاتيح: مقبضٌ يُسمّى بحرفٍ زائد يُكتب في مفتاحٍ
         * لا يقرؤه أحد، ويقول التنبيه «تم الحفظ» ولا يتغيّر شيء.
         */
        $this->actingAs($this->owner)->post(route('admin.settings.update'), [
            'vat_rate' => '5', 'tpl_show_qrr' => '1', 'مفتاح_مخترع' => 'قيمة',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('settings', ['business_id' => $this->business->id, 'key' => 'vat_rate']);
        $this->assertDatabaseMissing('settings', ['key' => 'tpl_show_qrr']);
        $this->assertDatabaseMissing('settings', ['key' => 'مفتاح_مخترع']);
    }

    public function test_every_key_the_form_sends_is_accepted(): void
    {
        // القائمة المغلقة لا تُسقط مقبضًا قائمًا: هذا ما يحرس الإغلاق نفسه
        $sent = [
            'shop_name' => 'متجري', 'email' => 'a@b.om', 'phone' => '90000000', 'address' => 'مسقط',
            'vat_enabled' => true, 'vat_rate' => '5', 'vat_number' => 'OM1', 'tax_mode' => 'exclusive',
            'currency' => 'OMR', 'decimals' => '3', 'symbol_pos' => 'after',
            'pay_cash' => true, 'pay_card' => false, 'pay_transfer' => true,
            'inv_prefix' => 'INV-', 'inv_start' => '1',
            'notify_smart_alerts' => true, 'notify_daily_summary' => false,

            /*
             * ولا مفاتيحَ قوالبَ هنا: `tpl_*` و`paper` انتقلت إلى محرّرها
             * (‏`admin.settings.templates.update`‎). وبقاؤها مقبولةً في هذا
             * الباب كان يجعل كلّ حفظٍ من أيّ تبويب يُعيد ما قرأته الشاشة قبل
             * تعديل الورقة — نسخُ القديم فوق الجديد بلا رسالة.
             */
        ];

        $this->actingAs($this->owner)->post(route('admin.settings.update'), $sent)
            ->assertSessionHasNoErrors();

        foreach (array_keys($sent) as $key) {
            if (in_array($key, ['shop_name', 'email', 'phone', 'address'], true)) {
                continue; // هذه تسكن جدول النشاط
            }
            $this->assertDatabaseHas('settings', ['business_id' => $this->business->id, 'key' => $key]);
        }
    }

    public function test_a_toggle_turned_off_is_stored_as_zero_not_as_emptiness(): void
    {
        $this->actingAs($this->owner)->post(route('admin.settings.update'), ['vat_enabled' => false]);

        $this->assertDatabaseHas('settings', [
            'business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0',
        ]);
        $this->assertFalse(Vat::enabled($this->business->id));
    }

    public function test_a_nonsense_value_is_refused_rather_than_saved(): void
    {
        $this->actingAs($this->owner)->post(route('admin.settings.update'), [
            'decimals' => '9', 'vat_rate' => 'خمسة',
        ])->assertSessionHasErrors(['decimals', 'vat_rate']);

        $this->assertDatabaseMissing('settings', ['business_id' => $this->business->id, 'key' => 'decimals']);

        // وكان مقاسُ الورق يُردّ في بابه هو — مقبضُ ورقة البيع وحدها، وخرج معها
    }

    public function test_the_business_profile_still_lands_in_its_own_table(): void
    {
        $this->actingAs($this->owner)->post(route('admin.settings.update'), [
            'shop_name' => 'اسمٌ جديد', 'phone' => '99887766',
        ])->assertSessionHasNoErrors();

        $this->assertSame('اسمٌ جديد', $this->business->fresh()->name);
        $this->assertDatabaseMissing('settings', ['key' => 'shop_name']);
    }
}
