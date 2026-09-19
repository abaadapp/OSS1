<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DeliveryPackage;
use App\Models\DeliveryPackageItem;
use App\Models\Project;
use App\Models\ProjectItem;
use App\Models\Quotation;
use App\Support\DeliveryPackages;
use App\Support\DeliveryReadiness;
use App\Support\Demo;
use App\Support\Mailer;
use App\Support\Pagination;
use App\Support\Permissions;
use App\Support\Search;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * حزمُ التسليم — الشاشة.
 *
 * ولا حسابَ يقع هنا: الدورةُ كلُّها في `App\Support\DeliveryPackages`. ما في
 * هذا الملفّ ثلاثة: يقرأ الطلب، ويسأل عن الإذن، ويردّ شاشةً أو ملفًّا.
 *
 * ═══ والأذونُ أذونُ عرض السعر ═══
 *
 * القسمُ `quotations` (انظر `Permissions::ALIASES`): من يعرض على العميل
 * يسلّمه. والأفعالُ الحسّاسة بأسمائها القائمة: الإخراجُ إلى العميل
 * `QUOTATION_SEND`، وتسجيلُ قراره نيابةً `QUOTATION_RECORD_DECISION`،
 * والإلغاءُ `QUOTATION_CANCEL`، والبريدُ `MESSAGING_SEND`. فعلٌ جديدٌ
 * لكلّ مستندٍ يجعل قائمةَ الصلاحيات تطول بلا أن يزيد الأمان.
 */
class DeliveryPackageController extends Controller
{
    private const PER_PAGE = [20, 50, 100];

    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    /** الحزمةُ من شركة الطالب لا من رقمٍ في الرابط */
    private function find(int|string $id): DeliveryPackage
    {
        return DeliveryPackage::where('business_id', $this->bid())
            ->whereKey($id)->with('items.projectItem', 'customer', 'quotation', 'project')->firstOrFail();
    }

    /** @return array<string, bool|string|null> */
    private function may(): array
    {
        $user = auth()->user();

        return [
            'create' => (bool) $user?->may(Permissions::QUOTATION_CREATE),
            'send' => (bool) $user?->may(Permissions::QUOTATION_SEND),
            'cancel' => (bool) $user?->may(Permissions::QUOTATION_CANCEL),
            'record_decision' => (bool) $user?->may(Permissions::QUOTATION_RECORD_DECISION),
            'email' => (bool) $user?->may(Permissions::MESSAGING_SEND) && Mailer::configured(),
            'mail_reason' => Mailer::configured() ? null
                : __('إرسالُ البريد غير مضبوط على الخادم — انسخ الرابط وأرسله بنفسك.'),
        ];
    }

    private function mustCreate(): void
    {
        abort_if(! auth()->user()?->may(Permissions::QUOTATION_CREATE), 403);
    }

    /* ─────────────────────────── القائمة ─────────────────────────── */

