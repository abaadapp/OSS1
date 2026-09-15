<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * البيانات الوهميّة لا تدخل حساب تاجر — وهذه هي القاعدة التي طُلبت.
 *
 * العزل بين التجّار قائمٌ بـ`business_id` ويحرسه `TenantIsolationTest`: يمنع
 * تاجرًا أن يقرأ بيانات آخر. وهذا الملفّ يحرس اتّجاهًا آخر لم يكن محروسًا:
 * أن يُكتب الوهميّ في الحقيقيّ.
 *
 * والفرق بينهما ليس نظريًّا: أمرٌ يُشغَّل بمعرّفٍ خاطئ، أو زرٌّ في اللوحة
 * يُضغط والمتجر المفتوح غير المقصود — فتدخل مئةُ فاتورةٍ ملفَّقة دفترَ تاجرٍ
 * يحاسب بها ضريبته.
 */
class DemoIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::create([
            'name' => 'مدير المنصة', 'email' => 'root@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    private function merchant(string $name = 'متجر تاجر'): Business
    {
        return Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط']);
    }

    /* ------------------------------ القاعدة ------------------------------ */

    public function test_a_merchant_business_is_never_a_demo_by_default(): void
    {
        $this->assertFalse($this->merchant()->is_demo);
    }
}
