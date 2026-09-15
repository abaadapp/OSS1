<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * محرّك الموقع — من جوابين إلى موقعٍ يصلح للنشر.
 *
 * وما يُحرَس هنا ثلاثة: أنّ التاجر لا يبدأ من فراغ، وأنّ ما يكتبه لا يخرج
 * إلى موقعٍ عامّ بلا تنظيف، وأنّ تعديله لا يمسّ ما نُشر حتى يَنشر.
 */
class WebsiteEngineTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط',
            'phone' => '96890000000', 'email' => 'shop@eventplus.om',
            'city' => 'مسقط', 'address' => 'الخوير', 'logo' => '/storage/logo.png',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function bid(): int
    {
        return $this->business->id;
    }

    private function setting(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => $this->bid(), 'key' => $key], ['value' => $value]);
    }

    private function catalogue(): void
    {
        $category = Category::create(['business_id' => $this->bid(), 'name' => 'باقات']);
        Product::create([
            'business_id' => $this->bid(), 'category_id' => $category->id,
            'name' => 'باقة ورد', 'price' => 12.5, 'active' => true,
        ]);
    }
    public function test_the_home_page_exists_and_is_not_removable(): void
    {
        $home = $this->build()->homePage();

        $this->assertNotNull($home);
        $this->assertSame('/', $home->slug);
        $this->assertFalse($home->removable, 'موقعٌ تُحذف صفحتُه الأولى نطاقٌ يردّ بلا شيء');
    }
}
