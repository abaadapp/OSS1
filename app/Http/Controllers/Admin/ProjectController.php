<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChangeOrder;
use App\Models\Customer;
use App\Models\DeliveryPackage;
use App\Models\EventOperation;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectRequirement;
use App\Models\ProjectTask;
use App\Models\User;
use App\Support\ChangeOrders;
use App\Support\DeliveryPackages;
use App\Support\DeliveryReadiness;
use App\Support\Demo;
use App\Support\Operations\Checklists;
use App\Support\Operations\Crew;
use App\Support\Operations\EventOperations;
use App\Support\Operations\OperationReadiness;
use App\Support\Operations\ResourceConflicts;
use App\Support\Pagination;
use App\Support\Permissions;
use App\Support\ProjectFinancials;
use App\Support\Projects;
use App\Support\ProjectScope;
use App\Support\Search;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * المشاريع — الشاشة.
 *
 * ولا حسابَ يقع هنا: الدورةُ في `Projects`، والنطاقُ في `ProjectScope`،
 * والمالُ في `ProjectFinancials`. ما في هذا الملفّ ثلاثة: يقرأ الطلب،
 * ويسأل عن الإذن، ويردّ شاشة.
 */
class ProjectController extends Controller
{
    private const PER_PAGE = [20, 50, 100];

    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    /** المشروعُ من شركة الطالب لا من رقمٍ في الرابط */
    private function find(int|string $id): Project
    {
        return Project::where('business_id', $this->bid())->whereKey($id)->with('customer')->firstOrFail();
    }

    /** @return array<string, bool> */
    private function may(): array
    {
        $user = auth()->user();

        return [
            'manage' => (bool) $user?->may(Permissions::PROJECT_MANAGE),
            'budget' => (bool) $user?->may(Permissions::PROJECT_BUDGET_VIEW),
            'close' => (bool) $user?->may(Permissions::PROJECT_CLOSE),
            'reopen' => (bool) $user?->may(Permissions::PROJECT_REOPEN),
            'change_order' => (bool) $user?->may(Permissions::CHANGE_ORDER_CREATE),
            /* والإعفاءُ من رسم الإلغاء فعلُ من يتجاوز السياسة باسمه */
            'override_policy' => (bool) $user?->may(Permissions::POLICY_OVERRIDE),
            'delivery' => (bool) $user?->may(Permissions::QUOTATION_CREATE),
            /*
             * والمالُ التجاريّ — القيمُ والأسعارُ والفروقُ والملاحظاتُ الداخليّة —
             * لمن يقرأ الميزانيّة أو يكتب أمرَ تغيير. ومن يملك القسمَ ليُنفّذ
             * (التسليم) يرى البنودَ والكميّاتِ والمواعيد، ولا يُرسَل إليه رقمٌ
             * ليُخفى في الشاشة.
             */
            'commercial' => (bool) ($user?->may(Permissions::PROJECT_BUDGET_VIEW) || $user?->may(Permissions::CHANGE_ORDER_CREATE)),
            /* والتشغيلُ — المرحلة 10: من يفتح «العمليات» يرى تبويبَها، ومن يديرها يُنشئ فعاليّة */
            'operations' => (bool) $user?->allows('operations'),
            'operations_manage' => (bool) $user?->may(Permissions::OPERATIONS_MANAGE),
        ];
    }

    /* ─────────────────────────── القائمة ─────────────────────────── */

