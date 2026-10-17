<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\GoodsReceiptNote;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\EmployeeAccess;
use App\Support\GoodsReceipts;
use App\Support\Ledger;
use App\Support\Permissions;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اعتمادُ الاستلام ورفضُه يُسألان في الشاشة التي يُضغطان منها.
 *
 * زرّاهما في شاشتي المخزون وحدهما، ومسارُهما `admin.purchases.receipts.*`
 * — والقسمُ يُشتقّ من الاسم. فمن مُنح «المخزون» و«اعتماد الاستلام» رأى
 * الزرّ ومُنع عند الضغط بقسم «المشتريات» الذي لم يُعطَه، قبل أن يُسأل عن
 * فعله أصلًا. والإصلاحُ نسبةُ هذين الاسمين وحدهما إلى المخزون
 * (`Permissions::ROUTE_SECTIONS`) — والفعلُ يبقى يُسأل عنه بعد القسم.
 */
class TheReceiptIsDecidedFromTheScreenThatShowsItTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'إيفنت بلس', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Ledger::seedChart($this->business->id);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّد المعدّات']);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'منصّة', 'price' => 10, 'cost' => 4, 'quantity' => 0,
        ]);
    }

    /** موظّفٌ بقائمةٍ يدوية — لا يملك إلا ما يُكتب له بحرفه */
    private function staff(array $permissions, string $role = 'inventory'): User
    {
        return User::create([
            'business_id' => $this->business->id,
            'name' => 'موظّف', 'email' => 'e'.random_int(1000, 999999).'@eventplus.om',
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
            'permissions' => $permissions,
        ]);
    }

    private function order(): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'number' => 'PO-'.random_int(1000, 9999),
            'supplier_id' => $this->supplier->id, 'supplier_name' => $this->supplier->name,
            'status' => 'مُرسل', 'total' => 400, 'ordered_at' => now(),
        ]);
        $po->items()->create([
            'product_id' => $this->product->id, 'name' => $this->product->name, 'cost' => 4, 'quantity' => 100,
        ]);

        return $po->refresh();
    }

    private function pendingNote(): GoodsReceiptNote
    {
        $po = $this->order();

        return GoodsReceipts::record($po, [$po->items()->first()->id => 10], [], $this->owner);
    }

    private function approve(User $user, GoodsReceiptNote $note)
    {
        return $this->actingAs($user)->post(route('admin.purchases.receipts.approve', $note->id));
    }

    private function reject(User $user, GoodsReceiptNote $note)
    {
        return $this->actingAs($user)->post(route('admin.purchases.receipts.reject', $note->id), ['reason' => 'وصلت ناقصة']);
    }

    /* ══════════════ ١–٢ · الاعتماد من المخزون ══════════════ */

    public function test_inventory_with_the_approve_action_approves_from_the_inventory_screen(): void
    {
        $keeper = $this->staff(['inventory', Permissions::RECEIPT_VIEW, Permissions::RECEIPT_APPROVE]);
        $note = $this->pendingNote();

        // يرى السندَ في «المخزون» بانتظار الاعتماد، وزرَّ اعتماده في صفحته
        $waiting = $this->actingAs($keeper)->get(route('admin.inventory.index'))->assertOk()->viewData('page')['props']['pendingReceipts'];
        $this->assertSame([$note->id], array_column($waiting, 'id'));
        $page = $this->actingAs($keeper)->get(route('admin.inventory.receipts.show', $note->id))->assertOk()->viewData('page')['props'];
        $this->assertTrue($page['can']['approve']);

        $this->approve($keeper, $note)->assertSessionHasNoErrors();

        $this->assertSame(GoodsReceipts::APPROVED, $note->fresh()->status);
        $this->assertSame(10, (int) $this->product->fresh()->quantity);
    }

    public function test_the_same_user_without_the_approve_action_is_refused(): void
    {
        $keeper = $this->staff(['inventory', Permissions::RECEIPT_VIEW]);
        $note = $this->pendingNote();

        $this->approve($keeper, $note)->assertForbidden();

        $this->assertSame(GoodsReceipts::PENDING, $note->fresh()->status);
        $this->assertSame(0, (int) $this->product->fresh()->quantity);
    }

    /* ══════════════ ٣–٤ · الرفض من المخزون ══════════════ */

    public function test_inventory_with_the_reject_action_rejects(): void
    {
        $keeper = $this->staff(['inventory', Permissions::RECEIPT_REJECT]);
        $note = $this->pendingNote();

        $this->reject($keeper, $note)->assertSessionHasNoErrors();

        $this->assertSame(GoodsReceipts::REJECTED, $note->fresh()->status);
    }

    public function test_without_the_reject_action_rejecting_is_refused(): void
    {
        // ولا يكفي الاعتمادُ عن الرفض: فعلان باسمين
        $keeper = $this->staff(['inventory', Permissions::RECEIPT_APPROVE]);
        $note = $this->pendingNote();

        $this->reject($keeper, $note)->assertForbidden();

        $this->assertSame(GoodsReceipts::PENDING, $note->fresh()->status);
    }

    /* ══════════════ ٥ · الفعلُ وحده لا يفتح الباب ══════════════ */

    public function test_the_approve_action_alone_without_inventory_opens_nothing(): void
    {
        $user = $this->staff([Permissions::RECEIPT_VIEW, Permissions::RECEIPT_APPROVE, Permissions::RECEIPT_REJECT], 'sales');
        $note = $this->pendingNote();

        $this->actingAs($user)->get(route('admin.inventory.receipts'))->assertForbidden();
        $this->approve($user, $note)->assertForbidden();
        $this->reject($user, $note)->assertForbidden();

        // و«المشتريات» لم تعد بابَهما: من يملكها بلا المخزون لا يعتمد من شاشةٍ لا يراها
        $buyer = $this->staff(['purchases', Permissions::RECEIPT_APPROVE], 'sales');
        $this->approve($buyer, $note)->assertForbidden();

        $this->assertSame(GoodsReceipts::PENDING, $note->fresh()->status);
    }

    /* ══════════════ ٦ · المالك والمدير ══════════════ */

    public function test_the_owner_and_the_manager_keep_approving_and_rejecting(): void
    {
        $manager = User::create([
            'business_id' => $this->business->id, 'name' => 'مدير الفرع', 'email' => 'm@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'manager', 'status' => 'نشط',
        ]);

        $first = $this->pendingNote();
        $this->approve($this->owner, $first)->assertSessionHasNoErrors();
        $this->assertSame(GoodsReceipts::APPROVED, $first->fresh()->status);

        $second = $this->pendingNote();
        $this->approve($manager, $second)->assertSessionHasNoErrors();
        $this->assertSame(GoodsReceipts::APPROVED, $second->fresh()->status);

        $third = $this->pendingNote();
        $this->reject($manager, $third)->assertSessionHasNoErrors();
        $this->assertSame(GoodsReceipts::REJECTED, $third->fresh()->status);
    }

    /** ومسؤولُ المخزون بدوره الافتراضيّ لا يعتمد — كما كان */
    public function test_the_inventory_role_still_does_not_approve_by_default(): void
    {
        $keeper = User::create([
            'business_id' => $this->business->id, 'name' => 'أمين المخزن', 'email' => 'k@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'inventory', 'status' => 'نشط',
        ]);

        $this->assertSame(['admin', 'manager'], Permissions::ACTION_ROLES[Permissions::RECEIPT_APPROVE]);
        $this->assertSame(['admin', 'manager'], Permissions::ACTION_ROLES[Permissions::RECEIPT_REJECT]);
        $this->assertFalse($keeper->may(Permissions::RECEIPT_APPROVE));
        $this->assertFalse($keeper->may(Permissions::RECEIPT_REJECT));

        $note = $this->pendingNote();
        $this->approve($keeper, $note)->assertForbidden();
        $this->reject($keeper, $note)->assertForbidden();
        $this->assertSame(GoodsReceipts::PENDING, $note->fresh()->status);
    }

    /* ══════════════ ٧ · تسجيلُ الاستلام باقٍ في المشتريات ══════════════ */

    public function test_recording_a_receipt_stays_where_it_is(): void
    {
        $po = $this->order();
        $line = $po->items()->first()->id;
        $body = ['items' => [['id' => $line, 'quantity' => 5]]];

        // «تسجيل الاستلام» يُضغط من أمر الشراء: بابُه المشتريات، لا المخزون
        $this->actingAs($this->staff(['inventory', Permissions::RECEIPT_CREATE]))
            ->post(route('admin.purchases.receive', $po->id), $body)->assertForbidden();
        $this->actingAs($this->staff(['purchases']))
            ->post(route('admin.purchases.receive', $po->id), $body)->assertForbidden();
        $this->assertSame(0, GoodsReceiptNote::count());

        $this->actingAs($this->staff(['purchases', Permissions::RECEIPT_CREATE]))
            ->post(route('admin.purchases.receive', $po->id), $body)->assertSessionHasNoErrors();
        $this->assertSame(1, GoodsReceiptNote::count());

        $this->assertSame('purchases', Permissions::sectionFromRoute('admin.purchases.receive'));
        $this->assertSame(['purchases'], EmployeeAccess::PARENTS[Permissions::RECEIPT_CREATE]);
    }

    /* ══════════════ ٨ · سنداتُ الموردين لا تتأثّر ══════════════ */

    public function test_supplier_invoice_approve_and_pay_still_ask_for_purchases(): void
    {
        $invoice = SupplierInvoice::create([
            'business_id' => $this->business->id, 'supplier_id' => $this->supplier->id,
            'supplier_ref' => 'S-'.random_int(1000, 999999), 'issued_at' => now()->toDateString(),
            'subtotal' => 100, 'tax' => 0, 'total' => 100, 'paid' => 0,
            'status' => 'غير مدفوع', 'approval_status' => SupplierInvoices::PENDING,
        ]);

        // الفعلُ بلا قسمه: المخزونُ ليس بابَ سندات الموردين
        $this->actingAs($this->staff(['inventory', Permissions::INVOICE_APPROVE, Permissions::INVOICE_PAY]))
            ->post(route('admin.purchases.invoices.approve', $invoice->id))->assertForbidden();
        $this->assertSame(SupplierInvoices::PENDING, $invoice->fresh()->approval_status);

        $this->actingAs($this->staff(['purchases', Permissions::INVOICE_APPROVE]))
            ->post(route('admin.purchases.invoices.approve', $invoice->id))->assertSessionHasNoErrors();
        $this->assertSame(SupplierInvoices::APPROVED, $invoice->fresh()->approval_status);

        $body = ['amount' => 100, 'paid_at' => now()->toDateString(), 'from' => 'cash'];
        $this->actingAs($this->staff(['inventory', Permissions::INVOICE_PAY]))
            ->post(route('admin.purchases.invoices.pay', $invoice->id), $body)->assertForbidden();
        $this->actingAs($this->staff(['purchases', Permissions::INVOICE_PAY]))
            ->post(route('admin.purchases.invoices.pay', $invoice->id), $body)->assertSessionHasNoErrors();
        $this->assertSame(100.0, (float) $invoice->fresh()->paid);
    }

    /* ══════════════ ٩ · الاسمان وحدهما ══════════════ */

    public function test_only_the_two_receipt_decisions_are_moved_to_inventory(): void
    {
        $this->assertSame('inventory', Permissions::sectionFromRoute('admin.purchases.receipts.approve'));
        $this->assertSame('inventory', Permissions::sectionFromRoute('admin.purchases.receipts.reject'));

        // والمشترياتُ كلُّها تبقى مشتريات
        foreach ([
            'admin.purchases.invoices.approve', 'admin.purchases.invoices.reject', 'admin.purchases.invoices.pay',
            'admin.purchases.orders', 'admin.purchases.store', 'admin.purchases.receive',
        ] as $route) {
            $this->assertSame('purchases', Permissions::sectionFromRoute($route), $route);
        }

        $this->assertSame(['inventory'], EmployeeAccess::PARENTS[Permissions::RECEIPT_APPROVE]);
        $this->assertSame(['inventory'], EmployeeAccess::PARENTS[Permissions::RECEIPT_REJECT]);
        $this->assertArrayNotHasKey('purchases', Permissions::ALIASES);
    }
}
