<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\CompanyPolicy;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\DeliveryPackage;
use App\Models\DocumentApproval;
use App\Models\JobTitle;
use App\Models\PolicyException;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectItem;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\User;
use App\Support\CancellationWindow;
use App\Support\DeliveryPackages;
use App\Support\DeliveryReadiness;
use App\Support\Projects;
use App\Support\PublicDocument;
use App\Support\Quotations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\SatisfiesThePathToClose;
use Tests\TestCase;

/**
 * التسليمُ يُقفل المشروعَ الذي جاء منه — والإلغاءُ يُقاس على سياسته.
 *
 * السلسلةُ كاملةً: عرضٌ مقبول → مشروعٌ ببنوده → حزمةُ تسليمٍ من بنوده →
 * قبولُ العميل من رابطه → الكميّةُ المسلَّمة تُزاد مرّةً → جاهزيّةُ التسليم →
 * الإقفال. والفرعُ الآخر: إلغاءٌ → مهلةُ الإلغاء → رسمٌ يُفوتَر مرّةً أو
 * استثناءٌ باسم من يملكه.
 */
class ADeliveryClosesTheProjectItCameFromTest extends TestCase
{
    use RefreshDatabase;
    use SatisfiesThePathToClose;

    private Business $business;

    private User $owner;

    private User $manager;

    private Customer $customer;

