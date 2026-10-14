<?php

namespace Tests\Feature;

use App\Models\ProjectTask;
use App\Support\Projects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Operations\OperationsTestCase;

/**
 * مهامُّ المشاريع كلِّها تُقرأ معًا — مشروعًا مشروعًا، والمتأخّرُ أوّلًا.
 *
 * الشاشةُ تقرأ ولا تكتب: الإضافةُ والإنجازُ من مسارات المشروع. والمشروعُ
 * الذي فيه متأخّرٌ يتقدّم، والجارُ لا تظهر مهامُّه عندنا.
 */
class EveryProjectsTasksAreReadTogetherByTheirDeadlinesTest extends OperationsTestCase
{
    use RefreshDatabase;

    private function screen(array $query = [], $as = null): array
    {
        $out = [];
        $this->actingAs($as ?? $this->owner)->get(route('admin.tasks.index', $query))->assertOk()
            ->assertInertia(function (AssertableInertia $p) use (&$out) {
                $p->component('Admin/Tasks/Index');
                $out = $p->toArray()['props'];
            });

        return $out;
    }

    public function test_tasks_are_grouped_by_project_and_the_late_project_comes_first(): void
    {
        $later = $this->project('EP-2026-002');
        Projects::addTask($later, ['title' => 'حجز القاعة', 'due_at' => '2026-09-25'], $this->owner);
        $late = Projects::addTask($this->project, ['title' => 'اعتماد التصاميم', 'due_at' => '2026-09-18', 'assignee_id' => $this->manager->id], $this->owner);
        Projects::addTask($this->project, ['title' => 'بلا موعد'], $this->owner);
        $done = Projects::addTask($this->project, ['title' => 'منجزة', 'due_at' => '2026-09-10'], $this->owner);
        Projects::updateTask($done, ['status' => ProjectTask::DONE], $this->owner);

        $props = $this->screen();

        $this->assertSame([$this->project->id, $later->id], array_column($props['projects'], 'id'));
        $first = $props['projects'][0];
        $this->assertSame(['اعتماد التصاميم', 'بلا موعد'], array_column($first['tasks'], 'title'), 'المنجزةُ لا تظهر في المفتوحة، وما بلا موعدٍ آخرًا');
        $this->assertSame(1, $first['late']);
        $this->assertSame(3, $first['total']);
        $this->assertSame(1, $first['done']);
        $this->assertSame($this->manager->id, $first['tasks'][0]['assignee']['id']);
        $this->assertSame(['open' => 3, 'late' => 1, 'week' => 1, 'mine' => 0], $props['totals']);

        $this->assertSame([$late->id], array_column($this->screen(['when' => 'late'])['projects'][0]['tasks'], 'id'));
        $this->assertSame(['منجزة'], array_column($this->screen(['show' => 'done'])['projects'][0]['tasks'], 'title'));
    }

    public function test_a_neighbours_tasks_never_appear(): void
    {
        Projects::addTask($this->project, ['title' => 'مهمّتنا'], $this->owner);

        $props = $this->screen([], $this->stranger);

        $this->assertSame([], $props['projects']);
        $this->assertSame(0, $props['totals']['open']);
    }
}
