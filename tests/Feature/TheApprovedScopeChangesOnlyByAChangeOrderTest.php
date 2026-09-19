<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\ChangeOrder;
use App\Models\CompanyPolicy;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\DocumentApproval;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectRequirement;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\User;
use App\Support\ChangeOrders;
use App\Support\Document\Snapshot;
use App\Support\Fingerprint;
use App\Support\Projects;
use App\Support\ProjectScope;
use App\Support\PublicDocument;
use App\Support\Quotations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * النطاقُ المعتمَد لا يتغيّر إلّا بأمر تغييرٍ يقبله العميل — ويُطبَّق مرّةً.
 *
 * ═══ ما يُفحص ═══
 *
 * الكتابةُ والإرسالُ والقبولُ من الرابط · الاعتمادُ الداخليّ عند تجاوز الخصم ·
 * الختمُ والبصمةُ وأساسُها · الكميّاتُ تُطبَّق مرّةً · المتطلّباتُ تُضاف وتُزال
 * · الموعدُ · العرضُ لا يُمسّ · القيمةُ الحاليّة تُشتقّ · الأصلُ والتغييراتُ
 * والحاليّ · الرفضُ يُفرج · لا إلغاءَ لمقبول · الصلاحيّات · الأثر.
 */
class TheApprovedScopeChangesOnlyByAChangeOrderTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $sales;

    private Customer $customer;

    private Product $service;

    private Product $extra;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط', 'email' => 'hello@hadath.om']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'بائع', 'role' => 'sales']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);
        Setting::create(['business_id' => $this->business->id, 'key' => Quotations::THRESHOLD_KEY, 'value' => '10']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@hadath.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->sales = User::create([
            'business_id' => $this->business->id, 'name' => 'بائع', 'email' => 'sales@hadath.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
            /* صلاحيّاتٌ يدويّة تعلو الدور: ما يرثه البائع + منحُ الخصم — ولا اعتماد ولا إلغاء */
            'permissions' => ['dashboard', 'quotations', 'projects', 'customers', 'products',
                'quotation.create', 'quotation.send', 'quotation.recordDecision', 'quotation.discount',
                'changeOrder.create', 'changeOrder.send', 'changeOrder.recordDecision'],
        ]);

        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة']);

        $this->service = Product::create(['business_id' => $this->business->id, 'name' => 'تشغيل نظام تسجيل', 'kind' => Product::SERVICE, 'price' => 500]);
        $this->extra = Product::create(['business_id' => $this->business->id, 'name' => 'شاشة LED', 'kind' => Product::SERVICE, 'price' => 200]);
    }

    /** مشروعٌ مؤكَّدٌ من عرضٍ قُبل من الرابط — ومتطلَّبان مثبَّتان */
    private function project(): Project
    {
        $this->actingAs($this->owner);

        $quotation = Quotations::create((int) $this->business->id, $this->customer, [
            'title' => 'مؤتمر الطاقة', 'issued_at' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'event_starts_at' => now()->addDays(40)->toDateString(),
        ], [
            ['product_id' => $this->service->id, 'quantity' => 2, 'unit_price' => 500],
            ['description' => 'طباعة بطاقات', 'quantity' => 300, 'unit_price' => 1.5, 'unit' => 'بطاقة'],
        ], $this->owner);
        Quotations::send($quotation, $this->owner);
        auth()->logout();

        $this->post(PublicDocument::url($quotation).'/decision', [
            'decision' => 'accepted', 'name' => 'سالم', 'consent' => '1',
        ])->assertSessionHasNoErrors();

        $project = Project::firstOrFail();
        $this->actingAs($this->owner);
        Projects::addRequirement($project, ['title' => 'منصّة 6×4'], $this->owner);
        Projects::addRequirement($project, ['title' => 'ميكروفونان لاسلكيّان', 'project_item_id' => $project->items[0]->id], $this->owner);
        Projects::baseline($project, $this->owner);

        return $project->fresh();
    }

    /** @return array<string, mixed> */
    private function acceptance(): array
    {
        return ['decision' => 'accepted', 'name' => 'سالم الهنائي', 'email' => 'salim@ministry.om', 'consent' => '1'];
    }

    /* ─────────────────────────── الدورة الكاملة ─────────────────────────── */

    public function test_a_change_order_is_written_sent_accepted_and_applied_once(): void
    {
        $project = $this->project();
        $item = $project->items[0];
        $cards = $project->items[1];
        $stage = ProjectRequirement::where('title', 'منصّة 6×4')->firstOrFail();
        $quotationHash = $project->quotation->content_hash;
        $originalTotal = (float) $project->quotation->total;

        $this->actingAs($this->owner);

        $this->post(route('admin.changeOrders.store', $project->id), [
            'title' => 'يومٌ إضافيّ وشاشة',
            'reason' => 'طلب العميل تمديد المؤتمر يومًا',
            'requested_by_party' => 'customer',
            'requested_by_name' => 'سالم',
            'valid_until' => now()->addDays(10)->toDateString(),
            'new_starts_at' => now()->addDays(45)->toDateString(),
            'new_ends_at' => now()->addDays(46)->toDateString(),
            'items' => [
                ['project_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 500],
                ['product_id' => $this->extra->id, 'quantity' => 2, 'unit_price' => 200],
                ['project_item_id' => $cards->id, 'quantity' => -100],
            ],
            'requirements' => [
                'add' => [['title' => 'شاشة LED في القاعة الرئيسيّة']],
                'remove' => [$stage->id],
            ],
        ])->assertSessionHasNoErrors();

        $order = ChangeOrder::firstOrFail();
        $this->assertSame(ChangeOrder::DRAFT, $order->status);
        $this->assertSame(1, (int) $order->sequence);
        $this->assertNull($order->number);
        /* +500 +400 −150 = 750 قبل الضريبة · 787.500 بعدها */
        $this->assertEqualsWithDelta(750.0, (float) $order->subtotal_delta, 0.0005);
        $this->assertEqualsWithDelta(787.5, (float) $order->total_delta, 0.0005);
        $this->assertCount(3, $order->items);
        $this->assertTrue($order->items[2]->isReduction());
        $this->assertEqualsWithDelta(1.5, (float) $order->items[2]->unit_price, 0.0005, 'النقصُ بسعر البند القائم');

        /* وقبل القبول: لا شيء تغيّر في النطاق الحاليّ */
        $this->assertEqualsWithDelta(2.0, (float) $item->fresh()->quantity, 0.0005);
        $this->assertTrue($stage->fresh()->isActive(), 'إزالةٌ مقترَحةٌ لا تُخرج المتطلَّب');
        $this->assertCount(2, $project->requirements()->active()->get());
        $this->assertEqualsWithDelta((float) $project->approved_value, ProjectScope::currentValue($project), 0.0005);

        /* الإرسال — رقمٌ وختمٌ وبصمةٌ وأساس */
        $this->post(route('admin.changeOrders.send', $order->id))->assertSessionHasNoErrors();
        $order->refresh()->load('items');
        $this->assertSame(ChangeOrder::SENT, $order->status);
        $this->assertSame($project->code.'/CO-01', $order->number);
        $this->assertTrue(Snapshot::stamped($order));
        $this->assertNotNull($order->content_hash);
        $payload = ChangeOrders::hashPayload($order);
        $this->assertSame($quotationHash, $payload['base']['q'], 'الأساسُ بصمةُ العرض المقبول');
        $this->assertSame([], $payload['base']['co']);
        $this->assertTrue(Fingerprint::matches($payload, $order->content_hash));

        /* الرابطُ العامّ يفتح ويقبل */
        auth()->logout();
        $url = (string) PublicDocument::url($order);
        $this->get($url)->assertOk()->assertSee('أمر تغيير');
        $this->post($url.'/decision', $this->acceptance())->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(ChangeOrder::ACCEPTED, $order->status);
        $approval = $order->approval;
        $this->assertSame(DocumentApproval::ACCEPTED, $approval->decision);
        $this->assertSame($order->content_hash, $approval->content_hash);
        $this->assertTrue($approval->verify());
        $this->assertStringContainsString('أمر التغيير', (string) $approval->consent_text);

        /* التطبيق — الكميّاتُ والبندُ الجديد والمتطلّباتُ والموعد */
        $project->refresh();
        $this->assertEqualsWithDelta(3.0, (float) $item->fresh()->quantity, 0.0005);
        $this->assertEqualsWithDelta(200.0, (float) $cards->fresh()->quantity, 0.0005);
        $new = $project->items()->where('change_order_id', $order->id)->first();
        $this->assertNotNull($new);
        $this->assertEqualsWithDelta(2.0, (float) $new->quantity, 0.0005);
        $this->assertSame((int) $this->extra->id, (int) $new->product_id);
        $this->assertNull($new->quotation_item_id);

        $this->assertFalse($stage->fresh()->isActive());
        $this->assertSame((int) $order->id, (int) $stage->fresh()->removed_by_change_order_id);
        $this->assertNotNull($stage->fresh(), 'الصفُّ يبقى');
        $active = $project->requirements()->active()->pluck('title')->all();
        $this->assertContains('شاشة LED في القاعة الرئيسيّة', $active);
        $this->assertNotContains('منصّة 6×4', $active);
        $this->assertCount(2, $active);

        $this->assertSame(now()->addDays(45)->toDateString(), $project->starts_at->toDateString());
        $this->assertSame(now()->addDays(46)->toDateString(), $project->ends_at->toDateString());

        /* القيمةُ الحاليّة تُشتقّ — والأصلُ لا يُكتب */
        $this->assertEqualsWithDelta($originalTotal, (float) $project->approved_value, 0.0005);
        $this->assertEqualsWithDelta($originalTotal + 787.5, ProjectScope::currentValue($project), 0.0005);
        $this->assertSame([], ProjectScope::verify($project));

        /* والعرضُ كما كان */
        $q = $project->quotation->fresh('items');
        $this->assertSame(Quotation::ACCEPTED, $q->status);
        $this->assertSame($quotationHash, $q->content_hash);
        $this->assertTrue(Fingerprint::matches(Quotations::hashPayload($q), $quotationHash));
        $this->assertEqualsWithDelta(2.0, (float) $q->items[0]->quantity, 0.0005);
        $this->assertSame(now()->addDays(40)->toDateString(), $q->event_starts_at->toDateString());

        /* ولا يُطبَّق ثانيةً: قرارٌ ثانٍ يُردّ، والكميّاتُ كما هي */
        $this->post($url.'/decision', $this->acceptance())->assertSessionHasErrors('decision');
        $this->assertEqualsWithDelta(3.0, (float) $item->fresh()->quantity, 0.0005);
        $this->assertSame(1, $project->items()->where('change_order_id', $order->id)->count());

        $this->actingAs($this->owner);
        $this->post(route('admin.changeOrders.decision', $order->id), ['decision' => 'accepted'])->assertSessionHasErrors('decision');
        $this->assertEqualsWithDelta(3.0, (float) $item->fresh()->quantity, 0.0005);
        $this->assertSame(1, DocumentApproval::where('approvable_type', $order->getMorphClass())->count());

        /* الأثر */
        $log = ActivityLog::where('subject_type', 'change_order')->where('subject_id', $order->id)->pluck('description');
        $this->assertTrue($log->contains(fn ($d) => str_contains($d, 'كتب أمر تغيير')));
        $this->assertTrue($log->contains(fn ($d) => str_contains($d, 'أرسل أمر التغيير')));
        $this->assertTrue($log->contains(fn ($d) => str_contains($d, 'قبل العميل أمر التغيير')));
        $this->assertTrue(ActivityLog::where('subject_type', 'project')->where('subject_id', $project->id)
            ->where('description', 'like', '%طُبّق أمر التغيير%')->exists());

        /* الأصلُ والتغييراتُ والحاليّ */
        $original = ProjectScope::original($project);
        $this->assertEqualsWithDelta(2.0, $original['items'][0]['quantity'], 0.0005);
        $this->assertCount(2, $original['requirements']);
        $this->assertTrue($original['quotation']['evidence']['verified']);

        $changes = ProjectScope::changes($project);
        $this->assertCount(1, $changes);
        $this->assertSame($project->code.'/CO-01', $changes[0]['number']);
        $this->assertCount(1, $changes[0]['added']);
        $this->assertCount(1, $changes[0]['removed']);

        $current = ProjectScope::current($project);
        $this->assertCount(3, $current['items']);
        $this->assertCount(2, $current['requirements']);
        $this->assertEqualsWithDelta($originalTotal + 787.5, $current['current_value'], 0.0005);

        /* وأمرٌ ثانٍ يحمل الأوّلَ في أساسه */
        $second = ChangeOrders::create($project->fresh(), ['title' => 'إلغاء الشاشة', 'requested_by_party' => 'company'],
            [['project_item_id' => $new->id, 'quantity' => -2]], [], $this->owner);
        $second = ChangeOrders::send($second, $this->owner);
        $this->assertSame([$project->code.'/CO-01'], ChangeOrders::hashPayload($second)['base']['co']);
        $this->assertEqualsWithDelta(-420.0, (float) $second->total_delta, 0.0005);
    }

    /* ─────────────────────────── الاعتماد الداخليّ ─────────────────────────── */

    public function test_a_discount_beyond_the_threshold_waits_for_internal_approval(): void
    {
        $project = $this->project();

        $this->actingAs($this->sales);
        $order = ChangeOrders::create($project, ['title' => 'شاشة بخصم', 'requested_by_party' => 'customer'], [
            ['product_id' => $this->extra->id, 'quantity' => 1, 'unit_price' => 100, 'discount_reason' => 'تفاوض'],
        ], [], $this->sales);

        $this->assertSame(ChangeOrder::PENDING_APPROVAL, $order->status);
        $this->assertNull($order->items[0]->discount_approved_by);

        /* لا يخرج قبل الاعتماد — ولا يعتمده البائع */
        $this->post(route('admin.changeOrders.send', $order->id))->assertSessionHasErrors('order');
        $this->post(route('admin.changeOrders.approve', $order->id))->assertForbidden();

        $this->actingAs($this->owner)->post(route('admin.changeOrders.approve', $order->id))->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame(ChangeOrder::DRAFT, $order->status);
        $this->assertSame((int) $this->owner->id, (int) $order->approved_by);
        $this->assertSame((int) $this->owner->id, (int) $order->items()->first()->discount_approved_by);

        $this->actingAs($this->sales)->post(route('admin.changeOrders.send', $order->id))->assertSessionHasNoErrors();
        $this->assertSame(ChangeOrder::SENT, $order->fresh()->status);
    }

    public function test_a_late_postponement_needs_a_policy_exception(): void
    {
        $project = $this->project();
        CompanyPolicy::create([
            'business_id' => $this->business->id, 'key' => 'postponement_window',
            'params' => ['days' => 60], 'text_ar' => 'يُقبل التأجيل قبل الفعاليّة بستّين يومًا.', 'active' => true, 'version' => 1,
        ]);

        $this->actingAs($this->sales);

        try {
            ChangeOrders::create($project, ['title' => 'تأجيل', 'requested_by_party' => 'customer',
                'new_starts_at' => now()->addDays(50)->toDateString()], [], [], $this->sales);
            $this->fail('تأجيلٌ متأخّرٌ بلا سبب لا يُحفظ');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('سبب الاستثناء', $e->getMessage());
        }

        $order = ChangeOrders::create($project, ['title' => 'تأجيل', 'requested_by_party' => 'customer',
            'new_starts_at' => now()->addDays(50)->toDateString()], [], [], $this->sales,
            [['key' => 'postponement_window', 'reason' => 'ظرفٌ طارئ عند العميل']]);

        $this->assertSame(ChangeOrder::PENDING_APPROVAL, $order->status);
        $this->assertSame(1, $order->policyExceptions()->pending()->count());

        $this->actingAs($this->owner);
        ChangeOrders::approve($order, $this->owner);
        $sent = ChangeOrders::send($order->fresh(), $this->owner);
        $this->assertNotEmpty($sent->policy_snapshot);
        $this->assertSame('postponement_window', $sent->policy_snapshot[0]['key']);
    }

    /* ─────────────────────────── الحدود ─────────────────────────── */

    public function test_an_empty_change_order_and_an_over_reduction_are_refused(): void
    {
        $project = $this->project();
        $this->actingAs($this->owner);

        $this->post(route('admin.changeOrders.store', $project->id), ['title' => 'لا شيء', 'requested_by_party' => 'customer'])
            ->assertSessionHasErrors('items');

        $this->post(route('admin.changeOrders.store', $project->id), [
            'title' => 'نقصٌ فوق الكميّة', 'requested_by_party' => 'customer',
            'items' => [['project_item_id' => $project->items[0]->id, 'quantity' => -3]],
        ])->assertSessionHasErrors('items');

        $this->post(route('admin.changeOrders.store', $project->id), [
            'title' => 'نقصٌ بلا بند', 'requested_by_party' => 'customer',
            'items' => [['description' => 'شيء', 'quantity' => -1]],
        ])->assertSessionHasErrors('items');

        $this->assertSame(0, ChangeOrder::count());
    }

    public function test_a_declined_order_releases_its_removals_and_an_accepted_one_cannot_be_cancelled(): void
    {
        $project = $this->project();
        $stage = ProjectRequirement::where('title', 'منصّة 6×4')->firstOrFail();
        $this->actingAs($this->owner);

        $order = ChangeOrders::create($project, ['title' => 'إزالة المنصّة', 'requested_by_party' => 'customer'], [],
            ['remove' => [$stage->id]], $this->owner);
        ChangeOrders::send($order, $this->owner);

        /* محجوزٌ لأمرٍ لم يُقرَّر — لا يحجزه ثانٍ */
        try {
            ChangeOrders::create($project, ['title' => 'إزالة ثانية', 'requested_by_party' => 'company'], [], ['remove' => [$stage->id]], $this->owner);
            $this->fail();
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('محجوز', $e->getMessage());
        }

        ChangeOrders::recordDecision($order->fresh(), ChangeOrder::DECLINED, ['decline_reason' => 'نحتاج المنصّة'], $this->owner);
        $this->assertSame(ChangeOrder::DECLINED, $order->fresh()->status);
        $this->assertNull($stage->fresh()->removed_by_change_order_id);
        $this->assertTrue($stage->fresh()->isActive());

        $second = ChangeOrders::create($project, ['title' => 'إزالة المنصّة فعلًا', 'requested_by_party' => 'customer'], [],
            ['remove' => [$stage->id]], $this->owner);
        ChangeOrders::send($second, $this->owner);
        ChangeOrders::recordDecision($second->fresh(), ChangeOrder::ACCEPTED, ['decided_by_name' => 'سالم'], $this->owner);
        $this->assertFalse($stage->fresh()->isActive());

        $this->post(route('admin.changeOrders.cancel', $second->id), ['reason' => 'خطأ'])->assertSessionHasErrors('order');
        $this->assertSame(ChangeOrder::ACCEPTED, $second->fresh()->status);
    }

    public function test_a_sent_order_whose_row_was_touched_is_not_accepted(): void
    {
        $project = $this->project();
        $this->actingAs($this->owner);

        $order = ChangeOrders::create($project, ['title' => 'شاشة', 'requested_by_party' => 'customer'],
            [['product_id' => $this->extra->id, 'quantity' => 1, 'unit_price' => 200]], [], $this->owner);
        ChangeOrders::send($order, $this->owner);
        $order->items()->first()->update(['unit_price' => 20]);
        auth()->logout();

        $this->post(PublicDocument::url($order).'/decision', $this->acceptance())->assertSessionHasErrors('decision');
        $this->assertSame(ChangeOrder::SENT, $order->fresh()->status);
        $this->assertEqualsWithDelta(2.0, (float) $project->items[0]->fresh()->quantity, 0.0005);
    }

    /* ─────────────────────────── الصلاحيّات ─────────────────────────── */

    public function test_change_order_actions_are_guarded_by_name(): void
    {
        $project = $this->project();
        $this->actingAs($this->owner);
        $order = ChangeOrders::create($project, ['title' => 'شاشة', 'requested_by_party' => 'customer'],
            [['product_id' => $this->extra->id, 'quantity' => 1, 'unit_price' => 200]], [], $this->owner);

        JobTitle::create(['business_id' => $this->business->id, 'name' => 'محاسب', 'role' => 'accountant']);
        $accountant = User::create([
            'business_id' => $this->business->id, 'name' => 'محاسب', 'email' => 'acc@hadath.om',
            'password' => bcrypt('password'), 'role' => 'accountant', 'status' => 'نشط',
        ]);

        $this->actingAs($accountant);
        $this->get(route('admin.projects.show', $project->id))->assertOk();
        $this->get(route('admin.changeOrders.create', $project->id))->assertForbidden();
        $this->post(route('admin.changeOrders.store', $project->id), ['title' => 'x', 'requested_by_party' => 'customer'])->assertForbidden();
        $this->post(route('admin.changeOrders.send', $order->id))->assertForbidden();
        $this->post(route('admin.changeOrders.cancel', $order->id), ['reason' => 'x'])->assertForbidden();
        $this->post(route('admin.changeOrders.decision', $order->id), ['decision' => 'accepted'])->assertForbidden();
        $this->post(route('admin.projects.close', $project->id))->assertForbidden();

        /* والبائعُ يكتب ويُرسل ويسجّل — ولا يُلغي */
        $this->actingAs($this->sales);
        $this->post(route('admin.changeOrders.cancel', $order->id), ['reason' => 'x'])->assertForbidden();
        $this->post(route('admin.changeOrders.send', $order->id))->assertSessionHasNoErrors();
        $this->post(route('admin.changeOrders.decision', $order->id), ['decision' => 'accepted', 'decided_by_name' => 'سالم'])
            ->assertSessionHasNoErrors();
        $this->assertSame(ChangeOrder::ACCEPTED, $order->fresh()->status);
    }
}
