<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChangeOrder;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\DeliveryPackage;
use App\Models\EventOperation;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectRequirement;
use App\Models\ProjectTask;
use App\Models\Quotation;
use App\Models\ScopeOfWork;
use App\Models\User;
use App\Support\ChangeOrders;
use App\Support\Communication\Templates;
use App\Support\CustomerContacts;
use App\Support\DeliveryPackages;
use App\Support\DeliveryReadiness;
use App\Support\Demo;
use App\Support\Intelligence\ProjectProfitability;
use App\Support\InventoryForecast;
use App\Support\Messaging\Notifiers;
use App\Support\Operations\Checklists;
use App\Support\Operations\Crew;
use App\Support\Operations\EventOperations;
use App\Support\Operations\OperationReadiness;
use App\Support\Operations\ResourceConflicts;
use App\Support\Pagination;
use App\Support\Permissions;
use App\Support\ProjectBilling;
use App\Support\ProjectFinancials;
use App\Support\ProjectPurchases;
use App\Support\Projects;
use App\Support\ProjectScope;
use App\Support\ProjectWorkflow;
use App\Support\PublicDocument;
use App\Support\Quotations;
use App\Support\ScopesOfWork;
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
            /* توقّعُ المخزون في المشروع — لمن يفتح المخزون (`InventoryForecast`) */
            'inventory' => (bool) $user?->allows('inventory'),
            'operations_manage' => (bool) $user?->may(Permissions::OPERATIONS_MANAGE),
            /* والأداءُ الماليّ — المرحلة 11: لمن يقرأ ربحيّة المشاريع */
            'performance' => (bool) $user?->may(Permissions::ANALYTICS_PROJECTS),
            /* والفوترةُ من صفحة المشروع لمن يفتح فواتيرَ العملاء — والإصدارُ فعلٌ يُحرس في شاشتها */
            'invoice' => (bool) $user?->allows('orders'),
            /* والمشترياتُ — المرحلة 14: من يفتح قسمَ المشتريات يرى تبويبَها في المشروع وينشئ منه */
            'purchases' => (bool) $user?->allows('purchases'),
        ];
    }

    /* ─────────────────────────── القائمة ─────────────────────────── */

    public function index(Request $request): Response
    {
        $bid = $this->bid();
        $may = $this->may();
        $per = (int) $request->integer('per_page');
        $per = in_array($per, self::PER_PAGE, true) ? $per : self::PER_PAGE[0];

        $page = Project::where('business_id', $bid)->with('customer', 'owner', 'quotation:id,number,issued_at')
            // ومهامُّه على بطاقته: كم بقي منها، وكم تأخّر
            ->withCount([
                'tasks as open_tasks' => fn ($t) => $t->where('status', ProjectTask::OPEN),
                'tasks as late_tasks' => fn ($t) => $t->where('status', ProjectTask::OPEN)->whereDate('due_at', '<', now()->toDateString()),
            ])
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('code', Search::like(), "%{$s}%")
                    ->orWhere('name', Search::like(), "%{$s}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', Search::like(), "%{$s}%"))
            ))
            ->when($request->integer('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
            ->when($request->string('status')->toString(), fn ($q, $s) => in_array($s, Project::STATUSES, true)
                ? $q->where('status', $s) : $q->whereRaw('1 = 0'))
            // القائمُ قبل المُقفَل والملغى: ما يُعمل عليه أوّلُ ما يُرى
            ->orderByRaw('case when status in (?, ?) then 1 else 0 end', [Project::CLOSED, Project::CANCELLED])
            ->orderByDesc('id')
            ->paginate($per)
            ->withQueryString();

        $base = fn () => Project::where('business_id', $bid);

        /*
         * ومسارُ كلّ مشروعٍ على بطاقته — من `ProjectWorkflow` نفسِه الذي تقرؤه
         * صفحةُ المشروع، دفعةً واحدةً لكلّ الصفحة لا مشروعًا مشروعًا. فلا تقول
         * البطاقةُ «التسليم» وتقول الصفحةُ «التنفيذ».
         */
        $ids = collect($page->items())->pluck('id')->all();
        $operations = EventOperation::whereIn('project_id', $ids)->orderByDesc('id')->get(['id', 'project_id', 'status'])->groupBy('project_id');
        $packages = DeliveryPackage::whereIn('project_id', $ids)->orderByDesc('id')->get(['id', 'project_id', 'status'])->groupBy('project_id');
        $flows = ProjectWorkflow::forMany($bid, collect($page->items()));
        /* ونقصُ المخزون المتوقَّع على بطاقة المشروع القادم — توقّعٌ واحد للصفحة، ولمن يفتح المخزون */
        $shortages = $may['inventory'] ? InventoryForecast::shortages(InventoryForecast::for($bid)) : [];

        return Inertia::render('Admin/Projects/Index', [
            'projects' => collect($page->items())->map(fn (Project $p) => $this->row($p, $may['commercial']) + $this->links($p, $operations[$p->id] ?? collect(), $packages[$p->id] ?? collect()) + [
                'workflow' => self::compact($flows[$p->id]),
                'inventory_shortages' => $shortages[$p->id] ?? 0,
            ])->all(),
            'quotes' => $this->pendingQuotes($request, $bid, $may['commercial'], $page->currentPage()),
            /* و«مسؤوليتي» على بطاقة ما يملكه الناظر */
            'me' => auth()->id(),
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

    /**
     * المسارُ مضغوطًا للبطاقة — حالُ كلّ مرحلة وأين يقف الفريق وما يمنع.
     *
     * @param  array<string, mixed>  $flow
     * @return array<string, mixed>
     */
    private static function compact(array $flow): array
    {
        return [
            'stages' => array_map(fn (array $s) => [
                'key' => $s['key'], 'state' => $s['state'], 'focus' => $s['focus'], 'note' => $s['note'],
            ], $flow['stages']),
            'focus' => $flow['focus'],
            'next' => $flow['next'],
            'can_advance' => $flow['can_advance'],
            'pending' => collect($flow['stages'])->firstWhere('key', $flow['focus'])['pending'] ?? [],
        ];
    }

    /**
     * روابطُ البطاقة: عرضُ السعر الذي بدأ منه، وفعاليّتُه، وتسليمُه.
     *
     * الحزمةُ المقبولةُ تُقدَّم على غيرها، ثمّ المرسلة، ثمّ أحدثُ ما كُتب.
     *
     * @return array<string, mixed>
     */
    private function links(Project $p, $operations, $packages): array
    {
        $op = $operations->first(fn (EventOperation $o) => $o->status !== EventOperation::CANCELLED) ?? $operations->first();
        $pkg = $packages->firstWhere('status', DeliveryPackage::ACCEPTED)
            ?? $packages->firstWhere('status', DeliveryPackage::SENT)
            ?? $packages->first(fn (DeliveryPackage $d) => ! in_array($d->status, [DeliveryPackage::CANCELLED, DeliveryPackage::DECLINED], true))
            ?? $packages->first();

        return [
            'location' => $p->location,
            'owner_id' => $p->owner_id,
            'created_at' => $p->created_at?->format('Y-m-d'),
            'quotation' => $p->quotation ? ['id' => $p->quotation->id, 'number' => $p->quotation->number] : null,
            'operation' => $op ? ['id' => $op->id, 'status' => $op->status] : null,
            'delivery' => $pkg ? ['id' => $pkg->id, 'status' => $pkg->status] : null,
        ];
    }

    /**
     * العروضُ التي لم تصر مشاريعَ بعد — أوّلُ مرحلةٍ في حياة المشروع.
     *
     * المشروعُ يبدأ عرضَ سعر: فما كُتب ولم يُقرَّر فيه بعدُ يُرى في قائمة
     * المشاريع بطاقةً مرحلتُها الأولى، والمرفوضُ حديثًا يُرى بعلامته. وتظهر
     * في الصفحة الأولى وحدَها — قليلةٌ بطبعها ولا تُرقَّم مع المشاريع.
     *
     * @return list<array<string, mixed>>
     */
    private function pendingQuotes(Request $request, int $bid, bool $commercial, int $page): array
    {
        $status = $request->string('status')->toString();
        if ($page > 1 || ! in_array($status, ['', 'quote'], true) || ! auth()->user()?->allows('quotations')) {
            return [];
        }

        return Quotation::where('business_id', $bid)->with('customer:id,name')
            ->where(fn ($w) => $w->whereIn('status', [Quotation::DRAFT, Quotation::PENDING_APPROVAL, Quotation::SENT])
                ->orWhere(fn ($r) => $r->where('status', Quotation::REJECTED)->where('updated_at', '>=', now()->subDays(60))))
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('number', Search::like(), "%{$s}%")
                    ->orWhere('title', Search::like(), "%{$s}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', Search::like(), "%{$s}%"))
            ))
            ->when($request->integer('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
            ->orderByDesc('id')->limit(50)->get()
            ->map(fn (Quotation $q) => [
                'id' => $q->id,
                'number' => $q->number,
                'title' => $q->title,
                'customer' => $q->customer?->name ?? $q->customer_name ?? '—',
                'status' => $q->status,
                'location' => $q->event_location,
                'starts_at' => $q->event_starts_at?->format('Y-m-d'),
                'ends_at' => $q->event_ends_at?->format('Y-m-d'),
                'total' => $commercial ? (float) $q->total : null,
                'created_at' => $q->created_at?->format('Y-m-d'),
                'created_by' => $q->created_by,
            ])->all();
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
            'open_tasks' => (int) ($p->open_tasks ?? 0),
            'late_tasks' => (int) ($p->late_tasks ?? 0),
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
        $summary = ProjectFinancials::summary($project);

        return Inertia::render('Admin/Projects/Show', [
            'project' => $this->row($project, $may['commercial']) + [
                'event_type' => $project->event_type,
                'location' => $project->location,
                'contact_person' => $project->contact_person,
                'contact_phone' => $project->contact_phone,
                'contact_email' => $project->contact_email,
                'customer_contact_id' => $project->customer_contact_id,
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
                /* والفعاليّةُ التي تقف عليها المهمّة — تصل صاحبَها مع أخواتها في رسالةٍ واحدة */
                'event_operation_id' => $t->event_operation_id,
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
            /*
             * توقّعُ المخزون — قراءةٌ لا خصم: المتوفّرُ الآن، وما يحتاجه من قبله في
             * الخطّ، واحتياجُه، والمتوقَّعُ بعده. ولكلّ منتجٍ خطُّه الزمنيّ للتفصيل.
             */
            'inventory_forecast' => $may['inventory'] ? InventoryForecast::forProject((int) $project->business_id, $project->id) : null,
            /* وفعاليّاتُ المشروع لنموذج المهمّة — اسمٌ ورمز لا أكثر (والملغاةُ لا تُختار) */
            'task_operations' => $may['manage'] ? EventOperation::where('business_id', $project->business_id)->where('project_id', $project->id)
                ->where('status', '!=', EventOperation::CANCELLED)->orderBy('event_starts_at')->get(['id', 'code', 'name'])->all() : [],
            /* الأداءُ الماليّ — المرحلة 11: الصيغةُ في `ProjectFinancials` والاكتمالُ في `ProjectProfitability` */
            'performance' => $may['performance'] ? ProjectProfitability::forProject($project) + ['timeline' => ProjectProfitability::timeline($project)] : null,
            /*
             * والفوترةُ والتحصيل — مرحلةٌ في مسار المشروع: فواتيرُه بحالها،
             * وكم فُوتر وحُصّل من قيمته. والأرقامُ لمن يملك المال، والفواتيرُ
             * لمن يفتح قسمَها.
             */
            'billing' => $this->billing($project, $may),
            'invoices' => $may['invoice'] ? $project->invoices()->orderByDesc('id')->get()->map(fn (CustomerInvoice $i) => [
                'id' => $i->id,
                'number' => $i->number,
                'status' => $i->status,
                'payment_state' => $i->paymentState(),
                'issued_at' => optional($i->issued_at)->format('Y-m-d'),
                'due_at' => optional($i->due_at)->format('Y-m-d'),
                'total' => $may['commercial'] ? (float) $i->total : null,
                'outstanding' => $may['commercial'] ? $i->outstanding() : null,
            ])->all() : null,
            /* وما يمنع الإقفالَ الآن — بنصّ الإقفال نفسِه، يُقرأ قبل الزرّ */
            'close_blockers' => $project->isOpen() ? Projects::closeBlockers($project, $summary) : [],
            /*
             * المسارُ — سبعُ مراحل تُقرأ من حقيقتها (`ProjectWorkflow`)، ومعها
             * ما تحتاجه مراحلُه: العرضُ ودليلُ قبوله، ونطاقُ العمل بنسخه،
             * والفوترةُ على مراحلها.
             */
            'workflow' => ProjectWorkflow::for($project),
            'quotation' => $this->quotation($project, $may),
            'scope_of_work' => $this->scopeOfWork($project, $may),
            'channels' => [
                'email' => Notifiers::email()->configured(),
                'whatsapp' => Notifiers::whatsapp()->configured(),
                'scope_whatsapp_template' => Templates::whatsapp((int) $project->business_id, 'scope.link', 'ar') !== null,
            ],
            'users' => User::where('business_id', $this->bid())->orderBy('name')->get(['id', 'name'])->all(),
            /* أشخاصُ اتصال الجهة — يُختار منهم في بيانات المشروع ونطاق عمله (المرحلة 14) */
            'contacts' => $project->customer ? CustomerContacts::options($project->customer) : [],
            /* والمشترياتُ — تبويبٌ مساند لا مرحلة: أوامرُ الشراء الحقيقيّة على المشروع لمن يفتح المشتريات */
            'purchases' => $may['purchases'] ? ProjectPurchases::for($project, auth()->user()) : null,
            'may' => $may,
        ]);
    }

    /**
     * العرضُ الذي بدأ منه المشروع — ودليلُ قبوله كما كُتب يومَها.
     *
     * @param  array<string, bool>  $may
     * @return array<string, mixed>|null
     */
    private function quotation(Project $project, array $may): ?array
    {
        $quotation = $project->quotation?->loadMissing('approval');

        if ($quotation === null) {
            return null;
        }

        $approval = $quotation->approval;

        return [
            'id' => $quotation->id,
            'label' => Quotations::label($quotation),
            'title' => $quotation->title,
            'total' => $may['commercial'] ? (float) $quotation->total : null,
            'sent_at' => optional($quotation->sent_at)->format('Y-m-d H:i'),
            'decided_at' => optional($quotation->decided_at)->format('Y-m-d H:i'),
            'decided_by_name' => $quotation->decided_by_name,
            'content_hash' => $quotation->content_hash,
            /* والرابطُ العامّ لعرضٍ خرج — يُفتح كما قرأه العميلُ وقبله */
            'public_url' => in_array($quotation->status, [Quotation::DRAFT, Quotation::PENDING_APPROVAL], true) ? null : PublicDocument::url($quotation),
            'evidence' => $approval === null ? null : [
                'channel' => 'link',
                'signer_name' => $approval->signer_name,
                'signer_title' => $approval->signer_title,
                'signer_email' => $approval->signer_email,
                'signer_phone' => $approval->signer_phone,
                'decided_at' => optional($approval->decided_at)->format('Y-m-d H:i'),
                'ip' => $approval->ip,
                'consent_text' => $approval->consent_text,
                'content_hash' => $approval->content_hash,
                'verified' => $approval->verify(),
            ],
        ];
    }

    /**
     * نطاقُ العمل في صفحة المشروع — النسخةُ الأحدث، والموقَّعةُ السارية، ونسخُه.
     *
     * @param  array<string, bool>  $may
     * @return array<string, mixed>
     */
    private function scopeOfWork(Project $project, array $may): array
    {
        $revisions = ScopeOfWork::where('business_id', $project->business_id)->where('project_id', $project->id)
            ->orderByDesc('revision')->get();
        $signed = $revisions->firstWhere('status', ScopeOfWork::SIGNED);

        return [
            'required' => ScopesOfWork::required($project),
            'latest' => $revisions->first() === null ? null : ScopesOfWork::present($revisions->first(), $may['commercial']),
            'signed' => $signed === null ? null : ScopesOfWork::present($signed, $may['commercial']),
            'revisions' => $revisions->map(fn (ScopeOfWork $s) => [
                'id' => $s->id, 'number' => $s->number, 'revision' => $s->revision, 'status' => $s->status, 'state' => $s->presentedState(),
                'sent_at' => optional($s->sent_at)->format('Y-m-d'), 'decided_at' => optional($s->decided_at)->format('Y-m-d'),
            ])->all(),
        ];
    }

    /**
     * مرحلةُ الفوترة: هل فُوترت القيمةُ كلُّها، وهل حُصّلت.
     *
     * الحكمُ يُرسَل للجميع (هو ما يُضيء المرحلة)، والمبالغُ لمن يملك المال.
     *
     * @param  array<string, bool>  $may
     * @return array<string, mixed>
     */
    private function billing(Project $project, array $may): array
    {
        $f = ProjectFinancials::summary($project);
        $position = ProjectBilling::position($project, $f);
        $c = $may['commercial'];
        $deposits = ProjectBilling::invoices($project, ProjectBilling::DEPOSIT);
        $finals = ProjectBilling::invoices($project, ProjectBilling::FINAL);
        $user = auth()->user();

        return [
            /* الحكمُ للجميع (هو ما يُضيء المرحلة)، والمبالغُ لمن يملك المال */
            'billed' => (bool) $position['fully_billed'],
            'paid' => (float) $f['net_invoiced'] > 0 && (float) $f['customer_outstanding'] <= 0.0005,
            'value' => $c ? $position['value'] : null,
            'invoiced' => $c ? $position['net_invoiced'] : null,
            'gross_invoiced' => $c ? $position['invoiced'] : null,
            'credited' => $c ? $position['credited'] : null,
            'drafts' => $c ? $position['drafts'] : null,
            'unbilled' => $c ? $position['unbilled'] : null,
            'billable' => $c ? $position['billable'] : null,
            'collected' => $c ? $position['collected'] : null,
            'outstanding' => $c ? $position['outstanding'] : null,
            'rate' => $c ? $position['rate'] : null,
            'deposit' => ProjectBilling::depositState($project, $position, $deposits) + [
                'waived_at' => optional($project->deposit_waived_at)->format('Y-m-d H:i'),
                'waiver_reason' => $project->deposit_waiver_reason,
                'invoices' => $deposits->map(fn ($i) => ProjectBilling::row($i, $c))->values()->all(),
            ],
            'final' => [
                'quote' => $c ? ProjectBilling::quoteFinal($project, $position) : null,
                'invoices' => $finals->map(fn ($i) => ProjectBilling::row($i, $c))->values()->all(),
            ],
            'may' => [
                'create' => (bool) $user?->allows('orders'),
                'issue' => (bool) $user?->may(Permissions::CUSTOMER_INVOICE_ISSUE),
                'waive' => (bool) $user?->may(Permissions::PROJECT_MANAGE),
            ],
        ];
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
            'customer_contact_id' => ['nullable', 'integer'],
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
            'event_operation_id' => ['nullable', 'integer'],
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
