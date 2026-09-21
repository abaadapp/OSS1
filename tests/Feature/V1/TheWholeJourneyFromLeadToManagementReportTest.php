<?php

namespace Tests\Feature\V1;

use App\Models\ActivityLog;
use App\Models\ChangeOrder;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\DeliveryPackage;
use App\Models\EventOperation;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Project;
use App\Models\Quotation;
use App\Support\ChangeOrders;
use App\Support\Intelligence\Period;
use App\Support\Intelligence\ProjectProfitability;
use App\Support\Ledger;
use App\Support\Operations\Checklists;
use App\Support\Operations\Crew;
use App\Support\Operations\EventOperations;
use App\Support\Operations\Schedule;
use App\Support\ProjectFinancials;
use App\Support\PublicDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Management\ManagementTestCase;

/**
 * الرحلةُ كاملةً من العميل إلى تقرير الإدارة — عبر أبواب النظام الحقيقيّة (المرحلة 12 §4).
 *
 * عميلٌ → عرضٌ يُكتب ويُرسَل → يقبله العميلُ من رابطه → مشروعٌ بنطاقٍ معتمَد →
 * أمرُ تغييرٍ يُقبل → فعاليّةٌ تُجهَّز وتُبثّ وتُقفل → حزمةُ تسليمٍ يقبلها العميل
 * من رابطها → فاتورةٌ تُصدَر → دفعةٌ تُقبض → الدفترُ متوازن → ربحيّةُ المشروع
 * وشاشاتُ الإدارة وتقاريرُها تُفتح وتقول الرقمَ نفسَه — وكلُّ خطوةٍ على السجلّ.
 */
class TheWholeJourneyFromLeadToManagementReportTest extends ManagementTestCase
{
    use RefreshDatabase;

