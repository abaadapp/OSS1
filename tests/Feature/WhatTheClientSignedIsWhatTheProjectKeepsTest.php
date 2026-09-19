<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\ChangeOrder;
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
use App\Support\Projects;
use App\Support\ProjectScope;
use App\Support\PublicDocument;
use App\Support\Quotations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * ما وقّع عليه العميل هو ما يحتفظ به المشروع — لا أكثر ولا أقلّ ولا بعد أوانه.
 *
 * ═══ ما يُفحص (ردًّا على تدقيق ما بعد المرحلة 4) ═══
 *
 * أمرُ تغييرٍ لا يُطبَّق على مشروعٍ مُقفَل أو ملغى — من الرابط ولا نيابةً ·
 * والإقفالُ ينتظر ما خرج من أوامر، والإلغاءُ يُغلقها معه · وإلغاءٌ متأخّر لا
 * يكتب فوق قبول — لا في الأمر ولا في العرض · وتعديلُ بندٍ قائم بسعره لا
 * بسعر الطلب · وما خرج لا يُعدَّل ولا تُمسّ متطلّباتُه · والموعدُ الفعليُّ
 * يُفحص قبل التوقيع · والقبولُ نيابةً يفحص البصمةَ كما يفحصها الرابط · ونقصٌ
 * فوق الكميّة عند التطبيق يُرجع القبولَ كلَّه · والرابطُ يردّ ما لا يُقرَّر ·
 * والمالُ لا يُرسَل إلى من لا يملك رؤيته.
 */
