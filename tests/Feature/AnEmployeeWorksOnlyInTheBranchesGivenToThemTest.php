<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Support\BranchAccess;
use App\Support\GoodsReceipts;
use App\Support\Ledger;
use App\Support\OrderStatus;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * فروعُ العمل تُفرض — لا تُسجَّل في الملفّ وحده.
 *
 * كانت `branch_user` تُحفظ ولا يسألها شيء: موظّفُ مسقط يبدّل إلى صور من
 * رأس الشاشة، ويكتب `branch_id` صور في نموذج الجرد، ويرى في «كل الفروع»
 * طلباتِ الشركة كلِّها. والقاعدةُ الآن في `BranchAccess`:
 *
 *  - صاحبُ النشاط: كلُّ الفروع.
 *  - موظّفٌ بلا صفوف: كلُّ الفروع (كما كان كلُّ موظّفٍ قبل الفرض).
 *  - موظّفٌ له صفوف: فروعُه وحدها — و«كل الفروع» له فروعُه هو.
 *  - فرعُ نشاطٍ آخر: مرفوضٌ أبدًا.
 */
class AnEmployeeWorksOnlyInTheBranchesGivenToThemTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $muscat;

    private Branch $sur;

    private Branch $nizwa;

    private Branch $theirs;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'إيفنت بلس', 'status' => 'نشط']);
        $this->muscat = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        $this->sur = Branch::create(['business_id' => $this->shop->id, 'name' => 'صور']);
        $this->nizwa = Branch::create(['business_id' => $this->shop->id, 'name' => 'نزوى']);
        Ledger::seedChart($this->shop->id);

        $other = Business::create(['name' => 'شركة الجار', 'status' => 'نشط']);
        $this->theirs = Branch::create(['business_id' => $other->id, 'name' => 'فرع الجار']);
        Order::create($this->orderRow($other->id, $this->theirs, 'INV-X'));

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        foreach (['INV-M' => $this->muscat, 'INV-S' => $this->sur, 'INV-N' => $this->nizwa] as $number => $branch) {
            Order::create($this->orderRow($this->shop->id, $branch, $number));
        }
    }

    private function orderRow(int $businessId, Branch $branch, string $number): array
    {
        return [
            'business_id' => $businessId, 'branch_id' => $branch->id, 'branch' => $branch->name,
            'number' => $number, 'customer_name' => 'عميل', 'employee_name' => 'نورة',
            'subtotal' => 10, 'tax' => 0, 'total' => 10,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'status' => OrderStatus::COMPLETED, 'ordered_at' => now(),
        ];
    }

    /** @param  list<Branch>  $branches */
    private function employee(array $branches = [], string $role = 'sales', ?array $permissions = null): User
    {
        $user = User::create([
            'business_id' => $this->shop->id, 'name' => 'موظّف '.$role,
            'email' => $role.random_int(1000, 999999).'@eventplus.om',
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
            'permissions' => $permissions,
        ]);
        $user->branches()->sync(array_map(fn (Branch $b) => $b->id, $branches));

        return $user;
    }

    /** @return list<string> */
    private function orderNumbers(): array
    {
        return collect($this->get(route('admin.orders.index'))->assertOk()->viewData('page')['props']['orders'])
            ->pluck('id')->sort()->values()->all();
    }

    /** @return list<string> */
    private function headerBranches(): array
    {
        return collect($this->get(route('admin.dashboard'))->assertOk()->viewData('page')['props']['context']['branches'])
            ->pluck('name')->all();
    }

    /* ══════════════ ١ · بلا صفوف: كلُّ الفروع ══════════════ */

    public function test_an_employee_without_branch_rows_enters_every_branch_of_the_business(): void
    {
        $this->actingAs($this->employee());

        foreach ([$this->muscat, $this->sur, $this->nizwa] as $branch) {
            $this->get(route('admin.branch.switch', $branch->id))->assertRedirect();
            $this->assertSame($branch->id, session('current_branch'));
        }

        $this->get(route('admin.branch.switch', 'all'));
        $this->assertSame(['INV-M', 'INV-N', 'INV-S'], $this->orderNumbers());
    }

    /* ══════════════ ٢–٤ · التبديل ══════════════ */

    public function test_an_employee_tied_to_one_branch_switches_to_it(): void
    {
        $this->actingAs($this->employee([$this->muscat]));

        $this->get(route('admin.branch.switch', $this->muscat->id))->assertRedirect();

        $this->assertSame($this->muscat->id, session('current_branch'));
        $this->assertSame(['INV-M'], $this->orderNumbers());
    }

    public function test_the_same_employee_cannot_switch_to_another_branch_of_the_business(): void
    {
        $this->actingAs($this->employee([$this->muscat]));

        $this->get(route('admin.branch.switch', $this->sur->id))->assertForbidden();

        $this->assertNull(session('current_branch'));
    }

    public function test_no_one_switches_to_a_branch_of_another_business(): void
    {
        $this->actingAs($this->employee([$this->muscat]));
        $this->get(route('admin.branch.switch', $this->theirs->id))->assertNotFound();

        $this->actingAs($this->employee());
        $this->get(route('admin.branch.switch', $this->theirs->id))->assertNotFound();

        $this->assertNull(session('current_branch'));
    }

    /* ══════════════ ٥–٦ · «كل الفروع» فروعُه هو ══════════════ */

    public function test_all_branches_for_an_employee_tied_to_one_branch_shows_that_branch_only(): void
    {
        $this->actingAs($this->employee([$this->muscat]));
        $this->get(route('admin.branch.switch', 'all'));

        $this->assertSame(['INV-M'], $this->orderNumbers());

        // ولا تُفتح ورقةُ طلبٍ من فرعٍ آخر، ولا يُحرَّك
        $this->get(route('admin.orders.show', 'INV-S'))->assertNotFound();
        $this->get(route('admin.orders.pdf', 'INV-N'))->assertNotFound();
        $this->post(route('admin.orders.status', 'INV-S'), ['status' => OrderStatus::CANCELLED])->assertNotFound();
        $this->assertSame(OrderStatus::COMPLETED, Order::where('number', 'INV-S')->value('status'));
    }

    public function test_all_branches_for_an_employee_tied_to_two_branches_shows_those_two(): void
    {
        $this->actingAs($this->employee([$this->muscat, $this->sur]));
        $this->get(route('admin.branch.switch', 'all'));

        $this->assertSame(['INV-M', 'INV-S'], $this->orderNumbers());
        $this->assertSame([$this->muscat->id, $this->sur->id], BranchAccess::currentAllowedBranchIds());
    }

    /** والمخزونُ في «كل الفروع» رصيدُ فروعه — لا مفرّقًا على غيرها ولا مجموعًا معها */
    public function test_the_inventory_screens_show_only_the_stock_of_their_branches(): void
    {
        $product = Product::create(['business_id' => $this->shop->id, 'name' => 'منصّة', 'price' => 5, 'cost' => 2, 'quantity' => 30]);
        foreach ([[$this->muscat, 10], [$this->sur, 15], [$this->nizwa, 5]] as [$branch, $qty]) {
            BranchStock::create(['business_id' => $this->shop->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => $qty]);
        }

        $this->actingAs($this->employee([$this->muscat], 'inventory'));

        $row = collect($this->get(route('admin.inventory.index'))->assertOk()->viewData('page')['props']['inventory'])
            ->firstWhere('id', $product->id);
        $this->assertSame(10, $row['qty']);
        $this->assertSame(10, $row['totalQty']);
        $this->assertSame(['مسقط'], collect($row['branches'])->pluck('name')->all());

        // وصاحبُ النشاط يرى الشركة كلَّها كما كان
        $this->actingAs($this->owner);
        $row = collect($this->get(route('admin.inventory.index'))->viewData('page')['props']['inventory'])->firstWhere('id', $product->id);
        $this->assertSame([30, 30], [$row['qty'], $row['totalQty']]);
    }

    /* ══════════════ ٧ · صاحبُ النشاط ══════════════ */

    public function test_the_owner_sees_every_branch(): void
    {
        $this->actingAs($this->owner);

        $this->assertSame(['INV-M', 'INV-N', 'INV-S'], $this->orderNumbers());
        $this->assertSame(['مسقط', 'صور', 'نزوى'], $this->headerBranches());

        foreach ([$this->muscat, $this->sur, $this->nizwa] as $branch) {
            $this->get(route('admin.branch.switch', $branch->id))->assertRedirect();
        }
        $this->get(route('admin.branch.switch', 'all'));
        $this->assertNull(BranchAccess::currentAllowedBranchIds());
    }

    /* ══════════════ ٨ · جلسةٌ على فرعٍ أُزيل ══════════════ */

    public function test_removing_a_branch_ends_the_session_that_stands_on_it(): void
    {
        $employee = $this->employee([$this->muscat, $this->sur]);
        $this->actingAs($employee);
        $this->get(route('admin.branch.switch', $this->sur->id));
        $this->assertSame(['INV-S'], $this->orderNumbers());

        // المدير يُزيل صور من فروع عمله — والجلسةُ ما زالت تقول «صور»
        $employee->branches()->sync([$this->muscat->id]);

        $page = $this->get(route('admin.orders.index'))->assertOk()->viewData('page')['props'];

        $this->assertSame(['INV-M'], collect($page['orders'])->pluck('id')->all());
        $this->assertNull($page['context']['branchId']);
        $this->assertNull(session('current_branch'));
    }

    /* ══════════════ ٩ · الكتابةُ اليدويّة ══════════════ */

    public function test_a_hand_written_branch_id_is_refused_on_the_server(): void
    {
        $product = Product::create(['business_id' => $this->shop->id, 'name' => 'كرسي', 'price' => 5, 'cost' => 2, 'quantity' => 0]);
        $this->actingAs($this->employee([$this->muscat], 'inventory'));
        $move = fn (Branch $b) => $this->post(route('admin.inventory.store'), [
            'product_id' => $product->id, 'branch_id' => $b->id, 'type' => 'إضافة كمية', 'quantity' => 5,
        ]);

        $move($this->sur)->assertForbidden();
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, (int) $product->fresh()->quantity);

        // والفرعُ المسموح يمرّ بالباب نفسه — فالرفضُ للفرع لا للنموذج
        $move($this->muscat)->assertSessionHasNoErrors();
        $this->assertSame(5, (int) $product->fresh()->quantity);

        // والمرشِّح المكتوب في الرابط يُسأل عنه كالكتابة
        $this->get(route('admin.inventory.adjustments', ['branch_id' => $this->nizwa->id]))->assertForbidden();
    }

    public function test_a_document_of_another_branch_is_not_acted_on(): void
    {
        $supplier = Supplier::create(['business_id' => $this->shop->id, 'name' => 'مورّد']);
        $product = Product::create(['business_id' => $this->shop->id, 'name' => 'إضاءة', 'price' => 10, 'cost' => 4, 'quantity' => 0]);
        $note = function (Branch $branch) use ($supplier, $product) {
            $po = PurchaseOrder::create([
                'business_id' => $this->shop->id, 'branch_id' => $branch->id, 'number' => 'PO-'.$branch->id,
                'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name,
                'status' => 'مُرسل', 'total' => 40, 'ordered_at' => now(),
            ]);
            $line = $po->items()->create(['product_id' => $product->id, 'name' => $product->name, 'cost' => 4, 'quantity' => 10]);

            return GoodsReceipts::record($po->refresh(), [$line->id => 10], [], $this->owner);
        };
        $atSur = $note($this->sur);
        $atMuscat = $note($this->muscat);

        $this->actingAs($this->employee([$this->muscat], 'inventory', [
            'inventory', Permissions::RECEIPT_VIEW, Permissions::RECEIPT_APPROVE,
        ]));

        $this->post(route('admin.purchases.receipts.approve', $atSur->id))->assertNotFound();
        $this->assertSame(GoodsReceipts::PENDING, $atSur->fresh()->status);
        $this->get(route('admin.inventory.receipts.show', $atSur->id))->assertNotFound();

        // وما ينتظر الاعتماد في «المخزون» سنداتُ فروعه وحدها — قائمةُ السندات خرجت من الواجهة
        $listed = collect($this->get(route('admin.inventory.index'))->assertOk()->viewData('page')['props']['pendingReceipts'])->pluck('id')->all();
        $this->assertSame([$atMuscat->id], $listed);

        $this->post(route('admin.purchases.receipts.approve', $atMuscat->id))->assertSessionHasNoErrors();
        $this->assertSame(GoodsReceipts::APPROVED, $atMuscat->fresh()->status);
    }

    /* ══════════════ ١٠ · لا تسرّب بين الشركات ══════════════ */

    public function test_no_branch_of_another_business_leaks_in(): void
    {
        $employee = $this->employee([$this->muscat]);
        // صفٌّ عابثٌ يشير إلى فرع الجار — لا يفتح شيئًا
        DB::table('branch_user')->insert(['user_id' => $employee->id, 'branch_id' => $this->theirs->id]);

        $this->assertSame([$this->muscat->id], BranchAccess::allowedBranchIds($employee));
        $this->assertFalse(BranchAccess::canAccess($employee, $this->theirs->id));
        $this->assertFalse($employee->worksAt($this->theirs->id));

        $this->actingAs($employee);
        $this->assertSame(['مسقط'], $this->headerBranches());
        $this->assertSame(['INV-M'], $this->orderNumbers());
        $this->get(route('admin.branch.switch', $this->theirs->id))->assertNotFound();

        // وصاحبُ النشاط لا يرى فرع الجار ولا طلبه
        $this->actingAs($this->owner);
        $this->assertNotContains('INV-X', $this->orderNumbers());
        $this->assertNotContains('فرع الجار', $this->headerBranches());
    }

    /* ══════════════ ١١ · المبدّل في رأس الشاشة ══════════════ */

    public function test_the_header_lists_only_the_branches_one_may_enter(): void
    {
        $this->actingAs($this->employee([$this->muscat]));
        $this->assertSame(['مسقط'], $this->headerBranches());

        $this->actingAs($this->employee([$this->muscat, $this->sur]));
        $this->assertSame(['مسقط', 'صور'], $this->headerBranches());

        $this->actingAs($this->employee());
        $this->assertSame(['مسقط', 'صور', 'نزوى'], $this->headerBranches());
    }

    /* ══════════════ ١٢ · الموظّفون الحاليّون ══════════════ */

    public function test_an_existing_employee_without_branch_rows_does_not_break(): void
    {
        // موظّفٌ قديمٌ كما هم كلُّهم صباحَ النشر: بلا صفٍّ واحد في branch_user
        $employee = $this->employee([], 'accountant');
        $this->assertSame(0, DB::table('branch_user')->where('user_id', $employee->id)->count());
        $this->assertFalse(BranchAccess::restricts($employee));

        $this->actingAs($employee);
        $this->get(route('admin.dashboard'))->assertOk();
        $this->assertSame(['INV-M', 'INV-N', 'INV-S'], $this->orderNumbers());
        $this->assertSame(['مسقط', 'صور', 'نزوى'], $this->headerBranches());
        $this->assertTrue($employee->worksAt($this->nizwa->id));
        $this->get(route('admin.branch.switch', $this->nizwa->id))->assertRedirect();
        $this->assertSame(['INV-N'], $this->orderNumbers());
    }

    /* ══════════════ وما يتبعها ══════════════ */

    /** حذفُ فرعه لا يجعله «بلا قيود» */
    public function test_deleting_the_only_branch_of_an_employee_does_not_open_every_branch(): void
    {
        $employee = $this->employee([$this->muscat]);
        $this->muscat->delete();

        $this->assertTrue(BranchAccess::restricts($employee));
        $this->assertSame([], BranchAccess::allowedBranchIds($employee));

        $this->actingAs($employee);
        $this->assertSame([], $this->orderNumbers());
        $this->get(route('admin.branch.switch', $this->sur->id))->assertForbidden();
    }

    /** مديرٌ محصورٌ في مسقط لا يفتح لغيره ما لم يُفتح له */
    public function test_a_restricted_manager_does_not_hand_out_branches_beyond_their_own(): void
    {
        $this->actingAs($this->employee([$this->muscat], 'manager'));
        $title = JobTitle::create(['business_id' => $this->shop->id, 'name' => 'منسّق', 'role' => 'sales']);
        $create = fn (array $branches) => $this->post(route('admin.employees.store'), [
            'name' => 'سالم', 'login_username' => 'salem'.random_int(100, 99999),
            'job_title' => $title->name, 'password' => 'secret123', 'branches' => $branches,
        ]);

        $create([])->assertSessionHasErrors('branches');
        $create([$this->sur->id])->assertSessionHasErrors('branches');
        $this->assertSame(0, User::where('name', 'سالم')->count());

        $create([$this->muscat->id])->assertSessionHasNoErrors();
        $hired = User::where('name', 'سالم')->firstOrFail();
        $this->assertSame([$this->muscat->id], BranchAccess::allowedBranchIds($hired));
    }
}
