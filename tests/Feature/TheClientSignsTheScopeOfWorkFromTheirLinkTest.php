<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Communication;
use App\Models\DocumentApproval;
use App\Models\DocumentLink;
use App\Models\ScopeOfWork;
use App\Models\User;
use App\Support\ChangeOrders;
use App\Support\Communication\Templates;
use App\Support\ProjectWorkflow;
use App\Support\PublicDocument;
use App\Support\ScopesOfWork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\Concerns\WalksTheProjectJourney;
use Tests\TestCase;

/**
 * العميلُ يوقّع نطاقَ العمل من رابطه — ودليلُ توقيعه كدليل قبول العرض.
 *
 * السلسلة: عرضٌ مقبول → مشروعٌ مسارُه عند «نطاق العمل» → مسودّةٌ تُقرأ بنودُها
 * من النطاق الحاليّ → جاهزةٌ → تُرسَل فتُبصَم ويُبنى رابطُها → يفتحها العميل
 * (يُختم أوّلُ فتح) → يوقّع فيُكتب الدليل. وما حولها: لا توقيعَ مرّتين، ولا
 * على نسخةٍ قديمة، ولا على محتوًى عُدّل، ولا بلا خنق، ولا من شركةٍ أخرى؛
 * والرابطُ يُرسَل على واتساب ويُقيَّد في مراسلات المشروع.
 */