    public function index(Request $request): Response
    {
        $bid = $this->bid();

        $per = (int) $request->integer('per_page');
        $per = in_array($per, self::PER_PAGE, true) ? $per : self::PER_PAGE[0];

        $page = DeliveryPackage::where('business_id', $bid)->with('customer')
            ->withCount('items')
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('number', Search::like(), "%{$s}%")
                    ->orWhere('title', Search::like(), "%{$s}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', Search::like(), "%{$s}%"))
            ))
            ->when($request->integer('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
            ->when($request->string('status')->toString(), fn ($q, $s) => in_array($s, DeliveryPackage::STATUSES, true)
                ? $q->where('status', $s) : $q->whereRaw('1 = 0'))
            ->orderByDesc('id')
            ->paginate($per)
            ->withQueryString();

        $base = fn () => DeliveryPackage::where('business_id', $bid);

        return Inertia::render('Admin/DeliveryPackages/Index', [
            'packages' => collect($page->items())->map(fn ($p) => [
                'id' => $p->id,
                'number' => $p->number,
                'label' => DeliveryPackages::label($p),
                'title' => $p->title,
                'customer' => $p->customer?->name ?? '—',
                'status' => $p->status,
                'items_count' => (int) $p->items_count,
                'sent_at' => $p->sent_at?->format('Y-m-d'),
                'decided_at' => ($p->accepted_at ?? $p->declined_at)?->format('Y-m-d'),
                'view_count' => (int) $p->view_count,
            ])->all(),
            'pagination' => Pagination::meta($page),
            'filters' => $request->only('q', 'customer_id', 'status', 'per_page'),
            'statuses' => DeliveryPackage::STATUSES,
            'customers' => Customer::where('business_id', $bid)->orderBy('name')->get(['id', 'name'])->all(),
            'totals' => [
                'in_preparation' => (int) $base()->whereIn('status', [DeliveryPackage::DRAFT, DeliveryPackage::READY])->count(),
                'awaiting_client' => (int) $base()->where('status', DeliveryPackage::SENT)->count(),
                'accepted' => (int) $base()->where('status', DeliveryPackage::ACCEPTED)->count(),
                'declined' => (int) $base()->where('status', DeliveryPackage::DECLINED)->count(),
            ],
            'may' => $this->may(),
        ]);
    }

    /* ─────────────────────────── الإنشاء ─────────────────────────── */

    public function create(Request $request): Response
    {
        $this->mustCreate();

        return Inertia::render('Admin/DeliveryPackages/Create', [
            'customers' => Customer::where('business_id', $this->bid())->orderBy('name')->get(['id', 'name'])->all(),
            'quotations' => $this->acceptedQuotations(),
            'projects' => $this->projects(),
            'preset' => [
                'quotation_id' => $request->integer('quotation_id') ?: null,
                'project_id' => $request->integer('project_id') ?: null,
            ],
        ]);
    }

    /** المشاريعُ غيرُ الملغاة — ما يُسلَّم منه */
    private function projects(): array
    {
        return Project::where('business_id', $this->bid())->where('status', '!=', Project::CANCELLED)
            ->with('customer')->orderByDesc('id')->limit(200)->get()
            ->map(fn ($p) => [
                'id' => $p->id, 'label' => $p->code.' — '.$p->name,
                'customer_id' => $p->customer_id, 'customer' => $p->customer?->name, 'quotation_id' => $p->quotation_id,
            ])->all();
    }

    /**
     * بنودُ مشروع الحزمة بما بقي من كلٍّ — للشاشة؛ والخادمُ يفحصها ثانيةً.
     *
     * @return list<array<string, mixed>>
     */
    private function scopeItems(DeliveryPackage $package): array
    {
        if ($package->project_id === null) {
            return [];
        }

        return ProjectItem::where('project_id', $package->project_id)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (ProjectItem $i) => [
                'id' => $i->id,
                'description' => $i->description,
                'unit' => $i->unit,
                'quantity' => (float) $i->quantity,
                'delivered_quantity' => (float) $i->delivered_quantity,
                'remaining' => DeliveryPackages::remainingOf($i, $package->id),
                'status' => $i->status,
            ])->all();
    }

