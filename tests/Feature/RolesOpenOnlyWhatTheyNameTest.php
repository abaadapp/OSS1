<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الدورُ يفتح ما يسمّيه ولا شيء سواه.
 *
 * ما يُحرَس هنا ليس الأزرار بل ما يقوله النظام عن الحساب: أن دورًا مجهولًا
 * لا يفتح شيئًا، وأن صاحب العمل ومدير الفرع يُفرَّق بينهما في التسمية.
 */
class RolesOpenOnlyWhatTheyNameTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'Event Plus', 'status' => 'نشط']);
    }

    private function staff(array $attrs = []): User
    {
        return User::create(array_merge([
            'business_id' => $this->business->id, 'name' => 'موظف',
            'email' => 'staff@eventplusapp.om', 'password' => bcrypt('secret12345'),
            'role' => 'sales', 'status' => 'نشط',
        ], $attrs));
    }

    public function test_an_unknown_role_opens_nothing_rather_than_everything(): void
    {
        // كان `?? ['*']` — فخطأٌ مطبعيّ يصنع حسابًا مفتوحًا على كل قسم
        $this->assertSame([], Permissions::abilities('ghost'));
        $this->assertFalse(Permissions::allows('ghost', 'orders'));
        $this->assertFalse(Permissions::allows('ghost', 'settings'));
    }

    public function test_the_owner_and_the_branch_manager_are_told_apart(): void
    {
        // كانت `roleLabel()` تكتب «مدير» لكليهما، والمرشّح فوقهما يكتب غير ذلك
        $this->assertNotSame(Roles::label('admin'), Roles::label('manager'));

        $owner = $this->staff(['email' => 'owner@eventplusapp.om', 'role' => 'admin']);
        $this->assertSame(Roles::label('admin'), $owner->roleLabel());
    }
}