class TheClientSignsTheScopeOfWorkFromTheirLinkTest extends TestCase
{
    use RefreshDatabase;
    use WalksTheProjectJourney;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('local');
        Mail::fake();
        $this->journeyCompany();
    }

    private function decide(ScopeOfWork $scope, array $over = [])
    {
        auth()->logout();

        return $this->post(PublicDocument::url($scope).'/decision', $over + [
            'decision' => 'accepted', 'name' => 'سالم الهنائي', 'title' => 'مدير الفعاليات',
            'email' => 'salem@energy.gov.om', 'phone' => '+96891234567', 'consent' => '1', 'hash' => $scope->content_hash,
        ]);
    }

    /* ─────────────────────────── من العرض إلى المسودّة ─────────────────────────── */

    public function test_an_accepted_quotation_becomes_a_project_whose_path_waits_at_the_scope(): void
    {
        $project = $this->acceptedProject();
        $flow = ProjectWorkflow::for($project->fresh());

        $this->assertSame(ProjectWorkflow::SCOPE, $project->workflow_stage);
        $this->assertSame(ProjectWorkflow::SCOPE, $flow['focus']);
        $this->assertSame(['done', 'current', 'todo', 'todo', 'todo', 'todo', 'todo'], array_column($flow['stages'], 'state'));
        $this->assertSame(['لم يُعدّ نطاق العمل بعد.'], $flow['stages'][1]['pending']);
        $this->assertFalse($flow['can_advance'], 'لا انتقالَ قبل التوقيع');
        /* وما بعده مقفلٌ ويقول لماذا */
        $this->assertStringContainsString('نطاق العمل', $flow['stages'][2]['blocked_by'][0]);
    }

    public function test_the_draft_reads_its_lines_from_the_current_scope_and_is_reviewed_before_it_leaves(): void
    {
        $project = $this->acceptedProject();
        $this->actingAs($this->owner);

        $this->post(route('admin.projects.scope.store', $project->id))->assertRedirect(route('admin.projects.scope.show', $project->id));
        $this->post(route('admin.projects.scope.store', $project->id));
        $this->assertSame(1, ScopeOfWork::where('project_id', $project->id)->count(), 'ضغطتان مسودّةٌ واحدة');

        $scope = ScopeOfWork::where('project_id', $project->id)->sole();
        $this->assertSame($project->code.'/SOW-R1', $scope->number);
        $this->assertSame(ScopeOfWork::DRAFT, $scope->status);
        $this->assertSame('وزارة الطاقة والمعادن', $scope->customer_name);
        $this->assertSame('سالم الهنائي', $scope->contact_person);
        $this->assertSame('تشغيل نظام التسجيل والبطاقات طوال أيام المؤتمر.', $scope->description, 'الوصفُ من نطاق العرض');
        $this->assertSame('تشغيل نظام تسجيل', $scope->scope_snapshot['items'][0]['description']);
        $this->assertSame('10.000', $scope->scope_snapshot['items'][0]['quantity']);
        $this->assertSame('10500.000', $scope->scope_snapshot['value'], 'القيمةُ من ProjectScope لا من رقمٍ يُكتب');

        $this->put(route('admin.projects.scope.update', [$project->id, $scope->id]), [
            'project_name' => 'مؤتمر الطاقة 2026', 'description' => 'تشغيلٌ كامل', 'inclusions' => "أجهزة\nبطاقات",
            'exclusions' => 'الإنترنت', 'client_responsibilities' => 'قائمة المدعوين', 'show_value' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame('مؤتمر الطاقة 2026', $scope->fresh()->project_name);
        $this->assertTrue($scope->fresh()->show_value);

        /* ولا يخرج من المسودّة مباشرةً — يُراجَع أوّلًا */
        $this->post(route('admin.projects.scope.send', [$project->id, $scope->id]))->assertSessionHasErrors('scope');
        $this->assertSame(ScopeOfWork::DRAFT, $scope->fresh()->status);

        $this->post(route('admin.projects.scope.ready', [$project->id, $scope->id]))->assertSessionHasNoErrors();
        $this->put(route('admin.projects.scope.update', [$project->id, $scope->id]), ['project_name' => 'x'])->assertSessionHasErrors('scope');

        $this->post(route('admin.projects.scope.send', [$project->id, $scope->id]))->assertSessionHasNoErrors();
        $sent = $scope->fresh();
        $this->assertSame(ScopeOfWork::SENT, $sent->status);
        $this->assertSame(64, strlen((string) $sent->content_hash));
        $this->assertTrue(DocumentLink::where('linkable_type', $sent->getMorphClass())->where('linkable_id', $sent->id)->exists());
        $this->assertNotNull($project->fresh()->requirements_baselined_at, 'ما يُرسَل للتوقيع تُثبَّت متطلّباتُه');
        $this->assertTrue(ActivityLog::where('description', 'like', '%أرسل نطاق العمل '.$sent->number.'%')->exists());
    }

    public function test_a_change_order_accepted_after_review_holds_the_send_until_the_draft_is_reviewed_again(): void
    {
        $project = $this->acceptedProject();
        $this->actingAs($this->owner);
        $scope = ScopesOfWork::markReady(ScopesOfWork::start($project, $this->owner), $this->owner);

        /* أمرُ تغييرٍ يُقبل بعد المراجعة: البنودُ تغيّرت ولم يقرأها أحد */
        $co = ChangeOrders::create($project, ['title' => 'يومٌ إضافيّ', 'requested_by_party' => 'customer'],
            [['project_item_id' => $project->items()->first()->id, 'quantity' => 2]], [], $this->owner);
        ChangeOrders::send($co, $this->owner);
        ChangeOrders::recordDecision($co->fresh(), 'accepted', ['decided_by_name' => 'سالم'], $this->owner);

        $this->assertTrue(ScopesOfWork::stale($scope->fresh()));
        $this->expectException(RuntimeException::class);
        ScopesOfWork::send($scope->fresh(), $this->owner);
    }

    /* ─────────────────────────── العميلُ يفتح ويوقّع ─────────────────────────── */

    public function test_the_client_reads_the_whole_scope_and_the_first_opening_is_stamped_once(): void
    {
        $scope = $this->sentScope($this->acceptedProject());
        auth()->logout();

        $page = $this->get(PublicDocument::url($scope))->assertOk();
        $page->assertSee('نطاق العمل')->assertSee('تشغيل نظام تسجيل')->assertSee('أجهزة التسجيل')
            ->assertSee('الإنترنت في القاعة')->assertSee('قائمة المدعوين قبل أسبوع')
            ->assertSee(ScopesOfWork::consent((string) $this->business->name))
            ->assertSee($scope->content_hash)->assertSee('موافقة وتوقيع')->assertSee('يحتاج تعديل');
        /* والقيمةُ لا تظهر ما لم يُقرَّر إظهارُها */
        $page->assertDontSee('القيمة المرجعية للنطاق');

        $this->get(PublicDocument::url($scope))->assertOk();

        $fresh = $scope->fresh();
        $this->assertNotNull($fresh->first_viewed_at);
        $this->assertSame(2, $fresh->view_count);
        $this->assertSame('opened', $fresh->presentedState());
        $this->assertSame(1, ActivityLog::where('description', 'like', 'فتح العميل رابط نطاق العمل%')->count(), 'أوّلُ فتحٍ يُقيَّد مرّةً');
    }

    public function test_a_draft_does_not_open_even_when_its_token_is_known(): void
    {
        $this->actingAs($this->owner);
        $scope = ScopesOfWork::start($this->acceptedProject(), $this->owner);
        $token = PublicDocument::token($scope);
        auth()->logout();

        $this->get(route('paper.show', $token))->assertNotFound();
        $this->post(route('paper.decide', $token), ['decision' => 'accepted', 'name' => 'سالم', 'consent' => '1', 'hash' => str_repeat('a', 64)])->assertNotFound();
        $this->assertSame(0, DocumentApproval::count());
    }

    public function test_signing_writes_the_evidence_and_completes_the_scope_stage(): void
    {
        $project = $this->acceptedProject();
        $scope = $this->sentScope($project);

        $this->decide($scope)->assertRedirect(PublicDocument::url($scope))->assertSessionHasNoErrors();

        $signed = $scope->fresh('approval');
        $this->assertSame(ScopeOfWork::SIGNED, $signed->status);
        $this->assertSame('سالم الهنائي', $signed->decided_by_name);

        $approval = $signed->approval;
        $this->assertSame(DocumentApproval::ACCEPTED, $approval->decision);
        $this->assertSame($scope->content_hash, $approval->content_hash);
        $this->assertSame('مدير الفعاليات', $approval->signer_title);
        $this->assertSame('salem@energy.gov.om', $approval->signer_email);
        $this->assertSame('+96891234567', $approval->signer_phone);
        $this->assertSame('127.0.0.1', $approval->ip);
        $this->assertNotEmpty($approval->user_agent);
        $this->assertSame(ScopesOfWork::consent((string) $this->business->name), $approval->consent_text);
        $this->assertSame(ScopesOfWork::hashPayload($signed), $approval->accepted_snapshot);
        $this->assertTrue($approval->verify(), 'الدليلُ يُعاد فحصُه فيطابق');

        $flow = ProjectWorkflow::for($project->fresh());
        $this->assertTrue($flow['stages'][1]['done']);
        $this->assertTrue($flow['can_advance']);
        $this->assertSame('deposit', $flow['next']);

        /* وتعود الصفحةُ تقول ما قُرّر — بلا نموذج */
        $this->get(PublicDocument::url($scope))->assertOk()->assertSee('تم اعتماد نطاق العمل')->assertDontSee('id="decide"', false);
    }

    public function test_a_second_signature_is_refused_and_writes_nothing(): void
    {
        $scope = $this->sentScope($this->acceptedProject());

        $this->decide($scope)->assertSessionHasNoErrors();
        $this->decide($scope)->assertSessionHasErrors('decision');
        $this->decide($scope, ['decision' => 'declined', 'decline_reason' => 'غيّرت رأيي'])->assertSessionHasErrors('decision');

        $this->assertSame(1, DocumentApproval::count());
        $this->assertSame(ScopeOfWork::SIGNED, $scope->fresh()->status);
    }

    public function test_the_client_may_ask_for_changes_with_a_reason(): void
    {
        $project = $this->acceptedProject();
        $scope = $this->sentScope($project);

        $this->decide($scope, ['decision' => 'declined', 'decline_reason' => ''])->assertSessionHasErrors('decision');
        $this->decide($scope, ['decision' => 'declined', 'decline_reason' => 'أضيفوا يومًا ثالثًا', 'consent' => null])->assertSessionHasNoErrors();

        $this->assertSame(ScopeOfWork::DECLINED, $scope->fresh()->status);
        $this->assertSame('أضيفوا يومًا ثالثًا', $scope->fresh()->decline_reason);
        $flow = ProjectWorkflow::for($project->fresh());
        $this->assertFalse($flow['stages'][1]['done']);
        $this->assertStringContainsString('طلب العميل تعديل نطاق العمل', $flow['stages'][1]['pending'][0]);
    }

    public function test_an_old_revision_or_a_stale_page_cannot_be_signed(): void
    {
        $project = $this->acceptedProject();
        $first = $this->sentScope($project);

        /* صفحةٌ فُتحت ثمّ تغيّر ما فيها: البصمةُ المرسَلة غيرُ بصمة المستند */
        $this->decide($first, ['hash' => str_repeat('0', 64)])->assertSessionHasErrors('decision');

        /* ونسخةٌ جديدةٌ تحلّ محلَّ ما لم يُوقَّع — فرابطُ القديمة لا يقبل توقيعًا */
        $this->actingAs($this->owner);
        $second = ScopesOfWork::start($project->fresh(), $this->owner);
        $this->assertSame(2, $second->revision);
        $this->assertSame($first->id, $second->supersedes_id);
        $this->assertSame(ScopeOfWork::SUPERSEDED, $first->fresh()->status);

        $this->decide($first)->assertSessionHasErrors('decision');
        $this->assertSame(0, DocumentApproval::count());

        auth()->logout();
        $this->get(PublicDocument::url($first))->assertOk()->assertSee('هذه نسخةٌ قديمة من نطاق العمل')->assertDontSee('id="decide"', false);
    }

    public function test_a_signed_scope_is_never_rewritten_and_a_change_is_a_new_revision_that_replaces_it_only_when_signed(): void
    {
        $project = $this->acceptedProject();
        $first = $this->signedScope($project);

        try {
            ScopesOfWork::update($first, ['description' => 'نصٌّ آخر'], $this->owner);
            $this->fail('النسخةُ الموقَّعة عُدّلت');
        } catch (RuntimeException) {
        }
        $this->assertSame('تشغيل نظام التسجيل في يومي المؤتمر.', $first->fresh()->description);

        $second = ScopesOfWork::start($project->fresh(), $this->owner);
        $this->assertSame('تشغيل نظام التسجيل في يومي المؤتمر.', $second->description, 'الجديدةُ ترث ما كُتب');
        $this->assertSame(ScopeOfWork::SIGNED, $first->fresh()->status, 'الموقَّعةُ ساريةٌ حتى تُوقَّع التي بعدها');
        $this->assertTrue(ProjectWorkflow::for($project->fresh())['stages'][1]['done']);

        ScopesOfWork::update($second, ['exclusions' => 'الإنترنت والكهرباء'], $this->owner);
        $second = ScopesOfWork::send(ScopesOfWork::markReady($second->fresh(), $this->owner), $this->owner);
        $this->decide($second)->assertSessionHasNoErrors();

        $this->assertSame(ScopeOfWork::SIGNED, $second->fresh()->status);
        $this->assertSame(ScopeOfWork::SUPERSEDED, $first->fresh()->status);
        $this->assertTrue($first->fresh('approval')->approval->verify(), 'دليلُ الأولى باقٍ يطابق لقطتَه');
        $this->assertSame($second->id, ScopesOfWork::signed($project->fresh())->id);
    }

    public function test_a_scope_edited_by_hand_after_it_left_cannot_be_signed(): void
    {
        $scope = $this->sentScope($this->acceptedProject());
        ScopeOfWork::whereKey($scope->id)->update(['exclusions' => 'لا شيء']);

        $this->decide($scope)->assertSessionHasErrors('decision');
        $this->assertSame(ScopeOfWork::SENT, $scope->fresh()->status);
        $this->assertSame(0, DocumentApproval::count());
    }

    public function test_the_public_signature_is_throttled(): void
    {
        $scope = $this->sentScope($this->acceptedProject());
        RateLimiter::clear('paper:decide:127.0.0.1');

        for ($i = 0; $i < 10; $i++) {
            $this->decide($scope, ['name' => '']);
        }

        $response = $this->decide($scope);
        $response->assertSessionHasErrors('decision');
        $this->assertStringContainsString('محاولات كثيرة', session('errors')->first('decision'));
        $this->assertSame(ScopeOfWork::SENT, $scope->fresh()->status);
    }

    /* ─────────────────────────── العزل والصلاحيّات ─────────────────────────── */

    public function test_no_screen_of_the_scope_opens_on_another_company(): void
    {
        $project = $this->acceptedProject();
        $scope = $this->sentScope($project);
        $mine = ['business' => $this->business, 'owner' => $this->owner];

        $this->journeyCompany('شركة أخرى');
        $other = $this->owner;
        $theirs = $this->acceptedProject();
        $this->business = $mine['business'];

        $this->actingAs($other);
        $this->get(route('admin.projects.scope.show', $project->id))->assertNotFound();
        $this->post(route('admin.projects.scope.store', $project->id))->assertNotFound();
        $this->get(route('admin.projects.scope.preview', [$project->id, $scope->id]))->assertNotFound();
        $this->post(route('admin.projects.scope.send', [$project->id, $scope->id]))->assertNotFound();
        $this->get(route('admin.projects.scopes.communications.compose', $scope->id))->assertNotFound();
        /* ونسخةُ مشروعٍ لا تُفتح من مسار مشروعٍ آخر — ولو في الشركة نفسِها */
        $this->get(route('admin.projects.scope.preview', [$theirs->id, $scope->id]))->assertNotFound();

        $this->assertSame(0, ScopeOfWork::where('project_id', $theirs->id)->count());
        $this->assertSame(1, ScopeOfWork::count());
    }

    public function test_only_who_manages_the_project_prepares_and_sends_its_scope(): void
    {
        $project = $this->acceptedProject();
        $seller = User::create([
            'business_id' => $this->business->id, 'name' => 'بائع', 'email' => 'seller@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
        ]);

        $this->actingAs($seller)->get(route('admin.projects.scope.show', $project->id))->assertOk();
        $this->actingAs($seller)->post(route('admin.projects.scope.store', $project->id))->assertForbidden();
        $this->assertSame(0, ScopeOfWork::count());
    }

    /* ─────────────────────────── واتساب ─────────────────────────── */

    public function test_the_link_sent_on_whatsapp_lands_in_the_project_communications(): void
    {
        config(['services.whatsapp.token' => 'SECRET_TOKEN_X', 'services.whatsapp.phone_number_id' => '1050000']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.SOW1']]], 200)]);

        $project = $this->acceptedProject();
        $scope = $this->sentScope($project);
        Templates::save((int) $this->business->id, 'scope.link', 'whatsapp', 'ar', [
            'provider_template' => 'scope_link', 'provider_language' => 'ar', 'status' => 'active',
            'variables' => ['contact_name', 'label', 'url'],
        ], $this->owner);

        $this->actingAs($this->owner)->post(route('admin.projects.scopes.communications.send', $scope->id), [
            'channel' => 'whatsapp', 'template' => 'scope.link', 'to' => '+968 9123 4567', 'language' => 'ar',
        ])->assertSessionHasNoErrors();

        $c = Communication::sole();
        $this->assertSame(Communication::WHATSAPP, $c->channel);
        $this->assertSame(Communication::SENT, $c->status);
        $this->assertSame($scope->getMorphClass(), $c->subject_type);
        $this->assertSame($scope->id, (int) $c->subject_id);
        $this->assertSame($project->id, (int) $c->project_id);
        $this->assertSame($this->customer->id, (int) $c->customer_id);
        $this->assertSame('wamid.SOW1', $c->provider_message_id);
        $this->assertSame(PublicDocument::url($scope), $c->metadata['url']);

        Http::assertSent(fn ($request) => collect(data_get($request->data(), 'template.components.0.parameters'))->pluck('text')->contains(PublicDocument::url($scope))
            && data_get($request->data(), 'template.name') === 'scope_link'
            && ! str_contains((string) $request->body(), 'SECRET_TOKEN_X'));

        /* وتظهر في قصّة المشروع */
        $timeline = $this->actingAs($this->owner)->getJson(route('admin.projects.timeline', $project->id))->assertOk()->json('timeline');
        $this->assertNotEmpty(collect($timeline)->filter(fn ($row) => str_contains(json_encode($row, JSON_UNESCAPED_UNICODE), 'نطاق العمل')));
    }

    public function test_without_whatsapp_the_link_still_works_and_the_screen_says_why(): void
    {
        config(['services.whatsapp.token' => null, 'services.whatsapp.phone_number_id' => null]);
        $project = $this->acceptedProject();
        $scope = $this->sentScope($project);

        $this->actingAs($this->owner)->get(route('admin.projects.scope.show', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Projects/ScopeOfWork')
                ->where('channels.whatsapp', false)
                ->where('current.url', PublicDocument::url($scope))
                ->where('current.state', 'sent'));

        $this->actingAs($this->owner)->post(route('admin.projects.scopes.communications.send', $scope->id), [
            'channel' => 'whatsapp', 'template' => 'scope.link', 'to' => '+96891234567',
        ])->assertSessionHasErrors('communication');
        $this->assertSame(0, Communication::count());

        auth()->logout();
        $this->get(PublicDocument::url($scope))->assertOk();
    }

    public function test_the_link_is_not_sent_once_signed(): void
    {
        $scope = $this->signedScope($this->acceptedProject());
        Mail::fake();

        $this->actingAs($this->owner)->post(route('admin.projects.scopes.communications.send', $scope->id), [
            'channel' => 'email', 'template' => 'scope.reminder', 'to' => 'salem@energy.gov.om',
        ])->assertSessionHasErrors('communication');
        $this->assertSame(0, Communication::where('subject_type', $scope->getMorphClass())->count());
    }

    public function test_the_project_page_carries_the_scope_and_the_quotation_evidence(): void
    {
        $project = $this->acceptedProject();
        $this->signedScope($project);

        $this->actingAs($this->owner)->get(route('admin.projects.show', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Projects/Show')
                ->where('workflow.focus', 'scope')
                ->where('workflow.can_advance', true)
                ->where('scope_of_work.signed.state', 'signed')
                ->where('scope_of_work.signed.approval.verified', true)
                ->where('quotation.decided_by_name', 'سالم الهنائي')
                ->has('quotation.public_url'));
    }
}
