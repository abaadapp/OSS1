<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * لوحة النشاط ونقطة البيع لا تُفتحان لمن لا متجر له.
 *
 * مدير المنصة كان يمرّ: CheckRole يستثنيه من كل فحص، و/pos لا تفحص الدور.
 * فتنهار الصفحة (context غير مُرسَل) — أو الأسوأ، تصمد وتعرض بيانات متجر
 * آخر لأن Demo::bid() ترجع أول نشاط لمن لا يملك واحدًا.
 */
class RequiresBusinessTest extends TestCase
{
    use RefreshDatabase;

    private Business $mine;

    private Business $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $this->theirs = Business::create(['name' => 'متجر آخر', 'status' => 'نشط']);

    }

    private function superAdmin(): User
    {
        return User::create([
            'business_id' => null, 'name' => 'مدير المنصة',
            'email' => 'super@eventplus.om', 'password' => bcrypt('password'),
            'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }
    #[DataProvider('merchantRoutes')]
    #[DataProvider('merchantRoutes')]
    public function test_a_real_merchant_user_passes_through_untouched(string $name): void
    {
        $owner = User::create([
            'business_id' => $this->mine->id, 'name' => 'المالك',
            'email' => 'owner@eventplus.om', 'password' => bcrypt('password'),
            'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($owner)->get(route($name))->assertOk();
    }
}
