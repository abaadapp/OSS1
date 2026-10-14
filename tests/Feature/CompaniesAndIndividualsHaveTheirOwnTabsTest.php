<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * الشركاتُ والجهاتُ في تبويب، والأفرادُ في «العملاء».
 *
 * والعميلُ واحدٌ في القاعدة، يفرّقه نوعُه: ما يُضاف من «شركات وجهات» يبقى
 * فيها، ولا يظهر عميلٌ في التبويبين، ولا يغيب عنهما.
 */
class CompaniesAndIndividualsHaveTheirOwnTabsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $b = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $b->id, 'name' => 'مدير', 'role' => 'admin']);
        $this->owner = User::create(['business_id' => $b->id, 'name' => 'المالك', 'email' => 'o@hadath.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        Customer::create(['business_id' => $b->id, 'name' => 'وزارة الطاقة', 'customer_type' => 'جهة حكومية']);
        Customer::create(['business_id' => $b->id, 'name' => 'بنك مسقط', 'customer_type' => 'شركة']);
        Customer::create(['business_id' => $b->id, 'name' => 'سالم الحارثي', 'customer_type' => 'فرد']);
    }

    private function names(string $route): array
    {
        $names = [];
        $this->actingAs($this->owner)->get(route($route))->assertOk()
            ->assertInertia(function (AssertableInertia $p) use (&$names) {
                $names = collect($p->toArray()['props']['customers'])->pluck('name')->sort()->values()->all();
            });

        return $names;
    }

    public function test_organizations_and_individuals_split_without_overlap_or_gap(): void
    {
        $this->assertSame(['بنك مسقط', 'وزارة الطاقة'], $this->names('admin.customers.organizations'));
        $this->assertSame(['سالم الحارثي'], $this->names('admin.customers.index'));
    }

    public function test_what_is_added_as_a_company_stays_with_the_companies(): void
    {
        $this->actingAs($this->owner)->post(route('admin.customers.store'), [
            'name' => 'أوكيو', 'phone' => '91112222', 'customer_type' => 'شركة',
        ])->assertRedirect(route('admin.customers.organizations'));

        $this->assertSame('شركة', Customer::where('name', 'أوكيو')->value('customer_type'));

        /* وبلا نوعٍ يبقى فردًا — كما كانت الإضافةُ قبل التبويب */
        $this->actingAs($this->owner)->post(route('admin.customers.store'), ['name' => 'هدى', 'phone' => '93334444'])
            ->assertRedirect(route('admin.customers.index'));
        $this->assertSame('فرد', Customer::where('name', 'هدى')->value('customer_type'));

        $this->actingAs($this->owner)->post(route('admin.customers.store'), ['name' => 'x', 'phone' => '95556666', 'customer_type' => 'مجهول'])
            ->assertSessionHasErrors('customer_type');
    }
}
