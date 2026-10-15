<?php

namespace Tests\Feature\V1\Concurrency;

use App\Models\CustomerContact;
use App\Models\EventOperation;
use App\Models\OperationOrganizer;
use App\Models\Organizer;
use App\Models\Project;
use App\Support\CustomerContacts;
use App\Support\Operations\EventOperations;
use App\Support\Operations\OperationOrganizers;
use App\Support\Organizers;
use Illuminate\Support\Facades\Auth;

/**
 * المنظّمون وأشخاصُ الاتصال تحت التزامن — على PostgreSQL (المرحلة 14).
 *
 * منظّمٌ يُطلب لفعاليّتين متداخلتين من نافذتين معًا فيُعيَّن في واحدة (قفلُ صفّ
 * الشركة كما للفريق) · ونافذتان تجعلان شخصين مختلفين أساسيَّين معًا فيبقى
 * أساسيٌّ واحد (قفلُ صفّ العميل والفهرسُ الجزئيّ الفريد) · وتعطيلُ الأساسيّ
 * ونافذةٌ تختار غيرَه معًا يتركان أساسيًّا واحدًا نشطًا.
 */
class OrganizersAndContactsHoldUnderContentionTest extends RaceTestCase
{
    public function test_one_organizer_is_not_assigned_to_two_overlapping_events_at_once(): void
    {
        $b = $this->company();
        $owner = $this->user($b);
        $customer = $this->customer($b);
        Auth::login($owner);
        $project = Project::create(['business_id' => $b->id, 'customer_id' => $customer->id, 'code' => 'EP-O1', 'name' => 'مؤتمر', 'status' => Project::CONFIRMED,
            'starts_at' => '2026-10-01', 'ends_at' => '2026-10-02', 'owner_id' => $owner->id, 'approved_value' => 1000]);
        $ops = [];
        foreach (['أ', 'ب'] as $name) {
            $ops[] = EventOperations::create($project, ['name' => $name, 'venue_name' => 'قاعة', 'event_starts_at' => '2026-10-01 09:00',
                'event_ends_at' => '2026-10-01 17:00', 'operations_lead_id' => $owner->id], $owner)->id;
        }
        $organizer = Organizers::create((int) $b->id, ['name' => 'علي', 'gender' => Organizer::MALE], $owner);
        Auth::logout();

        $assign = fn (int $opId) => function () use ($opId, $organizer, $owner) {
            Auth::login($owner);

            return OperationOrganizers::assign(EventOperation::findOrFail($opId), ['organizer_id' => $organizer->id], $owner)->id;
        };

        $results = $this->race([$assign($ops[0]), $assign($ops[1])]);

        $this->assertSame(1, $this->successes($results), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, OperationOrganizer::where('organizer_id', $organizer->id)->whereIn('status', OperationOrganizer::EXPECTED)->count());
    }

    public function test_two_windows_choosing_different_primaries_leave_exactly_one(): void
    {
        $b = $this->company();
        $owner = $this->user($b);
        $customer = $this->customer($b);
        $customer->update(['customer_type' => 'جهة حكومية']);
        Auth::login($owner);
        $first = CustomerContacts::save($customer, ['name' => 'محمد'], $owner);
        $second = CustomerContacts::save($customer, ['name' => 'سارة'], $owner);
        $third = CustomerContacts::save($customer, ['name' => 'خالد'], $owner);
        Auth::logout();

        $primary = fn (int $id) => function () use ($id, $owner) {
            Auth::login($owner);

            return CustomerContacts::setPrimary(CustomerContact::findOrFail($id), $owner)->id;
        };

        $results = $this->race([$primary($second->id), $primary($third->id), $primary($first->id)]);

        $this->assertSame(1, CustomerContact::where('customer_id', $customer->id)->where('is_primary', true)->count(), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertGreaterThanOrEqual(1, $this->successes($results));
    }

    /** تعطيلُ الأساسيّ (فيُسلّمها لخليفته) ونافذةٌ تختار أساسيًّا آخر معًا — أساسيٌّ واحدٌ نشط */
    public function test_deactivating_the_primary_while_another_window_picks_one_leaves_exactly_one(): void
    {
        $b = $this->company();
        $owner = $this->user($b);
        $customer = $this->customer($b);
        $customer->update(['customer_type' => 'شركة']);
        Auth::login($owner);
        $first = CustomerContacts::save($customer, ['name' => 'أحمد'], $owner);
        CustomerContacts::save($customer, ['name' => 'محمد'], $owner);
        $third = CustomerContacts::save($customer, ['name' => 'سارة'], $owner);
        Auth::logout();

        $results = $this->race([
            function () use ($first, $owner) {
                Auth::login($owner);

                return CustomerContacts::setActive(CustomerContact::findOrFail($first->id), false, $owner)->id;
            },
            function () use ($third, $owner) {
                Auth::login($owner);

                return CustomerContacts::setPrimary(CustomerContact::findOrFail($third->id), $owner)->id;
            },
        ]);

        $this->assertSame(2, $this->successes($results), json_encode($results, JSON_UNESCAPED_UNICODE));
        $primaries = CustomerContact::where('customer_id', $customer->id)->where('is_primary', true)->get();
        $this->assertCount(1, $primaries);
        $this->assertTrue((bool) $primaries->first()->active, 'الأساسيُّ نشطٌ دائمًا');
        $this->assertFalse((bool) $first->fresh()->active);
    }
}
