<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetReservation;
use App\Models\AutomationRule;
use App\Models\EventOperation;
use App\Models\EventTaskDispatch;
use App\Models\EventTaskLink;
use App\Models\OperationChecklist;
use App\Models\OperationChecklistItem;
use App\Models\OperationChecklistTemplate;
use App\Models\OperationCrewAssignment;
use App\Models\OperationEquipmentRequirement;
use App\Models\OperationIncident;
use App\Models\OperationOrganizer;
use App\Models\OperationScheduleItem;
use App\Models\Organizer;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Support\Automation\Rules;
use App\Support\Automation\Settings as AutomationSettings;
use App\Support\BranchAccess;
use App\Support\Demo;
use App\Support\InventoryForecast;
use App\Support\Operations\Assets;
use App\Models\OperationConsumableIssue;
use App\Support\Operations\Checklists;
use App\Support\Operations\Consumables;
use App\Support\Operations\Crew;
use App\Support\Operations\EventOperations;
use App\Support\Operations\EventTasks;
use App\Support\Operations\Incidents;
use App\Support\Operations\OperationOrganizers;
use App\Support\Operations\OperationReadiness;
use App\Support\Operations\Requirements;
use App\Support\Operations\Reservations;
use App\Support\Operations\ResourceConflicts;
use App\Support\Operations\Schedule;
use App\Support\Organizers;
use App\Support\Pagination;
use App\Support\Permissions;
use App\Support\Search;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * تشغيلُ الفعاليّات — الشاشات. المرحلة 10.
 *
 * ولا حسابَ يقع هنا: الدورةُ في `EventOperations`، والجاهزيّةُ في
 * `OperationReadiness`، والتعارضُ في `ResourceConflicts`، والحجزُ في
 * `Reservations`، والفريقُ في `Crew`، والقوائمُ في `Checklists`، والحوادثُ
 * في `Incidents`. ما في هذا الملفّ ثلاثة: يقرأ الطلب، ويسأل عن الإذن،
 * ويردّ شاشة.
 */
class EventOperationController extends Controller
{
    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    /** الفعاليّةُ من شركة الطالب لا من رقمٍ في الرابط */
    private function find(int|string $id): EventOperation
    {
        return EventOperation::where('business_id', $this->bid())->whereKey($id)->with('project.customer', 'lead')->firstOrFail();
    }

    /** @return array<string, bool> */
    private function may(): array
    {
        $user = auth()->user();

        return [
            'manage' => (bool) $user?->may(Permissions::OPERATIONS_MANAGE),
            'status' => (bool) $user?->may(Permissions::OPERATIONS_STATUS),
            'override' => (bool) $user?->may(Permissions::OPERATIONS_OVERRIDE),
            'incidents' => (bool) $user?->may(Permissions::OPERATIONS_INCIDENTS),
            'reserve' => (bool) $user?->may(Permissions::ASSETS_RESERVE),
            'checkout' => (bool) $user?->may(Permissions::ASSETS_CHECKOUT),
            'return' => (bool) $user?->may(Permissions::ASSETS_RETURN),
            'crew' => (bool) $user?->may(Permissions::CREW_MANAGE),
            'checklist' => (bool) $user?->may(Permissions::CHECKLIST_MANAGE),
            'assets' => (bool) $user?->allows('assets'),
        ];
    }

    private function gate(string $action): void
    {
        abort_if(! auth()->user()?->may($action), 403);
    }

    /* ─────────────────────────── القائمة ─────────────────────────── */

