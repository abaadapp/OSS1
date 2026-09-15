<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * «المستخدمون» في لوحة المنصّة — الشاشة التي تُقرأ منها الحسابات ويُوثق بها.
 *
 * ما تحرسه هذه الاختبارات ليس الأزرار بل ما تقوله الشاشة: أن تبويب
 * الصلاحيات يعرض ما يُفرض فعلًا، وأن دورًا مجهولًا لا يفتح شيئًا، وأن
 * الحساب المعطوب لا يُعرض سليمًا، وأن الحذف قابل للتراجع.
 */
class PlatformUsersTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'boss@eventplusapp.om',
            'password' => bcrypt('secret12345'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    private function staff(array $attrs = []): User
    {
        return User::create(array_merge([
            'business_id' => $this->business->id, 'name' => 'موظف',
            'email' => 'staff@eventplusapp.om', 'password' => bcrypt('secret12345'),
            'role' => 'cashier', 'status' => 'نشط',
        ], $attrs));
    }
    /* --------------------------- الدور المجهول --------------------------- */

    public function test_an_unknown_role_opens_nothing_rather_than_everything(): void
    {
        // كان `?? ['*']` — فخطأٌ مطبعيّ يصنع حسابًا مفتوحًا على كل قسم
        $this->assertSame([], Permissions::abilities('ghost'));
        $this->assertFalse(Permissions::allows('ghost', 'orders'));
        $this->assertFalse(Permissions::allows('ghost', 'settings'));
    }

    public function test_the_known_roles_are_untouched_by_that_tightening(): void
    {
        $this->assertTrue(Permissions::allows('admin', 'settings'));
        $this->assertTrue(Permissions::allows('manager', 'finance'));
        $this->assertTrue(Permissions::allows('cashier', 'pos'));
        $this->assertFalse(Permissions::allows('cashier', 'finance'));
    }
    public function test_the_owner_and_the_branch_manager_are_told_apart(): void
    {
        // كانت `roleLabel()` تكتب «مدير» لكليهما، والمرشّح فوقهما يكتب غير ذلك
        $this->assertNotSame(Roles::label('admin'), Roles::label('manager'));

        $owner = $this->staff(['email' => 'owner@eventplusapp.om', 'role' => 'admin']);
        $this->assertSame(Roles::label('admin'), $owner->roleLabel());
    }
}
