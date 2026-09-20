<?php

namespace Tests\Feature\Operations;

use App\Models\ActivityLog;
use App\Models\AssetReservation;
use App\Models\EventOperation;
use App\Models\OperationChecklistItem;
use App\Models\OperationCrewAssignment;
use App\Models\OperationScheduleItem;
use App\Models\Project;
use App\Support\Operations\Checklists;
use App\Support\Operations\Crew;
use App\Support\Operations\EventOperations;
use App\Support\Operations\Incidents;
use App\Support\Operations\OperationReadiness;
use App\Support\Operations\Requirements;
use App\Support\Operations\Reservations;
use App\Support\Operations\Schedule;
use App\Support\Projects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;

/**
 * الفعاليّةُ تُنفَّذ على الأرض لا على الورق — المرحلة 10: الدورةُ والجاهزيّة.
 *
 * الإنشاءُ من مشروعٍ قائم برمزٍ وقوائمَ افتراضيّة · التواريخُ تُفحص · الحالُ
 * تنتقل بقائمةٍ مغلقة وترفض القفز · «جاهزة» و«على الهواء» بابان تحرسهما
 * الجاهزيّة · التجاوزُ بإذنٍ وسببٍ وأثر · الإتمامُ ليس مرورَ الوقت · والإلغاءُ
 * يحرّر ما لم يخرج · وإتمامُ الفعاليّة لا يُقفل المشروع.
 */
class TheEventIsRunOnTheGroundTest extends OperationsTestCase
{
    use RefreshDatabase;

    /* ─────────────────────────── الإنشاء ─────────────────────────── */

    public function test_an_operation_is_created_from_an_open_project_with_a_code_and_default_checklists(): void
    {
        $op = $this->operation();

        $this->assertSame('OP-000001', $op->code);
        $this->assertSame(EventOperation::PLANNING, $op->status);
        $this->assertSame($this->business->id, $op->business_id);
        $this->assertSame($this->manager->id, $op->operations_lead_id);
        $this->assertSame('Asia/Muscat', $op->timezone);

        /* القوالبُ الثلاثة الجاهزة أُلحقت نسخًا */
        $phases = $op->checklists()->pluck('phase')->all();
        $this->assertEqualsCanonicalizing(['pre_event', 'setup', 'closing'], $phases);
        $this->assertSame(13, $op->checklists()->where('phase', 'pre_event')->first()->items()->count());

        $this->assertSame('OP-000002', $this->operation()->code);
        $this->assertTrue(ActivityLog::where('subject_type', 'event_operation')->where('subject_id', $op->id)->where('action', 'created')->exists());
    }

    public function test_an_operation_needs_an_open_project(): void
    {
        $closed = $this->project('EP-2026-009');
        $closed->update(['status' => Project::CLOSED]);

        $this->expectException(RuntimeException::class);
        EventOperations::create($closed, ['name' => 'x'], $this->owner);
    }

    public function test_dates_are_checked_end_after_start_and_setup_before_start(): void
    {
        $op = $this->operation();

        try {
            EventOperations::update($op, ['event_ends_at' => '2026-10-01 08:00'], $this->owner);
            $this->fail('نهايةٌ قبل البداية مرّت');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('نهاية الفعاليّة', $e->getMessage());
        }

        try {
            EventOperations::update($op, ['setup_starts_at' => '2026-10-01 10:00'], $this->owner);
            $this->fail('تجهيزٌ بعد البداية مرّ');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('التجهيز', $e->getMessage());
        }

        $op = EventOperations::update($op, ['venue_name' => 'فندق', 'event_ends_at' => '2026-10-01 18:00'], $this->owner);
        $this->assertSame('فندق', $op->venue_name);
        $this->assertSame('2026-10-01 18:00', $op->event_ends_at->format('Y-m-d H:i'));
    }

    public function test_the_lead_must_belong_to_the_company(): void
    {
        $this->expectException(RuntimeException::class);
        $this->operation(['operations_lead_id' => $this->stranger->id]);
    }

    /* ─────────────────────────── الدورة ─────────────────────────── */

