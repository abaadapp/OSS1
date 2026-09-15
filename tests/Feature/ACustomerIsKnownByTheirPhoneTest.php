<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * العميل يُعرَف برقمه لا باسمه.
 *
 * كان يُطابَق بالاسم وحده ويُؤخذ أوّل ما يعود من الجدول. والاسم ليس مفتاحًا:
 * شركةٌ لها ثلاثة عملاء باسم «محمد» كانت تنسب تاريخ شراء كلٍّ منهم لأوّلهم،
 * ولا يظهر منه شيء في أيّ شاشة لأنّ كلّ سجلّ صحيح على حدة.
 *
 * والقيدُ داخل الشركة لا عبرها: الرقمُ نفسُه في شركةٍ أخرى شخصٌ آخر.
 */
class ACustomerIsKnownByTheirPhoneTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'Event Plus', 'type' => 'شركة تقنية', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($owner);
    }

    /** والفراغ مسموح: جهةٌ بلا هاتف عميلٌ صحيح */
    public function test_customers_without_a_phone_are_still_allowed(): void
    {
        $this->post(route('admin.customers.store'), ['name' => 'عابر أول'])->assertSessionHasNoErrors();
        $this->post(route('admin.customers.store'), ['name' => 'عابر ثانٍ'])->assertSessionHasNoErrors();

        $this->assertSame(2, Customer::whereNull('phone')->count());
    }

    /** والرقم نفسه في شركةٍ أخرى شخصٌ آخر — القيد داخل الشركة لا عبرها */
    public function test_the_same_phone_may_exist_in_another_business(): void
    {
        Customer::create(['business_id' => $this->business->id, 'name' => 'محمد', 'phone' => '9111']);
        $theirs = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);

        Customer::create(['business_id' => $theirs->id, 'name' => 'عميلهم', 'phone' => '9111']);

        $this->assertSame(2, Customer::where('phone', '9111')->count());
    }
}