    private Product $service;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('local');

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
        /* مديرٌ مُنح إدارةَ المشروع بالاسم ولم يُمنح تجاوزَ السياسة */
        $this->manager = User::create([
            'business_id' => $this->business->id, 'name' => 'مدير مشاريع', 'email' => 'pm@hadath.om',
            'password' => bcrypt('password'), 'role' => 'manager', 'status' => 'نشط',
            'permissions' => ['dashboard', 'projects', 'quotations', 'project.manage', 'quotation.create', 'quotation.send', 'quotation.recordDecision'],
        ]);

        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة', 'legal_name' => 'وزارة الطاقة']);
        $this->service = Product::create([
            'business_id' => $this->business->id, 'name' => 'تشغيل نظام تسجيل', 'kind' => Product::SERVICE, 'price' => 500,
        ]);
    }

    /** مشروعٌ من عرضٍ مقبول — ببندين: خدمةٌ ×2 وبطاقاتٌ ×300 */
    private function project(int $eventInDays = 40): Project
    {
        $this->actingAs($this->owner);

        $quotation = Quotations::create((int) $this->business->id, $this->customer, [
            'title' => 'مؤتمر الطاقة', 'issued_at' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(),
            'event_starts_at' => now()->addDays($eventInDays)->toDateString(),
        ], [
            ['product_id' => $this->service->id, 'quantity' => 2, 'unit_price' => 500],
            ['description' => 'طباعة بطاقات', 'quantity' => 300, 'unit_price' => 1.5, 'unit' => 'بطاقة'],
        ], $this->owner);
        Quotations::send($quotation, $this->owner);
        Quotations::recordDecision($quotation->fresh(), Quotation::ACCEPTED, [], $this->owner);

        $project = Project::where('quotation_id', $quotation->id)->firstOrFail();
        $project->update(['status' => Project::IN_PROGRESS]);

        return $project->fresh('items');
    }

    /** حزمةٌ من بنود المشروع — بالكميّات المعطاة لكلّ بند بترتيبه */
    private function package(Project $project, array $quantities, bool $send = true): DeliveryPackage
    {
        $this->actingAs($this->owner);

        $package = DeliveryPackages::create((int) $this->business->id, null, ['project_id' => $project->id], $this->owner);

        foreach ($project->items as $i => $item) {
            if (($quantities[$i] ?? null) === null) {
                continue;
            }
            DeliveryPackages::addItem($package, ['kind' => 'item', 'project_item_id' => $item->id, 'quantity' => $quantities[$i]], null, $this->owner);
        }
        DeliveryPackages::addItem($package, ['kind' => 'file', 'title' => 'تقرير'],
            UploadedFile::fake()->create('report.pdf', 5, 'application/pdf'), $this->owner);

        if (! $send) {
            return $package->fresh('items');
        }

        return DeliveryPackages::send(DeliveryPackages::ready($package->fresh(), $this->owner), $this->owner);
    }

    private function acceptFromLink(DeliveryPackage $package): void
    {
        auth()->logout();
        $this->post(DeliveryPackages::url($package).'/decision', [
            'decision' => 'accepted', 'name' => 'سالم الهنائي', 'consent' => '1',
        ])->assertSessionHasNoErrors();
    }

    /* ─────────────────────────── الحزمة تتبع المشروع ─────────────────────────── */

    public function test_a_package_built_on_a_project_inherits_its_customer_quotation_and_scope(): void
    {
        $project = $this->project();

        $package = DeliveryPackages::create((int) $this->business->id, null, ['project_id' => $project->id], $this->owner);

        $this->assertSame($project->id, $package->project_id);
        $this->assertSame($project->customer_id, $package->customer_id);
        $this->assertSame($project->quotation_id, $package->quotation_id);
        $this->assertSame($project->name, $package->title);

        $item = DeliveryPackages::addItem($package, ['kind' => 'item', 'project_item_id' => $project->items[1]->id], null, $this->owner);
        $this->assertSame('طباعة بطاقات', $item->title, 'يرث وصفَ البند');
        $this->assertSame('بطاقة', $item->unit);
        $this->assertSame(300.0, $item->quantity, 'وما بقي منه كميّةً افتراضيّة');
    }

    public function test_items_from_another_project_or_another_tenant_are_rejected(): void
    {
        $mine = $this->project();
        $other = $this->project();
        $package = DeliveryPackages::create((int) $this->business->id, null, ['project_id' => $mine->id], $this->owner);

        try {
            DeliveryPackages::addItem($package, ['kind' => 'item', 'project_item_id' => $other->items[0]->id], null, $this->owner);
            $this->fail('قُبل بندٌ من مشروعٍ آخر');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ليس من بنود المشروع', $e->getMessage());
        }

        /* وبندٌ من شركةٍ أخرى — ولو بمعرّفٍ صحيح */
        $stranger = Business::create(['name' => 'أخرى', 'type' => 'عام', 'status' => 'نشط']);
        $strangeCustomer = Customer::create(['business_id' => $stranger->id, 'name' => 'ع']);
        $strangeProject = Project::create(['business_id' => $stranger->id, 'customer_id' => $strangeCustomer->id, 'code' => 'X-1', 'name' => 'x']);
        $strangeItem = ProjectItem::create(['business_id' => $stranger->id, 'project_id' => $strangeProject->id, 'description' => 'x', 'quantity' => 5]);

        try {
            DeliveryPackages::addItem($package, ['kind' => 'item', 'project_item_id' => $strangeItem->id], null, $this->owner);
            $this->fail('قُبل بندٌ من شركةٍ أخرى');
        } catch (RuntimeException) {
        }

        /* ومشروعُ شركةٍ أخرى لا يُربط أصلًا */
        try {
            DeliveryPackages::create((int) $this->business->id, null, ['project_id' => $strangeProject->id], $this->owner);
            $this->fail('رُبطت حزمة بمشروع شركةٍ أخرى');
        } catch (RuntimeException) {
        }

        /* ومن الشاشة كذلك — الخادمُ يفحص لا الواجهة */
        $this->post(route('admin.deliveries.items.store', $package->id), [
            'kind' => 'item', 'project_item_id' => $other->items[0]->id, 'quantity' => 1,
        ])->assertSessionHasErrors('item');
        $this->post(route('admin.deliveries.store'), ['title' => 'x', 'project_id' => $strangeProject->id])
            ->assertSessionHasErrors('package');

        $this->assertCount(0, $package->fresh()->items);
        $this->assertSame(1, DeliveryPackage::count());
    }

    public function test_a_scope_item_needs_a_project_on_the_package(): void
    {
        $project = $this->project();
        $package = DeliveryPackages::create((int) $this->business->id, $this->customer, ['title' => 'بلا مشروع'], $this->owner);

        $this->expectException(RuntimeException::class);
        DeliveryPackages::addItem($package, ['kind' => 'item', 'project_item_id' => $project->items[0]->id], null, $this->owner);
    }

    /* ─────────────────────────── الكميّة المسلَّمة ─────────────────────────── */

    public function test_acceptance_increments_delivered_quantity_exactly_once_and_a_retry_cannot_repeat_it(): void
    {
        $project = $this->project();
        $package = $this->package($project, [1, 300]);

        $this->assertSame(0.0, (float) $project->items[0]->fresh()->delivered_quantity);

        $this->acceptFromLink($package);

        $items = $project->fresh('items')->items;
        $this->assertSame(1.0, (float) $items[0]->delivered_quantity);
        $this->assertSame(ProjectItem::IN_PROGRESS, $items[0]->status, 'نصفُ الخدمة سُلِّم');
        $this->assertSame(300.0, (float) $items[1]->delivered_quantity);
        $this->assertSame(ProjectItem::DELIVERED, $items[1]->status, 'البطاقاتُ كلُّها سُلِّمت');

        /* الرابطُ ثانيةً — يُردّ ولا يزيد */
        $this->post(DeliveryPackages::url($package).'/decision', ['decision' => 'accepted', 'name' => 'سالم', 'consent' => '1'])
            ->assertSessionHasErrors('decision');
        /* والموظّفُ ثانيةً — يُردّ ولا يزيد */
        $this->actingAs($this->owner);
        try {
            DeliveryPackages::recordDecision($package->fresh(), DeliveryPackage::ACCEPTED, [], $this->owner);
            $this->fail('سُجّل قبولٌ ثانٍ');
        } catch (RuntimeException) {
        }

        $items = $project->fresh('items')->items;
        $this->assertSame(1.0, (float) $items[0]->delivered_quantity);
        $this->assertSame(300.0, (float) $items[1]->delivered_quantity);
        $this->assertSame(1, DocumentApproval::count());

        /* والحزمةُ المقبولة لا تُمسّ */
        $this->assertTrue($package->fresh()->isFrozen());
        try {
            DeliveryPackages::update($package->fresh(), ['title' => 'x'], $this->owner);
            $this->fail('عُدّلت حزمة مقبولة');
        } catch (RuntimeException) {
        }
        $this->assertTrue($package->fresh('approval')->approval->verify());
    }

    public function test_a_decline_does_not_deliver_and_a_replacement_delivers_once(): void
    {
        $project = $this->project();
        $package = $this->package($project, [2, 300]);

        $this->actingAs($this->owner);
        $declined = DeliveryPackages::recordDecision($package, DeliveryPackage::DECLINED, ['decline_reason' => 'ناقص'], $this->owner);

        $this->assertSame(0.0, (float) $project->items[0]->fresh()->delivered_quantity);
        $this->assertFalse(DeliveryReadiness::forProject($project)->ready);

        /* البديلُ يرث وصلاتِ النطاق ويُسلِّم مرّةً */
        $copy = DeliveryPackages::revise($declined, $this->owner);
        $this->assertSame($project->id, $copy->project_id);
        $this->assertSame($project->items[0]->id, $copy->items->firstWhere('kind', 'item')->project_item_id);

        $sent = DeliveryPackages::send(DeliveryPackages::ready($copy, $this->owner), $this->owner);
        DeliveryPackages::recordDecision($sent, DeliveryPackage::ACCEPTED, [], $this->owner);

        $this->assertSame(2.0, (float) $project->items[0]->fresh()->delivered_quantity);
        $this->assertSame(300.0, (float) $project->items[1]->fresh()->delivered_quantity);

        $readiness = DeliveryReadiness::forProject($project);
        $this->assertTrue($readiness->ready, implode(' | ', $readiness->blockers));
        $this->assertSame(0, $readiness->unresolvedDeclineCount, 'المرفوضةُ حلّ محلَّها بديل — دليلٌ لا مانع');
        $this->assertSame(DeliveryPackage::DECLINED, $declined->fresh()->status);
    }

    public function test_delivery_above_the_remaining_approved_quantity_is_refused_before_sending_and_at_acceptance(): void
    {
        $project = $this->project();

        /* أكثر ممّا اعتُمد */
        $over = $this->package($project, [3, null], send: false);
        try {
            DeliveryPackages::ready($over, $this->owner);
            $this->fail('جُهّزت حزمة فوق المعتمَد');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('فوق ما بقي من المعتمَد', $e->getMessage());
        }
        DeliveryPackages::cancel($over, 'خطأ', $this->owner);

        /* وما تحمله حزمةٌ خرجت ولم تُقرَّر يُحسب من الباقي */
        $first = $this->package($project, [2, null]);
        $second = $this->package($project, [1, null], send: false);
        try {
            DeliveryPackages::ready($second, $this->owner);
            $this->fail('جُهّزت حزمة تتجاوز ما في الطريق');
        } catch (RuntimeException) {
        }
        $this->assertSame(0.0, DeliveryPackages::remainingOf($project->items[0]->fresh()));

        /* وعند القبول تُفحص ثانيةً تحت القفل: النطاقُ نقص بعد الإرسال */
        ProjectItem::whereKey($project->items[0]->id)->update(['quantity' => 1]);
        try {
            DeliveryPackages::recordDecision($first, DeliveryPackage::ACCEPTED, [], $this->owner);
            $this->fail('قُبل تسليمٌ فوق المعتمَد');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('فوق ما بقي', $e->getMessage());
        }
        $this->assertSame(DeliveryPackage::SENT, $first->fresh()->status);
        $this->assertSame(0.0, (float) $project->items[0]->fresh()->delivered_quantity);
    }

    /* ─────────────────────────── الإقفال ─────────────────────────── */

    public function test_the_project_closes_only_after_an_accepted_delivery_and_the_money_rules_still_hold(): void
    {
        $project = $this->project();

        $this->actingAs($this->owner);
        $this->post(route('admin.projects.close', $project->id))->assertSessionHasErrors('project');
        $this->assertSame(Project::IN_PROGRESS, $project->fresh()->status);

        $sent = $this->package($project, [2, 300]);
        $this->actingAs($this->owner);
        $this->post(route('admin.projects.close', $project->id))->assertSessionHasErrors('project');

        $this->acceptFromLink($sent);
        $this->actingAs($this->owner);

        /* وفاتورةٌ مسودّة ما زالت تمنع — قاعدةُ المرحلة 4 لم تُمسّ */
        $draft = \App\Support\CustomerInvoices::create((int) $this->business->id, $this->customer,
            ['project_id' => $project->id], [['description' => 'x', 'quantity' => 1, 'unit_price' => 10]], $this->owner->id);
        $this->post(route('admin.projects.close', $project->id))->assertSessionHasErrors('project');
        \App\Support\CustomerInvoices::issue($draft, $this->owner->id);

        /* والتسليمُ وحده لا يُقفل بعد مسار المشروع: نطاقٌ موقَّع وتنفيذٌ مكتمل وقيمةٌ مفوتَرة */
        $this->post(route('admin.projects.close', $project->id))->assertSessionHasErrors('project');
        $this->satisfyThePathToClose($project, $this->owner);

        $this->post(route('admin.projects.close', $project->id))->assertSessionHasNoErrors();

        $closed = $project->fresh();
        $this->assertSame(Project::CLOSED, $closed->status);
        $this->assertNotNull($closed->financials_frozen);

        /* والإقفالُ ثانيةً يردّ نفسَه */
        $this->assertSame(Project::CLOSED, Projects::close($closed, $this->owner)->status);

        /* ومُقفَلٌ لا تُبنى عليه حزمةٌ جديدة؟ — تُبنى: الإقفالُ لا يمنع دليلًا متأخّرًا، لكنّه لا يُفتح به */
        $this->assertSame(Project::CLOSED, $project->fresh()->status);
    }

    /* ─────────────────────────── الإلغاء ─────────────────────────── */

    private function policy(int $days = 14, float $percent = 25): void
    {
        CompanyPolicy::create([
            'business_id' => $this->business->id, 'key' => CancellationWindow::KEY,
            'params' => ['days' => $days, 'fee_percent' => $percent], 'text_ar' => 'سياسة الإلغاء', 'active' => true,
        ]);
    }

    public function test_cancellation_inside_the_window_costs_nothing(): void
    {
        $this->policy();
        $project = $this->project(eventInDays: 40);

        $this->actingAs($this->owner);
        $this->post(route('admin.projects.transition', $project->id), ['status' => 'cancelled', 'reason' => 'تغيّرت الخطط'])
            ->assertSessionHasNoErrors();

        $p = $project->fresh();
        $this->assertSame(Project::CANCELLED, $p->status);
        $this->assertNull($p->cancellation_fee_invoice_id);
        $this->assertTrue($p->cancellation_policy['free']);
        $this->assertSame(0, CustomerInvoice::count());
        $this->assertTrue(ActivityLog::where('description', 'like', 'قِيست سياسة الإلغاء%')->exists());
    }

    public function test_cancellation_outside_the_window_issues_the_fee_invoice_exactly_once(): void
    {
        $this->policy(days: 14, percent: 25);
        $project = $this->project(eventInDays: 5);

        $this->actingAs($this->owner);
        $cancelled = Projects::cancel($project, 'ألغى العميل', $this->owner);

        $this->assertSame(Project::CANCELLED, $cancelled->status);
        $this->assertNotNull($cancelled->cancellation_fee_invoice_id);
        $this->assertFalse($cancelled->cancellation_policy['free']);

        $invoice = CustomerInvoice::findOrFail($cancelled->cancellation_fee_invoice_id);
        $this->assertSame(CustomerInvoice::ISSUED, $invoice->status);
        $this->assertSame($project->id, $invoice->project_id);
        $this->assertNotNull($invoice->number);
        $this->assertCount(1, $invoice->items);
        /* 25٪ من قيمة النطاق الحاليّة (1450) = 362.500 قبل الضريبة */
        $this->assertEqualsWithDelta(362.5, (float) $invoice->items[0]->unit_price, 0.0005);
        $this->assertEqualsWithDelta(362.5, (float) $invoice->subtotal, 0.0005);
        $this->assertStringContainsString('رسم إلغاء', $invoice->items[0]->description);

        /* وتكرارُ الإلغاء — من الخدمة ومن الشاشة — لا يُصدر ثانية */
        Projects::cancel($cancelled, 'ثانية', $this->owner);
        $this->post(route('admin.projects.transition', $project->id), ['status' => 'cancelled', 'reason' => 'ثالثة']);
        $this->assertSame(1, CustomerInvoice::count());
        $this->assertSame($invoice->id, $project->fresh()->cancellation_fee_invoice_id);
        $this->assertSame(1, ActivityLog::where('description', 'like', 'أصدر فاتورة رسم الإلغاء%')->count());
    }

    public function test_the_fee_is_waived_only_by_someone_who_may_override_the_policy(): void
    {
        $this->policy(days: 14, percent: 25);
        $project = $this->project(eventInDays: 5);

        /* مديرٌ بلا تجاوز يطلب إعفاءً: يُردّ ولا يُلغى ولا يُفوتَر */
        $this->actingAs($this->manager);
        $this->post(route('admin.projects.transition', $project->id), [
            'status' => 'cancelled', 'reason' => 'x', 'exception_reason' => 'عميل دائم',
        ])->assertSessionHasErrors('project');
        $this->assertSame(Project::IN_PROGRESS, $project->fresh()->status);
        $this->assertSame(0, CustomerInvoice::count());
        $this->assertSame(0, PolicyException::count());

        /* والمالكُ يعفي — استثناءٌ مكتوبٌ باسمه ولا فاتورة */
        $this->actingAs($this->owner);
        $this->post(route('admin.projects.transition', $project->id), [
            'status' => 'cancelled', 'reason' => 'ألغى العميل', 'exception_reason' => 'عميل دائم — عقد إطاري',
        ])->assertSessionHasNoErrors();

        $p = $project->fresh();
        $this->assertSame(Project::CANCELLED, $p->status);
        $this->assertNull($p->cancellation_fee_invoice_id);
        $this->assertSame(0, CustomerInvoice::count());

        $exception = PolicyException::sole();
        $this->assertSame(CancellationWindow::KEY, $exception->policy_key);
        $this->assertSame($project->id, (int) $exception->exceptionable_id);
        $this->assertSame($project->getMorphClass(), $exception->exceptionable_type);
        $this->assertSame($this->owner->id, $exception->approved_by);
        $this->assertNotNull($exception->approved_at);
        $this->assertSame('عميل دائم — عقد إطاري', $exception->reason);
        $this->assertEqualsWithDelta(362.5, (float) $exception->surcharge_amount, 0.0005);
        $this->assertSame($exception->id, $p->cancellation_policy['exception_id']);
    }

    public function test_without_a_policy_cancellation_is_free_and_manager_may_cancel(): void
    {
        $project = $this->project(eventInDays: 1);

        $this->actingAs($this->manager);
        $this->post(route('admin.projects.transition', $project->id), ['status' => 'cancelled', 'reason' => 'x'])
            ->assertSessionHasNoErrors();

        $this->assertSame(Project::CANCELLED, $project->fresh()->status);
        $this->assertSame(0, CustomerInvoice::count());
    }

    /* ─────────────────────────── الشاشة ─────────────────────────── */

    public function test_the_project_page_shows_delivery_and_the_cancellation_verdict(): void
    {
        $this->policy(days: 14, percent: 25);
        $project = $this->project(eventInDays: 5);
        $this->package($project, [2, 300]);

        $this->actingAs($this->owner);
        $this->get(route('admin.projects.show', $project->id))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('delivery.readiness.ready', false)
                ->where('delivery.readiness.awaiting_count', 1)
                ->has('delivery.packages', 1)
                ->where('cancellation.free', false)
                ->where('cancellation.fee_amount', 362.5)
                ->where('may.override_policy', true));

        $this->get(route('admin.deliveries.create', ['project_id' => $project->id]))->assertOk()
            ->assertInertia(fn ($page) => $page->where('preset.project_id', $project->id)->has('projects', 1));
    }
}
