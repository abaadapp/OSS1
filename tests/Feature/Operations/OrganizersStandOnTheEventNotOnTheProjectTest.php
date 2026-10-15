<?php

namespace Tests\Feature\Operations;

use App\Models\ActivityLog;
use App\Models\OperationOrganizer;
use App\Models\Organizer;
use App\Models\OrganizerCategory;
use App\Models\User;
use App\Support\Operations\EventOperations;
use App\Support\Operations\OperationOrganizers;
use App\Support\Operations\ResourceConflicts;
use App\Support\Organizers;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use RuntimeException;

/**
 * المنظّمون يقفون على الفعاليّة لا على المشروع — المرحلة 14.
 *
 * سجلٌّ مستقلٌّ بجنسٍ من قائمةٍ مغلقة وتصنيفاتٍ كثيرة، ويُعيَّن المنظّمُ على
 * الفعاليّة بتصنيفه ووقته وحاله، ومسؤولُ المنظّمين موظّفٌ من الشركة. والتعارضُ
 * بسياسة الفريق نفسِها: التداخلُ يُردّ، والفجوةُ الضيّقة تحذير. والكتابةُ
 * بالاسم (`organizers.manage` للسجلّ و`crew.manage` للتعيين)، والعزلُ بالشركة.
 */
class OrganizersStandOnTheEventNotOnTheProjectTest extends OperationsTestCase
{
    use RefreshDatabase;

    private function category(string $name): OrganizerCategory
    {
        return Organizers::saveCategory((int) $this->business->id, $name);
    }

    private function organizer(string $name, string $gender = Organizer::MALE, array $categories = [], array $over = []): Organizer
    {
        return Organizers::create((int) $this->business->id, $over + ['name' => $name, 'gender' => $gender, 'phone' => '9'.random_int(1000000, 9999999),
            'category_ids' => array_map(fn ($c) => $c->id, $categories)], $this->owner);
    }

    /* ─────────────────────────── السجلّ ─────────────────────────── */

    public function test_male_and_female_organizers_carry_many_categories(): void
    {
        $reception = $this->category('استقبال');
        $vip = $this->category('VIP');
        $protocol = $this->category('بروتوكول');

        $ali = $this->organizer('علي', Organizer::MALE, [$reception, $vip]);
        $mona = $this->organizer('منى', Organizer::FEMALE, [$reception, $protocol, $vip]);

        $this->assertSame(Organizer::MALE, $ali->gender);
        $this->assertSame(Organizer::FEMALE, $mona->gender);
        $this->assertEqualsCanonicalizing(['استقبال', 'VIP'], $ali->categories->pluck('name')->all());
        $this->assertCount(3, $mona->categories);
        $this->assertSame(2, $reception->organizers()->count(), 'التصنيفُ الواحد لمنظّمين كثيرين');
        $this->assertTrue(ActivityLog::where('description', 'أضاف المنظّم منى')->exists());
    }

    public function test_gender_is_a_closed_list_not_free_text(): void
    {
        $this->actingAs($this->owner)->post(route('admin.organizers.store'), ['name' => 'سالم', 'gender' => 'ذكر'])
            ->assertSessionHasErrors('gender');
        $this->actingAs($this->owner)->post(route('admin.organizers.store'), ['name' => 'سالم'])->assertSessionHasErrors('gender');

        $this->expectException(RuntimeException::class);
        Organizers::create((int) $this->business->id, ['name' => 'سالم', 'gender' => 'other'], $this->owner);
    }

    public function test_the_registry_is_searched_by_name_and_phone_and_filtered_by_gender_category_and_status(): void
    {
        $gates = $this->category('تنظيم بوابات');
        $this->organizer('علي البلوشي', Organizer::MALE, [$gates], ['phone' => '91110000']);
        $this->organizer('منى الحارثية', Organizer::FEMALE, [], ['phone' => '92220000']);
        $off = $this->organizer('خالد', Organizer::MALE);
        Organizers::setStatus($off, Organizer::SUSPENDED, $this->owner);

        $names = fn (array $q) => collect($this->actingAs($this->owner)->get(route('admin.organizers.index', $q))->assertOk()
            ->viewData('page')['props']['organizers'])->pluck('name')->all();

        $this->assertSame(['علي البلوشي'], $names(['q' => 'البلوشي']));
        $this->assertSame(['منى الحارثية'], $names(['q' => '9222']));
        $this->assertSame(['منى الحارثية'], $names(['gender' => 'female']));
        $this->assertSame(['علي البلوشي'], $names(['category' => $gates->id]));
        $this->assertSame(['خالد'], $names(['status' => 'suspended']));
    }

