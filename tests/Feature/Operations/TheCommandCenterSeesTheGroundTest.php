<?php

namespace Tests\Feature\Operations;

use App\Models\ActivityLog;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\EventOperation;
use App\Models\InternalNotification;
use App\Models\OperationChecklistItem;
use App\Support\Automation\Brief;
use App\Support\Automation\Engine;
use App\Support\Automation\Rules;
use App\Support\Automation\Settings;
use App\Support\Automation\Triggers;
use App\Support\CommandCenter;
use App\Support\Operations\Checklists;
use App\Support\Operations\Crew;
use App\Support\Operations\EventOperations;
use App\Support\Operations\Incidents;
use App\Support\Operations\Requirements;
use App\Support\Operations\Reservations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * مركزُ العمليات يرى الأرض — المرحلة 10: التكاملُ مع المراحل 7 و8 و6.
 *
 * ما على الهواء وما يبدأ اليوم وغدًا بجاهزيّته · التعارضاتُ وما لم يُردّ
 * والحوادثُ الحرجة · والموجزُ الصباحيّ يذكرها · والأتمتةُ تذكّر بالجاهزيّة
 * والتعيين والمعدّات على قواعد المرحلة 8 نفسِها: مراقبةٌ أوّلًا، وإيقافٌ
 * يُطاع، وتكرارٌ يُردّ، وتأهيلٌ يُعاد قبل الفعل — ولا رسالةَ للعميل.
 */
class TheCommandCenterSeesTheGroundTest extends OperationsTestCase
{
    use RefreshDatabase;

