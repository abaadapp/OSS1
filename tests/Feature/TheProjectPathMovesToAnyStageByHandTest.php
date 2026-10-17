<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CustomerInvoice;
use App\Models\DeliveryPackage;
use App\Models\DocumentApproval;
use App\Models\EventOperation;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\ScopeOfWork;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Permissions;
use App\Support\ProjectBilling;
use App\Support\Projects;
use App\Support\ProjectWorkflow;
use App\Support\Vat;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Concerns\WalksTheProjectJourney;
use Tests\TestCase;

/**
 * مسارُ المشروع يُنقل يدويًّا إلى أيّ مرحلة — للأمام أو للخلف أو قفزًا.
 *
 * `workflow_stage` مؤشّرُ عملٍ يختاره من يملك `PROJECT_MANAGE`، لا مصدرُ
 * حقيقة: لا يُسأل عن اكتمال الحاليّة ولا السابقة، ولا يُقصّ عند القراءة.
 * وحقائقُ المراحل (تمّت · ما ينقصها · تحذير) تُعرض بجانبه ولا تحرسه. والنقلُ
 * لا يمحو مستندًا ولا يُلغي فاتورةً ولا يمسّ قيدًا، ولا يغيّر حالةَ المشروع.
 */
class TheProjectPathMovesToAnyStageByHandTest extends TestCase
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
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);
        Vat::flush();
    }

    private function move(Project $project, string $stage)
    {
        return $this->actingAs($this->owner)->post(route('admin.projects.workflow.move', $project->id), ['stage' => $stage]);
    }

    private function at(Project $project, string $stage): Project
    {
        Project::whereKey($project->id)->update(['workflow_stage' => $stage]);

        return $project->fresh();
    }

    /** @return array<string, mixed> */
    private function stage(Project $project, string $key): array
    {
        return collect(ProjectWorkflow::for($project->fresh())['stages'])->firstWhere('key', $key);
    }

    /** مشروعٌ قطع المسارَ كلَّه إلّا الفاتورةَ النهائيّة والإقفال */
    private function walkedProject(): Project
    {
        $project = $this->acceptedProject(1000, 10);
        $this->signedScope($project);
        ProjectBilling::createDeposit($project->fresh(), ['method' => 'fixed', 'amount' => 4000, 'issue' => true], $this->owner);
        $this->completedOperation($project->fresh());
        $this->acceptedDelivery($project->fresh());

        return $project->fresh();
    }

    /* ─────────────────────────── أيُّ مرحلةٍ إلى أيّ مرحلة ─────────────────────────── */

    public function test_quote_to_execution_is_allowed(): void
    {
        $project = $this->at($this->acceptedProject(1000, 10), ProjectWorkflow::QUOTE);

        $this->move($project, 'execution')->assertSessionHasNoErrors();

        $this->assertSame('execution', $project->fresh()->workflow_stage);
        $this->assertSame('execution', ProjectWorkflow::for($project->fresh())['focus']);
    }

    public function test_execution_back_to_scope_is_allowed(): void
    {
        $project = $this->at($this->acceptedProject(1000, 10), ProjectWorkflow::EXECUTION);

        $this->move($project, 'scope')->assertSessionHasNoErrors();

        $this->assertSame('scope', ProjectWorkflow::for($project->fresh())['focus']);
    }

    public function test_delivery_back_to_deposit_is_allowed(): void
    {
        $project = $this->at($this->walkedProject(), ProjectWorkflow::DELIVERY);

        $this->move($project, 'deposit')->assertSessionHasNoErrors();

        $this->assertSame('deposit', ProjectWorkflow::for($project->fresh())['focus']);
    }

    public function test_final_back_to_quote_is_allowed(): void
    {
        $project = $this->at($this->acceptedProject(1000, 10), ProjectWorkflow::FINAL);

        $this->move($project, 'quote')->assertSessionHasNoErrors();

        $this->assertSame('quote', ProjectWorkflow::for($project->fresh())['focus']);
    }

    public function test_an_unfinished_stage_can_be_skipped(): void
    {
        /* النطاقُ لم يُوقَّع والفعاليّةُ لم تُنشأ — والقفزُ إلى التسليم يجوز */
        $project = $this->acceptedProject(1000, 10);
        $this->assertSame('scope', $project->workflow_stage);

        $this->move($project, 'delivery')->assertSessionHasNoErrors();

        $flow = ProjectWorkflow::for($project->fresh());
        $this->assertSame('delivery', $flow['focus']);
        $this->assertSame(['done', 'skipped', 'skipped', 'skipped', 'current', 'todo', 'todo'], array_column($flow['stages'], 'state'));

        /* والمتجاوَزةُ تبقى ناقصةً بما ينقصها، وتحذيرٌ يُقرأ ولا يمنع */
        $scope = $this->stage($project, 'scope');
        $this->assertFalse($scope['done']);
        $this->assertSame(['لم يُعدّ نطاق العمل بعد.'], $scope['pending']);
        $this->assertSame(['تجاوزها المسار ولم تكتمل بعد — للعلم، لا يمنع شيئًا.'], $scope['warnings']);
        $this->assertSame([], $this->stage($project, 'delivery')['warnings']);
    }

    public function test_going_back_is_allowed_even_when_later_stages_are_done(): void
    {
        $project = $this->at($this->walkedProject(), ProjectWorkflow::DELIVERY);

        $this->move($project, 'scope')->assertSessionHasNoErrors();

        $flow = ProjectWorkflow::for($project->fresh());
        $this->assertSame('scope', $flow['focus']);
        $done = collect($flow['stages'])->mapWithKeys(fn ($s) => [$s['key'] => $s['done']])->all();
        $this->assertSame(['quote' => true, 'scope' => true, 'deposit' => true, 'execution' => true, 'delivery' => true, 'final' => false, 'closing' => false], $done);
        $this->assertSame('done', $flow['stages'][4]['state'], 'التسليمُ بعد الحاليّة وما زال تامًّا');
    }

    /* ─────────────────────────── المؤشّرُ لا يمسّ الحقيقة ─────────────────────────── */

    public function test_moving_deletes_no_document(): void
    {
        $project = $this->walkedProject();
        $scope = ScopeOfWork::where('project_id', $project->id)->sole();
        $count = fn () => [
            ScopeOfWork::count(), DocumentApproval::count(), CustomerInvoice::count(),
            EventOperation::count(), DeliveryPackage::count(), JournalEntry::count(),
        ];
        $before = $count();
        $package = DeliveryPackage::where('project_id', $project->id)->sole()->only(['status', 'decided_at']);

        foreach (['quote', 'closing', 'execution', 'scope', 'final', 'deposit'] as $stage) {
            $this->move($project, $stage)->assertSessionHasNoErrors();
        }

        $this->assertSame($before, $count());
        $this->assertSame(ScopeOfWork::SIGNED, $scope->fresh()->status);
        $this->assertTrue($scope->fresh('approval')->approval->verify(), 'التوقيعُ ما زال يطابق ما وُقِّع');
        $this->assertEquals($package, DeliveryPackage::where('project_id', $project->id)->sole()->only(['status', 'decided_at']), 'حزمةُ التسليم لا تُلغى');
        $this->assertSame(EventOperation::COMPLETED, EventOperation::where('project_id', $project->id)->sole()->status);
    }

    public function test_moving_cancels_no_invoice(): void
    {
        $project = $this->walkedProject();
        $deposit = CustomerInvoice::where('project_id', $project->id)->where('billing_role', ProjectBilling::DEPOSIT)->sole();
        $final = ProjectBilling::createFinal($project->fresh(), ['issue' => true], $this->owner);
        $project = $this->at($project, ProjectWorkflow::FINAL);
        $fields = ['status', 'number', 'total', 'tax_total', 'issued_at'];
        $rows = [$deposit->fresh()->only($fields), $final->fresh()->only($fields)];
        $entries = JournalEntry::count();
        $receivable = (float) CustomerInvoice::where('project_id', $project->id)->sum('total');

        $this->move($project, 'scope')->assertSessionHasNoErrors();
        $this->move($project, 'quote')->assertSessionHasNoErrors();

        $this->assertEquals($rows, [$deposit->fresh()->only($fields), $final->fresh()->only($fields)]);
        $this->assertSame(CustomerInvoice::ISSUED, $deposit->fresh()->status);
        $this->assertSame($entries, JournalEntry::count(), 'لا قيدَ يُكتب ولا يُعكس');
        $this->assertEqualsWithDelta($receivable, (float) CustomerInvoice::where('project_id', $project->id)->sum('total'), 0.0005);
        $this->assertTrue($this->stage($project, 'deposit')['done'] && $this->stage($project, 'final')['done'], 'الحقائقُ لم تتراجع مع المؤشّر');
    }

    public function test_moving_never_changes_the_project_status(): void
    {
        foreach ([Project::CONFIRMED, Project::IN_PROGRESS, Project::ON_HOLD, Project::CLOSED, Project::CANCELLED] as $status) {
            $project = $this->acceptedProject(100, 1);
            Project::whereKey($project->id)->update(['status' => $status]);

            foreach (['closing', 'execution', 'quote'] as $stage) {
                $this->move($project, $stage)->assertSessionHasNoErrors();
                $this->assertSame($status, $project->fresh()->status, "«{$stage}» على مشروعٍ {$status}");
                $this->assertSame($stage, $project->fresh()->workflow_stage);
            }
        }
    }

    public function test_entering_execution_does_not_start_the_project(): void
    {
        $project = $this->acceptedProject(1000, 10);
        $this->assertSame(Project::CONFIRMED, $project->status);

        $this->move($project, 'execution')->assertSessionHasNoErrors();

        $fresh = $project->fresh();
        $this->assertSame('execution', $fresh->workflow_stage);
        $this->assertSame(Project::CONFIRMED, $fresh->status, 'البدءُ زرُّه «بدء التنفيذ» — لا المرحلة');
        $this->assertFalse(ActivityLog::where('subject_type', 'project')->where('subject_id', $project->id)
            ->where('description', 'like', '%قيد التنفيذ%')->exists());
    }

    public function test_closing_the_project_does_not_drag_the_path_to_closing(): void
    {
        $project = $this->walkedProject();
        ProjectBilling::createFinal($project->fresh(), ['issue' => true], $this->owner);
        $project = $this->at($project, ProjectWorkflow::DELIVERY);

        Projects::close($project->fresh(), $this->owner);

        $closed = $project->fresh();
        $this->assertSame(Project::CLOSED, $closed->status);
        $this->assertSame('delivery', $closed->workflow_stage);
        $flow = ProjectWorkflow::for($closed);
        $this->assertSame('delivery', $flow['focus'], 'المُقفَلُ يبقى على آخر اختيارٍ يدويّ');
        $this->assertTrue($flow['stages'][6]['done'], 'و«الإغلاق» تامّةٌ من حقيقتها');

        /* ومن يدير المشروعَ ينقله بعد الإقفال إن شاء */
        $this->move($closed, 'closing')->assertSessionHasNoErrors();
        $this->assertSame('closing', ProjectWorkflow::for($closed->fresh())['focus']);
        $this->assertSame(Project::CLOSED, $closed->fresh()->status);
    }

    /* ─────────────────────────── ما يُسأل عنه وحدَه ─────────────────────────── */

    public function test_whoever_does_not_manage_projects_gets_403(): void
    {
        $project = $this->acceptedProject(1000, 10);
        $seller = User::create([
            'business_id' => $this->business->id, 'name' => 'بائع', 'email' => 'seller@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
        ]);
        $this->assertFalse($seller->may(Permissions::PROJECT_MANAGE));

        $this->actingAs($seller)->post(route('admin.projects.workflow.move', $project->id), ['stage' => 'final'])->assertForbidden();
        $this->assertSame('scope', $project->fresh()->workflow_stage);

        $this->expectException(AuthorizationException::class);
        ProjectWorkflow::moveTo($project, 'final', $seller);
    }

    public function test_an_unknown_stage_is_refused(): void
    {
        $project = $this->acceptedProject(1000, 10);

        foreach (['archive', '', 'Scope'] as $stage) {
            $this->move($project, $stage)->assertSessionHasErrors(['workflow' => 'مرحلةٌ غير معروفة في مسار المشروع.']);
        }
        $this->actingAs($this->owner)->post(route('admin.projects.workflow.move', $project->id), ['stage' => ['final']])->assertSessionHasErrors('workflow');
        $this->actingAs($this->owner)->post(route('admin.projects.workflow.move', $project->id))->assertSessionHasErrors('workflow');
        $this->assertSame('scope', $project->fresh()->workflow_stage);

        $this->expectException(InvalidArgumentException::class);
        ProjectWorkflow::moveTo($project, 'archive', $this->owner);
    }

    public function test_another_companys_project_cannot_be_moved(): void
    {
        $project = $this->acceptedProject(1000, 10);
        $mine = $this->owner;

        $this->journeyCompany('شركة أخرى');
        $this->actingAs($this->owner)->post(route('admin.projects.workflow.move', $project->id), ['stage' => 'final'])->assertNotFound();
        $this->assertSame('scope', $project->fresh()->workflow_stage);

        try {
            ProjectWorkflow::moveTo($project, 'final', $this->owner);
            $this->fail('مشروعُ شركةٍ أخرى نُقل');
        } catch (ModelNotFoundException) {
            $this->assertSame('scope', $project->fresh()->workflow_stage);
        }

        /* وصاحبُه ينقله */
        $this->actingAs($mine);
        ProjectWorkflow::moveTo($project, 'final', $mine);
        $this->assertSame('final', $project->fresh()->workflow_stage);
    }

    public function test_every_move_is_written_to_the_activity_log(): void
    {
        $project = $this->acceptedProject(1000, 10);
        $log = fn () => ActivityLog::where('subject_type', 'project')->where('subject_id', $project->id)
            ->where('description', 'like', 'نقل المشروع %يدويًا%')->orderBy('id')->pluck('description')->all();

        $this->move($project, 'execution')->assertSessionHasNoErrors();   // قفزٌ للأمام
        $this->move($project, 'quote')->assertSessionHasNoErrors();       // رجوعٌ فوق مراحل
        $this->move($project, 'scope')->assertSessionHasNoErrors();       // خطوةٌ واحدة
        $this->move($project, 'scope')->assertSessionHasNoErrors();       // المرحلةُ نفسُها: لا سطر

        $code = $project->code;
        $this->assertSame([
            "نقل المشروع {$code} يدويًا من «نطاق العمل» إلى «التنفيذ»",
            "نقل المشروع {$code} يدويًا من «التنفيذ» إلى «عرض السعر»",
            "نقل المشروع {$code} يدويًا من «عرض السعر» إلى «نطاق العمل»",
        ], $log());
        $this->assertSame($this->owner->id, (int) ActivityLog::where('subject_id', $project->id)->where('subject_type', 'project')->latest('id')->value('user_id'));
    }

    /* ─────────────────────────── القراءة ─────────────────────────── */

    public function test_for_shows_the_stage_that_was_actually_chosen(): void
    {
        $project = $this->at($this->acceptedProject(1000, 10), ProjectWorkflow::FINAL);

        $flow = ProjectWorkflow::for($project);

        $this->assertSame('final', $flow['focus'], 'مؤشّرٌ كُتب بيدٍ هو «الآن» — لا يُقصّ');
        $this->assertSame('current', $flow['stages'][5]['state']);
        $this->assertTrue($flow['stages'][5]['focus']);
        $this->assertSame(array_fill(0, 7, true), array_column($flow['stages'], 'reachable'));
        foreach (['can_advance', 'can_back', 'back_blocked'] as $gone) {
            $this->assertArrayNotHasKey($gone, $flow);
        }
        $this->assertArrayNotHasKey('blocked_by', $flow['stages'][6]);

        /* وبطاقةُ القائمة وصفحةُ المشروع تقولان المرحلةَ نفسَها */
        $this->actingAs($this->owner);
        $card = collect($this->get(route('admin.projects.index'))->assertOk()->viewData('page')['props']['projects'])->firstWhere('id', $project->id);
        $this->assertSame('final', $card['workflow']['focus']);
        $this->assertSame('final', $this->get(route('admin.projects.show', $project->id))->viewData('page')['props']['workflow']['focus']);
    }

    public function test_the_frontier_is_information_and_never_cuts_the_focus(): void
    {
        $project = $this->acceptedProject(1000, 10);
        $this->signedScope($project);
        $invoice = ProjectBilling::createDeposit($project->fresh(), ['method' => 'fixed', 'amount' => 4000, 'issue' => true], $this->owner);
        $project = $this->at($project, ProjectWorkflow::FINAL);

        $flow = ProjectWorkflow::for($project);
        $this->assertSame('execution', $flow['frontier'], 'أوّلُ ما لم يتمّ');
        $this->assertSame('final', $flow['focus']);

        /* فاتورةُ الدفعة تُلغى من بابها — فتعود «الدفعة» ناقصةً، والمؤشّرُ حيث اختاره الفريق */
        CustomerInvoices::cancel($invoice->fresh(), 'خطأ في المبلغ', $this->owner->id);
        $flow = ProjectWorkflow::for($project->fresh());
        $this->assertSame('deposit', $flow['frontier']);
        $this->assertSame('final', $flow['focus']);
        $this->assertSame('skipped', $flow['stages'][2]['state']);
        $this->assertSame('final', $project->fresh()->workflow_stage);

        /* وبلا مؤشّرٍ مكتوبٍ أصلًا — يُقرأ أوّلُ ما لم يتمّ مكانَه */
        Project::whereKey($project->id)->update(['workflow_stage' => null]);
        $this->assertSame('deposit', ProjectWorkflow::for($project->fresh())['focus']);
    }
}
