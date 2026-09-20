<?php

namespace Tests\Feature\Operations;

use App\Models\ActivityLog;
use App\Models\Communication;
use App\Models\InternalNotification;
use App\Models\OperationCrewAssignment;
use App\Models\User;
use App\Support\Operations\Crew;
use App\Support\Operations\EventOperations;
use App\Support\Operations\OperationReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * الفريقُ يقف حيث يُقال له — المرحلة 10: التعيينُ والتعارضُ والحضور.
 *
 * موظّفٌ بحسابه أو متعاقدٌ باسمه بلا حسابٍ مخترَع · الموظّفُ لا يُعيَّن في
 * وقتين معًا (مانع) · وأقلُّ من ساعةٍ بينهما تحذير · وخارجُ النافذة تحذير ·
 * الحضورُ والانصرافُ · والتبليغُ جرسٌ داخليّ وبريدٌ يُقيَّد في المراسلات ·
 * والجارُ لا يُعيَّن.
 */
class TheCrewStandsWhereItIsToldTest extends OperationsTestCase
{
    use RefreshDatabase;

    public function test_internal_and_external_members_are_assigned_without_inventing_accounts(): void
    {
        $op = $this->operation();
        $users = User::count();

        $internal = Crew::assign($op, ['user_id' => $this->field->id, 'role' => 'registration_staff'], $this->owner);
        $external = Crew::assign($op, ['external_name' => 'أحمد المصوّر', 'external_phone' => '92000000', 'role' => 'photographer'], $this->owner);

        $this->assertFalse($internal->isExternal());
        $this->assertTrue($external->isExternal());
        $this->assertNull($external->user_id);
        $this->assertSame('أحمد المصوّر', $external->displayName());
        $this->assertSame($users, User::count(), 'لا حسابَ يُخترع لمتعاقد');

        /* النافذةُ الافتراضيّة نافذةُ الفعاليّة */
        $this->assertSame('2026-10-01 06:00', $internal->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-01 19:00', $internal->ends_at->format('Y-m-d H:i'));
        $this->assertSame(OperationCrewAssignment::PLANNED, $internal->status);
        $this->assertSame(OperationCrewAssignment::BRIEFING_PENDING, $internal->briefing_status);

        $this->assertTrue(ActivityLog::where('subject_type', 'event_operation')->where('subject_id', $op->id)->where('description', 'like', '%عيّن أحمد المصوّر%')->exists());

        /* والقائدُ يُكتب على الفعاليّة إن لم يكن لها قائد */
        $op2 = $this->operation(['operations_lead_id' => null], $this->project('EP-2026-002'));
        $op2->update(['operations_lead_id' => null]);
        Crew::assign($op2, ['user_id' => $this->manager->id, 'role' => 'operations_lead'], $this->owner);
        $this->assertSame($this->manager->id, $op2->fresh()->operations_lead_id);

        $this->expectException(RuntimeException::class);
        Crew::assign($op, ['role' => 'runner'], $this->owner);
    }

    public function test_the_same_user_cannot_be_in_two_overlapping_operations(): void
    {
        $first = $this->operation();
        $second = $this->operation([], $this->project('EP-2026-002'));

        Crew::assign($first, ['user_id' => $this->field->id, 'role' => 'runner'], $this->owner);

        try {
            Crew::assign($second, ['user_id' => $this->field->id, 'role' => 'runner'], $this->owner);
            $this->fail('تعيينٌ متداخل مرّ');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('لا يُعيَّن في وقتين معًا', $e->getMessage());
        }
        $this->assertSame(1, OperationCrewAssignment::where('user_id', $this->field->id)->count());

        /* وفي وقتٍ آخر من اليوم نفسه يمرّ */
        $evening = Crew::assign($second, ['user_id' => $this->field->id, 'role' => 'runner', 'starts_at' => '2026-10-01 20:00', 'ends_at' => '2026-10-01 23:00'], $this->owner);
        $this->assertNotNull($evening->id);

        /* والتعديلُ إلى وقتٍ متداخل يُردّ كذلك */
        try {
            Crew::update($evening, ['starts_at' => '2026-10-01 18:00'], $this->owner);
            $this->fail('تعديلٌ إلى تداخلٍ مرّ');
        } catch (RuntimeException) {
            $this->assertSame('2026-10-01 20:00', $evening->fresh()->starts_at->format('Y-m-d H:i'));
        }

        /* والمتعاقدُ الخارجيّ بلا هويّةٍ تُطابَق — يمرّ في اثنتين */
        Crew::assign($first, ['external_name' => 'متعاقد', 'role' => 'external_contractor'], $this->owner);
        $this->assertNotNull(Crew::assign($second, ['external_name' => 'متعاقد', 'role' => 'external_contractor'], $this->owner)->id);

        /* والموظّفُ مرّتين في الفعاليّة نفسِها يُردّ */
        $this->expectException(RuntimeException::class);
        Crew::assign($first, ['user_id' => $this->field->id, 'role' => 'supervisor'], $this->owner);
    }

    public function test_a_tight_gap_and_an_assignment_outside_the_window_are_warnings_not_blocks(): void
    {
        $first = $this->operation();
        $second = $this->operation(['setup_starts_at' => '2026-10-01 19:30', 'event_starts_at' => '2026-10-01 20:00', 'event_ends_at' => '2026-10-01 23:00', 'teardown_starts_at' => '2026-10-01 23:00', 'teardown_ends_at' => '2026-10-01 23:30'], $this->project('EP-2026-002'));

        Crew::assign($first, ['user_id' => $this->field->id, 'role' => 'runner'], $this->owner);
        /* ينتهي الأوّل 19:00 ويبدأ الثاني 19:30 — نصفُ ساعة */
        Crew::assign($second, ['user_id' => $this->field->id, 'role' => 'runner'], $this->owner);
        /* وتعيينٌ يبدأ قبل النافذة بيوم */
        Crew::assign($second, ['user_id' => $this->manager->id, 'role' => 'supervisor', 'starts_at' => '2026-09-30 06:00', 'ends_at' => '2026-10-01 23:30'], $this->owner);

        $conflicts = Crew::conflicts($second->fresh());
        $kinds = array_column($conflicts, 'kind');
        $this->assertContains('crew_tight_gap', $kinds);
        $this->assertContains('crew_outside_window', $kinds);
        $this->assertNotContains('crew_double_booked', $kinds);
        $this->assertTrue(collect($conflicts)->every(fn ($c) => $c['severity'] === 'warning'));

        /* وتُقرأ في الجاهزيّة تحذيراتٍ لا موانع */
        $verdict = OperationReadiness::for($second->fresh());
        $this->assertContains('crew_tight_gap', array_column($verdict->warnings, 'key'));
        $this->assertNotContains('crew_tight_gap', array_column($verdict->blockers, 'key'));
    }

    public function test_a_double_booking_that_slipped_in_is_a_blocker_in_readiness(): void
    {
        $first = $this->operation();
        $second = $this->operation([], $this->project('EP-2026-002'));
        Crew::assign($first, ['user_id' => $this->field->id, 'role' => 'runner'], $this->owner);

        /* كتابةٌ من غير الباب — تُكشف ولا تُخفى */
        OperationCrewAssignment::create(['business_id' => $this->business->id, 'event_operation_id' => $second->id, 'user_id' => $this->field->id,
            'role' => 'runner', 'starts_at' => '2026-10-01 06:00', 'ends_at' => '2026-10-01 19:00', 'status' => 'planned', 'briefing_status' => 'pending']);

        $verdict = OperationReadiness::for($second->fresh());
        $this->assertContains('crew_double_booked', array_column($verdict->blockers, 'key'));
    }

    public function test_check_in_and_check_out_are_recorded_and_repeat_safely(): void
    {
        $op = $this->operation();
        $a = Crew::assign($op, ['user_id' => $this->field->id, 'role' => 'runner'], $this->owner);

        try {
            Crew::checkOut($a, $this->field);
            $this->fail('انصرافٌ بلا حضور مرّ');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $a = Crew::checkIn($a, $this->field);
        $this->assertSame(OperationCrewAssignment::CHECKED_IN, $a->status);
        $in = $a->checked_in_at->toDateTimeString();
        $this->travelTo('2026-10-01 07:00:00');
        $this->assertSame($in, Crew::checkIn($a, $this->field)->checked_in_at->toDateTimeString());

        $this->assertSame(['expected' => 1, 'checked_in' => 1, 'checked_out' => 0, 'missing' => 0, 'no_show' => 0], Crew::attendance($op->fresh()));

        /* الحاضرُ لا يُزال قبل انصرافه */
        try {
            Crew::remove($a, $this->owner);
            $this->fail('إزالةُ حاضرٍ مرّت');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $a = Crew::checkOut($a, $this->field);
        $this->assertSame(OperationCrewAssignment::CHECKED_OUT, $a->status);

        $b = Crew::assign($op, ['user_id' => $this->manager->id, 'role' => 'supervisor'], $this->owner);
        Crew::remove($b, $this->owner);
        $this->assertSame(OperationCrewAssignment::CANCELLED, $b->fresh()->status);
        $this->assertSame(1, Crew::attendance($op->fresh())['expected']);

        /* ومن شاشة الموقع: المنفّذُ يسجّل حضورَه بلا إذنِ إدارة فريق */
        $c = Crew::assign($op, ['user_id' => $this->storekeeper->id, 'role' => 'printer_operator'], $this->owner);
        $this->actingAs($this->field)->post(route('admin.operations.crew.checkIn', [$op->id, $c->id]))->assertRedirect();
        $this->assertSame(OperationCrewAssignment::CHECKED_IN, $c->fresh()->status);
    }

    public function test_notifying_a_member_rings_the_bell_and_records_the_email_as_internal(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.om']);
        Mail::fake();

        $op = $this->operation();
        $a = Crew::assign($op, ['user_id' => $this->field->id, 'role' => 'runner', 'notes' => 'الوصول 5:30'], $this->owner);

        $result = Crew::notify($a, $this->owner);
        $this->assertTrue($result['notified']);
        $this->assertTrue($result['emailed']);
        $this->assertNotNull($a->fresh()->notified_at);

        $bell = InternalNotification::where('user_id', $this->field->id)->where('kind', 'crew_assignment')->first();
        $this->assertNotNull($bell);
        $this->assertStringContainsString('اليوم الأوّل', $bell->title);
        $this->assertSame(route('admin.operations.onsite', $op->id), $bell->url);

        /* والبريدُ عبر المرحلة 6 — مقيَّدٌ داخليًّا عن المشروع، لا رسالةَ للعميل */
        $mail = Communication::where('template_key', 'operation.crew_assignment')->first();
        $this->assertNotNull($mail);
        $this->assertSame('field@hadath.om', $mail->recipient);
        $this->assertSame($this->project->id, $mail->project_id);
        $this->assertTrue((bool) ($mail->metadata['internal'] ?? false));
        $this->assertSame(0, Communication::where('recipient', 'like', '%ministry%')->count());

        /* والمتعاقدُ بلا حساب لا يُبلَّغ من هنا */
        $ext = Crew::assign($op, ['external_name' => 'متعاقد', 'role' => 'external_contractor'], $this->owner);
        $this->expectException(RuntimeException::class);
        Crew::notify($ext, $this->owner);
    }

    public function test_an_operational_alert_reaches_every_member_with_an_account_and_is_recorded(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.om']);
        Mail::fake();

        $op = $this->operation();
        Crew::assign($op, ['user_id' => $this->field->id, 'role' => 'runner'], $this->owner);
        Crew::assign($op, ['user_id' => $this->storekeeper->id, 'role' => 'printer_operator'], $this->owner);
        Crew::assign($op, ['external_name' => 'متعاقد', 'role' => 'external_contractor'], $this->owner);
        $removed = Crew::assign($op, ['user_id' => $this->manager->id, 'role' => 'supervisor'], $this->owner);
        Crew::remove($removed, $this->owner);

        $result = Crew::alert($op->fresh(), 'تأخّر فتح القاعة ساعة — الوصول 7:00', $this->owner);
        $this->assertSame(['count' => 2, 'emailed' => 2], $result);
        $this->assertSame(2, InternalNotification::where('kind', 'operation_alert')->count());
        $this->assertSame(0, InternalNotification::where('kind', 'operation_alert')->where('user_id', $this->manager->id)->count(), 'من أُزيل لا يبلغه');
        $this->assertSame(2, Communication::where('template_key', 'operation.alert')->count());
        $this->assertTrue(Communication::where('template_key', 'operation.alert')->get()->every(fn ($c) => (bool) ($c->metadata['internal'] ?? false)));
        $this->assertTrue(ActivityLog::where('subject_id', $op->id)->where('description', 'like', 'نبّه فريق الفعاليّة%')->exists());

        /* ومن الشاشة: المنفّذُ (ينقل الحال) يُنبّه، والبائعُ لا */
        $this->actingAs($this->field)->post(route('admin.operations.crew.alert', $op->id), ['message' => 'الغداء 13:00'])->assertRedirect();
        $this->assertSame(4, InternalNotification::where('kind', 'operation_alert')->count());
        $this->actingAs($this->seller)->post(route('admin.operations.crew.alert', $op->id), ['message' => 'x'])->assertForbidden();

        $this->expectException(RuntimeException::class);
        Crew::alert($op->fresh(), '   ', $this->owner);
    }

    public function test_a_neighbours_user_cannot_be_assigned_and_crew_management_needs_its_permission(): void
    {
        $op = $this->operation();

        try {
            Crew::assign($op, ['user_id' => $this->stranger->id, 'role' => 'runner'], $this->owner);
            $this->fail('موظّفُ الجار عُيّن');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ليس من شركتك', $e->getMessage());
        }

        $this->actingAs($this->manager)->post(route('admin.operations.crew.store', $op->id), ['user_id' => $this->field->id, 'role' => 'runner'])->assertRedirect();
        $this->assertSame(1, OperationCrewAssignment::count());

        $this->actingAs($this->storekeeper)->post(route('admin.operations.crew.store', $op->id), ['user_id' => $this->manager->id, 'role' => 'runner'])->assertForbidden();
        $this->actingAs($this->stranger)->post(route('admin.operations.crew.store', $op->id), ['external_name' => 'x', 'role' => 'runner'])->assertNotFound();
        $this->assertSame(1, OperationCrewAssignment::count());

        /* والتعيينُ لا يُكتب لفعاليّةٍ خُتمت */
        EventOperations::cancel($op, 'أُلغيت', $this->owner);
        $this->expectException(RuntimeException::class);
        Crew::assign($op->fresh(), ['external_name' => 'x', 'role' => 'runner'], $this->owner);
    }
}
