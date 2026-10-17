<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * القوائمُ الجانبيّة والروابطُ المباشرة لا تفتح فرعًا آخر.
 *
 * بعد أن فُرضت فروعُ العمل في الشاشات الرئيسة بقيت قوائمُ داخل صفحاتٍ أخرى
 * تقرأ الشركة كلَّها: كشفُ حساب العميل، وسجلُّ المشتريات، وأوامرُ الشراء في
 * صفحة المورّد، وفواتيرُ العميل في ملفّه، وقائمةُ الأوامر في نموذج السند —
 * ورابطٌ مكتوبٌ باليد إلى فاتورةِ فرعٍ آخر كان يفتحها. والقاعدةُ واحدة
 * (`BranchAccess`): لمن قُيّد بفروعٍ ما في فروعه، وما بلا فرعٍ من مستندات
 * الشركة يبقى مرئيًّا (فاتورةٌ صدرت من مشروعٍ بلا فرع، وسندٌ بلا أمر شراء).
 *
 * ولا يُمسّ الدفترُ ولا الرواتبُ ولا الأرقامُ الماليّة العامّة للشركة.
 */
class NoSideListOrLinkOpensAnotherBranchTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $muscat;

    private Branch $sur;

    private User $owner;

    private User $clerk;

    private Customer $customer;

    private Supplier $supplier;

    /** @var array<string, CustomerInvoice> */
    private array $inv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'إيفنت بلس', 'status' => 'نشط']);
        $this->muscat = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        $this->sur = Branch::create(['business_id' => $this->shop->id, 'name' => 'صور']);
        Ledger::seedChart($this->shop->id);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        // مديرُ فرعٍ يرى الأقسام كلَّها — ومحصورٌ في مسقط
        $this->clerk = User::create([
            'business_id' => $this->shop->id, 'name' => 'مدير مسقط', 'email' => 'm@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'manager', 'status' => 'نشط',
        ]);
        $this->clerk->branches()->sync([$this->muscat->id]);

        $this->customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'شركة الحفلات']);
        $this->supplier = Supplier::create(['business_id' => $this->shop->id, 'name' => 'مورّد المسارح']);

        foreach (['INV-M' => [$this->muscat->id, 100], 'INV-S' => [$this->sur->id, 200], 'INV-0' => [null, 50]] as $number => [$branch, $total]) {
            $this->inv[$number] = CustomerInvoice::create([
                'business_id' => $this->shop->id, 'customer_id' => $this->customer->id, 'customer_name' => $this->customer->name,
                'branch_id' => $branch, 'number' => $number, 'status' => CustomerInvoice::ISSUED,
                'issued_at' => now()->subDays(40)->toDateString(), 'due_at' => now()->subDays(10)->toDateString(),
                'subtotal' => $total, 'discount_total' => 0, 'tax_total' => 0, 'total' => $total,
            ]);
        }

        // دفعةٌ واحدة بلا فرع: ٦٠ على فاتورة مسقط و١٥٠ على فاتورة صور
        $payment = CustomerPayment::create([
            'business_id' => $this->shop->id, 'customer_id' => $this->customer->id, 'number' => 'RCPT-1',
            'amount' => 210, 'occurred_at' => now()->subDays(5), 'method' => 'cash',
        ]);
        $payment->allocations()->create(['customer_invoice_id' => $this->inv['INV-M']->id, 'amount' => 60]);
        $payment->allocations()->create(['customer_invoice_id' => $this->inv['INV-S']->id, 'amount' => 150]);
    }

    private function po(Branch $branch, string $number, ?int $project = null): PurchaseOrder
    {
        return PurchaseOrder::create([
            'business_id' => $this->shop->id, 'branch_id' => $branch->id, 'number' => $number,
            'supplier_id' => $this->supplier->id, 'supplier_name' => $this->supplier->name,
            'status' => 'مُرسل', 'total' => 40, 'ordered_at' => now(), 'project_id' => $project,
        ]);
    }

    private function supplierInvoice(string $ref, ?PurchaseOrder $po = null): SupplierInvoice
    {
        return SupplierInvoice::create([
            'business_id' => $this->shop->id, 'supplier_id' => $this->supplier->id, 'purchase_order_id' => $po?->id,
            'supplier_ref' => $ref, 'issued_at' => now()->toDateString(),
            'subtotal' => 40, 'tax' => 0, 'total' => 40, 'paid' => 0,
            'status' => 'غير مدفوع', 'approval_status' => SupplierInvoices::PENDING,
        ]);
    }

    private function props(string $route, mixed $params = []): array
    {
        return $this->get(route($route, $params))->assertOk()->viewData('page')['props'];
    }

    /* ══════════════ كشفُ حساب العميل ══════════════ */

    public function test_the_customer_statement_shows_only_their_branches(): void
    {
        $this->actingAs($this->owner);
        $all = $this->props('admin.finance.customerStatement', $this->customer->id);
        $this->assertSame(['INV-0', 'INV-M', 'INV-S', 'RCPT-1'], collect($all['statement']['rows'])->pluck('ref')->sort()->values()->all());
        $this->assertSame(140.0, (float) $all['statement']['closing']);
        $this->assertSame(140.0, (float) $all['summary']['outstanding']);

        // المقيَّدُ بمسقط: فاتورتُه وفاتورةُ الشركة بلا فرع، ومن الدفعة ما وقع على فاتورته وحده
        $this->actingAs($this->clerk);
        $mine = $this->props('admin.finance.customerStatement', $this->customer->id);
        $rows = collect($mine['statement']['rows'])->keyBy('ref');
        $this->assertSame(['INV-0', 'INV-M', 'RCPT-1'], $rows->keys()->sort()->values()->all());
        $this->assertSame(60.0, (float) $rows['RCPT-1']['credit']);
        $this->assertSame(90.0, (float) $mine['statement']['closing']);
        $this->assertSame(90.0, (float) $mine['summary']['outstanding']);

        // والورقتان المطبوعتان تُفتحان — والكشفُ نفسُه تحتهما
        $this->get(route('admin.finance.customerStatement.pdf', $this->customer->id))->assertOk();
        $this->get(route('admin.customers.statement', $this->customer->id))->assertOk();
    }

    public function test_the_customer_file_and_list_count_only_their_branches(): void
    {
        $this->actingAs($this->clerk);
        $this->assertSame(['INV-0', 'INV-M'], collect($this->props('admin.customers.show', $this->customer->id)['orders'])->pluck('id')->sort()->values()->all());
        $row = collect($this->props('admin.customers.index')['customers'])->firstWhere('id', $this->customer->id);
        $this->assertSame([2, 150.0], [(int) $row['orders'], (float) $row['total_spent']]);

        $this->actingAs($this->owner);
        $row = collect($this->props('admin.customers.index')['customers'])->firstWhere('id', $this->customer->id);
        $this->assertSame([3, 350.0], [(int) $row['orders'], (float) $row['total_spent']]);
    }

    /* ══════════════ فاتورةُ فرعٍ آخر لا تُفتح بالرابط ══════════════ */

    public function test_a_direct_link_to_another_branchs_invoice_is_refused(): void
    {
        $note = CustomerCreditNote::create([
            'business_id' => $this->shop->id, 'customer_invoice_id' => $this->inv['INV-S']->id,
            'number' => 'CN-1', 'amount' => 10, 'tax_amount' => 0, 'issued_at' => now()->toDateString(),
        ]);
        $sur = $this->inv['INV-S'];
        $this->actingAs($this->clerk);

        $this->get(route('admin.customerInvoices.show', $sur->id))->assertNotFound();
        $this->get(route('admin.customerInvoices.pdf', $sur->id))->assertNotFound();
        $this->post(route('admin.customerInvoices.cancel', $sur->id), ['reason' => 'خطأ'])->assertNotFound();
        $this->get(route('admin.customerInvoices.creditNotes.show', $note->id))->assertNotFound();
        $this->assertSame(CustomerInvoice::ISSUED, $sur->fresh()->status);

        $listed = collect($this->props('admin.customerInvoices.index')['invoices'])->pluck('number')->sort()->values()->all();
        $this->assertSame(['INV-0', 'INV-M'], $listed);

        // وفاتورةُ فرعه وفاتورةُ الشركة بلا فرع تُفتحان
        $this->get(route('admin.customerInvoices.show', $this->inv['INV-M']->id))->assertOk();
        $this->get(route('admin.customerInvoices.show', $this->inv['INV-0']->id))->assertOk();
    }

    /* ══════════════ المشتريات: السجلّ وصفحةُ المورّد ونموذجُ السند ══════════════ */

    public function test_the_purchase_register_shows_only_their_branches(): void
    {
        $this->po($this->muscat, 'PO-M');
        $this->po($this->sur, 'PO-S');
        $this->supplierInvoice('SI-0');

        $this->actingAs($this->clerk);
        $this->assertSame(['PO-M', 'SI-0'], collect($this->props('admin.purchases.index')['rows'])->pluck('reference')->sort()->values()->all());

        $this->actingAs($this->owner);
        $this->assertSame(['PO-M', 'PO-S', 'SI-0'], collect($this->props('admin.purchases.index')['rows'])->pluck('reference')->sort()->values()->all());
    }

    public function test_the_supplier_page_lists_only_their_branches_orders_and_invoices(): void
    {
        $this->supplierInvoice('SI-M', $this->po($this->muscat, 'PO-M'));
        $this->supplierInvoice('SI-S', $this->po($this->sur, 'PO-S'));
        $this->supplierInvoice('SI-0');

        $this->actingAs($this->clerk);
        $page = $this->props('admin.suppliers.show', $this->supplier->id);
        $this->assertSame(['PO-M'], collect($page['orders'])->pluck('number')->all());
        $this->assertSame(['SI-0', 'SI-M'], collect($page['invoices'])->pluck('ref')->sort()->values()->all());
    }

    public function test_the_supplier_invoice_form_offers_only_their_orders(): void
    {
        $this->po($this->muscat, 'PO-M');
        $this->po($this->sur, 'PO-S');

        $this->actingAs($this->clerk);
        $labels = collect($this->props('admin.purchases.invoices')['orders'])->pluck('label')->implode(' | ');
        $this->assertStringContainsString('PO-M', $labels);
        $this->assertStringNotContainsString('PO-S', $labels);
    }

    public function test_a_direct_link_to_another_branchs_supplier_invoice_is_refused(): void
    {
        $sur = $this->supplierInvoice('SI-S', $this->po($this->sur, 'PO-S'));
        $mine = $this->supplierInvoice('SI-M', $this->po($this->muscat, 'PO-M'));
        $loose = $this->supplierInvoice('SI-0');

        $this->actingAs($this->clerk);
        $this->get(route('admin.purchases.invoices.show', $sur->id))->assertNotFound();
        $this->get(route('admin.purchases.invoices.pdf', $sur->id))->assertNotFound();
        $this->post(route('admin.purchases.invoices.approve', $sur->id))->assertNotFound();
        $this->assertSame(SupplierInvoices::PENDING, $sur->fresh()->approval_status);

        $listed = collect($this->props('admin.purchases.invoices')['invoices'])->pluck('id')->sort()->values()->all();
        $this->assertSame([$mine->id, $loose->id], $listed);

        $this->get(route('admin.purchases.invoices.show', $mine->id))->assertOk();
        $this->get(route('admin.purchases.invoices.show', $loose->id))->assertOk();
    }

    /* ══════════════ الجرسُ لا يقود إلى فرعٍ آخر ══════════════ */

    public function test_the_bell_does_not_point_to_another_branchs_invoice(): void
    {
        $this->actingAs($this->clerk);
        $urls = collect(Demo::notifications())->pluck('url')->filter()->implode(' ');

        $this->assertStringContainsString(route('admin.customerInvoices.show', $this->inv['INV-M']->id), $urls);
        $this->assertStringNotContainsString(route('admin.customerInvoices.show', $this->inv['INV-S']->id), $urls);
    }

    /* ══════════════ إنشاءُ الفروع ══════════════ */

    public function test_a_restricted_user_cannot_create_a_branch(): void
    {
        $this->actingAs($this->clerk);
        $this->post(route('admin.branches.store'), ['name' => 'نزوى'])->assertForbidden();
        $this->assertSame(0, Branch::where('name', 'نزوى')->count());
        $this->assertFalse($this->props('admin.settings.index', ['section' => 'branches'])['canCreateBranch']);

        // مديرٌ بلا فروعٍ محدّدة يُنشئ كما كان، وصاحبُ النشاط كذلك
        $free = User::create([
            'business_id' => $this->shop->id, 'name' => 'مدير عام', 'email' => 'g@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'manager', 'status' => 'نشط',
        ]);
        $this->actingAs($free);
        $this->assertTrue($this->props('admin.settings.index', ['section' => 'branches'])['canCreateBranch']);
        $this->post(route('admin.branches.store'), ['name' => 'نزوى'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('admin.branches.store'), ['name' => 'صحار'])->assertSessionHasNoErrors();
        $this->assertSame(2, Branch::whereIn('name', ['نزوى', 'صحار'])->count());
    }

    public function test_a_restricted_user_restores_only_a_branch_of_their_own(): void
    {
        $this->sur->delete();
        $this->muscat->delete();
        $this->actingAs($this->clerk);

        $this->post(route('admin.branches.restore', $this->sur->id))->assertNotFound();
        $this->assertSoftDeleted($this->sur);

        $this->post(route('admin.branches.restore', $this->muscat->id))->assertRedirect();
        $this->assertNotSoftDeleted($this->muscat);
    }
}
