<?php

namespace Tests\Feature\V1;

use App\Models\ActivityLog;
use App\Models\AssetReservation;
use App\Models\EventOperation;
use App\Models\OperationChecklistTemplate;
use App\Models\OperationIncident;
use App\Models\Product;
use App\Support\Operations\Assets;
use App\Support\Operations\Checklists;
use App\Support\Operations\Consumables;
use App\Support\Operations\Crew;
use App\Support\Operations\EventOperations;
use App\Support\Operations\Incidents;
use App\Support\Operations\OperationReadiness;
use App\Support\Operations\Requirements;
use App\Support\Operations\Reservations;
use App\Support\Operations\Schedule;
use App\Support\ProjectFinancials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\Operations\OperationsTestCase;

/**
 * الفعاليّةُ الذهبيّة — من الخطّة إلى الإقفال (المرحلة 12 §9 · §90).
 *
 * تُخطَّط وتُجهَّز، وتُسأل جاهزيّتُها فترفض البثَّ بموانعَ صلبة، ولا تُتجاوَز
 * إلّا بصلاحيّةٍ وسببٍ وأثر؛ يحضر الفريق وتُسلَّم القطعُ وتُصرف المستهلَكات
 * ويُبلَّغ حادثٌ ويُحلّ، وتُفكّ وتُقفل — ولا تُقفل وقطعةٌ في الميدان أو حادثٌ
 * حرجٌ مفتوح. والحالاتُ لا تقفز.
 */
class TheGoldenEventRunsFromPlanToCloseoutTest extends OperationsTestCase
{
    use RefreshDatabase;

    public function test_the_event_cannot_go_live_past_a_hard_blocker_without_an_authorised_reasoned_override(): void
    {
        $this->actingAs($this->owner);
        $op = $this->operation();
        $this->assertSame(EventOperation::PLANNING, $op->status);

        /* لا تقفز: من التخطيط إلى «على الهواء» مباشرةً ممنوع */
        try {
            EventOperations::transition($op, EventOperation::LIVE, $this->owner);
            $this->fail('قفزت الحالة');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('لا تنتقل', $e->getMessage());
        }

        $op = EventOperations::transition($op, EventOperation::PREPARING, $this->owner);

        /* بلا فريقٍ: مانعٌ حرج — «جاهزة» تُرفض */
        $verdict = OperationReadiness::for($op->fresh());
        $this->assertFalse($verdict->ready);
        $this->assertContains('crew', array_column($verdict->blockers, 'key'));
        try {
            EventOperations::transition($op->fresh(), EventOperation::READY, $this->owner);
            $this->fail('بلا فريقٍ صارت جاهزة');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ليست جاهزة', $e->getMessage());
        }

        /* المديرُ لا يملك التجاوز — ولو كتب سببًا */
        try {
            EventOperations::transition($op->fresh(), EventOperation::READY, $this->manager, null, 'العميل يلحّ');
            $this->fail('تجاوزٌ بلا صلاحيّة');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('صلاحيّة', $e->getMessage());
        }

        /* والمالكُ يملكه — لكن بسببٍ مكتوب */
        try {
            EventOperations::transition($op->fresh(), EventOperation::READY, $this->owner, null, '   ');
            $this->fail('تجاوزٌ بلا سبب');
        } catch (RuntimeException) {
        }
        $op = EventOperations::transition($op->fresh(), EventOperation::READY, $this->owner, null, 'العميل يلحّ والفريق في الطريق');
        $this->assertSame(EventOperation::READY, $op->status);
        $this->assertCount(1, $op->readiness_override);
        $this->assertSame($this->owner->id, $op->readiness_override[0]['by_id']);
        $this->assertNotEmpty($op->readiness_override[0]['blockers']);
        $this->assertTrue(ActivityLog::where('subject_type', 'event_operation')->where('subject_id', $op->id)->where('description', 'like', '%تجاوز جاهزيّة%')->exists(), 'التجاوزُ على السجلّ');
    }

