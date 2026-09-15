<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * من يملك أن يفعل ماذا.
 *
 * والحدّ هو الموضع الذي يُختبَر أشدَّ اختبار: هو ما تدفع افنت بلس ثمنه. فلو
 * استطاع تاجرٌ أن يرفعه بنفسه لَما كان حدًّا — وهذا لا يُكتشف بالنظر إلى
 * الشاشة، لأنّ الشاشة لا تعرض له الحقل أصلًا.
 */
class WhatsAppPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $cashier;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);
        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'root@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }
    /** والحدّ الافتراضي إعدادُ منصّةٍ لا يكتبه تاجر */
    public function test_a_business_cannot_write_the_platform_default_limit(): void
    {
        Setting::updateOrCreate(
            ['business_id' => null, 'key' => WhatsAppQuota::DEFAULT_KEY],
            ['value' => '100'],
        );

        $this->actingAs($this->owner)->post(route('admin.settings.update'), [
            WhatsAppQuota::DEFAULT_KEY => 99999,
        ]);

        $this->assertSame(100, WhatsAppQuota::platformDefault());
    }
}
