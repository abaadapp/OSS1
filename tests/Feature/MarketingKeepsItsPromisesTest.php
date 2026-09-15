<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * أدوات التسويق تفي بما تعرضه على الشاشة.
 *
 * وهذا القسم أخطر ما يُخلف الوعد فيه: مقبضٌ لا يُدير شيئًا في المخزون يُكتشف
 * عند الجرد، ومقبضٌ لا يُدير شيئًا هنا يظنّه التاجر يعمل شهورًا — يكتب نصّ
 * رسالةٍ ويعاينها ويحفظها، ولا تصل الزبونَ منها كلمة.
 *
 * والمال يخرج من هذا الباب لا البضاعة: نقطةٌ تُصرف مرّتين، وكوبونٌ محدودٌ
 * بمرّةٍ يُستهلك مرّتين، ونسبةُ اكتسابٍ تعيد للزبون أكثر ممّا دفع. ولا يظهر
 * نقصُ شيءٍ من ذلك في جرد.
 */
class MarketingKeepsItsPromisesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'زبون', 'phone' => '90000000', 'points' => 100000,
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف',
            'price' => 100, 'cost' => 40, 'quantity' => 1000, 'alert_qty' => 1, 'active' => true,
        ]);

        $this->set('vat_enabled', '0');
        $this->set('loyalty_enabled', '1');
        $this->set('loyalty_redeem_max_pct', '50');
        $this->set('loyalty_redeem_min', '100');
    }

    private function set(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => $key], ['value' => $value]);
    }
    /* ==================== التقييمات ==================== */

    public function test_the_review_search_is_not_blind_in_production(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Admin/Marketing/ReviewController.php'));

        $this->assertStringNotContainsString(
            "'like'",
            $src,
            'البحث بـlike أعمى على PostgreSQL: اسمٌ بحروفٍ لاتينية لا يُوجد إلا بحالة أحرفه',
        );
        $this->assertStringContainsString('Search::like()', $src);
    }
}
