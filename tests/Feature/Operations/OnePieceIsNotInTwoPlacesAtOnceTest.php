<?php

namespace Tests\Feature\Operations;

use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\AssetReservation;
use App\Support\Operations\Assets;
use App\Support\Operations\EventOperations;
use App\Support\Operations\Requirements;
use App\Support\Operations\Reservations;
use App\Support\Operations\ResourceConflicts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * القطعةُ الواحدة لا تكون في مكانين — المرحلة 10: المعدّاتُ وحجزُها.
 *
 * رمزٌ تسلسليّ ورقمٌ فريد · الحالُ تُشتقّ من الحجوز · التداخلُ يُردّ والمتجاورُ
 * يمرّ · خارجُ الخدمة لا يُحجز · التسليمُ والردُّ آمنان عند التكرار · التالفةُ
 * والمفقودةُ تُوسمان · وما خرج لا يصير متاحًا بمرور الوقت · والجارُ لا يحجز
 * قطعتَنا.
 */
class OnePieceIsNotInTwoPlacesAtOnceTest extends OperationsTestCase
{
    use RefreshDatabase;

    /* ─────────────────────────── السجلّ ─────────────────────────── */

    public function test_an_asset_gets_a_sequential_code_and_a_unique_serial(): void
    {
        $a = $this->asset('حاسوب ١', 'laptop', ['serial_number' => 'SN-1', 'brand' => 'Dell', 'purchase_cost' => '450.5']);
        $b = $this->asset('حاسوب ٢', 'laptop');

        $this->assertSame('AST-000001', $a->asset_code);
        $this->assertSame('AST-000002', $b->asset_code);
        $this->assertSame(Asset::AVAILABLE, $a->status);
        $this->assertSame('450.500', $a->purchase_cost);

        try {
            $this->asset('حاسوب ٣', 'laptop', ['serial_number' => 'SN-1']);
            $this->fail('رقمٌ تسلسليّ مكرَّر مرّ');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('تسلسلي', $e->getMessage());
        }

        /* والرقمُ نفسُه في شركةٍ أخرى لا يصطدم */
        $this->assertNotNull($this->asset('حاسوب', 'laptop', ['serial_number' => 'SN-1'], (int) $this->other->id)->id);
        $this->assertSame('AST-000001', Asset::where('business_id', $this->other->id)->first()->asset_code);

        $this->expectException(RuntimeException::class);
        Assets::create((int) $this->business->id, ['name' => '', 'category' => 'x'], $this->owner);
    }

    public function test_status_is_derived_from_reservations_and_out_of_service_is_set_by_hand(): void
    {
        $op = $this->operation();
        $asset = $this->asset();

        $r = Reservations::reserve($op, $asset, [], $this->storekeeper);
        $this->assertSame(Asset::RESERVED, $asset->fresh()->status);

        Reservations::checkOut($r, ['custodian_id' => $this->field->id, 'condition' => 'good'], $this->storekeeper);
        $this->assertSame(Asset::DEPLOYED, $asset->fresh()->status);

        Reservations::giveBack($r, ['condition' => 'good'], $this->storekeeper);
        $this->assertSame(Asset::AVAILABLE, $asset->fresh()->status);

        $asset = Assets::setStatus($asset->fresh(), Asset::MAINTENANCE, 'شاشة لا تعمل', $this->owner);
        $this->assertSame(Asset::MAINTENANCE, $asset->status);
        $this->assertTrue(ActivityLog::where('subject_type', 'asset')->where('subject_id', $asset->id)->where('description', 'like', '%شاشة لا تعمل%')->exists());

        $asset = Assets::setStatus($asset, Asset::AVAILABLE, null, $this->owner);
        $this->assertSame(Asset::AVAILABLE, $asset->status);

        $this->expectException(RuntimeException::class);
        Assets::setStatus($asset, Asset::DEPLOYED, null, $this->owner);
    }

    /* ─────────────────────────── الحجز ─────────────────────────── */

