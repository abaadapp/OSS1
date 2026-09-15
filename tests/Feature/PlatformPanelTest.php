<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * عمليات الكتابة في لوحة المنصة.
 *
 * الصفحات كانت مغطّاة بالكنس، أما ما يكتب في القاعدة — إنشاء شركة، تعديل
 * باقة، إيقاف حساب — فلم يكن مغطّى بشيء. وهذه بالذات لا يكفي فيها أن
 * تُرجع الشاشة تنبيه نجاح.
 */
class PlatformPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private Plan $plan;

    private \App\Models\Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'name' => 'الباقة الأساسية', 'monthly_price' => 10, 'yearly_price' => 100,
            'max_branches' => 1, 'max_employees' => 3, 'max_products' => 100,
        ]);
        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'super@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
        $this->business = \App\Models\Business::create(['name' => 'متجر الفحص', 'status' => 'نشط']);
    }
    /* ------------------------------ الحراسة ------------------------------ */

    public static function writeRoutes(): array
    {
        return [
            ['post', 'super-admin.businesses.store'],
            ['post', 'super-admin.users.store'],
            ['post', 'super-admin.settings.update'],
            ['post', 'super-admin.plans.store'],
        ];
    }

    #[DataProvider('writeRoutes')]
    public function test_a_merchant_owner_cannot_write_through_the_platform_panel(string $verb, string $name): void
    {
        $biz = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $owner = User::create([
            'business_id' => $biz->id, 'name' => 'المالك', 'email' => 'owner@eventplus.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($owner)->{$verb}(route($name), [])->assertForbidden();
    }

    #[DataProvider('writeRoutes')]
    public function test_a_guest_cannot_write_either(string $verb, string $name): void
    {
        $this->{$verb}(route($name), [])->assertRedirect(route('login'));
    }

    /* ------------------------------ الشعار ------------------------------ */

    private function business(array $extra = []): Business
    {
        $b = Business::create(array_merge([
            'name' => 'شركة', 'type' => 'مغسلة', 'status' => 'نشط',
        ], $extra));

        // ومعها حسابها: تعديل شركةٍ بلا حساب يطالب باستكماله أولًا
        \App\Support\MerchantAccount::create($b, 'sharika'.$b->id, 'secret12345');

        return $b;
    }
    /**
     * «حذف الشعار» يمسحه من القاعدة لا من الشاشة وحدها.
     *
     * إخفاء المعاينة دون علامةٍ تصل مع الطلب كان يترك الملف القديم مربوطًا:
     * يحفظ المستخدم، ويعود الشعار عند فتح الصفحة — حذفٌ ظاهرٌ لم يحدث.
     */
    public function test_removing_the_logo_actually_clears_it(): void
    {
        $b = $this->business(['logo' => 'logos/old.png']);

        $this->edit($b, ['remove_logo' => '1'])->assertSessionHasNoErrors();

        // العمود الخام: getLogoAttribute يُرجع رابطًا بديلًا حين يكون فارغًا
        $this->assertNull($b->fresh()->getRawOriginal('logo'));
    }

    /**
     * وتعديلُ حقلٍ آخر لا يمسّ الشعار.
     *
     * حقل الملف غائب عن الطلب حين لا يُختار ملف — فتمريره كما هو كان سيمسح
     * الشعار عند كل حفظٍ لاسمٍ أو هاتف.
     */
    public function test_editing_another_field_keeps_the_logo(): void
    {
        $b = $this->business(['logo' => 'logos/keep.png']);

        $this->edit($b, ['name' => 'اسم جديد'])->assertSessionHasNoErrors();

        $this->assertSame('logos/keep.png', $b->fresh()->getRawOriginal('logo'));
        $this->assertSame('اسم جديد', $b->fresh()->name);
    }
    /**
     * الشركة تُنشأ ومعها حسابُ دخول مالكها.
     *
     * كانت تُسجَّل بلا حساب: سجلٌّ في جدول لا يفتحه أحد — لا التاجر يدخل، ولا
     * الدعم يستطيع «الدخول كتاجر» لأنه لا يجد من ينتحله. ولا يظهر العطب إلا
     * حين يتصل صاحبها يسأل عن كلمة مروره.
     */
    public function test_creating_a_business_creates_its_owner_account(): void
    {
        $this->newBusiness()->assertSessionHasNoErrors();

        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();
        $owner = \App\Support\MerchantAccount::owner($biz);

        $this->assertNotNull($owner, 'أُنشئت الشركة بلا حساب');
        $this->assertSame('zahra@eventplusapp.om', $owner->email);
        $this->assertSame('admin', $owner->role);
    }

    /** والنطاق ثابت: يُكتب الاسم وحده ويُلحق به */
    public function test_the_domain_is_appended_not_typed(): void
    {
        $this->newBusiness(['login_username' => 'AlNoor']);

        $this->assertDatabaseHas('users', ['email' => 'alnoor@eventplusapp.om']);
    }

    /** وبها يدخل التاجر فعلًا — لا حسابًا يُكتب ولا يُفتح */
    public function test_the_owner_can_actually_sign_in(): void
    {
        $this->newBusiness();
        $this->post(route('logout'));

        $this->post(route('login.attempt'), [
            'email' => 'zahra@eventplusapp.om', 'password' => 'secret12345',
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticated();
        $this->assertSame('زهرة مسقط', auth()->user()->business->name);
    }

    /** ويدخل لوحته لا صفحة خطأ */
    public function test_the_owner_lands_on_their_panel(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->actingAs(\App\Support\MerchantAccount::owner($biz))
            ->get(route('admin.dashboard'))->assertOk();
    }
    /** واسم المستخدم لا يتكرّر — والفحص على البريد الكامل لا على الاسم */
    public function test_a_duplicate_username_is_refused(): void
    {
        $this->newBusiness();

        $this->newBusiness(['name' => 'زهرة ثانية'])->assertSessionHasErrors('login_username');

        $this->assertSame(1, User::where('email', 'zahra@eventplusapp.om')->count());
    }

    /** والعربية والمسافات مرفوضة: البريد يُملى في الهاتف ويُكتب على ورقة */
    public function test_an_arabic_or_spaced_username_is_refused(): void
    {
        $this->newBusiness(['login_username' => 'زهرة'])->assertSessionHasErrors('login_username');
        $this->newBusiness(['login_username' => 'al noor'])->assertSessionHasErrors('login_username');
    }

    /** وكلمة مرور قصيرة مرفوضة، والمحفوظة مجزَّأة لا نصًّا */
    public function test_the_password_is_checked_and_hashed(): void
    {
        $this->newBusiness(['login_password' => '123'])->assertSessionHasErrors('login_password');

        $this->newBusiness();
        $stored = User::where('email', 'zahra@eventplusapp.om')->value('password');
        $this->assertNotSame('secret12345', $stored);
        $this->assertTrue(password_verify('secret12345', $stored));
    }
    /* --------- الشركات القديمة: حسابٌ يُستكمل من صفحة التعديل --------- */

    /** شركة سُجّلت قبل إلزام الحساب */
    private function accountlessBusiness(): Business
    {
        return Business::create(['name' => 'قديمة', 'type' => 'عام', 'status' => 'نشط']);
    }
    /**
     * التعديل يُنشئ الحساب الغائب.
     *
     * كانت الصفحة تعرض «—» بلا حقلٍ ولا زرّ: شركةٌ لا يفتحها أحد ولا سبيل إلى
     * إصلاحها من اللوحة — إلا بفتح قاعدة البيانات يدويًّا.
     */
    public function test_editing_creates_a_missing_owner_account(): void
    {
        $biz = $this->accountlessBusiness();

        $this->updateBusiness($biz, ['login_username' => 'qadeema', 'login_password' => 'secret12345'])
            ->assertSessionHasNoErrors();

        $owner = \App\Support\MerchantAccount::owner($biz);
        $this->assertNotNull($owner, 'بقيت الشركة بلا حساب بعد التعديل');
        $this->assertSame('qadeema@eventplusapp.om', $owner->email);
    }

    /** وبه يدخل صاحبها فعلًا */
    public function test_the_completed_account_can_sign_in(): void
    {
        $biz = $this->accountlessBusiness();
        $this->updateBusiness($biz, ['login_username' => 'qadeema', 'login_password' => 'secret12345']);
        $this->post(route('logout'));

        $this->post(route('login.attempt'), [
            'email' => 'qadeema@eventplusapp.om', 'password' => 'secret12345',
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticated();
    }

    /** ولا يُحفظ التعديل بحسابٍ ناقص — وإلا بقيت مقفلة وظنّ المشغّل أنه أصلحها */
    public function test_editing_an_accountless_business_requires_credentials(): void
    {
        $biz = $this->accountlessBusiness();

        $this->updateBusiness($biz, ['name' => 'محاولة'])
            ->assertSessionHasErrors(['login_username', 'login_password']);
    }

    /** والشركة التي لها حساب لا تُطالَب بشيء */
    public function test_editing_a_business_that_has_an_account_needs_nothing(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->updateBusiness($biz, ['name' => 'زهرة مسقط الجديدة'])->assertSessionHasNoErrors();

        $this->assertSame('زهرة مسقط الجديدة', $biz->fresh()->name);
        $this->assertSame(1, User::where('business_id', $biz->id)->count());
    }

    /**
     * وكلمة المرور تُبدَّل من هنا.
     *
     * «نسيت كلمة المرور» محذوفة، وصفحة الموظفين لا يفتحها من لا يدخل أصلًا —
     * فمن نسي كلمته كان لا مخرج له.
     */
    public function test_a_new_password_replaces_the_old_one(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->updateBusiness($biz, ['login_password' => 'newsecret999'])->assertSessionHasNoErrors();
        $this->post(route('logout'));

        $this->post(route('login.attempt'), ['email' => 'zahra@eventplusapp.om', 'password' => 'secret12345'])
            ->assertSessionHasErrors();
        $this->post(route('login.attempt'), ['email' => 'zahra@eventplusapp.om', 'password' => 'newsecret999'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    /** والفارغ يعني «لا تغيّرها»: تصحيحُ مدينةٍ لا يُخرج التاجر من حسابه */
    public function test_an_empty_password_field_leaves_the_password_alone(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();
        $before = User::where('email', 'zahra@eventplusapp.om')->value('password');

        $this->updateBusiness($biz, ['city' => 'صحار', 'login_password' => '']);

        $this->assertSame($before, User::where('email', 'zahra@eventplusapp.om')->value('password'));
    }

    /** والقصيرة مرفوضة هنا أيضًا */
    public function test_a_short_new_password_is_refused(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->updateBusiness($biz, ['login_password' => '123'])->assertSessionHasErrors('login_password');
    }

    /* ------------------ تعديل بريد الدخول ------------------ */

    /**
     * البريد قابل للتعديل.
     *
     * اسمٌ يُكتب خطأً عند التسجيل كان يبقى إلى الأبد: الحقل يُعرض ولا يُكتب،
     * فلا سبيل إلى تصحيحه إلا بحسابٍ ثانٍ يُترك الأول بجانبه.
     */
    public function test_the_login_email_can_be_changed(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->updateBusiness($biz, ['login_username' => 'zahra.muscat'])->assertSessionHasNoErrors();

        $this->assertSame('zahra.muscat@eventplusapp.om', \App\Support\MerchantAccount::owner($biz)->email);
        // لا حساب ثانٍ: البريد بُدّل ولم يُضف
        $this->assertSame(1, User::where('business_id', $biz->id)->count());
    }

    /** وبالجديد يدخل، وبالقديم لا */
    public function test_the_old_email_stops_working_after_the_change(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();
        $this->updateBusiness($biz, ['login_username' => 'zahra.muscat']);
        $this->post(route('logout'));

        $this->post(route('login.attempt'), ['email' => 'zahra@eventplusapp.om', 'password' => 'secret12345'])
            ->assertSessionHasErrors();

        $this->post(route('login.attempt'), ['email' => 'zahra.muscat@eventplusapp.om', 'password' => 'secret12345'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    /** والبريد المحجوز لتاجرٍ آخر مرفوض — وإلا صار لاثنين بريدُ دخولٍ واحد */
    public function test_taking_another_merchants_email_is_refused(): void
    {
        $this->newBusiness();
        $this->newBusiness(['name' => 'ورود صحار', 'login_username' => 'sohar']);
        $biz = Business::where('name', 'ورود صحار')->firstOrFail();

        $this->updateBusiness($biz, ['login_username' => 'zahra'])->assertSessionHasErrors('login_username');

        $this->assertSame('sohar@eventplusapp.om', \App\Support\MerchantAccount::owner($biz)->email);
    }

    /** وحفظُ الاسم نفسه لا يُعدّ اصطدامًا بالنفس */
    public function test_saving_the_same_username_is_not_a_conflict(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->updateBusiness($biz, ['login_username' => 'zahra', 'city' => 'مسقط'])
            ->assertSessionHasNoErrors();

        $this->assertSame('zahra@eventplusapp.om', \App\Support\MerchantAccount::owner($biz)->email);
    }

    /** والعربية مرفوضة في التعديل كما في الإنشاء */
    public function test_an_invalid_username_is_refused_on_edit(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->updateBusiness($biz, ['login_username' => 'زهرة'])->assertSessionHasErrors('login_username');

        $this->assertSame('zahra@eventplusapp.om', \App\Support\MerchantAccount::owner($biz)->email);
    }
    /**
     * الحساب يُعدَّل بمساره وحده.
     *
     * تمريرُه عبر نموذج الشركة كان يعني إعادة كتابة الاسم والنوع والحالة
     * لتغيير كلمة مرورٍ نسيها تاجر — وأيّ حقلٍ يسقط من الطلب يمحو ما في
     * القاعدة.
     */
    public function test_the_account_can_be_changed_from_its_own_route(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->saveAccount($biz, ['login_username' => 'zahra2', 'login_password' => 'newsecret999'])
            ->assertSessionHasNoErrors();

        $this->assertSame('zahra2@eventplusapp.om', \App\Support\MerchantAccount::owner($biz)->email);
        // ولا تُمسّ بيانات الشركة
        $this->assertSame('زهرة مسقط', $biz->fresh()->name);
        $this->assertSame('محل ورود', $biz->fresh()->type);
    }

    /** وبالجديدة يدخل */
    public function test_the_password_set_from_the_business_page_works(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();
        $this->saveAccount($biz, ['login_username' => 'zahra', 'login_password' => 'newsecret999']);
        $this->post(route('logout'));

        $this->post(route('login.attempt'), ['email' => 'zahra@eventplusapp.om', 'password' => 'newsecret999'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    /**
     * وكلمة المرور تُعاد في الرسالة مرّةً واحدة.
     *
     * مخزَّنة مجزَّأة فلا تُقرأ بعدها أبدًا — وبلا عرضها هنا لا سبيل لإبلاغ
     * التاجر بها إلا أن يخترع المشغّل واحدةً أخرى.
     */
    public function test_the_new_password_is_shown_once(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->saveAccount($biz, ['login_username' => 'zahra', 'login_password' => 'newsecret999']);

        $this->assertStringContainsString('newsecret999', (string) json_encode(session('toast')));
    }

    /** ولا تُعرض حين لا تُغيَّر */
    public function test_no_password_is_shown_when_it_is_not_changed(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->saveAccount($biz, ['login_username' => 'zahra.new']);

        $toast = (string) json_encode(session('toast'));
        $this->assertStringNotContainsString('secret', $toast);
    }
    /**
     * وحسابٌ على نطاقٍ قديم يُنقل إلى النطاق الموحَّد.
     *
     * حسابات أُنشئت قبل توحيد النطاق تحمل غيره. وكان الحقل يُملأ بنزع
     * «@eventplusapp.om» فلا يطابق شيئًا — فيصل البريد كاملًا ويُرفض الحفظ لأن @
     * ليست حرفًا مسموحًا. والقطع عند @ يجعله يعمل.
     */
    public function test_a_legacy_domain_account_moves_to_the_unified_domain(): void
    {
        $biz = Business::create(['name' => 'قديمة النطاق', 'type' => 'عام', 'status' => 'نشط']);
        $owner = User::create([
            'business_id' => $biz->id, 'name' => 'مالك', 'email' => 'admin@eventpluspos.com',
            'password' => 'password', 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->saveAccount($biz, ['login_username' => 'admin'])->assertSessionHasNoErrors();

        $this->assertSame('admin@eventplusapp.om', $owner->fresh()->email);
    }

    /** والبريد وكلمة المرور يتغيّران معًا في حفظةٍ واحدة */
    public function test_both_credentials_change_together(): void
    {
        $this->newBusiness();
        $biz = Business::where('name', 'زهرة مسقط')->firstOrFail();

        $this->updateBusiness($biz, ['login_username' => 'zahra.new', 'login_password' => 'newsecret999'])
            ->assertSessionHasNoErrors();
        $this->post(route('logout'));

        $this->post(route('login.attempt'), ['email' => 'zahra.new@eventplusapp.om', 'password' => 'newsecret999'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }
}
