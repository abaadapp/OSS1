<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CustomerInvoice;
use App\Models\DocumentApproval;
use App\Models\EventOperation;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\ScopeOfWork;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\DeliveryPackages;
use App\Support\Import\Provenance;
use App\Support\Operations\EventOperations;
use App\Support\ProjectBilling;
use App\Support\Projects;
use App\Support\ProjectWorkflow;
use App\Support\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\WalksTheProjectJourney;
use Tests\TestCase;

/**
 * المشروعُ يمشي سبعَ مراحل — وكلُّ مرحلةٍ تتمّ بمستندها لا بزرّ.
 *
 *   عرض السعر → نطاق العمل → الدفعة المقدّمة → التنفيذ → التسليم →
 *   الفاتورة النهائيّة → الإغلاق
 *
 * و«تم — الانتقال» لا يتقدّم قبل أن تتمّ المرحلة، و«رجوع» لا يمحو فاتورةً
 * ولا توقيعًا ولا قيدًا. والمؤشّرُ لا يتجاوز الحقيقة: فاتورةُ دفعةٍ تُلغى
 * تُعيده إليها. والإقفالُ يسأل عن النطاق الموقَّع والتنفيذ المكتمل والفوترة
 * الكاملة فوق موانعه القديمة. وبطاقةُ القائمة وصفحةُ المشروع مصدرُهما واحد.
 */
