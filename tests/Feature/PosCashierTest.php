<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\PosCashier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * من تُنسب إليه البيعة.
 *
 * كانت كل بيعة تُكتب باسم `auth()->user()` — فإن فتح صاحب النشاط الصندوق
 * صارت مبيعات اليوم كلها باسمه مهما تناوب عليه الموظفون، وتقارير الأداء
 * بلا معنى. صار الصندوق يسأل «من عليه الآن؟» ويُسجّل باسم من يُختار.
 */
class PosCashierTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $cashier;

    private Product $product;

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
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 10, 'cost' => 4, 'quantity' => 50, 'alert_qty' => 2, 'active' => true,
        ]);

        // البيع صار يتطلّب جهازًا مفعَّلًا — شرط السيناريو لا موضوعه
        $this->activatePosDevice($this->business->id);
    }
    public function test_the_owner_is_not_in_the_list_of_employees(): void
    {
        // بمستخدمٍ صراحةً: Demo::bid() لم تعد تخمّن متجرًا لمن لا متجر له
        $this->actingAs($this->owner);
        $names = collect(PosCashier::selectable())->pluck('name')->all();

        $this->assertContains('أحمد', $names);
        $this->assertNotContains('صاحب النشاط', $names, 'الغرض من الشاشة ألّا تُنسب المبيعات إليه');
    }
}
