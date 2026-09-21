<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\ChangeOrder;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\JournalLine;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\ChangeOrders;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use App\Support\ProjectFinancials;
use App\Support\PurchaseOrders;
use App\Support\Quotations;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * المشروعُ يعدّ مالَه كما يعدّه الدفتر — لا بمعيارٍ ثانٍ.
 *
 * ═══ ما يُفحص (ردًّا على تدقيق ما بعد المرحلة 4) ═══
 *
 * إشعارُ الدائن ينقص الإيرادَ والربحَ ويُطفئ «فوق المعتمَد» · وتكلفةُ المورّد
 * صافيةٌ من ضريبةٍ تُستردّ كما يقيّدها الدفتر — وإجماليّةٌ لمن لا يستردّ ·
 * والذمّةُ للمورّد إجماليّةٌ دائمًا · وأمرُ شراءٍ استُلم وفُوتر بأقلّ يُقفَل
 * بيدٍ فيخرج باقيه من الملتزَم · وحالُ المصروف من قائمةٍ مغلقة.
 */
class AProjectCountsItsMoneyAsTheLedgerDoesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $customer;

    private Supplier $supplier;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);
        Ledger::seedChart($this->business->id);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@hadath.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة']);
        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّد']);

        $this->actingAs($this->owner);

        $quotation = Quotations::create((int) $this->business->id, $this->customer,
            ['title' => 'مؤتمر', 'issued_at' => now()->toDateString()],
            [['description' => 'تنظيم', 'quantity' => 1, 'unit_price' => 1000]], $this->owner);
        Quotations::send($quotation, $this->owner);
        Quotations::recordDecision($quotation->fresh(), 'accepted', ['decided_by_name' => 'سالم'], $this->owner);

        $this->project = Project::firstOrFail();
    }

    private function po(float $subtotal, float $tax, string $status = PurchaseOrders::SENT): PurchaseOrder
    {
        return PurchaseOrder::create([
            'business_id' => $this->business->id, 'number' => 'PO-'.rand(1000, 9999),
            'supplier_id' => $this->supplier->id, 'supplier_name' => 'مورّد',
            'status' => $status, 'items_subtotal' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax,
            'ordered_at' => now(), 'project_id' => $this->project->id,
        ]);
    }

    private function supplierInvoice(float $subtotal, float $tax, ?PurchaseOrder $po = null): SupplierInvoice
    {
        $invoice = SupplierInvoices::create((int) $this->business->id, [
            'supplier_id' => $this->supplier->id, 'purchase_order_id' => $po?->id,
            'supplier_ref' => 'R-'.rand(1000, 9999), 'issued_at' => now()->toDateString(),
            'subtotal' => $subtotal, 'tax' => $tax,
        ], $this->owner);
        $invoice->update(['project_id' => $this->project->id]);

        return $invoice->fresh();
    }

    /** رصيدُ حسابٍ في الدفتر بمفتاحه — مدينُه ناقصَ دائنِه */
    private function ledgerBalance(string $key): float
    {
        $account = Ledger::account((int) $this->business->id, $key);

        return round((float) JournalLine::where('account_id', $account->id)->sum('debit')
            - (float) JournalLine::where('account_id', $account->id)->sum('credit'), 3);
    }

    /* ─────────────────────────── إشعارُ الدائن ─────────────────────────── */

    public function test_a_credit_note_reduces_project_revenue_and_clears_the_over_billing_flag(): void
    {
        /* الأصلُ 1050 بضريبته · فاتورةٌ صادرة بها كاملةً */
        $invoice = CustomerInvoices::create((int) $this->business->id, $this->customer, ['project_id' => $this->project->id],
            [['description' => 'تنظيم', 'quantity' => 1, 'unit_price' => 1000]], $this->owner->id);
        CustomerInvoices::issue($invoice->fresh(), $this->owner->id);

        /* نقصٌ مقبول: نصفُ الكميّة — القيمةُ الحاليّة 525 */
        $order = ChangeOrders::create($this->project, ['title' => 'نصف التنظيم', 'requested_by_party' => 'customer'],
            [['project_item_id' => $this->project->items[0]->id, 'quantity' => -0.5]], [], $this->owner);
        ChangeOrders::send($order, $this->owner);
        ChangeOrders::recordDecision($order->fresh(), ChangeOrder::ACCEPTED, [], $this->owner);

        $over = ProjectFinancials::summary($this->project->fresh());
        $this->assertEqualsWithDelta(525.0, $over['current_value'], 0.0005);
        $this->assertTrue($over['invoiced_above_approved'], 'مفوتَرٌ 1050 على نطاقٍ صار 525');
        $this->assertEqualsWithDelta(1000.0, $over['realized_gross_profit'], 0.0005);

        /* إشعارُ دائنٍ بيد المحاسب: 525 منها 25 ضريبة */
        CustomerInvoices::creditNote($invoice->fresh(), 525, 25, 'نقصُ النطاق', $this->owner->id);

        $after = ProjectFinancials::summary($this->project->fresh());
        $this->assertEqualsWithDelta(1050.0, $after['invoiced'], 0.0005, 'الفاتورةُ الصادرة كما هي — لا تُكتب');
        $this->assertEqualsWithDelta(525.0, $after['credited'], 0.0005);
        $this->assertEqualsWithDelta(525.0, $after['net_invoiced'], 0.0005);
        $this->assertFalse($after['invoiced_above_approved'], 'صُحّحت الفوترةُ بإشعارٍ — فلم تعد فوق المعتمَد');
        $this->assertEqualsWithDelta(500.0, $after['realized_gross_profit'], 0.0005, 'الإيرادُ الصافي 1000 − 500 مردودات');
        $this->assertEqualsWithDelta(500.0, $this->ledgerBalance('sales_returns'), 0.0005, 'وهو ما قيّده الدفترُ مردوداتٍ');
        $this->assertEqualsWithDelta(525.0, $after['customer_outstanding'], 0.0005, 'ولا يُطرح الإشعارُ مرّتين: 1050 − 0 محصَّل − 525');
    }

    /* ─────────────────────────── أساسُ الضريبة ─────────────────────────── */

    public function test_supplier_cost_is_net_of_recoverable_vat_like_the_ledger_and_the_liability_stays_gross(): void
    {
        $po = $this->po(1000, 50);
        $invoice = $this->supplierInvoice(1000, 50, $po);

        $committed = ProjectFinancials::summary($this->project);
        $this->assertEqualsWithDelta(1000.0, $committed['open_purchase_orders'], 0.0005, 'أمرُ الشراء صافيًا كذلك — أساسٌ واحد للتغطية');
        $this->assertEqualsWithDelta(1000.0, $committed['committed'], 0.0005);

        SupplierInvoices::approve($invoice, $this->owner, 'اعتمادٌ مباشر');

        $s = ProjectFinancials::summary($this->project);
        $this->assertEqualsWithDelta(1000.0, $s['supplier_actual'], 0.0005, 'التكلفةُ صافيةٌ من ضريبةٍ تُستردّ');
        $this->assertEqualsWithDelta(1000.0, $s['actual'], 0.0005);
        /* المرحلة 12 §18: أمرٌ بلا أصنافٍ مخزونة تكلفةٌ مباشرة (5150) لا مخزون — والرقمُ هو هو */
        $this->assertEqualsWithDelta($this->ledgerBalance('direct_purchases'), $s['actual'], 0.0005, 'وهي ما قيّده الدفترُ ثمنًا');
        $this->assertEqualsWithDelta(50.0, $this->ledgerBalance('tax_payable'), 0.0005, 'والضريبةُ في حسابها لا في التكلفة');
        $this->assertEqualsWithDelta(1050.0, $s['supplier_outstanding'], 0.0005, 'والذمّةُ للمورّد بكاملها — ما يُدفع له');
        $this->assertEqualsWithDelta(0.0, $s['open_purchase_orders'], 0.0005, 'وسندٌ صافيه 1000 يغطّي أمرًا صافيه 1000');
        $this->assertEqualsWithDelta(0.0, $s['committed'], 0.0005);

        /* وسندٌ معلَّق بلا أمر يُقرأ ملتزَمًا صافيًا كذلك */
        $this->supplierInvoice(200, 10);
        $this->assertEqualsWithDelta(200.0, ProjectFinancials::summary($this->project)['pending_supplier_invoices'], 0.0005);
    }

    public function test_without_vat_registration_the_gross_amount_is_the_cost_as_in_the_ledger(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);

        $po = $this->po(1000, 50);
        $invoice = $this->supplierInvoice(1000, 50, $po);
        $this->assertEqualsWithDelta(1050.0, ProjectFinancials::summary($this->project)['open_purchase_orders'], 0.0005);

        SupplierInvoices::approve($invoice, $this->owner, 'اعتمادٌ مباشر');

        $s = ProjectFinancials::summary($this->project);
        $this->assertEqualsWithDelta(1050.0, $s['supplier_actual'], 0.0005, 'من لا يستردّ الضريبةَ يدفعها ثمنًا');
        $this->assertEqualsWithDelta($this->ledgerBalance('direct_purchases'), $s['actual'], 0.0005);
        $this->assertEqualsWithDelta(0.0, $s['open_purchase_orders'], 0.0005);
    }

    /* ─────────────────────────── أمرُ شراءٍ فُوتر بأقلّ ─────────────────────────── */

    public function test_a_received_order_invoiced_for_less_blocks_close_until_someone_closes_it_with_a_reason(): void
    {
        $po = $this->po(600, 0, PurchaseOrders::RECEIVED);
        SupplierInvoices::approve($this->supplierInvoice(550, 0, $po), $this->owner, 'اعتمادٌ مباشر');

        $this->assertEqualsWithDelta(50.0, ProjectFinancials::summary($this->project)['committed'], 0.0005, 'الفرقُ ملتزَمٌ ما لم يُقَل غيرُ ذلك');

        /* بلا سببٍ لا يُقفَل · وبلا الصلاحيّة لا يُقفَل */
        try {
            PurchaseOrders::close($po, '   ', $this->owner);
            $this->fail('أُقفل أمرٌ بلا سبب');
        } catch (RuntimeException) {
        }
        $clerk = User::create([
            'business_id' => $this->business->id, 'name' => 'كاتب', 'email' => 'c@hadath.om',
            'password' => bcrypt('password'), 'role' => 'inventory', 'status' => 'نشط',
        ]);
        try {
            PurchaseOrders::close($po, 'خصم من المورّد', $clerk);
            $this->fail('أُقفل أمرٌ بلا صلاحيّة التجاوز');
        } catch (RuntimeException) {
        }
        $this->actingAs($clerk);
        $this->post(route('admin.purchases.close', $po->id), ['note' => 'خصم'])->assertForbidden();

        /* ولا يُقفَل ما لم يُستلَم كلُّه أو لم يُفوتَر */
        $this->actingAs($this->owner);
        $partial = $this->po(300, 0, PurchaseOrders::PARTIAL);
        SupplierInvoices::approve($this->supplierInvoice(100, 0, $partial), $this->owner, 'اعتمادٌ مباشر');
        try {
            PurchaseOrders::close($partial, 'x', $this->owner);
            $this->fail('أُقفل أمرٌ لم يُستلَم كلُّه');
        } catch (RuntimeException) {
        }
        $uninvoiced = $this->po(300, 0, PurchaseOrders::RECEIVED);
        try {
            PurchaseOrders::close($uninvoiced, 'x', $this->owner);
            $this->fail('أُقفل أمرٌ لم يُفوتَر');
        } catch (RuntimeException) {
        }

        $this->post(route('admin.purchases.close', $po->id), ['note' => 'المورّد خصم فرق الشحن'])->assertSessionHasNoErrors();

        $closed = $po->fresh();
        $this->assertNotNull($closed->closed_at);
        $this->assertSame($this->owner->id, $closed->closed_by);
        $this->assertSame('المورّد خصم فرق الشحن', $closed->closing_note);
        $this->assertSame(PurchaseOrders::RECEIVED, $closed->status, 'الحالُ لا تتغيّر — الإقفالُ وسمٌ فوقها');
        $this->assertEqualsWithDelta(550.0 + 100.0, ProjectFinancials::summary($this->project)['actual'], 0.0005, 'الفعليُّ لا يتغيّر بالإقفال');

        $s = ProjectFinancials::summary($this->project);
        $this->assertEqualsWithDelta(200.0 + 300.0, $s['open_purchase_orders'], 0.0005, 'الأمرُ المُقفَل وحده خرج — والآخران باقيان');

        /* والإقفالُ ثانيةً يردّ نفسَه */
        $this->assertSame($closed->closed_at->toDateTimeString(), PurchaseOrders::close($po, 'ثانية', $this->owner)->closed_at->toDateTimeString());
    }

    /* ─────────────────────────── حالُ المصروف ─────────────────────────── */

    public function test_an_expense_status_outside_the_list_is_refused(): void
    {
        $this->post(route('admin.expenses.store'), [
            'type' => 'نقل', 'amount' => 30, 'status' => 'مؤجَّل', 'project_id' => $this->project->id,
        ])->assertSessionHasErrors('status');
        $this->assertSame(0, Expense::count());

        $this->post(route('admin.expenses.store'), [
            'type' => 'نقل', 'amount' => 30, 'status' => Expense::UNPAID, 'project_id' => $this->project->id,
        ])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(30.0, ProjectFinancials::summary($this->project)['expenses_unpaid'], 0.0005);
    }
}