    public function test_the_whole_lifecycle_runs_and_closeout_refuses_to_bury_anything(): void
    {
        $this->actingAs($this->owner);
        $op = $this->operation();
        $product = Product::create(['business_id' => $this->business->id, 'name' => 'بطاقة', 'price' => 2, 'cost' => 0.5, 'quantity' => 2500, 'alert_qty' => 1, 'active' => true, 'sku' => 'CARD']);
        \App\Support\Ledger::seedChart((int) $this->business->id);

        /* التخطيط: جدولٌ ومتطلّباتٌ وقطعٌ وفريقٌ وقوائم */
        Schedule::add($op, ['title' => 'تجهيز المسرح', 'type' => 'setup', 'starts_at' => '2026-10-01 06:00', 'ends_at' => '2026-10-01 08:00'], $this->owner);
        $teardown = Schedule::add($op, ['title' => 'فكّ المسرح', 'type' => 'teardown', 'starts_at' => '2026-10-01 17:00', 'ends_at' => '2026-10-01 19:00'], $this->owner);
        $laptop = $this->asset('حاسوب', 'laptop');
        $backup = $this->asset('حاسوب احتياطي', 'laptop');
        $req = Requirements::add($op, ['kind' => 'asset_category', 'asset_category' => 'laptop', 'required_quantity' => 1, 'critical' => true], $this->owner);
        $backupReq = Requirements::add($op, ['kind' => 'asset_category', 'asset_category' => 'laptop', 'required_quantity' => 1, 'backup' => true], $this->owner);
        $reservation = Reservations::reserve($op, $laptop, ['requirement_id' => $req->id], $this->owner);
        $backupReservation = Reservations::reserve($op, $backup, ['requirement_id' => $backupReq->id], $this->owner);
        $crew = Crew::assign($op, ['user_id' => $this->field->id, 'role' => 'technical_support'], $this->owner);
        Crew::update($crew, ['briefing_status' => 'briefed'], $this->owner);
        $template = Checklists::createTemplate((int) $this->business->id, ['name' => 'الإقفال', 'phase' => OperationChecklistTemplate::CLOSING, 'items' => [['title' => 'تسليم الموقع', 'required' => true]]], $this->owner);
        Checklists::attach($op, $template, $this->owner);
        $closingItem = $op->checklists()->where('phase', OperationChecklistTemplate::CLOSING)->firstOrFail()->items()->firstOrFail();
        /* والقوائمُ الافتراضيّة (قبل الفعاليّة) تُنجَز بنودُها الإلزاميّة كما يفعل الفريق */
        foreach ($op->checklists()->where('phase', '!=', OperationChecklistTemplate::CLOSING)->get() as $list) {
            foreach ($list->items()->where('required', true)->get() as $item) {
                Checklists::complete($item, 'تمّ', $this->owner);
            }
        }

        $op = EventOperations::transition($op->fresh(), EventOperation::PREPARING, $this->owner);
        $op = EventOperations::transition($op->fresh(), EventOperation::READY, $this->owner);
        $this->assertSame(EventOperation::READY, $op->status);
        $this->assertSame([], $op->readiness_override ?? [], 'جاهزةٌ بلا تجاوز');

        $op = EventOperations::transition($op->fresh(), EventOperation::SETUP, $this->owner);

        /* البثُّ يحتاج القائدَ في الموقع والقطعَ الحرجةَ مسلَّمة */
        $live = OperationReadiness::live($op->fresh());
        $this->assertFalse($live->ready);
        Crew::assign($op->fresh(), ['user_id' => $this->manager->id, 'role' => 'operations_lead'], $this->owner);
        foreach ($op->fresh()->crew as $a) {
            Crew::checkIn($a, $this->owner);
        }
        Reservations::checkOut($reservation->fresh(), ['custodian_id' => $this->field->id, 'condition' => 'good'], $this->field);
        Reservations::checkOut($backupReservation->fresh(), ['custodian_id' => $this->field->id, 'condition' => 'good'], $this->field);
        $this->assertSame(AssetReservation::CHECKED_OUT, $reservation->fresh()->status);
        $this->assertTrue(OperationReadiness::live($op->fresh())->ready, implode(' · ', OperationReadiness::live($op->fresh())->blockerMessages()));

        $op = EventOperations::transition($op->fresh(), EventOperation::LIVE, $this->owner);
        $this->assertSame(EventOperation::LIVE, $op->status);
        $this->assertNotNull($op->went_live_at);

        /* في الميدان: مستهلَكاتٌ وحادثٌ */
        $issue = Consumables::issue($op, $product, ['quantity' => 2000, 'key' => 'g'], $this->field);
        $incident = Incidents::report($op, ['title' => 'انقطاع الشبكة', 'category' => 'network', 'severity' => OperationIncident::HIGH], $this->field);

        $op = EventOperations::transition($op->fresh(), EventOperation::TEARDOWN, $this->owner);

        /* الإقفالُ يرفض: قطعةٌ في الميدان، حادثٌ حرجٌ مفتوح، بندُ فكٍّ لم يكتمل، قائمةُ إقفالٍ ناقصة */
        $blockers = EventOperations::completionBlockers($op->fresh());
        $this->assertGreaterThanOrEqual(4, count($blockers), implode(' · ', $blockers));
        try {
            EventOperations::complete($op->fresh(), $this->owner);
            $this->fail('أُقفلت وفيها ما لم يُعالَج');
        } catch (RuntimeException) {
        }

        /* تُعالَج واحدةً واحدة */
        Consumables::giveBack($issue, ['quantity' => 300], $this->field);
        Reservations::giveBack($reservation->fresh(), ['condition' => 'good'], $this->field);
        Reservations::giveBack($backupReservation->fresh(), ['condition' => 'good'], $this->field);
        Incidents::resolve($incident, 'أُعيد الاتّصال', $this->field);
        Schedule::setStatus($teardown, 'completed', $this->owner);
        Checklists::complete($closingItem, 'سُلّم الموقع', $this->owner);
        /* وقائمةُ الإقفال الافتراضيّة كذلك — كلُّ إلزاميٍّ فيها */
        foreach ($op->fresh()->checklists()->where('phase', OperationChecklistTemplate::CLOSING)->get() as $list) {
            foreach ($list->items()->where('required', true)->whereNotIn('status', ['done', 'waived'])->get() as $item) {
                Checklists::complete($item, 'تمّ', $this->owner);
            }
        }
        $this->assertSame([], EventOperations::completionBlockers($op->fresh()));

        $op = EventOperations::complete($op->fresh(), $this->owner);
        $this->assertSame(EventOperation::COMPLETED, $op->status);
        $this->assertFalse($op->isActive());

        /* وبعد الإقفال لا يُصرف ولا يُحجز ولا يُعيَّن */
        foreach ([
            fn () => Consumables::issue($op->fresh(), $product, ['quantity' => 1], $this->owner),
            fn () => Reservations::reserve($op->fresh(), $this->asset('ثالث', 'laptop'), [], $this->owner),
            fn () => Crew::assign($op->fresh(), ['user_id' => $this->manager->id, 'role' => 'runner'], $this->owner),
        ] as $forbidden) {
            try {
                $forbidden();
                $this->fail('فعاليّةٌ مقفلة قبلت كتابة');
            } catch (RuntimeException) {
            }
        }

        /* والمالُ يقرأ ما استُهلك: 2000 − 300 = 1700 × 0.5 */
        $this->assertSame(800, (int) $product->fresh()->quantity, '2500 − 2000 + 300');
        $this->assertEqualsWithDelta(850.0, ProjectFinancials::summary($this->project->fresh())['stock_consumed'], 0.0005);
        $this->assertEqualsWithDelta(850.0, \App\Support\Ledger::balance((int) $this->business->id, 'cogs'), 0.0005);
        $this->assertSame('available', $laptop->fresh()->status);
    }

    public function test_every_state_transition_is_a_known_edge(): void
    {
        $edges = [];
        foreach (EventOperation::STATUSES as $from) {
            foreach (EventOperations::allowedFrom($from) as $to) {
                $edges[] = "{$from}→{$to}";
            }
        }
        $this->assertSame([
            'planning→preparing', 'planning→cancelled',
            'preparing→ready', 'preparing→planning', 'preparing→cancelled',
            'ready→setup', 'ready→preparing', 'ready→cancelled',
            'setup→live', 'setup→ready', 'setup→cancelled',
            'live→teardown',
            'teardown→completed', 'teardown→live',
        ], $edges, 'تغيّرت خريطةُ الحالات — إن كان عمدًا فحدّث هذا الحارس');
    }
}
