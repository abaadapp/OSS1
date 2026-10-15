<?php

namespace Tests\Feature\Operations;

use App\Models\ActivityLog;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Communication;
use App\Models\EventOperation;
use App\Models\EventTaskDispatch;
use App\Models\EventTaskLink;
use App\Models\InternalNotification;
use App\Models\ProjectTask;
use App\Models\User;
use App\Support\Automation\Engine;
use App\Support\Automation\Rules;
use App\Support\Automation\Settings;
use App\Support\Communication\Templates;
use App\Support\Operations\EventTasks;
use App\Support\PhoneNumber;
use App\Support\Projects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use RuntimeException;

/**
 * لكلّ موظّفٍ رسالةٌ واحدة بمهامّه هو في الفعاليّة — والمهمّةُ مصدرُ الحقيقة.
 *
 * `ProjectTask` بعمودها `event_operation_id` هي المهمّة، لا نسخةَ عنها. والنشرُ
 * مرساةُ رسالة التعيين في الأتمتة، والتذكيرُ يحسب المفتوحَ وحده. ومنعُ التكرار
 * مفتاحٌ فريد (الشركة · الفعاليّة · الموظّف · المُطلِق · الخطوة). والرابطُ يفتح
 * مهامَّ صاحبه وحدها، و«تمّت» من بابٍ واحد مع شاشة المشروع. وواتساب إن لم يخرج
 * لا يُسقط شيئًا: يُقيَّد فاشلًا ويُنبَّه الموظّفُ داخل النظام بالرابط.
 */
class EachEmployeeGetsOneMessageWithOnlyTheirEventTasksTest extends OperationsTestCase
{
    use RefreshDatabase;

    private EventOperation $op;

    /** @var array<string, ProjectTask> */
    private array $tasks = [];

    protected function setUp(): void
    {
        parent::setUp();
        Settings::save((int) $this->business->id, ['mode' => Settings::LIVE], $this->owner);
        Rules::ensure((int) $this->business->id);
        AutomationRule::where('business_id', $this->business->id)->whereNotIn('trigger', [EventTasks::ASSIGNED, EventTasks::REMINDER])->update(['enabled' => false]);
        AutomationRule::where('business_id', $this->business->id)->whereIn('trigger', [EventTasks::ASSIGNED, EventTasks::REMINDER])->update(['mode' => AutomationRule::LIVE]);

        foreach (EventTasks::TEMPLATES as $key) {
            Templates::save((int) $this->business->id, $key, 'whatsapp', 'ar', ['provider_template' => str_replace('.', '_', $key), 'provider_language' => 'ar'], $this->owner);
        }
        $this->whatsapp(true);

        $this->op = $this->operation(['name' => 'معرض التقنية', 'venue_name' => 'مركز عمان للمؤتمرات']);
        $add = fn (string $title, User $who, ?EventOperation $op, string $priority = 'normal') => $this->tasks[$title] = Projects::addTask($this->project,
            ['title' => $title, 'assignee_id' => $who->id, 'event_operation_id' => $op?->id, 'priority' => $priority, 'due_at' => '2026-10-01'], $this->owner);

        foreach (['تجهيز شاشة الاستقبال', 'التأكد من الصوت', 'استلام بطاقات الفريق'] as $title) {
            $add($title, $this->field, $this->op);
        }
        $add('اختبار العرض', $this->storekeeper, $this->op, 'high');
        $add('تسليم التقرير', $this->storekeeper, $this->op);
        $add('مهمة عامة للمشروع', $this->field, null);
    }