    public function test_an_overlapping_reservation_is_refused_and_an_adjacent_one_passes(): void
    {
        $asset = $this->asset();
        $first = $this->operation();
        $second = $this->operation(['event_starts_at' => '2026-10-01 12:00', 'event_ends_at' => '2026-10-01 20:00', 'setup_starts_at' => '2026-10-01 11:00', 'teardown_starts_at' => '2026-10-01 20:00', 'teardown_ends_at' => '2026-10-01 21:00'], $this->project('EP-2026-002'));
        $third = $this->operation(['setup_starts_at' => '2026-10-01 19:00', 'event_starts_at' => '2026-10-01 20:00', 'event_ends_at' => '2026-10-01 23:00', 'teardown_starts_at' => '2026-10-01 23:00', 'teardown_ends_at' => '2026-10-01 23:30'], $this->project('EP-2026-003'));

        $r = Reservations::reserve($first, $asset, [], $this->storekeeper);
        $this->assertSame('2026-10-01 06:00', $r->reserved_from->format('Y-m-d H:i'), 'يبدأ من التجهيز');
        $this->assertSame('2026-10-01 19:00', $r->reserved_until->format('Y-m-d H:i'), 'وينتهي بالفكّ');

        try {
            Reservations::reserve($second, $asset, [], $this->storekeeper);
            $this->fail('حجزٌ متداخل مرّ');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('محجوزة للفعاليّة OP-000001', $e->getMessage());
        }
        $this->assertSame(1, AssetReservation::where('asset_id', $asset->id)->count());

        /* المتجاورُ (يبدأ حين ينتهي الأوّل) يمرّ */
        $this->assertNotNull(Reservations::reserve($third, $asset, [], $this->storekeeper)->id);

        /* والحجزُ نفسُه للفعاليّة نفسِها مرّتين يُردّ أيضًا */
        $this->expectException(RuntimeException::class);
        Reservations::reserve($first, $asset, [], $this->storekeeper);
    }

    public function test_the_reservation_is_written_under_a_row_lock_on_the_asset(): void
    {
        /*
         * التداخلُ الزمنيّ لا يُصاغ قيدًا فريدًا، فالحارسُ قفلُ صفّ القطعة داخل
         * معاملة الحجز: طلبان يصلان معًا يقف ثانيهما على القفل حتى يكتب الأوّل.
         * وSQLite لا يقفل صفوفًا، فيُفحص المصدرُ أنّ القفل يسبق فحصَ التداخل.
         */
        $source = file_get_contents(app_path('Support/Operations/Reservations.php'));
        $reserve = substr($source, strpos($source, 'public static function reserve('), strpos($source, 'public static function cancel(') - strpos($source, 'public static function reserve('));

        $lock = strpos($reserve, 'lockForUpdate()');
        $check = strpos($reserve, 'Assets::overlapping(');
        $write = strpos($reserve, 'AssetReservation::create(');

        $this->assertNotFalse($lock, 'لا قفلَ على صفّ القطعة');
        $this->assertTrue($lock < $check && $check < $write, 'القفلُ يجب أن يسبق فحصَ التداخل والكتابة');
        $this->assertStringContainsString('DB::transaction(', $reserve);
    }

    public function test_an_asset_out_of_service_cannot_be_reserved_or_checked_out(): void
    {
        $op = $this->operation();
        $damaged = Assets::setStatus($this->asset('تالف'), Asset::DAMAGED, 'كسر', $this->owner);

        try {
            Reservations::reserve($op, $damaged, [], $this->storekeeper);
            $this->fail('حجزُ تالفةٍ مرّ');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('خارج الخدمة', $e->getMessage());
        }

        $fine = $this->asset('سليم');
        $r = Reservations::reserve($op, $fine, [], $this->storekeeper);
        Assets::setStatus($fine->fresh(), Asset::MAINTENANCE, null, $this->owner);

        try {
            Reservations::checkOut($r->fresh(), [], $this->storekeeper);
            $this->fail('تسليمُ ما في الصيانة مرّ');
        } catch (RuntimeException) {
            $this->assertSame(AssetReservation::RESERVED, $r->fresh()->status);
        }

        /* والتعارضُ يُكشف: قطعةٌ محجوزة وهي خارج الخدمة */
        $conflicts = ResourceConflicts::equipment($op->fresh());
        $this->assertTrue(collect($conflicts)->contains(fn ($c) => $c['kind'] === 'asset_out_of_service' && $c['severity'] === 'critical'));
    }

