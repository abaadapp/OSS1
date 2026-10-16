<?php

namespace Tests\Feature\Documents;

use App\Models\ActivityLog;
use App\Models\AutomationRule;
use App\Models\DocumentCategory;
use App\Models\DocumentRecord;
use App\Models\InternalNotification;
use App\Models\User;
use App\Support\Automation\Engine;
use App\Support\Automation\Rules;
use App\Support\Automation\Settings;
use App\Support\DocumentRecords;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Operations\OperationsTestCase;

/**
 * الشركةُ تحفظ مستنداتها — وتسمع قبل أن تنتهي.
 *
 * المستندُ يُرفع ملفُّه إلى قرصٍ خاصّ ولا يُفتح إلّا من شركته، والحالُ تُحسب من تاريخ
 * الانتهاء، والنسخةُ الجديدة لا تمحو القديمة، والربطُ والتصنيفُ والمسؤولُ من الشركة
 * نفسِها. والقراءةُ بالقسم والكتابةُ بـ`documents.manage`. والتنبيهُ قبل ٩٠ و٦٠ و٣٠
 * و٧ أيّام ويومَ الانتهاء — مرّةً لكلّ خطوة، والمؤرشفُ صامت، والتجديدُ يبدأ من جديد.
 */
class TheCompanyKeepsItsDocumentsAndHearsBeforeTheyExpireTest extends OperationsTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        DocumentRecords::ensureCategories((int) $this->business->id);
    }

    private function category(string $name = 'تأمين', ?int $businessId = null): DocumentCategory
    {
        return DocumentCategory::where('business_id', $businessId ?? $this->business->id)->where('name', $name)->first()
            ?? DocumentCategory::create(['business_id' => $businessId ?? $this->business->id, 'name' => $name]);
    }

    /** مستندٌ بلا طلب — لما يُختبر فيه الحالُ والتنبيه لا الرفع */
    private function document(string $title, ?string $expires, array $over = []): DocumentRecord
    {
        return DocumentRecord::create($over + [
            'business_id' => $this->business->id, 'title' => $title, 'expires_at' => $expires,
            'document_category_id' => $this->category()->id, 'responsible_user_id' => $this->storekeeper->id, 'created_by' => $this->owner->id,
        ]);
    }

    private function pdf(string $name = 'وثيقة.pdf', int $kb = 40): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    public function test_a_document_is_stored_privately_logged_and_opened_only_from_its_company(): void
    {
        $this->actingAs($this->manager)->post(route('admin.documents.store'), [
            'title' => 'وثيقة التأمين الشامل', 'category_id' => $this->category()->id, 'document_number' => 'INS-2026-77',
            'issuer' => 'شركة التأمين', 'issued_at' => '2026-01-01', 'expires_at' => '2026-10-10',
            'link_type' => 'project', 'link_id' => $this->project->id, 'responsible_id' => $this->storekeeper->id,
            'file' => $this->pdf(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $doc = DocumentRecord::sole();
        $this->assertSame((int) $this->business->id, (int) $doc->business_id);
        $this->assertSame('project', $doc->documentable_type, 'مفتاحٌ قصيرٌ لا اسمُ صنف');
        $this->assertStringStartsWith("documents/{$this->business->id}/", $doc->file_path);
        Storage::disk('local')->assertExists($doc->file_path);
        $this->assertSame(1, $doc->files()->count());
        $this->assertTrue(ActivityLog::where('business_id', $this->business->id)->where('subject_type', 'document')
            ->where('description', 'like', 'أضاف المستند «وثيقة التأمين الشامل» (مشروع: EP-2026-001%')->exists());

        $this->actingAs($this->owner)->get(route('admin.documents.file', $doc->id))->assertOk();
        $this->actingAs($this->owner)->get(route('admin.documents.show', $doc->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Admin/Documents/Show')
                ->where('document.status', DocumentRecord::EXPIRING)->where('document.days_left', 20)
                ->where('document.link_label', 'EP-2026-001 — '.$this->project->name)->where('document.responsible', 'أمين المخزن'));

        /* شركةٌ أخرى: لا الصفحةُ ولا الملفُّ ولا نسخُه — ولو عرفت الرقم */
        $this->actingAs($this->stranger)->get(route('admin.documents.show', $doc->id))->assertNotFound();
        $this->actingAs($this->stranger)->get(route('admin.documents.file', $doc->id))->assertNotFound();
        $this->actingAs($this->stranger)->get(route('admin.documents.version', [$doc->id, $doc->files()->first()->id]))->assertNotFound();
        $this->actingAs($this->stranger)->post(route('admin.documents.archive', $doc->id))->assertNotFound();
        $this->actingAs($this->stranger)->get(route('admin.documents.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('documents', [])->where('stats.total', 0));
    }

    public function test_a_new_version_keeps_the_old_file_and_both_stay_readable(): void
    {
        $this->actingAs($this->manager)->post(route('admin.documents.store'), ['title' => 'السجل التجاري', 'expires_at' => '2027-03-01', 'file' => $this->pdf('سجل-2025.pdf')]);
        $doc = DocumentRecord::sole();
        $old = $doc->file_path;

        $this->actingAs($this->manager)->post(route('admin.documents.update', $doc->id), [
            'title' => 'السجل التجاري', 'expires_at' => '2028-03-01', 'file' => $this->pdf('سجل-2026.pdf'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $doc->refresh();
        $this->assertNotSame($old, $doc->file_path);
        $this->assertSame('سجل-2026.pdf', $doc->file_name);
        $this->assertSame('2028-03-01', $doc->expires_at->toDateString());
        Storage::disk('local')->assertExists($old);
        $this->assertSame(['سجل-2026.pdf', 'سجل-2025.pdf'], $doc->files()->pluck('name')->all());
        $this->actingAs($this->owner)->get(route('admin.documents.version', [$doc->id, $doc->files()->get()->last()->id]))->assertOk();
        $this->assertTrue(ActivityLog::where('description', 'like', 'رفع نسخة جديدة من المستند «السجل التجاري»%')->exists());

        /* وتعديلٌ بلا ملفّ لا يمسّ النسخ */
        $this->actingAs($this->manager)->post(route('admin.documents.update', $doc->id), ['title' => 'السجل التجاري — الفرع', 'expires_at' => '2028-03-01'])->assertSessionHasNoErrors();
        $this->assertSame(2, $doc->files()->count());
    }

    public function test_only_allowed_files_and_only_this_companys_links_are_accepted(): void
    {
        $base = ['title' => 'عقد', 'expires_at' => '2027-01-01'];

        $this->actingAs($this->manager)->post(route('admin.documents.store'), $base + ['file' => UploadedFile::fake()->create('x.exe', 10, 'application/octet-stream')])->assertSessionHasErrors('file');
        $this->actingAs($this->manager)->post(route('admin.documents.store'), $base + ['file' => UploadedFile::fake()->create('x.php', 10, 'text/plain')])->assertSessionHasErrors('file');
        $this->actingAs($this->manager)->post(route('admin.documents.store'), $base + ['file' => $this->pdf('كبير.pdf', DocumentRecords::MAX_KB + 1)])->assertSessionHasErrors('file');
        $this->actingAs($this->manager)->post(route('admin.documents.store'), $base)->assertSessionHasErrors('document');

        $theirProject = $this->project('EP-THEIRS', (int) $this->other->id);
        $theirCategory = $this->category('تأمين', (int) $this->other->id);
        $this->actingAs($this->manager)->post(route('admin.documents.store'), $base + ['file' => $this->pdf(), 'link_type' => 'project', 'link_id' => $theirProject->id])->assertSessionHasErrors('document');
        $this->actingAs($this->manager)->post(route('admin.documents.store'), $base + ['file' => $this->pdf(), 'category_id' => $theirCategory->id])->assertSessionHasErrors('document');
        $this->actingAs($this->manager)->post(route('admin.documents.store'), $base + ['file' => $this->pdf(), 'responsible_id' => $this->stranger->id])->assertSessionHasErrors('document');
        $this->actingAs($this->manager)->post(route('admin.documents.store'), $base + ['file' => $this->pdf(), 'link_type' => 'App\\Models\\Project', 'link_id' => $this->project->id])->assertSessionHasErrors('link_type');
        $this->actingAs($this->manager)->post(route('admin.documents.store'), $base + ['file' => $this->pdf(), 'issued_at' => '2027-06-01'])->assertSessionHasErrors('document');

        $this->assertSame(0, DocumentRecord::count());
        $this->assertSame([], Storage::disk('local')->allFiles(), 'ولا يبقى ملفٌّ يتيم من طلبٍ رُفض');
    }

    public function test_the_status_is_read_from_the_date_and_the_list_filters_by_it(): void
    {
        $this->document('نشط', '2027-06-01');
        $this->document('بلا انتهاء', null);
        $this->document('قريب', '2026-10-15');
        $this->document('اليوم', '2026-09-20');
        $this->document('منتهٍ', '2026-09-01');
        $this->document('مؤرشف', '2026-09-01', ['archived_at' => now()]);

        $titles = fn (string $status = '') => $this->actingAs($this->owner)->get(route('admin.documents.index', array_filter(['status' => $status])))
            ->assertOk()->viewData('page')['props']['documents'];

        $this->assertSame(['منتهٍ', 'اليوم', 'قريب', 'نشط', 'بلا انتهاء'], array_column($titles(), 'title'), 'ما ينتهي أوّلًا أوّلًا، والمؤرشفُ خارجًا');
        $this->assertSame(['اليوم', 'قريب'], array_column($titles('expiring'), 'title'));
        $this->assertSame(['منتهٍ'], array_column($titles('expired'), 'title'));
        $this->assertSame(['نشط', 'بلا انتهاء'], array_column($titles('active'), 'title'));
        $this->assertSame(['مؤرشف'], array_column($titles('archived'), 'title'));

        $this->actingAs($this->owner)->get(route('admin.documents.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('stats.total', 5)->where('stats.expiring', 2)->where('stats.expired', 1)
                ->where('stats.archived', 1)->where('stats.needs_action', 3)->where('stats.nearest.title', 'اليوم'));

        /* والحالُ لا تتعفّن: غدًا يصير «اليوم» منتهيًا بلا كتابة */
        Carbon::setTestNow('2026-09-21 10:00:00');
        $this->assertSame(DocumentRecord::EXPIRED, DocumentRecord::where('title', 'اليوم')->sole()->status());
    }

    public function test_reading_is_the_section_and_writing_is_documents_manage(): void
    {
        $doc = $this->document('رخصة البلدية', '2026-10-01');
        $reader = User::create(['business_id' => $this->business->id, 'name' => 'قارئ', 'email' => 'reader@hadath.om', 'password' => bcrypt('password'),
            'role' => 'sales', 'status' => 'نشط', 'permissions' => ['dashboard', 'documents']]);

        /* من لا يفتح القسم لا يرى شيئًا */
        $this->actingAs($this->field)->get(route('admin.documents.index'))->assertForbidden();
        $this->actingAs($this->field)->get(route('admin.documents.show', $doc->id))->assertForbidden();

        /* والقارئُ يقرأ ولا يكتب */
        $this->actingAs($reader)->get(route('admin.documents.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('may.manage', false)->where('documents.0.title', 'رخصة البلدية'));
        $this->actingAs($reader)->post(route('admin.documents.store'), ['title' => 'x', 'file' => $this->pdf()])->assertForbidden();
        $this->actingAs($reader)->post(route('admin.documents.update', $doc->id), ['title' => 'x'])->assertForbidden();
        $this->actingAs($reader)->post(route('admin.documents.archive', $doc->id))->assertForbidden();
        $this->actingAs($reader)->post(route('admin.documents.categories.store'), ['name' => 'جديد'])->assertForbidden();

        /* والفعلُ يُمنح بالاسم */
        $reader->update(['permissions' => ['dashboard', 'documents', Permissions::DOCUMENTS_MANAGE]]);
        $this->actingAs($reader->fresh())->post(route('admin.documents.archive', $doc->id))->assertRedirect();
        $this->assertNotNull($doc->fresh()->archived_at);
        $this->assertTrue(ActivityLog::where('description', 'أرشف المستند «رخصة البلدية»')->exists());
        $this->actingAs($this->manager)->post(route('admin.documents.restore', $doc->id))->assertRedirect();
        $this->assertNull($doc->fresh()->archived_at);
        $this->assertContains('documents', array_keys(Permissions::sectionLabels()));
    }

    public function test_the_sidebar_badge_and_the_dashboard_count_what_needs_action(): void
    {
        $this->document('منتهٍ', '2026-09-01');
        $this->document('قريب', '2026-10-01');
        $this->document('بعيد', '2027-09-01');
        $this->document('مؤرشف', '2026-09-01', ['archived_at' => now()]);
        DocumentRecord::create(['business_id' => $this->other->id, 'title' => 'لهم', 'expires_at' => '2026-09-25']);

        $this->actingAs($this->owner)->get(route('admin.dashboard'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('badges.documents', 2)
                ->where('documents.needs_action', 2)->where('documents.expired', 1)->where('documents.expiring', 1)->where('documents.nearest.title', 'قريب'));
        $this->actingAs($this->stranger)->get(route('admin.documents.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('badges.documents', 1));

        /* ومن لا يفتح المستندات لا يُحسب له عدّادٌ ولا ودجت */
        $this->actingAs($this->seller)->get(route('admin.dashboard'))
            ->assertInertia(fn (AssertableInertia $p) => $p->missing('badges.documents')->where('documents', null));
    }

    public function test_the_expiry_alert_speaks_at_each_step_once_never_for_the_archived_and_anew_after_renewal(): void
    {
        Settings::save((int) $this->business->id, ['mode' => Settings::LIVE], $this->owner);
        Rules::ensure((int) $this->business->id);
        AutomationRule::where('business_id', $this->business->id)->where('trigger', '!=', 'document_expiry')->update(['enabled' => false]);
        $rule = AutomationRule::where('business_id', $this->business->id)->where('trigger', 'document_expiry')->sole();
        $this->assertSame([-90, -60, -30, -30, -7, -7, 0, 0], array_column($rule->steps, 'offset'), 'الخطواتُ من القاعدة — تُعدَّل من شاشة الأتمتة');
        $this->assertSame('internal', $rule->kind);

        $tick = fn () => Engine::tick((int) $this->business->id);
        $alerts = fn (?User $u = null) => InternalNotification::where('kind', 'document_expiry')->when($u, fn ($q) => $q->where('user_id', $u->id))->count();

        $doc = $this->document('التأمين', '2026-12-19'); // بعد تسعين يومًا
        $this->document('المؤرشف', '2026-12-19', ['archived_at' => now()]);
        app()->setLocale('en'); // سطرُ الأوامر بلغة الخادم — والتنبيهُ بلغة القاعدة
        $tick();
        $this->assertSame('en', app()->getLocale(), 'وتعود لغةُ الخادم كما كانت');
        $this->assertSame(1, $alerts($this->storekeeper), 'قبل تسعين يومًا: المسؤولُ وحده');
        $this->assertSame(0, $alerts($this->owner));
        $this->assertStringContainsString('ينتهي المستند «التأمين» بعد 90 يومًا', InternalNotification::where('kind', 'document_expiry')->sole()->title);
        $tick();
        $this->assertSame(1, $alerts(), 'الخطوةُ نفسُها لا تُعاد في الدورة نفسها');

        Carbon::setTestNow('2026-11-19 10:00:00'); // قبل ثلاثين يومًا
        $tick();
        $this->assertSame(2, $alerts($this->storekeeper));
        $this->assertSame(1, $alerts($this->owner), 'ومن الثلاثين: الإدارة');
        $this->assertSame(1, $alerts($this->manager));

        Carbon::setTestNow('2026-12-19 10:00:00'); // يومَ الانتهاء
        $tick();
        $tick();
        $this->assertSame(3, $alerts($this->storekeeper));
        $this->assertStringContainsString('ينتهي المستند «التأمين» اليوم', InternalNotification::where('kind', 'document_expiry')->latest('id')->first()->title);

        /* تجديد: تاريخٌ جديد يبدأ خطواتِه من جديد — والقديمةُ لا تُعاد */
        $doc->update(['expires_at' => '2027-12-19']);
        $tick();
        $this->assertSame(3, $alerts($this->storekeeper), 'لا خطوةَ للتاريخ الجديد اليوم');
        Carbon::setTestNow('2027-09-20 10:00:00');
        $tick();
        $this->assertSame(4, $alerts($this->storekeeper), 'المستندُ المجدَّد — تسعون يومًا قبل تاريخه الجديد');
        $this->assertStringContainsString('2027-12-19', InternalNotification::where('kind', 'document_expiry')->where('user_id', $this->storekeeper->id)->latest('id')->first()->title);

        /* وبلا مسؤول: من أضافه يسمع */
        $this->document('تصريح', '2027-12-19', ['responsible_user_id' => null]);
        $tick();
        $this->assertSame(1, InternalNotification::where('kind', 'document_expiry')->where('user_id', $this->owner->id)->where('title', 'like', '%«تصريح»%')->count());
        $this->assertSame(0, InternalNotification::where('kind', 'document_expiry')->where('title', 'like', '%المؤرشف%')->count(), 'والمؤرشفُ صامت');
        $this->assertSame(0, InternalNotification::where('kind', 'document_expiry')->whereNotIn('user_id', User::where('business_id', $this->business->id)->pluck('id'))->count(), 'ولا يخرج عن الشركة');
    }

    public function test_categories_are_managed_inside_the_company_and_a_used_one_is_disabled_not_deleted(): void
    {
        $this->assertSame(count(DocumentRecords::DEFAULT_CATEGORIES), DocumentCategory::where('business_id', $this->business->id)->count());

        $this->actingAs($this->manager)->post(route('admin.documents.categories.store'), ['name' => 'شهادة جودة'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('admin.documents.categories.store'), ['name' => 'شهادة جودة'])->assertSessionHasErrors('category');
        $used = $this->category('تأمين');
        $this->document('وثيقة', null, ['document_category_id' => $used->id]);

        $this->actingAs($this->manager)->delete(route('admin.documents.categories.destroy', $used->id))->assertSessionHasErrors('category');
        $this->actingAs($this->manager)->put(route('admin.documents.categories.update', $used->id), ['name' => 'تأمين', 'active' => false])->assertSessionHasNoErrors();
        $this->assertFalse((bool) $used->fresh()->active);
        $fresh = $this->category('شهادة جودة');
        $this->actingAs($this->manager)->delete(route('admin.documents.categories.destroy', $fresh->id))->assertSessionHasNoErrors();
        $this->assertNull(DocumentCategory::find($fresh->id));

        /* وتصنيفُ شركةٍ أخرى لا يُمسّ */
        $theirs = $this->category('تأمين', (int) $this->other->id);
        $this->actingAs($this->manager)->put(route('admin.documents.categories.update', $theirs->id), ['name' => 'تسلّل'])->assertNotFound();
        $this->actingAs($this->manager)->delete(route('admin.documents.categories.destroy', $theirs->id))->assertNotFound();
    }
}