    private function whatsapp(bool $on): void
    {
        config(['services.whatsapp.token' => $on ? 'SECRET_WA' : null, 'services.whatsapp.phone_number_id' => $on ? '1050' : null]);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.Str::random(10)]]])]);
    }

    private function publish(): void
    {
        $this->actingAs($this->manager)->post(route('admin.operations.tasks.publish', $this->op->id))->assertSessionHasNoErrors();
        auth()->logout();
    }

    /** @return list<array<string, mixed>> */
    private function sent(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0]->data())->values()->all();
    }

    private function tokenOf(User $user): string
    {
        return EventTasks::token($this->op->fresh(), $user);
    }

    private function params(array $message, string $type): array
    {
        $component = collect($message['template']['components'])->firstWhere('type', $type);

        return array_column($component['parameters'] ?? [], 'text');
    }

    /* ─────────────────────────── الرسالة ─────────────────────────── */

    public function test_each_employee_gets_one_message_with_only_their_tasks_in_this_event(): void
    {
        $this->publish();
        Engine::tick((int) $this->business->id);

        $sent = $this->sent();
        $this->assertCount(2, $sent, 'موظّفان ← رسالتان؛ لا رسالةَ لكلّ مهمّة');

        $byPhone = collect($sent)->keyBy('to');
        $field = $byPhone[PhoneNumber::normalize($this->field->phone)];
        $store = $byPhone[PhoneNumber::normalize($this->storekeeper->phone)];

        $this->assertSame('event_tasks_assigned', $field['template']['name']);
        $this->assertSame(['المنفّذ', '3', 'معرض التقنية', '2026-10-01', 'مركز عمان للمؤتمرات'], array_slice($this->params($field, 'body'), 0, 5), 'مهامّه في الفعاليّة وحدها — لا المهمّةُ العامّة');
        $this->assertSame('2', $this->params($store, 'body')[1]);
        $this->assertSame(['معرض التقنية'], $this->params($field, 'header'));
        $this->assertSame([$this->tokenOf($this->field)], $this->params($field, 'button'), 'زرُّ «عرض المهام» يحمل رمزَ صاحبه');
        $this->assertNotSame($this->params($field, 'button'), $this->params($store, 'button'));

        /* والمراسلةُ عن الفعاليّة لا عن العميل */
        $communications = Communication::where('business_id', $this->business->id)->get();
        $this->assertCount(2, $communications);
        $this->assertTrue($communications->every(fn ($c) => $c->customer_id === null && $c->subject_type === EventOperation::class && $c->status === Communication::SENT));
        $this->assertSame(2, EventTaskDispatch::where('status', EventTaskDispatch::SENT)->count());
        $this->assertTrue(ActivityLog::where('description', 'like', 'أرسل مهامّ الفعاليّة '.$this->op->code.' إلى المنفّذ (3 مفتوحة) — واتساب')->exists());

        /* والرابطُ يفتح مهامّه هو في هذه الفعاليّة */
        $page = $this->get(route('event-tasks.show', $this->tokenOf($this->field)))->assertOk();
        $page->assertSee('تجهيز شاشة الاستقبال')->assertSee('التأكد من الصوت')->assertSee('استلام بطاقات الفريق')
            ->assertDontSee('اختبار العرض')->assertDontSee('مهمة عامة للمشروع')->assertSee('مركز عمان للمؤتمرات')->assertSee('المدير');
    }

    public function test_the_same_step_is_never_sent_twice_and_a_republish_reaches_only_who_was_missed(): void
    {
        $this->publish();
        Engine::tick((int) $this->business->id);
        Engine::tick((int) $this->business->id);
        $this->assertCount(2, $this->sent(), 'دورتان ← رسالتان لا أربع');

        /* ومفتاحُ الموظّف نفسُه يردّ محاولةً ثانية مباشرة */
        $rule = AutomationRule::where('business_id', $this->business->id)->where('trigger', EventTasks::ASSIGNED)->sole();
        $this->assertNull(EventTasks::dispatch($this->op, $this->field, EventTasks::ASSIGNED, 'assigned', $rule));

        /* موظّفٌ جديد ثمّ إعادةُ نشرٍ في اليوم نفسِه — مفتاحُ تشغيل الأتمتة يوميّ: لا تشغيلَ ثانٍ */
        Projects::addTask($this->project, ['title' => 'توزيع البطاقات', 'assignee_id' => $this->seller->id, 'event_operation_id' => $this->op->id], $this->owner);
        Carbon::setTestNow(now()->addHours(2));
        $this->publish();
        Engine::tick((int) $this->business->id);
        $this->assertCount(2, $this->sent(), 'إعادةُ النشر في اليوم نفسِه لا تفتح تشغيلًا ثانيًا');

        /* وفي يومٍ تالٍ — يصل الجديدَ وحده، ولا يُعاد لمن وصله */
        Carbon::setTestNow(now()->addDay());
        $this->publish();
        Engine::tick((int) $this->business->id);

        $this->assertCount(3, $this->sent());
        $this->assertSame(PhoneNumber::normalize($this->seller->phone), $this->sent()[2]['to']);
        $this->assertSame(3, EventTaskDispatch::where('trigger', EventTasks::ASSIGNED)->count());
    }

    public function test_the_reminder_counts_only_open_tasks_and_skips_whoever_finished(): void
    {
        $this->publish();
        Engine::tick((int) $this->business->id);

        /* المنفّذ أنجز واحدةً من رابطه، وأمين المخزن أنجز مهمّتيه كلتيهما */
        $this->post(route('event-tasks.done', [$this->tokenOf($this->field), $this->tasks['التأكد من الصوت']->id]))->assertRedirect();
        Projects::setTaskStatus($this->tasks['اختبار العرض'], ProjectTask::DONE, $this->owner);
        Projects::setTaskStatus($this->tasks['تسليم التقرير'], ProjectTask::DONE, $this->owner);

        Carbon::setTestNow(Carbon::parse('2026-09-30 08:30:00', 'Asia/Muscat'));
        Engine::tick((int) $this->business->id);

        $reminders = collect($this->sent())->filter(fn ($m) => $m['template']['name'] === 'event_tasks_reminder')->values();
        $this->assertCount(1, $reminders, 'من أنهى كلَّ مهامّه لا يُذكَّر');
        $this->assertSame(PhoneNumber::normalize($this->field->phone), $reminders[0]['to']);
        $this->assertSame('2', $this->params($reminders[0], 'body')[1], 'المنجزةُ لا تدخل التذكير');
        $this->assertSame(1, EventTaskDispatch::where('trigger', EventTasks::REMINDER)->where('step', '-1@2026-10-01')->count());
    }

    /* ─────────────────────────── «تمّت» ─────────────────────────── */

    public function test_done_from_the_link_writes_completed_at_through_the_one_door_and_tells_the_lead_when_all_are_done(): void
    {
        $token = $this->tokenOf($this->storekeeper);
        $this->post(route('event-tasks.done', [$token, $this->tasks['اختبار العرض']->id]))
            ->assertRedirect(route('event-tasks.show', $token))->assertSessionHas('event_tasks_ok');

        $task = $this->tasks['اختبار العرض']->fresh();
        $this->assertSame(ProjectTask::DONE, $task->status);
        $this->assertNotNull($task->completed_at);
        $this->assertTrue(ActivityLog::where('description', 'like', 'أنهى المهمّة «اختبار العرض»%من رابط المهام على واتساب')->exists());
        $this->assertSame(0, InternalNotification::where('kind', 'event_tasks_completed')->count(), 'بقيت له مهمّة');

        $this->post(route('event-tasks.done', [$token, $this->tasks['تسليم التقرير']->id]));
        $notice = InternalNotification::where('kind', 'event_tasks_completed')->sole();
        $this->assertSame($this->manager->id, $notice->user_id, 'قائدُ العمليّات — لا العميل');
        $this->assertSame('أمين المخزن أكمل جميع مهامه في فعالية معرض التقنية', $notice->title);

        /* والصفحةُ تقرأ الحالَ الآن؛ والتراجعُ يمحو `completed_at` */
        $this->get(route('event-tasks.show', $token))->assertOk()->assertSee('0</b>', false);
        $this->post(route('event-tasks.reopen', [$token, $this->tasks['تسليم التقرير']->id]));
        $this->assertNull($this->tasks['تسليم التقرير']->fresh()->completed_at);
        $this->assertTrue(ActivityLog::where('description', 'like', 'أعاد فتح المهمّة «تسليم التقرير»%')->exists());

        /* وشاشةُ المشروع تمرّ بالباب نفسِه */
        $this->actingAs($this->owner)->put(route('admin.projects.tasks.update', [$this->project->id, $this->tasks['تسليم التقرير']->id]),
            ['title' => 'تسليم التقرير', 'status' => 'done'])->assertSessionHasNoErrors();
        $this->assertSame(2, InternalNotification::where('kind', 'event_tasks_completed')->count());
    }

    public function test_the_link_opens_its_owners_tasks_only_and_a_changed_task_id_touches_nothing(): void
    {
        $token = $this->tokenOf($this->field);
        $others = $this->tasks['اختبار العرض'];

        $this->post(route('event-tasks.done', [$token, $others->id]))->assertNotFound();
        $this->post(route('event-tasks.done', [$token, $this->tasks['مهمة عامة للمشروع']->id]))->assertNotFound();
        $this->assertSame(ProjectTask::OPEN, $others->fresh()->status);
        $this->assertSame(ProjectTask::OPEN, $this->tasks['مهمة عامة للمشروع']->fresh()->status);

        /* رمزٌ لا يُعرف، أو رابطٌ جُدّد، أو انتهى — لا يفتح */
        $this->get('/t/'.Str::random(40))->assertNotFound()->assertSee('الرابط لا يفتح');
        $old = $token;
        EventTasks::rotate($this->op, $this->field, $this->manager);
        $this->get(route('event-tasks.show', $old))->assertNotFound();
        $this->post(route('event-tasks.done', [$old, $this->tasks['التأكد من الصوت']->id]))->assertNotFound();

        $fresh = $this->tokenOf($this->field);
        $this->get(route('event-tasks.show', $fresh))->assertOk();
        EventTaskLink::where('user_id', $this->field->id)->update(['expires_at' => now()->subMinute()]);
        $this->get(route('event-tasks.show', $fresh))->assertNotFound();

        /* والرمزُ محفوظٌ ببصمته ونسخةٍ مشفّرة — لا نصًّا صريحًا */
        $this->assertNotSame($fresh, EventTaskLink::where('user_id', $this->field->id)->value('token_hash'));
        $this->assertStringNotContainsString($fresh, (string) \DB::table('event_task_links')->where('user_id', $this->field->id)->value('token'));
    }

    /* ─────────────────────────── الفشل ─────────────────────────── */

    public function test_a_whatsapp_failure_is_recorded_and_notified_in_app_and_loses_no_task(): void
    {
        $this->whatsapp(false);
        $this->publish();
        Engine::tick((int) $this->business->id);

        $this->assertCount(0, $this->sent());
        $this->assertSame(2, EventTaskDispatch::where('status', EventTaskDispatch::FAILED)->count());
        $this->assertSame(2, Communication::where('status', Communication::FAILED)->whereNull('customer_id')->count(), 'يُقيَّد الفشلُ في المراسلات');

        $mine = InternalNotification::where('user_id', $this->field->id)->where('kind', EventTasks::ASSIGNED)->sole();
        $this->assertSame(route('event-tasks.show', $this->tokenOf($this->field)), $mine->url, 'والرابطُ يصله داخل النظام');
        $lead = InternalNotification::where('user_id', $this->manager->id)->where('kind', 'event_tasks_failed')->sole();
        $this->assertStringContainsString('المنفّذ', $lead->title);
        $this->assertSame(6, ProjectTask::where('project_id', $this->project->id)->where('status', ProjectTask::OPEN)->count(), 'ولا مهمّةَ تُمسّ');
        $this->assertSame(AutomationRun::EXECUTED, AutomationRun::where('trigger', EventTasks::ASSIGNED)->sole()->status, 'العمليّةُ لا تفشل كلُّها');
    }

    public function test_the_meta_template_mapping_is_set_on_screen_not_hard_coded(): void
    {
        /* قالبُ Meta بلا ترويسةٍ متغيّرة وبلا زرّ — والمتنُ بترتيبٍ آخر */
        $this->actingAs($this->owner)->post(route('admin.communications.templates.update'), [
            'key' => 'event.tasks_assigned', 'channel' => 'whatsapp', 'language' => 'ar', 'status' => 'active',
            'provider_template' => 'team_tasks_v2', 'provider_language' => 'ar',
            'variables' => ['event_name', 'open_tasks_count', 'tasks_url'], 'header_variables' => [], 'button_variable' => null,
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('admin.communications.templates.update'), [
            'key' => 'event.tasks_assigned', 'channel' => 'whatsapp', 'language' => 'ar', 'status' => 'active',
            'provider_template' => 'team_tasks_v2', 'button_variable' => 'customer_name',
        ])->assertSessionHasErrors('template');
        auth()->logout();

        $this->publish();
        Engine::tick((int) $this->business->id);

        $message = collect($this->sent())->firstWhere('to', PhoneNumber::normalize($this->field->phone));
        $this->assertSame('team_tasks_v2', $message['template']['name']);
        $this->assertSame(['body'], array_column($message['template']['components'], 'type'), 'لا ترويسة ولا زرّ — كما ضُبط');
        $this->assertSame(['معرض التقنية', '3', route('event-tasks.show', $this->tokenOf($this->field))], $this->params($message, 'body'));
    }

    public function test_observe_mode_writes_what_would_happen_and_sends_nothing(): void
    {
        AutomationRule::where('business_id', $this->business->id)->where('trigger', EventTasks::ASSIGNED)->update(['mode' => AutomationRule::OBSERVE]);
        $this->publish();
        Engine::tick((int) $this->business->id);

        $this->assertCount(0, $this->sent());
        $this->assertSame(0, EventTaskDispatch::count());
        $this->assertSame(AutomationRun::OBSERVED, AutomationRun::where('trigger', EventTasks::ASSIGNED)->sole()->status);
    }

    /* ─────────────────────────── الشاشة والعزل ─────────────────────────── */

    public function test_the_event_tab_reads_tasks_by_employee_and_only_crew_managers_publish(): void
    {
        $this->publish();
        Engine::tick((int) $this->business->id);

        $this->actingAs($this->manager)->get(route('admin.operations.show', $this->op->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('event_tasks.people', fn ($people) => collect($people)->firstWhere('name', 'المنفّذ')['open'] === 3
                && collect($people)->firstWhere('name', 'المنفّذ')['last_dispatch']['status'] === 'sent'
                && str_contains((string) collect($people)->firstWhere('name', 'المنفّذ')['link'], '/t/')));

        /* من لا يدير الفريق يقرأ ولا ينشر ولا يرى الروابط */
        $this->actingAs($this->field)->post(route('admin.operations.tasks.publish', $this->op->id))->assertForbidden();
        $this->actingAs($this->field)->get(route('admin.operations.show', $this->op->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('event_tasks.people', fn ($people) => collect($people)->every(fn ($row) => $row['link'] === null)));
    }

    public function test_another_company_neither_links_tasks_to_its_event_nor_opens_these_links(): void
    {
        $theirProject = $this->project('EP-OTHER', (int) $this->other->id, null);
        $theirs = EventOperation::create(['business_id' => $this->other->id, 'project_id' => $theirProject->id, 'code' => 'OP-X', 'name' => 'فعاليتهم', 'status' => EventOperation::PLANNING]);

        $this->expectException(RuntimeException::class);
        try {
            Projects::addTask($this->project, ['title' => 'تسلّل', 'event_operation_id' => $theirs->id], $this->owner);
        } finally {
            /* ولا يُجدَّد من «ب» رابطُ موظّفٍ في «أ» */
            $this->actingAs($this->stranger)->post(route('admin.operations.tasks.rotate', [$this->op->id, $this->field->id]))->assertNotFound();
            $this->assertSame(0, EventTaskLink::count());
        }
    }
}
