<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CustomerInvoice;
use App\Models\EventOperation;
use App\Models\Project;
use App\Models\ScopeOfWork;
use App\Models\Setting;
use App\Support\ProjectBilling;
use App\Support\ProjectWorkflow;
use App\Support\PublicDocument;
use App\Support\ScopesOfWork;
use App\Support\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\WalksTheProjectJourney;
use Tests\TestCase;

/**
 * المرحلةُ يدويّةٌ ١٠٠٪ — لا مستندَ يحرّكها من نفسه.
 *
 * كتابةُ نطاق العمل وإرسالُه وتوقيعُه، وإصدارُ الدفعة، وإنشاءُ الفعاليّة
 * وإتمامُها، وقبولُ التسليم، وإصدارُ النهائيّة — كلُّها تُحدّث حقائقَ المراحل
 * (تمّت · ما ينقص) ولا تمسّ `workflow_stage`. والمؤشّرُ لا يتغيّر إلّا من
 * `admin.projects.workflow.move`.
 */
class NoDocumentMovesTheProjectStageByItselfTest extends TestCase
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

    /** المؤشّرُ المكتوبُ والمقروءُ معًا على المرحلة */
    private function assertStaysOn(string $stage, Project $project, string $after): void
    {
        $this->assertSame($stage, $project->fresh()->workflow_stage, "{$after} — المؤشّرُ المكتوب");
        $this->assertSame($stage, ProjectWorkflow::for($project->fresh())['focus'], "{$after} — المرحلةُ الحاليّة");
    }

    /** @return array<string, bool> */
    private function done(Project $project): array
    {
        return collect(ProjectWorkflow::for($project->fresh())['stages'])->mapWithKeys(fn ($s) => [$s['key'] => $s['done']])->all();
    }

    /** @return array<string, mixed> */
    private function stage(Project $project, string $key): array
    {
        return collect(ProjectWorkflow::for($project->fresh())['stages'])->firstWhere('key', $key);
    }

    private function onStage(Project $project, string $stage): Project
    {
        Project::whereKey($project->id)->update(['workflow_stage' => $stage]);

        return $project->fresh();
    }

    /** نطاقٌ يُكتب ويُرسَل من شاشته، ويوقّعه العميل من رابطه */
    private function scopeFromItsScreens(Project $project): ScopeOfWork
    {
        $at = (string) $project->fresh()->workflow_stage;
        $this->actingAs($this->owner)->post(route('admin.projects.scope.store', $project->id))->assertSessionHasNoErrors();
        $this->assertStaysOn($at, $project, 'كتابةُ النطاق');
        $scope = ScopeOfWork::where('project_id', $project->id)->latest('id')->firstOrFail();

        ScopesOfWork::update($scope, [
            'description' => 'تشغيل نظام التسجيل في يومي المؤتمر.', 'inclusions' => 'أجهزة التسجيل', 'exclusions' => 'الإنترنت',
            'company_responsibilities' => 'فريقٌ فنيّ', 'client_responsibilities' => 'قائمة المدعوين',
        ], $this->owner);
        $this->actingAs($this->owner)->post(route('admin.projects.scope.ready', [$project->id, $scope->id]))->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('admin.projects.scope.send', [$project->id, $scope->id]))->assertSessionHasNoErrors();
        $this->assertStaysOn($at, $project, 'إرسالُ النطاق');

        $scope = $scope->fresh();
        auth()->logout();
        $this->post(PublicDocument::url($scope).'/decision', [
            'decision' => 'accepted', 'name' => 'سالم الهنائي', 'title' => 'مدير الفعاليات',
            'email' => 'salem@energy.gov.om', 'phone' => '+96891234567', 'consent' => '1', 'hash' => $scope->content_hash,
        ])->assertSessionHasNoErrors();
        $this->assertSame(ScopeOfWork::SIGNED, $scope->fresh()->status);

        return $scope->fresh();
    }

    private function depositFromItsScreen(Project $project): CustomerInvoice
    {
        $this->actingAs($this->owner)->post(route('admin.projects.billing.deposit', $project->id), ['method' => 'percent', 'percent' => 50, 'issue' => '1'])
            ->assertSessionHasNoErrors();

        return CustomerInvoice::where('project_id', $project->id)->where('billing_role', ProjectBilling::DEPOSIT)->sole();
    }

    private function eventFromItsScreen(Project $project): EventOperation
    {
        $at = (string) $project->fresh()->workflow_stage;
        $this->actingAs($this->owner)->post(route('admin.operations.store'), [
            'project_id' => $project->id, 'name' => 'تشغيل المؤتمر', 'event_starts_at' => now()->addDays(20)->format('Y-m-d 09:00'),
        ])->assertSessionHasNoErrors();
        $operation = EventOperation::where('project_id', $project->id)->sole();
        $this->assertStaysOn($at, $project, 'إنشاءُ الفعاليّة');

        /* الإتمامُ بسلسلة جاهزيّته اختبارُه في Operations — وهنا يُكتب «مكتملة» تحضيرًا */
        $operation->forceFill(['status' => EventOperation::COMPLETED, 'completed_at' => now()])->save();

        return $operation->fresh();
    }

    private function finalFromItsScreen(Project $project): CustomerInvoice
    {
        $this->actingAs($this->owner)->post(route('admin.projects.billing.final', $project->id), ['issue' => '1'])->assertSessionHasNoErrors();

        return CustomerInvoice::where('project_id', $project->id)->where('billing_role', ProjectBilling::FINAL)->sole();
    }

    /* ─────────────────────────── ١ · المشروعُ على «نطاق العمل» ─────────────────────────── */

    public function test_an_accepted_project_starts_on_scope_and_opening_its_screens_does_not_move_it(): void
    {
        $project = $this->acceptedProject(1000, 10);
        $this->assertStaysOn('scope', $project, 'قبولُ العرض');

        $this->actingAs($this->owner);
        $this->get(route('admin.projects.show', $project->id))->assertOk();
        $this->get(route('admin.projects.index'))->assertOk();
        $this->get(route('admin.projects.scope.show', $project->id))->assertOk();
        $this->assertStaysOn('scope', $project, 'فتحُ الشاشات');
    }

    /* ─────────────────────────── ٢ · نطاقُ العمل ─────────────────────────── */

    public function test_writing_sending_and_signing_the_scope_does_not_move_it(): void
    {
        $onScope = $this->acceptedProject(1000, 10);
        $this->scopeFromItsScreens($onScope);
        $this->assertStaysOn('scope', $onScope, 'توقيعُ النطاق');
        $this->assertTrue($this->done($onScope)['scope']);

        /* ومن قبله: مؤشّرٌ على «عرض السعر» لا يلحق بالنطاق حين يُكتب ويُرسَل */
        $onQuote = $this->onStage($this->acceptedProject(500, 4), 'quote');
        $this->scopeFromItsScreens($onQuote);
        $this->assertStaysOn('quote', $onQuote, 'كتابةُ النطاق وإرسالُه وتوقيعُه');
        $this->assertTrue($this->done($onQuote)['scope']);
    }

    /* ─────────────────────────── ٣ · الدفعة المقدّمة ─────────────────────────── */

    public function test_issuing_the_deposit_does_not_move_it(): void
    {
        $project = $this->acceptedProject(1000, 10);
        $this->signedScope($project);

        $invoice = $this->depositFromItsScreen($project);

        $this->assertSame(CustomerInvoice::ISSUED, $invoice->status);
        $this->assertStaysOn('scope', $project, 'إصدارُ الدفعة');
        $this->assertTrue($this->done($project)['deposit']);
    }

    /* ─────────────────────────── ٤ · الفعاليّة ─────────────────────────── */

    public function test_creating_and_completing_an_event_does_not_move_it(): void
    {
        $project = $this->acceptedProject(1000, 10);

        $this->eventFromItsScreen($project);

        $this->assertStaysOn('scope', $project, 'إتمامُ الفعاليّة');
        $this->assertTrue($this->done($project)['execution']);
    }

    /* ─────────────────────────── ٥ · التسليم ─────────────────────────── */

    public function test_accepting_the_delivery_does_not_move_it(): void
    {
        $project = $this->acceptedProject(1000, 10);

        $this->acceptedDelivery($project);

        $this->assertStaysOn('scope', $project, 'قبولُ التسليم');
        $this->assertTrue($this->done($project)['delivery']);
    }

    /* ─────────────────────────── ٦ · الفاتورة النهائيّة ─────────────────────────── */

    public function test_issuing_the_final_invoice_does_not_move_it(): void
    {
        $project = $this->acceptedProject(1000, 10);
        $this->signedScope($project);
        $this->depositFromItsScreen($project);
        $this->completedOperation($project->fresh());
        $this->acceptedDelivery($project->fresh());

        $final = $this->finalFromItsScreen($project);

        $this->assertSame(CustomerInvoice::ISSUED, $final->status);
        $this->assertStaysOn('scope', $project, 'إصدارُ النهائيّة');
        $this->assertTrue($this->done($project)['final']);
    }

    /* ─────────────────────────── ٧ · بابٌ واحد ─────────────────────────── */

    public function test_the_stage_changes_only_by_the_manual_move(): void
    {
        $project = $this->acceptedProject(1000, 10);
        $moves = fn () => ActivityLog::where('subject_type', 'project')->where('subject_id', $project->id)
            ->where('description', 'like', 'نقل المشروع %')->count();

        $this->scopeFromItsScreens($project);
        $this->depositFromItsScreen($project);
        $this->eventFromItsScreen($project);
        $this->acceptedDelivery($project->fresh());
        $this->finalFromItsScreen($project);

        $this->assertStaysOn('scope', $project, 'المسارُ كلُّه بمستنداته');
        $this->assertSame(0, $moves(), 'لا نقلَ في السجلّ لم يطلبه أحد');
        $this->assertSame(Project::CONFIRMED, $project->fresh()->status, 'والحالةُ لم يمسّها شيءٌ من هذا');

        $this->actingAs($this->owner)->post(route('admin.projects.workflow.move', $project->id), ['stage' => 'final'])->assertSessionHasNoErrors();
        $this->assertStaysOn('final', $project, 'النقلُ اليدويّ');
        $this->assertSame(1, $moves());
    }

    public function test_no_code_writes_the_stage_except_the_manual_move_the_first_value_and_the_demo(): void
    {
        $this->assertFalse(method_exists(ProjectWorkflow::class, 'touch'), 'لا لحاقَ تلقائيّ');

        /*
         * من يكتب `workflow_stage` في `app/`: `moveTo` وحدَه — ومعه القيمةُ الأولى
         * حين يولد المشروعُ من عرضه (`Projects`)، وبذرةُ العرض التجريبيّ (`DemoEvents`).
         */
        $writers = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match("/'workflow_stage'\s*=>/", (string) file_get_contents($file->getPathname()))) {
                $writers[] = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }
        sort($writers);

        $this->assertSame(['Support/DemoEvents.php', 'Support/ProjectWorkflow.php', 'Support/Projects.php'], $writers);
        $this->assertSame(1, preg_match_all("/'workflow_stage'\s*=>/", (string) file_get_contents(app_path('Support/ProjectWorkflow.php'))), 'وفي ProjectWorkflow كتابةٌ واحدة: moveTo');
    }

    /* ─────────────────────────── ٨ · الحقائقُ تمشي والمؤشّرُ مكانه ─────────────────────────── */

    public function test_the_facts_follow_the_documents_while_the_pointer_stays(): void
    {
        $project = $this->acceptedProject(1000, 10);
        $facts = fn () => [ProjectWorkflow::for($project->fresh())['frontier'], $this->done($project)];
        $none = ['quote' => true, 'scope' => false, 'deposit' => false, 'execution' => false, 'delivery' => false, 'final' => false, 'closing' => false];
        $with = fn (string ...$done) => array_replace($none, array_fill_keys($done, true));

        $this->assertSame(['scope', $none], $facts());
        $this->assertSame(['لم يُعدّ نطاق العمل بعد.'], $this->stage($project, 'scope')['pending']);

        $this->scopeFromItsScreens($project);
        $this->assertSame(['deposit', $with('scope')], $facts());
        $this->assertSame([], $this->stage($project, 'scope')['pending']);
        $this->assertSame(['لم تُصدر فاتورة الدفعة المقدمة بعد.'], $this->stage($project, 'deposit')['pending']);

        $this->depositFromItsScreen($project);
        $this->assertSame(['execution', $with('scope', 'deposit')], $facts());
        $this->assertSame([], $this->stage($project, 'deposit')['pending']);

        $this->eventFromItsScreen($project);
        $this->assertSame(['delivery', $with('scope', 'deposit', 'execution')], $facts());
        $this->assertSame([], $this->stage($project, 'execution')['pending']);
        $this->assertNotSame([], $this->stage($project, 'delivery')['pending']);

        $this->acceptedDelivery($project->fresh());
        $this->assertSame(['final', $with('scope', 'deposit', 'execution', 'delivery')], $facts());
        $this->assertSame(['لم تُفوتر قيمة المشروع كلّها بعد.'], $this->stage($project, 'final')['pending']);

        $this->finalFromItsScreen($project);
        $this->assertSame(['closing', $with('scope', 'deposit', 'execution', 'delivery', 'final')], $facts());
        $this->assertSame([], $this->stage($project, 'final')['pending']);

        /* والمؤشّرُ ما زال على «نطاق العمل» — تامّةً، وما بعدها تامٌّ يقول ذلك */
        $flow = ProjectWorkflow::for($project->fresh());
        $this->assertSame('scope', $flow['focus']);
        $this->assertSame(['done', 'done', 'done', 'done', 'done', 'done', 'todo'], array_column($flow['stages'], 'state'));
        $this->assertTrue($flow['stages'][1]['focus']);
    }
}