class TheProjectWalksSevenStagesFromQuoteToCloseTest extends TestCase
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

    /** @return array<string, bool> */
    private function done(Project $project): array
    {
        return collect(ProjectWorkflow::for($project->fresh())['stages'])->mapWithKeys(fn ($s) => [$s['key'] => $s['done']])->all();
    }

    private function stage(Project $project, string $key): array
    {
        return collect(ProjectWorkflow::for($project->fresh())['stages'])->firstWhere('key', $key);
    }

    private function deposit(Project $project, float $amount = 4000): CustomerInvoice
    {
        return ProjectBilling::createDeposit($project->fresh(), ['method' => 'fixed', 'amount' => $amount, 'issue' => true], $this->owner);
    }

    /* ─────────────────────────── المراحلُ من حقيقتها ─────────────────────────── */

    public function test_each_stage_is_done_by_its_own_document_not_by_a_button(): void
    {
        $project = $this->acceptedProject(1000, 10);
        $this->assertSame(['quote' => true, 'scope' => false, 'deposit' => false, 'execution' => false, 'delivery' => false, 'final' => false, 'closing' => false], $this->done($project));

        $this->signedScope($project);
        $this->assertTrue($this->done($project)['scope']);

        $this->deposit($project);
        $this->assertTrue($this->done($project)['deposit']);

        $operation = EventOperations::create($project->fresh(), ['name' => 'تشغيل', 'event_starts_at' => now()->addDays(20)->format('Y-m-d 09:00')], $this->owner);
        $this->assertFalse($this->done($project)['execution']);
        $this->assertStringContainsString($operation->code, $this->stage($project, 'execution')['pending'][0]);
        $operation->forceFill(['status' => EventOperation::COMPLETED, 'completed_at' => now()])->save();
        $this->assertTrue($this->done($project)['execution']);

        $this->acceptedDelivery($project->fresh());
        $this->assertTrue($this->done($project)['delivery']);

        ProjectBilling::createFinal($project->fresh(), ['issue' => true], $this->owner);
        $this->assertTrue($this->done($project)['final']);

        Projects::close($project->fresh(), $this->owner);
        $this->assertSame(array_fill_keys(ProjectWorkflow::STAGES, true), $this->done($project));
        $this->assertSame('closing', ProjectWorkflow::for($project->fresh())['focus']);
    }

    public function test_execution_reads_the_event_operations_not_a_button(): void
    {
        $project = $this->acceptedProject(1000, 10);
        $this->assertSame(['لا فعاليّة تشغيليّة على هذا المشروع — أنشئ فعاليّة التنفيذ وأتمّها.'], $this->stage($project, 'execution')['pending']);

        $first = EventOperations::create($project->fresh(), ['name' => 'اليوم الأوّل'], $this->owner);
        $second = EventOperations::create($project->fresh(), ['name' => 'اليوم الثاني'], $this->owner);
        $first->forceFill(['status' => EventOperation::COMPLETED])->save();
        $this->assertFalse($this->done($project)['execution'], 'فعاليّةٌ قائمةٌ لم تكتمل');

        EventOperations::cancel($second->fresh(), 'أُلغي اليوم الثاني', $this->owner);
        $this->assertTrue($this->done($project)['execution'], 'المكتملةُ والملغاةُ تُتمّان التنفيذ');

        $first->forceFill(['status' => EventOperation::CANCELLED])->save();
        $this->assertFalse($this->done($project)['execution'], 'الملغاةُ وحدها لا تُنفّذ شيئًا');
    }

    public function test_delivery_reads_the_delivery_packages(): void
    {
        $project = $this->acceptedProject(1000, 10);
        $this->assertFalse($this->done($project)['delivery']);

        $package = DeliveryPackages::create((int) $this->business->id, null, ['project_id' => $project->id], $this->owner);
        DeliveryPackages::addItem($package, ['kind' => 'file', 'title' => 'تقرير'], UploadedFile::fake()->create('r.pdf', 5, 'application/pdf'), $this->owner);
        DeliveryPackages::send(DeliveryPackages::ready($package->fresh(), $this->owner), $this->owner);
        $this->assertStringContainsString('تنتظر قرار العميل', implode(' ', $this->stage($project, 'delivery')['pending']));

        DeliveryPackages::recordDecision($package->fresh(), 'accepted', ['decided_by_name' => 'سالم'], $this->owner);
        $this->assertTrue($this->done($project)['delivery']);
    }

    /* ─────────────────────────── تقدّمٌ ورجوع ─────────────────────────── */

    public function test_advancing_needs_a_finished_stage_and_entering_execution_starts_the_project(): void
    {
        $project = $this->acceptedProject(1000, 10);
        $this->actingAs($this->owner);

        $this->post(route('admin.projects.workflow.advance', $project->id))->assertSessionHasErrors('workflow');
        $this->assertSame('scope', $project->fresh()->workflow_stage);

        $this->signedScope($project);
        $this->actingAs($this->owner)->post(route('admin.projects.workflow.advance', $project->id))->assertSessionHasNoErrors();
        $this->assertSame('deposit', $project->fresh()->workflow_stage);
        $this->assertTrue(ActivityLog::where('description', 'like', '%من «نطاق العمل» إلى «الدفعة المقدمة»%')->exists());

        $this->post(route('admin.projects.workflow.advance', $project->id))->assertSessionHasErrors('workflow');

        $this->post(route('admin.projects.billing.waive', $project->id), ['reason' => 'يدفع بعد التسليم'])->assertSessionHasNoErrors();
        $this->post(route('admin.projects.workflow.advance', $project->id))->assertSessionHasNoErrors();

        $fresh = $project->fresh();
        $this->assertSame('execution', $fresh->workflow_stage);
        $this->assertSame(Project::IN_PROGRESS, $fresh->status, 'الدخولُ إلى التنفيذ يبدأه');
    }

    public function test_going_back_reviews_and_erases_nothing(): void
    {
        $project = $this->acceptedProject(1000, 10);
        $scope = $this->signedScope($project);
        $invoice = $this->deposit($project);
        $project->update(['workflow_stage' => 'execution']);

        $entries = JournalEntry::count();
        $invoiceRow = $invoice->fresh()->only(['status', 'number', 'total', 'issued_at']);
        $this->actingAs($this->owner)->post(route('admin.projects.workflow.back', $project->id))->assertSessionHasNoErrors();
        $this->assertSame('deposit', ProjectWorkflow::for($project->fresh())['focus']);

        /* خطوةٌ واحدةٌ لا اثنتان: مؤشّرٌ أبعدُ من ذلك يجعل البطاقةَ تقول غير الحقيقة */
        $this->actingAs($this->owner)->post(route('admin.projects.workflow.back', $project->id))
            ->assertSessionHasErrors(['workflow' => 'المسار على مرحلةٍ تامّة قبل التي يُعمل عليها — الرجوع خطوةٌ واحدة. لمراجعة ما قبلها افتحها من الشريط.']);
        $this->assertSame('deposit', $project->fresh()->workflow_stage);

        /* لا فاتورةٌ أُلغيت ولا تغيّرت، ولا توقيعٌ نُقض، ولا قيدٌ عُكس */
        $this->assertEquals($invoiceRow, $invoice->fresh()->only(['status', 'number', 'total', 'issued_at']));
        $this->assertSame(ScopeOfWork::SIGNED, $scope->fresh()->status);
        $this->assertSame(1, DocumentApproval::where('approvable_type', $scope->getMorphClass())->count());
        $this->assertTrue($scope->fresh('approval')->approval->verify());
        $this->assertSame($entries, JournalEntry::count());
        $this->assertTrue(ActivityLog::where('description', 'like', '%ولم يُمحَ مستندٌ ولا توقيع%')->exists());

        /* والمراحلُ ما زالت تامّةً — والعودةُ إلى الأمام بزرّها */
        $this->assertTrue($this->done($project)['scope'] && $this->done($project)['deposit']);
        $this->actingAs($this->owner)->post(route('admin.projects.workflow.advance', $project->id))->assertSessionHasNoErrors();
        $this->assertSame('execution', $project->fresh()->workflow_stage);
    }

    public function test_going_back_is_refused_once_a_later_stage_is_already_done(): void
    {
        $project = $this->acceptedProject(1000, 10);
        $this->signedScope($project);
        $this->deposit($project);
        $project->update(['workflow_stage' => 'execution']);

        /* التسليمُ قُبل والتنفيذُ لم يكتمل — الحقيقةُ تقدّمت على المؤشّر */
        $this->acceptedDelivery($project->fresh());
        $flow = ProjectWorkflow::for($project->fresh());
        $this->assertSame('execution', $flow['focus']);
        $this->assertFalse($flow['can_back']);

        $this->actingAs($this->owner)->post(route('admin.projects.workflow.back', $project->id))
            ->assertSessionHasErrors(['workflow' => 'تمّت مرحلةٌ بعد هذه — الرجوع الآن يجعل المسار يقول غير الحقيقة. افتح المرحلة من الشريط لمراجعتها.']);
        $this->assertSame('execution', $project->fresh()->workflow_stage);
    }

    public function test_the_pointer_never_lags_the_truth_by_more_than_one_stage(): void
    {
        $project = $this->acceptedProject(1000, 10);
        $this->signedScope($project);
        $this->deposit($project);
        $this->completedOperation($project->fresh());
        $this->acceptedDelivery($project->fresh());

        /* المؤشّرُ المكتوبُ ما زال على «نطاق العمل» — والحقيقةُ عند الفاتورة النهائيّة */
        $this->assertSame('scope', $project->fresh()->workflow_stage);
        $flow = ProjectWorkflow::for($project->fresh());
        $this->assertSame('delivery', $flow['focus'], 'تامّةٌ تنتظر الانتقال — لا «نطاق العمل» وقد تمّ التنفيذ والتسليم');
        $this->assertSame('done', collect($flow['stages'])->firstWhere('key', 'delivery')['state']);
        $this->assertSame('final', $flow['next']);
        $this->assertTrue($flow['can_advance']);

        $this->actingAs($this->owner)->post(route('admin.projects.workflow.advance', $project->id))->assertSessionHasNoErrors();
        $this->assertSame('final', ProjectWorkflow::for($project->fresh())['focus']);
    }

    public function test_the_pointer_never_runs_past_the_truth(): void
    {
        $project = $this->acceptedProject(1000, 10);
        $project->update(['workflow_stage' => 'final']);
        $this->assertSame('scope', ProjectWorkflow::for($project->fresh())['focus'], 'مؤشّرٌ كُتب بيدٍ لا يتجاوز أوّلَ ما لم يتمّ');

        $this->signedScope($project);
        $invoice = $this->deposit($project);
        $project->update(['workflow_stage' => 'execution']);
        $this->assertSame('execution', ProjectWorkflow::for($project->fresh())['focus']);

        /* فاتورةُ الدفعة تُلغى من بابها — فيعود المسارُ إلى مرحلتها وحدَه */
        CustomerInvoices::cancel($invoice->fresh(), 'خطأ في المبلغ', $this->owner->id);
        $flow = ProjectWorkflow::for($project->fresh());
        $this->assertSame('deposit', $flow['focus']);
        $this->assertSame('current', $flow['stages'][2]['state']);
        $this->assertSame('execution', $project->fresh()->workflow_stage, 'والمؤشّرُ المكتوبُ لم يُمسّ — القراءةُ تقصّه');
    }

    public function test_a_cancelled_project_shows_where_it_stopped(): void
    {
        $project = $this->acceptedProject(1000, 10);
        Projects::cancel($project->fresh(), 'ألغى العميل الفعالية', $this->owner, 'ضمن المهلة');
        $flow = ProjectWorkflow::for($project->fresh());

        $this->assertSame('failed', $flow['stages'][1]['state']);
        $this->assertSame('ملغى', $flow['stages'][1]['note']);
        $this->assertFalse($flow['can_advance']);
        $this->assertFalse($flow['can_back']);
        $this->actingAs($this->owner)->post(route('admin.projects.workflow.advance', $project->id))->assertSessionHasErrors('workflow');
    }

    /* ─────────────────────────── الإغلاق ─────────────────────────── */

    public function test_the_close_waits_for_a_signed_scope_a_finished_execution_and_the_whole_value_billed(): void
    {
        $project = $this->acceptedProject(1000, 10);
        $this->acceptedDelivery($project);

        $blockers = implode(' | ', Projects::closeBlockers($project->fresh()));
        $this->assertStringContainsString('لم يوقّع العميل نطاق العمل', $blockers);
        $this->assertStringContainsString('لا فعاليّة تشغيليّة', $blockers);
        $this->assertStringContainsString('لم تُفوتر قيمة المشروع كلّها — باقٍ 10,000.000', $blockers);
        $this->actingAs($this->owner)->post(route('admin.projects.close', $project->id))->assertSessionHasErrors('project');

        $this->signedScope($project);
        $this->completedOperation($project);
        $this->assertSame(1, count(Projects::closeBlockers($project->fresh())), 'الفوترةُ وحدها باقية');

        ProjectBilling::createFinal($project->fresh(), ['issue' => true], $this->owner);
        $this->assertSame([], Projects::closeBlockers($project->fresh()));

        $this->actingAs($this->owner)->post(route('admin.projects.close', $project->id))->assertSessionHasNoErrors();
        $closed = $project->fresh();
        $this->assertSame(Project::CLOSED, $closed->status);
        $this->assertEqualsWithDelta(10000.0, (float) $closed->financials_frozen['net_invoiced'], 0.0005, 'الأرقامُ تُجمَّد عند الإقفال');
    }

    public function test_a_historical_project_is_not_asked_for_a_scope_or_an_event_it_never_had(): void
    {
        $project = Project::create([
            'business_id' => $this->business->id, 'customer_id' => $this->customer->id, 'code' => 'EP-2025-001',
            'name' => 'مشروع قديم', 'status' => Project::IN_PROGRESS, 'quoted_value' => 0, 'approved_value' => 0,
            'origin' => Provenance::HISTORICAL,
        ]);

        $scope = $this->stage($project, 'scope');
        $this->assertTrue($scope['done']);
        $this->assertSame('مشروعٌ مستورَد — بلا نطاق موقَّع', $scope['note']);
        $this->assertTrue($this->done($project)['execution']);

        $blockers = implode(' | ', Projects::closeBlockers($project->fresh()));
        $this->assertStringNotContainsString('نطاق العمل', $blockers);
        $this->assertStringNotContainsString('فعاليّة', $blockers);
    }

    /* ─────────────────────────── مصدرٌ واحد ─────────────────────────── */

    public function test_the_list_card_and_the_project_page_read_the_same_path(): void
    {
        $waiting = $this->acceptedProject(1000, 10);
        $signed = $this->acceptedProject(500, 4);
        $this->signedScope($signed);
        $this->deposit($signed, 500);
        $signed->update(['workflow_stage' => 'deposit']);

        $this->actingAs($this->owner);
        $cards = collect($this->get(route('admin.projects.index'))->assertOk()
            ->viewData('page')['props']['projects'])->keyBy('id');

        foreach ([$waiting, $signed] as $project) {
            $page = ProjectWorkflow::for($project->fresh());
            $card = $cards[$project->id]['workflow'];
            $this->assertSame($page['focus'], $card['focus']);
            $this->assertSame(array_column($page['stages'], 'state'), array_column($card['stages'], 'state'));

            $this->get(route('admin.projects.show', $project->id))->assertInertia(fn (AssertableInertia $p) => $p
                ->where('workflow.focus', $card['focus']));
        }

        $this->assertSame('scope', $cards[$waiting->id]['workflow']['focus']);
        $this->assertSame('deposit', $cards[$signed->id]['workflow']['focus']);
        $this->assertTrue($cards[$signed->id]['workflow']['can_advance'], 'الدفعةُ صدرت — «تم — الانتقال» على البطاقة');
    }

    public function test_only_project_managers_move_the_path_and_never_on_another_company(): void
    {
        $project = $this->acceptedProject(1000, 10);
        $this->signedScope($project);
        $seller = User::create([
            'business_id' => $this->business->id, 'name' => 'بائع', 'email' => 'seller@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
        ]);

        $this->actingAs($seller)->post(route('admin.projects.workflow.advance', $project->id))->assertForbidden();
        $this->actingAs($seller)->post(route('admin.projects.workflow.back', $project->id))->assertForbidden();

        $mine = $this->business;
        $this->journeyCompany('شركة أخرى');
        $this->actingAs($this->owner)->post(route('admin.projects.workflow.advance', $project->id))->assertNotFound();

        $this->assertSame('scope', $project->fresh()->workflow_stage);
        $this->assertNotNull($mine);
    }
}