class WhatTheClientSignedIsWhatTheProjectKeepsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $delivery;

    private Customer $customer;

    private Product $service;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط', 'email' => 'hello@hadath.om']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مندوب تسليم', 'role' => 'delivery']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@hadath.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->delivery = User::create([
            'business_id' => $this->business->id, 'name' => 'مندوب', 'email' => 'delivery@hadath.om',
            'password' => bcrypt('password'), 'role' => 'delivery', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة']);
        $this->service = Product::create(['business_id' => $this->business->id, 'name' => 'تشغيل نظام تسجيل', 'kind' => Product::SERVICE, 'price' => 500]);
    }

    /** مشروعٌ من عرضٍ قُبل نيابةً — بندان: خدمةٌ من الكتالوج وبندٌ حرّ */
    private function project(bool $baseline = true): Project
    {
        $this->actingAs($this->owner);

        $quotation = Quotations::create((int) $this->business->id, $this->customer, [
            'title' => 'مؤتمر الطاقة', 'issued_at' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'event_starts_at' => now()->addDays(40)->toDateString(),
            'event_ends_at' => now()->addDays(42)->toDateString(),
        ], [
            ['product_id' => $this->service->id, 'quantity' => 2, 'unit_price' => 500],
            ['description' => 'طباعة بطاقات', 'quantity' => 300, 'unit_price' => 1.5, 'unit' => 'بطاقة'],
        ], $this->owner);
        Quotations::send($quotation, $this->owner);
        Quotations::recordDecision($quotation->fresh(), Quotation::ACCEPTED, ['decided_by_name' => 'سالم'], $this->owner);

        $project = Project::where('quotation_id', $quotation->id)->firstOrFail();
        Projects::addRequirement($project, ['title' => 'منصّة 6×4'], $this->owner);
        Projects::addRequirement($project, ['title' => 'ميكروفونان لاسلكيّان'], $this->owner);

        if ($baseline) {
            Projects::baseline($project, $this->owner);
        }

        return $project->fresh('items');
    }

    /** أمرٌ مُرسَل: نقصُ بطاقةٍ واحدة وإضافةُ متطلَّب — بلا اعتمادٍ ينتظر */
    private function sentOrder(Project $project, ?array $lines = null, array $requirements = []): ChangeOrder
    {
        $this->actingAs($this->owner);
        $lines ??= [['project_item_id' => $project->items[1]->id, 'quantity' => -1]];
        $order = ChangeOrders::create($project, ['title' => 'تعديل', 'requested_by_party' => 'customer'],
            $lines, $requirements, $this->owner);

        return ChangeOrders::send($order, $this->owner)->fresh('items');
    }

    /** @return array<string, mixed> */
    private function acceptance(): array
    {
        return ['decision' => 'accepted', 'name' => 'سالم الهنائي', 'consent' => '1'];
    }

    /** @return array{items: array<int, float>, value: float, starts: ?string, ends: ?string} */
    private function snapshot(Project $project): array
    {
        $project = $project->fresh('items');

        return [
            'items' => $project->items->pluck('quantity', 'id')->map(fn ($q) => (float) $q)->all(),
            'value' => ProjectScope::currentValue($project),
            'starts' => $project->starts_at?->toDateString(),
            'ends' => $project->ends_at?->toDateString(),
        ];
    }

    /* ─────────────────────────── مُقفَلٌ وملغًى لا يتغيّران ─────────────────────────── */

    public function test_a_change_order_is_not_accepted_on_a_closed_project_from_the_link_nor_by_staff(): void
    {
        $project = $this->project();
        $order = $this->sentOrder($project, [
            ['project_item_id' => $project->items[1]->id, 'quantity' => -100],
            ['description' => 'شاشة', 'quantity' => 1, 'unit_price' => 100],
        ], ['add' => [['title' => 'مولّد احتياطيّ']]]);
        $before = $this->snapshot($project);

        /* مشروعٌ صار مُقفَلًا والأمرُ بيد العميل — بابٌ لا يُفتح بعد الإقفال */
        $project->update(['status' => Project::CLOSED, 'closed_at' => now()]);

        auth()->logout();
        $this->post(PublicDocument::url($order).'/decision', $this->acceptance())->assertSessionHasErrors('decision');

        $this->actingAs($this->owner);
        try {
            ChangeOrders::recordDecision($order->fresh(), ChangeOrder::ACCEPTED, [], $this->owner);
            $this->fail('قُبل أمرُ تغييرٍ على مشروعٍ مُقفَل');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('لم يعد قائمًا', $e->getMessage());
        }

        $this->assertSame(ChangeOrder::SENT, $order->fresh()->status, 'الأمرُ يبقى كما كان — لا قُبل ولا طُبّق');
        $this->assertSame(0, DocumentApproval::where('approvable_type', $order->getMorphClass())->count(), 'لا دليلَ يُكتب لقبولٍ رُدّ');
        $this->assertSame($before, $this->snapshot($project), 'لا كميّةَ ولا قيمةَ ولا موعدَ تغيّر');
        $this->assertSame(2, (int) $project->fresh()->items()->count(), 'ولا بندَ جديد');
        $this->assertFalse(ProjectRequirement::where('project_id', $project->id)->active()->where('title', 'مولّد احتياطيّ')->exists());
    }

    public function test_cancelling_a_project_cancels_its_outstanding_orders_and_a_forced_cancelled_state_still_refuses(): void
    {
        $project = $this->project();
        $held = ProjectRequirement::where('project_id', $project->id)->where('title', 'منصّة 6×4')->firstOrFail();
        $order = $this->sentOrder($project, null, ['remove' => [$held->id]]);
        $draft = ChangeOrders::create($project, ['title' => 'مسودّة', 'requested_by_party' => 'company'],
            [['project_item_id' => $project->items[0]->id, 'quantity' => 1]], [], $this->owner);
        $before = $this->snapshot($project);

        Projects::cancel($project, 'ألغى العميل الفعاليّة', $this->owner);

        $this->assertSame(ChangeOrder::CANCELLED, $order->fresh()->status, 'ما خرج يُلغى مع المشروع');
        $this->assertSame(ChangeOrder::CANCELLED, $draft->fresh()->status, 'والمسودّةُ كذلك');
        $this->assertNull($held->fresh()->removed_by_change_order_id, 'ويُفرَج عمّا حُجز');

        auth()->logout();
        $this->post(PublicDocument::url($order).'/decision', $this->acceptance())->assertSessionHasErrors('decision');
        $this->assertSame($before, $this->snapshot($project));

        /* وحالُ «ملغى» بلغت الصفَّ من غير بابها والأمرُ ما زال مُرسَلًا — التطبيقُ يردّ بنفسه */
        $order->update(['status' => ChangeOrder::SENT]);
        $this->post(PublicDocument::url($order).'/decision', $this->acceptance())->assertSessionHasErrors('decision');

        $this->actingAs($this->owner);
        $this->expectException(RuntimeException::class);
        ChangeOrders::recordDecision($order->fresh(), ChangeOrder::ACCEPTED, [], $this->owner);
    }

    public function test_closing_waits_for_change_orders_out_for_a_decision(): void
    {
        $project = $this->project();
        $project->update(['status' => Project::IN_PROGRESS]);
        $order = $this->sentOrder($project);

        $this->actingAs($this->owner);
        $response = $this->post(route('admin.projects.close', $project->id));
        $response->assertSessionHasErrors('project');
        $this->assertStringContainsString('أمرَ تغيير', session('errors')->first('project'));
        $this->assertSame(Project::IN_PROGRESS, $project->fresh()->status);

        /* وبعد إلغاء الأمر لا يبقى من أوامر التغيير مانع — وما يمنع بعدها بابُ التسليم لا هذا */
        ChangeOrders::cancel($order, 'سُحب', $this->owner);
        $this->post(route('admin.projects.close', $project->id))->assertSessionHasErrors('project');
        $this->assertStringNotContainsString('أمرَ تغيير', session('errors')->first('project'));
    }

    /* ─────────────────────────── الإلغاءُ لا يكتب فوق القبول ─────────────────────────── */

    public function test_an_accepted_order_cannot_be_cancelled_and_a_cancelled_one_cannot_be_accepted(): void
    {
        $project = $this->project();
        $order = $this->sentOrder($project);

        auth()->logout();
        $this->post(PublicDocument::url($order).'/decision', $this->acceptance())->assertSessionHasNoErrors();
        $this->assertSame(ChangeOrder::ACCEPTED, $order->fresh()->status);

        /* نموذجٌ قديم يقول «مُرسَل» — والقفلُ يقرأ الصفَّ لا النموذج */
        $stale = $order;
        $this->assertSame(ChangeOrder::SENT, $stale->status);
        $this->actingAs($this->owner);
        try {
            ChangeOrders::cancel($stale, 'متأخّر', $this->owner);
            $this->fail('أُلغي أمرٌ قُبل وطُبّق');
        } catch (RuntimeException) {
        }
        $this->assertSame(ChangeOrder::ACCEPTED, $order->fresh()->status);
        $this->assertEqualsWithDelta(299.0, (float) $project->items[1]->fresh()->quantity, 0.0005, 'وما طُبّق يبقى');

        /* والعكس: ملغًى لا يُقبل — لا من الرابط ولا نيابةً */
        $second = $this->sentOrder($project);
        ChangeOrders::cancel($second, 'سُحب', $this->owner);
        auth()->logout();
        $this->post(PublicDocument::url($second).'/decision', $this->acceptance())->assertSessionHasErrors('decision');
        $this->actingAs($this->owner);
        $this->expectException(RuntimeException::class);
        ChangeOrders::recordDecision($second->fresh(), ChangeOrder::ACCEPTED, [], $this->owner);
    }

    public function test_an_accepted_quotation_cannot_be_cancelled_and_a_cancelled_one_cannot_be_accepted(): void
    {
        $this->actingAs($this->owner);
        $quotation = Quotations::create((int) $this->business->id, $this->customer,
            ['title' => 'حفل', 'issued_at' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString()],
            [['description' => 'تنظيم', 'quantity' => 1, 'unit_price' => 1000]], $this->owner);
        Quotations::send($quotation, $this->owner);
        $stale = $quotation->fresh();

        auth()->logout();
        $this->post(PublicDocument::url($quotation).'/decision', $this->acceptance())->assertSessionHasNoErrors();
        $this->assertSame(Quotation::ACCEPTED, $quotation->fresh()->status);
        $this->assertSame(1, Project::where('quotation_id', $quotation->id)->count());

        $this->actingAs($this->owner);
        try {
            Quotations::cancel($stale, 'متأخّر', $this->owner);
            $this->fail('أُلغي عرضٌ قُبل وأُنشئ مشروعُه');
        } catch (RuntimeException) {
        }
        $this->assertSame(Quotation::ACCEPTED, $quotation->fresh()->status);

        $other = Quotations::create((int) $this->business->id, $this->customer,
            ['title' => 'حفل 2', 'issued_at' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString()],
            [['description' => 'تنظيم', 'quantity' => 1, 'unit_price' => 1000]], $this->owner);
        Quotations::send($other, $this->owner);
        Quotations::cancel($other->fresh(), 'سُحب', $this->owner);

        auth()->logout();
        $this->post(PublicDocument::url($other).'/decision', $this->acceptance())->assertSessionHasErrors('decision');
        $this->assertSame(0, Project::where('quotation_id', $other->id)->count());
    }

    /* ─────────────────────────── بندٌ قائم بسعره ─────────────────────────── */

    public function test_an_adjustment_to_an_existing_item_keeps_its_approved_price_and_tax_whatever_the_request_says(): void
    {
        $project = $this->project();
        $item = $project->items[1]; /* بطاقات @ 1.500 · ضريبة 5٪ */
        $this->assertEqualsWithDelta(1.5, (float) $item->unit_price, 0.0005);

        $this->actingAs($this->owner);
        $this->post(route('admin.changeOrders.store', $project->id), [
            'title' => 'مئة بطاقة أخرى', 'requested_by_party' => 'customer',
            'items' => [[
                'project_item_id' => $item->id, 'quantity' => 100,
                /* سعرٌ وضريبةٌ من الطلب — يُطرحان */
                'unit_price' => 9, 'tax_rate' => 0, 'description' => 'بطاقات فاخرة',
            ]],
        ])->assertSessionHasNoErrors();

        $order = ChangeOrder::firstOrFail();
        $line = $order->items()->firstOrFail();
        $this->assertEqualsWithDelta(1.5, (float) $line->unit_price, 0.0005, 'بسعر البند المعتمَد');
        $this->assertEqualsWithDelta((float) $item->tax_rate, (float) $line->tax_rate, 0.005, 'وبضريبته');
        $this->assertSame('طباعة بطاقات', $line->description, 'وبوصفه');
        $this->assertEqualsWithDelta(157.5, (float) $order->total_delta, 0.0005, '100 × 1.5 × 1.05');
        $this->assertSame(ChangeOrder::DRAFT, $order->status, 'ولا اعتمادَ ينتظر: لا خصمَ ولا تجاوز');

        ChangeOrders::send($order, $this->owner);
        ChangeOrders::recordDecision($order->fresh(), ChangeOrder::ACCEPTED, [], $this->owner);

        $fresh = $project->fresh('items');
        $this->assertEqualsWithDelta(400.0, (float) $fresh->items[1]->quantity, 0.0005);
        $this->assertEqualsWithDelta(
            ProjectScope::currentValue($fresh),
            round((float) $fresh->items->sum('line_total'), 3),
            0.0005,
            'القيمةُ الحاليّة المشتقّة من الفروق = مجموعُ بنود النطاق الحاليّ',
        );
        $this->assertSame([], ProjectScope::verify($fresh));
    }

    /* ─────────────────────────── ما خرج لا يُعدَّل ─────────────────────────── */

    public function test_a_sent_order_cannot_be_rewritten_by_update(): void
    {
        $project = $this->project();
        $order = $this->sentOrder($project);
        $hash = $order->content_hash;

        try {
            ChangeOrders::update($order, ['title' => 'غير', 'requested_by_party' => 'customer'],
                [['project_item_id' => $project->items[1]->id, 'quantity' => -50]], [], $this->owner);
            $this->fail('كُتب فوق أمرٍ خرج');
        } catch (RuntimeException) {
        }

        /* ونموذجٌ قرأ «مسودّة» قبل أن يُرسَل يُردّ تحت القفل — لا بالقراءة الأولى */
        $stale = ChangeOrder::find($order->id);
        $stale->status = ChangeOrder::DRAFT;
        try {
            ChangeOrders::update($stale, ['title' => 'غير', 'requested_by_party' => 'customer'],
                [['project_item_id' => $project->items[1]->id, 'quantity' => -50]], [], $this->owner);
            $this->fail('كُتب فوق أمرٍ خرج بنموذجٍ قديم');
        } catch (RuntimeException) {
        }

        $this->put(route('admin.changeOrders.update', $order->id), [
            'title' => 'غير', 'requested_by_party' => 'customer',
            'items' => [['project_item_id' => $project->items[1]->id, 'quantity' => -50]],
        ])->assertSessionHasErrors('items');

        $fresh = $order->fresh('items');
        $this->assertSame(ChangeOrder::SENT, $fresh->status);
        $this->assertSame($hash, $fresh->content_hash);
        $this->assertSame('تعديل', $fresh->title);
        $this->assertEqualsWithDelta(-1.0, (float) $fresh->items[0]->quantity, 0.0005);
    }

    public function test_a_requirement_held_by_a_sent_order_is_not_edited_or_deleted_from_the_project(): void
    {
        $project = $this->project(baseline: false);
        $held = ProjectRequirement::where('project_id', $project->id)->where('title', 'منصّة 6×4')->firstOrFail();
        $free = ProjectRequirement::where('project_id', $project->id)->where('title', 'ميكروفونان لاسلكيّان')->firstOrFail();
        $order = $this->sentOrder($project, null, ['remove' => [$held->id], 'add' => [['title' => 'مولّد']]]);
        $added = $order->addedRequirements()->firstOrFail();

        /* ونموذجٌ فُتح قبل أن يحجزه الأمر — الحارسُ يقرأ الصفَّ لا النموذج */
        $this->assertNull($held->removed_by_change_order_id);
        $this->actingAs($this->owner);
        foreach ([$held, $added] as $row) {
            try {
                Projects::updateRequirement($row, ['title' => 'غُيّر'], $this->owner);
                $this->fail('عُدّل متطلَّبٌ في يد أمرٍ خرج');
            } catch (RuntimeException) {
            }
            try {
                Projects::deleteRequirement($row, $this->owner);
                $this->fail('حُذف متطلَّبٌ في يد أمرٍ خرج');
            } catch (RuntimeException) {
            }
        }

        $this->put(route('admin.projects.requirements.update', [$project->id, $held->id]), ['title' => 'غُيّر'])
            ->assertSessionHasErrors('requirement');
        $this->delete(route('admin.projects.requirements.destroy', [$project->id, $held->id]))
            ->assertSessionHasErrors('requirement');

        /* والحرُّ يُعدَّل — والبصمةُ لا تُمسّ لأنّ الأمر لا يذكره */
        Projects::updateRequirement($free, ['title' => 'ثلاثة ميكروفونات'], $this->owner);
        $this->assertSame('ثلاثة ميكروفونات', $free->fresh()->title);
        $this->assertSame('منصّة 6×4', $held->fresh()->title);

        auth()->logout();
        $this->post(PublicDocument::url($order).'/decision', $this->acceptance())->assertSessionHasNoErrors();
        $this->assertSame(ChangeOrder::ACCEPTED, $order->fresh()->status);
    }

    /* ─────────────────────────── الموعدُ الفعليّ ─────────────────────────── */

    public function test_an_end_date_before_the_effective_start_is_refused_before_anyone_signs(): void
    {
        $project = $this->project();
        $this->actingAs($this->owner);

        /* نهايةٌ جديدة وحدها — قبل بداية المشروع القائمة */
        try {
            ChangeOrders::create($project, ['title' => 'نهاية', 'requested_by_party' => 'customer',
                'new_ends_at' => now()->addDays(39)->toDateString()], [], [], $this->owner);
            $this->fail('قُبلت نهايةٌ قبل البداية القائمة');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('قبل بدايتها', $e->getMessage());
        }

        /* بدايةٌ جديدة وحدها — بعد نهاية المشروع القائمة */
        try {
            ChangeOrders::create($project, ['title' => 'بداية', 'requested_by_party' => 'customer',
                'new_starts_at' => now()->addDays(50)->toDateString()], [], [], $this->owner);
            $this->fail('قُبلت بدايةٌ بعد النهاية القائمة');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('قبل بدايتها', $e->getMessage());
        }

        $this->post(route('admin.changeOrders.store', $project->id), [
            'title' => 'نهاية', 'requested_by_party' => 'customer', 'new_ends_at' => now()->addDays(39)->toDateString(),
        ])->assertSessionHasErrors('items');
        $this->assertSame(0, ChangeOrder::count());

        /* وتأجيلٌ كاملٌ بموعدين متّسقين يمرّ ويُطبَّق بموعديه */
        $order = ChangeOrders::create($project, ['title' => 'تأجيل', 'requested_by_party' => 'customer',
            'new_starts_at' => now()->addDays(60)->toDateString(), 'new_ends_at' => now()->addDays(61)->toDateString()], [], [], $this->owner);
        ChangeOrders::send($order, $this->owner);
        ChangeOrders::recordDecision($order->fresh(), ChangeOrder::ACCEPTED, [], $this->owner);
        $this->assertSame(now()->addDays(61)->toDateString(), $project->fresh()->ends_at->toDateString());
    }

    /* ─────────────────────────── الدليلُ واحدٌ من الرابط ونيابةً ─────────────────────────── */

    public function test_staff_recorded_acceptance_checks_the_sent_fingerprint_like_the_link_does(): void
    {
        $project = $this->project();
        $order = $this->sentOrder($project);
        $before = $this->snapshot($project);

        /* صفٌّ مُسّ بعد الإرسال من غير بابه */
        ChangeOrder::whereKey($order->id)->update(['title' => 'عُدّل بعد الإرسال']);

        $this->actingAs($this->owner);
        try {
            ChangeOrders::recordDecision($order->fresh(), ChangeOrder::ACCEPTED, [], $this->owner);
            $this->fail('سُجّل قبولُ ورقةٍ تغيّرت');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('تغيّر محتوى', $e->getMessage());
        }
        $this->assertSame(ChangeOrder::SENT, $order->fresh()->status);
        $this->assertSame($before, $this->snapshot($project));

        /* والرفضُ نيابةً يُسجَّل: لا يُبنى عليه نطاق */
        ChangeOrders::recordDecision($order->fresh(), ChangeOrder::DECLINED, ['decline_reason' => 'غالٍ'], $this->owner);
        $this->assertSame(ChangeOrder::DECLINED, $order->fresh()->status);

        /* والعرضُ كذلك */
        $quotation = Quotations::create((int) $this->business->id, $this->customer,
            ['title' => 'حفل', 'issued_at' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString()],
            [['description' => 'تنظيم', 'quantity' => 1, 'unit_price' => 1000]], $this->owner);
        Quotations::send($quotation, $this->owner);
        $quotation->items()->update(['unit_price' => 1]);

        $this->post(route('admin.quotations.decision', $quotation->id), ['decision' => Quotation::ACCEPTED])
            ->assertSessionHasErrors('decision');
        $this->assertSame(Quotation::SENT, $quotation->fresh()->status);
        $this->assertSame(0, Project::where('quotation_id', $quotation->id)->count());
    }

    /* ─────────────────────────── نقصٌ فوق الكميّة عند التطبيق ─────────────────────────── */

    public function test_an_over_reduction_detected_at_apply_rolls_back_the_whole_acceptance(): void
    {
        $project = $this->project();
        $item = $project->items[0]; /* كميّته 2 */
        $first = $this->sentOrder($project, [['project_item_id' => $item->id, 'quantity' => -2]]);
        $second = $this->sentOrder($project, [['project_item_id' => $item->id, 'quantity' => -2]]);

        ChangeOrders::recordDecision($first->fresh(), ChangeOrder::ACCEPTED, [], $this->owner);
        $this->assertEqualsWithDelta(0.0, (float) $item->fresh()->quantity, 0.0005);

        auth()->logout();
        $this->post(PublicDocument::url($second).'/decision', $this->acceptance())->assertSessionHasErrors('decision');

        $this->assertSame(ChangeOrder::SENT, $second->fresh()->status, 'الحالُ لم تنتقل');
        $this->assertSame(0, DocumentApproval::where('approvable_type', $second->getMorphClass())
            ->where('approvable_id', $second->id)->count(), 'ولا دليلَ بقي من معاملةٍ رُدّت');
        $this->assertEqualsWithDelta(0.0, (float) $item->fresh()->quantity, 0.0005, 'ولا كميّةَ تحت الصفر');
        $this->assertSame([], ProjectScope::verify($project->fresh()));
    }

    /* ─────────────────────────── الرابطُ يردّ ما لا يُقرَّر ─────────────────────────── */

    public function test_the_public_link_refuses_orders_that_cannot_be_decided(): void
    {
        $project = $this->project();
        $this->actingAs($this->owner);
        $draft = ChangeOrders::create($project, ['title' => 'مسودّة', 'requested_by_party' => 'customer'],
            [['project_item_id' => $project->items[1]->id, 'quantity' => -1]], [], $this->owner);

        auth()->logout();
        $this->get((string) PublicDocument::url($draft))->assertNotFound();
        $this->post(PublicDocument::url($draft).'/decision', $this->acceptance())->assertNotFound();

        $expired = $this->sentOrder($project);
        ChangeOrder::whereKey($expired->id)->update(['valid_until' => now()->subDay()->toDateString()]);
        auth()->logout();
        $this->get((string) PublicDocument::url($expired))->assertOk();
        $this->post(PublicDocument::url($expired).'/decision', $this->acceptance())->assertSessionHasErrors('decision');
        $this->assertSame(ChangeOrder::SENT, $expired->fresh()->status);

        /* ومرّةً واحدة: قرارٌ ثانٍ على ما قُرّر يُردّ */
        $order = $this->sentOrder($project);
        auth()->logout();
        $this->post(PublicDocument::url($order).'/decision', $this->acceptance())->assertSessionHasNoErrors();
        $this->post(PublicDocument::url($order).'/decision', $this->acceptance())->assertSessionHasErrors('decision');
        $this->assertSame(1, DocumentApproval::where('approvable_type', $order->getMorphClass())->where('approvable_id', $order->id)->count());
    }

    /* ─────────────────────────── المالُ لمن يملك رؤيته ─────────────────────────── */

    public function test_money_and_internal_notes_are_not_sent_to_a_user_without_the_commercial_actions(): void
    {
        $project = $this->project();
        $project->update(['internal_notes' => 'هامشُنا ضيّق']);
        $order = $this->sentOrder($project);
        $this->actingAs($this->owner);
        ChangeOrders::recordDecision($order->fresh(), ChangeOrder::ACCEPTED, [], $this->owner);

        /* المالكُ يرى كلَّ شيء */
        $this->get(route('admin.projects.show', $project->id))->assertInertia(fn ($p) => $p
            ->where('may.commercial', true)
            ->where('project.internal_notes', 'هامشُنا ضيّق')
            ->where('project.current_value', fn ($v) => (float) $v > 0)
            ->where('scope.current.items.0.unit_price', fn ($v) => (float) $v > 0)
            ->where('change_orders.0.total_delta', fn ($v) => $v !== null));

        /* ومندوبُ التسليم يملك القسم ليُنفّذ — ولا يُرسَل إليه رقمٌ ولا ملاحظةٌ داخليّة */
        $this->actingAs($this->delivery);
        $this->get(route('admin.projects.show', $project->id))->assertOk()->assertInertia(fn ($p) => $p
            ->where('may.commercial', false)
            ->where('project.internal_notes', null)
            ->where('project.quoted_value', null)
            ->where('project.approved_value', null)
            ->where('project.current_value', null)
            ->where('project.financials_frozen', null)
            ->where('scope.original.approved_value', null)
            ->where('scope.original.quotation.total', null)
            ->where('scope.current.current_value', null)
            ->where('scope.current.items.0.unit_price', null)
            ->where('scope.current.items.0.line_total', null)
            ->where('scope.current.items.0.quantity', fn ($v) => (float) $v > 0)
            ->where('scope.changes.0.total_delta', null)
            ->where('change_orders.0.total_delta', null)
            ->where('financials', null)
            ->where('cancellation', null));
        $this->get(route('admin.projects.index'))->assertOk()->assertInertia(fn ($p) => $p
            ->where('projects.0.current_value', null)->where('projects.0.approved_value', null));
        $this->get(route('admin.changeOrders.show', $order->id))->assertForbidden();

        /* ومن لم تُرسَل إليه الملاحظاتُ الداخليّة لا يمحوها بحفظٍ — يحتاج الإدارةَ ليحفظ أصلًا */
        $manager = User::create([
            'business_id' => $this->business->id, 'name' => 'منفّذ', 'email' => 'ops@hadath.om',
            'password' => bcrypt('password'), 'role' => 'delivery', 'status' => 'نشط',
            'permissions' => ['dashboard', 'projects', 'project.manage'],
        ]);
        $this->actingAs($manager);
        $this->put(route('admin.projects.update', $project->id), ['name' => 'مؤتمر الطاقة 2', 'internal_notes' => ''])
            ->assertSessionHasNoErrors();
        $this->assertSame('هامشُنا ضيّق', $project->fresh()->internal_notes);
        $this->assertSame('مؤتمر الطاقة 2', $project->fresh()->name);
    }

    /* ─────────────────────────── العزل ─────────────────────────── */

    public function test_change_order_routes_are_not_reachable_across_tenants(): void
    {
        $project = $this->project();
        $order = $this->sentOrder($project);

        $other = Business::create(['name' => 'شركة أخرى', 'type' => 'عام', 'status' => 'نشط']);
        JobTitle::create(['business_id' => $other->id, 'name' => 'مدير', 'role' => 'admin']);
        $stranger = User::create([
            'business_id' => $other->id, 'name' => 'غريب', 'email' => 'x@other.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($stranger);
        $this->get(route('admin.changeOrders.show', $order->id))->assertNotFound();
        $this->get(route('admin.changeOrders.create', $project->id))->assertNotFound();
        $this->post(route('admin.changeOrders.store', $project->id), ['title' => 'x', 'requested_by_party' => 'customer',
            'items' => [['project_item_id' => $project->items[0]->id, 'quantity' => 1]]])->assertNotFound();
        $this->post(route('admin.changeOrders.cancel', $order->id), ['reason' => 'x'])->assertNotFound();
        $this->post(route('admin.changeOrders.decision', $order->id), ['decision' => 'accepted'])->assertNotFound();
        $this->post(route('admin.projects.close', $project->id))->assertNotFound();

        $this->assertSame(ChangeOrder::SENT, $order->fresh()->status);
        $this->assertSame(1, ChangeOrder::count());
    }
}
