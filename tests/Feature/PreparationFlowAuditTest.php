<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لوحة التجهيز بوصفها تدفّقًا لا شاشة: من يضغط الزرّ، وماذا يقع تحته،
 * وماذا يُقال له إن لم يقع.
 *
 * واللوحة معروضةٌ على كلّ شاشات المحلّ في وقتٍ واحد، ولا تتحدّث من نفسها.
 * فما يراه العامل قد تجاوزه غيرُه قبل لحظة — وهذا هو موضع كلّ ما هنا.
 */
class PreparationFlowAuditTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة', 'price' => 25, 'cost' => 10,
            'quantity' => 50, 'alert_qty' => 2,
        ]);

        $this->travelTo(today()->setTime(9, 0));
        $this->actingAs($this->owner);
    }

    private function order(array $extra = []): Order
    {
        $order = Order::create(array_merge([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'number' => 'INV-'.uniqid(), 'status' => OrderStatus::PREPARING, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
            'scheduled_for' => now()->addHours(3),
        ], $extra));

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id, 'name' => 'باقة',
            'price' => 25, 'cost' => 10, 'quantity' => 2, 'total' => 50,
        ]);

        return $order;
    }
    /* ------------------- اللوحة والشاشة بابٌ واحد ------------------- */
    public function test_neither_screen_writes_the_move_by_hand(): void
    {
        foreach ([
            'app/Http/Controllers/Admin/PreparationController.php',
            'app/Http/Controllers/Admin/OrderDetailController.php',
        ] as $file) {
            $source = file_get_contents(base_path($file));

            $this->assertStringContainsString('OrderTransition::apply', $source);
            $this->assertStringNotContainsString('OrderStatus::canMove', $source);
        }
    }

    /* ------------------- شاشتان تتحرّكان معًا ------------------- */

    /**
     * موظّفان على اللوحة نفسها: أحدهما يُلغي والآخر ينقل إلى «جاهز».
     *
     * كلٌّ منهما يحمل نسخته من الصفّ قُرئت قبل الضغطة. ولولا القفل لكتبت
     * الكتابةُ الأخرى «جاهز» فوق الإلغاء: طلبٌ حيٌّ على اللوحة، بضاعتُه
     * عادت إلى الرفّ ولا قيدَ دخلٍ له.
     */
    public function test_a_stale_move_cannot_overwrite_a_cancellation(): void
    {
        $order = $this->order();
        Transaction::create([
            'business_id' => $this->business->id, 'order_id' => $order->id,
            'type' => 'دخل', 'amount' => 25, 'occurred_at' => now(),
            'reference' => 'TRX-'.uniqid(), 'description' => 'بيع',
        ]);

        $stale = Order::findOrFail($order->id);   // شاشةُ الموظّف الثاني

        \App\Support\OrderTransition::apply($order, OrderStatus::CANCELLED);
        $error = \App\Support\OrderTransition::apply($stale, OrderStatus::READY);

        $this->assertNotNull($error);
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(0, Transaction::where('order_id', $order->id)->count());
    }

    public function test_a_stale_move_does_not_return_the_stock_twice(): void
    {
        $order = $this->order();
        $before = (int) $this->product->fresh()->quantity;

        $stale = Order::findOrFail($order->id);
        \App\Support\OrderTransition::apply($order, OrderStatus::CANCELLED);
        \App\Support\OrderTransition::apply($stale, OrderStatus::CANCELLED);

        $this->assertSame($before + 2, (int) $this->product->fresh()->quantity);
    }
    /* ------------------- الصلاحية ------------------- */

    private function staff(string $role, array $permissions): User
    {
        return User::create([
            'business_id' => $this->business->id, 'name' => 'موظف', 'email' => $role.'@eventplus.om',
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
            'permissions' => $permissions,
        ]);
    }
}