    public function test_a_reservation_counts_toward_its_requirement_and_cancelling_uncounts_it(): void
    {
        $op = $this->operation();
        $req = Requirements::add($op, ['kind' => 'asset_category', 'asset_category' => 'laptop', 'required_quantity' => 2], $this->owner);

        $r1 = Reservations::reserve($op, $this->asset('ح١'), [], $this->storekeeper);
        $this->assertSame($req->id, $r1->requirement_id, 'يُربط بأوّل متطلَّبٍ من صنفه');
        $this->assertSame(1, $req->fresh()->assigned_quantity);
        $this->assertSame(1, $req->fresh()->shortfall());

        Reservations::reserve($op, $this->asset('ح٢'), [], $this->storekeeper);
        $this->assertSame(2, $req->fresh()->assigned_quantity);
        $this->assertSame([], Requirements::gaps($op->fresh()));

        Reservations::cancel($r1, 'لا حاجة', $this->storekeeper);
        $this->assertSame(1, $req->fresh()->assigned_quantity);
        $this->assertSame(AssetReservation::CANCELLED, $r1->fresh()->status);
        $this->assertSame(Asset::AVAILABLE, $r1->asset->fresh()->status);

        /* والمتطلَّبُ الذي عليه حجزٌ لا يُحذف */
        $this->expectException(RuntimeException::class);
        Requirements::remove($req->fresh(), $this->owner);
    }

    /* ─────────────────────────── التسليم والردّ ─────────────────────────── */

