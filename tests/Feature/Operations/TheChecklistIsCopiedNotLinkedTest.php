<?php

namespace Tests\Feature\Operations;

use App\Models\ActivityLog;
use App\Models\OperationChecklistItem;
use App\Models\OperationChecklistTemplate;
use App\Support\Operations\Checklists;
use App\Support\Operations\OperationReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;

/**
 * قائمةُ التحقّق تُنسخ ولا تُربط — المرحلة 10.
 *
 * القوالبُ الثلاثة تُكتب مرّةً لكلّ شركة · الإلحاقُ نسخةٌ لا تتغيّر بتغيّر
 * القالب · الإنجازُ آمنٌ عند التكرار · التعطيلُ بسبب · الإعفاءُ بإذنٍ وسببٍ
 * وأثرٍ ويبقى تحذيرًا · والإلزاميُّ الناقص يمنع الجاهزيّة.
 */
class TheChecklistIsCopiedNotLinkedTest extends OperationsTestCase
{
    use RefreshDatabase;

    public function test_the_default_templates_are_written_once_per_company(): void
    {
        Checklists::ensure((int) $this->business->id);
        Checklists::ensure((int) $this->business->id);

        $templates = OperationChecklistTemplate::where('business_id', $this->business->id)->get();
        $this->assertCount(3, $templates);
        $this->assertSame(['pre_event', 'setup', 'closing'], $templates->sortBy('sort_order')->pluck('phase')->values()->all());
        $this->assertSame(13, $templates->firstWhere('phase', 'pre_event')->items()->count());
        $this->assertSame(6, $templates->firstWhere('phase', 'closing')->items()->count());

        /* والشركةُ الأخرى قوالبُها لها وحدَها */
        Checklists::ensure((int) $this->other->id);
        $this->assertSame(3, OperationChecklistTemplate::where('business_id', $this->other->id)->count());
        $this->assertSame(6, OperationChecklistTemplate::count());
    }

    public function test_attaching_copies_the_items_and_later_template_edits_do_not_reach_the_copy(): void
    {
        $op = $this->operation();
        $template = Checklists::createTemplate((int) $this->business->id, ['name' => 'تسليم العميل', 'phase' => 'custom',
            'items' => [['title' => 'توقيع محضر', 'required' => true], ['title' => 'صور', 'required' => false]]], $this->owner);

        $checklist = Checklists::attach($op, $template, $this->owner);
        $this->assertSame(['توقيع محضر', 'صور'], $checklist->items()->pluck('title')->all());
        $this->assertSame($checklist->id, Checklists::attach($op, $template, $this->owner)->id, 'لا تُلحق القائمةُ نفسُها مرّتين');

        Checklists::updateTemplate($template, ['name' => 'تسليم', 'items' => [['title' => 'بند جديد']]], $this->owner);
        $this->assertSame(['توقيع محضر', 'صور'], $checklist->fresh()->items()->pluck('title')->all(), 'النسخةُ لا تتبع القالب');
        $this->assertSame('تسليم العميل', $checklist->fresh()->name);

        Checklists::deleteTemplate($template, $this->owner);
        $this->assertNull($checklist->fresh()->template_id);
        $this->assertSame(2, $checklist->fresh()->items()->count());

        /* وقالبُ الجار لا يُلحق */
        Checklists::ensure((int) $this->other->id);
        $this->expectException(RuntimeException::class);
        Checklists::attach($op, OperationChecklistTemplate::where('business_id', $this->other->id)->first(), $this->owner);
    }

