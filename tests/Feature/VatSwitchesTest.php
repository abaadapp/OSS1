<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مفاتيح الضريبة في الإعدادات — تفعل ما تقول.
 *
 * كان «تفعيل ضريبة القيمة المضافة» يُحفَظ ولا يقرؤه شيء: يُطفئه من لا ضريبة
 * عليه — ومن يبيع دون حدّ التسجيل في عُمان كذلك — فتبقى الضريبة تُضاف إلى
 * كل فاتورة، وتُقرّ في التقرير الضريبي، ويجبيها من زبائنه وهو غير مخوَّلٍ
 * بجبايتها.
 *
 * و«طريقة الاحتساب» كذلك: يختار «مشمولة في السعر» فتُضاف فوقه.
 */
class VatSwitchesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف',
            'price' => 100, 'cost' => 60, 'quantity' => 500, 'active' => true,
        ]);

        $this->set('vat_rate', '5');
        $this->actingAs($this->cashier);
    }

    private function set(string $key, ?string $value): void
    {
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => $key],
            ['value' => $value],
        );
    }
    /** والورقة لا تحمل رقمًا ضريبيًّا لمتجرٍ لا يجبي الضريبة */
    public function test_the_tax_number_disappears_from_the_paper_when_it_is_off(): void
    {
        $this->set('vat_number', 'OM1100234567');
        $this->set('vat_enabled', '0');

        $vat = Demo::vatSettings();

        $this->assertSame(0.0, $vat['rate']);
        $this->assertSame('', $vat['number']);
    }
}