    public function test_the_command_center_lists_events_by_bucket_with_readiness_conflicts_outstanding_and_incidents(): void
    {
        /* اليومَ: فعاليّةٌ غير جاهزة */
        $today = $this->operation(['setup_starts_at' => '2026-09-20 12:00', 'event_starts_at' => '2026-09-20 14:00', 'event_ends_at' => '2026-09-20 18:00', 'teardown_starts_at' => '2026-09-20 18:00', 'teardown_ends_at' => '2026-09-20 19:00']);
        /* غدًا: فعاليّةٌ عليها حادثٌ حرج وقطعةٌ لم تُردّ من فعاليّةٍ سابقة */
        $tomorrow = $this->operation(['setup_starts_at' => '2026-09-21 06:00', 'event_starts_at' => '2026-09-21 09:00', 'event_ends_at' => '2026-09-21 17:00', 'teardown_starts_at' => '2026-09-21 17:00', 'teardown_ends_at' => '2026-09-21 19:00'], $this->project('EP-2026-002'));
        /* أمس: فعاليّةٌ خرجت منها قطعةٌ ولم تعد */
        $past = $this->operation(['setup_starts_at' => '2026-09-18 06:00', 'event_starts_at' => '2026-09-18 09:00', 'event_ends_at' => '2026-09-18 17:00', 'teardown_starts_at' => '2026-09-18 17:00', 'teardown_ends_at' => '2026-09-18 19:00'], $this->project('EP-2026-003'));
        /* وبعد شهر: خارج النافذة */
        $far = $this->operation(['setup_starts_at' => '2026-10-25 06:00', 'event_starts_at' => '2026-10-25 09:00', 'event_ends_at' => '2026-10-25 17:00', 'teardown_starts_at' => '2026-10-25 17:00', 'teardown_ends_at' => '2026-10-25 19:00'], $this->project('EP-2026-004'));

        $laptop = $this->asset('حاسوب');
        $out = Reservations::checkOut(Reservations::reserve($past, $laptop, [], $this->storekeeper), ['custodian_id' => $this->field->id], $this->storekeeper);
        Reservations::reserve($tomorrow, $laptop, [], $this->storekeeper);
        Incidents::report($tomorrow, ['title' => 'المولّد معطّل', 'severity' => 'critical'], $this->field);

        $section = CommandCenter::operations((int) $this->business->id);

        $codes = collect($section['rows'])->pluck('bucket', 'code')->all();
        $this->assertSame('today', $codes[$today->code]);
        $this->assertSame('tomorrow', $codes[$tomorrow->code]);
        $this->assertArrayNotHasKey($far->code, $codes, 'ما بعد أسبوعين لا يُعرض');
        $this->assertArrayNotHasKey($past->code, $codes, 'ما مضى ولم يكن على الأرض لا يُعرض');

        $this->assertSame(0, $section['live']);
        $this->assertSame(2, $section['not_ready']);
        $this->assertSame(1, $section['counts']['today']);
        $this->assertSame(1, $section['counts']['tomorrow']);

        $tomorrowRow = collect($section['rows'])->firstWhere('code', $tomorrow->code);
        $this->assertFalse($tomorrowRow['ready']);
        $this->assertSame('danger', $tomorrowRow['severity']);
        $this->assertGreaterThanOrEqual(1, $tomorrowRow['conflicts']['critical'], 'القطعةُ لم تُردّ من الفعاليّة السابقة');

        $this->assertSame(1, $section['outstanding']['total']);
        $this->assertStringContainsString($laptop->asset_code, $section['outstanding']['rows'][0]['title']);
        $this->assertSame(route('admin.assets.show', $laptop->id), $section['outstanding']['rows'][0]['url']);

        $this->assertSame(1, $section['incidents']['total']);
        $this->assertStringContainsString('المولّد معطّل', $section['incidents']['rows'][0]['title']);

        $this->assertTrue(collect($section['conflicts']['rows'])->contains(fn ($r) => str_contains($r['detail'], $laptop->asset_code)));
        $this->assertGreaterThanOrEqual(4, $section['total']);

        /* ويُضمّ إلى الملخّص ولا يُعاد حسابُه */
        $center = CommandCenter::for((int) $this->business->id, $this->owner);
        $this->assertSame($section['total'], $center['summary']['operations']);
        $this->assertSame($section['not_ready'], $center['operations']['not_ready']);

        /* وتعود القطعة فيختفي الصفّ */
        Reservations::giveBack($out, ['condition' => 'good'], $this->storekeeper);
        $this->assertSame(0, CommandCenter::operations((int) $this->business->id)['outstanding']['total']);

        /* والشاشةُ تفتح بالقسم */
        $this->actingAs($this->owner)->get(route('admin.command.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('center.operations.rows', 2)->where('center.summary.operations', fn ($n) => $n >= 3));
    }

    public function test_a_live_operation_is_first_and_the_morning_brief_mentions_the_ground(): void
    {
        $op = $this->goLive($this->operation(['setup_starts_at' => '2026-09-20 06:00', 'event_starts_at' => '2026-09-20 09:00', 'event_ends_at' => '2026-09-20 17:00', 'teardown_starts_at' => '2026-09-20 17:00', 'teardown_ends_at' => '2026-09-20 19:00']));

        $section = CommandCenter::operations((int) $this->business->id);
        $this->assertSame(1, $section['live']);
        $this->assertSame('live', $section['rows'][0]['bucket']);
        $this->assertSame($op->code, $section['rows'][0]['code']);

        $brief = Brief::for((int) $this->business->id, $this->owner);
        $this->assertStringContainsString('على الهواء: 1', implode("\n", $brief['paragraphs']));
    }

    /* ─────────────────────────── الأتمتة ─────────────────────────── */

    public function test_the_operations_rules_are_written_in_observe_mode_and_the_triggers_are_known(): void
    {
        Rules::ensure((int) $this->business->id);

        foreach (['operation.start', 'crew.assignment', 'equipment.checkout', 'equipment.unreturned', 'operation.closeout'] as $key) {
            $rule = AutomationRule::where('business_id', $this->business->id)->where('key', $key)->first();
            $this->assertNotNull($rule, $key);
            $this->assertSame(AutomationRule::INTERNAL, $rule->kind, $key.' داخليّة');
            $this->assertSame('observe', $rule->mode, $key.' تُكتب على «مراقبة»');
            $this->assertTrue($rule->enabled);
            $this->assertTrue(Triggers::exists($rule->trigger));
            $this->assertNull(Triggers::for($rule->trigger)->subjectType(), 'لا تبلغ العميل');
            $this->assertNotContains('customer', Triggers::for($rule->trigger)->audiences());
        }
        $this->assertSame(count(Rules::PRESETS), AutomationRule::where('business_id', $this->business->id)->count());
    }

    public function test_readiness_reminders_observe_first_then_notify_the_lead_when_switched_live(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-20 10:00:00', config('app.timezone')));
        Settings::save((int) $this->business->id, ['mode' => Settings::LIVE], $this->owner);
        Rules::ensure((int) $this->business->id);

        /* فعاليّةٌ بعد سبعة أيّام وغير جاهزة */
        $op = $this->operation(['setup_starts_at' => '2026-09-27 06:00', 'event_starts_at' => '2026-09-27 09:00', 'event_ends_at' => '2026-09-27 17:00', 'teardown_starts_at' => '2026-09-27 17:00', 'teardown_ends_at' => '2026-09-27 19:00']);
        $rule = AutomationRule::where('business_id', $this->business->id)->where('key', 'operation.start')->first();

        Engine::evaluate((int) $this->business->id);
        $run = AutomationRun::where('automation_rule_id', $rule->id)->where('target_id', $op->id)->where('step', -7)->first();
        $this->assertNotNull($run, 'T-7 كُشف');
        $this->assertSame(AutomationRun::OBSERVED, $run->status, 'مراقبةٌ أوّلًا — لا تنبيه');
        $this->assertSame(0, InternalNotification::where('kind', 'operation_start')->count());
        $this->assertStringContainsString('غير جاهزة', $run->metadata['description']);

        /* التكرارُ في الدورة نفسِها لا يفتح تنفيذًا ثانيًا */
        Engine::evaluate((int) $this->business->id);
        $this->assertSame(1, AutomationRun::where('automation_rule_id', $rule->id)->where('target_id', $op->id)->where('step', -7)->count());

        /* تُشغَّل القاعدةُ بيد — يوم T-3 يُنبَّه القائد */
        Rules::save($rule, ['mode' => 'live'], $this->owner);
        Carbon::setTestNow(Carbon::parse('2026-09-24 10:00:00', config('app.timezone')));
        Engine::evaluate((int) $this->business->id);

        $run = AutomationRun::where('automation_rule_id', $rule->id)->where('target_id', $op->id)->where('step', -3)->first();
        $this->assertSame(AutomationRun::EXECUTED, $run->status);
        $bell = InternalNotification::where('user_id', $this->manager->id)->where('kind', 'operation_start')->first();
        $this->assertNotNull($bell, 'القائدُ نُبّه');
        $this->assertStringContainsString($op->code, $bell->title);
        $this->assertSame(route('admin.operations.show', $op->id), $bell->url);
        $this->assertSame(0, \App\Models\Communication::count(), 'ولا رسالةَ للعميل');

        /* والجاهزةُ لا تُذكَّر بموانعَ ليست لها — T-1 */
        $this->makeReady($op);
        Carbon::setTestNow(Carbon::parse('2026-09-26 10:00:00', config('app.timezone')));
        Engine::evaluate((int) $this->business->id);
        $run = AutomationRun::where('automation_rule_id', $rule->id)->where('target_id', $op->id)->where('step', -1)->where('audience', 'lead')->first();
        $this->assertSame(AutomationRun::SKIPPED, $run->status);
        $this->assertSame(Engine::RESOLVED, $run->skipped_reason);

        /* والإيقافُ العامّ يُطاع */
        Settings::pause((int) $this->business->id, true, $this->owner);
        Carbon::setTestNow(Carbon::parse('2026-09-27 10:00:00', config('app.timezone')));
        Engine::evaluate((int) $this->business->id);
        $run = AutomationRun::where('automation_rule_id', $rule->id)->where('target_id', $op->id)->where('step', 0)->first();
        $this->assertSame(AutomationRun::SKIPPED, $run->status);
        $this->assertSame(Engine::PAUSED, $run->skipped_reason);
    }

    public function test_crew_and_equipment_reminders_re_check_the_world_before_speaking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-20 10:00:00', config('app.timezone')));
        Settings::save((int) $this->business->id, ['mode' => Settings::LIVE], $this->owner);
        Rules::ensure((int) $this->business->id);
        foreach (['crew.assignment', 'equipment.checkout', 'equipment.unreturned', 'operation.closeout'] as $key) {
            Rules::save(AutomationRule::where('business_id', $this->business->id)->where('key', $key)->first(), ['mode' => 'live'], $this->owner);
        }

        $tomorrow = $this->operation(['setup_starts_at' => '2026-09-21 06:00', 'event_starts_at' => '2026-09-21 09:00', 'event_ends_at' => '2026-09-21 17:00', 'teardown_starts_at' => '2026-09-21 17:00', 'teardown_ends_at' => '2026-09-21 19:00']);
        $assignment = Crew::assign($tomorrow, ['user_id' => $this->field->id, 'role' => 'runner'], $this->owner);
        Crew::assign($tomorrow, ['external_name' => 'متعاقد', 'role' => 'photographer'], $this->owner);
        $reserved = Reservations::reserve($tomorrow, $this->asset('حاسوب'), [], $this->storekeeper);

        /* وقطعةٌ خرجت أمس ولم تعد، من فعاليّةٍ انتهت ولم تُتمّ */
        $yesterday = $this->operation(['setup_starts_at' => '2026-09-19 06:00', 'event_starts_at' => '2026-09-19 09:00', 'event_ends_at' => '2026-09-19 17:00', 'teardown_starts_at' => '2026-09-19 17:00', 'teardown_ends_at' => '2026-09-19 19:00'], $this->project('EP-2026-002'));
        $late = Reservations::checkOut(Reservations::reserve($yesterday, $this->asset('طابعة', 'printer'), [], $this->storekeeper), ['custodian_id' => $this->field->id], $this->storekeeper);

        Engine::evaluate((int) $this->business->id);

        /* المعيَّن يُذكَّر عشيّة الفعاليّة — والمتعاقدُ بلا حساب لا يُكشف أصلًا */
        $this->assertSame(1, InternalNotification::where('user_id', $this->field->id)->where('kind', 'crew_assignment')->count());
        $this->assertSame(1, AutomationRun::where('trigger', 'crew_assignment')->count());

        /* والقائدُ يُذكَّر بالتسليم غدًا وبما لم يُردّ من أمس */
        $this->assertSame(1, InternalNotification::where('user_id', $this->manager->id)->where('kind', 'equipment_checkout')->count());
        $this->assertSame(1, InternalNotification::where('user_id', $this->manager->id)->where('kind', 'equipment_unreturned')->count());
        $this->assertSame(1, InternalNotification::where('user_id', $this->manager->id)->where('kind', 'operation_closeout')->count());

        /* تُسلَّم القطعة وتُردّ الأخرى ويُزال المعيَّن — فلا يُذكَّر بشيءٍ منها في الغد */
        Reservations::checkOut($reserved, [], $this->storekeeper);
        Reservations::giveBack($late, ['condition' => 'good'], $this->storekeeper);
        Crew::remove($assignment->fresh(), $this->owner);
        Carbon::setTestNow(Carbon::parse('2026-09-21 10:00:00', config('app.timezone')));
        Engine::evaluate((int) $this->business->id);

        /* ما سُلّم وما رُدّ وما أُزيل لم يعد مرشَّحًا أصلًا — الكشفُ بالاستعلام يستثنيه قبل أن يُفتح تنفيذ */
        $this->assertSame(0, AutomationRun::where('trigger', 'equipment_checkout')->where('step', 0)->count());
        $this->assertSame(0, AutomationRun::where('trigger', 'crew_assignment')->where('step', 0)->count());
        $this->assertSame(1, InternalNotification::where('kind', 'equipment_checkout')->count(), 'لا تذكيرَ بما سُلّم');
        $this->assertSame(1, InternalNotification::where('kind', 'equipment_unreturned')->count(), 'ولا بما رُدّ');
        $this->assertSame(1, InternalNotification::where('user_id', $this->field->id)->where('kind', 'crew_assignment')->count(), 'ولا لمن أُزيل');

        /* وتنفيذٌ فُتح ثمّ تغيّر العالمُ قبل فعله يُتخطّى بسبب «عولج» — التأهيلُ الطازج */
        $rule = AutomationRule::where('business_id', $this->business->id)->where('key', 'equipment.unreturned')->first();
        $stale = Reservations::checkOut(Reservations::reserve($tomorrow, $this->asset('ماسح', 'scanner'), ['from' => '2026-09-20 06:00', 'until' => '2026-09-20 19:00'], $this->storekeeper), [], $this->storekeeper);
        $run = AutomationRun::create([
            'business_id' => $this->business->id, 'automation_rule_id' => $rule->id, 'trigger' => 'equipment_unreturned',
            'target_type' => $stale->getMorphClass(), 'target_id' => $stale->id, 'target_label' => 'x', 'step' => 1, 'audience' => 'lead',
            'occurrence' => '2026-09-20', 'idempotency_key' => Engine::idempotencyKey($rule, $stale, '2026-09-20', ['offset' => 1, 'audience' => 'lead']),
            'status' => AutomationRun::PENDING, 'evaluated_at' => now(), 'scheduled_for' => now()->subHour(),
        ]);
        Reservations::giveBack($stale, ['condition' => 'good'], $this->storekeeper);
        Engine::processPending((int) $this->business->id);
        $this->assertSame(AutomationRun::SKIPPED, $run->fresh()->status);
        $this->assertSame(Engine::RESOLVED, $run->fresh()->skipped_reason);
        $this->assertSame(1, InternalNotification::where('kind', 'equipment_unreturned')->count());
    }

    /* ─────────────────────────── السجلّ ─────────────────────────── */

    public function test_every_important_action_leaves_a_trace_under_its_actor(): void
    {
        $op = $this->operation();
        $asset = $this->asset();

        $this->actingAs($this->storekeeper);
        $r = Reservations::reserve($op, $asset, [], $this->storekeeper);
        Reservations::checkOut($r, ['custodian_id' => $this->field->id], $this->storekeeper);
        Reservations::giveBack($r, ['condition' => 'damaged', 'damage_notes' => 'كسر'], $this->storekeeper);

        $this->actingAs($this->manager);
        Crew::assign($op, ['user_id' => $this->field->id, 'role' => 'runner'], $this->manager);
        EventOperations::transition($op, EventOperation::PREPARING, $this->manager);
        Incidents::report($op, ['title' => 'حادث', 'severity' => 'low'], $this->manager);

        $names = ActivityLog::where('business_id', $this->business->id)->pluck('user_name', 'description');
        $find = fn (string $needle) => $names->keys()->first(fn ($d) => str_contains($d, $needle));

        $this->assertSame('أمين المخزن', $names[$find('حجز القطعة')]);
        $this->assertSame('أمين المخزن', $names[$find('سلّم القطعة')]);
        $this->assertSame('أمين المخزن', $names[$find('ردّ القطعة')]);
        $this->assertStringContainsString('تلف: كسر', $find('ردّ القطعة'));
        $this->assertSame('المدير', $names[$find('عيّن المنفّذ')]);
        $this->assertSame('المدير', $names[$find('نقل الفعاليّة')]);
        $this->assertSame('المدير', $names[$find('سجّل حادثًا')]);
    }

    /* ─────────────────────────── أدوات ─────────────────────────── */

    private function makeReady(EventOperation $op): void
    {
        Crew::assign($op, ['user_id' => $this->manager->id, 'role' => 'operations_lead', 'briefing_status' => 'briefed'], $this->owner);
        Requirements::add($op, ['kind' => 'asset_category', 'asset_category' => 'laptop', 'required_quantity' => 1, 'backup' => true], $this->owner);
        Reservations::reserve($op, $this->asset('حاسوب '.$op->id), [], $this->storekeeper);
        foreach (OperationChecklistItem::whereHas('checklist', fn ($q) => $q->where('event_operation_id', $op->id)->where('phase', 'pre_event'))->get() as $i) {
            Checklists::complete($i, null, $this->field);
        }
    }

    private function goLive(EventOperation $op): EventOperation
    {
        $op = EventOperations::transition($op, EventOperation::PREPARING, $this->owner);
        $this->makeReady($op);
        $op = EventOperations::transition($op->fresh(), EventOperation::READY, $this->owner);
        $op = EventOperations::transition($op, EventOperation::SETUP, $this->owner);
        Crew::checkIn($op->crew()->where('user_id', $this->manager->id)->first(), $this->manager);
        foreach ($op->reservations as $r) {
            Reservations::checkOut($r, [], $this->storekeeper);
        }
        foreach (OperationChecklistItem::whereHas('checklist', fn ($q) => $q->where('event_operation_id', $op->id)->where('phase', 'setup'))->get() as $i) {
            Checklists::complete($i, null, $this->field);
        }

        return EventOperations::transition($op->fresh(), EventOperation::LIVE, $this->owner);
    }
}
