<?php

namespace Tests\Feature;

use App\Models\CustomerInvoice;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Support\ChangeOrders;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use App\Support\ProjectBilling;
use App\Support\ProjectFinancials;
use App\Support\Projects;
use App\Support\ProjectWorkflow;
use App\Support\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\WalksTheProjectJourney;
use Tests\TestCase;

/**
 * الدفعةُ المقدّمة والفاتورةُ النهائيّة — فواتيرُ عملاءَ حقيقيّة، ومبالغُها من الحقيقة.
 *
 * الدفعةُ نسبةٌ أو مبلغٌ ثابت بعد توقيع النطاق، ولا دفعتان بالخطأ. والنهائيّةُ
 * = القيمةُ الحاليّة (بأوامر التغيير) − صافي ما فُوتر (بعد إشعارات الدائن)،
 * بعد قبول التسليم، ولا تتجاوز القيمة. وكلتاهما تمرّ بـ`CustomerInvoices`
 * فتدخل الدفترَ والذممَ والربحيّة كأيّ فاتورة — ولا رقمَ من المتصفّح.
 *
 * والأرقامُ بلا ضريبة إلّا حيث تُختبر الضريبة: عشرةُ آلاف، دفعةٌ أربعة،
 * نهائيّةٌ ستّة — وبعد أمر تغييرٍ يرفعها إلى اثني عشر: ثمانية.
 */
