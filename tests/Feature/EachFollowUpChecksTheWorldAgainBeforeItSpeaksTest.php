<?php

namespace Tests\Feature;

use App\Mail\DocumentMail;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Communication;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\DeliveryPackage;
use App\Models\InternalNotification;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\User;
use App\Support\Automation\Engine;
use App\Support\Automation\Rules;
use App\Support\Automation\Settings;
use App\Support\CommandCenter;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\DeliveryPackages;
use App\Support\Projects;
use App\Support\Quotations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * كلُّ متابعةٍ تفحص العالمَ من جديد قبل أن تتكلّم — المرحلة 8: المُطلِقات.
 *
 * عرضٌ قُبل أو أُلغي قبل موعد التذكير يُتخطّى · فاتورةٌ سُدّدت تُتخطّى وما
 * زال عليها رصيدٌ تُذكَّر · حزمةٌ قُبلت تُتخطّى · مهمّةٌ أُنجزت تُتخطّى ·
 * والتصعيدُ يمشي بخطواته يومًا بعد يوم · ورسالةٌ لم تصل تُصعَّد وتُعاد
 * مرّةً إن أُذن · والمشروعُ الجاهز يُذكَّر بإقفاله ولا يُقفل · والموجزُ
 * الصباحيّ يقرأ مركزَ العمليات بصلاحيّات متلقّيه.
 */
