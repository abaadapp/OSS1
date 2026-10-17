<?php

namespace Tests\Feature;

use App\Models\CustomerInvoice;
use App\Models\EventOperation;
use App\Models\Project;
use App\Models\Setting;
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
 * والمؤشّرُ (`workflow_stage`) اختيارٌ يدويٌّ حرّ لا تحرسه هذه الحقائق — انظر
 * `TheProjectPathMovesToAnyStageByHandTest`. والإقفالُ يسأل عن النطاق الموقَّع
 * والتنفيذ المكتمل والفوترة الكاملة فوق موانعه القديمة. وبطاقةُ القائمة
 * وصفحةُ المشروع مصدرُهما واحد.
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
        /* والمراحلُ تمّت بمستنداتها والمؤشّرُ حيث تركه الفريق — الإقفالُ لا يجرّه */
        $this->assertSame('scope', ProjectWorkflow::for($project->fresh())['focus']);
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

    /* ─────────────────────────── الموقوف ─────────────────────────── */

    public function test_a_cancelled_project_shows_where_it_stopped(): void
    {
        $project = $this->acceptedProject(1000, 10);
        Projects::cancel($project->fresh(), 'ألغى العميل الفعالية', $this->owner, 'ضمن المهلة');
        $flow = ProjectWorkflow::for($project->fresh());

        $this->assertSame('failed', $flow['stages'][1]['state']);
        $this->assertSame('ملغى', $flow['stages'][1]['note']);
        $this->assertSame(Project::CANCELLED, $project->fresh()->status);
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
        /* الدفعةُ صدرت — تامّةٌ على البطاقة بلا ما ينقصها، فيُقترح الانتقال */
        $this->assertSame('done', $cards[$signed->id]['workflow']['stages'][2]['state']);
        $this->assertSame([], $cards[$signed->id]['workflow']['pending']);
        $this->assertArrayNotHasKey('can_advance', $cards[$signed->id]['workflow']);
    }
}