    public function index(Request $request): Response
    {
        $bid = $this->bid();
        $may = $this->may();
        $per = (int) $request->integer('per_page');
        $per = in_array($per, self::PER_PAGE, true) ? $per : self::PER_PAGE[0];

        $page = Project::where('business_id', $bid)->with('customer', 'owner')
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('code', Search::like(), "%{$s}%")
                    ->orWhere('name', Search::like(), "%{$s}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', Search::like(), "%{$s}%"))
            ))
            ->when($request->integer('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
            ->when($request->string('status')->toString(), fn ($q, $s) => in_array($s, Project::STATUSES, true)
                ? $q->where('status', $s) : $q->whereRaw('1 = 0'))
            ->orderByDesc('id')
            ->paginate($per)
            ->withQueryString();

        $base = fn () => Project::where('business_id', $bid);

        return Inertia::render('Admin/Projects/Index', [
            'projects' => collect($page->items())->map(fn (Project $p) => $this->row($p, $may['commercial']))->all(),
            'pagination' => Pagination::meta($page),
            'filters' => $request->only('q', 'customer_id', 'status', 'per_page'),
            'statuses' => Project::STATUSES,
            'customers' => Customer::where('business_id', $bid)->orderBy('name')->get(['id', 'name'])->all(),
            'totals' => [
                'open' => (int) $base()->open()->count(),
                'upcoming' => (int) $base()->open()->whereBetween('starts_at', [now()->toDateString(), now()->addDays(7)->toDateString()])->count(),
                'unbaselined' => (int) $base()->open()->whereNull('requirements_baselined_at')->count(),
                'pending_change_orders' => (int) ChangeOrder::where('business_id', $bid)
                    ->whereIn('status', [ChangeOrder::PENDING_APPROVAL, ChangeOrder::SENT])->count(),
            ],
            'may' => $may,
        ]);
    }

    /** @return array<string, mixed> */
    private function row(Project $p, bool $commercial): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'customer' => $p->customer?->name ?? '—',
            'customer_id' => $p->customer_id,
            'status' => $p->status,
            'starts_at' => optional($p->starts_at)->format('Y-m-d'),
            'ends_at' => optional($p->ends_at)->format('Y-m-d'),
            'approved_value' => $commercial ? (float) $p->approved_value : null,
            'current_value' => $commercial ? ProjectScope::currentValue($p) : null,
            'owner' => $p->owner?->name,
            'baselined' => $p->isBaselined(),
        ];
    }

    /**
     * النطاقُ بلا أرقامه لمن لا يملك المال — الوصفُ والكميّةُ والموعدُ تبقى.
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private static function withoutMoney(array $scope): array
    {
        $blank = ['unit_price' => null, 'line_total' => null];
        $items = fn (array $rows) => array_map(fn (array $i) => array_replace($i, array_intersect_key($blank, $i)), $rows);

        $scope['original']['items'] = $items($scope['original']['items']);
        $scope['original']['approved_value'] = null;

        if (isset($scope['original']['quotation'])) {
            $scope['original']['quotation']['total'] = null;
        }

        foreach ($scope['changes'] as $k => $change) {
            $scope['changes'][$k]['total_delta'] = null;
            $scope['changes'][$k]['items'] = $items($change['items']);
        }

        $scope['current']['items'] = $items($scope['current']['items']);
        $scope['current']['current_value'] = null;

        return $scope;
    }

    /* ─────────────────────────── الشاشة ─────────────────────────── */

    public function show(int|string $id): Response
    {
        $project = $this->find($id);
        $may = $this->may();

        $project->load('owner', 'baseliner', 'members.user', 'tasks.assignee',
            'requirements.addedBy', 'requirements.removedBy', 'requirements.item',
            'changeOrders.approval', 'deliveryPackages', 'cancellationFeeInvoice');

        return Inertia::render('Admin/Projects/Show', [
            'project' => $this->row($project, $may['commercial']) + [
                'event_type' => $project->event_type,
                'location' => $project->location,
                'contact_person' => $project->contact_person,
                'contact_phone' => $project->contact_phone,
                'contact_email' => $project->contact_email,
                'owner_id' => $project->owner_id,
                'quoted_value' => $may['commercial'] ? (float) $project->quoted_value : null,
                'notes' => $project->notes,
                'internal_notes' => $may['commercial'] ? $project->internal_notes : null,
                'requirements_baselined_at' => optional($project->requirements_baselined_at)->format('Y-m-d H:i'),
                'requirements_baselined_by' => $project->baseliner?->name,
                'closed_at' => optional($project->closed_at)->format('Y-m-d H:i'),
                'cancellation_reason' => $project->cancellation_reason,
                'cancellation_policy' => $project->cancellation_policy,
                'cancellation_fee_invoice' => $project->cancellationFeeInvoice === null ? null : [
                    'id' => $project->cancellationFeeInvoice->id,
                    'number' => $project->cancellationFeeInvoice->number,
                    'total' => $may['commercial'] ? (float) $project->cancellationFeeInvoice->total : null,
                ],
                'financials_frozen' => $may['budget'] ? $project->financials_frozen : null,
                'quotation_id' => $project->quotation_id,
                /* وما افترق من الحارس يُقال بالأحمر — لا يُخفى */
                'scope_broken' => ProjectScope::verify($project),
            ],
            'scope' => ($scope = [
                'original' => ProjectScope::original($project),
                'changes' => ProjectScope::changes($project),
                'current' => ProjectScope::current($project),
            ]) && $may['commercial'] ? $scope : self::withoutMoney($scope),
            'requirements' => $project->requirements->map(fn (ProjectRequirement $r) => [
                'id' => $r->id,
                'title' => $r->title,
                'details' => $r->details,
                'project_item_id' => $r->project_item_id,
                'item' => $r->item?->description,
                'baseline' => $r->isBaseline(),
                'active' => $r->isActive(),
                'added_by' => $r->addedBy === null ? null : ['id' => $r->addedBy->id, 'label' => ChangeOrders::label($r->addedBy), 'status' => $r->addedBy->status],
                'removed_by' => $r->removedBy === null ? null : ['id' => $r->removedBy->id, 'label' => ChangeOrders::label($r->removedBy), 'status' => $r->removedBy->status],
            ])->all(),
            'members' => $project->members->map(fn (ProjectMember $m) => [
                'id' => $m->id, 'user_id' => $m->user_id, 'name' => $m->user?->name, 'role' => $m->role_in_project,
            ])->all(),
            'tasks' => $project->tasks->map(fn (ProjectTask $t) => [
                'id' => $t->id, 'title' => $t->title, 'description' => $t->description,
                'assignee_id' => $t->assignee_id, 'assignee' => $t->assignee?->name,
                'due_at' => optional($t->due_at)->format('Y-m-d'), 'status' => $t->status, 'priority' => $t->priority,
                'completed_at' => optional($t->completed_at)->format('Y-m-d H:i'),
            ])->all(),
            'change_orders' => $project->changeOrders->map(fn (ChangeOrder $co) => [
                'id' => $co->id,
                'label' => ChangeOrders::label($co),
                'sequence' => $co->sequence,
                'title' => $co->title,
                'status' => $co->status,
                'state' => $co->presentedState(),
                'total_delta' => $may['commercial'] ? (float) $co->total_delta : null,
                'new_starts_at' => optional($co->new_starts_at)->format('Y-m-d'),
                'requested_by_party' => $co->requested_by_party,
                'sent_at' => optional($co->sent_at)->format('Y-m-d'),
                'decided_at' => optional($co->decided_at)->format('Y-m-d'),
            ])->all(),
            /* والمالُ لمن يملك رؤيته — لا يُرسَل إلى شاشةٍ لا تُظهره */
            'financials' => $may['budget'] ? ProjectFinancials::summary($project) : null,
            /* التسليم — المرحلة 5: الحزمُ وجاهزيّةُ الإقفال من جهتها */
            'delivery' => [
                'readiness' => DeliveryReadiness::forProject($project)->toArray(),
                'packages' => $project->deliveryPackages->map(fn (DeliveryPackage $d) => [
                    'id' => $d->id,
                    'label' => DeliveryPackages::label($d),
                    'title' => $d->title,
                    'status' => $d->status,
                    'sent_at' => optional($d->sent_at)->format('Y-m-d'),
                    'decided_at' => optional($d->accepted_at ?? $d->declined_at)->format('Y-m-d'),
                ])->all(),
            ],
            /* وحكمُ سياسة الإلغاء لو أُلغي الآن — يُقرأ قبل أن يُضغط الزرّ */
            'cancellation' => $may['manage'] && ($project->isOpen() || $project->status === Project::DRAFT)
                ? Projects::cancellationVerdict($project)->toArray() : null,
            /* التشغيل — المرحلة 10: الفعاليّاتُ بجاهزيّتها وتعارضاتها؛ ولمن يفتح القسم */
            'operations' => $may['operations'] ? $this->operations($project) : null,
            'users' => User::where('business_id', $this->bid())->orderBy('name')->get(['id', 'name'])->all(),
            'may' => $may,
        ]);
    }

    /**
     * فعاليّاتُ المشروع — ملخّصٌ لتبويب «العمليات»، لا الشاشةَ كلَّها.
     *
     * @return list<array<string, mixed>>
     */
    private function operations(Project $project): array
    {
        $operations = EventOperation::where('project_id', $project->id)
            ->with('lead:id,name', 'requirements', 'reservations.asset', 'reservations.requirement', 'crew.user', 'checklists.items', 'scheduleItems', 'incidents')
            ->orderBy('event_starts_at')->orderBy('id')->get();

        return $operations->map(function (EventOperation $o) {
            $verdict = $o->status === EventOperation::LIVE || $o->status === EventOperation::SETUP ? OperationReadiness::live($o) : OperationReadiness::for($o);
            $conflicts = ResourceConflicts::all($o);
            $progress = Checklists::progress($o);

            return [
                'id' => $o->id,
                'code' => $o->code,
                'name' => $o->name,
                'status' => $o->status,
                'status_label' => __(EventOperations::statusLabel($o->status)),
                'venue_name' => $o->venue_name,
                'event_starts_at' => $o->event_starts_at?->format('Y-m-d H:i'),
                'event_ends_at' => $o->event_ends_at?->format('Y-m-d H:i'),
                'lead' => $o->lead?->name,
                'ready' => $verdict->ready,
                'blockers' => $verdict->blockerMessages(),
                'warnings' => count($verdict->warnings),
                'conflicts' => count($conflicts),
                'critical_conflicts' => count(array_filter($conflicts, fn ($c) => $c['severity'] === ResourceConflicts::CRITICAL)),
                'crew' => Crew::attendance($o),
                'equipment' => [
                    'required' => (int) $o->requirements->sum('required_quantity'),
                    'assigned' => (int) $o->requirements->sum('assigned_quantity'),
                    'reserved' => $o->reservations->whereIn('status', ['reserved', 'checked_out'])->count(),
                    'outstanding' => $o->reservations->where('status', 'checked_out')->count(),
                ],
                'checklist' => [
                    'total' => array_sum(array_column($progress, 'total')),
                    'done' => array_sum(array_column($progress, 'done')) + array_sum(array_column($progress, 'waived')),
                    'required_pending' => array_sum(array_column($progress, 'required_pending')),
                ],
                'incidents' => ['open' => $o->incidents->filter(fn ($i) => $i->isUnresolved())->count(), 'total' => $o->incidents->count()],
                'url' => route('admin.operations.show', $o->id),
                'onsite_url' => route('admin.operations.onsite', $o->id),
            ];
        })->values()->all();
    }

    /* ─────────────────────────── الكتابة ─────────────────────────── */

    public function update(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::PROJECT_MANAGE), 403);

        $project = $this->find($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'event_type' => ['nullable', 'string', 'max:60'],
            'location' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:120'],
            'owner_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'internal_notes' => ['nullable', 'string', 'max:20000'],
        ], [], ['name' => __('اسم المشروع')]);

        /* ومن لم تُرسَل إليه الملاحظاتُ الداخليّة لا يكتب فوقها فراغًا */
        if (! $this->may()['commercial']) {
            unset($data['internal_notes']);
        }

        try {
            Projects::update($project, $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['project' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('حُفظ المشروع'), 'type' => 'success']);
    }

    public function baseline(int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::PROJECT_MANAGE), 403);

        $project = $this->find($id);

        try {
            Projects::baseline($project, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['project' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('ثُبّتت المتطلّبات — وما بعده بأمر تغيير'), 'type' => 'success']);
    }

    public function transition(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::PROJECT_MANAGE), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in([Project::IN_PROGRESS, Project::ON_HOLD, Project::CANCELLED])],
            'reason' => ['nullable', 'string', 'max:255'],
            /* الإعفاءُ من رسم الإلغاء — يُقبل هنا ويُفحص إذنُه في `Projects::cancel` */
            'exception_reason' => ['nullable', 'string', 'max:500'],
        ], [], ['status' => __('الحالة'), 'reason' => __('السبب'), 'exception_reason' => __('سبب الإعفاء')]);

        $project = $this->find($id);

        try {
            if ($data['status'] === Project::CANCELLED) {
                Projects::cancel($project, (string) ($data['reason'] ?? ''), auth()->user(), $data['exception_reason'] ?? null);
            } else {
                Projects::transition($project, $data['status'], $data['reason'] ?? null, auth()->user());
            }
        } catch (RuntimeException $e) {
            return back()->withErrors(['project' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('حُدّثت حالة المشروع'), 'type' => 'success']);
    }

    public function close(int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::PROJECT_CLOSE), 403);

        $project = $this->find($id);

        try {
            Projects::close($project, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['project' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('أُقفل المشروع — وجُمّدت أرقامه'), 'type' => 'success']);
    }

    public function reopen(int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::PROJECT_REOPEN), 403);

        $project = $this->find($id);

        try {
            Projects::reopen($project, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['project' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('أُعيد فتح المشروع'), 'type' => 'success']);
    }

    /* ─────────────────────────── المتطلّبات ─────────────────────────── */

    /** @return array<string, mixed> */
    private function requirementRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:5000'],
            'project_item_id' => ['nullable', 'integer'],
        ];
    }

    public function storeRequirement(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::PROJECT_MANAGE), 403);

        $project = $this->find($id);
        $data = $request->validate($this->requirementRules(), [], ['title' => __('المتطلّب')]);

        try {
            Projects::addRequirement($project, $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['requirement' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('أُضيف المتطلّب'), 'type' => 'success']);
    }

    public function updateRequirement(Request $request, int|string $id, int|string $rid)
    {
        abort_if(! auth()->user()?->may(Permissions::PROJECT_MANAGE), 403);

        $project = $this->find($id);
        $requirement = ProjectRequirement::where('project_id', $project->id)->findOrFail($rid);
        $data = $request->validate($this->requirementRules(), [], ['title' => __('المتطلّب')]);

        try {
            Projects::updateRequirement($requirement, $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['requirement' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('حُفظ المتطلّب'), 'type' => 'success']);
    }

    public function destroyRequirement(int|string $id, int|string $rid)
    {
        abort_if(! auth()->user()?->may(Permissions::PROJECT_MANAGE), 403);

        $project = $this->find($id);
        $requirement = ProjectRequirement::where('project_id', $project->id)->findOrFail($rid);

        try {
            Projects::deleteRequirement($requirement, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['requirement' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('حُذف المتطلّب'), 'type' => 'success']);
    }

    /* ─────────────────────────── الفريق ─────────────────────────── */

    public function storeMember(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::PROJECT_MANAGE), 403);

        $project = $this->find($id);
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'role' => ['nullable', 'string', 'max:60'],
        ], [], ['user_id' => __('الموظّف')]);

        try {
            Projects::addMember($project, (int) $data['user_id'], $data['role'] ?? null, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['member' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('أُضيف إلى الفريق'), 'type' => 'success']);
    }

    public function destroyMember(int|string $id, int|string $mid)
    {
        abort_if(! auth()->user()?->may(Permissions::PROJECT_MANAGE), 403);

        $project = $this->find($id);
        $member = ProjectMember::where('project_id', $project->id)->findOrFail($mid);
        Projects::removeMember($member, auth()->user());

        return back()->with('toast', ['msg' => __('أُخرج من الفريق'), 'type' => 'success']);
    }

    /* ─────────────────────────── المهامّ ─────────────────────────── */

    /** @return array<string, mixed> */
    private function taskRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assignee_id' => ['nullable', 'integer'],
            'due_at' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(ProjectTask::PRIORITIES)],
            'status' => ['nullable', Rule::in(ProjectTask::STATUSES)],
        ];
    }

    public function storeTask(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::PROJECT_MANAGE), 403);

        $project = $this->find($id);
        $data = $request->validate($this->taskRules(), [], ['title' => __('المهمّة')]);

        try {
            Projects::addTask($project, $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['task' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('أُضيفت المهمّة'), 'type' => 'success']);
    }

    public function updateTask(Request $request, int|string $id, int|string $tid)
    {
        abort_if(! auth()->user()?->may(Permissions::PROJECT_MANAGE), 403);

        $project = $this->find($id);
        $task = ProjectTask::where('project_id', $project->id)->findOrFail($tid);
        $data = $request->validate($this->taskRules(), [], ['title' => __('المهمّة')]);

        try {
            Projects::updateTask($task, $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['task' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('حُفظت المهمّة'), 'type' => 'success']);
    }

    public function destroyTask(int|string $id, int|string $tid)
    {
        abort_if(! auth()->user()?->may(Permissions::PROJECT_MANAGE), 403);

        $project = $this->find($id);
        ProjectTask::where('project_id', $project->id)->findOrFail($tid)->delete();

        return back()->with('toast', ['msg' => __('حُذفت المهمّة'), 'type' => 'success']);
    }
}
