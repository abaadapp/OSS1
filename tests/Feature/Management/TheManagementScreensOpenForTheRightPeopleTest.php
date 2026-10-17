<?php

namespace Tests\Feature\Management;

use App\Support\Intelligence\ExecutiveMetrics;
use App\Support\Intelligence\Period;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * شاشاتُ الإدارة تُفتح لمن يحقّ له — وكلُّ شركةٍ ترى أرقامَها وحدَها.
 *
 * المالكُ والمديرُ يفتحان كلَّ شيء؛ والمحاسبُ يقرأ المالَ والمشاريعَ والعملاءَ
 * والنقدَ والموازناتِ ويقرأ المراجعاتِ ولا يفتح التشغيل؛ والبائعُ لا يفتح القسم؛
 * ومن مُنح «الإدارة» بالاسم بلا تحليلاتٍ يرى الحركةَ لا المال. والغريبُ من
 * شركةٍ أخرى يفتح شاشاتِ شركته فارغةً — لا رقمًا من جارته.
 */
class TheManagementScreensOpenForTheRightPeopleTest extends ManagementTestCase
{
    use RefreshDatabase;

    /** @return array<string, array<string, int>> route => [role => status] */
    private function matrix(): array
    {
        return [
            'admin.management.index' => ['owner' => 200, 'manager' => 200, 'accountant' => 200, 'seller' => 403],
            'admin.management.financial' => ['owner' => 200, 'manager' => 200, 'accountant' => 200, 'seller' => 403],
            'admin.management.projects' => ['owner' => 200, 'manager' => 200, 'accountant' => 200, 'seller' => 403],
            'admin.management.customers' => ['owner' => 200, 'manager' => 200, 'accountant' => 200, 'seller' => 403],
            'admin.management.services' => ['owner' => 200, 'manager' => 200, 'accountant' => 200, 'seller' => 403],
            'admin.management.cash' => ['owner' => 200, 'manager' => 200, 'accountant' => 200, 'seller' => 403],
            'admin.management.budgets' => ['owner' => 200, 'manager' => 200, 'accountant' => 200, 'seller' => 403],
            'admin.management.operations' => ['owner' => 200, 'manager' => 200, 'accountant' => 403, 'seller' => 403],
            'admin.management.reviews' => ['owner' => 200, 'manager' => 200, 'accountant' => 200, 'seller' => 403],
            'admin.management.reports' => ['owner' => 200, 'manager' => 200, 'accountant' => 200, 'seller' => 403],
        ];
    }

    public function test_every_management_screen_answers_each_role_as_the_matrix_says(): void
    {
        $this->invoice(1000, '2026-09-10');

        foreach ($this->matrix() as $route => $roles) {
            foreach ($roles as $role => $status) {
                $this->actingAs($this->{$role})->get(route($route))->assertStatus($status, "$route لـ$role");
            }
        }
    }

    public function test_someone_granted_the_section_by_name_sees_activity_but_no_money(): void
    {
        $bid = (int) $this->business->id;
        $this->invoice(1000, '2026-09-10');
        $this->seller->update(['permissions' => ['dashboard', 'quotations', 'projects', 'customers', 'products', 'management']]);
        $viewer = $this->seller->fresh();

        $overview = ExecutiveMetrics::overview($bid, Period::resolve($bid, 'this_month'), $viewer);
        $this->assertArrayHasKey('activity', $overview);
        $this->assertArrayNotHasKey('financial', $overview);
        $this->assertArrayNotHasKey('cash', $overview);
        $this->assertFalse($overview['may']['financial']);

        $this->actingAs($viewer)->get(route('admin.management.index'))->assertOk()
            ->assertInertia(fn ($a) => $a->missing('overview.financial')->has('overview.activity'));
        $this->actingAs($viewer)->get(route('admin.management.financial'))->assertForbidden();
        $this->actingAs($viewer)->get(route('admin.management.budgets'))->assertForbidden();
    }

    public function test_a_neighbouring_business_sees_only_its_own_empty_numbers(): void
    {
        $bid = (int) $this->business->id;
        $project = $this->project('EP-SECRET', 5250);
        $this->invoice(5000, '2026-09-10', $project);
        $this->supplierInvoice(1000, 0, $project);

        $overview = ExecutiveMetrics::overview((int) $this->other->id, Period::resolve((int) $this->other->id, 'this_month'), $this->stranger);
        $this->assertEqualsWithDelta(0.0, $overview['financial']['revenue']['net'], 0.0005);
        $this->assertSame(0, $overview['projects']['totals']['projects']);

        foreach (array_keys($this->matrix()) as $route) {
            $this->actingAs($this->stranger)->get(route($route))->assertOk()->assertDontSee('EP-SECRET');
        }
        $this->actingAs($this->stranger)->get(route('admin.management.reports', ['report' => 'project_profitability', 'period' => 'this_month']))->assertOk()->assertDontSee('EP-SECRET');
        $this->actingAs($this->stranger)->get(route('admin.projects.show', $project->id))->assertNotFound();
    }

    public function test_the_project_screen_carries_performance_only_for_those_who_may_read_it(): void
    {
        $project = $this->project('EP-P', 1050);
        $this->invoice(1000, '2026-09-10', $project);

        $this->actingAs($this->owner)->get(route('admin.projects.show', $project->id))->assertOk()
            ->assertInertia(fn ($a) => $a->where('performance.revenue', 1000)->where('may.performance', true)->has('performance.timeline'));

        $this->actingAs($this->seller)->get(route('admin.projects.show', $project->id))->assertOk()
            ->assertInertia(fn ($a) => $a->where('performance', null)->where('may.performance', false));
    }

    public function test_the_overview_compares_with_the_previous_period_and_never_invents_a_change(): void
    {
        $bid = (int) $this->business->id;
        $this->invoice(1000, '2026-09-10');

        $o = ExecutiveMetrics::overview($bid, Period::resolve($bid, 'this_month'), $this->owner);
        $this->assertSame('insufficient_data', $o['financial']['previous']['change']['revenue']['status']);
        $this->assertCount(12, $o['trend']);
        $this->assertSame('insufficient_data', $o['runway']['status']);
        $this->assertNull($o['forecast'], 'لا توقّعَ معتمَدًا — يُقال لا يُختلق');
        $this->assertSame(0, $o['activity']['active_projects']);
        $this->assertArrayHasKey('alerts', $o);
    }
}
