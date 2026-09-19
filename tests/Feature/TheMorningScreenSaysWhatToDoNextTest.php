<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Communication;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\DeliveryPackage;
use App\Models\Expense;
use App\Models\JobTitle;
use App\Models\PolicyException;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectTask;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\CommandCenter;
use App\Support\CustomerInvoices;
use App\Support\DeliveryPackages;
use App\Support\Quotations;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * شاشةُ الصباح تقول ما يُفعل — لا ما حدث.
 *
 * ═══ ما يُفحص ═══
 *
 * كلُّ قسمٍ يلتقط ما يخصّه من سجلّاتٍ حقيقيّة: قرارٌ معلَّق، موعدٌ فات،
 * حزمةٌ تنتظر، رسالةٌ لم تصل، مشروعٌ قادم، مسؤولٌ متأخّر، مالٌ مستحقّ.
 * ولا يلتقط ما لا يحتاج يدًا. والشركةُ لا ترى غيرَها. والمالُ لمن يملكه.
 */
class TheMorningScreenSaysWhatToDoNextTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private User $coordinator;

    private Customer $customer;

    private Product $service;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('local');

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط', 'email' => 'hello@hadath.om']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@hadath.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->coordinator = User::create([
            'business_id' => $this->business->id, 'name' => 'المنسّق', 'email' => 'field@hadath.om',
            'password' => bcrypt('password'), 'role' => 'delivery', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة الطاقة', 'email' => 'buyer@ministry.om']);
        $this->service = Product::create(['business_id' => $this->business->id, 'name' => 'تشغيل نظام تسجيل', 'kind' => Product::SERVICE, 'price' => 500]);
        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّد الخيام']);

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.om']);
        Mail::fake();
    }

    /** عرضٌ خرج — أو قُبل فصار مشروعًا يبدأ بعد أيّام */
    private function quotation(array $over = []): Quotation
    {
        $this->actingAs($this->owner);
        $q = Quotations::create((int) $this->business->id, $this->customer, $over + [
            'title' => 'مؤتمر الطاقة', 'issued_at' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(),
            'event_starts_at' => now()->addDays(5)->toDateString(), 'event_ends_at' => now()->addDays(6)->toDateString(),
        ], [['product_id' => $this->service->id, 'quantity' => 2, 'unit_price' => 500]], $this->owner);

        return Quotations::send($q, $this->owner);
    }

    private function project(array $over = []): Project
    {
        $q = $this->quotation($over);
        Quotations::recordDecision($q->fresh(), Quotation::ACCEPTED, [], $this->owner);
        $project = Project::where('quotation_id', $q->id)->firstOrFail();
        $project->update(['status' => Project::IN_PROGRESS, 'owner_id' => $this->owner->id]);

        return $project->fresh();
    }

    /** الصباحُ كاملًا: شيءٌ في كلّ قسم */
    private function busyMorning(): Project
    {
        $project = $this->project();

        ProjectTask::create(['business_id' => $this->business->id, 'project_id' => $project->id, 'title' => 'حجز القاعة',
            'status' => ProjectTask::OPEN, 'priority' => 'high', 'due_at' => now()->subDays(3)->toDateString(), 'assignee_id' => $this->coordinator->id]);
        ProjectTask::create(['business_id' => $this->business->id, 'project_id' => $project->id, 'title' => 'طباعة البطاقات',
            'status' => ProjectTask::OPEN, 'priority' => 'normal', 'due_at' => now()->addDays(2)->toDateString(), 'assignee_id' => $this->coordinator->id]);
        ProjectTask::create(['business_id' => $this->business->id, 'project_id' => $project->id, 'title' => 'مهمّة منجَزة متأخّرة',
            'status' => ProjectTask::DONE, 'priority' => 'normal', 'due_at' => now()->subDays(9)->toDateString(), 'completed_at' => now()]);
        ProjectMember::create(['business_id' => $this->business->id, 'project_id' => $project->id, 'user_id' => $this->coordinator->id, 'role_in_project' => 'منسّق']);

        /* عرضٌ ينتظر اعتمادَ خصمٍ، وآخرُ انتهت صلاحيّتُه بلا قرار */
        $pending = $this->quotation(['title' => 'عرض بخصم']);
        $pending->forceFill(['status' => Quotation::PENDING_APPROVAL])->saveQuietly();
        $expired = $this->quotation(['title' => 'عرض قديم']);
        $expired->forceFill(['valid_until' => now()->subDay()->toDateString()])->saveQuietly();

        PolicyException::create(['business_id' => $this->business->id, 'exceptionable_type' => Quotation::class, 'exceptionable_id' => $pending->id,
            'policy_key' => 'lead_time', 'reason' => 'طلب عاجل من الوزارة', 'requested_by' => $this->owner->id]);

        /* فاتورةٌ متأخّرة على العميل */
        $invoice = CustomerInvoices::create((int) $this->business->id, $this->customer, ['issued_at' => now()->subDays(40)->toDateString(),
            'due_at' => now()->subDays(10)->toDateString(), 'project_id' => $project->id],
            [['product_id' => $this->service->id, 'description' => 'خدمة', 'quantity' => 1, 'unit_price' => 300]], $this->owner->id);
        CustomerInvoices::issue($invoice, $this->owner->id);

        /* سندُ مورّدٍ ينتظر اعتمادًا، وآخرُ معتمَدٌ فات استحقاقُه */
        SupplierInvoice::create(['business_id' => $this->business->id, 'supplier_id' => $this->supplier->id, 'supplier_ref' => 'R-PENDING',
            'issued_at' => now()->toDateString(), 'subtotal' => 100, 'tax' => 5, 'total' => 105, 'approval_status' => SupplierInvoices::PENDING, 'project_id' => $project->id]);
        SupplierInvoice::create(['business_id' => $this->business->id, 'supplier_id' => $this->supplier->id, 'supplier_ref' => 'R-LATE',
            'issued_at' => now()->subDays(30)->toDateString(), 'due_at' => now()->subDays(5)->toDateString(), 'subtotal' => 200, 'tax' => 10, 'total' => 210, 'paid' => 10,
            'approval_status' => SupplierInvoices::APPROVED]);

        Expense::create(['business_id' => $this->business->id, 'reference' => 'EXP-1', 'type' => 'إيجار', 'description' => 'إيجار المخزن',
            'amount' => 80, 'method' => 'نقدي', 'status' => Expense::UNPAID, 'spent_at' => now()->subDays(20)->toDateString(), 'due_date' => now()->subDays(2)->toDateString()]);

        PurchaseOrder::create(['business_id' => $this->business->id, 'branch_id' => $this->branch->id, 'supplier_id' => $this->supplier->id,
            'number' => 'PU-000001', 'status' => 'مُرسل', 'total' => 400, 'items_subtotal' => 400, 'ordered_at' => now()->subDays(10),
            'expected_delivery_at' => now()->subDays(1)->toDateString()]);

        /* حزمةُ تسليمٍ أُرسلت للعميل ولم يقرّر */
        $package = DeliveryPackages::create((int) $this->business->id, null, ['project_id' => $project->id], $this->owner);
        DeliveryPackages::addItem($package, ['kind' => 'file', 'title' => 'تقرير'], UploadedFile::fake()->create('r.pdf', 5, 'application/pdf'), $this->owner);
        DeliveryPackages::send(DeliveryPackages::ready($package->fresh(), $this->owner), $this->owner);

        /* رسالةٌ ارتدّت */
        Communication::create(['business_id' => $this->business->id, 'customer_id' => $this->customer->id, 'project_id' => $project->id,
            'subject_type' => Project::class, 'subject_id' => $project->id, 'channel' => 'email', 'recipient' => 'wrong@ministry.om',
            'template_key' => 'project.update', 'title' => 'بيان المشروع', 'provider' => 'postmark', 'provider_message_id' => 'pm-x',
            'status' => Communication::BOUNCED, 'sent_at' => now()->subDay(), 'failed_at' => now()->subDay(), 'failure_reason' => 'mailbox not found',
            'initiated_by_name' => 'المالك']);

        return $project->fresh();
    }

    /* ─────────────────────────── الأقسام ─────────────────────────── */

    public function test_every_section_names_what_needs_a_hand(): void
    {
        $project = $this->busyMorning();
        $c = CommandCenter::for((int) $this->business->id, $this->owner);

        $kinds = fn (string $section) => collect($c[$section]['rows'])->pluck('kind')->all();

        $this->assertEqualsCanonicalizing(['quotation_approval', 'quotation_expired', 'policy_exception', 'supplier_invoice_approval'], $kinds('decisions'));
        $this->assertEqualsCanonicalizing(['task', 'customer_invoice', 'supplier_invoice', 'expense', 'purchase_order'], $kinds('late'),
            'المهمّةُ المنجَزة والمهمّةُ التي لم يحن موعدُها لا تُعدّان متأخّرتين');
        $this->assertSame(['package_waiting'], $kinds('delivery'));
        $this->assertSame(['communication_failed'], $kinds('communications'));

        $late = collect($c['late']['rows'])->firstWhere('kind', 'task');
        $this->assertSame('حجز القاعة', $late['title']);
        $this->assertSame(3, $late['days'], 'متأخّرةٌ منذ ثلاثة أيّام');
        $this->assertSame('أنجز المهمّة أو أعد جدولتها', $late['action']);
        $this->assertSame(route('admin.projects.show', $project->id), $late['url']);

        $invoice = collect($c['late']['rows'])->firstWhere('kind', 'customer_invoice');
        $this->assertSame(315.0, $invoice['amount'], 'المالكُ يرى المبلغ — ٣٠٠ وضريبتها');
        $this->assertSame(10, $invoice['days']);

        $exception = collect($c['decisions']['rows'])->firstWhere('kind', 'policy_exception');
        $this->assertStringContainsString('lead_time', $exception['title']);
        $this->assertStringContainsString('طلب عاجل', $exception['detail']);

        $failed = $c['communications']['rows'][0];
        $this->assertStringContainsString('wrong@ministry.om', $failed['detail']);
        $this->assertStringContainsString('mailbox not found', $failed['detail']);

        $this->assertSame($c['decisions']['total'], $c['summary']['decisions']);
        $this->assertSame($c['late']['total'], $c['summary']['late']);
        $this->assertSame(1, $c['summary']['communications']);
    }

    public function test_the_upcoming_project_is_shown_with_its_readiness_not_its_date_alone(): void
    {
        $project = $this->busyMorning();
        $c = CommandCenter::for((int) $this->business->id, $this->owner);

        $this->assertCount(1, $c['upcoming']['rows']);
        $row = $c['upcoming']['rows'][0];
        $this->assertSame($project->id, $row['id']);
        $this->assertSame(5, $row['days_to_start']);
        $this->assertFalse($row['running']);
        $this->assertSame(2, $row['open_tasks']);
        $this->assertSame(1, $row['late_tasks']);
        $this->assertFalse($row['baselined']);
        $this->assertSame('sent', $row['delivery']);
        $this->assertContains('المتطلّبات لم تُثبَّت', $row['issues']);
        $this->assertContains('1 مهامّ متأخّرة', $row['issues']);
        $this->assertSame('danger', $row['severity'], 'يبدأ خلال أسبوع وعليه ما يعطّله');
        $this->assertSame(1, $c['summary']['upcoming']);
    }

    public function test_blockers_list_the_project_with_its_operational_and_financial_reasons(): void
    {
        $project = $this->busyMorning();
        $c = CommandCenter::for((int) $this->business->id, $this->owner);

        $this->assertCount(1, $c['blockers']['rows']);
        $b = $c['blockers']['rows'][0];
        $this->assertSame($project->id, $b['id']);
        $this->assertContains('المتطلّبات لم تُثبَّت', $b['operational']);
        $this->assertContains('1 مهامّ متأخّرة', $b['operational']);
        $this->assertContains('ذمم متأخّرة على العميل', $b['financial']);
        $this->assertContains('1 سندات موردين بانتظار اعتماد', $b['financial']);
        $this->assertSame('danger', $b['severity']);

        /* ومشروعٌ سليم لا يظهر */
        $clean = $this->project(['title' => 'مشروع سليم', 'event_starts_at' => now()->addDays(40)->toDateString(), 'event_ends_at' => now()->addDays(41)->toDateString()]);
        $clean->update(['requirements_baselined_at' => now(), 'requirements_baselined_by' => $this->owner->id]);
        $again = CommandCenter::for((int) $this->business->id, $this->owner);
        $this->assertSame([$project->id], collect($again['blockers']['rows'])->pluck('id')->all());
    }

    public function test_the_team_shows_who_is_late_and_what_comes_next(): void
    {
        $this->busyMorning();
        $c = CommandCenter::for((int) $this->business->id, $this->owner);

        $rows = collect($c['team']['rows'])->keyBy('id');
        $coordinator = $rows[$this->coordinator->id];
        $this->assertSame(2, $coordinator['open_tasks']);
        $this->assertSame(1, $coordinator['late_tasks']);
        $this->assertSame(1, $coordinator['projects_member']);
        $this->assertSame('حجز القاعة', $coordinator['next_task']['title'], 'الأقربُ موعدًا أوّلًا — وهو المتأخّر');

        $owner = $rows[$this->owner->id];
        $this->assertSame(1, $owner['projects_owned']);
        $this->assertSame(0, $owner['open_tasks']);

        $this->assertSame($this->coordinator->id, $c['team']['rows'][0]['id'], 'المتأخّرُ يتصدّر');
        $this->assertSame(0, $c['team']['unassigned']);
    }

    public function test_money_says_who_owes_us_and_what_we_owe(): void
    {
        $this->busyMorning();
        $c = CommandCenter::for((int) $this->business->id, $this->owner);

        $this->assertTrue($c['sees_money']);
        $money = $c['money'];
        $this->assertSame(315.0, $money['receivables']['total']);
        $this->assertSame(315.0, $money['receivables']['overdue']);
        $this->assertSame(1, $money['receivables']['overdue_count']);
        $this->assertSame('وزارة الطاقة', $money['receivables']['top'][0]['customer']);
        $this->assertSame(315.0, $money['receivables']['top'][0]['amount']);

        $this->assertSame(200.0, $money['payables']['supplier_total'], 'المعتمَدُ وحدَه — ٢١٠ منها ١٠ سُدّدت؛ والمعلَّقُ ليس دَينًا بعد');
        $this->assertSame(200.0, $money['payables']['supplier_overdue']);
        $this->assertSame(80.0, $money['payables']['expenses_unpaid']);
        $this->assertSame(400.0, $money['payables']['open_purchase_orders']);
        $this->assertSame(1, $money['payables']['open_purchase_orders_count']);
        $this->assertSame(35.0, $money['net']);
    }

    public function test_a_quiet_morning_has_nothing_to_do(): void
    {
        $c = CommandCenter::for((int) $this->business->id, $this->owner);

        foreach (['decisions', 'late', 'delivery', 'communications'] as $section) {
            $this->assertSame([], $c[$section]['rows'], $section);
        }
        $this->assertSame([], $c['upcoming']['rows']);
        $this->assertSame([], $c['blockers']['rows']);
        $this->assertSame([], $c['team']['rows']);
        $this->assertSame(0, array_sum($c['summary']));
    }

    /* ─────────────────────────── ما لا يحتاج يدًا لا يُعرض ─────────────────────────── */

    public function test_what_was_handled_leaves_the_screen(): void
    {
        $project = $this->busyMorning();

        /* أُنجزت المهمّة، وأُعيدت الرسالةُ ووصلت، وقُبلت الحزمة */
        ProjectTask::where('title', 'حجز القاعة')->update(['status' => ProjectTask::DONE, 'completed_at' => now()]);
        $bounced = Communication::where('status', Communication::BOUNCED)->sole();
        Communication::create(['business_id' => $this->business->id, 'channel' => 'email', 'recipient' => 'right@ministry.om',
            'template_key' => 'project.update', 'title' => 'بيان المشروع', 'provider' => 'postmark', 'provider_message_id' => 'pm-y',
            'status' => Communication::DELIVERED, 'sent_at' => now(), 'retry_of_id' => $bounced->id, 'initiated_by_name' => 'المالك']);
        $package = DeliveryPackage::where('project_id', $project->id)->sole();
        auth()->logout();
        $this->post(DeliveryPackages::url($package).'/decision', ['decision' => 'accepted', 'name' => 'سالم', 'consent' => '1'])->assertSessionHasNoErrors();

        $c = CommandCenter::for((int) $this->business->id, $this->owner);

        $this->assertNotContains('task', collect($c['late']['rows'])->pluck('kind')->all());
        $this->assertSame([], $c['communications']['rows'], 'ما نجحت إعادتُه لم يعد ينتظر');
        $this->assertSame(['project_closable'], collect($c['delivery']['rows'])->pluck('kind')->all(), 'قُبل التسليم — بقي الإقفال');
        $this->assertSame('أقفل المشروع', $c['delivery']['rows'][0]['action']);
    }

    /* ─────────────────────────── العزل والأذون ─────────────────────────── */

    public function test_nothing_of_another_company_reaches_the_screen(): void
    {
        $this->busyMorning();

        $rival = Business::create(['name' => 'منافس', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $rival->id, 'name' => 'الرئيسي']);
        $stranger = User::create(['business_id' => $rival->id, 'name' => 'غريب', 'email' => 'x@rival.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط']);

        $c = CommandCenter::for((int) $rival->id, $stranger);
        $this->assertSame(0, array_sum($c['summary']));
        $this->assertSame([], $c['team']['rows']);
        $this->assertSame(0.0, $c['money']['receivables']['total']);

        $this->actingAs($stranger)->get(route('admin.command.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Command/Index')->where('center.summary.late', 0)->etc());
    }

    public function test_the_section_is_a_door_of_its_own_and_money_is_for_those_who_hold_it(): void
    {
        $this->busyMorning();

        $accountant = User::create(['business_id' => $this->business->id, 'name' => 'المحاسب', 'email' => 'acc@hadath.om',
            'password' => bcrypt('password'), 'role' => 'accountant', 'status' => 'نشط']);
        $this->assertContains($this->actingAs($accountant)->get(route('admin.command.index'))->getStatusCode(), [403, 302],
            'القسمُ لا يُفتح بالدور وحده — يُمنح بالاسم');

        /* مُنح القسمَ بلا مال: يرى ما يُفعل ولا يرى كم */
        $granted = User::create(['business_id' => $this->business->id, 'name' => 'منسّق أُذن له', 'email' => 'g@hadath.om',
            'password' => bcrypt('password'), 'role' => 'delivery', 'status' => 'نشط', 'permissions' => ['dashboard', 'command', 'projects']]);

        $this->actingAs($granted)->get(route('admin.command.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Command/Index')
                ->where('center.sees_money', false)
                ->where('center.money', null)
                ->where('center.summary.late', 5)
                ->etc());

        $c = CommandCenter::for((int) $this->business->id, $granted);
        $this->assertSame([], array_filter(array_column($c['late']['rows'], 'amount')), 'لا مبلغَ يصل من لا يملك رؤيته');
        $this->assertSame([], $c['blockers']['rows'][0]['financial'] === [] ? [] : array_diff($c['blockers']['rows'][0]['financial'],
            ['ذمم متأخّرة على العميل', '1 سندات موردين بانتظار اعتماد']), 'عوائقُ المال بلا أرقامٍ لمن لا يرى المال');

        /* والمالكُ يرى كلَّ شيء من الشاشة */
        $this->actingAs($this->owner)->get(route('admin.command.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('center.sees_money', true)->where('center.money.receivables.overdue_count', 1)->etc());
    }
}
