<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * نقاط الولاء تتبع الشخص لا اسمه.
 *
 * كان العميل يُطابَق بالاسم وحده ويُؤخذ أوّل ما يعود من الجدول. والاسم ليس
 * مفتاحًا: متجرٌ فيه ثلاثة باسم «محمد» كان يمنح نقاط شراء كلٍّ منهم لأوّلهم،
 * ويخصم رصيده هو عند استبدال غيره.
 *
 * والنقاط مالٌ فعلي: الخلط فيها خسارةٌ لصاحبها وهبةٌ لسواه — ولا يظهر منه
 * شيء في أي شاشة، لأن كل سجلّ صحيح على حدة.
 */
class LoyaltyIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Branch $branch;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'موظف مبيعات', 'role' => 'sales']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة', 'price' => 10,
            'quantity' => 100, 'active' => true,
        ]);

        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '0']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'loyalty_earn_rate', 'value' => '5']);

        $this->actingAs($this->owner);
        session(['current_branch' => $this->branch->id]);
    }

    private function customer(string $name, string $phone, int $points = 0): Customer
    {
        return Customer::create([
            'business_id' => $this->business->id, 'name' => $name,
            'phone' => $phone, 'points' => $points,
        ]);
    }
    /** والفراغ مسموح: عابرٌ بلا هاتف عميلٌ صحيح */
    public function test_customers_without_a_phone_are_still_allowed(): void
    {
        $this->post(route('admin.customers.store'), ['name' => 'عابر أول'])->assertSessionHasNoErrors();
        $this->post(route('admin.customers.store'), ['name' => 'عابر ثانٍ'])->assertSessionHasNoErrors();

        $this->assertSame(2, Customer::whereNull('phone')->count());
    }

    /** والرقم نفسه في متجرٍ آخر شخصٌ آخر — القيد داخل المتجر لا عبره */
    public function test_the_same_phone_may_exist_in_another_business(): void
    {
        $this->customer('محمد', '9111');
        $theirs = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);

        Customer::create(['business_id' => $theirs->id, 'name' => 'زبونهم', 'phone' => '9111']);

        $this->assertSame(2, Customer::where('phone', '9111')->count());
    }
}
