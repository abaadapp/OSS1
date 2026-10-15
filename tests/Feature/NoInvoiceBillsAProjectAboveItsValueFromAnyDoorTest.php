<?php

namespace Tests\Feature;

use App\Models\CompanyPolicy;
use App\Models\CustomerInvoice;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\Setting;
use App\Support\CancellationWindow;
use App\Support\ChangeOrders;
use App\Support\CustomerInvoices;
use App\Support\Import\Provenance;
use App\Support\ProjectBilling;
use App\Support\ProjectFinancials;
use App\Support\Projects;
use App\Support\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\WalksTheProjectJourney;
use Tests\TestCase;

/**
 * لا فاتورةَ تُفوتِر مشروعًا فوق قيمته — من أيّ باب.
 *
 * مسارُ المشروع يحسب دفعتَه ونهائيّتَه من الحقيقة، لكنّ شاشةَ الفواتير
 * العامّة بابٌ آخر: يُكتب فيه أيُّ مبلغ ويُربط بأيّ مشروع. فالسقفُ في
 * الخادم — عند الإصدار (`CustomerInvoices::issue`) لكلّ الأبواب، وعند كتابة
 * المسودّة من الشاشة ومعها المسودّاتُ القائمة. والقيمةُ تزيد بأمر تغييرٍ
 * يقبله العميل، وإشعارُ الدائن يُعيد ما أخذته فاتورة. وما ليس فوترةً لنطاق
 * المشروع — رسمُ إلغائه والمستورَدُ من التاريخ — له اسمُه ولا يُقاس.
 */
class NoInvoiceBillsAProjectAboveItsValueFromAnyDoorTest extends TestCase
{
    use RefreshDatabase;
    use WalksTheProjectJourney;

