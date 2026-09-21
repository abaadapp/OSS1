<?php

namespace Tests\Feature\V1\Concurrency;

use App\Models\AssetReservation;
use App\Models\CustomerInvoice;
use App\Models\DeliveryPackage;
use App\Models\DocumentApproval;
use App\Models\OperationConsumableIssue;
use App\Models\OperationCrewAssignment;
use App\Models\Product;
use App\Models\Project;
use App\Models\Quotation;
use App\Support\CustomerInvoices;
use App\Support\DeliveryPackages;
use App\Support\Operations\Assets;
use App\Support\Operations\Consumables;
use App\Support\Operations\Crew;
use App\Support\Operations\EventOperations;
use App\Support\Operations\Reservations;
use App\Support\Quotations;
use Illuminate\Support\Facades\Auth;

/**
 * الكتاباتُ الحرجة تقع مرّةً واحدة تحت التزامن — على PostgreSQL.
 *
 * عرضٌ يُقبل من ثلاث نوافذ معًا فيصير مشروعًا واحدًا · قطعةٌ تُحجز لمدّتين
 * متداخلتين فتُحجز مرّة · موظّفٌ يُعيَّن في فعاليّتين متزامنتين فيُعيَّن في
 * واحدة · فواتيرُ تُصدَر معًا فلا تتقاسم رقمًا · مستهلَكٌ يُصرف من رفٍّ لا
 * يكفي الجميعَ فلا ينزل الرصيدُ تحت الصفر · وحزمةٌ تُقبل من رابطها مرّتين
 * معًا فتُقبل مرّة.
 */
class CriticalWritesHappenOnceUnderContentionTest extends RaceTestCase
{
    public function test_a_quotation_accepted_from_three_windows_becomes_one_project(): void
    {
        $b = $this->company();
        $owner = $this->user($b);
        $customer = $this->customer($b);
        Auth::login($owner);
        $q = Quotations::create((int) $b->id, $customer, ['title' => 'مؤتمر', 'issued_at' => now()->toDateString()],
            [['description' => 'تنظيم', 'quantity' => 1, 'unit_price' => 1000]], $owner);
        Quotations::send($q, $owner);
        Auth::logout();
        $qid = $q->id;

        $signer = ['name' => 'سالم', 'consent' => 'أوافق'];
        $public = fn () => function () use ($qid, $signer) {
            return Quotations::decide(Quotation::findOrFail($qid), 'accepted', $signer, ['ip' => '1.1.1.1'])->status;
        };
        $staff = function () use ($qid, $owner) {
            Auth::login($owner);

            return Quotations::recordDecision(Quotation::findOrFail($qid), 'accepted', ['decided_by_name' => 'سالم'], $owner)->status;
        };

        $results = $this->race([$public(), $public(), $staff]);

        $this->assertSame(1, $this->successes($results), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, Project::where('quotation_id', $qid)->count(), 'مشروعٌ واحد');
        $this->assertLessThanOrEqual(1, DocumentApproval::where('approvable_id', $qid)->where('decision', 'accepted')->count());
        $this->assertSame(Quotation::ACCEPTED, Quotation::find($qid)->status);
    }

