<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

/**
 * العملياتُ تُقرأ في تقويم — والقائمةُ بابٌ ثانٍ.
 *
 * الشهرُ شبكةُ أسابيعَ كاملة، وما يقع فيها ما تتقاطع نافذتُه معها (التجهيزُ
 * إلى الفكّ) لا بدايتُه وحدَها. وما لا موعدَ له يُعدّ ويُدلّ عليه، والجارُ
 * لا يظهر في تقويمنا.
 */
class TheOperationsAreReadOnACalendarTest extends OperationsTestCase
{
    use RefreshDatabase;

    private function calendar(array $query = []): array
    {
        $out = [];
        $this->actingAs($this->owner)->get(route('admin.operations.index', $query))->assertOk()
            ->assertInertia(function (AssertableInertia $p) use (&$out) {
                $out = $p->toArray()['props'];
            });

        return $out;
    }

    public function test_the_calendar_is_the_default_and_shows_what_overlaps_the_month_grid(): void
    {
        $october = $this->operation();
        /* فعاليّةٌ تبدأ تجهيزُها في آخر سبتمبر وموعدُها أوّل نوفمبر — تمرّ بأكتوبر كلّه */
        $spanning = $this->operation(['name' => 'معرضٌ طويل', 'setup_starts_at' => '2026-09-28 06:00', 'event_starts_at' => '2026-11-01 09:00',
            'event_ends_at' => '2026-11-01 17:00', 'teardown_starts_at' => '2026-11-01 17:00', 'teardown_ends_at' => '2026-11-02 12:00'], $this->project('EP-2026-002'));
        $december = $this->operation(['name' => 'ديسمبر', 'setup_starts_at' => '2026-12-10 06:00', 'event_starts_at' => '2026-12-10 09:00',
            'event_ends_at' => '2026-12-10 17:00', 'teardown_starts_at' => '2026-12-10 17:00', 'teardown_ends_at' => '2026-12-10 19:00'], $this->project('EP-2026-003'));

        $props = $this->calendar(['month' => '2026-10']);

        $this->assertSame('calendar', $props['view']);
        $this->assertSame('2026-10', $props['calendar']['month']);
        /* أحدُ الأسبوع الأوّل إلى سبتِ الأخير */
        $this->assertSame('2026-09-27', $props['calendar']['from']);
        $this->assertSame('2026-10-31', $props['calendar']['to']);

        $ids = collect($props['calendar']['events'])->pluck('id')->all();
        $this->assertContains($october->id, $ids);
        $this->assertContains($spanning->id, $ids, 'نافذتُه تتقاطع مع الشهر وإن كان موعدُه بعده');
        $this->assertNotContains($december->id, $ids);
        $this->assertSame([], $props['operations'], 'القائمةُ لا تُحسب ما دام التقويمُ معروضًا');

        $row = collect($props['calendar']['events'])->firstWhere('id', $spanning->id);
        $this->assertSame('2026-09-28 06:00', $row['window_starts_at']);
        $this->assertSame('2026-11-02 12:00', $row['window_ends_at']);
    }

    public function test_the_list_is_still_there_and_a_bad_month_falls_back_to_this_one(): void
    {
        $this->operation();

        $list = $this->calendar(['view' => 'list']);
        $this->assertSame('list', $list['view']);
        $this->assertCount(1, $list['operations']);

        $this->assertSame('2026-09', $this->calendar(['month' => 'x'])['calendar']['month']);
    }

    public function test_the_side_list_is_whats_coming_from_today_not_the_month_shown(): void
    {
        /* اليومُ في الاختبار 2026-09-20 */
        $soon = $this->operation(['name' => 'قريبة', 'setup_starts_at' => '2026-09-25 06:00', 'event_starts_at' => '2026-09-25 09:00', 'event_ends_at' => '2026-09-27 17:00', 'teardown_starts_at' => '2026-09-27 17:00', 'teardown_ends_at' => '2026-09-27 19:00']);
        $later = $this->operation(['name' => 'بعيدة', 'setup_starts_at' => '2026-12-10 06:00', 'event_starts_at' => '2026-12-10 09:00', 'event_ends_at' => '2026-12-10 17:00', 'teardown_starts_at' => '2026-12-10 17:00', 'teardown_ends_at' => '2026-12-10 19:00'], $this->project('EP-2026-002'));
        $past = $this->operation(['name' => 'مضت', 'setup_starts_at' => '2026-09-01 06:00', 'event_starts_at' => '2026-09-01 09:00', 'event_ends_at' => '2026-09-01 17:00', 'teardown_starts_at' => '2026-09-01 17:00', 'teardown_ends_at' => '2026-09-01 19:00'], $this->project('EP-2026-003'));

        /* والشهرُ المعروضُ شهرٌ مضى — والقادمةُ هي هي */
        $props = $this->calendar(['month' => '2026-08']);

        $this->assertSame([$soon->id, $later->id], array_column($props['calendar']['upcoming'], 'id'));
        $this->assertNotContains($past->id, array_column($props['calendar']['upcoming'], 'id'));
    }

    public function test_a_neighbour_never_appears_on_our_calendar(): void
    {
        $this->operation();
        $this->actingAs($this->stranger)->get(route('admin.operations.index', ['month' => '2026-10']))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('calendar.events', []));
    }
}
