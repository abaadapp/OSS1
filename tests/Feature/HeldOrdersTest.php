<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الطلبات المعلّقة والمحفوظة.
 *
 * كانت القائمة تُرشَّح على `employee_name == auth()->user()->name` — صحيحًا
 * يوم كان اسم الموظف هو اسم الحساب المسجَّل. ثم صار البيع يُنسب إلى الكاشير
 * المختار على الصندوق، فصار الطلب يُحفظ باسم «أحمد» ويُبحث عنه باسم «مالك
 * النشاط»: يُخزَّن بنجاح، وتظهر رسالة «تم تعليق الطلب»، ولا يظهر أبدًا.
 */
class HeldOrdersTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $cashier;

    private Branch $branch;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'كاشير', 'role' => 'cashier']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك النشاط', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط', 'job_title' => 'مدير',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'أحمد', 'email' => 'c@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط', 'job_title' => 'كاشير',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة', 'price' => 10,
            'quantity' => 100, 'active' => true,
        ]);

        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '0']);

        $this->actingAs($this->owner);
        session(['current_branch' => $this->branch->id]);
    }
    public function test_a_neighbours_held_order_is_not_mine(): void
    {
        $theirs = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Order::create([
            'business_id' => $theirs->id, 'number' => 'HOLD-JAR', 'customer_name' => 'زبونهم',
            'status' => 'معلّق', 'is_held' => true, 'subtotal' => 5, 'total' => 5, 'ordered_at' => now(),
        ]);

        $this->assertCount(0, Demo::heldOrders());
    }

    /** ولا اسم تجريبي يتسرّب مكان اسمٍ غائب */
    public function test_a_missing_employee_name_does_not_become_a_demo_name(): void
    {
        Order::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'number' => 'HOLD-X', 'customer_name' => 'نقدي', 'employee_name' => null,
            'status' => 'معلّق', 'is_held' => true, 'subtotal' => 5, 'total' => 5, 'ordered_at' => now(),
        ]);

        $this->assertSame(__('غير محدّد'), Demo::heldOrders()[0]['employee']);
    }
}
