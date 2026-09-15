<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الإلغاء يردّ ما أخذه البيع.
 *
 * كان يقلب الحالة وحدها. والتقارير تستثني الملغى (`Order::scopeSold`) —
 * فيبدو الأمر سليمًا في الشاشة، والخلل تحتها في أربعة مواضع:
 *
 *   - المخزون: خمسُ ورداتٍ خرجت من الدفتر ولم تخرج من المحلّ، فيقول
 *     الجرد إنّها نقصت ولا أحد يعرف أين ذهبت.
 *   - النقاط: يكسبها العميل على طلبٍ لم يقع، ويستبدلها بضاعةً تقع.
 *   - الكوبون: «مرّة واحدة» يُحرَق على طلبٍ أُلغي — ولا باب لتعديل
 *     الكوبونات أصلًا، فلا سبيل إلى ردّه.
 *   - المالية: قيدُ دخلٍ يبقى على بيعةٍ لم تكن.
 *
 * وبيعةُ المنضدة لا يبلغها هذا: تُغلق «مكتمل» ولا يجوز نقلها إلى «ملغي»
 * أصلًا — تُصحَّح من بابها حيث يُطلب السبب.
 */
class CancelReleasesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private User $cashier;
    private Product $product;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل', 'email' => 'c@t.local', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك', 'email' => 'o@c.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'k@c.local',
            'password' => bcrypt('secret'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة',
            'price' => 10, 'cost' => 4, 'quantity' => 100, 'active' => true,
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'خالد', 'phone' => '91234567', 'points' => 0,
        ]);

    }
    public function test_what_left_the_shelf_comes_back_to_it(): void
    {
        $order = $this->sellAndCancel();

        $this->assertSame('ملغي', $order->status);
        $this->assertSame(100, (int) $this->product->fresh()->quantity, 'المخزون لم يُعَد بعد الإلغاء');
        $this->assertSame(100, (int) \App\Models\BranchStock::where('product_id', $this->product->id)->sum('quantity'));
    }

    public function test_points_earned_on_an_order_that_never_happened_are_taken_back(): void
    {
        $this->sellAndCancel();

        $this->assertSame(0, (int) $this->customer->fresh()->points, 'النقاط بقيت بعد الإلغاء');
        $this->assertSame(0, (int) Order::latest('id')->firstOrFail()->points_earned);
    }
    public function test_a_single_use_coupon_is_given_back(): void
    {
        $coupon = Coupon::create([
            'business_id' => $this->business->id, 'code' => 'ONCE', 'type' => 'مبلغ',
            'value' => 2, 'min_order' => 0, 'max_uses' => 1, 'used_count' => 0, 'active' => true,
        ]);

        $this->sellAndCancel(['coupon_code' => 'ONCE']);

        $this->assertSame(0, (int) $coupon->fresh()->used_count, 'استعمال الكوبون لم يُحرَّر');
    }

    public function test_no_income_stays_on_a_sale_that_did_not_happen(): void
    {
        $order = $this->sellAndCancel();

        $this->assertSame(0, Transaction::where('order_id', $order->id)->count(),
            'معاملة الدخل بقيت بعد الإلغاء');
    }

    /**
     * ولا يُردّ المخزون مرّتين.
     *
     * نقلٌ ثانٍ إلى «ملغي» — من زرٍّ ضُغط مرّتين أو من لوحةٍ أخرى — كان
     * سيُضيف خمسًا لا وجود لها.
     */
    public function test_cancelling_twice_returns_the_stock_once(): void
    {
        $order = $this->sellAndCancel();

        $this->actingAs($this->owner)
            ->post(route('admin.orders.status', $order->number), ['status' => 'ملغي']);

        $this->assertSame(100, (int) $this->product->fresh()->quantity);
        $this->assertSame(1, \App\Models\OrderEdit::where('order_id', $order->id)->count());
    }
}