    /** العروضُ المقبولة — ما يُسلَّم بُني على وعدٍ قُبل */
    private function acceptedQuotations(): array
    {
        return Quotation::where('business_id', $this->bid())->where('status', Quotation::ACCEPTED)
            ->with('customer')->orderByDesc('id')->limit(200)->get()
            ->map(fn ($q) => [
                'id' => $q->id, 'label' => $q->number.' — '.$q->title,
                'customer_id' => $q->customer_id, 'customer' => $q->customer?->name,
            ])->all();
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:4000'],
            'client_notes' => ['nullable', 'string', 'max:4000'],
            'internal_notes' => ['nullable', 'string', 'max:4000'],
            'contact_email' => ['nullable', 'email', 'max:120'],
            'customer_id' => ['nullable', 'integer'],
            'quotation_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
        ];
    }

    public function store(Request $request)
    {
        $this->mustCreate();

        $data = $request->validate($this->rules(), [], ['title' => __('العنوان'), 'contact_email' => __('البريد الإلكتروني')]);

        $customer = filled($data['customer_id'] ?? null)
            ? Customer::where('business_id', $this->bid())->whereKey($data['customer_id'])->first()
            : null;

        try {
            $package = DeliveryPackages::create($this->bid(), $customer, $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['package' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.deliveries.show', $package->id)
            ->with('toast', ['msg' => __('أُنشئت الحزمة — أضف بنودها وملفّاتها'), 'type' => 'success']);
    }

    public function update(Request $request, int|string $id)
    {
        $this->mustCreate();

        $data = $request->validate($this->rules(), [], ['title' => __('العنوان'), 'contact_email' => __('البريد الإلكتروني')]);

        $package = $this->find($id);

        try {
            DeliveryPackages::update($package, $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['package' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('حُفظت الحزمة'), 'type' => 'success']);
    }

    /* ─────────────────────────── الشاشة ─────────────────────────── */

    public function show(int|string $id): Response
    {
        $package = $this->find($id);
        $package->load('items.uploader', 'creator', 'preparer', 'sender', 'decider', 'approval', 'supersedes');

        $approval = $package->approval;

        return Inertia::render('Admin/DeliveryPackages/Show', [
            'package' => [
                'id' => $package->id,
                'number' => $package->number,
                'label' => DeliveryPackages::label($package),
                'title' => $package->title,
                'description' => $package->description,
                'client_notes' => $package->client_notes,
                'internal_notes' => $package->internal_notes,
                'contact_email' => $package->contact_email,
                'status' => $package->status,
                'frozen' => $package->isFrozen(),
                'customer' => $package->customer?->name,
                'customer_id' => $package->customer_id,
                'quotation_id' => $package->quotation_id,
                'quotation' => $package->quotation ? ($package->quotation->number.' — '.$package->quotation->title) : null,
                'project_id' => $package->project_id,
                'project' => $package->project ? ($package->project->code.' — '.$package->project->name) : null,
                'created_by' => $package->creator?->name,
                'prepared_by' => $package->preparer?->name,
                'prepared_at' => $package->prepared_at?->format('Y-m-d H:i'),
                'sent_by' => $package->sender?->name,
                'sent_at' => $package->sent_at?->format('Y-m-d H:i'),
                'sent_to' => $package->sent_to,
                'first_viewed_at' => $package->first_viewed_at?->format('Y-m-d H:i'),
                'view_count' => (int) $package->view_count,
                'accepted_at' => $package->accepted_at?->format('Y-m-d H:i'),
                'declined_at' => $package->declined_at?->format('Y-m-d H:i'),
                'decided_by' => $package->decider?->name,
                'decided_by_name' => $package->decided_by_name,
                'decline_reason' => $package->decline_reason,
                'cancellation_reason' => $package->cancellation_reason,
                'content_hash' => $package->content_hash,
                /* والرابطُ لما خرج وحدَه: رمزُ مسودّةٍ يفتح للعميل ما لم يُرسَل إليه */
                'public_url' => $package->isPubliclyVisible() ? DeliveryPackages::url($package) : null,
                'supersedes' => $package->supersedes ? ['id' => $package->supersedes->id, 'label' => DeliveryPackages::label($package->supersedes)] : null,
                'blockers' => $package->status === DeliveryPackage::DRAFT ? DeliveryPackages::blockers($package) : [],
                'items' => $package->items->map(fn (DeliveryPackageItem $i) => [
                    'id' => $i->id,
                    'kind' => $i->kind,
                    'title' => $i->title,
                    'description' => $i->description,
                    'quantity' => $i->quantity,
                    'unit' => $i->unit,
                    'name' => $i->name,
                    'size' => $i->size,
                    'mime' => $i->mime,
                    'checksum' => $i->checksum,
                    'project_item_id' => $i->project_item_id,
                    'project_item' => $i->projectItem?->description,
                    'uploaded_by' => $i->uploader?->name,
                    'url' => $i->hasFile() ? route('admin.deliveries.file', [$package->id, $i->id]) : null,
                ])->all(),
                'approval' => $approval === null ? null : [
                    'decision' => $approval->decision,
                    'signer_name' => $approval->signer_name,
                    'signer_title' => $approval->signer_title,
                    'signer_email' => $approval->signer_email,
                    'signer_phone' => $approval->signer_phone,
                    'consent_text' => $approval->consent_text,
                    'decline_reason' => $approval->decline_reason,
                    'decided_at' => $approval->decided_at?->format('Y-m-d H:i'),
                    'ip' => $approval->ip,
                    'user_agent' => $approval->user_agent,
                    'content_hash' => $approval->content_hash,
                    'verified' => $approval->verify(),
                ],
                'readiness' => $package->project !== null
                    ? DeliveryReadiness::forProject($package->project)->toArray()
                    : ($package->quotation_id ? DeliveryReadiness::forQuotation($this->bid(), (int) $package->quotation_id)->toArray() : null),
            ],
            'scope_items' => $this->scopeItems($package),
            'customers' => Customer::where('business_id', $this->bid())->orderBy('name')->get(['id', 'name'])->all(),
            'quotations' => $this->acceptedQuotations(),
            'projects' => $this->projects(),
            'limits' => [
                'extensions' => DeliveryPackages::EXTENSIONS,
                'max_kb' => DeliveryPackages::MAX_KB,
                'max_items' => DeliveryPackages::MAX_ITEMS,
            ],
            'may' => $this->may(),
        ]);
    }

    /* ─────────────────────────── البنود ─────────────────────────── */

    public function addItem(Request $request, int|string $id)
    {
        $this->mustCreate();

        $data = $request->validate([
            'kind' => ['required', Rule::in(DeliveryPackageItem::KINDS)],
            'title' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'unit' => ['nullable', 'string', 'max:40'],
            'project_item_id' => ['nullable', 'integer'],
            'file' => ['required_if:kind,file', 'nullable', 'file', 'max:'.DeliveryPackages::MAX_KB,
                'extensions:'.implode(',', DeliveryPackages::EXTENSIONS)],
        ], [
            'file.required_if' => __('أرفق الملف — بندُ الملفّ بلا ملفّ لا يُثبت شيئًا.'),
        ], ['title' => __('العنوان'), 'file' => __('الملف'), 'quantity' => __('الكمية')]);

        $package = $this->find($id);

        try {
            DeliveryPackages::addItem($package, $data, $request->file('file'), auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['item' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('أُضيف البند'), 'type' => 'success']);
    }

    public function removeItem(int|string $id, int|string $item)
    {
        $this->mustCreate();

        $package = $this->find($id);
        $row = $package->items()->whereKey($item)->firstOrFail();

        try {
            DeliveryPackages::removeItem($row, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['item' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('حُذف البند'), 'type' => 'success']);
    }

    /**
     * ملفُّ بندٍ — من اللوحة: يسأل عن الشركة، والقسمُ سُئل عنه عند الباب.
     */
    public function file(int|string $id, int|string $item): StreamedResponse
    {
        $package = $this->find($id);
        $row = $package->items()->whereKey($item)->firstOrFail();

        abort_if(! $row->hasFile() || ! Storage::disk('local')->exists($row->path), 404);

        return Storage::disk('local')->response($row->path, $row->name ?: $row->title, ['Content-Disposition' => 'inline']);
    }

    /* ─────────────────────────── الدورة ─────────────────────────── */

    public function ready(int|string $id)
    {
        $this->mustCreate();

        $package = $this->find($id);

        try {
            DeliveryPackages::ready($package, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['package' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('الحزمة جاهزة للإرسال'), 'type' => 'success']);
    }

    public function reopen(int|string $id)
    {
        $this->mustCreate();

        $package = $this->find($id);

        try {
            DeliveryPackages::reopen($package, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['package' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('أُعيدت إلى المسودّة'), 'type' => 'success']);
    }

    public function send(int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::QUOTATION_SEND), 403);

        $package = $this->find($id);

        try {
            $package = DeliveryPackages::send($package, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['package' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('أُرسلت حزمة التسليم :n', ['n' => DeliveryPackages::label($package)]), 'type' => 'success']);
    }

    public function email(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::MESSAGING_SEND), 403);

        $data = $request->validate([
            'to' => ['required', 'email', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [], ['to' => __('بريد المُرسَل إليه'), 'note' => __('رسالة مرافقة')]);

        $package = $this->find($id);

        try {
            DeliveryPackages::email($package, $data['to'], $data['note'] ?? null, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('أُرسل إلى :to', ['to' => $data['to']]), 'type' => 'success']);
    }

    public function decision(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::QUOTATION_RECORD_DECISION), 403);

        $data = $request->validate([
            'decision' => ['required', Rule::in([DeliveryPackage::ACCEPTED, DeliveryPackage::DECLINED])],
            'decided_by_name' => ['nullable', 'string', 'max:120'],
            'decline_reason' => ['nullable', 'string', 'max:500'],
        ], [], ['decision' => __('القرار'), 'decline_reason' => __('سبب الرفض')]);

        $package = $this->find($id);

        try {
            DeliveryPackages::recordDecision($package, $data['decision'], $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('سُجّل قرار العميل'), 'type' => 'success']);
    }

    public function cancel(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::QUOTATION_CANCEL), 403);

        $data = $request->validate(['reason' => ['required', 'string', 'max:200']], [], ['reason' => __('سبب الإلغاء')]);

        $package = $this->find($id);

        try {
            DeliveryPackages::cancel($package, $data['reason'], auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['package' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('أُلغيت الحزمة'), 'type' => 'success']);
    }

    public function revise(int|string $id)
    {
        $this->mustCreate();

        $package = $this->find($id);

        try {
            $copy = DeliveryPackages::revise($package, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['package' => $e->getMessage()]);
        }

        return redirect()->route('admin.deliveries.show', $copy->id)
            ->with('toast', ['msg' => __('أُعدّت حزمة جديدة — عالج ما رُفض ثم أرسلها'), 'type' => 'success']);
    }
}