    public function index(Request $request): Response
    {
        $bid = $this->bid();
        $today = now()->startOfDay();

        /*
         * التقويمُ أوّلًا، والقائمةُ بابٌ ثانٍ.
         *
         * الفعاليّاتُ مواعيدُ قبل أن تكون صفوفًا: من يفتح «العمليات» يسأل
         * «ماذا عندنا هذا الشهر؟» — وجوابُه شبكةُ أيّام لا جدولٌ مرقَّم.
         * والبحثُ والحالُ يصفّيان الاثنين معًا.
         */
        $view = $request->string('view')->toString() === 'list' ? 'list' : 'calendar';
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month', '')) ? Carbon::createFromFormat('Y-m-d', $request->query('month').'-01')->startOfDay() : $today->copy()->startOfMonth();
        /* والشبكةُ أسابيعُ كاملة: من أحدِ الأسبوع الأوّل إلى سبتِ الأخير */
        $gridStart = $month->copy()->startOfWeek(Carbon::SUNDAY);
        $gridEnd = $month->copy()->endOfMonth()->endOfWeek(Carbon::SATURDAY);

        $filtered = fn () => EventOperation::where('business_id', $bid)->with('project:id,code,name', 'lead:id,name')
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('code', Search::like(), "%{$s}%")
                    ->orWhere('name', Search::like(), "%{$s}%")
                    ->orWhere('venue_name', Search::like(), "%{$s}%")
                    ->orWhereHas('project', fn ($p) => $p->where('code', Search::like(), "%{$s}%")->orWhere('name', Search::like(), "%{$s}%"))
            ))
            ->when($request->string('status')->toString(), fn ($q, $s) => in_array($s, EventOperation::STATUSES, true) ? $q->where('status', $s) : $q->whereRaw('1 = 0'));

        /* وما يقع في الشبكة: نافذتُه (التجهيزُ إلى الفكّ) تتقاطع معها */
        $calendar = $view === 'calendar'
            ? $filtered()
                ->whereNotNull('event_starts_at')
                ->where(fn ($w) => $w->where(fn ($x) => $x->whereNotNull('setup_starts_at')->where('setup_starts_at', '<=', $gridEnd))
                    ->orWhere(fn ($x) => $x->whereNull('setup_starts_at')->where('event_starts_at', '<=', $gridEnd)))
                ->where(fn ($w) => $w->where('teardown_ends_at', '>=', $gridStart)
                    ->orWhere(fn ($x) => $x->whereNull('teardown_ends_at')->where('event_ends_at', '>=', $gridStart))
                    ->orWhere(fn ($x) => $x->whereNull('teardown_ends_at')->whereNull('event_ends_at')->where('event_starts_at', '>=', $gridStart)))
                ->orderBy('event_starts_at')->limit(500)->get()
                ->map(fn (EventOperation $o) => $this->row($o) + [
                    'window_starts_at' => $o->windowStart()?->format('Y-m-d H:i'),
                    'window_ends_at' => $o->windowEnd()?->format('Y-m-d H:i'),
                ])->all()
            : [];
        /*
         * والفعاليّاتُ القادمة بجانب الشبكة — من اليوم فصاعدًا، لا من الشهر
         * المعروض: من تصفّح إلى شهرٍ مضى لا يفقد ما ينتظره. والجاريةُ الآن
         * منها، والمكتملةُ والملغاةُ ليست «قادمة».
         */
        $upcoming = $view === 'calendar'
            ? $filtered()
                ->whereNotNull('event_starts_at')
                ->whereNotIn('status', [EventOperation::COMPLETED, EventOperation::CANCELLED])
                ->where(fn ($w) => $w->where('event_ends_at', '>=', $today)
                    ->orWhere(fn ($x) => $x->whereNull('event_ends_at')->where('event_starts_at', '>=', $today)))
                ->orderBy('event_starts_at')->limit(30)->get()
                ->map(fn (EventOperation $o) => $this->row($o) + [
                    'window_starts_at' => $o->windowStart()?->format('Y-m-d H:i'),
                    'window_ends_at' => $o->windowEnd()?->format('Y-m-d H:i'),
                ])->all()
            : [];
        /* وما لا موعدَ له لا مكانَ له في الشبكة — يُعدّ ويُدلّ عليه في القائمة */
        $undated = $view === 'calendar' ? (int) $filtered()->whereNull('event_starts_at')->count() : 0;

        $page = EventOperation::where('business_id', $bid)->with('project:id,code,name', 'lead:id,name')
            ->when($view === 'calendar', fn ($q) => $q->whereRaw('1 = 0'))
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('code', Search::like(), "%{$s}%")
                    ->orWhere('name', Search::like(), "%{$s}%")
                    ->orWhere('venue_name', Search::like(), "%{$s}%")
                    ->orWhereHas('project', fn ($p) => $p->where('code', Search::like(), "%{$s}%")->orWhere('name', Search::like(), "%{$s}%"))
            ))
            ->when($request->string('status')->toString(), fn ($q, $s) => in_array($s, EventOperation::STATUSES, true) ? $q->where('status', $s) : $q->whereRaw('1 = 0'))
            ->when($request->string('window')->toString(), fn ($q, $w) => match ($w) {
                'today' => $q->whereBetween('event_starts_at', [$today, $today->copy()->endOfDay()]),
                'week' => $q->whereBetween('event_starts_at', [$today, $today->copy()->addDays(7)->endOfDay()]),
                'upcoming' => $q->where('event_starts_at', '>=', $today),
                'past' => $q->where('event_ends_at', '<', $today),
                default => $q,
            })
            ->orderByRaw('CASE WHEN event_starts_at IS NULL THEN 1 ELSE 0 END')->orderBy('event_starts_at')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        $base = fn () => EventOperation::where('business_id', $bid);

        return Inertia::render('Admin/Operations/Index', [
            'operations' => collect($page->items())->map(fn (EventOperation $o) => $this->row($o))->all(),
            'pagination' => Pagination::meta($page),
            'filters' => $request->only('q', 'status', 'window'),
            'view' => $view,
            'calendar' => [
                'month' => $month->format('Y-m'),
                'from' => $gridStart->toDateString(),
                'to' => $gridEnd->toDateString(),
                'today' => $today->toDateString(),
                'events' => $calendar,
                'upcoming' => $upcoming,
                'undated' => $undated,
            ],
            'statuses' => EventOperation::STATUSES,
            'projects' => Project::where('business_id', $bid)->open()->orderByDesc('id')->limit(200)->get(['id', 'code', 'name'])->all(),
            'totals' => [
                'live' => (int) $base()->where('status', EventOperation::LIVE)->count(),
                'today' => (int) $base()->active()->whereBetween('event_starts_at', [$today, $today->copy()->endOfDay()])->count(),
                'week' => (int) $base()->active()->whereBetween('event_starts_at', [$today, $today->copy()->addDays(7)->endOfDay()])->count(),
                'active' => (int) $base()->active()->count(),
            ],
            'labels' => $this->labels(),
            'may' => $this->may(),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(EventOperation $o): array
    {
        return [
            'id' => $o->id,
            'code' => $o->code,
            'name' => $o->name,
            'status' => $o->status,
            'project' => $o->project === null ? null : ['id' => $o->project->id, 'code' => $o->project->code, 'name' => $o->project->name],
            'venue_name' => $o->venue_name,
            'event_starts_at' => $o->event_starts_at?->format('Y-m-d H:i'),
            'event_ends_at' => $o->event_ends_at?->format('Y-m-d H:i'),
            'lead' => $o->lead?->name,
            'timezone' => $o->timezone,
        ];
    }

    public function store(Request $request)
    {
        $this->gate(Permissions::OPERATIONS_MANAGE);

        $data = $request->validate([
            'project_id' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'venue_name' => ['nullable', 'string', 'max:160'],
            'venue_address' => ['nullable', 'string', 'max:255'],
            'venue_notes' => ['nullable', 'string', 'max:5000'],
            'setup_starts_at' => ['nullable', 'date'],
            'doors_open_at' => ['nullable', 'date'],
            'event_starts_at' => ['nullable', 'date'],
            'event_ends_at' => ['nullable', 'date'],
            'teardown_starts_at' => ['nullable', 'date'],
            'teardown_ends_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'operations_lead_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:20000'],
        ]);

        $project = Project::where('business_id', $this->bid())->whereKey($data['project_id'])->firstOrFail();

        try {
            $operation = EventOperations::create($project, $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['operation' => $e->getMessage()]);
        }

        return redirect()->route('admin.operations.show', $operation->id)->with('toast', ['msg' => __('أُنشئت الفعاليّة :code', ['code' => $operation->code]), 'type' => 'success']);
    }

    /* ─────────────────────────── الشاشة ─────────────────────────── */

    public function show(int|string $id): Response
    {
        $operation = $this->find($id);
        $may = $this->may();
        $bid = $this->bid();

        $operation->load('requirements.product', 'reservations.asset', 'reservations.requirement', 'reservations.custodian', 'crew.user',
            'checklists.items.assignee', 'incidents.reporter', 'incidents.assignee', 'incidents.asset', 'scheduleItems.responsible', 'creator');

        $verdict = $operation->status === EventOperation::LIVE || $operation->status === EventOperation::SETUP
            ? OperationReadiness::live($operation) : OperationReadiness::for($operation);
        $from = $operation->windowStart();
        $until = $operation->windowEnd();

        $busy = $from !== null && $until !== null
            ? AssetReservation::where('business_id', $bid)->whereIn('status', AssetReservation::OCCUPYING)
                ->where('reserved_from', '<', $until)->where('reserved_until', '>', $from)->pluck('asset_id')->flip()
            : collect();

        return Inertia::render('Admin/Operations/Show', [
            'operation' => $this->detail($operation),
            'readiness' => $verdict->toArray(),
            'completion_blockers' => in_array($operation->status, [EventOperation::TEARDOWN, EventOperation::LIVE], true) ? EventOperations::completionBlockers($operation) : [],
            'conflicts' => ResourceConflicts::all($operation),
            'transitions' => EventOperations::allowedFrom($operation->status),
            'schedule' => $operation->scheduleItems->map(fn (OperationScheduleItem $i) => $this->scheduleRow($i))->all(),
            'requirements' => $operation->requirements->map(fn (OperationEquipmentRequirement $r) => $this->requirementRow($r))->all(),
            'reservations' => $operation->reservations->map(fn (AssetReservation $r) => $this->reservationRow($r))->all(),
            'crew' => $operation->crew->map(fn (OperationCrewAssignment $a) => $this->crewRow($a))->all(),
            'attendance' => Crew::attendance($operation),
            'checklists' => $operation->checklists->map(fn (OperationChecklist $c) => $this->checklistRow($c))->all(),
            'incidents' => $operation->incidents->map(fn (OperationIncident $i) => $this->incidentRow($i))->all(),
            /* المنظّمون — المرحلة 14: من على الفعاليّة، ومن يُختار منه، وتصنيفاتُهم */
            'organizers' => OperationOrganizer::where('event_operation_id', $operation->id)->with(['organizer:id,name,phone,whatsapp,gender', 'organizer.categories:id,name', 'category:id,name'])
                ->orderBy('starts_at')->orderBy('id')->get()->map(fn (OperationOrganizer $a) => $this->organizerRow($a))->all(),
            'organizer_options' => Organizer::where('business_id', $bid)->where('status', Organizer::AVAILABLE)->with('categories:id,name')
                ->orderBy('name')->limit(500)->get()
                ->map(fn (Organizer $o) => ['id' => $o->id, 'name' => $o->name, 'gender' => $o->gender, 'phone' => $o->phone,
                    'categories' => $o->categories->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()->all()])->all(),
            'users' => User::where('business_id', $bid)->where('status', 'نشط')->orderBy('name')->get(['id', 'name'])->all(),
            /* مهامُّ الفعاليّة لكلّ موظّف — `ProjectTask` نفسُها، وما أُرسل إلى كلٍّ منهم ورابطُه لمن يدير الفريق */
            'event_tasks' => $this->eventTasks($operation, (bool) auth()->user()?->may(Permissions::CREW_MANAGE)),
            'project_members' => $operation->project?->members()->with('user:id,name')->get()->map(fn ($m) => ['user_id' => $m->user_id, 'name' => $m->user?->name, 'role' => $m->role_in_project])->all() ?? [],
            'templates' => OperationChecklistTemplate::where('business_id', $bid)->where('active', true)->orderBy('sort_order')->get(['id', 'name', 'phase'])->all(),
            'products' => Product::where('business_id', $bid)->orderBy('name')->limit(500)->get(['id', 'name', 'kind', 'quantity'])->all(),
            /* المستهلَكاتُ — المرحلة 11: ما صُرف وما عاد وما استُهلك بتكلفته */
            'consumables' => Consumables::summary($operation),
            /* وتوقّعُ مخزون مشروعها — المتوفّرُ وما قبله في الخطّ واحتياجُها والمتوقَّع؛ لمن يفتح المخزون */
            'inventory_forecast' => auth()->user()?->allows('inventory') ? InventoryForecast::forProject($bid, (int) $operation->project_id) : null,
            'categories' => $this->categories($bid),
            'assets' => Asset::where('business_id', $bid)->whereNotIn('status', Asset::OUT_OF_SERVICE)->orderBy('asset_code')->limit(500)->get()
                ->map(fn (Asset $a) => ['id' => $a->id, 'code' => $a->asset_code, 'name' => $a->name, 'category' => $a->category, 'status' => $a->status, 'free' => ! isset($busy[$a->id])])->all(),
            'labels' => $this->labels(),
            'may' => $may,
        ]);
    }

    /** شاشةُ الموقع — للهاتف: ماذا الآن، ومن حضر، وما خرج، وما ينقص، وما جرى */
    public function onsite(int|string $id): Response
    {
        $operation = $this->find($id);
        $operation->load('reservations.asset', 'reservations.requirement', 'reservations.custodian', 'crew.user', 'checklists.items', 'incidents.reporter', 'scheduleItems.responsible', 'requirements');

        $now = Schedule::now($operation);
        $verdict = $operation->status === EventOperation::LIVE || $operation->status === EventOperation::SETUP || $operation->status === EventOperation::READY
            ? OperationReadiness::live($operation) : OperationReadiness::for($operation);

        $criticalItems = [];
        foreach ($operation->checklists as $c) {
            foreach ($c->items as $i) {
                if ($i->required && ! $i->isSettled()) {
                    $criticalItems[] = $this->checklistItemRow($i) + ['checklist' => $c->name, 'phase' => $c->phase];
                }
            }
        }

        return Inertia::render('Admin/Operations/OnSite', [
            'operation' => $this->detail($operation),
            'relative' => EventOperations::relativeNow($operation),
            'readiness' => ['ready' => $verdict->ready, 'blockers' => $verdict->blockers, 'warnings' => count($verdict->warnings)],
            'completion_blockers' => $operation->status === EventOperation::TEARDOWN ? EventOperations::completionBlockers($operation) : [],
            'transitions' => EventOperations::allowedFrom($operation->status),
            'now' => [
                'current' => array_map(fn ($i) => $this->scheduleRow($i), $now['current']),
                'next' => array_map(fn ($i) => $this->scheduleRow($i), $now['next']),
                'overdue' => array_map(fn ($i) => $this->scheduleRow($i), $now['overdue']),
            ],
            'schedule' => $operation->scheduleItems->map(fn (OperationScheduleItem $i) => $this->scheduleRow($i))->all(),
            'crew' => $operation->crew->filter(fn (OperationCrewAssignment $a) => $a->status !== OperationCrewAssignment::CANCELLED)->values()->map(fn ($a) => $this->crewRow($a))->all(),
            'attendance' => Crew::attendance($operation),
            'equipment' => [
                'rows' => $operation->reservations->filter(fn (AssetReservation $r) => $r->status !== AssetReservation::CANCELLED)->values()->map(fn ($r) => $this->reservationRow($r))->all(),
                'expected' => $operation->reservations->filter(fn (AssetReservation $r) => $r->status !== AssetReservation::CANCELLED)->count(),
                'checked_out' => $operation->reservations->where('status', AssetReservation::CHECKED_OUT)->count(),
                'returned' => $operation->reservations->where('status', AssetReservation::RETURNED)->count(),
                'problems' => $operation->reservations->filter(fn (AssetReservation $r) => $r->asset?->isOutOfService() ?? false)->count(),
                'gaps' => Requirements::gaps($operation),
            ],
            'checklist' => ['progress' => Checklists::progress($operation), 'critical' => $criticalItems],
            'incidents' => $operation->incidents->filter(fn (OperationIncident $i) => $i->isUnresolved())->values()->map(fn ($i) => $this->incidentRow($i))->all(),
            'contacts' => array_values(array_filter([
                $operation->lead ? ['label' => __('قائد العمليات'), 'name' => $operation->lead->name, 'phone' => $operation->lead->phone ?? null] : null,
                $operation->project?->contact_person ? ['label' => __('جهة اتصال العميل'), 'name' => $operation->project->contact_person, 'phone' => $operation->project->contact_phone] : null,
                $operation->project?->owner ? ['label' => __('صاحب المشروع'), 'name' => $operation->project->owner->name, 'phone' => $operation->project->owner->phone ?? null] : null,
            ])),
            'labels' => $this->labels(),
            'may' => $this->may(),
        ]);
    }

    /* ─────────────────────────── الكتابة: الفعاليّة ─────────────────────────── */

    public function update(Request $request, int|string $id)
    {
        $this->gate(Permissions::OPERATIONS_MANAGE);
        $operation = $this->find($id);

        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'venue_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'venue_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'venue_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'setup_starts_at' => ['sometimes', 'nullable', 'date'],
            'doors_open_at' => ['sometimes', 'nullable', 'date'],
            'event_starts_at' => ['sometimes', 'nullable', 'date'],
            'event_ends_at' => ['sometimes', 'nullable', 'date'],
            'teardown_starts_at' => ['sometimes', 'nullable', 'date'],
            'teardown_ends_at' => ['sometimes', 'nullable', 'date'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'operations_lead_id' => ['sometimes', 'nullable', 'integer'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ]);

        return $this->attempt(fn () => EventOperations::update($operation, $data, auth()->user()), __('حُفظت الفعاليّة'), 'operation');
    }

    public function transition(Request $request, int|string $id)
    {
        $this->gate(Permissions::OPERATIONS_STATUS);
        $operation = $this->find($id);
        $data = $request->validate([
            'status' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:255'],
            'override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->attempt(fn () => EventOperations::transition($operation, $data['status'], auth()->user(), $data['reason'] ?? null, $data['override_reason'] ?? null),
            __('نُقلت الفعاليّة إلى «:status»', ['status' => __(EventOperations::statusLabel($data['status']))]), 'operation');
    }

    public function complete(int|string $id)
    {
        $this->gate(Permissions::OPERATIONS_STATUS);
        $operation = $this->find($id);

        return $this->attempt(fn () => EventOperations::complete($operation, auth()->user()), __('أُتمّت الفعاليّة'), 'operation');
    }

    public function cancel(Request $request, int|string $id)
    {
        $this->gate(Permissions::OPERATIONS_MANAGE);
        $operation = $this->find($id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => EventOperations::cancel($operation, $data['reason'], auth()->user()), __('أُلغيت الفعاليّة'), 'operation');
    }

    /* ─────────────────────────── الجدول ─────────────────────────── */

    public function storeScheduleItem(Request $request, int|string $id)
    {
        $this->gate(Permissions::OPERATIONS_MANAGE);
        $operation = $this->find($id);
        $data = $this->scheduleData($request);

        return $this->attempt(fn () => Schedule::add($operation, $data, auth()->user()), __('أُضيف البند'), 'schedule');
    }

    public function updateScheduleItem(Request $request, int|string $id, int|string $sid)
    {
        $this->gate(Permissions::OPERATIONS_MANAGE);
        $operation = $this->find($id);
        $item = OperationScheduleItem::where('event_operation_id', $operation->id)->whereKey($sid)->firstOrFail();
        $data = $this->scheduleData($request);

        return $this->attempt(fn () => Schedule::update($item, $data, auth()->user()), __('حُفظ البند'), 'schedule');
    }

    public function destroyScheduleItem(int|string $id, int|string $sid)
    {
        $this->gate(Permissions::OPERATIONS_MANAGE);
        $operation = $this->find($id);
        $item = OperationScheduleItem::where('event_operation_id', $operation->id)->whereKey($sid)->firstOrFail();

        return $this->attempt(fn () => Schedule::remove($item, auth()->user()), __('حُذف البند'), 'schedule');
    }

    /** من شاشة الموقع — حالُ بندٍ؛ لمن يفتح القسم */
    public function scheduleStatus(Request $request, int|string $id, int|string $sid)
    {
        $operation = $this->find($id);
        $item = OperationScheduleItem::where('event_operation_id', $operation->id)->whereKey($sid)->firstOrFail();
        $data = $request->validate(['status' => ['required', 'string']]);

        return $this->attempt(fn () => Schedule::setStatus($item, $data['status'], auth()->user()), null, 'schedule');
    }

    /** @return array<string, mixed> */
    private function scheduleData(Request $request): array
    {
        return $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'type' => ['sometimes', 'nullable', 'string', 'max:20'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'responsible_user_id' => ['sometimes', 'nullable', 'integer'],
            'responsible_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'sort_order' => ['sometimes', 'nullable', 'integer'],
        ]);
    }

    /* ─────────────────────────── المتطلّبات ─────────────────────────── */

    public function storeRequirement(Request $request, int|string $id)
    {
        $this->gate(Permissions::OPERATIONS_MANAGE);
        $operation = $this->find($id);
        $data = $this->requirementData($request);

        return $this->attempt(fn () => Requirements::add($operation, $data, auth()->user()), __('أُضيف المتطلَّب'), 'requirement');
    }

    public function updateRequirement(Request $request, int|string $id, int|string $rid)
    {
        $this->gate(Permissions::OPERATIONS_MANAGE);
        $operation = $this->find($id);
        $requirement = OperationEquipmentRequirement::where('event_operation_id', $operation->id)->whereKey($rid)->firstOrFail();
        $data = $this->requirementData($request);

        return $this->attempt(fn () => Requirements::update($requirement, $data, auth()->user()), __('حُفظ المتطلَّب'), 'requirement');
    }

    public function destroyRequirement(int|string $id, int|string $rid)
    {
        $this->gate(Permissions::OPERATIONS_MANAGE);
        $operation = $this->find($id);
        $requirement = OperationEquipmentRequirement::where('event_operation_id', $operation->id)->whereKey($rid)->firstOrFail();

        return $this->attempt(fn () => Requirements::remove($requirement, auth()->user()), __('حُذف المتطلَّب'), 'requirement');
    }

    /** @return array<string, mixed> */
    private function requirementData(Request $request): array
    {
        return $request->validate([
            'kind' => ['sometimes', 'nullable', 'string', 'max:20'],
            'asset_category' => ['sometimes', 'nullable', 'string', 'max:60'],
            'product_id' => ['sometimes', 'nullable', 'integer'],
            'description' => ['sometimes', 'nullable', 'string', 'max:200'],
            'required_quantity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'assigned_quantity' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'source' => ['sometimes', 'nullable', 'string', 'max:20'],
            'critical' => ['sometimes', 'boolean'],
            'backup' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
    }

    /* ─────────────────────────── الحجز ─────────────────────────── */

    public function reserve(Request $request, int|string $id)
    {
        $this->gate(Permissions::ASSETS_RESERVE);
        $operation = $this->find($id);
        $data = $request->validate([
            'asset_id' => ['required', 'integer'],
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date'],
            'requirement_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $asset = Asset::where('business_id', $this->bid())->whereKey($data['asset_id'])->firstOrFail();

        return $this->attempt(fn () => Reservations::reserve($operation, $asset, $data, auth()->user()), __('حُجزت القطعة :code', ['code' => $asset->asset_code]), 'reservation');
    }

    public function cancelReservation(Request $request, int|string $id, int|string $rid)
    {
        $this->gate(Permissions::ASSETS_RESERVE);
        $reservation = $this->reservation($id, $rid);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return $this->attempt(fn () => Reservations::cancel($reservation, $data['reason'] ?? null, auth()->user()), __('أُلغي الحجز'), 'reservation');
    }

    public function checkOut(Request $request, int|string $id, int|string $rid)
    {
        $this->gate(Permissions::ASSETS_CHECKOUT);
        $reservation = $this->reservation($id, $rid);
        $data = $request->validate([
            'custodian_id' => ['nullable', 'integer'],
            'condition' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->attempt(fn () => Reservations::checkOut($reservation, $data, auth()->user()), __('سُلّمت القطعة'), 'reservation');
    }

    public function giveBack(Request $request, int|string $id, int|string $rid)
    {
        $this->gate(Permissions::ASSETS_RETURN);
        $reservation = $this->reservation($id, $rid);
        $data = $request->validate([
            'condition' => ['required', 'string', 'max:20'],
            'missing_accessories' => ['nullable', 'string', 'max:2000'],
            'damage_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->attempt(fn () => Reservations::giveBack($reservation, $data, auth()->user()), __('رُدّت القطعة'), 'reservation');
    }

    private function reservation(int|string $id, int|string $rid): AssetReservation
    {
        $operation = $this->find($id);

        return AssetReservation::where('event_operation_id', $operation->id)->whereKey($rid)->with('asset', 'operation', 'requirement')->firstOrFail();
    }

    /* ─────────────────────────── المستهلَكات — المرحلة 11 ─────────────────────────── */

    /** الصرفُ من الرفّ — بإذن التسليم؛ ومن باب المخزون نفسِه */
    public function issueConsumable(Request $request, int|string $id)
    {
        $this->gate(Permissions::ASSETS_CHECKOUT);
        $operation = $this->find($id);
        $data = $request->validate([
            'product_id' => ['required', 'integer'], 'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'requirement_id' => ['nullable', 'integer'], 'branch_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string', 'max:2000'], 'key' => ['nullable', 'string', 'max:40'],
        ]);
        $product = Product::where('business_id', $this->bid())->whereKey($data['product_id'])->firstOrFail();

        return $this->attempt(fn () => Consumables::issue($operation, $product, $data, auth()->user()), __('صُرف :n × :p', ['n' => $data['quantity'], 'p' => $product->name]), 'consumable');
    }

    public function returnConsumable(Request $request, int|string $id, int|string $cid)
    {
        $this->gate(Permissions::ASSETS_RETURN);
        $operation = $this->find($id);
        $issue = OperationConsumableIssue::where('event_operation_id', $operation->id)->whereKey($cid)->with('operation', 'requirement')->firstOrFail();
        // والإرجاعُ يُعيد الكميّة إلى فرع الصرف — فلا يُرجِع من قُيّد بفروعٍ إلى فرعٍ ليس من فروعه
        abort_if(
            BranchAccess::restricts(auth()->user()) && ! BranchAccess::canAccess(auth()->user(), $issue->branch_id),
            403,
            __('هذا الفرع ليس من فروع عملك.'),
        );
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:0', 'max:1000000'], 'notes' => ['nullable', 'string', 'max:2000']]);

        return $this->attempt(fn () => Consumables::giveBack($issue, $data, auth()->user()), __('أُقفل الصرف'), 'consumable');
    }

    /* ─────────────────────────── الفريق ─────────────────────────── */

    public function storeCrew(Request $request, int|string $id)
    {
        $this->gate(Permissions::CREW_MANAGE);
        $operation = $this->find($id);
        $data = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'external_name' => ['nullable', 'string', 'max:120'],
            'external_phone' => ['nullable', 'string', 'max:40'],
            'role' => ['required', 'string', 'max:40'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'briefing_status' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->attempt(fn () => Crew::assign($operation, $data, auth()->user()), __('أُضيف إلى الفريق'), 'crew');
    }

    public function updateCrew(Request $request, int|string $id, int|string $cid)
    {
        $this->gate(Permissions::CREW_MANAGE);
        $assignment = $this->crew($id, $cid);
        $data = $request->validate([
            'role' => ['sometimes', 'nullable', 'string', 'max:40'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'nullable', 'string', 'max:20'],
            'briefing_status' => ['sometimes', 'nullable', 'string', 'max:20'],
            'external_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return $this->attempt(fn () => Crew::update($assignment, $data, auth()->user()), __('حُفظ التعيين'), 'crew');
    }

    /* ─────────────────────────── المنظّمون ─────────────────────────── */

    /** تعيينُ منظّمٍ على الفعاليّة — بفعل الفريق نفسِه، والتداخلُ يُردّ كما يُردّ للموظّف */
    public function storeOrganizer(Request $request, int|string $id)
    {
        $this->gate(Permissions::CREW_MANAGE);
        $operation = $this->find($id);
        $data = $request->validate([
            'organizer_id' => ['required', 'integer'],
            'organizer_category_id' => ['nullable', 'integer'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->attempt(fn () => OperationOrganizers::assign($operation, $data, auth()->user()), __('عُيّن المنظّم على الفعاليّة'), 'organizers');
    }

    public function updateOrganizer(Request $request, int|string $id, int|string $oid)
    {
        $this->gate(Permissions::CREW_MANAGE);
        $operation = $this->find($id);
        $assignment = OperationOrganizer::where('business_id', $this->bid())->where('event_operation_id', $operation->id)->whereKey($oid)->firstOrFail();
        $data = $request->validate([
            'organizer_category_id' => ['sometimes', 'nullable', 'integer'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'required', 'string', 'max:20'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return $this->attempt(fn () => OperationOrganizers::update($assignment, $data, auth()->user()), __('حُفظ تعيين المنظّم'), 'organizers');
    }

    /**
     * «نشر المهام على الفريق» — مرساةُ رسالة كلّ موظّف في الأتمتة.
     *
     * لا يُرسل من هنا: قاعدةُ «إرسال مهام الفعاليّة» تُرسل في دورتها القادمة
     * بوضعها (مراقبة/تنفيذ) وقناتها. والروابطُ تُنشأ الآن فتُنسخ بيد.
     */
    public function publishTasks(int|string $id)
    {
        $this->gate(Permissions::CREW_MANAGE);
        $operation = $this->find($id);

        return $this->attempt(fn () => EventTasks::publish($operation, auth()->user()), __('نُشرت المهام — تُرسل لكلّ موظّف في دورة الأتمتة القادمة'), 'tasks');
    }

    /** تجديدُ رابط مهامّ موظّف — القديمُ لا يفتح بعده */
    public function rotateTaskLink(int|string $id, int|string $userId)
    {
        $this->gate(Permissions::CREW_MANAGE);
        $operation = $this->find($id);
        $user = User::where('business_id', $this->bid())->whereKey($userId)->firstOrFail();

        return $this->attempt(fn () => EventTasks::rotate($operation, $user, auth()->user()), __('جُدّد الرابط — الرابط القديم لم يعد يفتح'), 'tasks');
    }

    /** مسؤولُ المنظّمين — موظّفٌ من الشركة، أو لا أحد */
    public function organizersLead(Request $request, int|string $id)
    {
        $this->gate(Permissions::CREW_MANAGE);
        $operation = $this->find($id);
        $data = $request->validate(['user_id' => ['nullable', 'integer']]);

        return $this->attempt(fn () => OperationOrganizers::setLead($operation, $data['user_id'] ?? null, auth()->user()), __('حُفظ مسؤول المنظّمين'), 'organizers');
    }

    public function destroyCrew(int|string $id, int|string $cid)
    {
        $this->gate(Permissions::CREW_MANAGE);
        $assignment = $this->crew($id, $cid);

        return $this->attempt(fn () => Crew::remove($assignment, auth()->user()), __('أُزيل من الفريق'), 'crew');
    }

    /** الحضورُ من شاشة الموقع — لمن يفتح القسم */
    public function crewCheckIn(int|string $id, int|string $cid)
    {
        $assignment = $this->crew($id, $cid);

        return $this->attempt(fn () => Crew::checkIn($assignment, auth()->user()), __('سُجّل الحضور'), 'crew');
    }

    public function crewCheckOut(int|string $id, int|string $cid)
    {
        $assignment = $this->crew($id, $cid);

        return $this->attempt(fn () => Crew::checkOut($assignment, auth()->user()), __('سُجّل الانصراف'), 'crew');
    }

    public function notifyCrew(int|string $id, int|string $cid)
    {
        $this->gate(Permissions::CREW_MANAGE);
        $assignment = $this->crew($id, $cid);

        return $this->attempt(fn () => Crew::notify($assignment, auth()->user()), __('بُلّغ الموظّف'), 'crew');
    }

    /** تنبيهٌ إلى الفريق كلِّه — لمن ينقل الحال (يقف في الموقع) أو يدير الفريق */
    public function alertCrew(Request $request, int|string $id)
    {
        abort_if(! (auth()->user()?->may(Permissions::CREW_MANAGE) || auth()->user()?->may(Permissions::OPERATIONS_STATUS)), 403);
        $operation = $this->find($id);
        $data = $request->validate(['message' => ['required', 'string', 'max:2000']]);

        return $this->attempt(fn () => Crew::alert($operation, $data['message'], auth()->user()), __('نُبّه الفريق'), 'crew');
    }

    private function crew(int|string $id, int|string $cid): OperationCrewAssignment
    {
        $operation = $this->find($id);

        return OperationCrewAssignment::where('event_operation_id', $operation->id)->whereKey($cid)->with('user', 'operation.project')->firstOrFail();
    }

    /* ─────────────────────────── قوائمُ التحقّق ─────────────────────────── */

    public function attachChecklist(Request $request, int|string $id)
    {
        $this->gate(Permissions::CHECKLIST_MANAGE);
        $operation = $this->find($id);
        $data = $request->validate(['template_id' => ['required', 'integer']]);
        $template = OperationChecklistTemplate::where('business_id', $this->bid())->whereKey($data['template_id'])->with('items')->firstOrFail();

        return $this->attempt(fn () => Checklists::attach($operation, $template, auth()->user()), __('أُلحقت القائمة'), 'checklist');
    }

    public function storeChecklistItem(Request $request, int|string $id, int|string $clid)
    {
        $this->gate(Permissions::CHECKLIST_MANAGE);
        $operation = $this->find($id);
        $checklist = OperationChecklist::where('event_operation_id', $operation->id)->whereKey($clid)->firstOrFail();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'required' => ['sometimes', 'boolean'],
            'assigned_user_id' => ['nullable', 'integer'],
            'due_at' => ['nullable', 'date'],
        ]);

        return $this->attempt(fn () => Checklists::addItem($checklist, $data, auth()->user()), __('أُضيف البند'), 'checklist');
    }

    /** الإنجازُ من الشاشة أو الموقع — لمن يفتح القسم */
    public function completeChecklistItem(Request $request, int|string $id, int|string $iid)
    {
        $item = $this->checklistItem($id, $iid);
        $data = $request->validate(['evidence' => ['nullable', 'string', 'max:2000']]);

        return $this->attempt(fn () => Checklists::complete($item, $data['evidence'] ?? null, auth()->user()), null, 'checklist');
    }

    public function reopenChecklistItem(int|string $id, int|string $iid)
    {
        $item = $this->checklistItem($id, $iid);

        return $this->attempt(fn () => Checklists::reopen($item, auth()->user()), null, 'checklist');
    }

    public function blockChecklistItem(Request $request, int|string $id, int|string $iid)
    {
        $item = $this->checklistItem($id, $iid);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => Checklists::block($item, $data['reason'], auth()->user()), null, 'checklist');
    }

    public function waiveChecklistItem(Request $request, int|string $id, int|string $iid)
    {
        $this->gate(Permissions::CHECKLIST_MANAGE);
        $item = $this->checklistItem($id, $iid);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => Checklists::waive($item, $data['reason'], auth()->user()), __('أُعفي البند'), 'checklist');
    }

    private function checklistItem(int|string $id, int|string $iid): OperationChecklistItem
    {
        $operation = $this->find($id);

        return OperationChecklistItem::where('business_id', $operation->business_id)->whereKey($iid)
            ->whereHas('checklist', fn ($q) => $q->where('event_operation_id', $operation->id))->with('checklist.operation')->firstOrFail();
    }

    /* ─────────────────────────── القوالب ─────────────────────────── */

    public function templates(): Response
    {
        $bid = $this->bid();
        Checklists::ensure($bid);

        return Inertia::render('Admin/Operations/Templates', [
            'templates' => OperationChecklistTemplate::where('business_id', $bid)->with('items')->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (OperationChecklistTemplate $t) => [
                    'id' => $t->id, 'name' => $t->name, 'phase' => $t->phase, 'description' => $t->description, 'active' => (bool) $t->active,
                    'items' => $t->items->map(fn ($i) => ['id' => $i->id, 'title' => $i->title, 'required' => (bool) $i->required])->all(),
                ])->all(),
            'phases' => OperationChecklistTemplate::PHASES,
            'labels' => $this->labels(),
            'may' => $this->may(),
        ]);
    }

    public function storeTemplate(Request $request)
    {
        $this->gate(Permissions::CHECKLIST_MANAGE);
        $data = $this->templateData($request);

        return $this->attempt(fn () => Checklists::createTemplate($this->bid(), $data, auth()->user()), __('أُنشئ القالب'), 'template');
    }

    public function updateTemplate(Request $request, int|string $tid)
    {
        $this->gate(Permissions::CHECKLIST_MANAGE);
        $template = OperationChecklistTemplate::where('business_id', $this->bid())->whereKey($tid)->firstOrFail();
        $data = $this->templateData($request);

        return $this->attempt(fn () => Checklists::updateTemplate($template, $data, auth()->user()), __('حُفظ القالب'), 'template');
    }

    public function destroyTemplate(int|string $tid)
    {
        $this->gate(Permissions::CHECKLIST_MANAGE);
        $template = OperationChecklistTemplate::where('business_id', $this->bid())->whereKey($tid)->firstOrFail();

        return $this->attempt(fn () => Checklists::deleteTemplate($template, auth()->user()), __('حُذف القالب'), 'template');
    }

    /** @return array<string, mixed> */
    private function templateData(Request $request): array
    {
        return $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'phase' => ['sometimes', 'nullable', 'string', 'max:20'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
            'items' => ['sometimes', 'array', 'max:100'],
            'items.*.title' => ['required_with:items', 'string', 'max:200'],
            'items.*.required' => ['sometimes', 'boolean'],
        ]);
    }

    /* ─────────────────────────── الحوادث ─────────────────────────── */

    public function storeIncident(Request $request, int|string $id)
    {
        $this->gate(Permissions::OPERATIONS_INCIDENTS);
        $operation = $this->find($id);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:30'],
            'severity' => ['nullable', 'string', 'max:10'],
            'description' => ['nullable', 'string', 'max:5000'],
            'occurred_at' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer'],
            'asset_id' => ['nullable', 'integer'],
        ]);

        return $this->attempt(fn () => Incidents::report($operation, $data, auth()->user()), __('سُجّل الحادث'), 'incident');
    }

    public function updateIncident(Request $request, int|string $id, int|string $iid)
    {
        $this->gate(Permissions::OPERATIONS_INCIDENTS);
        $incident = $this->incident($id, $iid);
        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'category' => ['sometimes', 'nullable', 'string', 'max:30'],
            'severity' => ['sometimes', 'nullable', 'string', 'max:10'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'assigned_to' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        return $this->attempt(fn () => Incidents::update($incident, $data, auth()->user()), __('حُفظ الحادث'), 'incident');
    }

    public function resolveIncident(Request $request, int|string $id, int|string $iid)
    {
        $this->gate(Permissions::OPERATIONS_INCIDENTS);
        $incident = $this->incident($id, $iid);
        $data = $request->validate(['resolution' => ['required', 'string', 'max:5000']]);

        return $this->attempt(fn () => Incidents::resolve($incident, $data['resolution'], auth()->user()), __('حُلّ الحادث'), 'incident');
    }

    public function closeIncident(int|string $id, int|string $iid)
    {
        $this->gate(Permissions::OPERATIONS_INCIDENTS);
        $incident = $this->incident($id, $iid);

        return $this->attempt(fn () => Incidents::close($incident, auth()->user()), __('أُقفل الحادث'), 'incident');
    }

    public function reopenIncident(int|string $id, int|string $iid)
    {
        $this->gate(Permissions::OPERATIONS_INCIDENTS);
        $incident = $this->incident($id, $iid);

        return $this->attempt(fn () => Incidents::reopen($incident, auth()->user()), __('أُعيد فتح الحادث'), 'incident');
    }

    public function carryForwardIncident(Request $request, int|string $id, int|string $iid)
    {
        $this->gate(Permissions::OPERATIONS_STATUS);
        $incident = $this->incident($id, $iid);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => Incidents::carryForward($incident, $data['reason'], auth()->user()), __('أُقرّ بحمل الحادث'), 'incident');
    }

    private function incident(int|string $id, int|string $iid): OperationIncident
    {
        $operation = $this->find($id);

        return OperationIncident::where('event_operation_id', $operation->id)->whereKey($iid)->with('operation')->firstOrFail();
    }

    /* ─────────────────────────── الصفوف ─────────────────────────── */

    /** @return array<string, mixed> */
    /**
     * مهامُّ الفعاليّة مجموعةً لكلّ موظّف — بحالها الآن، وآخرِ ما أُرسل إليه.
     *
     * والرابطُ لمن يدير الفريق وحدَه، ومن رابطٍ أُنشئ عند النشر: الشاشةُ لا
     * تُنشئ روابطَ بمجرّد فتحها.
     *
     * @return array<string, mixed>
     */
    private function eventTasks(EventOperation $operation, bool $manager): array
    {
        $tasks = ProjectTask::where('business_id', $operation->business_id)->where('event_operation_id', $operation->id)
            ->with('assignee:id,name,phone')->orderBy('status')->orderBy('due_at')->orderBy('id')->get();
        $links = $manager
            ? EventTaskLink::where('event_operation_id', $operation->id)->get()->keyBy('user_id')
            : collect();
        $dispatches = EventTaskDispatch::where('event_operation_id', $operation->id)->orderByDesc('id')->get()->groupBy('user_id');

        $people = $tasks->groupBy(fn (ProjectTask $t) => (int) $t->assignee_id)->map(function ($group, $userId) use ($links, $dispatches) {
            $user = $group->first()->assignee;
            $last = $dispatches->get($userId)?->first();
            $link = $links->get($userId);

            return [
                'user_id' => $userId ?: null,
                'name' => $user?->name,
                'has_phone' => filled($user?->phone),
                'open' => $group->where('status', ProjectTask::OPEN)->count(),
                'done' => $group->where('status', ProjectTask::DONE)->count(),
                'tasks' => $group->map(fn (ProjectTask $t) => [
                    'id' => $t->id, 'title' => $t->title, 'status' => $t->status, 'priority' => $t->priority,
                    'due_at' => optional($t->due_at)->format('Y-m-d'), 'completed_at' => optional($t->completed_at)->format('Y-m-d H:i'),
                ])->values()->all(),
                'last_dispatch' => $last === null ? null : [
                    'trigger' => $last->trigger, 'status' => $last->status, 'channel' => $last->channel,
                    'open_count' => $last->open_count, 'failure' => $last->failure, 'at' => optional($last->sent_at ?? $last->created_at)->format('Y-m-d H:i'),
                ],
                'link' => $link !== null && $link->isUsable() ? route('event-tasks.show', (string) $link->token) : null,
            ];
        })->sortBy(fn ($row) => $row['user_id'] === null ? 1 : 0)->values()->all();

        /* وقاعدتا الإرسال تُنشآن إن لم تكونا — ليقرأ التبويبُ وضعَهما الحقيقيّ */
        Rules::ensure((int) $operation->business_id);

        return [
            'published_at' => optional($operation->tasks_published_at)->format('Y-m-d H:i'),
            'people' => $people,
            'rules' => AutomationRule::where('business_id', $operation->business_id)
                ->whereIn('trigger', [EventTasks::ASSIGNED, EventTasks::REMINDER])->get(['trigger', 'enabled', 'mode', 'channel'])
                ->map(fn (AutomationRule $r) => ['trigger' => $r->trigger, 'enabled' => (bool) $r->enabled, 'mode' => $r->mode, 'channel' => $r->channel])->values()->all(),
            'automation_mode' => AutomationSettings::for((int) $operation->business_id)->mode,
        ];
    }

    private function organizerRow(OperationOrganizer $a): array
    {
        return [
            'id' => $a->id,
            'organizer_id' => $a->organizer_id,
            'name' => $a->organizer?->name,
            'phone' => $a->organizer?->whatsapp ?: $a->organizer?->phone,
            'gender' => $a->organizer?->gender,
            'category_id' => $a->organizer_category_id,
            'category' => $a->category?->name,
            /* ما يُختار منه تصنيفُ هذا التعيين — تصنيفاتُ المنظّم في ملفّه (`OperationOrganizers::categoryOf`) */
            'categories' => $a->organizer?->categories->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()->all() ?? [],
            'starts_at' => $a->starts_at?->format('Y-m-d H:i'),
            'ends_at' => $a->ends_at?->format('Y-m-d H:i'),
            'status' => $a->status,
            'notes' => $a->notes,
        ];
    }

    /** @return array<string, mixed> */
    private function detail(EventOperation $o): array
    {
        return $this->row($o) + [
            'venue_address' => $o->venue_address,
            'venue_notes' => $o->venue_notes,
            'setup_starts_at' => $o->setup_starts_at?->format('Y-m-d H:i'),
            'doors_open_at' => $o->doors_open_at?->format('Y-m-d H:i'),
            'teardown_starts_at' => $o->teardown_starts_at?->format('Y-m-d H:i'),
            'teardown_ends_at' => $o->teardown_ends_at?->format('Y-m-d H:i'),
            'operations_lead_id' => $o->operations_lead_id,
            'organizers_lead_user_id' => $o->organizers_lead_user_id,
            'notes' => $o->notes,
            'readiness_override' => $o->readiness_override,
            'went_live_at' => $o->went_live_at?->format('Y-m-d H:i'),
            'completed_at' => $o->completed_at?->format('Y-m-d H:i'),
            'cancelled_at' => $o->cancelled_at?->format('Y-m-d H:i'),
            'cancellation_reason' => $o->cancellation_reason,
            'customer' => $o->project?->customer?->name,
            'project_status' => $o->project?->status,
            'created_by' => $o->creator?->name,
            'active' => $o->isActive(),
        ];
    }

    /** @return array<string, mixed> */
    private function scheduleRow(OperationScheduleItem $i): array
    {
        return [
            'id' => $i->id, 'title' => $i->title, 'description' => $i->description, 'type' => $i->type, 'status' => $i->status,
            'starts_at' => $i->starts_at?->format('Y-m-d H:i'), 'ends_at' => $i->ends_at?->format('Y-m-d H:i'),
            'responsible_user_id' => $i->responsible_user_id, 'responsible' => $i->responsible?->name ?? $i->responsible_name,
            'notes' => $i->notes, 'sort_order' => (int) $i->sort_order, 'completed_at' => $i->completed_at?->format('Y-m-d H:i'),
        ];
    }

    /** @return array<string, mixed> */
    private function requirementRow(OperationEquipmentRequirement $r): array
    {
        return [
            'id' => $r->id, 'kind' => $r->kind, 'asset_category' => $r->asset_category, 'product_id' => $r->product_id, 'product' => $r->product?->name,
            'description' => $r->description, 'required_quantity' => (int) $r->required_quantity, 'assigned_quantity' => (int) $r->assigned_quantity,
            'issued_quantity' => (int) $r->issued_quantity, 'returned_quantity' => (int) $r->returned_quantity,
            'shortfall' => $r->shortfall(), 'source' => $r->source, 'critical' => (bool) $r->critical, 'backup' => (bool) $r->backup, 'notes' => $r->notes,
        ];
    }

    /** @return array<string, mixed> */
    private function reservationRow(AssetReservation $r): array
    {
        return [
            'id' => $r->id, 'asset_id' => $r->asset_id, 'asset_code' => $r->asset?->asset_code, 'asset_name' => $r->asset?->name,
            'asset_category' => $r->asset?->category, 'asset_status' => $r->asset?->status, 'requirement_id' => $r->requirement_id,
            'requirement' => $r->requirement?->description, 'status' => $r->status,
            'reserved_from' => $r->reserved_from?->format('Y-m-d H:i'), 'reserved_until' => $r->reserved_until?->format('Y-m-d H:i'),
            'checked_out_at' => $r->checked_out_at?->format('Y-m-d H:i'), 'custodian' => $r->custodian?->name, 'checkout_condition' => $r->checkout_condition,
            'returned_at' => $r->returned_at?->format('Y-m-d H:i'), 'return_condition' => $r->return_condition,
            'missing_accessories' => $r->missing_accessories, 'damage_notes' => $r->damage_notes, 'notes' => $r->notes,
        ];
    }

    /** @return array<string, mixed> */
    private function crewRow(OperationCrewAssignment $a): array
    {
        return [
            'id' => $a->id, 'user_id' => $a->user_id, 'name' => $a->displayName(), 'external' => $a->isExternal(), 'phone' => $a->external_phone ?? $a->user?->phone ?? null,
            'role' => $a->role, 'status' => $a->status, 'briefing_status' => $a->briefing_status,
            'starts_at' => $a->starts_at?->format('Y-m-d H:i'), 'ends_at' => $a->ends_at?->format('Y-m-d H:i'),
            'checked_in_at' => $a->checked_in_at?->format('H:i'), 'checked_out_at' => $a->checked_out_at?->format('H:i'),
            'notified_at' => $a->notified_at?->format('Y-m-d H:i'), 'notes' => $a->notes,
        ];
    }

    /** @return array<string, mixed> */
    private function checklistRow(OperationChecklist $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'phase' => $c->phase,
            'items' => $c->items->map(fn (OperationChecklistItem $i) => $this->checklistItemRow($i))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function checklistItemRow(OperationChecklistItem $i): array
    {
        return [
            'id' => $i->id, 'title' => $i->title, 'required' => (bool) $i->required, 'status' => $i->status,
            'assignee' => $i->assignee?->name, 'due_at' => $i->due_at?->format('Y-m-d H:i'),
            'completed_at' => $i->completed_at?->format('Y-m-d H:i'), 'evidence_note' => $i->evidence_note,
            'blocked_reason' => $i->blocked_reason, 'waived_reason' => $i->waived_reason,
        ];
    }

    /** @return array<string, mixed> */
    private function incidentRow(OperationIncident $i): array
    {
        return [
            'id' => $i->id, 'title' => $i->title, 'category' => $i->category, 'severity' => $i->severity, 'status' => $i->status,
            'description' => $i->description, 'occurred_at' => $i->occurred_at?->format('Y-m-d H:i'), 'reported_by' => $i->reporter?->name,
            'assigned_to' => $i->assigned_to, 'assignee' => $i->assignee?->name, 'resolved_at' => $i->resolved_at?->format('Y-m-d H:i'),
            'resolution' => $i->resolution, 'carried_forward' => (bool) $i->carried_forward, 'asset' => $i->asset?->label(),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private function categories(int $bid): array
    {
        $used = Asset::where('business_id', $bid)->select('category')->distinct()->pluck('category')->all();
        $all = array_values(array_unique([...$used, ...Assets::SUGGESTED_CATEGORIES]));

        return array_map(fn (string $c) => ['value' => $c, 'label' => Assets::categoryLabel($c)], $all);
    }

    /** الأسماءُ العربيّة للقوائم المغلقة — الواجهةُ لا تخمّنها */
    private function labels(): array
    {
        $t = fn (array $map) => collect($map)->map(fn ($l) => __($l))->all();

        return [
            'status' => $t(EventOperations::STATUS_LABELS),
            'schedule_type' => $t(Schedule::TYPE_LABELS),
            'schedule_status' => $t(Schedule::STATUS_LABELS),
            'source' => $t(Requirements::SOURCE_LABELS),
            'role' => $t(Crew::ROLE_LABELS),
            'crew_status' => $t(Crew::STATUS_LABELS),
            'phase' => $t(Checklists::PHASE_LABELS),
            'incident_category' => $t(Incidents::CATEGORY_LABELS),
            'severity' => $t(Incidents::SEVERITY_LABELS),
            'incident_status' => $t(Incidents::STATUS_LABELS),
            'asset_status' => $t(Assets::STATUS_LABELS),
            'condition' => $t(Assets::CONDITION_LABELS),
            'organizer_status' => $t(OperationOrganizers::STATUS_LABELS),
            'gender' => $t(Organizers::GENDER_LABELS),
        ];
    }

    /** يُنفّذ الفعلَ ويردّ بتوستٍ أو بخطأٍ مقروء — لا ٥٠٠ على قاعدةِ عمل */
    private function attempt(callable $fn, ?string $message, string $bag)
    {
        try {
            $fn();
        } catch (RuntimeException $e) {
            return back()->withErrors([$bag => $e->getMessage()]);
        }

        return $message === null ? back() : back()->with('toast', ['msg' => $message, 'type' => 'success']);
    }
}
