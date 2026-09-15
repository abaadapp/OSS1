<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * بيعةٌ تمّت فبدايةٌ نظيفة — والزبون معها.
 *
 * كانت السلّة تُفرَغ ويبقى الزبون معلّقًا في الشاشة: يمشي ويقف غيره،
 * فتُقيَّد البيعةُ التالية عليه **وتُضاف نقاطُ ولائه إلى من لم يشترِ**.
 * والنقاط مالٌ يُستبدَل، ولا يُكتشف ذلك إلا حين يسأل صاحبها عن رصيدٍ ناقص.
 *
 * وشاشة العملاء تقول للكاشير رقمَ آخر فاتورة: يُسأل عنها والزبون واقفٌ
 * أمامه، وتاريخٌ بلا رقم لا يفتح شيئًا.
 */
class PosStartsCleanTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة', 'price' => 10,
            'cost' => 4, 'quantity' => 100, 'active' => true,
        ]);

        $this->actingAs($this->cashier);
    }
    private function customer(string $name): Customer
    {
        return Customer::create(['business_id' => $this->business->id, 'name' => $name]);
    }

    /* --------------------- الشاشة تبدأ نظيفة بعد البيع --------------------- */
    public function test_a_customer_who_never_bought_shows_nothing(): void
    {
        $jadid = $this->customer('جديد');

        $rows = collect(Demo::customers())->keyBy('id');

        $this->assertNull($rows[$jadid->id]['last_invoice']);
        $this->assertNull($rows[$jadid->id]['last_invoice_total']);
    }
    /** ولا فاتورةُ جارٍ تصل شاشة أحد */
    public function test_a_neighbours_invoice_never_appears(): void
    {
        $other = Business::create(['name' => 'الجار', 'status' => 'نشط']);
        $theirCustomer = Customer::create(['business_id' => $other->id, 'name' => 'زبونهم']);
        Order::create([
            'business_id' => $other->id, 'customer_id' => $theirCustomer->id,
            'number' => 'JAAR-1', 'customer_name' => 'زبونهم', 'total' => 50,
            'is_held' => false, 'status' => 'مكتمل', 'ordered_at' => now(),
        ]);

        $numbers = collect(Demo::customers())->pluck('last_invoice')->filter()->all();

        $this->assertNotContains('JAAR-1', $numbers);
    }
}
