<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لا يعبر طلبٌ حدود متجره ولا فرعه.
 *
 * الرقم يصل من شريط العنوان، وترقيمُ الفواتير يبدأ من واحدٍ عند كلّ تاجر —
 * فرقمُ متجرٍ آخر ليس تخمينًا بعيدًا بل أوّل ما يُجرَّب. والحقول الجديدة
 * تُوسّع ما يُسرَّب لو انفتح الباب: اسم المستلِم ورقمُه وعنوان بيته.
 *
 * والفحص على الأبواب كلّها لا على العرض وحده: القراءة، والتعديل، ونقل
 * الحالة، ولوحة التجهيز، وأفعالها.
 */
class OrderIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Business $mine;

    private Business $his;

    private User $me;

    private Order $hisOrder;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->mine, $this->me] = $this->shop('متجري', 'me@eventplus.om');
        [$this->his, $hisOwner] = $this->shop('متجر الجار', 'him@eventplus.om');

        $this->hisOrder = Order::create([
            'business_id' => $this->his->id,
            'branch_id' => Branch::where('business_id', $this->his->id)->value('id'),
            'number' => 'INV-0001', 'status' => OrderStatus::CONFIRMED, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
            'scheduled_for' => now()->addHour(),
            'recipient_name' => 'سارة سرّيّة', 'recipient_phone' => '99887766',
            'delivery_address' => 'بيت الجار، شارع ٣',
            'card_message' => 'رسالةٌ خاصّة',
        ]);

        $this->actingAs($this->me);
    }

    /** @return array{0: Business, 1: User} */
    private function shop(string $name, string $email): array
    {
        $b = Business::create(['name' => $name, 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);
        $u = User::create([
            'business_id' => $b->id, 'name' => 'مالك '.$name, 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        return [$b, $u];
    }

    /* --------------------------- عزل المستأجرين --------------------------- */

    public function test_i_cannot_view_his_order(): void
    {
        $this->get(route('admin.orders.show', $this->hisOrder->number))->assertNotFound();
    }

    public function test_i_cannot_edit_his_order(): void
    {
        $this->put(route('admin.orders.details.update', $this->hisOrder->number), [
            'recipient_name' => 'اسمٌ دسسته',
        ])->assertNotFound();

        $this->assertSame('سارة سرّيّة', $this->hisOrder->fresh()->recipient_name);
    }

    public function test_i_cannot_move_his_order(): void
    {
        $this->post(route('admin.orders.status', $this->hisOrder->number), [
            'status' => OrderStatus::CANCELLED,
        ])->assertNotFound();

        $this->assertSame(OrderStatus::CONFIRMED, $this->hisOrder->fresh()->status);
    }
    private function mineOrder(int $branchId, string $number): Order
    {
        return Order::create([
            'business_id' => $this->mine->id, 'branch_id' => $branchId,
            'number' => $number, 'status' => OrderStatus::CONFIRMED, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 10, 'total' => 10, 'ordered_at' => now(),
            'scheduled_for' => now()->addHour(),
        ]);
    }
    /** وصلاحيةُ التجهيز وحدها لا تفتح شاشة المبيعات وإجماليّاتها */
    public function test_preparation_alone_does_not_open_the_sales_screen(): void
    {
        $staff = User::create([
            'business_id' => $this->mine->id, 'name' => 'مجهّز', 'email' => 'p2@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'inventory', 'status' => 'نشط',
            'permissions' => ['preparation'],
        ]);

        $this->actingAs($staff)->get(route('admin.orders.index'))->assertForbidden();
    }
}
