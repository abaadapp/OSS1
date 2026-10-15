<?php

namespace Tests\Feature\V1\Concurrency;

use App\Models\CustomerInvoice;
use App\Models\DocumentApproval;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\ScopeOfWork;
use App\Support\CustomerInvoices;
use App\Support\ProjectBilling;
use App\Support\ProjectFinancials;
use App\Support\Quotations;
use App\Support\ScopesOfWork;
use Illuminate\Support\Facades\Auth;

/**
 * مسارُ المشروع يصمد تحت التزامن — على PostgreSQL.
 *
 * نطاقٌ يُوقَّع من نافذتين معًا فيُوقَّع مرّة · وتوقيعٌ يسابق نسخةً جديدةً
 * فلا تنتهي النسخةُ «موقّعةً» و«حلّت محلَّها» معًا · وفاتورتان تُصدَران معًا
 * على مشروعٍ لا يتّسع لهما فتصدر واحدة · ودفعتان تُطلبان معًا فتُكتب واحدة.
 */
class TheProjectPathHoldsUnderContentionTest extends RaceTestCase
{
    /** مشروعٌ من عرضٍ مقبول (1000 + ضريبة ٥٪ = 1050) ونطاقُه مُرسَلٌ ينتظر التوقيع */
    private function projectWithSentScope(): array
    {
        $b = $this->company();
        $owner = $this->user($b);
        $customer = $this->customer($b);
        Auth::login($owner);
        $q = Quotations::create((int) $b->id, $customer, [
            'title' => 'مؤتمر', 'issued_at' => now()->toDateString(), 'event_starts_at' => now()->addDays(20)->toDateString(),
        ], [['description' => 'تنظيم', 'quantity' => 1, 'unit_price' => 1000]], $owner);
        Quotations::send($q, $owner);
        Quotations::recordDecision($q->fresh(), Quotation::ACCEPTED, ['decided_by_name' => 'سالم'], $owner);
        $project = Project::where('quotation_id', $q->id)->firstOrFail();
        $scope = ScopesOfWork::update(ScopesOfWork::start($project, $owner), ['description' => 'تشغيل المؤتمر'], $owner);
        $scope = ScopesOfWork::send(ScopesOfWork::markReady($scope, $owner), $owner);
        Auth::logout();

        return [$b, $owner, $project->fresh(), $scope];
    }

    private function sign(int $scopeId): callable
    {
        return fn () => ScopesOfWork::decide(ScopeOfWork::findOrFail($scopeId), 'accepted',
            ['name' => 'سالم الهنائي', 'consent' => 'أوافق'], ['ip' => '10.0.0.7', 'user_agent' => 'race'])->status;
    }

    private function signedProject(): array
    {
        [$b, $owner, $project, $scope] = $this->projectWithSentScope();
        ($this->sign($scope->id))();

        return [$b, $owner, $project->fresh()];
    }

    public function test_a_scope_signed_from_two_windows_at_once_is_signed_once(): void
    {
        [, , , $scope] = $this->projectWithSentScope();

        $results = $this->race([$this->sign($scope->id), $this->sign($scope->id)]);

        $this->assertSame(1, $this->successes($results), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, DocumentApproval::where('approvable_type', $scope->getMorphClass())->where('approvable_id', $scope->id)->count());
        $this->assertSame(ScopeOfWork::SIGNED, $scope->fresh()->status);
    }

    public function test_a_signature_racing_a_new_revision_never_leaves_a_signed_revision_superseded(): void
    {
        foreach (range(1, 3) as $round) {
            [, $owner, $project, $scope] = $this->projectWithSentScope();
            $pid = $project->id;

            $results = $this->race([
                $this->sign($scope->id),
                function () use ($pid, $owner) {
                    Auth::login($owner);

                    return ScopesOfWork::start(Project::findOrFail($pid), $owner)->revision;
                },
            ]);

            $r1 = $scope->fresh();
            $approved = DocumentApproval::where('approvable_type', $r1->getMorphClass())->where('approvable_id', $r1->id)->exists();
            $context = 'الجولة '.$round.': '.json_encode($results, JSON_UNESCAPED_UNICODE);

            $this->assertTrue($results[1]['ok'], $context);
            $this->assertSame(2, ScopeOfWork::where('project_id', $pid)->count(), $context);
            /* إمّا وُقّعت فبقيت موقّعة، وإمّا طُويت قبل أن تُوقَّع فلا دليلَ عليها */
            $this->assertSame($approved ? ScopeOfWork::SIGNED : ScopeOfWork::SUPERSEDED, $r1->status, $context);
            $this->assertSame($approved, $results[0]['ok'], $context);
        }
    }

    public function test_two_invoices_issued_at_once_never_bill_a_project_above_its_value(): void
    {
        [$b, $owner, $project] = $this->signedProject();
        $customer = $project->customer;
        Auth::login($owner);
        $ids = [];
        foreach ([1, 2] as $i) {
            /* كلٌّ منهما 630 بضريبته — والمشروعُ 1050: يتّسع لواحدةٍ لا لاثنتين */
            $ids[] = CustomerInvoices::create((int) $b->id, $customer, ['project_id' => $project->id],
                [['description' => 'مرحلة '.$i, 'quantity' => 1, 'unit_price' => 600]], $owner->id)->id;
        }
        Auth::logout();

        $issue = fn (int $id) => function () use ($id, $owner) {
            Auth::login($owner);

            return CustomerInvoices::issue(CustomerInvoice::findOrFail($id), $owner->id)->number;
        };

        $results = $this->race([$issue($ids[0]), $issue($ids[1])]);

        $this->assertSame(1, $this->successes($results), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, CustomerInvoice::whereIn('id', $ids)->where('status', CustomerInvoice::ISSUED)->count());
        $this->assertLessThanOrEqual(1050.0005, (float) ProjectFinancials::summary($project->fresh())['net_invoiced']);
    }

    public function test_two_deposits_requested_at_once_write_one_invoice(): void
    {
        [, $owner, $project] = $this->signedProject();
        $pid = $project->id;

        $deposit = fn (string $key) => function () use ($pid, $owner, $key) {
            Auth::login($owner);

            return ProjectBilling::createDeposit(Project::findOrFail($pid), [
                'method' => 'percent', 'percent' => 50, 'issue' => true, 'request_key' => $key,
            ], $owner)->id;
        };

        /* ضغطتان من نافذتين بمفتاحين — ثمّ الطلبُ نفسُه مُعادًا بمفتاحه */
        $results = $this->race([$deposit('window-a-'.$pid), $deposit('window-b-'.$pid), $deposit('window-a-'.$pid)]);

        $live = CustomerInvoice::where('project_id', $pid)->where('billing_role', ProjectBilling::DEPOSIT)->get();
        $this->assertCount(1, $live, json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertEqualsWithDelta(525.0, (float) $live->first()->total, 0.0005);
        $this->assertGreaterThanOrEqual(1, $this->successes($results));
    }
}
