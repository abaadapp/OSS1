<?php

namespace Tests\Feature\Operations;

use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\OperationIncident;
use App\Support\Operations\Incidents;
use App\Support\Operations\OperationReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;

/**
 * الحادثُ لا يُدفن تحت «مكتملة» — المرحلة 10: التسجيلُ والإسنادُ والحلّ.
 *
 * يُسجَّل سريعًا بتصنيفٍ وشدّة · يُسنَد · يُحلّ بنصٍّ · يُقفل بعد الحلّ لا قبله ·
 * يُعاد فتحُه · تلفٌ على قطعةٍ يوسمها · والحرجُ المفتوح يمنع البثّ ·
 * والأذونُ والعزل.
 */
class AnIncidentIsNotBuriedUnderCompletedTest extends OperationsTestCase
{
    use RefreshDatabase;

    public function test_an_incident_is_reported_assigned_resolved_and_closed_in_order(): void
    {
        $op = $this->operation();

        $incident = Incidents::report($op, ['title' => 'الطابعة الثانية توقّفت', 'category' => 'equipment', 'severity' => 'high', 'description' => 'خطأ ورق'], $this->field);
        $this->assertSame(OperationIncident::OPEN, $incident->status);
        $this->assertSame($this->field->id, $incident->reported_by);
        $this->assertSame('2026-09-20 10:00', $incident->occurred_at->format('Y-m-d H:i'));
        $this->assertTrue(ActivityLog::where('subject_type', 'operation_incident')->where('subject_id', $incident->id)->where('color', 'danger')->exists());

        /* لا إقفالَ قبل الحلّ */
        try {
            Incidents::close($incident, $this->owner);
            $this->fail('إقفالٌ قبل الحلّ مرّ');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $incident = Incidents::update($incident, ['assigned_to' => $this->manager->id, 'status' => 'investigating'], $this->owner);
        $this->assertSame($this->manager->id, $incident->assigned_to);
        $this->assertSame(OperationIncident::INVESTIGATING, $incident->status);

        try {
            Incidents::resolve($incident, '   ', $this->manager);
            $this->fail('حلٌّ بلا نصّ مرّ');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $incident = Incidents::resolve($incident, 'بُدّلت بكرة الورق', $this->manager);
        $this->assertSame(OperationIncident::RESOLVED, $incident->status);
        $this->assertSame($this->manager->id, $incident->resolved_by);
        $at = $incident->resolved_at->toDateTimeString();

        $this->travelTo('2026-09-20 12:00:00');
        $this->assertSame($at, Incidents::resolve($incident, 'ثانية', $this->owner)->resolved_at->toDateTimeString(), 'الحلُّ الثاني لا يكتب');
        $this->assertSame('بُدّلت بكرة الورق', $incident->fresh()->resolution);

        $incident = Incidents::close($incident, $this->owner);
        $this->assertSame(OperationIncident::CLOSED, $incident->status);

        $incident = Incidents::reopen($incident, $this->owner);
        $this->assertSame(OperationIncident::OPEN, $incident->status);
        $this->assertNull($incident->resolved_at);
    }

    public function test_severity_and_category_come_from_closed_lists(): void
    {
        $op = $this->operation();

        try {
            Incidents::report($op, ['title' => 'x', 'severity' => 'apocalyptic'], $this->field);
            $this->fail('شدّةٌ مخترَعة مرّت');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }
        try {
            Incidents::report($op, ['title' => 'x', 'category' => 'weather'], $this->field);
            $this->fail('تصنيفٌ مخترَع مرّ');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $this->expectException(RuntimeException::class);
        Incidents::report($op, ['title' => ''], $this->field);
    }

    public function test_a_damage_incident_on_an_asset_marks_it_damaged(): void
    {
        $op = $this->operation();
        $asset = $this->asset('شاشة', 'screen');

        $incident = Incidents::report($op, ['title' => 'كُسرت الشاشة', 'category' => 'damage', 'severity' => 'medium', 'asset_id' => $asset->id], $this->field);
        $this->assertSame($asset->id, $incident->asset_id);
        $this->assertSame(Asset::DAMAGED, $asset->fresh()->status);
        $this->assertTrue(ActivityLog::where('subject_type', 'asset')->where('subject_id', $asset->id)->where('description', 'like', '%كُسرت الشاشة%')->exists());

        /* وقطعةُ الجار لا تُذكر */
        $theirs = $this->asset('x', 'y', [], (int) $this->other->id);
        $this->expectException(RuntimeException::class);
        Incidents::report($op, ['title' => 'x', 'asset_id' => $theirs->id], $this->field);
    }

    public function test_an_open_critical_incident_blocks_going_live_and_appears_in_the_active_list(): void
    {
        $op = $this->operation();
        $critical = Incidents::report($op, ['title' => 'انقطع التيار', 'severity' => 'critical'], $this->field);
        Incidents::report($op, ['title' => 'قلم ناقص', 'severity' => 'low'], $this->field);

        $verdict = OperationReadiness::live($op->fresh());
        $this->assertContains('incidents', array_column($verdict->blockers, 'key'));

        $active = Incidents::active((int) $this->business->id);
        $this->assertSame([$critical->id], $active->pluck('id')->all(), 'الحرجُ والعالي وحدَهما');

        Incidents::resolve($critical, 'عاد التيار', $this->field);
        $this->assertNotContains('incidents', array_column(OperationReadiness::live($op->fresh())->blockers, 'key'));
        $this->assertCount(0, Incidents::active((int) $this->business->id));
    }

    public function test_carrying_forward_needs_a_reason_and_only_for_unresolved(): void
    {
        $op = $this->operation();
        $incident = Incidents::report($op, ['title' => 'x', 'severity' => 'high'], $this->field);

        try {
            Incidents::carryForward($incident, '', $this->owner);
            $this->fail('حملٌ بلا سبب مرّ');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $incident = Incidents::carryForward($incident, 'يُتابَع مع المورّد', $this->owner);
        $this->assertTrue($incident->carried_forward);
        $this->assertSame('يُتابَع مع المورّد', $incident->metadata['carried_forward']['reason']);

        Incidents::resolve($incident, 'حُلّ', $this->owner);
        $this->expectException(RuntimeException::class);
        Incidents::carryForward($incident->fresh(), 'x', $this->owner);
    }

    public function test_the_field_reports_the_seller_cannot_and_the_neighbour_sees_nothing(): void
    {
        $op = $this->operation();

        $this->actingAs($this->field)->post(route('admin.operations.incidents.store', $op->id), ['title' => 'ماسح معطّل', 'category' => 'equipment', 'severity' => 'medium'])->assertRedirect();
        $incident = OperationIncident::first();
        $this->assertNotNull($incident);
        $this->assertSame($this->field->id, $incident->reported_by);

        $this->actingAs($this->field)->post(route('admin.operations.incidents.resolve', [$op->id, $incident->id]), ['resolution' => 'أُعيد تشغيله'])->assertRedirect();
        $this->assertSame(OperationIncident::RESOLVED, $incident->fresh()->status);

        /* أمينُ المخزن يفتح المعدّات لا العمليات — والبائعُ لا شيء */
        $this->actingAs($this->storekeeper)->post(route('admin.operations.incidents.store', $op->id), ['title' => 'x'])->assertForbidden();
        $this->actingAs($this->seller)->post(route('admin.operations.incidents.store', $op->id), ['title' => 'x'])->assertForbidden();
        $this->actingAs($this->stranger)->post(route('admin.operations.incidents.store', $op->id), ['title' => 'x'])->assertNotFound();
        $this->actingAs($this->stranger)->post(route('admin.operations.incidents.resolve', [$op->id, $incident->id]), ['resolution' => 'x'])->assertNotFound();
        $this->assertSame(1, OperationIncident::count());

        /* والحملُ من الشاشة لمن ينقل الحال */
        $critical = Incidents::report($op, ['title' => 'حرج', 'severity' => 'critical'], $this->field);
        $this->actingAs($this->field)->post(route('admin.operations.incidents.carryForward', [$op->id, $critical->id]), ['reason' => 'يُتابَع'])->assertRedirect();
        $this->assertTrue($critical->fresh()->carried_forward);
    }
}
