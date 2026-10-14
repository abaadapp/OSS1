<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Support\Demo;
use App\Support\Permissions;
use App\Support\Search;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * المهامّ — عملُ الفريق في كلّ المشاريع، مشروعًا مشروعًا.
 *
 * المهمّةُ تُكتب في صفحة مشروعها، وهنا تُقرأ كلُّها معًا: كلُّ مشروعٍ
 * بطاقةٌ بمهامّه ومواعيدها، والمتأخّرُ أوّلًا. والإضافةُ والإنجازُ يمرّان
 * بمسارات المشروع نفسها — `Projects::addTask` و`updateTask` — فلا بابَ
 * ثانٍ لكتابة المهمّة.
 */
class TaskController extends Controller
{
    /** سقفُ ما يُقرأ في الشاشة الواحدة — المهامُّ المفتوحةُ لا تبلغه عادةً */
    private const LIMIT = 500;

    public function index(Request $request): Response
    {
        $user = auth()->user();
        $bid = (int) ($user->business_id ?? Demo::bid());
        $today = now()->toDateString();
        $week = now()->addDays(7)->toDateString();

        $show = in_array($request->string('show')->toString(), ['open', 'done', 'all'], true) ? $request->string('show')->toString() : 'open';
        $when = in_array($request->string('when')->toString(), ['late', 'week'], true) ? $request->string('when')->toString() : '';
        $mine = $request->boolean('mine');
        $q = Search::term($request);

        $tasks = ProjectTask::where('business_id', $bid)
            ->with('assignee:id,name', 'project.customer:id,name')
            ->when($show !== 'all', fn ($w) => $w->where('status', $show === 'done' ? ProjectTask::DONE : ProjectTask::OPEN))
            ->when($when === 'late', fn ($w) => $w->where('status', ProjectTask::OPEN)->whereDate('due_at', '<', $today))
            ->when($when === 'week', fn ($w) => $w->where('status', ProjectTask::OPEN)->whereDate('due_at', '>=', $today)->whereDate('due_at', '<=', $week))
            ->when($mine, fn ($w) => $w->where('assignee_id', $user->id))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('title', Search::like(), "%{$q}%")
                ->orWhereHas('project', fn ($p) => $p->where('code', Search::like(), "%{$q}%")->orWhere('name', Search::like(), "%{$q}%"))))
            // المفتوحُ قبل المنجَز، والموعدُ الأقربُ أوّلًا، وما بلا موعدٍ آخرًا
            ->orderByRaw('case when status = ? then 0 else 1 end', [ProjectTask::OPEN])
            ->orderByRaw('case when due_at is null then 1 else 0 end')
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        /* وعدّادُ كلّ مشروعٍ من مهامّه كلّها، لا ممّا بقي بعد التصفية */
        $ids = $tasks->pluck('project_id')->unique()->all();
        $counts = ProjectTask::whereIn('project_id', $ids)
            ->selectRaw('project_id, count(*) as total, sum(case when status = ? then 1 else 0 end) as done', [ProjectTask::DONE])
            ->groupBy('project_id')->get()->keyBy('project_id');

        $projects = $tasks->groupBy('project_id')->map(function (Collection $rows) use ($counts, $today) {
            /** @var Project $p */
            $p = $rows->first()->project;
            $c = $counts[$p->id] ?? null;

            return [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'customer' => $p->customer?->name,
                'status' => $p->status,
                'starts_at' => $p->starts_at?->format('Y-m-d'),
                'ends_at' => $p->ends_at?->format('Y-m-d'),
                'total' => (int) ($c->total ?? 0),
                'done' => (int) ($c->done ?? 0),
                'late' => $rows->filter(fn (ProjectTask $t) => ! $t->isDone() && $t->due_at && $t->due_at->toDateString() < $today)->count(),
                'next_due' => $rows->first(fn (ProjectTask $t) => ! $t->isDone() && $t->due_at)?->due_at?->format('Y-m-d'),
                'tasks' => $rows->map(fn (ProjectTask $t) => [
                    'id' => $t->id,
                    'title' => $t->title,
                    'description' => $t->description,
                    'due_at' => $t->due_at?->format('Y-m-d'),
                    'priority' => $t->priority,
                    'status' => $t->status,
                    'assignee' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name] : null,
                ])->values()->all(),
            ];
        })
            // المشروعُ الذي فيه متأخّرٌ أوّلًا، ثمّ الأقربُ موعدًا
            ->sortBy(fn ($p) => [$p['late'] > 0 ? 0 : 1, $p['next_due'] ?? '9999-12-31'])
            ->values()->all();

        $open = fn () => ProjectTask::where('business_id', $bid)->where('status', ProjectTask::OPEN);

        return Inertia::render('Admin/Tasks/Index', [
            'projects' => $projects,
            'filters' => ['show' => $show, 'when' => $when, 'mine' => $mine, 'q' => $q],
            'today' => $today,
            'totals' => [
                'open' => (int) $open()->count(),
                'late' => (int) $open()->whereDate('due_at', '<', $today)->count(),
                'week' => (int) $open()->whereDate('due_at', '>=', $today)->whereDate('due_at', '<=', $week)->count(),
                'mine' => (int) $open()->where('assignee_id', $user->id)->count(),
            ],
            /* وللإضافة: المشاريعُ القائمة وفريقُ الشركة */
            'choices' => [
                'projects' => Project::where('business_id', $bid)->open()->orderByDesc('id')->get(['id', 'code', 'name'])->all(),
                'staff' => User::where('business_id', $bid)->orderBy('name')->get(['id', 'name'])->all(),
            ],
            'may' => ['manage' => (bool) $user->may(Permissions::PROJECT_MANAGE)],
        ]);
    }
}