    public function test_a_status_change_is_written_in_the_activity_log(): void
    {
        $o = $this->organizer('علي');
        $this->actingAs($this->owner)->post(route('admin.organizers.status', $o->id), ['status' => 'unavailable'])->assertSessionHasNoErrors();

        $this->assertSame(Organizer::UNAVAILABLE, $o->fresh()->status);
        $this->assertTrue(ActivityLog::where('description', 'like', 'غيّر حال المنظّم علي من «متاح» إلى «غير متاح»%')->exists());
    }

    /* ─────────────────────────── على الفعاليّة ─────────────────────────── */

    public function test_an_organizer_is_assigned_to_the_event_with_a_category_inside_its_window(): void
    {
        $op = $this->operation();
        $reception = $this->category('استقبال');
        $ali = $this->organizer('علي', Organizer::MALE, [$reception]);

        $this->actingAs($this->owner)->post(route('admin.operations.organizers.store', $op->id), [
            'organizer_id' => $ali->id, 'organizer_category_id' => $reception->id,
        ])->assertSessionHasNoErrors();

        $a = OperationOrganizer::sole();
        $this->assertSame($op->id, $a->event_operation_id);
        $this->assertSame($reception->id, $a->organizer_category_id);
        $this->assertSame('2026-10-01 06:00', $a->starts_at->format('Y-m-d H:i'), 'نافذةُ الفعاليّة ما لم يُكتب غيرُها');
        $this->assertSame('2026-10-01 19:00', $a->ends_at->format('Y-m-d H:i'));
        $this->assertSame(OperationOrganizer::ASSIGNED, $a->status);
        $this->assertTrue(ActivityLog::where('description', 'like', 'عيّن المنظّم علي («استقبال») في الفعاليّة%')->exists());

        $this->actingAs($this->owner)->put(route('admin.operations.organizers.update', [$op->id, $a->id]), ['status' => 'confirmed'])->assertSessionHasNoErrors();
        $this->assertSame(OperationOrganizer::CONFIRMED, $a->fresh()->status);

        $this->actingAs($this->owner)->get(route('admin.operations.show', $op->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('organizers.0.name', 'علي')->where('organizers.0.category', 'استقبال')
                ->where('organizer_options.0.name', 'علي'));
    }

    public function test_an_overlapping_assignment_is_refused_and_a_tight_gap_is_a_warning(): void
    {
        $morning = $this->operation(['name' => 'الصباح', 'setup_starts_at' => null, 'teardown_starts_at' => null, 'teardown_ends_at' => null,
            'event_starts_at' => '2026-10-01 09:00', 'event_ends_at' => '2026-10-01 12:00']);
        $noon = $this->operation(['name' => 'الظهيرة', 'setup_starts_at' => null, 'teardown_starts_at' => null, 'teardown_ends_at' => null,
            'event_starts_at' => '2026-10-01 11:00', 'event_ends_at' => '2026-10-01 14:00']);
        $evening = $this->operation(['name' => 'المساء', 'setup_starts_at' => null, 'teardown_starts_at' => null, 'teardown_ends_at' => null,
            'event_starts_at' => '2026-10-01 12:30', 'event_ends_at' => '2026-10-01 15:00']);
        $ali = $this->organizer('علي');

        OperationOrganizers::assign($morning, ['organizer_id' => $ali->id], $this->owner);

        $this->actingAs($this->owner)->post(route('admin.operations.organizers.store', $noon->id), ['organizer_id' => $ali->id])
            ->assertSessionHasErrors(['organizers' => 'المنظّم علي معيَّن في الفعاليّة '.$morning->code.' من 2026-10-01 09:00 إلى 2026-10-01 12:00 — لا يُعيَّن في وقتين معًا.']);
        $this->assertSame(0, OperationOrganizer::where('event_operation_id', $noon->id)->count());

        /* المساءُ بعد نصف ساعة — يُعيَّن، والفجوةُ الضيّقة تحذيرٌ لا مانع */
        OperationOrganizers::assign($evening, ['organizer_id' => $ali->id], $this->owner);
        $kinds = collect(ResourceConflicts::all($evening->fresh()))->keyBy('kind');
        $this->assertSame(ResourceConflicts::WARNING, $kinds['organizer_tight_gap']['severity']);
        $this->assertArrayNotHasKey('organizer_double_booked', $kinds->all());
    }

    public function test_a_suspended_or_unavailable_organizer_is_not_assigned(): void
    {
        $op = $this->operation();
        $off = $this->organizer('خالد');
        Organizers::setStatus($off, Organizer::SUSPENDED, $this->owner);

        $this->actingAs($this->owner)->post(route('admin.operations.organizers.store', $op->id), ['organizer_id' => $off->id])
            ->assertSessionHasErrors('organizers');
        $this->assertSame(0, OperationOrganizer::count());
    }

    public function test_the_organizers_lead_is_an_employee_of_this_company(): void
    {
        $op = $this->operation();

        $this->actingAs($this->owner)->post(route('admin.operations.organizersLead', $op->id), ['user_id' => $this->field->id])->assertSessionHasNoErrors();
        $this->assertSame($this->field->id, $op->fresh()->organizers_lead_user_id);
        $this->assertSame($this->manager->id, $op->fresh()->operations_lead_id, 'غيرُ قائد العمليّات — حقلٌ مستقلّ');
        $this->assertTrue(ActivityLog::where('description', 'like', 'عيّن المنفّذ مسؤولًا عن المنظّمين%')->exists());

        $this->actingAs($this->owner)->post(route('admin.operations.organizersLead', $op->id), ['user_id' => $this->stranger->id])
            ->assertSessionHasErrors('organizers');
        $this->assertSame($this->field->id, $op->fresh()->organizers_lead_user_id);
    }

    public function test_cancelling_the_event_cancels_its_pending_organizers(): void
    {
        $op = $this->operation();
        $a = OperationOrganizers::assign($op, ['organizer_id' => $this->organizer('علي')->id], $this->owner);

        EventOperations::cancel($op->fresh(), 'أُلغي المؤتمر', $this->owner);

        $this->assertSame(OperationOrganizer::CANCELLED, $a->fresh()->status);
    }

    public function test_the_organizer_page_shows_upcoming_events_and_history(): void
    {
        $ali = $this->organizer('علي');
        $op = $this->operation();
        OperationOrganizers::assign($op, ['organizer_id' => $ali->id], $this->owner);

        $this->actingAs($this->owner)->get(route('admin.organizers.show', $ali->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Admin/Organizers/Show')
                ->where('upcoming.0.operation.code', $op->code)->where('history', []));
    }

    /* ─────────────────────────── الأبواب ─────────────────────────── */

    public function test_who_reads_the_registry_and_who_writes_it(): void
    {
        $ali = $this->organizer('علي');
        $reader = User::create(['business_id' => $this->business->id, 'name' => 'قارئ', 'email' => 'reader@hadath.om', 'password' => bcrypt('password'),
            'role' => 'delivery', 'status' => 'نشط', 'permissions' => ['dashboard', 'organizers']]);

        $this->actingAs($this->seller)->get(route('admin.organizers.index'))->assertForbidden();
        $this->actingAs($reader)->get(route('admin.organizers.index'))->assertOk();
        $this->actingAs($reader)->post(route('admin.organizers.store'), ['name' => 'س', 'gender' => 'male'])->assertForbidden();
        $this->actingAs($this->manager)->post(route('admin.organizers.store'), ['name' => 'س', 'gender' => 'male'])->assertSessionHasNoErrors();
        $this->assertContains(Permissions::ORGANIZERS_MANAGE, Permissions::actions());

        /* والتعيينُ على الفعاليّة بفعل الفريق — المنفّذُ بلا `crew.manage` لا يعيّن */
        $op = $this->operation();
        $this->actingAs($this->field)->post(route('admin.operations.organizers.store', $op->id), ['organizer_id' => $ali->id])->assertForbidden();
    }

    public function test_another_company_neither_reads_nor_assigns_these_organizers(): void
    {
        $ali = $this->organizer('علي');
        $op = $this->operation();

        $this->actingAs($this->stranger)->get(route('admin.organizers.show', $ali->id))->assertNotFound();
        $this->actingAs($this->stranger)->put(route('admin.organizers.update', $ali->id), ['name' => 'x', 'gender' => 'male'])->assertNotFound();
        $this->actingAs($this->stranger)->post(route('admin.operations.organizers.store', $op->id), ['organizer_id' => $ali->id])->assertNotFound();

        $foreign = Organizers::create((int) $this->other->id, ['name' => 'غريب', 'gender' => 'male'], $this->stranger);
        $this->actingAs($this->owner)->post(route('admin.operations.organizers.store', $op->id), ['organizer_id' => $foreign->id])
            ->assertSessionHasErrors(['organizers' => 'هذا المنظّم ليس من سجلّ شركتك.']);
        $this->assertSame(0, OperationOrganizer::count());
        $this->assertSame('علي', $ali->fresh()->name);
    }
}
