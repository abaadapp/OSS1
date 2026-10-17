<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\EmployeeAccess;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * شاشةُ الموظفين تقول ما يفعله المحرّك — لا أكثر ولا أقلّ.
 *
 * المسمّى الوظيفيّ اسمٌ يحمل ملفَّ صلاحياتٍ أساسيًّا (`users.role`) يُختار صراحةً،
 * والصلاحياتُ إمّا «تتبع الوظيفة» (`permissions = null`) وإمّا قائمةٌ صريحة هي كلُّ
 * ما يملكه صاحبها. ولا يمنح أحدٌ ما لا يملك، ولا يمسّ من فوقه، ولا يرفع نفسه،
 * ولا يرى الرواتبَ من لا يملك قراءتها — والشركاتُ لا يمسّ بعضُها بعضًا.
 */
class TheEmployeeScreenSaysWhatTheEngineDoesTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);

        foreach ([['مدير', 'admin'], ['مدير فرع', 'manager'], ['محاسب', 'accountant'],
            ['مشرف مخزن', 'inventory'], ['مسؤول مبيعات', 'sales'], ['مندوب توصيل', 'delivery']] as [$name, $role]) {
            JobTitle::create(['business_id' => $this->shop->id, 'name' => $name, 'role' => $role]);
        }

        $this->owner = $this->person('المالك', 'owner', 'admin', 'مدير');
        $this->accountant = $this->person('المحاسب', 'accountant', 'accountant', 'محاسب', ['basic_salary' => 300]);
    }

    /** @param array<string, mixed> $extra */
    private function person(string $name, string $login, string $role, ?string $title, array $extra = [], ?Business $in = null): User
    {
        return User::create([
            'business_id' => ($in ?? $this->shop)->id, 'name' => $name, 'email' => $login.'@eventplusapp.om',
            'password' => bcrypt('password12345'), 'role' => $role, 'job_title' => $title, 'status' => 'نشط',
        ] + $extra);
    }

    /** @param array<string, mixed> $over */
    private function newEmployee(array $over = []): array
    {
        return array_merge([
            'name' => 'موظف جديد', 'login_username' => 'newone', 'job_title' => 'مسؤول مبيعات',
            'password' => 'secret123',
        ], $over);
    }

    /** @param array<string, mixed> $over */
    private function save(User $actor, User $employee, array $over = [])
    {
        return $this->actingAs($actor)->put(route('admin.employees.update', $employee->id), array_merge([
            'name' => $employee->name, 'job_title' => $employee->job_title,
        ], $over));
    }

    private function created(): User
    {
        return User::where('email', 'newone@eventplusapp.om')->firstOrFail();
    }

    /* ═══════════════ ١–٤ · المسمّى الوظيفيّ وملفُّه ═══════════════ */

    /** ١ · صاحبُ النشاط ليس ملفَّ وظيفة */
    public function test_the_owner_is_not_a_job_title_profile(): void
    {
        $this->assertNotContains('admin', Roles::STAFF);
        $this->assertSame(Roles::STAFF, array_keys(JobTitle::roles()));

        $this->actingAs($this->owner)->post(route('admin.jobTitles.store'), ['name' => 'شريك', 'role' => 'admin'])
            ->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('job_titles', ['name' => 'شريك']);
    }

    /** ٢ · وملفّاتُ الموظّفين الخمسة كما هي — والشاشةُ تعرضها وحدها */
    public function test_the_staff_profiles_are_the_five_that_exist(): void
    {
        $this->assertSame(['manager', 'accountant', 'inventory', 'sales', 'delivery'], Roles::STAFF);

        $options = $this->actingAs($this->owner)->get(route('admin.employees.index'))->viewData('page')['props']['roleOptions'];
        $this->assertSame(Roles::STAFF, array_column($options, 'value'));
    }

    /** ٣ · ولا تُنشأ وظيفةٌ بلا ملفّها — ولا تسقط على «مبيعات» بصمت */
    public function test_a_job_title_is_not_created_without_its_profile(): void
    {
        $this->actingAs($this->owner)->post(route('admin.jobTitles.store'), ['name' => 'مشرف الفعالية'])
            ->assertSessionHasErrors(['role' => 'اختر ملف الصلاحيات الأساسي لهذه الوظيفة.']);

        $this->assertDatabaseMissing('job_titles', ['name' => 'مشرف الفعالية']);
    }

    /** ٤ · والوظيفةُ تحمل الملفَّ الذي اختير — ومن لا يملكه لا يُنشئه */
    public function test_a_job_title_carries_the_profile_that_was_chosen(): void
    {
        $this->actingAs($this->owner)->post(route('admin.jobTitles.store'), ['name' => 'مشرف الفعالية', 'role' => 'inventory'])
            ->assertSessionHasNoErrors();
        $this->assertSame('inventory', JobTitle::where('name', 'مشرف الفعالية')->value('role'));

        // المحاسبُ لا يملك ما يحمله ملفُّ المبيعات — فلا يكتب وظيفةً به
        $this->actingAs($this->accountant)->post(route('admin.jobTitles.store'), ['name' => 'بائع ميداني', 'role' => 'sales'])
            ->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('job_titles', ['name' => 'بائع ميداني']);
    }

    /** ولا يتغيّر ملفُّ وظيفةٍ يحملها موظّفون — والفارغةُ تتغيّر */
    public function test_the_profile_of_a_title_people_hold_does_not_change_under_them(): void
    {
        $held = JobTitle::where('name', 'مسؤول مبيعات')->first();
        $this->person('سارة', 'sara', 'sales', 'مسؤول مبيعات');

        $this->actingAs($this->owner)->put(route('admin.jobTitles.update', $held->id), ['name' => 'مسؤول مبيعات', 'role' => 'manager'])
            ->assertSessionHasErrors('role');
        $this->assertSame('sales', $held->fresh()->role);

        $empty = JobTitle::where('name', 'مندوب توصيل')->first();
        $this->actingAs($this->owner)->put(route('admin.jobTitles.update', $empty->id), ['name' => 'مندوب توصيل', 'role' => 'inventory'])
            ->assertSessionHasNoErrors();
        $this->assertSame('inventory', $empty->fresh()->role);

        // ووظيفةُ صاحب النشاط تُعدَّل باسمها ولا يُرسل ملفُّها — فيبقى كما هو
        $owner = JobTitle::where('name', 'مدير')->first();
        $this->actingAs($this->owner)->put(route('admin.jobTitles.update', $owner->id), ['name' => 'المالك'])->assertSessionHasNoErrors();
        $this->assertSame('admin', $owner->fresh()->role);
    }

    /* ═══════════════ ٥–٩ · «صلاحيات الوظيفة» و«تخصيص» ═══════════════ */

    /** ٥ · موظّفٌ جديدٌ على صلاحيات وظيفته: العمودُ `null` */
    public function test_a_new_employee_on_his_job_titles_permissions_is_null(): void
    {
        $this->actingAs($this->owner)->post(route('admin.employees.store'), $this->newEmployee(['manual_permissions' => false]))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->created()->permissions);
        $this->assertSame('sales', $this->created()->role);
    }

    /** ٦ · ويملك ما يحمله ملفُّه بالضبط — قسمًا قسمًا وفعلًا فعلًا */
    public function test_an_inherited_employee_gets_exactly_what_his_profile_grants(): void
    {
        $this->actingAs($this->owner)->post(route('admin.employees.store'), $this->newEmployee(['job_title' => 'مشرف مخزن']));
        $employee = $this->created();

        $has = [
            ...array_values(array_filter(Permissions::SECTIONS, fn ($s) => $employee->allows($s))),
            ...array_values(array_filter(Permissions::actions(), fn ($a) => $employee->may($a))),
        ];
        $this->assertSame(Permissions::roleGrants('inventory'), $has);

        // والمعاينةُ في الشاشة من المصدر نفسِه
        $profiles = $this->get(route('admin.employees.create'))->viewData('page')['props']['roleProfiles'];
        $this->assertSame(Permissions::roleGrants('inventory'), $profiles['inventory']['grants']);
    }

    /** ٧ · والمخصَّصُ قائمةٌ صريحة */
    public function test_a_custom_employee_keeps_an_explicit_list(): void
    {
        $this->actingAs($this->owner)->post(route('admin.employees.store'), $this->newEmployee([
            'manual_permissions' => true, 'permissions' => ['dashboard', 'inventory', 'products'],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(['dashboard', 'inventory', 'products'], $this->created()->permissions);
    }

    /** ٨ · ولا تُدمج القائمةُ المخصّصة بملفّ الوظيفة سرًّا */
    public function test_a_custom_list_is_not_secretly_merged_with_the_profile(): void
    {
        // كانت `orders` — خرجت من الكتالوج فيردّها التحقّق؛ و«العملاء» قسمٌ في ملفّ البائع مثلها
        $this->actingAs($this->owner)->post(route('admin.employees.store'), $this->newEmployee([
            'manual_permissions' => true, 'permissions' => ['dashboard', 'customers'],
        ]));
        $employee = $this->created();

        $this->assertSame('sales', $employee->role);
        $this->assertTrue(Permissions::allowsAction('sales', Permissions::QUOTATION_CREATE));
        $this->assertFalse($employee->may(Permissions::QUOTATION_CREATE), 'دُمج فعلٌ من الملفّ في قائمةٍ مخصّصة');
        $this->assertFalse($employee->allows('quotations'));
    }

    /** ٩ · والرجوعُ إلى صلاحيات الوظيفة يعيد العمودَ `null` — بلا قائمةٍ تُرسل معه */
    public function test_returning_to_the_job_title_sets_permissions_back_to_null(): void
    {
        $employee = $this->person('سارة', 'sara', 'sales', 'مسؤول مبيعات', ['permissions' => ['dashboard', 'customers']]);

        $this->save($this->owner, $employee, ['manual_permissions' => false])->assertSessionHasNoErrors();

        $this->assertNull($employee->fresh()->permissions);
        $this->assertTrue($employee->fresh()->may(Permissions::QUOTATION_CREATE));
    }

    /** وحفظُ موظّفٍ يتبع وظيفته لا يحوّله «مخصّصًا» */
    public function test_saving_an_inherited_employee_keeps_him_inherited(): void
    {
        $employee = $this->person('سارة', 'sara', 'accountant', 'محاسب');

        $this->save($this->owner, $employee, ['phone' => '91234567', 'manual_permissions' => false])->assertSessionHasNoErrors();

        $this->assertNull($employee->fresh()->permissions);
        $this->assertTrue($employee->fresh()->may(Permissions::PAYROLL_VIEW), 'خسر فعلًا من أفعال ملفّه بحفظ هاتفه');
    }

    /* ═══════════════ ١٠–١٦ · الإسنادُ والمنحُ والنفس ═══════════════ */

    /** ١٠ · تغييرُ المسمّى يغيّر الملفَّ */
    public function test_changing_the_job_title_changes_the_profile(): void
    {
        $employee = $this->person('سارة', 'sara', 'sales', 'مسؤول مبيعات');

        $this->save($this->owner, $employee, ['job_title' => 'مشرف مخزن'])->assertSessionHasNoErrors();

        $this->assertSame(['مشرف مخزن', 'inventory'], [$employee->fresh()->job_title, $employee->fresh()->role]);
    }

    /** ١١ · ولا يُسند أحدٌ مسمًّى يحمل فوق ما يملك */
    public function test_no_one_assigns_a_title_above_himself(): void
    {
        $employee = $this->person('سائق', 'driver', 'delivery', 'مندوب توصيل');

        $this->save($this->accountant, $employee, ['job_title' => 'مدير فرع'])->assertForbidden();

        $this->assertSame('delivery', $employee->fresh()->role);
    }

    /** ١٢ · ولا يمنح أحدٌ صلاحيةً لا يملكها */
    public function test_no_one_grants_a_permission_he_does_not_have(): void
    {
        $this->actingAs($this->accountant)->post(route('admin.employees.store'), $this->newEmployee([
            'job_title' => 'مندوب توصيل', 'manual_permissions' => true, 'permissions' => ['dashboard', 'products'],
        ]))->assertForbidden();

        $this->assertNull(User::where('email', 'newone@eventplusapp.om')->first());
    }

    /** ١٣ · ولا يغيّر أحدٌ صلاحياتِ نفسه — ويحفظ هاتفَه بلا ذلك */
    public function test_an_employee_does_not_change_his_own_permissions(): void
    {
        // ممّا يملكه — فلا يردّه حارسُ المنح قبل حارس النفس
        $this->save($this->accountant, $this->accountant, ['manual_permissions' => true, 'permissions' => ['dashboard', 'finance']])
            ->assertSessionHasErrors('permissions');
        $this->assertNull($this->accountant->fresh()->permissions);

        // والنموذجُ لا يرسل علمَ الصلاحيات لمن يعدّل نفسه — فيُحفظ هاتفُه
        $this->save($this->accountant, $this->accountant, ['phone' => '99887766'])->assertSessionHasNoErrors();
        $this->assertSame('99887766', $this->accountant->fresh()->phone);
    }

    /** ١٤ · ولا يغيّر أحدٌ وظيفتَه بنفسه */
    public function test_an_employee_does_not_raise_his_own_job_title(): void
    {
        // «مندوب توصيل» يُسنده المحاسب لغيره — ولا يُسنده لنفسه
        $this->save($this->accountant, $this->accountant, ['job_title' => 'مندوب توصيل'])
            ->assertSessionHasErrors(['job_title' => 'لا يمكنك تغيير وظيفتك بنفسك.']);
        $this->assertSame('accountant', $this->accountant->fresh()->role);
    }

    /** ١٥ · ولا يرفع أحدٌ راتبَه بنفسه */
    public function test_an_employee_does_not_raise_his_own_salary(): void
    {
        $this->save($this->accountant, $this->accountant, ['basic_salary' => 900])
            ->assertSessionHasErrors(['job_title' => 'لا يمكنك رفع راتبك أو بدلاتك بنفسك.']);
        $this->assertSame(300.0, (float) $this->accountant->fresh()->basic_salary);
    }

    /** ١٦ · ولا يُمسّ حسابُ من فوقه */
    public function test_no_one_edits_someone_above_him(): void
    {
        $manager = $this->person('المدير', 'manager', 'manager', 'مدير فرع');

        $this->actingAs($this->accountant)->get(route('admin.employees.edit', $manager->id))->assertForbidden();
        $this->save($this->accountant, $manager, ['name' => 'مسروق'])->assertForbidden();
        $this->assertSame('المدير', $manager->fresh()->name);
    }

    /* ═══════════════ ١٧–١٨ · الرواتب ═══════════════ */

    /** ١٧ · حقولُ الراتب لا تبلغ من لا يقرأ الرواتب — ولا تُكتب بيده */
    public function test_salary_fields_are_hidden_and_protected_without_payroll_view(): void
    {
        // يملك ما يحمله ملفُّ التوصيل فيمسّ السائق — ولا يملك «عرض مسيرة الرواتب»
        // (و«المبيعات» خرجت من ملفّ التوصيل ومن الكتالوج — فلا تُذكر هنا)
        $clerk = $this->person('كاتب', 'clerk', 'delivery', 'مندوب توصيل', ['permissions' => ['dashboard', 'employees', 'projects']]);
        $driver = $this->person('سائق', 'driver', 'delivery', 'مندوب توصيل', ['basic_salary' => 250]);

        $edit = $this->actingAs($clerk)->get(route('admin.employees.edit', $driver->id))->viewData('page')['props'];
        $this->assertFalse($edit['may_read_payroll']);
        $this->assertNull($edit['employee']['basic_salary']);
        $this->assertNull($this->get(route('admin.employees.show', $driver->id))->viewData('page')['props']['salary']);

        $this->save($clerk, $driver, ['basic_salary' => 9999])->assertSessionHasNoErrors();
        $this->assertSame(250.0, (float) $driver->fresh()->basic_salary);

        // ومن يقرأ الرواتب يراه في البطاقة
        $this->assertSame(250.0, $this->actingAs($this->owner)->get(route('admin.employees.show', $driver->id))->viewData('page')['props']['salary']['basic']);
    }

    /** ١٨ · واعتمادُ الرواتب وصرفُها لا يأتيان من قسم الموظفين وحده */
    public function test_payroll_approve_and_pay_do_not_come_from_the_employees_section(): void
    {
        $clerk = $this->person('كاتب', 'clerk', 'delivery', 'مندوب توصيل', ['permissions' => ['dashboard', 'employees']]);

        $this->assertTrue($clerk->allows('employees'));
        foreach ([Permissions::PAYROLL_VIEW, Permissions::PAYROLL_APPROVE, Permissions::PAYROLL_PAY] as $action) {
            $this->assertFalse($clerk->may($action), $action);
        }
        $this->actingAs($clerk)->get(route('admin.payroll.index'))->assertForbidden();
        $this->assertSame('الموظفون', Permissions::sectionLabels()['employees']);
    }

    /* ═══════════════ ١٩–٢١ · العزل ═══════════════ */

    private function neighbour(): array
    {
        $other = Business::create(['name' => 'شركة الجار', 'type' => 'عام', 'status' => 'نشط']);
        $branch = Branch::create(['business_id' => $other->id, 'name' => 'فرع الجار']);
        JobTitle::create(['business_id' => $other->id, 'name' => 'مدير عمليات الجار', 'role' => 'manager']);
        $theirs = $this->person('موظف الجار', 'theirs', 'sales', 'مدير عمليات الجار', [], $other);

        return [$other, $branch, $theirs];
    }

    /** ١٩ · شركةٌ لا تعدّل موظّفَ شركةٍ أخرى */
    public function test_a_company_does_not_edit_another_companys_employee(): void
    {
        [, , $theirs] = $this->neighbour();

        $this->save($this->owner, $theirs, ['name' => 'مسروق', 'job_title' => 'محاسب'])->assertNotFound();
        $this->actingAs($this->owner)->post(route('admin.employees.toggle', $theirs->id))->assertNotFound();
        $this->assertSame('موظف الجار', $theirs->fresh()->name);
    }

    /** ٢٠ · ولا تستعمل مسمّى شركةٍ أخرى */
    public function test_a_company_does_not_use_another_companys_job_title(): void
    {
        $this->neighbour();

        $this->actingAs($this->owner)->post(route('admin.employees.store'), $this->newEmployee(['job_title' => 'مدير عمليات الجار']))
            ->assertSessionHasErrors('job_title');
        $this->assertNull(User::where('email', 'newone@eventplusapp.om')->first());
    }

    /** ٢١ · ولا تربط فرعَ شركةٍ أخرى */
    public function test_a_company_does_not_link_another_companys_branch(): void
    {
        [, $theirBranch] = $this->neighbour();
        $mine = Branch::where('business_id', $this->shop->id)->first();

        $this->actingAs($this->owner)->post(route('admin.employees.store'), $this->newEmployee(['branches' => [$theirBranch->id, $mine->id]]))
            ->assertSessionHasNoErrors();

        $this->assertSame([$mine->id], $this->created()->branches()->pluck('branches.id')->all());
    }

    /* ═══════════════ ٢٢–٢٤ · الحالُ وصاحبُ النشاط ═══════════════ */

    /** ٢٢ · تعطيلُ الموظّف يعمل */
    public function test_disabling_an_employee_works(): void
    {
        $employee = $this->person('سارة', 'sara', 'sales', 'مسؤول مبيعات');

        $this->actingAs($this->owner)->post(route('admin.employees.toggle', $employee->id));

        $this->assertSame('معطل', $employee->fresh()->status);
    }

    /** ٢٣ · ولا يعطّل أحدٌ نفسَه */
    public function test_no_one_disables_himself(): void
    {
        $this->actingAs($this->accountant)->post(route('admin.employees.toggle', $this->accountant->id));
        $this->assertSame('نشط', $this->accountant->fresh()->status);

        $this->save($this->accountant, $this->accountant, ['status' => false])->assertSessionHasErrors('status');
        $this->assertSame('نشط', $this->accountant->fresh()->status);
    }

    /** ٢٤ · وصاحبُ النشاط يُعرض موسومًا ولا يُعدّ بين الموظفين */
    public function test_the_owner_is_marked_and_not_counted_among_employees(): void
    {
        $this->person('سارة', 'sara', 'sales', 'مسؤول مبيعات');

        $rows = collect($this->actingAs($this->owner)->get(route('admin.employees.index'))->viewData('page')['props']['employees']);

        $this->assertTrue($rows->firstWhere('id', $this->owner->id)['is_owner']);
        $this->assertSame(2, $rows->where('is_owner', false)->count(), 'المحاسب وسارة — بلا المالك');
        $this->assertFalse($this->owner->isEmployee());
    }

    /* ═══════════════ الكتالوج والبطاقة ═══════════════ */

    /** كلُّ صلاحيةٍ في موضعٍ واحدٍ من الشاشة، ولكلّ فعلٍ قسمُه — فلا يُضاف مفتاحٌ يُنسى */
    public function test_every_permission_has_exactly_one_place_on_the_screen(): void
    {
        $keys = collect(EmployeeAccess::catalog())->flatMap(fn ($g) => array_column($g['items'], 'key'));

        $this->assertSame($keys->count(), $keys->unique()->count(), 'مفتاحٌ في موضعين');
        $this->assertEqualsCanonicalizing([...Permissions::SECTIONS, ...Permissions::actions()], $keys->all());

        foreach (Permissions::actions() as $action) {
            $this->assertNotEmpty(EmployeeAccess::PARENTS[$action] ?? [], 'فعلٌ بلا قسم: '.$action);
            $this->assertEmpty(array_diff(EmployeeAccess::PARENTS[$action], Permissions::SECTIONS), $action);
        }
        // والحسّاسُ وحده — لا يقع وسط الأقسام
        foreach (array_keys(EmployeeAccess::SENSITIVE) as $action) {
            foreach (EmployeeAccess::GROUPS as $group) {
                $this->assertNotContains($action, $group['actions'], $action);
            }
        }
    }

    /** وبطاقةُ الموظّف تعرض ما يفتحه فعلًا — بالحارس نفسِه — ووضعَه */
    public function test_the_card_shows_what_the_guard_allows_and_the_mode(): void
    {
        // كانت `orders` قسمَ إلغاء الفاتورة — خرجت مع «المبيعات»، وقسمُ الفعل الآن «المالية»
        $employee = $this->person('سارة', 'sara', 'sales', 'مسؤول مبيعات', ['permissions' => ['dashboard', 'finance', Permissions::CUSTOMER_INVOICE_CANCEL]]);

        $access = $this->actingAs($this->owner)->get(route('admin.employees.show', $employee->id))->viewData('page')['props']['access'];

        $this->assertSame(['custom', 'sales'], [$access['mode'], $access['role']]);
        $this->assertSame(['sections' => 2, 'actions' => 1, 'sensitive' => 1], $access['summary']);
        $shown = collect($access['groups'])->flatMap(fn ($g) => array_column($g['items'], 'key'))->sort()->values()->all();
        $this->assertSame(['customerInvoice.cancel', 'dashboard', 'finance'], $shown);
        $this->assertSame([Permissions::CUSTOMER_INVOICE_CANCEL], array_column(collect($access['groups'])->firstWhere('key', 'sensitive')['items'], 'key'));

        $row = collect($this->get(route('admin.employees.index'))->viewData('page')['props']['employees'])->firstWhere('id', $employee->id);
        $this->assertSame(['custom', 2, 1], [$row['permissions_mode'], $row['permissions_summary']['sections'], $row['permissions_summary']['sensitive']]);
    }
}
