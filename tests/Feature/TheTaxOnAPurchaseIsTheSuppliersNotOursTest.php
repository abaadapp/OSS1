<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Ledger;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الضريبةُ على الشراء ضريبةُ المورّد لا ضريبتُنا — والسعرُ يُرى قبل أن يُكتب.
 *
 * ═══ العطبُ الأوّل: النسبةُ كانت سياستَنا ═══
 *
 * `PurchaseOrderTotals::taxRateFor($bid)` تُقرأ من إعدادات المتجر وتُفرض على
 * كلّ أمر شراء. وهي صحيحةٌ في **البيع**: نسبتُنا سياستُنا، ولا تُترك لمن
 * يكتب الورقة. أمّا في **الشراء** فالضريبةُ ليست سياستَنا أصلًا — هي ما
 * يفرضه المورّد، ومورّدٌ غير مسجَّلٍ ضريبيًّا لا يفرض شيئًا.
 *
 * وأثرُه تجاوز الورقة إلى ما يُمنع: `SupplierInvoices::match` تقابل إجماليَّ
 * السند بإجماليّ الأمر. فمتجرٌ ضريبتُه مُطفأة يشتري ممّن يفرضها كان سندُه
 * **يُمنع** بمقدار الضريبة بالضبط — ولا يقول العطبُ عن نفسه شيئًا سوى
 * «فاتورة المورد أعلى من أمر الشراء».
 *
 * ═══ والعطبُ الثاني: حقلٌ موجودٌ لا يُرى ═══
 *
 * شاشةُ الأمر كانت تفتح بلا صفٍّ البتّة — صندوقٌ يقول «لا توجد أصناف مضافة
 * بعد»، وزرُّ الإضافة بعيدٌ في رأس البطاقة. و«تكلفة الوحدة» مفتوحةٌ للكتابة
 * منذ كُتبت الشاشة، لكنّها خلف ضغطةٍ لا تُرى — فمن جاء ليكتب سعرًا بحث عنه
 * في الملخّص حيث «قيمة الأصناف» رقمٌ محسوبٌ لا يُكتب فيه شيء.
 */
class TheTaxOnAPurchaseIsTheSuppliersNotOursTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Branch $branch;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->supplier = Supplier::create([
            'business_id' => $this->business->id, 'name' => 'مشتل الباطنة',
        ]);
    }

    /** ونسبةُ المتجر خمسةٌ ما لم يُقل غيرُ ذلك */
    private function shopRate(float $rate): void
    {
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => 'vat_enabled'],
            ['value' => $rate > 0 ? '1' : '0'],
        );
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => 'vat_rate'],
            ['value' => (string) $rate],
        );
    }

    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'payment_method' => 'نقدي',
            'ordered_at' => now()->toDateString(),
            'items' => [['name' => 'ورد جوري', 'quantity' => 10, 'cost' => 10]],
        ], $extra);
    }

    // ───────────────────────── نسبةُ الورقة ─────────────────────────
    /** ونسبةٌ فوق المئة تُردّ */
    public function test_a_rate_above_a_hundred_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.purchases.store'), $this->payload(['tax_rate' => 101]))
            ->assertSessionHasErrors('tax_rate');

        $this->assertSame(0, PurchaseOrder::count());
    }

    /** وسالبةٌ كذلك — ضريبةٌ تُنقص الإجماليَّ ليست ضريبة */
    public function test_a_negative_rate_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.purchases.store'), $this->payload(['tax_rate' => -5]))
            ->assertSessionHasErrors('tax_rate');
    }
    // ───────────── وهذا ما كان يُمنع: المطابقة ─────────────
    // ───────────────────── المورّدُ من هذه الشاشة ─────────────────────

    /** ومورّدٌ يُضاف من نافذة الأمر يُختار فور العودة */
    public function test_a_new_supplier_comes_back_selected(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.suppliers.store'), ['name' => 'مزرعة صحار'])
            ->assertSessionHas('new_supplier_id');

        $fresh = Supplier::where('name', 'مزرعة صحار')->firstOrFail();

        $this->actingAs($this->owner)
            ->withSession(['new_supplier_id' => $fresh->id])
            ->get(route('admin.purchases.create'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('newSupplierId', $fresh->id)->etc());
    }

    /** والشاشةُ بلا مورّدٍ جديدٍ لا تختار أحدًا */
    public function test_the_screen_selects_nobody_by_default(): void
    {
        $this->actingAs($this->owner)->get(route('admin.purchases.create'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('newSupplierId', null)->etc());
    }

    /** والمورّدُ الجديد من متجر من أضافه لا من رقمٍ في الطلب */
    public function test_the_new_supplier_belongs_to_the_adders_shop(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.suppliers.store'), ['name' => 'مزرعة صحار']);

        $this->assertSame(
            $this->business->id,
            (int) Supplier::where('name', 'مزرعة صحار')->firstOrFail()->business_id,
        );
    }

    // ──────────────── والسعرُ يُرى: حارسُ مصدرٍ لا تصيير ────────────────

    /**
     * وشاشةُ الأمر تفتح بصفٍّ جاهز.
     *
     * وهذا حارسُ مصدرٍ يمنع الحذف ولا يُثبت التصيير — والتصييرُ الكامل
     * يتطلّب محاكاة `AdminLayout` بشجرته كاملةً، وهو ما أجّله DEC-002 حتى
     * تتكرّر الحاجة. فيُقال هنا ما هو ولا يُدّعى أكثر.
     */
    public function test_the_screen_opens_with_a_row_ready(): void
    {
        $screen = file_get_contents(base_path('resources/js/Pages/Admin/Purchases/Create.tsx'));

        // ‏و`blank` صارت تأخذ وحدةَ الشراء الافتراضية — والصفُّ الجاهزُ هو المقصود
        $this->assertStringContainsString(': [blank(', $screen);
        $this->assertStringContainsString('مجموع (تكلفة الوحدة × الكمية)', $screen);
    }

    /** و«إلغاء» وجهةٌ مكتوبةٌ باسمها لا رجوعُ متصفّح */
    public function test_cancel_names_where_it_goes(): void
    {
        $screen = file_get_contents(base_path('resources/js/Pages/Admin/Purchases/Create.tsx'));

        $this->assertStringNotContainsString('window.history.back()', $screen);
        $this->assertStringContainsString("route('admin.purchases.orders')", $screen);
    }
}