    public function test_checkout_and_return_are_safe_to_repeat(): void
    {
        $op = $this->operation();
        $asset = $this->asset();
        $r = Reservations::reserve($op, $asset, [], $this->storekeeper);

        /* لا ردَّ لما لم يُسلَّم */
        try {
            Reservations::giveBack($r, ['condition' => 'good'], $this->storekeeper);
            $this->fail('ردُّ ما لم يُسلَّم مرّ');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $r = Reservations::checkOut($r, ['custodian_id' => $this->field->id, 'condition' => 'excellent', 'notes' => 'مع الشاحن'], $this->storekeeper);
        $this->assertSame(AssetReservation::CHECKED_OUT, $r->status);
        $this->assertSame($this->field->id, $r->custodian_id);
        $this->assertSame('excellent', $r->checkout_condition);
        $checkedOutAt = $r->checked_out_at->toDateTimeString();

        $this->travelTo('2026-10-01 12:00:00');
        $this->assertSame($checkedOutAt, Reservations::checkOut($r, [], $this->storekeeper)->checked_out_at->toDateTimeString(), 'التسليمُ الثاني لا يكتب');

        $r = Reservations::giveBack($r, ['condition' => 'fair', 'missing_accessories' => 'الشاحن'], $this->storekeeper);
        $this->assertSame(AssetReservation::RETURNED, $r->status);
        $this->assertSame('fair', $r->return_condition);
        $this->assertSame('الشاحن', $r->missing_accessories);
        $this->assertSame('fair', $asset->fresh()->condition);
        $this->assertSame(Asset::AVAILABLE, $asset->fresh()->status);
        $returnedAt = $r->returned_at->toDateTimeString();

        $this->travelTo('2026-10-01 13:00:00');
        $again = Reservations::giveBack($r, ['condition' => 'damaged', 'damage_notes' => 'x'], $this->storekeeper);
        $this->assertSame($returnedAt, $again->returned_at->toDateTimeString(), 'الردُّ الثاني لا يكتب');
        $this->assertSame('fair', $again->return_condition, 'ولا يغيّر الحال');
        $this->assertSame(Asset::AVAILABLE, $asset->fresh()->status);
        $this->assertSame(1, ActivityLog::where('subject_type', 'asset_reservation')->where('subject_id', $r->id)->where('action', 'return')->count());
    }

    public function test_a_damaged_return_marks_the_asset_damaged_and_a_lost_one_lost(): void
    {
        $op = $this->operation();
        $a = $this->asset('أ');
        $b = $this->asset('ب');
        $ra = Reservations::checkOut(Reservations::reserve($op, $a, [], $this->storekeeper), [], $this->storekeeper);
        $rb = Reservations::checkOut(Reservations::reserve($op, $b, [], $this->storekeeper), [], $this->storekeeper);

        Reservations::giveBack($ra, ['condition' => 'damaged', 'damage_notes' => 'شاشة مكسورة'], $this->field);
        $this->assertSame(Asset::DAMAGED, $a->fresh()->status);
        $this->assertSame('damaged', $a->fresh()->condition);

        Reservations::giveBack($rb, ['condition' => 'lost'], $this->field);
        $this->assertSame(Asset::LOST, $b->fresh()->status);

        /* التالفةُ لا تُحجز حتى تُعاد إلى الخدمة بيد */
        try {
            Reservations::reserve($this->operation([], $this->project('EP-2026-002')), $a->fresh(), [], $this->storekeeper);
            $this->fail('حجزُ التالفة مرّ');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $a = Assets::setStatus($a->fresh(), Asset::AVAILABLE, 'أُصلحت', $this->owner);
        $this->assertSame(Asset::AVAILABLE, $a->status);
        $this->assertSame('good', $a->condition);
    }

    public function test_what_left_stays_outstanding_after_the_event_ends(): void
    {
        $op = $this->operation();
        $asset = $this->asset();
        $r = Reservations::checkOut(Reservations::reserve($op, $asset, [], $this->storekeeper), ['custodian_id' => $this->field->id], $this->storekeeper);

        $this->travelTo('2026-10-10 10:00:00');

        $this->assertSame(Asset::DEPLOYED, $asset->fresh()->status);
        $this->assertTrue($r->fresh()->isOutstanding());
        $this->assertSame([$r->id], Reservations::outstanding((int) $this->business->id)->pluck('id')->all());

        /* ولا يُسحب ولا يُفقد من السجلّ قبل الردّ */
        foreach ([Asset::RETIRED, Asset::LOST] as $status) {
            try {
                Assets::setStatus($asset->fresh(), $status, null, $this->owner);
                $this->fail("«{$status}» مرّت على قطعةٍ في الميدان");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('في الميدان', $e->getMessage());
            }
        }

        /* ولا يُلغى حجزُها — تُردّ */
        $this->expectException(RuntimeException::class);
        Reservations::cancel($r->fresh(), 'x', $this->storekeeper);
    }

    public function test_availability_answers_by_window_and_conflicts_read_the_neighbouring_events(): void
    {
        $laptop = $this->asset('ح', 'laptop');
        $printer = $this->asset('ط', 'printer');
        $first = $this->operation();
        Reservations::reserve($first, $laptop, [], $this->storekeeper);

        $free = Assets::availableFor((int) $this->business->id, null, Carbon::parse('2026-10-01 08:00'), Carbon::parse('2026-10-01 12:00'));
        $this->assertSame([$printer->id], $free->pluck('id')->all());

        $free = Assets::availableFor((int) $this->business->id, 'laptop', Carbon::parse('2026-10-02 08:00'), Carbon::parse('2026-10-02 12:00'));
        $this->assertSame([$laptop->id], $free->pluck('id')->all());

        /* فعاليّةٌ في الغد تحتاج الحاسوب — يعود قبلها بأقلّ من يوم: تحذير */
        $next = $this->operation(['setup_starts_at' => '2026-10-02 06:00', 'event_starts_at' => '2026-10-02 09:00', 'event_ends_at' => '2026-10-02 17:00', 'teardown_starts_at' => '2026-10-02 17:00', 'teardown_ends_at' => '2026-10-02 19:00'], $this->project('EP-2026-002'));
        $rn = Reservations::reserve($next, $laptop, [], $this->storekeeper);
        $conflicts = ResourceConflicts::equipment($next->fresh());
        $this->assertTrue(collect($conflicts)->contains(fn ($c) => $c['kind'] === 'asset_tight_return' && $c['severity'] === 'warning'), json_encode($conflicts, JSON_UNESCAPED_UNICODE));

        /* وإن خرج ولم يُردّ من الأولى صار مانعًا للثانية */
        Reservations::checkOut(AssetReservation::where('event_operation_id', $first->id)->first(), [], $this->storekeeper);
        $conflicts = ResourceConflicts::equipment($next->fresh());
        $this->assertTrue(collect($conflicts)->contains(fn ($c) => $c['kind'] === 'asset_unreturned' && $c['severity'] === 'critical'));
        $this->assertSame('critical', ResourceConflicts::worst($conflicts));

        /* وحجزٌ أقصر من نافذة الفعاليّة يُقال */
        Reservations::cancel($rn, null, $this->storekeeper);
        Reservations::reserve($next, $printer, ['from' => '2026-10-02 08:00', 'until' => '2026-10-02 12:00'], $this->storekeeper);
        $this->assertTrue(collect(ResourceConflicts::equipment($next->fresh()))->contains(fn ($c) => $c['kind'] === 'reservation_short'));
    }

    /* ─────────────────────────── العزل والأذون ─────────────────────────── */

    public function test_a_neighbour_cannot_reserve_our_asset_nor_see_it(): void
    {
        $asset = $this->asset();
        $theirProject = $this->project('EP-2026-050', (int) $this->other->id, \App\Models\Customer::create(['business_id' => $this->other->id, 'name' => 'عميلهم'])->id);
        $theirProject->update(['owner_id' => $this->stranger->id]);
        $theirOp = EventOperations::create($theirProject, ['event_starts_at' => '2026-10-01 09:00', 'event_ends_at' => '2026-10-01 17:00'], $this->stranger);

        try {
            Reservations::reserve($theirOp, $asset, [], $this->stranger);
            $this->fail('حجزٌ عبر الشركات مرّ');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ليست من شركتك', $e->getMessage());
        }

        $this->actingAs($this->stranger)->get(route('admin.assets.show', $asset->id))->assertNotFound();
        $this->actingAs($this->stranger)->post(route('admin.operations.reservations.store', $theirOp->id), ['asset_id' => $asset->id])->assertNotFound();
        $this->actingAs($this->stranger)->get(route('admin.assets.index'))->assertOk()->assertInertia(fn ($page) => $page->where('assets', []));
        $this->assertSame(0, AssetReservation::count());
    }

    public function test_the_asset_screens_and_actions_are_guarded_by_section_and_named_actions(): void
    {
        $asset = $this->asset();
        $op = $this->operation();

        $this->actingAs($this->storekeeper)->get(route('admin.assets.index'))->assertOk();
        $this->actingAs($this->storekeeper)->get(route('admin.assets.calendar'))->assertOk();
        $this->actingAs($this->storekeeper)->get(route('admin.assets.show', $asset->id))->assertOk();
        $this->actingAs($this->storekeeper)->post(route('admin.assets.store'), ['name' => 'ماسح', 'category' => 'scanner'])->assertRedirect();
        $this->assertSame(2, Asset::count());

        /* المنفّذُ الميدانيّ لا يفتح «المعدّات» ولا يسجّل — ويسلّم ويردّ من الفعاليّة */
        $this->actingAs($this->field)->get(route('admin.assets.index'))->assertForbidden();
        $this->actingAs($this->field)->post(route('admin.operations.reservations.store', $op->id), ['asset_id' => $asset->id])->assertForbidden();

        $r = Reservations::reserve($op, $asset, [], $this->storekeeper);
        $this->actingAs($this->field)->post(route('admin.operations.reservations.checkout', [$op->id, $r->id]), ['custodian_id' => $this->field->id])->assertRedirect();
        $this->assertSame(AssetReservation::CHECKED_OUT, $r->fresh()->status);
        $this->actingAs($this->field)->post(route('admin.operations.reservations.return', [$op->id, $r->id]), ['condition' => 'good'])->assertRedirect();
        $this->assertSame(AssetReservation::RETURNED, $r->fresh()->status);

        /* والبائعُ لا شيء */
        $this->actingAs($this->seller)->get(route('admin.assets.index'))->assertForbidden();
        $this->actingAs($this->seller)->post(route('admin.assets.store'), ['name' => 'x', 'category' => 'y'])->assertForbidden();

        /* والحالُ تُغيَّر بمن يدير */
        $this->actingAs($this->storekeeper)->post(route('admin.assets.status', $asset->id), ['status' => 'maintenance', 'reason' => 'فحص'])->assertRedirect();
        $this->assertSame(Asset::MAINTENANCE, $asset->fresh()->status);
    }
}
