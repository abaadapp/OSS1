<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الكوبون يجب أن ينجو من التعليق والاستكمال.
 *
 * كان `hold` لا يحفظ الكود و`resume` لا يعيده: الكاشير يطبّق خصم 10%،
 * يعلّق الطلب لينادي الزبون التالي، ثم يستكمله فيدفع الزبون السعر كاملًا.
 * لا خطأ يظهر ولا شيء ينبّه — فرق صامت في كل فاتورة معلّقة.
 *
 * ويُحفظ الكود وحده لا قيمة الخصم: الطلب قد يُستكمل غدًا وقد يكون
 * الكوبون انتهى، فالتحقق يقع وقت الدفع.
 */
class HeldOrderCouponTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $cashier;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $this->cashier = User::create([
            'business_id' => $this->business->id,
            'name' => 'كاشير', 'email' => 'cashier@hold.local',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id,
            'name' => 'صنف', 'price' => 10, 'cost' => 4,
            'quantity' => 50, 'alert_qty' => 5, 'active' => true,
        ]);

        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

    }

    private function coupon(array $attrs = []): Coupon
    {
        return Coupon::create(array_merge([
            'business_id' => $this->business->id,
            'code' => 'SAVE10', 'type' => 'نسبة', 'value' => 10, 'active' => true,
        ], $attrs));
    }
    public function test_holding_an_order_remembers_its_coupon(): void
    {
        $this->coupon();
        $held = $this->hold('SAVE10');

        $this->assertSame('SAVE10', $held->coupon_code);
    }
    public function test_an_order_held_without_a_coupon_stays_without_one(): void
    {
        $held = $this->hold(null);

        $this->assertNull($held->coupon_code);
    }
}
