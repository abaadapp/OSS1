<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لغة واجهة مدير المنصة.
 *
 * كان المبدّل صفًّا في القائمة التي تُفتح بمرور الماوس تحت الصورة، فحُذف من
 * هناك — تبديل اللغة يقلب اتجاه المستند ويعيد تحميل الصفحة، وهذا ليس ما
 * يُتوقَّع من صفٍّ يمرّ عليه المؤشّر. وموضعه الآن زرٌّ ظاهر في الشريط العلوي،
 * وتبويبٌ في الإعدادات معه: الإعدادات موضعُ ما يُضبط مرّة، واللغة يبدّلها
 * الموظّف كل يوم — ومن لا يقرأ العربية لا يقرأ كلمة «الإعدادات» ليصل إليها.
 *
 * وأهمّ ما يُختبر أنها **تفضيل شخصي**: تُكتب في حساب من بدّلها وحده.
 */
class PlatformLanguageTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = User::create([
            'name' => 'مدير المنصة',
            'email' => 'platform@eventplus.om',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'status' => 'نشط',
        ]);
    }
    /**
     * ولكل لوحةٍ مسارُها.
     *
     * المسار واحدُ الاسم في ثلاث مجموعات — لوحة المنصة ولوحة التاجر ونقطة
     * البيع — ولكلٍّ حارسه. وزرُّ الشريط يختار بحسب من يقف عليه، فلو أرسل
     * إلى مسارٍ واحد لردَّ ٤٠٣ لأحدهما. وهذا يثبت أن البابين مفتوحان.
     */
    public function test_a_merchant_switches_through_their_own_route(): void
    {
        $business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $owner = User::create([
            'business_id' => $business->id, 'name' => 'المالك', 'email' => 'owner@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($owner)
            ->post(route('admin.language.update'), ['locale' => 'en'])
            ->assertRedirect();

        $this->assertSame('en', $owner->fresh()->locale);
    }
}
