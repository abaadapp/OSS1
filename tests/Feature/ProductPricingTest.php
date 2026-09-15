<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مقبضان في نموذج المنتج لم يكن يقرؤهما أحد.
 *
 * «الضريبة» و«الخصم (%)» يُملآن ويُحفظان، والبيع يقرأ السعر وحده: الضريبة
 * تُحسب من إعداد المتجر، والخصم يأتي من الكوبون والنقاط فقط. فمن وضع خصم
 * ٢٠٪ على صنفٍ ثم باعه بكامل سعره لا يعلم — والمقبض غير الموصول أسوأ من
 * غيابه لأنه يطمئن.
 *
 * وأخطر ما في التوصيل أن يصير الصفر إعلانًا بالإعفاء: الأصناف القائمة كلّها
 * تبدأ من صفر، فتخرج فواتير المتاجر بلا ضريبة بين ليلةٍ وضحاها. فالفراغ
 * «اتبع المتجر»، والصفر قرارٌ يُكتب باليد.
 */
class ProductPricingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->actingAs($this->owner);
        }

    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;
    private function product(array $over = []): Product
    {
        return Product::create(array_merge([
            'business_id' => $this->business->id, 'name' => 'صنف',
            'price' => 100, 'cost' => 60, 'quantity' => 50, 'alert_qty' => 5, 'active' => true,
        ], $over));
    }
    public function test_a_discount_over_a_hundred_is_refused(): void
    {
        // خصمٌ ٥٠٠ على سعر ١٠ يجعل سطر الفاتورة سالبًا — وكان يُقبل ويُحفظ
        $this->post(route('admin.products.store'), [
            'name' => 'خصم كبير', 'price' => 10, 'discount' => 500,
        ])->assertSessionHasErrors('discount');

        $this->post(route('admin.products.store'), [
            'name' => 'ضريبة كبيرة', 'price' => 10, 'tax' => 900,
        ])->assertSessionHasErrors('tax');
    }

    public function test_a_stored_over_discount_still_cannot_go_negative(): void
    {
        // ما حُفظ قبل القيد يُقصّ عند البيع لا يُصدَّق
        $p = $this->product(['price' => 10]);
        Product::whereKey($p->id)->update(['discount' => 500]);

        $this->assertSame(0.0, $p->fresh()->sellingPrice());
    }
}