class TheDepositAndTheFinalInvoiceAreBilledFromTheTruthTest extends TestCase
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

    private function withoutVat(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);
        Vat::flush();
    }

    /** مشروعٌ بعشرة آلاف بلا ضريبة، ونطاقُه موقَّع */
    private function signedProject(): Project
    {
        $this->withoutVat();
        $project = $this->acceptedProject(1000, 10);
        $this->signedScope($project);
        $this->actingAs($this->owner);

        return $project->fresh();
    }

    private function deposit(Project $project, array $data)
    {
        return $this->actingAs($this->owner)->post(route('admin.projects.billing.deposit', $project->id), $data + ['issue' => '1']);
    }

    private function user(string $role, array $permissions = []): User
    {
        return User::create([
            'business_id' => $this->business->id, 'name' => 'موظّف '.$role, 'email' => $role.'@eventplus.om',
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
            'permissions' => $permissions ?: null,
        ]);
    }

    /* ─────────────────────────── الدفعة المقدّمة ─────────────────────────── */

    public function test_the_deposit_waits_for_a_signed_scope(): void
    {
        $this->withoutVat();
        $project = $this->acceptedProject(1000, 10);

        $this->deposit($project, ['method' => 'percent', 'percent' => 50])->assertSessionHasErrors('billing');
        $this->assertSame(0, CustomerInvoice::count());
    }

    public function test_a_percentage_deposit_is_a_real_issued_invoice_with_its_tax_on_the_project(): void
    {
        $project = $this->acceptedProject(1000, 10);
        $this->signedScope($project);
        $this->actingAs($this->owner);

        $quote = $this->getJson(route('admin.projects.billing.preview', [$project->id, 'method' => 'percent', 'percent' => 50]))
            ->assertOk()->json('quote');
        $this->assertEqualsWithDelta(10500.0, $quote['value'], 0.0005);
        $this->assertEqualsWithDelta(5250.0, $quote['amount'], 0.0005);
        $this->assertEqualsWithDelta(5000.0, $quote['net'], 0.0005);
        $this->assertEqualsWithDelta(250.0, $quote['tax'], 0.0005);
        $this->assertEqualsWithDelta(5250.0, $quote['remaining_after'], 0.0005);

        $this->deposit($project, ['method' => 'percent', 'percent' => 50])->assertSessionHasNoErrors();

        $invoice = CustomerInvoice::sole();
        $this->assertSame(CustomerInvoice::ISSUED, $invoice->status);
        $this->assertNotNull($invoice->number);
        $this->assertSame($project->id, (int) $invoice->project_id);
        $this->assertSame($this->customer->id, (int) $invoice->customer_id);
        $this->assertSame(ProjectBilling::DEPOSIT, $invoice->billing_role);
        $this->assertSame('percent', $invoice->billing_basis['method']);
        $this->assertEqualsWithDelta(50.0, (float) $invoice->billing_basis['percent'], 0.001);
        $this->assertEqualsWithDelta(5250.0, (float) $invoice->total, 0.0005);
        $this->assertEqualsWithDelta(250.0, (float) $invoice->tax_total, 0.0005);

        /* والدفترُ والذممُ والربحيّةُ تقرؤها كأيّ فاتورة */
        $this->assertEqualsWithDelta(5250.0, Ledger::balance((int) $this->business->id, 'receivable'), 0.0005);
        $this->assertEqualsWithDelta(5250.0, ProjectFinancials::summary($project->fresh())['invoiced'], 0.0005);

        $flow = ProjectWorkflow::for($project->fresh());
        $this->assertTrue($flow['stages'][2]['done'], 'المرحلةُ تتمّ بالإصدار');
        /* والتحصيلُ يُقال منفصلًا: المرحلةُ تمّت والفاتورةُ لم تُسدَّد */
        $this->assertNotSame('مدفوعة', $invoice->fresh()->paymentState());
        $this->assertFalse(ProjectBilling::depositState($project->fresh(), ProjectBilling::position($project->fresh()))['paid']);
    }

    public function test_a_fixed_deposit_is_billed_as_typed_and_an_amount_slipped_into_the_request_is_ignored(): void
    {
        $project = $this->signedProject();

        $this->deposit($project, ['method' => 'fixed', 'amount' => 1500])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(1500.0, (float) CustomerInvoice::sole()->total, 0.0005);

        $other = $this->acceptedProject(1000, 10);
        $this->signedScope($other);
        /* نسبةٌ ومعها «مبلغٌ» و«إجماليٌّ» مزوَّران — والفاتورةُ من النسبة وحدها */
        $this->deposit($other, ['method' => 'percent', 'percent' => 40, 'amount' => 1, 'total' => 1, 'net' => 1])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(4000.0, (float) CustomerInvoice::where('project_id', $other->id)->sole()->total, 0.0005);
    }

    public function test_a_deposit_above_what_is_left_to_bill_is_refused(): void
    {
        $project = $this->signedProject();

        $this->deposit($project, ['method' => 'fixed', 'amount' => 10000.5])->assertSessionHasErrors('billing');
        $this->deposit($project, ['method' => 'percent', 'percent' => 120])->assertSessionHasErrors('percent');
        $this->deposit($project, ['method' => 'percent', 'percent' => 0])->assertSessionHasErrors('percent');
        $this->assertSame(0, CustomerInvoice::count());
    }

    public function test_a_second_deposit_is_a_choice_and_a_repeated_request_writes_one_invoice(): void
    {
        $project = $this->signedProject();
        $key = 'dep-4f1c2a7e-0001';

        $this->deposit($project, ['method' => 'fixed', 'amount' => 3000, 'request_key' => $key])->assertSessionHasNoErrors();
        $this->deposit($project, ['method' => 'fixed', 'amount' => 3000, 'request_key' => $key])->assertSessionHasNoErrors();
        $this->assertSame(1, CustomerInvoice::count(), 'الطلبُ المعاد يجد فاتورتَه');

        $this->deposit($project, ['method' => 'fixed', 'amount' => 2000])->assertSessionHasErrors('billing');
        $this->assertSame(1, CustomerInvoice::count(), 'لا دفعةَ ثانيةٌ بالخطأ');

        $this->deposit($project, ['method' => 'fixed', 'amount' => 2000, 'additional' => '1'])->assertSessionHasNoErrors();
        $second = CustomerInvoice::orderByDesc('id')->first();
        $this->assertTrue((bool) $second->billing_basis['additional']);
        $this->assertStringContainsString('دفعة مقدّمة إضافيّة', $second->items()->first()->description);

        /* والإضافيّةُ نفسُها لا تتجاوز الباقي */
        $this->deposit($project, ['method' => 'fixed', 'amount' => 6000, 'additional' => '1'])->assertSessionHasErrors('billing');
        $this->assertSame(2, CustomerInvoice::count());
    }

    public function test_who_cannot_issue_saves_the_deposit_as_a_draft_and_the_stage_waits_for_it(): void
    {
        $project = $this->signedProject();
        $seller = $this->user('sales');

        $this->actingAs($seller)->post(route('admin.projects.billing.deposit', $project->id), ['method' => 'percent', 'percent' => 50, 'issue' => '1'])
            ->assertSessionHasErrors('billing');
        $this->actingAs($seller)->post(route('admin.projects.billing.deposit', $project->id), ['method' => 'percent', 'percent' => 50])
            ->assertSessionHasNoErrors();

        $draft = CustomerInvoice::sole();
        $this->assertSame(CustomerInvoice::DRAFT, $draft->status);
        $this->assertNull($draft->number);

        $deposit = ProjectWorkflow::for($project->fresh())['stages'][2];
        $this->assertFalse($deposit['done']);
        $this->assertSame(['فاتورة الدفعة المقدمة مسودّة — أصدرها.'], $deposit['pending']);

        CustomerInvoices::issue($draft, $this->owner->id);
        $this->assertTrue(ProjectWorkflow::for($project->fresh())['stages'][2]['done']);
    }

    public function test_billing_from_the_project_belongs_to_who_opens_customer_invoices(): void
    {
        $project = $this->signedProject();

        $this->actingAs($this->user('inventory'))->post(route('admin.projects.billing.deposit', $project->id), ['method' => 'percent', 'percent' => 50])
            ->assertForbidden();
        $this->actingAs($this->user('inventory2', ['projects']))->post(route('admin.projects.billing.final', $project->id))->assertForbidden();
        $this->assertSame(0, CustomerInvoice::count());
    }

    public function test_no_deposit_is_a_named_decision_with_a_reason(): void
    {
        $project = $this->signedProject();

        $this->actingAs($this->user('sales'))->post(route('admin.projects.billing.waive', $project->id), ['reason' => 'عميلٌ حكوميّ'])->assertForbidden();
        $this->actingAs($this->owner)->post(route('admin.projects.billing.waive', $project->id), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->owner)->post(route('admin.projects.billing.waive', $project->id), ['reason' => 'عميلٌ حكوميّ يدفع بعد التسليم'])->assertSessionHasNoErrors();

        $fresh = $project->fresh();
        $this->assertNotNull($fresh->deposit_waived_at);
        $this->assertSame($this->owner->id, (int) $fresh->deposit_waived_by);
        $deposit = ProjectWorkflow::for($fresh)['stages'][2];
        $this->assertTrue($deposit['done']);
        $this->assertSame('بلا دفعة مقدمة', $deposit['note']);
    }

    /* ─────────────────────────── الفاتورة النهائيّة ─────────────────────────── */

    public function test_the_final_invoice_is_the_current_value_minus_what_was_billed_after_delivery(): void
    {
        $project = $this->signedProject();
        $this->deposit($project, ['method' => 'fixed', 'amount' => 4000])->assertSessionHasNoErrors();

        /* قبل قبول التسليم لا فاتورةَ نهائيّة */
        $this->actingAs($this->owner)->post(route('admin.projects.billing.final', $project->id), ['issue' => '1'])->assertSessionHasErrors('billing');

        $this->acceptedDelivery($project);
        $quote = ProjectBilling::quoteFinal($project->fresh());
        $this->assertEqualsWithDelta(10000.0, $quote['value'], 0.0005);
        $this->assertEqualsWithDelta(4000.0, $quote['net_invoiced'], 0.0005);
        $this->assertEqualsWithDelta(6000.0, $quote['amount'], 0.0005);

        /* ومبلغٌ يُرسَل معها لا يُقرأ */
        $this->actingAs($this->owner)->post(route('admin.projects.billing.final', $project->id), ['issue' => '1', 'amount' => 999999])->assertSessionHasNoErrors();

        $final = CustomerInvoice::where('billing_role', ProjectBilling::FINAL)->sole();
        $this->assertSame(CustomerInvoice::ISSUED, $final->status);
        $this->assertEqualsWithDelta(6000.0, (float) $final->total, 0.0005);
        $this->assertEqualsWithDelta(10000.0, ProjectFinancials::summary($project->fresh())['net_invoiced'], 0.0005);
        $this->assertEqualsWithDelta(10000.0, Ledger::balance((int) $this->business->id, 'receivable'), 0.0005);
        $this->assertTrue(ProjectWorkflow::for($project->fresh())['stages'][5]['done']);

        /* ولا نهائيّةَ ثانية: لا شيء باقٍ */
        $this->actingAs($this->owner)->post(route('admin.projects.billing.final', $project->id), ['issue' => '1'])->assertSessionHasErrors('billing');
        $this->assertSame(2, CustomerInvoice::count());
    }

    public function test_an_accepted_change_order_raises_the_final_invoice(): void
    {
        $project = $this->signedProject();
        $this->deposit($project, ['method' => 'fixed', 'amount' => 4000])->assertSessionHasNoErrors();

        $co = ChangeOrders::create($project->fresh(), ['title' => 'يومان إضافيّان', 'requested_by_party' => 'customer'],
            [['project_item_id' => $project->items()->first()->id, 'quantity' => 2]], [], $this->owner);
        ChangeOrders::send($co, $this->owner);
        ChangeOrders::recordDecision($co->fresh(), 'accepted', ['decided_by_name' => 'سالم'], $this->owner);

        $this->acceptedDelivery($project->fresh());
        $quote = ProjectBilling::quoteFinal($project->fresh());
        $this->assertEqualsWithDelta(12000.0, $quote['value'], 0.0005);
        $this->assertEqualsWithDelta(8000.0, $quote['amount'], 0.0005);

        $this->actingAs($this->owner)->post(route('admin.projects.billing.final', $project->id), ['issue' => '1'])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(8000.0, (float) CustomerInvoice::where('billing_role', ProjectBilling::FINAL)->sole()->total, 0.0005);
    }

    public function test_a_credit_note_gives_back_to_the_final_invoice_what_it_took(): void
    {
        $project = $this->signedProject();
        $this->deposit($project, ['method' => 'fixed', 'amount' => 4000])->assertSessionHasNoErrors();
        CustomerInvoices::creditNote(CustomerInvoice::sole(), 1000, 0, 'تصحيح الدفعة', $this->owner->id);

        $this->acceptedDelivery($project->fresh());
        $quote = ProjectBilling::quoteFinal($project->fresh());
        $this->assertEqualsWithDelta(3000.0, $quote['net_invoiced'], 0.0005);
        $this->assertEqualsWithDelta(7000.0, $quote['amount'], 0.0005);
    }

    public function test_a_draft_on_the_project_holds_the_final_invoice_and_nothing_bills_above_the_value(): void
    {
        $project = $this->signedProject();
        $this->acceptedDelivery($project);
        $this->deposit($project, ['method' => 'fixed', 'amount' => 4000, 'issue' => '0'])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->post(route('admin.projects.billing.final', $project->id), ['issue' => '1'])->assertSessionHasErrors('billing');

        CustomerInvoices::issue(CustomerInvoice::sole(), $this->owner->id);
        $this->actingAs($this->owner)->post(route('admin.projects.billing.final', $project->id), ['issue' => '1'])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(10000.0, (float) CustomerInvoice::where('project_id', $project->id)->sum('total'), 0.0005);
        $this->expectException(RuntimeException::class);
        ProjectBilling::quoteDeposit($project->fresh(), ProjectBilling::FIXED, 1);
    }

    public function test_the_final_invoice_reaches_the_remainder_to_the_baisa_and_lets_the_project_close(): void
    {
        /* بضريبة ٥٪: القيمة 1050 · دفعةٌ ثابتة 400.040 · الباقي 649.960 — وبندٌ صافٍ واحدٌ لا يبلغه (649.959) */
        $project = $this->acceptedProject(1000, 1);
        $this->signedScope($project);
        $this->deposit($project->fresh(), ['method' => 'fixed', 'amount' => '400.040'])->assertSessionHasNoErrors();
        $this->completedOperation($project->fresh());
        $this->acceptedDelivery($project->fresh());

        $quote = ProjectBilling::quoteFinal($project->fresh());
        $this->assertEqualsWithDelta(649.960, $quote['amount'], 0.0005);
        $this->assertEqualsWithDelta(0.001, $quote['rounding'], 0.0005);

        $this->actingAs($this->owner)->post(route('admin.projects.billing.final', $project->id), ['issue' => '1'])->assertSessionHasNoErrors();
        $final = CustomerInvoice::where('billing_role', ProjectBilling::FINAL)->sole();
        $this->assertEqualsWithDelta(649.960, (float) $final->total, 0.0005, 'إجماليُّها الباقي بعينه — لا ينقص فلسًا ولا يزيده');
        $this->assertCount(2, $final->items);
        $this->assertSame('فرق تقريب الضريبة', $final->items[1]->description);
        $this->assertEqualsWithDelta(0.0, (float) $final->items[1]->tax_amount, 0.0005, 'وضريبةُ فلسٍ تُقرَّب صفرًا');

        $this->assertEqualsWithDelta(1050.0, ProjectFinancials::summary($project->fresh())['net_invoiced'], 0.0005);
        $this->assertTrue(ProjectBilling::position($project->fresh())['fully_billed']);
        $this->assertSame([], Projects::closeBlockers($project->fresh()), 'فلسٌ باقٍ لا يمنع الإقفال');
    }

    public function test_a_repeated_final_request_writes_one_final_invoice(): void
    {
        $project = $this->signedProject();
        $this->deposit($project, ['method' => 'fixed', 'amount' => 4000])->assertSessionHasNoErrors();
        $this->acceptedDelivery($project->fresh());

        /* الطلبُ نفسُه مرّتين — ردٌّ بطيءٌ أُعيد — فاتورةٌ واحدة */
        $key = 'final-'.str_repeat('a', 12);
        $this->actingAs($this->owner)->post(route('admin.projects.billing.final', $project->id), ['issue' => '0', 'request_key' => $key])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('admin.projects.billing.final', $project->id), ['issue' => '0', 'request_key' => $key])->assertSessionHasNoErrors();
        $this->assertSame(1, CustomerInvoice::where('billing_role', ProjectBilling::FINAL)->count());

        /* وضغطةٌ أخرى بمفتاحٍ آخر تجد المسودّةَ فتُردّ — لا نهائيّتان */
        $this->actingAs($this->owner)->post(route('admin.projects.billing.final', $project->id), ['issue' => '1', 'request_key' => 'final-'.str_repeat('b', 12)])
            ->assertSessionHasErrors('billing');
        $this->assertSame(1, CustomerInvoice::where('billing_role', ProjectBilling::FINAL)->count());

        /* وإصدارُها من شاشة الفواتير يمرّ بالسقف — وهي الباقي بعينه فتصدر */
        $final = CustomerInvoice::where('billing_role', ProjectBilling::FINAL)->sole();
        $this->assertEqualsWithDelta(6000.0, (float) $final->total, 0.0005);
        $this->actingAs($this->owner)->post(route('admin.customerInvoices.issue', $final->id))->assertSessionHasNoErrors();
        $this->assertSame(CustomerInvoice::ISSUED, $final->fresh()->status);
        $this->assertEqualsWithDelta(10000.0, ProjectFinancials::summary($project->fresh())['net_invoiced'], 0.0005);
    }

    public function test_the_project_screen_reads_its_billing_from_the_server(): void
    {
        $project = $this->signedProject();
        $this->deposit($project, ['method' => 'percent', 'percent' => 40])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get(route('admin.projects.show', $project->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Projects/Show')
                ->where('billing.deposit.done', true)
                ->where('billing.deposit.invoices.0.role', ProjectBilling::DEPOSIT)
                ->where('billing.final.quote.amount', 6000)
                ->where('billing.unbilled', 6000));
    }

    public function test_billing_doors_do_not_open_on_another_company(): void
    {
        $project = $this->signedProject();
        $mine = $this->business;

        $this->journeyCompany('شركة أخرى');
        $this->actingAs($this->owner);

        $this->post(route('admin.projects.billing.deposit', $project->id), ['method' => 'percent', 'percent' => 50])->assertNotFound();
        $this->post(route('admin.projects.billing.final', $project->id))->assertNotFound();
        $this->post(route('admin.projects.billing.waive', $project->id), ['reason' => 'x'])->assertNotFound();
        $this->getJson(route('admin.projects.billing.preview', [$project->id, 'method' => 'percent', 'percent' => 50]))->assertNotFound();

        $this->assertSame(0, CustomerInvoice::count());
        $this->assertNull($project->fresh()->deposit_waived_at);
        $this->assertNotNull($mine);
    }
}