    public function test_the_golden_path_holds_from_the_first_quotation_to_the_last_report(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.om']);
        $bid = (int) $this->business->id;

        /* 1) العرض — يُكتب ويُرسَل من الشاشة */
        $this->actingAs($this->owner)->post(route('admin.quotations.store'), [
            'customer_id' => $this->customer->id, 'title' => 'مؤتمر الطاقة 2026', 'issued_at' => '2026-09-01', 'valid_until' => '2026-10-01',
            'items' => [['description' => 'تنظيم المؤتمر', 'quantity' => 1, 'unit_price' => 8000], ['description' => 'تسجيل الحضور', 'quantity' => 200, 'unit_price' => 5]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $q = Quotation::where('business_id', $bid)->sole();
        $this->assertEqualsWithDelta(9450.0, (float) $q->total, 0.0005, '9000 + 5٪');
        $this->actingAs($this->owner)->post(route('admin.quotations.send', $q->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(Quotation::SENT, $q->fresh()->status);

        /* 2) العميلُ يقبل من رابطه — ببصمةٍ وإقرار */
        $token = PublicDocument::token($q->fresh());
        $this->assertNotNull($token);
        auth()->logout();
        $this->get("/i/$token")->assertOk()->assertSee('مؤتمر الطاقة 2026');
        $this->post("/i/$token/decision", ['decision' => 'accepted', 'name' => 'سالم الحارثي', 'title' => 'مدير المشتريات', 'email' => 'buyer@ministry.om', 'consent' => '1'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(Quotation::ACCEPTED, $q->fresh()->status);
        $this->post("/i/$token/decision", ['decision' => 'accepted', 'name' => 'سالم', 'consent' => '1'])->assertSessionHasErrors('decision');

        /* 3) المشروعُ وُلد بنطاقه */
        $project = Project::where('quotation_id', $q->id)->sole();
        $this->assertSame(Project::CONFIRMED, $project->status);
        $this->assertEqualsWithDelta(9450.0, (float) $project->approved_value, 0.0005);
        $this->assertCount(2, $project->items);
        $this->assertTrue(ActivityLog::where('subject_type', 'project')->where('subject_id', $project->id)->exists());

        /* 4) أمرُ تغييرٍ من الشاشة يُرسَل ويُقبل: +50 مسجّلًا × 5 = 250 (+12.5) */
        $registration = $project->items()->where('description', 'تسجيل الحضور')->firstOrFail();
        $this->actingAs($this->owner)->post(route('admin.changeOrders.store', $project->id), [
            'title' => 'خمسون مسجّلًا إضافيًّا', 'reason' => 'ارتفع عدد الحضور', 'requested_by_party' => 'customer', 'requested_by_name' => 'سالم',
            'items' => [['project_item_id' => $registration->id, 'quantity' => 50]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $co = ChangeOrder::where('project_id', $project->id)->sole();
        ChangeOrders::send($co, $this->owner);
        ChangeOrders::recordDecision($co->fresh(), ChangeOrder::ACCEPTED, ['decided_by_name' => 'سالم'], $this->owner);
        $this->assertEqualsWithDelta(9712.5, ProjectFinancials::summary($project->fresh())['current_value'], 0.0005);
        $this->assertSame(250, (int) $registration->fresh()->quantity);

        /* 5) الفعاليّة — تُجهَّز وتُبثّ وتُقفل (الجاهزيّةُ تُسأل ولا تُتجاوَز) */
        $project->fresh()->update(['status' => Project::IN_PROGRESS]);
        $op = EventOperations::create($project->fresh(), ['name' => 'يوم المؤتمر', 'venue_name' => 'مركز عُمان للمؤتمرات', 'setup_starts_at' => '2026-10-01 06:00', 'event_starts_at' => '2026-10-01 09:00',
            'event_ends_at' => '2026-10-01 17:00', 'teardown_starts_at' => '2026-10-01 17:00', 'teardown_ends_at' => '2026-10-01 19:00', 'operations_lead_id' => $this->manager->id], $this->owner);
        Schedule::add($op, ['title' => 'افتتاح', 'type' => 'live', 'starts_at' => '2026-10-01 09:00', 'ends_at' => '2026-10-01 10:00'], $this->owner);
        $lead = Crew::assign($op, ['user_id' => $this->manager->id, 'role' => 'operations_lead', 'briefing_status' => 'briefed'], $this->owner);
        foreach ($op->checklists as $list) {
            foreach ($list->items()->where('required', true)->get() as $item) {
                Checklists::complete($item, 'تمّ', $this->owner);
            }
        }
        $op = EventOperations::transition($op->fresh(), EventOperation::PREPARING, $this->owner);
        $this->actingAs($this->owner)->post(route('admin.operations.transition', $op->id), ['status' => EventOperation::READY])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(EventOperation::READY, $op->fresh()->status);
        $op = EventOperations::transition($op->fresh(), EventOperation::SETUP, $this->owner);
        Crew::checkIn($lead->fresh(), $this->manager);
        $this->actingAs($this->owner)->post(route('admin.operations.transition', $op->id), ['status' => EventOperation::LIVE])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(EventOperation::LIVE, $op->fresh()->status);
        $op = EventOperations::transition($op->fresh(), EventOperation::TEARDOWN, $this->owner);
        $this->actingAs($this->owner)->post(route('admin.operations.complete', $op->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(EventOperation::COMPLETED, $op->fresh()->status);

        /* 6) حزمةُ التسليم — تُبنى وتُرسَل ويقبلها العميل من رابطها */
        $this->actingAs($this->owner)->post(route('admin.deliveries.store'), ['title' => 'تسليم المؤتمر', 'project_id' => $project->id])->assertRedirect()->assertSessionHasNoErrors();
        $package = DeliveryPackage::where('project_id', $project->id)->sole();
        foreach ($project->fresh()->items as $item) {
            $this->post(route('admin.deliveries.items.store', $package->id), ['kind' => 'item', 'project_item_id' => $item->id, 'quantity' => $item->quantity])->assertSessionHasNoErrors();
        }
        $this->post(route('admin.deliveries.ready', $package->id))->assertSessionHasNoErrors();
        $this->post(route('admin.deliveries.send', $package->id))->assertSessionHasNoErrors();
        $dToken = PublicDocument::token($package->fresh());
        auth()->logout();
        $this->get("/d/$dToken")->assertOk();
        $this->post("/d/$dToken/decision", ['decision' => 'accepted', 'name' => 'سالم الحارثي', 'consent' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(DeliveryPackage::ACCEPTED, $package->fresh()->status);

        /* 7) الفاتورةُ تُصدَر بكامل المتعاقَد ويُقبض نصفُها */
        $this->actingAs($this->owner)->post(route('admin.customerInvoices.store'), [
            'customer_id' => $this->customer->id, 'project_id' => $project->id, 'payment_method' => 'آجل', 'issued_at' => '2026-09-15',
            'items' => [['description' => 'تنظيم المؤتمر', 'quantity' => 1, 'unit_price' => 8000], ['description' => 'تسجيل الحضور', 'quantity' => 250, 'unit_price' => 5]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $invoice = CustomerInvoice::where('project_id', $project->id)->sole();
        $this->post(route('admin.customerInvoices.issue', $invoice->id))->assertSessionHasNoErrors();
        $this->assertSame(CustomerInvoice::ISSUED, $invoice->fresh()->status);
        $this->assertEqualsWithDelta(9712.5, (float) $invoice->fresh()->total, 0.0005);
        $this->post(route('admin.customerPayments.store'), ['customer_id' => $this->customer->id, 'amount' => 4712.5, 'method' => 'نقدي', 'occurred_at' => '2026-09-16', 'allocations' => [$invoice->id => 4712.5]])
            ->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(4712.5, (float) CustomerPayment::where('business_id', $bid)->sum('amount'), 0.0005);
        $this->assertEqualsWithDelta(5000.0, $invoice->fresh()->outstanding(), 0.0005);

        /* 8) الدفترُ متوازن وكلُّ قيدٍ متوازن */
        $this->assertTrue(Ledger::trialBalance($bid)['balanced']);
        foreach (JournalEntry::where('business_id', $bid)->get() as $entry) {
            $this->assertEqualsWithDelta((float) JournalLine::where('journal_entry_id', $entry->id)->sum('debit'), (float) JournalLine::where('journal_entry_id', $entry->id)->sum('credit'), 0.0005, $entry->number);
        }
        $this->assertEqualsWithDelta(5000.0, Ledger::balance($bid, 'receivable'), 0.0005);
        $this->assertEqualsWithDelta(9250.0, Ledger::balance($bid, 'sales'), 0.0005);
        $this->assertEqualsWithDelta(462.5, Ledger::balance($bid, 'tax_payable'), 0.0005);

        /* 9) الربحيّةُ والإدارةُ والتقارير — الرقمُ نفسُه في كلّ باب */
        $p = ProjectProfitability::forProject($project->fresh());
        $this->assertEqualsWithDelta(9250.0, $p['revenue'], 0.0005);
        $this->assertEqualsWithDelta(4712.5, $p['collected'], 0.0005);
        $this->assertEqualsWithDelta(5000.0, $p['receivable'], 0.0005);
        $this->assertEqualsWithDelta(9250.0, $p['contribution'], 0.0005, 'لا تكلفةَ مباشرة بعد');
        $this->assertSame('partial', $p['completeness']['status'], 'مشروعٌ قائم بإيرادٍ ولا تكلفة — يُقال جزئيًّا لا هامشَ مئة');
        $this->assertContains(__('لا تكاليفَ مسجَّلة على المشروع بعد'), $p['completeness']['reasons']);

        foreach (['admin.management.index', 'admin.management.financial', 'admin.management.projects', 'admin.management.customers', 'admin.management.cash', 'admin.management.reports'] as $screen) {
            $this->actingAs($this->owner)->get(route($screen, ['period' => 'this_month']))->assertOk();
        }
        /* والمشروعُ بلا تاريخِ بدء (العرضُ لم يذكر موعدًا) — فيُقرأ في «كلّ المشاريع» */
        $this->actingAs($this->owner)->get(route('admin.management.projects', ['period' => 'all']))
            ->assertInertia(fn ($a) => $a->where('list.totals.revenue', 9250)->where('list.totals.collected', 4712.5));
        $csv = $this->actingAs($this->owner)->get(route('admin.reports.export.csv', ['report' => 'project_profitability', 'period' => 'all']));
        $csv->assertOk();
        $this->assertStringContainsString($project->code, $csv->streamedContent(), 'التقريرُ يحمل المشروع');
        $this->actingAs($this->owner)->get(route('admin.projects.show', $project->id))->assertOk()->assertInertia(fn ($a) => $a->where('performance.revenue', 9250));
        $this->actingAs($this->owner)->get(route('admin.command.index'))->assertOk();

        /* 10) الأثرُ: كلُّ خطوةٍ حرجة على السجلّ */
        foreach (['quotation', 'project', 'change_order', 'event_operation', 'delivery_package', 'customer_invoice', 'customer_payment'] as $subject) {
            $this->assertTrue(ActivityLog::where('business_id', $bid)->where('subject_type', $subject)->exists(), "لا أثرَ لـ$subject");
        }
        $this->assertSame(Period::resolve($bid, 'this_month')->timezone, 'Asia/Muscat');
    }
}
