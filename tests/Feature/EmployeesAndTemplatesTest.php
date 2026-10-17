<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\GoodsReceiptNote;
use App\Models\GoodsReceiptNoteItem;
use App\Models\JobTitle;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Support\DocumentPaper;
use App\Support\DocumentRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * تعديل الموظف، وتعديل الوظائف، وقوالب الأوراق.
 *
 * وأخطر ما هنا أن الموظفين مرتبطون بالوظيفة **بالاسم لا بالمعرّف**: تغيير
 * اسم وظيفة يترك حامليها معلَّقين على وظيفة لا وجود لها، وتغيير صلاحيتها
 * لا يصلهم فيبقى «موظّف مبيعات» بصلاحيات مدير.
 */
class EmployeesAndTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function title(string $name = 'موظف مبيعات', string $role = 'sales'): JobTitle
    {
        return JobTitle::create(['business_id' => $this->business->id, 'name' => $name, 'role' => $role]);
    }

    private function employee(string $jobTitle = 'موظف مبيعات', string $role = 'sales'): User
    {
        return User::create([
            'business_id' => $this->business->id, 'name' => 'أحمد', 'email' => 'a@eventplus.om',
            'password' => bcrypt('password'), 'role' => $role, 'job_title' => $jobTitle,
            'branch' => 'الفرع الرئيسي', 'status' => 'نشط',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function updatePayload(User $e, array $overrides = []): array
    {
        return array_merge([
            'name' => $e->name,
            'email' => $e->email,
            'job_title' => $e->job_title,
            'branch' => $e->branch,
        ], $overrides);
    }

    /* ======================= تعديل الموظف ======================= */

    public function test_the_edit_form_carries_the_fields_it_shows(): void
    {
        $e = $this->employee();
        $e->update(['monthly_target' => 500]);

        $props = $this->actingAs($this->owner)->get(route('admin.employees.edit', $e->id))
            ->assertOk()->viewData('page')['props']['employee'];

        $this->assertSame('نشط', $props['status']);
        $this->assertSame('500', (string) (int) $props['monthly_target']);
        $this->assertArrayHasKey('avatar', $props);
        // الرمز رُفع من النظام كلّه، فلا يصل حقلُه أصلًا
        $this->assertArrayNotHasKey('pin', $props);
    }

    public function test_a_new_password_typed_on_the_edit_form_actually_changes_it(): void
    {
        $this->title();
        $e = $this->employee();

        $this->actingAs($this->owner)->put(
            route('admin.employees.update', $e->id),
            $this->updatePayload($e, ['password' => 'newpass123']),
        )->assertRedirect();

        $this->assertTrue(
            Hash::check('newpass123', $e->fresh()->password),
            'حقلٌ معروض والتحقق لا يقبله — تُبتلع كلمة المرور بصمت',
        );
    }

    public function test_leaving_the_password_blank_keeps_the_old_one(): void
    {
        $this->title();
        $e = $this->employee();

        $this->actingAs($this->owner)->put(
            route('admin.employees.update', $e->id),
            $this->updatePayload($e, ['password' => '', 'name' => 'أحمد المعدَّل']),
        );

        $this->assertTrue(Hash::check('password', $e->fresh()->password), 'مُسحت كلمة المرور بحفظٍ عاديّ');
        $this->assertSame('أحمد المعدَّل', $e->fresh()->name);
    }

    public function test_the_status_toggle_disables_and_enables(): void
    {
        $this->title();
        $e = $this->employee();

        $this->actingAs($this->owner)->put(route('admin.employees.update', $e->id), $this->updatePayload($e, ['status' => false]));
        $this->assertSame('معطل', $e->fresh()->status);

        $this->actingAs($this->owner)->put(route('admin.employees.update', $e->id), $this->updatePayload($e, ['status' => true]));
        $this->assertSame('نشط', $e->fresh()->status);
    }

    public function test_an_admin_cannot_lock_themselves_out(): void
    {
        $this->title('مدير', 'manager');
        $this->owner->update(['job_title' => 'مدير']);

        $this->actingAs($this->owner)->put(
            route('admin.employees.update', $this->owner->id),
            $this->updatePayload($this->owner, ['status' => false]),
        )->assertSessionHasErrors('status');

        $this->assertSame('نشط', $this->owner->fresh()->status);
    }

    public function test_the_target_is_saved(): void
    {
        $this->title();
        $e = $this->employee();

        $this->actingAs($this->owner)->put(route('admin.employees.update', $e->id), $this->updatePayload($e, [
            'monthly_target' => '1200',
        ]));

        $this->assertSame(1200.0, (float) $e->fresh()->monthly_target);
    }

    /* ============ نسبةُ عمولةٍ لا تُصرف — رُفع الحقل ============ */

    /**
     * ═══ مقبضٌ لا يُدير شيئًا ═══
     *
     * «نسبة العمولة %» كانت حقلًا في ملفّ الموظّف تحت لافتة «الهدف والعمولة»:
     * يُدخله التاجر، ويُحفظ في `users.commission_rate`، ولا يُصرف منه شيء —
     * لا مسيرةَ رواتبَ تقرؤه، ولا كشفَ عمولةٍ في النظام أصلًا. ويُقرأ في
     * موضعٍ واحدٍ فقط: حارسٌ يمنع الموظّف من رفع نسبته بنفسه — يحرس رقمًا لا
     * يُصرف منه شيء.
     *
     * وحقلٌ كهذا أسوأ من غيابه: صاحبُه يظنّ أنّ الأمر مضبوطٌ فلا يسأل عنه،
     * ويكتشف عند أوّل مسيرةٍ أنّ ما كتبه لم يبلغ أحدًا.
     *
     * والعمودُ يبقى بما فيه: رفعُ المقبض لا يمحو ما أدخله التاجر.
     */
    public function test_the_commission_field_no_longer_reaches_the_screen(): void
    {
        $e = $this->employee();

        $props = $this->actingAs($this->owner)->get(route('admin.employees.edit', $e->id))
            ->assertOk()->viewData('page')['props']['employee'];

        $this->assertArrayNotHasKey('commission_rate', $props);
        // والهدفُ باقٍ — رُفعت العمولة وحدها
        $this->assertArrayHasKey('monthly_target', $props);
    }

    public function test_the_column_itself_is_gone(): void
    {
        /*
         * ورُفع المقبضُ أوّلًا ثمّ حُذف العمود بكلمةٍ صريحة من صاحب النظام —
         * انظر مهاجرة `a_column_nothing_writes_is_dropped`. وذِكرُه هنا كي
         * لا يعود إسنادًا في شفرةٍ جديدة.
         */
        $this->assertFalse(Schema::hasColumn('users', 'commission_rate'));
    }

    public function test_a_commission_posted_by_hand_is_not_written(): void
    {
        /*
         * والحارس في الخادم لا في الشاشة: رفعُ الحقل من النموذج لا يمنع
         * أحدًا من إرسال المفتاح في الطلب.
         *
         * وبعد حذف العمود صار الحارسُ ألزمَ لا أهون: لو عاد المفتاح إلى
         * قائمة التحقّق لَبلغ `update()` ولَسقط الحفظُ كلُّه على عمودٍ لا
         * وجود له — فيُردّ التاجرُ عن تعديلٍ لا صلة له بالعمولة.
         */
        $this->title();
        $e = $this->employee();

        $this->actingAs($this->owner)->put(
            route('admin.employees.update', $e->id),
            $this->updatePayload($e, ['commission_rate' => '99', 'name' => 'أحمد المعدَّل']),
        )->assertSessionHasNoErrors();

        // والتعديلُ نفسه وقع: المفتاحُ الغريب أُسقط ولم يُسقِط ما معه
        $this->assertSame('أحمد المعدَّل', $e->fresh()->name);
    }

    public function test_no_live_field_carries_the_commission_on_the_employee_form(): void
    {
        /*
         * وعلى شكل الشفرة لا على النصّ: تعليقي في الملفّ يذكر «نسبة العمولة»
         * شرحًا لِما رُفع — وحارسٌ يقرأ كلمةً كتبتُها بنفسي يحرس نفسه.
         */
        $form = file_get_contents(resource_path('js/Pages/Admin/Employees/partials/EmployeeForm.tsx'));

        $this->assertStringNotContainsString('form.data.commission_rate', $form);
        $this->assertStringNotContainsString("setData('commission_rate'", $form);
        $this->assertStringNotContainsString('form.errors.commission_rate', $form);
    }

    /* ======================= تعديل الوظيفة ======================= */

    public function test_renaming_a_job_title_carries_its_holders_with_it(): void
    {
        $title = $this->title('موظف مبيعات');
        $e = $this->employee('موظف مبيعات');

        $this->actingAs($this->owner)->put(route('admin.jobTitles.update', $title->id), [
            'name' => 'أمين صندوق', 'role' => 'sales',
        ])->assertRedirect();

        $this->assertSame('أمين صندوق', $e->fresh()->job_title, 'بقي الموظف على وظيفة لا وجود لها');
    }

    /**
     * الوظيفة مسمًّى لا صلاحية.
     *
     * كانت تحمل «صلاحية مكافئة» تُفرَض على كل حاملٍ لها، فتعديل اسمها يقلب
     * صلاحيات موظفين لم يُقصدوا. وصار لكل موظف صلاحياته في شاشته، فلا يمسّ
     * تعديلُ الوظيفة أحدًا سوى اسمها.
     */
    public function test_renaming_a_title_does_not_touch_its_holders_permissions(): void
    {
        $title = $this->title('كاشير', 'cashier');
        $e = $this->employee('كاشير', 'cashier');
        $e->update(['permissions' => ['inventory']]);

        $this->actingAs($this->owner)->put(route('admin.jobTitles.update', $title->id), [
            'name' => 'أمين صندوق',
        ])->assertRedirect();

        $e = $e->fresh();
        $this->assertSame('أمين صندوق', $e->job_title);
        $this->assertSame('cashier', $e->role, 'تبدّل الدور بلا طلب');
        $this->assertTrue($e->allows('inventory'), 'ضاعت صلاحية مُنحت يدويًا');
    }

    /**
     * وتُضاف الوظيفة باسمها وملفّ صلاحياتها الأساسيّ — لا باسمها وحده.
     *
     * كانت تُضاف بالاسم وحده فتسقط في الخادم على «مبيعات» بصمت: من أضاف
     * «مشرف الصالة» أسند لحاملها عروضَ الأسعار والعملاء وهو لا يعلم.
     */
    public function test_a_title_is_created_with_its_name_and_its_profile(): void
    {
        $this->actingAs($this->owner)->post(route('admin.jobTitles.store'), [
            'name' => 'مشرف الصالة',
        ])->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('job_titles', ['name' => 'مشرف الصالة']);

        $this->actingAs($this->owner)->post(route('admin.jobTitles.store'), [
            'name' => 'مشرف الصالة', 'role' => 'delivery',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('job_titles', ['name' => 'مشرف الصالة', 'role' => 'delivery']);
    }

    public function test_a_renamed_title_is_still_accepted_when_saving_the_employee(): void
    {
        $title = $this->title('موظف مبيعات');
        $e = $this->employee('موظف مبيعات');

        $this->actingAs($this->owner)->put(route('admin.jobTitles.update', $title->id), [
            'name' => 'أمين صندوق', 'role' => 'sales',
        ]);

        // كان هذا يُرفض بـ«الوظيفة المحددة غير موجودة»
        $this->actingAs($this->owner)->put(route('admin.employees.update', $e->id), [
            'name' => 'أحمد', 'email' => 'a@eventplus.om', 'job_title' => 'أمين صندوق', 'branch' => 'الفرع الرئيسي',
        ])->assertSessionHasNoErrors();
    }

    public function test_two_job_titles_cannot_share_a_name(): void
    {
        $this->title('كاشير');
        $other = $this->title('مندوب', 'delivery');

        $this->actingAs($this->owner)->put(route('admin.jobTitles.update', $other->id), [
            'name' => 'كاشير', 'role' => 'delivery',
        ])->assertSessionHasErrors('name');
    }

    public function test_a_title_keeps_its_own_name_on_save(): void
    {
        $title = $this->title('كاشير');

        $this->actingAs($this->owner)->put(route('admin.jobTitles.update', $title->id), [
            'name' => 'كاشير', 'role' => 'manager',
        ])->assertSessionHasNoErrors();
    }

    public function test_an_unknown_permission_is_refused(): void
    {
        $title = $this->title();

        $this->actingAs($this->owner)->put(route('admin.jobTitles.update', $title->id), [
            'name' => 'كاشير', 'role' => 'god',
        ])->assertSessionHasErrors('role');
    }

    public function test_a_title_of_another_business_is_out_of_reach(): void
    {
        $other = Business::create(['name' => 'جارنا', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = JobTitle::create(['business_id' => $other->id, 'name' => 'وظيفة الجار', 'role' => 'sales']);

        $this->actingAs($this->owner)->put(route('admin.jobTitles.update', $theirs->id), [
            'name' => 'مسروقة', 'role' => 'manager',
        ])->assertNotFound();

        $this->assertSame('وظيفة الجار', $theirs->fresh()->name);
    }

    /* ======================= قوالب الأوراق ======================= */

    /*
     * وكانت تُفحص على الإيصال الحراريّ — خرج مع «المبيعات».
     *
     * والقاعدةُ نفسُها باقيةٌ لكلّ ورقة: ما يُحفظ من المحرّر يبلغ الورقةَ
     * المرسومة لا القاعدةَ وحدها. فتُفحص على سند الاستلام: فيه اسمُ موظّفٍ
     * يُخفى، وترويسةٌ وتذييلٌ يُكتبان، وخطٌّ يكبر — كما كان الإيصال.
     */
    private function grn(): GoodsReceiptNote
    {
        $branch = Branch::where('business_id', $this->business->id)->firstOrFail();
        $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مشتل الوادي']);

        $grn = GoodsReceiptNote::create([
            'business_id' => $this->business->id, 'branch_id' => $branch->id,
            'supplier_id' => $supplier->id, 'number' => 'GRN-1',
            'received_at' => now()->toDateString(), 'receiver' => 'أحمد',
        ]);
        GoodsReceiptNoteItem::create([
            'goods_receipt_note_id' => $grn->id, 'name' => 'باقة ورد',
            'quantity' => 1, 'cost' => 10,
        ]);

        return $grn;
    }

    private function tpl(array $pairs): void
    {
        foreach ($pairs as $k => $v) {
            Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => $k], ['value' => $v]);
        }
    }

    public function test_the_templates_settings_are_saved(): void
    {
        /*
         * والحفظ من محرّر الورقة لا من نموذج الإعدادات العامّ — وبمفاتيح
         * السجلّ (`tpl_grn_header`) لا بأسماء الحقول.
         *
         * وكانت على ورقة البيع بمفاتيحها المسطّحة (`tpl_header`) — خرجت مع
         * «المبيعات»؛ والقاعدةُ نفسُها على سند الاستلام.
         */
        $this->actingAs($this->owner)->post(route('admin.settings.templates.update', 'grn'), [
            'header' => 'أجمل الورود',
            'footer' => "شكرًا\nنراكم قريبًا",
            'font' => 'كبير',
            'show_employee' => '0',
        ])->assertRedirect();

        $saved = Setting::where('business_id', $this->business->id)->pluck('value', 'key');
        $this->assertSame('أجمل الورود', $saved['tpl_grn_header']);
        $this->assertSame("شكرًا\nنراكم قريبًا", $saved['tpl_grn_footer']);
        $this->assertSame('كبير', $saved['tpl_grn_font']);
        $this->assertSame('0', $saved['tpl_grn_show_employee']);
    }

    public function test_hiding_a_field_removes_it_from_the_rendered_paper(): void
    {
        $grn = $this->grn();

        $shown = $this->renderPaper($grn);
        $this->assertStringContainsString('أحمد', $shown, 'اسم الموظف غائب أصلًا — راجع الاختبار');

        $this->tpl(['tpl_grn_show_employee' => '0']);
        $this->assertStringNotContainsString('أحمد', $this->renderPaper($grn), 'بقي اسم الموظف رغم إخفائه');
    }

    public function test_the_footer_text_reaches_the_paper(): void
    {
        $grn = $this->grn();
        $this->tpl(['tpl_grn_footer' => "وداعًا\nنراكم قريبًا"]);

        $html = $this->renderPaper($grn);

        $this->assertStringContainsString('وداعًا', $html);
        $this->assertStringContainsString('نراكم قريبًا', $html, 'ضاع السطر الثاني من التذييل');
    }

    public function test_the_header_line_appears_only_when_written(): void
    {
        $grn = $this->grn();
        $this->assertStringNotContainsString('أجمل الورود', $this->renderPaper($grn));

        $this->tpl(['tpl_grn_header' => 'أجمل الورود']);
        $this->assertStringContainsString('أجمل الورود', $this->renderPaper($grn));
    }

    public function test_the_font_size_setting_changes_the_rendered_size(): void
    {
        $grn = $this->grn();
        $normal = $this->renderPaper($grn);

        $this->tpl(['tpl_grn_font' => 'كبير']);
        $this->assertNotSame($normal, $this->renderPaper($grn), 'حجم الخط لا أثر له');
    }

    /** يرسم الورقة كـHTML (لا PDF) بالقالب المحفوظ — حتى يُفحص محتواها لا حجمها */
    private function renderPaper(GoodsReceiptNote $grn): string
    {
        return DocumentRenderer::generic(
            $this->business->id,
            'grn',
            DocumentPaper::forGrn($grn->fresh(['items', 'supplier', 'branch', 'purchaseOrder'])),
        );
    }
}
