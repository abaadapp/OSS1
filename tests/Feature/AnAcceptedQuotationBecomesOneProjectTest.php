<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectRequirement;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\User;
use App\Support\ChangeOrders;
use App\Support\Fingerprint;
use App\Support\Projects;
use App\Support\PublicDocument;
use App\Support\Quotations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * العرضُ المقبول يصير مشروعًا — واحدًا، والعرضُ لا يُمسّ.
 *
 * ═══ ما يُفحص ═══
 *
 * القبولُ من الرابط ينشئ مشروعًا · التسجيلُ نيابةً ينشئه · إعادةُ النداء لا
 * تنشئ ثانيًا · البنودُ تُنسخ بكميّاتها · العرضُ بعد القبول كما كان ببصمته ·
 * المتطلّباتُ تُثبَّت مرّةً وتُجمَّد · الرمزُ متسلسل · العزل.
 */
class AnAcceptedQuotationBecomesOneProjectTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $customer;

    private Product $service;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط', 'email' => 'hello@hadath.om']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@hadath.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة', 'legal_name' => 'وزارة الطاقة']);

        $this->service = Product::create([
            'business_id' => $this->business->id, 'name' => 'تشغيل نظام تسجيل',
            'kind' => Product::SERVICE, 'price' => 500,
        ]);
    }

    private function sent(): Quotation
    {
        $this->actingAs($this->owner);

        $quotation = Quotations::create(
            (int) $this->business->id,
            $this->customer,
            ['title' => 'مؤتمر الطاقة', 'issued_at' => now()->toDateString(),
                'valid_until' => now()->addDays(30)->toDateString(),
                'event_starts_at' => now()->addDays(40)->toDateString(),
                'event_ends_at' => now()->addDays(41)->toDateString(),
                'scope' => 'تسجيل الحضور وطباعة البطاقات'],
            [
                ['product_id' => $this->service->id, 'quantity' => 2, 'unit_price' => 500],
                ['description' => 'طباعة بطاقات', 'quantity' => 300, 'unit_price' => 1.5, 'unit' => 'بطاقة'],
            ],
            $this->owner,
        );

        $sent = Quotations::send($quotation, $this->owner);
        auth()->logout();

        return $sent;
    }

    /** @return array<string, mixed> */
    private function acceptance(): array
    {
        return ['decision' => 'accepted', 'name' => 'سالم الهنائي', 'email' => 'salim@ministry.om', 'consent' => '1'];
    }

    /* ─────────────────────────── الإنشاء ─────────────────────────── */

    public function test_acceptance_from_the_link_creates_exactly_one_project(): void
    {
        $q = $this->sent();
        $url = (string) PublicDocument::url($q);

        $this->post($url.'/decision', $this->acceptance())->assertSessionHasNoErrors();

        $this->assertSame(1, Project::count());
        $project = Project::first();

        $this->assertSame((int) $q->id, (int) $project->quotation_id);
        $this->assertSame((int) $this->business->id, (int) $project->business_id);
        $this->assertSame((int) $this->customer->id, (int) $project->customer_id);
        $this->assertSame(Project::CONFIRMED, $project->status);
        $this->assertSame('مؤتمر الطاقة', $project->name);
        $this->assertSame(now()->year, (int) substr($project->code, 3, 4));
        $this->assertStringEndsWith('-001', $project->code);
        $this->assertEqualsWithDelta((float) $q->total, (float) $project->approved_value, 0.0005);
        $this->assertEqualsWithDelta((float) $q->total, (float) $project->quoted_value, 0.0005);
        $this->assertSame($q->event_starts_at->toDateString(), $project->starts_at->toDateString());
        $this->assertNull($project->requirements_baselined_at);

        $this->assertTrue(ActivityLog::where('subject_type', 'project')->where('subject_id', $project->id)
            ->where('action', 'created')->exists());
    }

    public function test_a_staff_recorded_acceptance_creates_the_project_too(): void
    {
        $q = $this->sent();
        $this->actingAs($this->owner);

        $this->post(route('admin.quotations.decision', $q->id), ['decision' => 'accepted', 'decided_by_name' => 'سالم'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Project::count());
        $this->assertSame((int) $this->owner->id, (int) Project::first()->owner_id);
    }

    public function test_a_retried_acceptance_never_creates_a_second_project(): void
    {
        $q = $this->sent();

        $this->post(PublicDocument::url($q).'/decision', $this->acceptance());
        /* ضغطةٌ ثانية — يردّها القيدُ الفريد على القرار، ولا مشروعَ ثانيًا */
        $this->post(PublicDocument::url($q).'/decision', $this->acceptance());

        /* ونداءٌ مباشرٌ بعد القبول يجد المشروعَ القائم */
        $again = Projects::fromQuotation($q->fresh());

        $this->assertSame(1, Project::count());
        $this->assertSame((int) Project::first()->id, (int) $again->id);
    }

    public function test_a_project_is_not_created_from_an_unaccepted_quotation(): void
    {
        $q = $this->sent();

        $this->expectException(RuntimeException::class);
        Projects::fromQuotation($q);
    }

    /* ─────────────────────────── البنود ─────────────────────────── */

    public function test_project_items_copy_the_accepted_quantities_and_prices(): void
    {
        $q = $this->sent();
        $this->post(PublicDocument::url($q).'/decision', $this->acceptance());

        $project = Project::first();
        $items = $project->items;

        $this->assertCount(2, $items);
        $this->assertEqualsWithDelta(2.0, (float) $items[0]->quantity, 0.0005);
        $this->assertEqualsWithDelta(500.0, (float) $items[0]->unit_price, 0.0005);
        $this->assertSame((int) $this->service->id, (int) $items[0]->product_id);
        $this->assertSame((int) $q->items[0]->id, (int) $items[0]->quotation_item_id);
        $this->assertEqualsWithDelta(300.0, (float) $items[1]->quantity, 0.0005);
        $this->assertSame('بطاقة', $items[1]->unit);
        $this->assertNull($items[0]->change_order_id);
        /* والإجماليُّ بضريبته — كما على العرض */
        $this->assertEqualsWithDelta((float) $q->items[0]->line_total, (float) $items[0]->line_total, 0.0005);
    }

    public function test_the_accepted_quotation_is_untouched_and_its_evidence_still_verifies(): void
    {
        $q = $this->sent();
        $hash = $q->content_hash;
        $this->post(PublicDocument::url($q).'/decision', $this->acceptance());

        $q->refresh()->load('items');

        $this->assertSame(Quotation::ACCEPTED, $q->status);
        $this->assertSame($hash, $q->content_hash);
        $this->assertTrue(Fingerprint::matches(Quotations::hashPayload($q), $hash));
        $this->assertTrue($q->approval->verify());
        $this->assertCount(2, $q->items);
    }

    /* ─────────────────────────── التثبيت ─────────────────────────── */

    private function project(): Project
    {
        $q = $this->sent();
        $this->post(PublicDocument::url($q).'/decision', $this->acceptance());
        $this->actingAs($this->owner);

        return Project::first();
    }

    public function test_requirements_are_free_before_baseline_and_frozen_after(): void
    {
        $project = $this->project();

        $this->post(route('admin.projects.requirements.store', $project->id), ['title' => 'منصّة 6×4', 'details' => 'ستارة سوداء'])
            ->assertSessionHasNoErrors();
        $req = ProjectRequirement::first();
        $this->assertNotNull($req);
        $this->assertTrue($req->isBaseline());

        $this->put(route('admin.projects.requirements.update', [$project->id, $req->id]), ['title' => 'منصّة 8×4'])
            ->assertSessionHasNoErrors();
        $this->assertSame('منصّة 8×4', $req->fresh()->title);

        $this->post(route('admin.projects.baseline', $project->id))->assertSessionHasNoErrors();
        $project->refresh();
        $this->assertNotNull($project->requirements_baselined_at);
        $this->assertSame((int) $this->owner->id, (int) $project->requirements_baselined_by);

        /* بعد التثبيت: لا تعديلَ ولا حذفَ ولا إضافةَ مباشرة */
        $this->put(route('admin.projects.requirements.update', [$project->id, $req->id]), ['title' => 'منصّة 10×4'])
            ->assertSessionHasErrors('requirement');
        $this->delete(route('admin.projects.requirements.destroy', [$project->id, $req->id]))
            ->assertSessionHasErrors('requirement');
        $this->post(route('admin.projects.requirements.store', $project->id), ['title' => 'شيء آخر'])
            ->assertSessionHasErrors('requirement');

        $this->assertSame('منصّة 8×4', $req->fresh()->title);
        $this->assertSame(1, ProjectRequirement::count());

        /* ولا يُعاد التثبيت */
        $this->post(route('admin.projects.baseline', $project->id))->assertSessionHasErrors('project');

        $this->assertTrue(ActivityLog::where('subject_type', 'project')->where('subject_id', $project->id)
            ->where('description', 'like', '%ثبّت متطلّبات%')->exists());
    }

    public function test_baseline_needs_the_manage_action(): void
    {
        $project = $this->project();
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'بائع', 'role' => 'sales']);
        $sales = User::create([
            'business_id' => $this->business->id, 'name' => 'بائع', 'email' => 'sales@hadath.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
        ]);

        $this->actingAs($sales)->post(route('admin.projects.baseline', $project->id))->assertForbidden();
        $this->assertNull($project->fresh()->requirements_baselined_at);
    }

    /* ─────────────────────────── الشاشات ─────────────────────────── */

    /** كلُّ شاشةٍ من شاشات المشروع تُفتح — على مشروعٍ فيه أمرُ تغييرٍ ومتطلّب */
    public function test_every_project_screen_opens(): void
    {
        $project = $this->project();
        Projects::addRequirement($project, ['title' => 'منصّة'], $this->owner);
        $order = ChangeOrders::create($project, ['title' => 'شاشة', 'requested_by_party' => 'customer'],
            [['description' => 'شاشة', 'quantity' => 1, 'unit_price' => 100]], [], $this->owner);

        $this->get(route('admin.projects.index'))->assertOk();
        $this->get(route('admin.projects.show', $project->id))->assertOk();
        $this->get(route('admin.changeOrders.create', $project->id))->assertOk();
        $this->get(route('admin.changeOrders.show', $order->id))->assertOk();
        $this->get(route('admin.changeOrders.edit', $order->id))->assertOk();

        ChangeOrders::send($order, $this->owner);
        $this->get(route('admin.changeOrders.show', $order->id))->assertOk();
        $this->get(route('admin.changeOrders.edit', $order->id))->assertForbidden();
        /* والورقةُ العامّة تُفتح بلا حساب */
        auth()->logout();
        $this->get((string) PublicDocument::url($order->fresh()))->assertOk()->assertSee('CO-01');
    }

    /* ─────────────────────────── العزل ─────────────────────────── */

    public function test_a_project_of_another_business_is_not_found(): void
    {
        $project = $this->project();

        $other = Business::create(['name' => 'شركة أخرى', 'type' => 'عام', 'status' => 'نشط']);
        JobTitle::create(['business_id' => $other->id, 'name' => 'مدير', 'role' => 'admin']);
        $stranger = User::create([
            'business_id' => $other->id, 'name' => 'غريب', 'email' => 'x@other.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($stranger)->get(route('admin.projects.show', $project->id))->assertNotFound();
        $this->actingAs($stranger)->post(route('admin.projects.baseline', $project->id))->assertNotFound();
    }
}
