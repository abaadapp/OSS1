<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\ReportData;
use App\Support\Revenue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * عينُ النظام مفتوحة: ما تفوتره الشركةُ تراه لوحتُها وتقاريرُها وإقرارُها.
 *
 * ═══ ما كان ═══
 *
 * طبقةُ التحليل كلُّها كانت تقرأ `orders`: اثنا عشر موضعًا في `ReportData`،
 * واثنان في `DashboardMetrics`، وأكثرُ من عشرين في `Demo` — وصفرٌ يقرأ
 * `customer_invoices`. **ولا مسارَ HTTP واحدٍ يُنشئ `Order`** منذ رُفعت نقطة
 * البيع. فجدولُ الطلبات فارغٌ أبدًا، واللوحةُ تقرأ صفرًا مهما فوترت الشركة.
 *
 * وأخطرُها الإقرارُ الضريبيّ: رقمٌ يُقدَّم إلى جهةٍ حكوميّة وهو صفر.
 *
 * ═══ وهذا الملفُّ يحرس ما بعده ═══
 *
 * لا على نصوصِ شاشةٍ ولا على ترتيب بطاقة، بل على الشيء الوحيد الذي لا يجوز
 * أن يفترق: **ما يقوله التقريرُ هو ما يقوله دفترُ الأستاذ**.
 */
class TheSystemSeesItsOwnRevenueTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@hadath.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'شركةُ المؤتمرات',
        ]);

        $this->actingAs($this->owner);
    }

    /** فاتورةٌ تمرّ ببابها الحقيقيّ — فتُقيَّد في الدفتر كما تُقيَّد في الإنتاج */
    private function issue(float $net, float $tax): CustomerInvoice
    {
        $invoice = CustomerInvoice::create([
            'business_id' => $this->business->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->name,
            'status' => CustomerInvoice::DRAFT,
            'issued_at' => now()->toDateString(),
            'subtotal' => $net, 'discount_total' => 0,
            'tax_total' => $tax, 'total' => $net + $tax,
        ]);

        return CustomerInvoices::issue($invoice, $this->owner->id);
    }

    /* ═════════════ الوعاء هو ما يُقيَّد، حرفًا بحرف ═════════════ */

    /**
     * ما يقوله التقريرُ عن الإيراد هو رصيدُ حساب «إيراد المبيعات».
     *
     * وهذا هو الحارسُ الأصل: رقمان يجيبان سؤالًا واحدًا ويجب ألّا يفترقا.
     * فلو قُرئ الوعاءُ `total - tax` بدل `subtotal - discount_total` لَافترقا
     * عند أوّل خصم، ولا شيءَ يقول أيُّهما الصادق.
     */
    public function test_what_the_report_calls_revenue_is_what_the_ledger_credited(): void
    {
        $this->issue(net: 1000, tax: 50);
        $this->issue(net: 400, tax: 20);

        $ledger = Ledger::balance($this->business->id, 'sales');
        $report = Revenue::summary($this->business->id)['net'];

        $this->assertSame(1400.0, round($report, 3));
        $this->assertSame(round($ledger, 3), round($report, 3), 'التقريرُ والدفترُ يقولان قولين');
    }

    /** وضريبةُ المخرجات هي رصيدُ «ضريبة مستحقّة» */
    public function test_the_output_tax_is_what_the_ledger_owes(): void
    {
        $this->issue(net: 1000, tax: 50);

        $this->assertSame(
            round(Ledger::balance($this->business->id, 'tax_payable'), 3),
            round(Revenue::summary($this->business->id)['netTax'], 3),
            'الإقرارُ والدفترُ يقولان قولين',
        );
    }

    /**
     * وإشعارُ الدائن يُنقص الاثنين معًا.
     *
     * قيدُه `sales_returns` مدينًا و`tax_payable` مدينًا — فلو لم يُطرح من
     * التقرير لَطالب الإقرارُ بضريبةِ بيعةٍ رُدّت.
     */
    public function test_a_credit_note_reduces_both_the_report_and_the_ledger(): void
    {
        $invoice = $this->issue(net: 1000, tax: 50);
        CustomerInvoices::creditNote($invoice, 210, 10, 'ردٌّ جزئيّ', $this->owner->id);

        $summary = Revenue::summary($this->business->id);

        $this->assertSame(800.0, round($summary['net'], 3));
        $this->assertSame(40.0, round($summary['netTax'], 3));
        $this->assertSame(
            round(Ledger::balance($this->business->id, 'tax_payable'), 3),
            round($summary['netTax'], 3),
        );
    }

    /* ═════════════ ما ليس إيرادًا ═════════════ */

    public function test_a_draft_is_not_revenue(): void
    {
        // لا ذمّةَ لها ولا قيدَ ولا رقمَ محجوز — ورقةٌ لم تصدر بعد
        $this->issue(net: 100, tax: 5);
        CustomerInvoice::create([
            'business_id' => $this->business->id, 'customer_id' => $this->customer->id,
            'status' => CustomerInvoice::DRAFT, 'issued_at' => now()->toDateString(),
            'subtotal' => 900, 'discount_total' => 0, 'tax_total' => 45, 'total' => 945,
        ]);

        $this->assertSame(105.0, Revenue::summary($this->business->id)['total']);
    }

    public function test_a_cancelled_invoice_is_not_revenue(): void
    {
        // عُكس قيدُها في الدفتر — فجمعُها في التقرير يُفرّق الاثنين
        $this->issue(net: 100, tax: 5);
        CustomerInvoices::cancel($this->issue(net: 900, tax: 45), 'أُلغيت');

        $this->assertSame(105.0, Revenue::summary($this->business->id)['total']);
        $this->assertSame(100.0, round(Ledger::balance($this->business->id, 'sales'), 3));
    }

    /* ═════════════ الشاشاتُ تقرأ الشيءَ نفسه ═════════════ */

    /**
     * واللوحةُ والتقريرُ والإقرارُ ثلاثتُها من مصدرٍ واحد.
     *
     * وكانت ثلاثتُها تقرأ صفرًا: بطاقةُ «مبيعات اليوم» على أوّل شاشةٍ يفتحها
     * صاحبُ الشركة كلَّ صباح، وملخّصُ المبيعات، والإقرار.
     */
    public function test_the_dashboard_the_report_and_the_return_agree(): void
    {
        $this->issue(net: 1000, tax: 50);

        $card = collect(Demo::adminStats())->firstWhere('label', __('مبيعات اليوم'))['value'];
        $report = Demo::reportSummary('month')['sales'];
        $vat = ReportData::vat($this->business->id, ['range' => 'month'])['summary'];

        $this->assertStringContainsString('1,050', (string) $card, 'بطاقةُ اللوحة عمياء');
        $this->assertSame(1050.0, round($report, 3), 'ملخّصُ المبيعات أعمى');
        $this->assertSame(50.0, round((float) $vat['output'], 3), 'الإقرارُ أعمى');
        $this->assertSame(1000.0, round((float) $vat['taxable'], 3));
    }

    /** وتقريرُ فواتير العملاء يفتح على ما صدر فعلًا */
    public function test_the_invoices_report_opens_on_what_was_issued(): void
    {
        $this->issue(net: 1000, tax: 50);

        $this->get(route('admin.reports.invoices'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Reports/Invoices')
                ->has('rows', 1)
                ->where('summary.total', fn ($v) => round((float) $v, 3) === 1050.0)
                ->where('summary.outstanding', fn ($v) => round((float) $v, 3) === 1050.0));
    }

    /* ═════════════ التحصيلُ بابٌ ثانٍ ═════════════ */

    /**
     * ووسيلةُ الدفع تُسأل عن سند القبض لا عن الفاتورة.
     *
     * كانت تُقرأ من `orders.payment_method` — وفاتورةُ العميل لا وسيلةَ دفعٍ
     * فيها أصلًا: تُصدَر اليوم ويُقبض ثمنُها بعد شهرٍ بشيك، وقد يُقبض على
     * دفعتين بوسيلتين.
     */
    public function test_the_payment_method_is_asked_of_the_receipt_not_the_invoice(): void
    {
        $invoice = $this->issue(net: 1000, tax: 50);

        CustomerPayment::create([
            'business_id' => $this->business->id, 'customer_id' => $this->customer->id,
            'number' => 'RCPT-1', 'amount' => 600, 'method' => 'تحويل', 'occurred_at' => now(),
        ]);
        CustomerPayment::create([
            'business_id' => $this->business->id, 'customer_id' => $this->customer->id,
            'number' => 'RCPT-2', 'amount' => 450, 'method' => 'شيك', 'occurred_at' => now(),
        ]);

        $rows = Revenue::byMethod($this->business->id)->keyBy('key');

        $this->assertSame(600.0, $rows['تحويل']['total']);
        $this->assertSame(450.0, $rows['شيك']['total']);
        $this->assertSame(1050.0, round((float) $invoice->total, 3));
    }

    /* ═════════════ العزل ═════════════ */

    public function test_one_companys_revenue_never_reaches_another(): void
    {
        $this->issue(net: 1000, tax: 50);
        $neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);

        $this->assertSame(0.0, Revenue::summary($neighbour->id)['total']);
        $this->assertSame(0, Revenue::count($neighbour->id));
    }

    /* ═════════════ الحدودُ لا تُحسب مرّتين ═════════════ */

    /**
     * وفاتورةٌ على حدّ الشهرين تُحسب في واحدٍ منهما لا في الاثنين.
     *
     * `issued_at` عمودُ تاريخٍ بلا ساعة، و`between` تشمل الطرفين — فما وقع
     * على الحدّ كان يُحسب مرّتين. والحدُّ الأعلى مفتوحٌ دائمًا في `Revenue`.
     */
    public function test_an_invoice_on_the_boundary_belongs_to_one_month_only(): void
    {
        $first = now()->startOfMonth();
        $this->issue(net: 100, tax: 0)->update(['issued_at' => $first->toDateString()]);

        $thisMonth = Revenue::total($this->business->id, $first, $first->copy()->addMonthNoOverflow());
        $lastMonth = Revenue::total(
            $this->business->id,
            $first->copy()->subMonthNoOverflow(),
            $first->copy(),
        );

        $this->assertSame(100.0, $thisMonth);
        $this->assertSame(0.0, $lastMonth, 'فاتورةٌ على الحدّ حُسبت في الشهرين');
    }

    /** وبندُ خدمةٍ بلا صنفٍ تكلفتُه صفر — وهو الصواب لشركةِ خدمات */
    public function test_a_service_line_has_no_goods_cost(): void
    {
        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'شاشة',
            'price' => 100, 'cost' => 60, 'quantity' => 10, 'alert_qty' => 1,
        ]);

        $this->sellThroughInvoice($this->business->id, [
            ['product' => $product, 'quantity' => 2, 'unit_price' => 100],
            ['description' => 'تشغيلُ منصّة التسجيل', 'quantity' => 1, 'unit_price' => 500],
        ]);

        $this->assertSame(120.0, Revenue::cogs($this->business->id), 'حُسبت تكلفةٌ لبندِ خدمة');
        $this->assertSame(700.0, Revenue::summary($this->business->id)['total']);
    }
}