    public function test_one_asset_is_reserved_once_for_overlapping_windows(): void
    {
        $b = $this->company();
        $owner = $this->user($b);
        $customer = $this->customer($b);
        Auth::login($owner);
        $project = Project::create(['business_id' => $b->id, 'customer_id' => $customer->id, 'code' => 'EP-R1', 'name' => 'مشروع', 'status' => Project::CONFIRMED,
            'starts_at' => '2026-10-01', 'ends_at' => '2026-10-02', 'owner_id' => $owner->id, 'approved_value' => 1000]);
        $ops = [];
        foreach (['اليوم الأوّل', 'اليوم الثاني'] as $name) {
            $ops[] = EventOperations::create($project, ['name' => $name, 'venue_name' => 'قاعة', 'setup_starts_at' => '2026-10-01 06:00', 'event_starts_at' => '2026-10-01 09:00',
                'event_ends_at' => '2026-10-01 17:00', 'teardown_starts_at' => '2026-10-01 17:00', 'teardown_ends_at' => '2026-10-01 19:00', 'operations_lead_id' => $owner->id], $owner);
        }
        $asset = Assets::create((int) $b->id, ['name' => 'حاسوب', 'category' => 'laptop'], $owner);
        Auth::logout();
        [$a, $c] = [$ops[0]->id, $ops[1]->id];
        $assetId = $asset->id;

        $reserve = fn (int $opId) => function () use ($opId, $assetId, $owner) {
            Auth::login($owner);

            return Reservations::reserve(\App\Models\EventOperation::findOrFail($opId), \App\Models\Asset::findOrFail($assetId), [], $owner)->id;
        };

        $results = $this->race([$reserve($a), $reserve($c), $reserve($a)]);

        $this->assertSame(1, $this->successes($results), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, AssetReservation::where('asset_id', $assetId)->where('status', '!=', AssetReservation::CANCELLED)->count());
    }

    public function test_a_crew_member_is_not_assigned_to_two_simultaneous_operations(): void
    {
        $b = $this->company();
        $owner = $this->user($b);
        $tech = $this->user($b, 'delivery', 'فنّي');
        $customer = $this->customer($b);
        Auth::login($owner);
        $project = Project::create(['business_id' => $b->id, 'customer_id' => $customer->id, 'code' => 'EP-C1', 'name' => 'مشروع', 'status' => Project::CONFIRMED,
            'starts_at' => '2026-10-01', 'ends_at' => '2026-10-02', 'owner_id' => $owner->id, 'approved_value' => 1000]);
        $ids = [];
        foreach (['أ', 'ب', 'ج'] as $name) {
            $ids[] = EventOperations::create($project, ['name' => $name, 'venue_name' => 'قاعة', 'event_starts_at' => '2026-10-01 09:00', 'event_ends_at' => '2026-10-01 17:00', 'operations_lead_id' => $owner->id], $owner)->id;
        }
        Auth::logout();

        $assign = fn (int $opId) => function () use ($opId, $tech, $owner) {
            Auth::login($owner);

            return Crew::assign(\App\Models\EventOperation::findOrFail($opId), ['user_id' => $tech->id, 'role' => 'technical_support'], $owner)->id;
        };

        $results = $this->race(array_map($assign, $ids));

        $this->assertSame(1, $this->successes($results), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, OperationCrewAssignment::where('user_id', $tech->id)->count());
    }

    public function test_invoices_issued_together_never_share_a_number(): void
    {
        $b = $this->company();
        $owner = $this->user($b);
        $customer = $this->customer($b);
        $bid = (int) $b->id;

        $worker = fn () => function () use ($bid, $customer, $owner) {
            Auth::login($owner);
            $numbers = [];
            for ($i = 0; $i < 3; $i++) {
                $draft = CustomerInvoices::create($bid, $customer, ['issued_at' => now()->toDateString()], [['description' => 'تنظيم', 'quantity' => 1, 'unit_price' => 100]], $owner->id);
                $numbers[] = CustomerInvoices::issue($draft->fresh(), $owner->id)->number;
            }

            return $numbers;
        };

        $results = $this->race([$worker(), $worker(), $worker(), $worker()]);

        $this->assertSame(4, $this->successes($results), json_encode(array_column($results, 'error'), JSON_UNESCAPED_UNICODE));
        $numbers = CustomerInvoice::where('business_id', $bid)->where('status', CustomerInvoice::ISSUED)->pluck('number');
        $this->assertCount(12, $numbers);
        $this->assertCount(12, $numbers->unique());
    }