    public function test_the_status_moves_only_along_the_closed_list(): void
    {
        $op = $this->operation();

        $op = EventOperations::transition($op, EventOperation::PREPARING, $this->owner);
        $this->assertSame(EventOperation::PREPARING, $op->status);

        /* ضغطةٌ ثانية لا تغيّر شيئًا ولا ترمي */
        $this->assertSame(EventOperation::PREPARING, EventOperations::transition($op, EventOperation::PREPARING, $this->owner)->status);

        foreach ([EventOperation::LIVE, EventOperation::TEARDOWN, EventOperation::SETUP, EventOperation::COMPLETED] as $illegal) {
            try {
                EventOperations::transition($op->fresh(), $illegal, $this->owner);
                $this->fail("القفزُ إلى {$illegal} مرّ");
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(EventOperation::PREPARING, $op->fresh()->status);
    }

    public function test_ready_is_refused_while_readiness_has_blockers(): void
    {
        $op = EventOperations::transition($this->operation(), EventOperation::PREPARING, $this->owner);
        $verdict = OperationReadiness::for($op);

        $this->assertFalse($verdict->ready);
        $keys = array_column($verdict->blockers, 'key');
        $this->assertContains('crew', $keys);
        $this->assertTrue(collect($keys)->contains(fn ($k) => str_starts_with($k, 'checklist_pre_event')));
        $this->assertContains('requirements', array_column($verdict->warnings, 'key'));

        try {
            EventOperations::transition($op, EventOperation::READY, $this->owner);
            $this->fail('«جاهزة» مرّت بموانع');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ليست جاهزة', $e->getMessage());
        }
        $this->assertSame(EventOperation::PREPARING, $op->fresh()->status);
    }

    public function test_ready_passes_once_the_hard_requirements_are_met(): void
    {
        $op = $this->prepared();

        $verdict = OperationReadiness::for($op);
        $this->assertTrue($verdict->ready, implode(' · ', $verdict->blockerMessages()));
        $this->assertNotEmpty($verdict->checks);
        $this->assertTrue(collect($verdict->checks)->every(fn ($c) => isset($c['key'], $c['label'], $c['ok'], $c['severity'])));

        $op = EventOperations::transition($op, EventOperation::READY, $this->owner);
        $this->assertSame(EventOperation::READY, $op->status);
    }

    public function test_an_override_needs_the_permission_and_a_reason_and_leaves_a_trace(): void
    {
        $op = EventOperations::transition($this->operation(), EventOperation::PREPARING, $this->owner);

        /* المديرُ لا يملك التجاوز */
        try {
            EventOperations::transition($op, EventOperation::READY, $this->manager, null, 'لا وقت');
            $this->fail('تجاوزٌ بلا إذن مرّ');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('صلاحيّة التجاوز', $e->getMessage());
        }

        /* والمالكُ بلا سبب لا يمرّ */
        try {
            EventOperations::transition($op, EventOperation::READY, $this->owner, null, '   ');
            $this->fail('تجاوزٌ بلا سبب مرّ');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $op = EventOperations::transition($op, EventOperation::READY, $this->owner, null, 'العميل أصرّ — الفريق يُستكمل صباحًا');
        $this->assertSame(EventOperation::READY, $op->status);
        $this->assertCount(1, $op->readiness_override);
        $this->assertSame('العميل أصرّ — الفريق يُستكمل صباحًا', $op->readiness_override[0]['reason']);
        $this->assertSame($this->owner->id, $op->readiness_override[0]['by_id']);
        $this->assertNotEmpty($op->readiness_override[0]['blockers']);
        $this->assertTrue(ActivityLog::where('subject_type', 'event_operation')->where('subject_id', $op->id)
            ->where('description', 'like', '%تجاوز جاهزيّة%')->exists());
    }

    public function test_live_needs_the_lead_on_site_and_critical_equipment_checked_out(): void
    {
        $op = EventOperations::transition($this->prepared(), EventOperation::READY, $this->owner);
        $op = EventOperations::transition($op, EventOperation::SETUP, $this->owner);

        $verdict = OperationReadiness::live($op);
        $this->assertFalse($verdict->ready);
        $keys = array_column($verdict->blockers, 'key');
        $this->assertContains('lead_on_site', $keys);
        $this->assertContains('equipment_deployed', $keys);
        $this->assertTrue(collect($keys)->contains(fn ($k) => str_starts_with($k, 'checklist_setup')));

        try {
            EventOperations::transition($op, EventOperation::LIVE, $this->owner);
            $this->fail('البثّ مرّ بلا جاهزيّة');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        /* القائدُ يحضر، والمعدّاتُ تُسلَّم، وقائمةُ التجهيز تُنجَز */
        Crew::checkIn($op->crew()->where('user_id', $this->manager->id)->first(), $this->manager);
        foreach ($op->reservations as $r) {
            Reservations::checkOut($r, ['custodian_id' => $this->field->id], $this->storekeeper);
        }
        $this->completeChecklist($op, 'setup');

        $verdict = OperationReadiness::live($op->fresh());
        $this->assertTrue($verdict->ready, implode(' · ', $verdict->blockerMessages()));

        $op = EventOperations::transition($op->fresh(), EventOperation::LIVE, $this->field);
        $this->assertSame(EventOperation::LIVE, $op->status);
        $this->assertNotNull($op->went_live_at);
    }

    /* ─────────────────────────── الإتمام ─────────────────────────── */

    public function test_completion_is_blocked_by_outstanding_equipment_open_critical_incidents_and_the_closing_checklist(): void
    {
        $op = $this->live();
        $op = EventOperations::transition($op, EventOperation::TEARDOWN, $this->field);

        Schedule::add($op, ['title' => 'عدّ المعدّات', 'type' => 'teardown'], $this->owner);
        $incident = Incidents::report($op, ['title' => 'انقطع الإنترنت', 'severity' => 'critical'], $this->field);

        $blockers = EventOperations::completionBlockers($op->fresh());
        $this->assertTrue(collect($blockers)->contains(fn ($b) => str_contains($b, 'لم تُردّ')), implode(' · ', $blockers));
        $this->assertTrue(collect($blockers)->contains(fn ($b) => str_contains($b, 'انقطع الإنترنت')));
        $this->assertTrue(collect($blockers)->contains(fn ($b) => str_contains($b, 'قائمة الإقفال')));
        $this->assertTrue(collect($blockers)->contains(fn ($b) => str_contains($b, 'الفكّ والإرجاع')));

        try {
            EventOperations::complete($op, $this->owner);
            $this->fail('الإتمامُ مرّ بموانع');
        } catch (RuntimeException) {
            $this->assertSame(EventOperation::TEARDOWN, $op->fresh()->status);
        }

        /* تُردّ المعدّات، ويُحلّ الحادث، وتُنجَز القوائم والجدول */
        foreach ($op->reservations()->where('status', AssetReservation::CHECKED_OUT)->get() as $r) {
            Reservations::giveBack($r, ['condition' => 'good'], $this->storekeeper);
        }
        Incidents::resolve($incident, 'بدّلنا الموجّه', $this->field);
        $this->completeChecklist($op, 'closing');
        foreach (OperationScheduleItem::where('event_operation_id', $op->id)->get() as $i) {
            Schedule::setStatus($i, OperationScheduleItem::COMPLETED, $this->field);
        }

        $this->assertSame([], EventOperations::completionBlockers($op->fresh()));

        $op = EventOperations::complete($op->fresh(), $this->owner);
        $this->assertSame(EventOperation::COMPLETED, $op->status);
        $this->assertNotNull($op->completed_at);

        /* والتكرارُ آمن */
        $this->assertSame($op->completed_at->toDateTimeString(), EventOperations::complete($op, $this->owner)->completed_at->toDateTimeString());
        $this->assertSame(1, ActivityLog::where('subject_id', $op->id)->where('subject_type', 'event_operation')->where('description', 'like', 'أتمّ الفعاليّة%')->count());

        /* والمشروعُ يبقى قائمًا — إتمامُ التشغيل ليس إقفالًا ماليًّا */
        $this->assertSame(Project::CONFIRMED, $this->project->fresh()->status);
    }

    public function test_a_carried_forward_critical_incident_does_not_block_completion(): void
    {
        $op = EventOperations::transition($this->live(), EventOperation::TEARDOWN, $this->field);
        $incident = Incidents::report($op, ['title' => 'تلف شاشة', 'severity' => 'high'], $this->field);

        $this->assertTrue(collect(EventOperations::completionBlockers($op->fresh()))->contains(fn ($b) => str_contains($b, 'تلف شاشة')));

        Incidents::carryForward($incident, 'تُعالَج مع المورّد الأسبوع القادم', $this->owner);
        $this->assertFalse(collect(EventOperations::completionBlockers($op->fresh()))->contains(fn ($b) => str_contains($b, 'تلف شاشة')));
        $this->assertTrue($incident->fresh()->carried_forward);
    }

    public function test_completion_is_not_the_passing_of_time(): void
    {
        $op = $this->live();
        $this->travelTo('2026-10-05 10:00:00');

        $this->assertSame(EventOperation::LIVE, $op->fresh()->status);
        $this->assertContains('لم تبلغ الفعاليّة مرحلة الفكّ بعد.', EventOperations::completionBlockers($op->fresh()));
    }

    /* ─────────────────────────── الإلغاء ─────────────────────────── */

    public function test_cancelling_releases_unchecked_reservations_and_keeps_what_left(): void
    {
        $op = $this->prepared();
        $reservations = $op->reservations()->get();
        $this->assertCount(2, $reservations);

        Reservations::checkOut($reservations[0], [], $this->storekeeper);

        $op = EventOperations::cancel($op, 'أُلغي المؤتمر', $this->owner);
        $this->assertSame(EventOperation::CANCELLED, $op->status);
        $this->assertSame(AssetReservation::CHECKED_OUT, $reservations[0]->fresh()->status, 'ما خرج يبقى معلَّقًا حتى يُردّ');
        $this->assertSame(AssetReservation::CANCELLED, $reservations[1]->fresh()->status);
        $this->assertSame('available', $reservations[1]->asset->fresh()->status);
        $this->assertSame(0, $op->crew()->whereIn('status', OperationCrewAssignment::EXPECTED)->count());

        /* والفعاليّةُ الملغاة لا تُعدَّل ولا يُحجز لها */
        $this->expectException(RuntimeException::class);
        EventOperations::update($op, ['name' => 'x'], $this->owner);
    }

    public function test_a_live_operation_cannot_be_cancelled(): void
    {
        $op = $this->live();

        $this->expectException(RuntimeException::class);
        EventOperations::cancel($op, 'x', $this->owner);
    }

    /* ─────────────────────────── الجدول ─────────────────────────── */

    public function test_the_schedule_answers_what_is_happening_now(): void
    {
        $op = $this->operation();
        $this->travelTo('2026-10-01 09:30:00');

        $done = Schedule::add($op, ['title' => 'وصول الفريق', 'type' => 'logistics', 'starts_at' => '2026-10-01 07:00', 'ends_at' => '2026-10-01 07:30'], $this->owner);
        $overdue = Schedule::add($op, ['title' => 'اختبار الطابعات', 'type' => 'technical', 'starts_at' => '2026-10-01 08:00', 'ends_at' => '2026-10-01 08:30'], $this->owner);
        $current = Schedule::add($op, ['title' => 'التسجيل', 'type' => 'registration', 'starts_at' => '2026-10-01 09:00', 'ends_at' => '2026-10-01 11:00'], $this->owner);
        $next = Schedule::add($op, ['title' => 'الغداء', 'type' => 'live', 'starts_at' => '2026-10-01 13:00'], $this->owner);
        Schedule::setStatus($done, OperationScheduleItem::COMPLETED, $this->field);

        $now = Schedule::now($op->fresh());
        $this->assertSame([$current->id], array_map(fn ($i) => $i->id, $now['current']));
        $this->assertSame([$next->id], array_map(fn ($i) => $i->id, $now['next']));
        $this->assertSame([$overdue->id], array_map(fn ($i) => $i->id, $now['overdue']));

        /* والتكرارُ لا يعيد التسجيل */
        Schedule::setStatus($done, OperationScheduleItem::COMPLETED, $this->field);
        $this->assertSame(1, ActivityLog::where('description', 'like', '%وصول الفريق%')->count());

        $this->expectException(RuntimeException::class);
        Schedule::setStatus($current, 'flying', $this->field);
    }

    /* ─────────────────────────── الشاشات والأذون ─────────────────────────── */

    public function test_the_screens_open_and_the_doors_are_guarded(): void
    {
        $op = $this->operation();

        $this->actingAs($this->owner)->get(route('admin.operations.index'))->assertOk();
        $this->actingAs($this->owner)->get(route('admin.operations.show', $op->id))->assertOk();
        $this->actingAs($this->owner)->get(route('admin.operations.onsite', $op->id))->assertOk();
        $this->actingAs($this->owner)->get(route('admin.operations.templates'))->assertOk();

        /* المنفّذُ الميدانيّ يفتح القسم ولا يدير */
        $this->actingAs($this->field)->get(route('admin.operations.onsite', $op->id))->assertOk();
        $this->actingAs($this->field)->put(route('admin.operations.update', $op->id), ['name' => 'x'])->assertForbidden();
        $this->actingAs($this->field)->post(route('admin.operations.crew.store', $op->id), ['role' => 'runner', 'external_name' => 'x'])->assertForbidden();
        $this->actingAs($this->field)->post(route('admin.operations.transition', $op->id), ['status' => 'preparing'])->assertRedirect();
        $this->assertSame(EventOperation::PREPARING, $op->fresh()->status);

        /* والبائعُ لا يفتح القسم أصلًا */
        $this->actingAs($this->seller)->get(route('admin.operations.index'))->assertForbidden();
        $this->actingAs($this->seller)->get(route('admin.operations.onsite', $op->id))->assertForbidden();

        /* والتجاوزُ من الشاشة يحتاج إذنَه أيضًا */
        $this->actingAs($this->manager)->post(route('admin.operations.transition', $op->id), ['status' => 'ready', 'override_reason' => 'x'])
            ->assertSessionHasErrors('operation');
        $this->assertSame(EventOperation::PREPARING, $op->fresh()->status);
    }

    public function test_a_neighbour_cannot_see_or_touch_the_operation(): void
    {
        $op = $this->operation();

        $this->actingAs($this->stranger)->get(route('admin.operations.show', $op->id))->assertNotFound();
        $this->actingAs($this->stranger)->get(route('admin.operations.onsite', $op->id))->assertNotFound();
        $this->actingAs($this->stranger)->post(route('admin.operations.transition', $op->id), ['status' => 'preparing'])->assertNotFound();
        $this->actingAs($this->stranger)->post(route('admin.operations.incidents.store', $op->id), ['title' => 'x'])->assertNotFound();
        $this->assertSame(EventOperation::PLANNING, $op->fresh()->status);

        /* ولا تُنشأ فعاليّةٌ على مشروع الجار */
        $this->actingAs($this->stranger)->post(route('admin.operations.store'), ['project_id' => $this->project->id])->assertNotFound();
        $this->assertSame(1, EventOperation::count());

        /* والقائمةُ لا تُظهر إلّا فعاليّاتِ الشركة */
        $this->actingAs($this->stranger)->get(route('admin.operations.index'))->assertOk()->assertInertia(fn ($page) => $page->where('operations', []));
    }

    public function test_the_project_screen_carries_an_operations_tab_without_commercial_leakage(): void
    {
        $op = $this->operation();

        $this->actingAs($this->owner)->get(route('admin.projects.show', $this->project->id))->assertOk()
            ->assertInertia(fn ($page) => $page->has('operations', 1)
                ->where('operations.0.code', $op->code)
                ->where('operations.0.ready', false)
                ->where('may.operations', true)
                ->missing('operations.0.approved_value'));

        /* والبائعُ لا يفتح «العمليات» فلا يبلغه التبويب */
        $this->actingAs($this->seller)->get(route('admin.projects.show', $this->project->id))->assertOk()
            ->assertInertia(fn ($page) => $page->where('operations', null)->where('may.operations', false));
    }

    /* ─────────────────────────── أدوات ─────────────────────────── */

    /** فعاليّةٌ فريقُها معيَّن ومعدّاتُها محجوزة وقائمةُ ما قبلها مُنجَزة */
    protected function prepared(): EventOperation
    {
        $op = EventOperations::transition($this->operation(), EventOperation::PREPARING, $this->owner);

        Crew::assign($op, ['user_id' => $this->manager->id, 'role' => 'operations_lead', 'briefing_status' => 'briefed'], $this->owner);
        Crew::assign($op, ['user_id' => $this->field->id, 'role' => 'registration_staff', 'briefing_status' => 'briefed'], $this->owner);

        $req = Requirements::add($op, ['kind' => 'asset_category', 'asset_category' => 'laptop', 'required_quantity' => 2, 'critical' => true], $this->owner);
        Requirements::add($op, ['kind' => 'asset_category', 'asset_category' => 'printer', 'required_quantity' => 1, 'critical' => false, 'backup' => true], $this->owner);
        Reservations::reserve($op, $this->asset('حاسوب ١'), ['requirement_id' => $req->id], $this->storekeeper);
        Reservations::reserve($op, $this->asset('حاسوب ٢'), ['requirement_id' => $req->id], $this->storekeeper);

        $this->completeChecklist($op, 'pre_event');
        Schedule::add($op, ['title' => 'خروج المعدّات', 'type' => 'logistics', 'starts_at' => '2026-10-01 05:00'], $this->owner);

        return $op->fresh();
    }

    /** فعاليّةٌ على الهواء */
    protected function live(): EventOperation
    {
        $op = EventOperations::transition($this->prepared(), EventOperation::READY, $this->owner);
        $op = EventOperations::transition($op, EventOperation::SETUP, $this->owner);
        Crew::checkIn($op->crew()->where('user_id', $this->manager->id)->first(), $this->manager);
        foreach ($op->reservations as $r) {
            Reservations::checkOut($r, ['custodian_id' => $this->field->id], $this->storekeeper);
        }
        $this->completeChecklist($op, 'setup');

        return EventOperations::transition($op->fresh(), EventOperation::LIVE, $this->owner);
    }

    protected function completeChecklist(EventOperation $op, string $phase): void
    {
        $items = OperationChecklistItem::whereHas('checklist', fn ($q) => $q->where('event_operation_id', $op->id)->where('phase', $phase))->get();
        foreach ($items as $item) {
            Checklists::complete($item, null, $this->field);
        }
    }
}