    public function test_a_template_needs_a_name_and_at_least_one_item(): void
    {
        try {
            Checklists::createTemplate((int) $this->business->id, ['name' => '', 'items' => [['title' => 'x']]], $this->owner);
            $this->fail('قالبٌ بلا اسم مرّ');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $this->expectException(RuntimeException::class);
        Checklists::createTemplate((int) $this->business->id, ['name' => 'فارغ', 'items' => []], $this->owner);
    }

    public function test_completing_blocking_and_reopening_an_item(): void
    {
        $op = $this->operation();
        $item = OperationChecklistItem::whereHas('checklist', fn ($q) => $q->where('event_operation_id', $op->id))->first();

        $done = Checklists::complete($item, 'اختُبرت على ٤ أجهزة', $this->field);
        $this->assertSame(OperationChecklistItem::DONE, $done->status);
        $this->assertSame($this->field->id, $done->completed_by);
        $this->assertSame('اختُبرت على ٤ أجهزة', $done->evidence_note);
        $at = $done->completed_at->toDateTimeString();

        $this->travelTo('2026-09-21 10:00:00');
        $again = Checklists::complete($done, 'شيء آخر', $this->owner);
        $this->assertSame($at, $again->completed_at->toDateTimeString(), 'الإنجازُ الثاني لا يكتب');
        $this->assertSame($this->field->id, $again->completed_by);
        $this->assertSame('اختُبرت على ٤ أجهزة', $again->evidence_note);

        $pending = Checklists::reopen($again, $this->owner);
        $this->assertSame(OperationChecklistItem::PENDING, $pending->status);
        $this->assertNull($pending->completed_at);

        $blocked = Checklists::block($pending, 'الإنترنت لم يُركَّب بعد', $this->field);
        $this->assertSame(OperationChecklistItem::BLOCKED, $blocked->status);
        $this->assertSame('الإنترنت لم يُركَّب بعد', $blocked->blocked_reason);

        $this->expectException(RuntimeException::class);
        Checklists::block($blocked, '', $this->field);
    }

    public function test_waiving_needs_the_permission_and_a_reason_and_stays_a_warning(): void
    {
        $op = $this->operation();
        $checklist = $op->checklists()->where('phase', 'pre_event')->first();
        $items = $checklist->items;
        $required = $items->where('required', true);

        foreach ($required->slice(1) as $i) {
            Checklists::complete($i, null, $this->field);
        }
        $last = $required->first();

        $verdict = OperationReadiness::for($op->fresh());
        $this->assertTrue(collect($verdict->blockers)->contains(fn ($b) => str_starts_with($b['key'], 'checklist_pre_event')));

        try {
            Checklists::waive($last, 'لا حاجة', $this->field);
            $this->fail('إعفاءٌ بلا إذن مرّ');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('صلاحيّة', $e->getMessage());
        }
        try {
            Checklists::waive($last, '  ', $this->owner);
            $this->fail('إعفاءٌ بلا سبب مرّ');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $waived = Checklists::waive($last, 'العميل لم يرسل القائمة — تُستلم صباحًا', $this->owner);
        $this->assertSame(OperationChecklistItem::WAIVED, $waived->status);
        $this->assertSame($this->owner->id, $waived->waived_by);
        $this->assertTrue(ActivityLog::where('subject_type', 'event_operation')->where('subject_id', $op->id)->where('description', 'like', '%أعفى بند التحقّق%')->exists());

        $verdict = OperationReadiness::for($op->fresh());
        $this->assertFalse(collect($verdict->blockers)->contains(fn ($b) => str_starts_with($b['key'], 'checklist_pre_event')), 'الإعفاءُ يرفع المانع');
        $this->assertTrue(collect($verdict->warnings)->contains(fn ($w) => str_starts_with($w['key'], 'checklist_waived')), 'ويبقى تحذيرًا');
        $this->assertTrue(collect($verdict->warnings)->contains(fn ($w) => str_starts_with($w['key'], 'checklist_optional')), 'والاختياريُّ الناقص تحذير');

        $progress = collect(Checklists::progress($op->fresh()))->firstWhere('phase', 'pre_event');
        $this->assertSame(0, $progress['required_pending']);
        $this->assertSame(1, $progress['waived']);
        $this->assertSame($required->count() - 1, $progress['done']);
    }

    public function test_the_screens_guard_template_management_and_let_the_field_tick_items(): void
    {
        $op = $this->operation();
        $item = OperationChecklistItem::whereHas('checklist', fn ($q) => $q->where('event_operation_id', $op->id))->first();

        $this->actingAs($this->field)->post(route('admin.operations.checklistItems.complete', [$op->id, $item->id]), ['evidence' => 'تمّ'])->assertRedirect();
        $this->assertSame(OperationChecklistItem::DONE, $item->fresh()->status);

        $this->actingAs($this->field)->post(route('admin.operations.checklistItems.waive', [$op->id, $item->id]), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->field)->post(route('admin.operations.templates.store'), ['name' => 'x', 'items' => [['title' => 'y']]])->assertForbidden();

        $this->actingAs($this->manager)->post(route('admin.operations.templates.store'), ['name' => 'مخصَّص', 'phase' => 'custom', 'items' => [['title' => 'بند', 'required' => true]]])->assertRedirect();
        $this->assertSame(4, OperationChecklistTemplate::where('business_id', $this->business->id)->count());

        /* والجارُ لا يبلغ بنودَنا */
        $this->actingAs($this->stranger)->post(route('admin.operations.checklistItems.complete', [$op->id, $item->id]))->assertNotFound();
        $this->actingAs($this->stranger)->get(route('admin.operations.templates'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('templates', 3)->where('templates.0.name', 'قبل الفعاليّة'));
        $this->assertSame(3, OperationChecklistTemplate::where('business_id', $this->other->id)->count(), 'الجارُ حين فتح الشاشة كُتبت له قوالبُه هو');
    }
}