class EachFollowUpChecksTheWorldAgainBeforeItSpeaksTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $seller;

    private User $coordinator;

    private Customer $customer;

    private Product $service;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-09-20 10:00:00', 'Asia/Muscat'));

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط', 'email' => 'hello@hadath.om']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->owner = User::create(['business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@hadath.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط']);
        $this->seller = User::create(['business_id' => $this->business->id, 'name' => 'البائع', 'email' => 'sales@hadath.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط']);
        $this->coordinator = User::create(['business_id' => $this->business->id, 'name' => 'المنسّق', 'email' => 'field@hadath.om',
            'password' => bcrypt('password'), 'role' => 'delivery', 'status' => 'نشط']);
        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة الطاقة', 'email' => 'buyer@ministry.om']);
        $this->service = Product::create(['business_id' => $this->business->id, 'name' => 'خدمة', 'kind' => Product::SERVICE, 'price' => 500]);

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.om']);
        Mail::fake();

        Settings::save((int) $this->business->id, ['mode' => Settings::LIVE], $this->owner);
        Rules::ensure((int) $this->business->id);
        AutomationRule::where('business_id', $this->business->id)->update(['enabled' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ─────────────────────────── الأدوات ─────────────────────────── */

    private function rule(string $key, array $over = []): AutomationRule
    {
        $rule = AutomationRule::where('business_id', $this->business->id)->where('key', $key)->firstOrFail();

        return Rules::save($rule, $over + ['enabled' => true, 'mode' => AutomationRule::LIVE], $this->owner);
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse($time, 'Asia/Muscat'));
    }

    /** عرضٌ أُرسل للعميل قبل أيّام */
    private function sentQuotation(int $daysAgo, array $over = []): Quotation
    {
        $this->actingAs($this->seller);
        $this->at(now()->subDays($daysAgo)->format('Y-m-d').' 09:30:00');
        $q = Quotations::create((int) $this->business->id, $this->customer, $over + [
            'title' => 'مؤتمر الطاقة', 'issued_at' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(),
            'event_starts_at' => now()->addDays(20)->toDateString(), 'event_ends_at' => now()->addDays(21)->toDateString(),
            'contact_email' => 'buyer@ministry.om',
        ], [['product_id' => $this->service->id, 'quantity' => 2, 'unit_price' => 500]], $this->seller);
        $q = Quotations::send($q, $this->seller);
        $this->at('2026-09-20 10:00:00');
        auth()->logout();

        return $q->fresh();
    }

    private function project(int $startsInDays = 5): Project
    {
        $q = $this->sentQuotation(10, ['event_starts_at' => now()->addDays($startsInDays)->toDateString(), 'event_ends_at' => now()->addDays($startsInDays + 1)->toDateString()]);
        $this->actingAs($this->owner);
        Quotations::recordDecision($q, Quotation::ACCEPTED, [], $this->owner);
        $project = Project::where('quotation_id', $q->id)->firstOrFail();
        $project->update(['status' => Project::IN_PROGRESS, 'owner_id' => $this->owner->id]);
        auth()->logout();

        return $project->fresh();
    }

    private function sentPackage(Project $project, int $daysAgo): DeliveryPackage
    {
        $this->actingAs($this->owner);
        $this->at(now()->subDays($daysAgo)->format('Y-m-d').' 11:00:00');
        $package = DeliveryPackages::create((int) $this->business->id, null, ['project_id' => $project->id], $this->owner);
        DeliveryPackages::addItem($package, ['kind' => 'file', 'title' => 'تقرير'], UploadedFile::fake()->create('r.pdf', 5, 'application/pdf'), $this->owner);
        $package = DeliveryPackages::send(DeliveryPackages::ready($package->fresh(), $this->owner), $this->owner);
        $this->at('2026-09-20 10:00:00');
        auth()->logout();

        return $package->fresh();
    }

    private function invoice(int $dueInDays, ?Project $project = null): CustomerInvoice
    {
        $invoice = CustomerInvoices::create((int) $this->business->id, $this->customer, [
            'issued_at' => now()->subDays(10)->toDateString(), 'due_at' => now()->addDays($dueInDays)->toDateString(), 'project_id' => $project?->id,
        ], [['product_id' => $this->service->id, 'description' => 'خدمة', 'quantity' => 1, 'unit_price' => 100]], $this->owner->id);

        return CustomerInvoices::issue($invoice, $this->owner->id);
    }

    /* ─────────────────────────── عرض السعر ─────────────────────────── */

    public function test_a_quotation_still_waiting_is_followed_up_but_an_accepted_or_cancelled_one_is_not(): void
    {
        $waiting = $this->sentQuotation(3);
        $accepted = $this->sentQuotation(3, ['title' => 'عرض قُبل']);
        $cancelled = $this->sentQuotation(3, ['title' => 'عرض أُلغي']);
        $this->actingAs($this->owner);
        Quotations::recordDecision($accepted, Quotation::ACCEPTED, [], $this->owner);
        Quotations::cancel($cancelled, 'لم يعد مطلوبًا', $this->owner);
        auth()->logout();

        $this->rule('quotation.followup');
        Engine::tick((int) $this->business->id);

        $runs = AutomationRun::get()->keyBy('target_id');
        $this->assertSame(AutomationRun::EXECUTED, $runs[$waiting->id]->status);
        /* المقبولُ والملغى لا يُكشفان أصلًا: الاستعلامُ يسأل عمّا ينتظر */
        $this->assertArrayNotHasKey($accepted->id, $runs->all());
        $this->assertArrayNotHasKey($cancelled->id, $runs->all());

        Mail::assertSentCount(1);
        Mail::assertSent(DocumentMail::class, fn (DocumentMail $m) => $m->hasTo('buyer@ministry.om') && str_starts_with($m->intent->subject, 'تذكير'));

        $c = Communication::sole();
        $this->assertSame($waiting->id, (int) $c->subject_id);
        $this->assertSame('quotation_awaiting', $c->metadata['trigger']);

        /* ولا تتغيّر حالُ العرض بيد الأتمتة */
        $this->assertSame(Quotation::SENT, $waiting->fresh()->status);
        $this->assertSame(Quotation::ACCEPTED, $accepted->fresh()->status);
        $this->assertSame(Quotation::CANCELLED, $cancelled->fresh()->status);
    }

    public function test_a_quotation_accepted_between_evaluation_and_execution_is_skipped(): void
    {
        $q = $this->sentQuotation(3);
        $this->rule('quotation.followup');
        /* خارجَ الدوام: يُفتح الصفُّ ويُؤجَّل — ثمّ يقبل العميل قبل الصباح */
        $this->at('2026-09-20 20:00:00');
        Engine::tick((int) $this->business->id);
        $this->assertSame(AutomationRun::PENDING, AutomationRun::sole()->status);

        $this->actingAs($this->owner);
        Quotations::recordDecision($q->fresh(), Quotation::ACCEPTED, [], $this->owner);
        auth()->logout();

        $this->at('2026-09-21 09:30:00');
        Engine::tick((int) $this->business->id);

        $this->assertSame(Engine::NOT_ELIGIBLE, AutomationRun::sole()->skipped_reason);
        Mail::assertNothingSent();
    }

    public function test_quotation_expiry_alerts_its_author_and_never_the_client_after_it_lapsed(): void
    {
        $q = $this->sentQuotation(1, ['valid_until' => now()->toDateString()]);
        $this->rule('quotation.expiry', ['steps' => [['offset' => 0, 'audience' => 'creator'], ['offset' => 0, 'audience' => 'customer']],
            'channel' => 'email', 'template_key' => 'quotation.reminder']);

        /* اليومُ الأخير: العرضُ ما زال صالحًا — الكاتبُ يُنبَّه، والعميلُ يُذكَّر */
        Engine::tick((int) $this->business->id);
        $this->assertSame(1, InternalNotification::where('user_id', $this->seller->id)->count());
        $this->assertSame(1, Communication::where('template_key', 'quotation.reminder')->count());

        /* وبعد الانقضاء لا يُذكَّر العميلُ بعرضٍ مات — الكاتبُ وحدَه */
        $late = $this->sentQuotation(2, ['title' => 'عرض قديم', 'valid_until' => now()->subDay()->toDateString()]);
        $this->rule('quotation.expiry', ['steps' => [['offset' => 1, 'audience' => 'creator'], ['offset' => 1, 'audience' => 'customer']],
            'channel' => 'email', 'template_key' => 'quotation.reminder', 'cooldown_hours' => 1]);
        Engine::tick((int) $this->business->id);

        $runs = AutomationRun::where('target_id', $late->id)->get()->keyBy('audience');
        $this->assertSame(AutomationRun::EXECUTED, $runs['creator']->status);
        $this->assertSame(Engine::NOT_ELIGIBLE, $runs['customer']->skipped_reason);
        $this->assertSame(1, Communication::where('template_key', 'quotation.reminder')->count());
        $this->assertSame(Quotation::SENT, $late->fresh()->status, 'ولا يُغيّر المحرّكُ حالَ العرض المنقضي');
    }

    /* ─────────────────────────── الفاتورة ─────────────────────────── */

    public function test_an_invoice_paid_before_the_reminder_is_skipped_and_an_outstanding_one_is_reminded(): void
    {
        $paid = $this->invoice(0);
        $owed = $this->invoice(0);
        CustomerPayments::record((int) $this->business->id, $this->customer, (float) $paid->total,
            ['method' => 'نقدي', 'occurred_at' => now()->toDateString()], [$paid->id => (float) $paid->total], $this->owner->id);

        $this->rule('invoice.due', ['channel' => 'email', 'template_key' => 'invoice.reminder']);
        Engine::tick((int) $this->business->id);

        $runs = AutomationRun::get()->keyBy('target_id');
        $this->assertArrayNotHasKey($paid->id, $runs->all(), 'المسدَّدةُ لا تُكشف أصلًا: الاستعلامُ يسأل عن رصيد');
        $this->assertSame(AutomationRun::EXECUTED, $runs[$owed->id]->status);
        Mail::assertSentCount(1);

        /* وما سُدّد بين الكشف والتنفيذ يُتخطّى بسببه */
        $this->at('2026-09-20 20:00:00');
        $later = $this->invoice(1);
        $this->at('2026-09-21 20:00:00');
        Engine::tick((int) $this->business->id);
        $this->assertSame(AutomationRun::PENDING, AutomationRun::where('target_id', $later->id)->sole()->status);
        CustomerPayments::record((int) $this->business->id, $this->customer, (float) $later->total,
            ['method' => 'نقدي', 'occurred_at' => now()->toDateString()], [$later->id => (float) $later->total], $this->owner->id);
        $this->at('2026-09-22 09:30:00');
        Engine::tick((int) $this->business->id);
        $this->assertSame(Engine::SETTLED, AutomationRun::where('target_id', $later->id)->sole()->skipped_reason);
        Mail::assertSentCount(1);
    }

    public function test_the_invoice_escalation_climbs_from_client_to_finance_to_management(): void
    {
        $invoice = $this->invoice(-3);
        $this->rule('invoice.overdue', ['channel' => 'email', 'template_key' => 'invoice.reminder', 'cooldown_hours' => 20]);
        $accountant = User::create(['business_id' => $this->business->id, 'name' => 'المحاسب', 'email' => 'acc@hadath.om',
            'password' => bcrypt('p'), 'role' => 'accountant', 'status' => 'نشط']);

        $toClient = fn () => Communication::where('template_key', 'invoice.reminder')->where('recipient', 'buyer@ministry.om')->count();

        /* اليوم الثالث: العميل */
        Engine::tick((int) $this->business->id);
        $this->assertSame(1, $toClient());
        $this->assertSame(0, InternalNotification::count());

        /* اليوم السابع: العميل ثانيةً + المالية (في الجرس وبالبريد لأنّ قناة القاعدة بريد) */
        $this->at('2026-09-24 10:00:00');
        Engine::tick((int) $this->business->id);
        $this->assertSame(2, $toClient());
        $this->assertSame(1, InternalNotification::where('user_id', $accountant->id)->count());
        $this->assertGreaterThanOrEqual(1, InternalNotification::where('user_id', $this->owner->id)->count(), 'المالكُ يملك المالية أيضًا');
        Mail::assertSent(DocumentMail::class, fn (DocumentMail $m) => $m->hasTo('acc@hadath.om') && $m->intent->templateKey === 'automation.notice');

        /* اليوم الرابع عشر: الإدارة — ولا رسالةَ للعميل */
        $this->at('2026-10-01 10:00:00');
        Engine::tick((int) $this->business->id);
        $this->assertSame(2, $toClient());
        $management = InternalNotification::where('kind', 'invoice_due')->where('user_id', $this->owner->id)->count();
        $this->assertSame(2, $management);

        /* والفاتورةُ كما هي: صادرةٌ وعليها رصيدُها كلُّه */
        $this->assertSame(CustomerInvoice::ISSUED, $invoice->fresh()->status);
        $this->assertSame(105.0, $invoice->fresh()->outstanding());
        $this->assertSame(0, CustomerPayment::count());

        /* ولا تكرار: الدورةُ تعاد في اليوم نفسِه بلا أثر */
        Engine::tick((int) $this->business->id);
        $this->assertSame(2, $toClient());
        $this->assertSame(4, AutomationRun::count());
    }

    /* ─────────────────────────── المهامّ ─────────────────────────── */

    public function test_task_reminders_escalate_day_by_day_and_stop_when_the_task_is_done(): void
    {
        $project = $this->project(30);
        $task = ProjectTask::create(['business_id' => $this->business->id, 'project_id' => $project->id, 'title' => 'حجز القاعة',
            'status' => ProjectTask::OPEN, 'priority' => 'high', 'due_at' => '2026-09-21', 'assignee_id' => $this->coordinator->id]);
        $this->rule('task.due');

        /* قبل يوم: المسؤول */
        Engine::tick((int) $this->business->id);
        Engine::tick((int) $this->business->id);
        $this->assertSame(1, InternalNotification::where('user_id', $this->coordinator->id)->count());
        $this->assertStringContainsString('تستحقّ غدًا', InternalNotification::first()->title);
        $this->assertSame(route('admin.projects.show', $project->id), InternalNotification::first()->url);

        /* في اليوم، ثمّ بعد يوم، ثمّ بعد ثلاثة */
        foreach (['2026-09-21', '2026-09-22', '2026-09-24'] as $day) {
            $this->at($day.' 10:00:00');
            Engine::tick((int) $this->business->id);
        }
        $this->assertSame(4, InternalNotification::where('user_id', $this->coordinator->id)->count());
        $this->assertSame(0, InternalNotification::where('user_id', $this->owner->id)->count());

        /* بعد خمسة: صاحبُ المشروع */
        $this->at('2026-09-26 10:00:00');
        Engine::tick((int) $this->business->id);
        $this->assertSame(1, InternalNotification::where('user_id', $this->owner->id)->count());
        $this->assertStringContainsString('متأخّرة منذ 5', InternalNotification::where('user_id', $this->owner->id)->first()->title);

        /* وتُنجَز المهمّة: لا شيء بعدها — ولو أُعيدت الدورة */
        $task->update(['status' => ProjectTask::DONE, 'completed_at' => now()]);
        $this->at('2026-09-27 10:00:00');
        Engine::tick((int) $this->business->id);
        $this->assertSame(5, InternalNotification::count());
        $this->assertSame(ProjectTask::DONE, $task->fresh()->status);
    }

    public function test_a_task_finished_before_its_reminder_is_skipped(): void
    {
        $project = $this->project(30);
        $task = ProjectTask::create(['business_id' => $this->business->id, 'project_id' => $project->id, 'title' => 'حجز القاعة',
            'status' => ProjectTask::OPEN, 'priority' => 'high', 'due_at' => '2026-09-20', 'assignee_id' => $this->coordinator->id]);
        $this->rule('task.due');
        $task->update(['status' => ProjectTask::DONE, 'completed_at' => now()]);

        Engine::tick((int) $this->business->id);

        $this->assertSame(0, AutomationRun::count(), 'المنجَزةُ لا تُكشف أصلًا');
        $this->assertSame(0, InternalNotification::count());
    }

    /* ─────────────────────────── المشروع ─────────────────────────── */

    public function test_project_start_reminders_name_what_is_missing(): void
    {
        $project = $this->project(3);
        ProjectTask::create(['business_id' => $this->business->id, 'project_id' => $project->id, 'title' => 'متأخّرة',
            'status' => ProjectTask::OPEN, 'priority' => 'high', 'due_at' => '2026-09-18', 'assignee_id' => $this->coordinator->id]);
        $this->rule('project.start');

        Engine::tick((int) $this->business->id);

        $n = InternalNotification::where('user_id', $this->owner->id)->sole();
        $this->assertStringContainsString('يبدأ بعد 3 أيام', $n->title);
        $this->assertStringContainsString('المتطلّبات لم تُثبَّت', $n->title);
        $this->assertStringContainsString('1 مهامّ متأخّرة', $n->title);
        $this->assertStringContainsString('لا فريق مسنَد', $n->title);
        $this->assertSame(Project::IN_PROGRESS, $project->fresh()->status);
    }

    public function test_a_project_ready_to_close_is_reminded_and_never_closed(): void
    {
        $project = $this->project(-10);
        $package = $this->sentPackage($project, 2);
        $this->post(DeliveryPackages::url($package).'/decision', ['decision' => 'accepted', 'name' => 'سالم', 'consent' => '1'])->assertSessionHasNoErrors();
        $this->rule('project.closable');

        Engine::tick((int) $this->business->id);

        $n = InternalNotification::where('user_id', $this->owner->id)->where('kind', 'project_closable')->sole();
        $this->assertStringContainsString('أقفله', $n->title);
        $this->assertSame(Project::IN_PROGRESS, $project->fresh()->status, 'لا يُقفل المشروع وحده');

        /* وبعد الإقفال بيد لا تذكيرَ ثانيًا — القاعدةُ تسأل عن مشروعٍ مفتوح */
        $this->actingAs($this->owner);
        Projects::close($project->fresh(), $this->owner);
        auth()->logout();
        $this->at('2026-09-23 10:00:00');
        Engine::tick((int) $this->business->id);
        $this->assertSame(1, InternalNotification::where('kind', 'project_closable')->count());
    }

    /* ─────────────────────────── التسليم ─────────────────────────── */

    public function test_a_delivery_still_waiting_is_followed_up_and_an_accepted_one_is_skipped(): void
    {
        $project = $this->project(-5);
        $waiting = $this->sentPackage($project, 2);
        $accepted = $this->sentPackage($this->project(-6), 2);
        $this->post(DeliveryPackages::url($accepted).'/decision', ['decision' => 'accepted', 'name' => 'سالم', 'consent' => '1'])->assertSessionHasNoErrors();
        Mail::assertSentCount(1); // خبرُ القبول إلى الشركة — من المرحلة 5

        $this->rule('delivery.followup', ['channel' => 'email', 'template_key' => 'delivery.reminder']);
        Engine::tick((int) $this->business->id);

        $runs = AutomationRun::get()->keyBy('target_id');
        $this->assertSame(AutomationRun::EXECUTED, $runs[$waiting->id]->status);
        $this->assertArrayNotHasKey($accepted->id, $runs->all(), 'المقبولةُ لا تُكشف');
        Mail::assertSentCount(2);
        $this->assertSame(DeliveryPackage::SENT, $waiting->fresh()->status);
        $this->assertSame(DeliveryPackage::ACCEPTED, $accepted->fresh()->status);

        /* ويقبلها العميل بين الكشف والتنفيذ: تُكشف مساءً فتُؤجَّل، ويقبل قبل الصباح */
        $late = $this->sentPackage($this->project(-7), 2);
        $this->at('2026-09-20 20:00:00');
        Engine::tick((int) $this->business->id);
        $this->assertSame(AutomationRun::PENDING, AutomationRun::where('target_id', $late->id)->sole()->status);
        $this->post(DeliveryPackages::url($late).'/decision', ['decision' => 'accepted', 'name' => 'سالم', 'consent' => '1'])->assertSessionHasNoErrors();
        $this->at('2026-09-21 09:30:00');
        Engine::tick((int) $this->business->id);
        $this->assertSame(Engine::NOT_ELIGIBLE, AutomationRun::where('target_id', $late->id)->sole()->skipped_reason);
    }

    /* ─────────────────────────── الرسائل التي لم تصل ─────────────────────────── */

    public function test_a_failed_communication_is_escalated_and_retried_once_only_when_allowed(): void
    {
        $q = $this->sentQuotation(1);
        $this->actingAs($this->seller);
        $this->post(route('admin.quotations.email', $q->id), ['to' => 'buyer@ministry.om'])->assertSessionHasNoErrors();
        auth()->logout();
        $c = Communication::sole();
        $c->update(['status' => Communication::BOUNCED, 'failed_at' => now()->subMinutes(10), 'failure_code' => 'HardBounce', 'failure_reason' => 'mailbox not found']);

        /* الافتراض: تنبيهٌ لمن أرسل وللإدارة — ولا إعادة */
        $this->rule('communication.failed');
        Engine::tick((int) $this->business->id);

        $this->assertSame(1, InternalNotification::where('user_id', $this->seller->id)->count());
        $this->assertSame(1, InternalNotification::where('user_id', $this->owner->id)->count());
        $this->assertStringContainsString('mailbox not found', InternalNotification::first()->title);
        $this->assertSame(route('admin.communications.show', $c->id), InternalNotification::first()->url);
        $this->assertSame(1, Communication::count(), 'لا إعادةَ بلا إذن');

        /* بالإذن: إعادةٌ واحدة عبر قواعد المرحلة 6 — ثمّ لا ثانية */
        $second = $this->sentQuotation(1, ['title' => 'عرض ثانٍ']);
        $this->actingAs($this->seller);
        $this->post(route('admin.quotations.email', $second->id), ['to' => 'buyer@ministry.om'])->assertSessionHasNoErrors();
        auth()->logout();
        $failed = Communication::where('subject_id', $second->id)->sole();
        $failed->update(['status' => Communication::FAILED, 'failed_at' => now()->subMinutes(5), 'failure_reason' => 'timeout']);
        $this->rule('communication.failed', ['retry_once' => true, 'steps' => [['offset' => 0, 'audience' => 'initiator']], 'cooldown_hours' => 1]);

        Engine::tick((int) $this->business->id);

        $retry = Communication::where('retry_of_id', $failed->id)->sole();
        $this->assertSame(Communication::SENT, $retry->status);
        $run = AutomationRun::where('target_id', $failed->id)->sole();
        $this->assertSame($retry->id, (int) $run->communication_id);
        $this->assertStringContainsString('أُعيد الإرسال مرّةً واحدة', $run->result);

        $this->at('2026-09-21 10:00:00');
        Engine::tick((int) $this->business->id);
        $this->assertSame(1, Communication::where('retry_of_id', $failed->id)->count(), 'مرّةٌ واحدة لا أكثر');
    }

    /* ─────────────────────────── الموجز الصباحيّ ─────────────────────────── */

    public function test_the_morning_brief_reads_the_command_center_once_a_day_per_recipient_with_their_own_eyes(): void
    {
        $project = $this->project(0);
        $this->invoice(-4, $project);
        $rule = $this->rule('brief.morning', ['send_time' => '07:30', 'steps' => [['offset' => 0, 'audience' => 'custom']],
            'recipients' => [$this->owner->id, $this->coordinator->id]]);

        /* شركةٌ أخرى لها فاتورةٌ متأخّرة لا يجب أن تظهر في موجزنا */
        $rival = Business::create(['name' => 'منافس', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create(['business_id' => $rival->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true]);
        $stranger = User::create(['business_id' => $rival->id, 'name' => 'غريب', 'email' => 'x@rival.om', 'password' => bcrypt('p'), 'role' => 'admin', 'status' => 'نشط']);
        $theirCustomer = Customer::create(['business_id' => $rival->id, 'name' => 'شركة نفط', 'email' => 'oil@rival.om']);
        $theirProduct = Product::create(['business_id' => $rival->id, 'name' => 'خدمة', 'kind' => Product::SERVICE, 'price' => 9000]);
        CustomerInvoices::issue(CustomerInvoices::create((int) $rival->id, $theirCustomer, ['issued_at' => now()->subDays(20)->toDateString(), 'due_at' => now()->subDays(5)->toDateString()],
            [['product_id' => $theirProduct->id, 'description' => 'خدمة', 'quantity' => 1, 'unit_price' => 9000]], $stranger->id), $stranger->id);

        Engine::tick((int) $this->business->id);
        Engine::tick((int) $this->business->id);

        $run = AutomationRun::where('automation_rule_id', $rule->id)->sole();
        $this->assertSame(AutomationRun::EXECUTED, $run->status);
        $this->assertSame(2, $run->notification_count);

        $ownerBrief = InternalNotification::where('user_id', $this->owner->id)->where('kind', 'morning_brief')->sole();
        $this->assertStringContainsString('مشاريع اليوم: 1', $ownerBrief->body);
        $this->assertStringContainsString('مال يُحصَّل', $ownerBrief->body);
        $this->assertStringContainsString('105', $ownerBrief->body, 'فاتورتُنا وحدَها');
        $this->assertStringNotContainsString('9,000', $ownerBrief->body, 'لا شيء من الشركة الأخرى');
        $this->assertStringNotContainsString('شركة نفط', $ownerBrief->body);

        $fieldBrief = InternalNotification::where('user_id', $this->coordinator->id)->where('kind', 'morning_brief')->sole();
        $this->assertStringNotContainsString('مال يُحصَّل', $fieldBrief->body, 'المنسّقُ لا يرى المال');

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $m) => $m->hasTo('owner@hadath.om') && $m->intent->templateKey === 'automation.brief');
        $this->assertSame(2, Communication::where('template_key', 'automation.brief')->count());
        $this->assertTrue((bool) Communication::where('template_key', 'automation.brief')->first()->metadata['internal']);

        /* وغدًا موجزٌ جديد — واليومَ لا ثانٍ */
        $this->at('2026-09-21 08:00:00');
        Engine::tick((int) $this->business->id);
        $this->assertSame(2, AutomationRun::where('automation_rule_id', $rule->id)->count());

        /* والغريبُ لا يراه في جرسه */
        $this->assertSame(0, InternalNotification::where('user_id', $stranger->id)->count());
    }

    public function test_internal_notifications_reach_the_bell_and_are_read_by_their_owner_only(): void
    {
        $project = $this->project(1);
        $this->rule('project.start', ['steps' => [['offset' => -1, 'audience' => 'owner']]]);
        Engine::tick((int) $this->business->id);
        $n = InternalNotification::where('user_id', $this->owner->id)->sole();

        $feed = $this->actingAs($this->owner)->getJson(route('admin.notifications.feed'))->assertOk()->json();
        $this->assertTrue(collect($feed['items'])->contains(fn ($i) => $i['key'] === 'internal-'.$n->id && str_contains($i['text'], 'يبدأ غدًا')));

        $this->actingAs($this->seller)->postJson(route('admin.notifications.internal.read', $n->id))->assertNotFound();
        $this->assertNull($n->fresh()->read_at);

        $this->actingAs($this->owner)->postJson(route('admin.notifications.internal.read', $n->id))->assertOk();
        $this->assertNotNull($n->fresh()->read_at);
        $feed = $this->actingAs($this->owner)->getJson(route('admin.notifications.feed'))->assertOk()->json();
        $this->assertFalse(collect($feed['items'])->contains(fn ($i) => $i['key'] === 'internal-'.$n->id));
        $this->assertSame(Project::IN_PROGRESS, $project->fresh()->status);
    }

    public function test_the_command_center_surfaces_what_the_automation_could_not_do(): void
    {
        $this->customer->update(['email' => null]);
        $this->invoice(0);
        $this->rule('invoice.due', ['channel' => 'email', 'template_key' => 'invoice.reminder']);
        Engine::tick((int) $this->business->id);
        $this->assertSame(Engine::NO_RECIPIENT, AutomationRun::sole()->skipped_reason);

        $c = CommandCenter::for((int) $this->business->id, $this->owner);
        $this->assertSame(1, $c['summary']['automation']);
        $this->assertSame('automation_attention', $c['automation']['rows'][0]['kind']);
        $this->assertSame('أكمل بيانات الاتصال', $c['automation']['rows'][0]['action']);
        $this->assertFalse($c['automation']['paused']);

        Settings::pause((int) $this->business->id, true, $this->owner);
        $c = CommandCenter::for((int) $this->business->id, $this->owner);
        $this->assertTrue($c['automation']['paused']);
    }
}
