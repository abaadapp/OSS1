<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «حسابي وراتبي» صفحةُ موظّف — ومديرُ المنصّة ليس موظّفًا.
 *
 * ═══ ما كان ═══
 *
 * الشرط في شريط اللوحة: «دورُه ليس `admin`» — ومديرُ المنصّة دورُه
 * `super_admin`، فيمرّ. ويملك كلّ قسمٍ بحكم دوره، فتمرّ معه صلاحيةُ «نقطة
 * البيع» أيضًا. فيرى في قائمة حسابه «حسابي وراتبي» ويفتحها.
 *
 * فإن ضغطه قذفه `RequiresBusiness` إلى لوحة المنصّة برسالةٍ عن «لوحة النشاط
 * تخصّ متجرًا بعينه» — لا عن راتب. بابٌ مرسومٌ لا يؤدّي إلى شيء.
 *
 * ═══ والخادمُ كان يردّه أصلًا ═══
 *
 * وقِسنا الأدوار الثلاثة على المسار قبل الإصلاح:
 *
 *   super_admin → ٣٠٢ إلى لوحة المنصّة   ·   admin → ٢٠٠   ·   cashier → ٢٠٠
 *
 * فلا ثغرةَ في الخادم: `RequiresBusiness` تردّ مديرَ المنصّة قبل المتحكّم.
 * العطبُ في الشاشة وحدها — بندٌ يُرسم لمن يُردّ عنه.
 *
 * ═══ ولماذا يبقى البابُ مفتوحًا لصاحب النشاط ═══
 *
 * البندُ يُخفى عنه — ملفُّه في الغالب ليس ملفَّ موظّف — ولا يُحرَس المسار
 * عنه: `payableEmployees` لا ترشّح بالدور، وشاشةُ الموظفين تستثني
 * `super_admin` وحده. فصاحبُ نشاطٍ سجّل لنفسه راتبًا له سطرٌ في المسيرة
 * فعلًا، وحجبُ الصفحة عنه يُخفي عنه مسيرتَه هو.
 *
 * ومديرُ الفرع موظّفٌ يُستثنى من الاستثناء: له راتبٌ ومسيرة.
 */
class MySalaryIsForWhoHasOneTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'ورود مسقط', 'type' => 'عام', 'status' => 'نشط']);
    }

    private function person(string $role, ?int $businessId): User
    {
        return User::create([
            'business_id' => $businessId, 'name' => $role,
            'email' => $role.'-'.($businessId ?? 'x').'@eventplus.om',
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
        ]);
    }

    private function operator(): User
    {
        return $this->person('super_admin', null);
    }

    private function owner(): User
    {
        return $this->person('admin', $this->shop->id);
    }

    private function clerk(string $role = 'cashier'): User
    {
        return $this->person($role, $this->shop->id);
    }
    public function test_every_shop_role_that_is_not_the_owner_may_open_it(): void
    {
        foreach (['cashier', 'sales', 'inventory', 'accountant', 'delivery'] as $role) {
            $this->assertTrue($this->person($role, $this->shop->id)->isEmployee(), $role);
        }
    }

    /* ═══════════ والتعريفُ واحد ═══════════ */

    public function test_who_is_an_employee_is_asked_in_one_place(): void
    {
        $this->assertFalse($this->operator()->isEmployee(), 'مدير المنصّة');
        $this->assertFalse($this->owner()->isEmployee(), 'صاحب النشاط');
        $this->assertTrue($this->clerk()->isEmployee(), 'الكاشير');
        $this->assertTrue($this->clerk('manager')->isEmployee(), 'مدير الفرع');

        // ودورُ متجرٍ بلا متجر ليس موظّفًا: تردّه `RequiresBusiness` أصلًا
        $this->assertFalse($this->person('cashier', null)->isEmployee(), 'بلا نشاط مربوط');
    }
    public function test_the_screen_asks_the_server_not_the_role(): void
    {
        /*
         * وعلى شكل الشفرة: تعليقي في الملفّ يذكر `admin` شرحًا لِما تغيّر،
         * فالقياس على الشرط نفسه لا على ورود الكلمة.
         */
        $topbar = file_get_contents(resource_path('js/Components/Topbar.tsx'));

        $this->assertStringContainsString("{auth?.isEmployee && auth?.abilities.includes('pos') && (", $topbar);
        $this->assertStringNotContainsString("{auth?.user.role !== 'admin' && auth?.abilities.includes('pos')", $topbar);
    }
}