    /** مشروعٌ بعشرة آلاف بلا ضريبة */
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('local');
        Mail::fake();
        $this->journeyCompany();
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);
        Vat::flush();
        $this->project = $this->acceptedProject(1000, 10);
    }

    /** فاتورةٌ من شاشة الفواتير العامّة — كما يرسلها نموذجُها */
    private function store(float $amount, ?int $projectId, bool $issue = true)
    {
        return $this->actingAs($this->owner)->post(route('admin.customerInvoices.store'), array_filter([
            'customer_id' => $this->customer->id,
            'project_id' => $projectId,
            'payment_method' => CustomerInvoices::CREDIT,
            'issue' => $issue ? '1' : '0',
            'items' => [['description' => 'تنظيم', 'quantity' => 1, 'unit_price' => $amount]],
        ], fn ($v) => $v !== null));
    }

    private function netInvoiced(Project $project): float
    {
        return (float) ProjectFinancials::summary($project->fresh())['net_invoiced'];
    }

    /* ─────────────────────────── شاشةُ الفواتير العامّة ─────────────────────────── */

    public function test_the_general_invoice_screen_refuses_an_invoice_above_what_is_left_of_the_project_value(): void
    {
        $this->store(4000, $this->project->id)->assertSessionHasNoErrors();
        $entries = JournalEntry::count();

        $this->store(6500, $this->project->id)->assertSessionHasErrors(['project_id']);
        $this->assertStringContainsString('تتجاوز ما بقي من قيمة المشروع', session('errors')->first('project_id'));
        $this->assertStringContainsString('6,000.000', session('errors')->first('project_id'));
        $this->assertSame(1, CustomerInvoice::count(), 'لا ورقةَ كُتبت — ولا مسودّة');
        $this->assertSame($entries, JournalEntry::count(), 'ولا قيدَ وقع');

        /* والباقي بعينه يُفوتَر — ثمّ لا فلسَ فوقه */
        $this->store(6000, $this->project->id)->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(10000.0, $this->netInvoiced($this->project), 0.0005);
        $this->store(0.5, $this->project->id)->assertSessionHasErrors(['project_id']);
        $this->assertSame(2, CustomerInvoice::count());
    }

    public function test_a_waiting_draft_counts_when_the_screen_writes_a_new_invoice(): void
    {
        $this->store(6000, $this->project->id, issue: false)->assertSessionHasNoErrors();

        /* مسودّةٌ بستّة آلاف مالٌ موعودٌ من القيمة نفسِها — فلا تُكتب فوقها خمسة */
        $this->store(5000, $this->project->id, issue: false)->assertSessionHasErrors(['project_id']);
        $this->store(4000, $this->project->id, issue: false)->assertSessionHasNoErrors();
        $this->assertSame(2, CustomerInvoice::where('status', CustomerInvoice::DRAFT)->count());
    }

    public function test_a_draft_cannot_be_issued_once_the_project_has_no_room_left_for_it(): void
    {
        $this->store(6000, $this->project->id, issue: false)->assertSessionHasNoErrors();
        $draft = CustomerInvoice::sole();

        /* بابٌ آخر أصدر خمسة آلاف على المشروع نفسِه — من الخدمة لا من الشاشة */
        $other = CustomerInvoices::create((int) $this->business->id, $this->customer, ['project_id' => $this->project->id],
            [['description' => 'مرحلة', 'quantity' => 1, 'unit_price' => 5000]], $this->owner->id);
        CustomerInvoices::issue($other->fresh(), $this->owner->id);
        $entries = JournalEntry::count();

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.issue', $draft->id))->assertSessionHasErrors(['invoice']);
        $fresh = $draft->fresh();
        $this->assertSame(CustomerInvoice::DRAFT, $fresh->status);
        $this->assertNull($fresh->number, 'لا رقمَ قُطع لورقةٍ لم تصدر');
        $this->assertSame($entries, JournalEntry::count());
        $this->assertEqualsWithDelta(5000.0, $this->netInvoiced($this->project), 0.0005);
    }

    public function test_the_cap_lives_in_the_domain_not_in_a_screen(): void
    {
        $this->store(10000, $this->project->id)->assertSessionHasNoErrors();

        $invoice = CustomerInvoices::create((int) $this->business->id, $this->customer, ['project_id' => $this->project->id],
            [['description' => 'خدمةٌ إضافيّة', 'quantity' => 1, 'unit_price' => 250]], $this->owner->id);

        try {
            CustomerInvoices::issue($invoice->fresh(), $this->owner->id);
            $this->fail('أُصدرت فاتورةٌ فوق قيمة المشروع من الخدمة نفسِها');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('بأمر تغيير يقبله العميل', $e->getMessage());
        }

        $this->assertSame(CustomerInvoice::DRAFT, $invoice->fresh()->status);
        $this->assertEqualsWithDelta(10000.0, $this->netInvoiced($this->project), 0.0005);
    }

    /* ─────────────────────────── ما يحرّك السقف من بابه ─────────────────────────── */

    public function test_an_accepted_change_order_is_the_door_to_bill_more(): void
    {
        $this->store(10000, $this->project->id)->assertSessionHasNoErrors();
        $this->store(2000, $this->project->id)->assertSessionHasErrors(['project_id']);

        $order = ChangeOrders::create($this->project->fresh(), ['title' => 'يومان إضافيّان', 'requested_by_party' => 'customer'],
            [['project_item_id' => $this->project->items()->first()->id, 'quantity' => 2]], [], $this->owner);
        ChangeOrders::send($order, $this->owner);
        ChangeOrders::recordDecision($order->fresh(), 'accepted', ['decided_by_name' => 'سالم'], $this->owner);

        $this->store(2000, $this->project->id)->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(12000.0, $this->netInvoiced($this->project), 0.0005);
    }

    public function test_a_credit_note_gives_the_room_back(): void
    {
        $this->store(10000, $this->project->id)->assertSessionHasNoErrors();
        CustomerInvoices::creditNote(CustomerInvoice::sole(), 1000, 0, 'تصحيح', $this->owner->id);

        $this->store(1000, $this->project->id)->assertSessionHasNoErrors();
        $this->store(1, $this->project->id)->assertSessionHasErrors(['project_id']);
        $this->assertEqualsWithDelta(10000.0, $this->netInvoiced($this->project), 0.0005);
    }

    /* ─────────────────────────── ما لا يُقاس — بأسمائه ─────────────────────────── */

    public function test_an_invoice_without_a_project_is_not_capped(): void
    {
        $this->store(999999, null)->assertSessionHasNoErrors();

        $this->assertSame(CustomerInvoice::ISSUED, CustomerInvoice::sole()->status);
        $this->assertEqualsWithDelta(999999.0, (float) CustomerInvoice::sole()->total, 0.0005);
    }

    public function test_the_cancellation_fee_and_historical_imports_are_named_doors_outside_the_cap(): void
    {
        $this->store(10000, $this->project->id)->assertSessionHasNoErrors();

        /* رسمُ الإلغاء ذمّةٌ يُنشئها الإلغاءُ وفق سياسته — والقيمةُ مفوتَرةٌ كلُّها */
        CompanyPolicy::create([
            'business_id' => $this->business->id, 'key' => CancellationWindow::KEY,
            'params' => ['days' => 60, 'fee_percent' => 25], 'text_ar' => 'سياسة الإلغاء', 'active' => true,
        ]);
        $cancelled = Projects::cancel($this->project->fresh(), 'ألغى العميل', $this->owner);
        $fee = CustomerInvoice::findOrFail($cancelled->cancellation_fee_invoice_id);
        $this->assertSame(CustomerInvoice::ISSUED, $fee->status);
        $this->assertSame(ProjectBilling::CANCELLATION_FEE, $fee->billing_role);
        $this->assertEqualsWithDelta(2500.0, (float) $fee->total, 0.0005);

        /* والمستورَدُ من التاريخ يُكتب كما وقع — بالباب الذي يكتبه به الاستيراد */
        $old = $this->acceptedProject(100, 1);
        $imported = CustomerInvoices::create((int) $this->business->id, $this->customer, ['project_id' => $old->id],
            [['description' => 'فاتورة قديمة', 'quantity' => 1, 'unit_price' => 500]], $this->owner->id);
        $imported->forceFill(['origin' => Provenance::HISTORICAL])->save();
        $this->assertSame(CustomerInvoice::ISSUED, CustomerInvoices::issue($imported->fresh(), $this->owner->id)->status);
    }
}