    public function test_consumables_never_drive_stock_below_zero_and_a_shared_key_issues_once(): void
    {
        $b = $this->company();
        $owner = $this->user($b);
        $customer = $this->customer($b);
        Auth::login($owner);
        $project = Project::create(['business_id' => $b->id, 'customer_id' => $customer->id, 'code' => 'EP-S1', 'name' => 'مشروع', 'status' => Project::CONFIRMED,
            'starts_at' => '2026-10-01', 'ends_at' => '2026-10-02', 'owner_id' => $owner->id, 'approved_value' => 1000]);
        $op = EventOperations::create($project, ['name' => 'اليوم', 'venue_name' => 'قاعة', 'event_starts_at' => '2026-10-01 09:00', 'event_ends_at' => '2026-10-01 17:00', 'operations_lead_id' => $owner->id], $owner);
        $product = Product::create(['business_id' => $b->id, 'name' => 'بطاقة', 'price' => 2, 'cost' => 1, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'sku' => 'C-1']);
        Auth::logout();
        [$opId, $pid] = [$op->id, $product->id];

        $issue = fn (string $key) => function () use ($opId, $pid, $owner, $key) {
            Auth::login($owner);

            return Consumables::issue(\App\Models\EventOperation::findOrFail($opId), Product::findOrFail($pid), ['quantity' => 3, 'key' => $key], $owner)->id;
        };

        /* ثلاثةٌ يطلبون 3 من 5 — واحدٌ يمرّ */
        $results = $this->race([$issue('k1'), $issue('k2'), $issue('k3')]);
        $this->assertSame(1, $this->successes($results), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(2, (int) Product::find($pid)->quantity);
        $this->assertSame(1, OperationConsumableIssue::where('product_id', $pid)->count());

        /* والمفتاحُ نفسُه من ثلاث نوافذ — صرفٌ واحد (والرفُّ يُملأ من بابه لا بكتابةٍ مباشرة) */
        $branchId = (int) \App\Models\Branch::where('business_id', $b->id)->value('id');
        \Illuminate\Support\Facades\DB::transaction(fn () => \App\Support\StockLedger::move((int) $b->id, $branchId, [$pid => 48], 'اختبار'));
        $results = $this->race([$issue('same'), $issue('same'), $issue('same')]);
        $this->assertSame(3, $this->successes($results), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertCount(1, collect($results)->pluck('value')->unique(), 'الثلاثةُ عادوا بالصرف نفسِه');
        $this->assertSame(47, (int) Product::find($pid)->quantity, 'حركةٌ واحدة');
    }

    public function test_a_delivery_package_is_accepted_once_from_its_link(): void
    {
        $b = $this->company();
        $owner = $this->user($b);
        $customer = $this->customer($b);
        Auth::login($owner);
        $q = Quotations::create((int) $b->id, $customer, ['title' => 'مؤتمر', 'issued_at' => now()->toDateString()],
            [['description' => 'تنظيم', 'quantity' => 2, 'unit_price' => 500]], $owner);
        Quotations::send($q, $owner);
        Quotations::recordDecision($q->fresh(), 'accepted', ['decided_by_name' => 'سالم'], $owner);
        $project = Project::where('quotation_id', $q->id)->firstOrFail();
        $package = DeliveryPackages::create((int) $b->id, null, ['project_id' => $project->id], $owner);
        DeliveryPackages::addItem($package, ['kind' => 'item', 'project_item_id' => $project->items()->first()->id, 'quantity' => 2], null, $owner);
        $package = DeliveryPackages::send(DeliveryPackages::ready($package->fresh(), $owner), $owner);
        Auth::logout();
        $pid = $package->id;

        $accept = fn () => fn () => DeliveryPackages::decide(DeliveryPackage::findOrFail($pid), 'accepted', ['name' => 'سالم', 'consent' => 'أوافق'], ['ip' => '1.1.1.1'])->status;

        $results = $this->race([$accept(), $accept(), $accept()]);

        $this->assertSame(1, $this->successes($results), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, DocumentApproval::where('approvable_id', $pid)->where('approvable_type', (new DeliveryPackage)->getMorphClass())->count());
    }
}
