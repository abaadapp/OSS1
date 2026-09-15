<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الكاشير لا يجمع حصيلة اليوم من شاشة الفواتير.
 *
 * الشاشة كانت تُرسل آخر ثلاثين فاتورة بمبالغها إلى كل من يفتحها، والبحث
 * فيها يتجاوزها إلى تاريخ الفرع كلّه — فعمودٌ واحد يُجمع يكشف الحصيلة وكم
 * باع كل زميل. والحجب في الواجهة وحدها لا يحجب: الأرقام تبقى في الاستجابة.
 * لذلك تفحص هذه الاختبارات الحمولة لا الشاشة.
 */
class ReceiptVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'صاحب النشاط', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'أحمد', 'email' => 'c@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-0001',
            'customer_name' => 'خالد', 'employee_name' => 'سالم', 'status' => 'مكتمل',
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع', 'is_held' => false,
            'subtotal' => 100, 'discount' => 0, 'tax' => 5, 'total' => 105,
            'ordered_at' => now(),
        ]);
    }
    /* --------------------------- القائمة --------------------------- */

    public function test_the_owner_still_sees_the_amounts(): void
    {
        $row = $this->listFor($this->owner)[0];

        $this->assertSame(105.0, $row['total']);
        $this->assertArrayHasKey('subtotal', $row);
    }

    public function test_the_amounts_never_reach_the_cashiers_browser(): void
    {
        $row = $this->listFor($this->cashier)[0];

        foreach (['total', 'subtotal', 'discount', 'tax', 'delivery_fee', 'lines'] as $field) {
            $this->assertArrayNotHasKey($field, $row, "المبلغ {$field} وصل إلى الكاشير");
        }
    }

    /** ما يحتاجه للإرجاع يبقى: رقم الفاتورة والزبون والوقت */
    public function test_what_he_needs_for_a_refund_is_still_there(): void
    {
        $row = $this->listFor($this->cashier)[0];

        $this->assertSame('INV-0001', $row['number']);
        $this->assertSame('خالد', $row['customer']);
        $this->assertArrayHasKey('time', $row);
    }
}
