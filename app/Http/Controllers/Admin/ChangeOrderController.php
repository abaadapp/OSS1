<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChangeOrder;
use App\Models\PolicyException;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectRequirement;
use App\Support\ChangeOrders;
use App\Support\Communication\Communications;
use App\Support\CompanyPolicies;
use App\Support\Demo;
use App\Support\Document\Branding;
use App\Support\Document\PaperSize;
use App\Support\Document\Snapshot;
use App\Support\Document\Version;
use App\Support\DocumentRenderer;
use App\Support\DocumentTemplates;
use App\Support\InvoiceBranding;
use App\Support\Mailer;
use App\Support\Money;
use App\Support\Paper;
use App\Support\Permissions;
use App\Support\Policy\Registry;
use App\Support\ProjectScope;
use App\Support\PublicDocument;
use App\Support\Quotations;
use App\Support\Vat;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * أوامرُ التغيير — الشاشةُ والورقة.
 *
 * ولا حسابَ يقع هنا: التسعيرُ والدورةُ والتطبيقُ في `App\Support\ChangeOrders`.
 */
class ChangeOrderController extends Controller
{
    /**
     * ورقتُه تُضبط بإعدادات ورقة العرض — لا نوعَ ورقةٍ تاسعًا.
     *
     * الشعارُ والتذييلُ والرقمُ الضريبيُّ ما يضبطه صاحبُ العمل لورقةٍ تخرج
     * إلى العميل، وأمرُ التغيير من عائلة العرض نفسِها. ونوعٌ جديد في «قوالب
     * الأوراق» يعني شاشةً ثانية تُضبط مرّتين.
     */
    public const PAPER_TYPE = QuotationController::PAPER_TYPE;

    private const CATALOG_LIMIT = 500;

    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    private function find(int|string $id): ChangeOrder
    {
        return ChangeOrder::where('business_id', $this->bid())
            ->whereKey($id)->with('items', 'project.customer')->firstOrFail();
    }

    private function project(int|string $id): Project
    {
        return Project::where('business_id', $this->bid())->whereKey($id)->with('customer')->firstOrFail();
    }

    /** @return array<string, bool|float|string|null> */
    private function may(): array
    {
        $user = auth()->user();

        return [
            'create' => (bool) $user?->may(Permissions::CHANGE_ORDER_CREATE),
            'send' => (bool) $user?->may(Permissions::CHANGE_ORDER_SEND),
            'discount' => (bool) $user?->may(Permissions::QUOTATION_DISCOUNT),
            'override_price' => (bool) $user?->may(Permissions::QUOTATION_OVERRIDE_PRICE),
            'approve' => (bool) $user?->may(Permissions::QUOTATION_APPROVE),
            'cancel' => (bool) $user?->may(Permissions::CHANGE_ORDER_CANCEL),
            'record_decision' => (bool) $user?->may(Permissions::CHANGE_ORDER_RECORD_DECISION),
            'override_policy' => (bool) $user?->may(Permissions::POLICY_OVERRIDE),
            'email' => (bool) $user?->may(Permissions::MESSAGING_SEND) && Mailer::configured(),
            'mail_reason' => Mailer::configured() ? null
                : __('إرسالُ البريد غير مضبوط على الخادم — انسخ الرابط وأرسله بنفسك.'),
            'discount_threshold' => Quotations::threshold($this->bid()),
        ];
    }

    /* ─────────────────────────── الشاشات ─────────────────────────── */

    public function create(Request $request, int|string $projectId): Response
    {
        abort_if(! auth()->user()?->may(Permissions::CHANGE_ORDER_CREATE), 403);

        $project = $this->project($projectId);

        return $this->editor($project, null);
    }

    public function edit(int|string $id): Response
    {
        abort_if(! auth()->user()?->may(Permissions::CHANGE_ORDER_CREATE), 403);

        $order = $this->find($id);

        if ($order->isFrozen()) {
            abort(403, __('هذا الأمر خرج إلى العميل — لا يُعدَّل.'));
        }

        return $this->editor($order->project, $order);
    }

    private function editor(Project $project, ?ChangeOrder $order): Response
    {
        $bid = $this->bid();

        return Inertia::render('Admin/ChangeOrders/Create', [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'customer' => $project->customer?->name,
                'starts_at' => optional($project->starts_at)->format('Y-m-d'),
                'ends_at' => optional($project->ends_at)->format('Y-m-d'),
                'current_value' => ProjectScope::currentValue($project),
                'items' => $project->items()->get()->map(fn ($i) => [
                    'id' => $i->id, 'description' => $i->description, 'unit' => $i->unit,
                    'quantity' => (float) $i->quantity, 'unit_price' => (float) $i->unit_price,
                    'product_id' => $i->product_id,
                ])->all(),
                /* والمتطلّباتُ القائمةُ وحدها تُعرض للإزالة — وما حجزه أمرٌ آخر يُقال */
                'requirements' => $project->requirements()->active()->with('removedBy')->get()->map(fn (ProjectRequirement $r) => [
                    'id' => $r->id, 'title' => $r->title, 'details' => $r->details,
                    'held_by' => $r->removedBy === null || $r->removed_by_change_order_id === $order?->id
                        ? null : ChangeOrders::label($r->removedBy),
                ])->all(),
            ],
            'order' => $order ? $this->detail($order) : null,
            'catalog' => $this->catalog($bid),
            'may' => $this->may(),
            'vat_rate' => Vat::rate($bid),
            'currency' => Money::of($bid),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function catalog(int $bid): array
    {
        return Product::where('business_id', $bid)->where('active', true)
            ->orderBy('name')->limit(self::CATALOG_LIMIT)
            ->get(['id', 'name', 'sku', 'kind', 'price', 'discount', 'tax', 'category_id'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'kind' => $p->kind ?? Product::ITEM,
                'list_price' => $p->sellingPrice(),
                'tax_rate' => Vat::rateFor($p, $bid),
            ])->all();
    }

    public function show(int|string $id): Response
    {
        /* والورقةُ أسعارٌ وفروق: لمن يقرأ الميزانيّة أو يكتب أمرًا — لا لكلّ من يملك القسم */
        abort_if(! auth()->user()?->may(Permissions::CHANGE_ORDER_CREATE)
            && ! auth()->user()?->may(Permissions::PROJECT_BUDGET_VIEW), 403);

        $order = $this->find($id);
        $order->load('items.approver', 'creator', 'approval', 'addedRequirements', 'removedRequirements',
            'policyExceptions.requester', 'policyExceptions.approver');

        return Inertia::render('Admin/ChangeOrders/Show', [
            'order' => $this->detail($order),
            'paper' => self::paper($this->bid(), $order)->render(),
            'may' => $this->may(),
        ]);
    }

    /** @return array<string, mixed> */
    private function detail(ChangeOrder $o): array
    {
        $o->loadMissing('items.approver', 'addedRequirements', 'removedRequirements', 'policyExceptions', 'approval', 'project');

        return [
            'id' => $o->id,
            'label' => ChangeOrders::label($o),
            'number' => $o->number,
            'sequence' => $o->sequence,
            'project' => ['id' => $o->project->id, 'code' => $o->project->code, 'name' => $o->project->name,
                'customer' => $o->project->customer?->name],
            'title' => $o->title,
            'reason' => $o->reason,
            'requested_by_party' => $o->requested_by_party,
            'requested_by_name' => $o->requested_by_name,
            'status' => $o->status,
            'state' => $o->presentedState(),
            'frozen' => $o->isFrozen(),
            'new_starts_at' => optional($o->new_starts_at)->format('Y-m-d'),
            'new_ends_at' => optional($o->new_ends_at)->format('Y-m-d'),
            'valid_until' => optional($o->valid_until)->format('Y-m-d'),
            'subtotal_delta' => (float) $o->subtotal_delta,
            'discount_delta' => (float) $o->discount_delta,
            'tax_delta' => (float) $o->tax_delta,
            'total_delta' => (float) $o->total_delta,
            'content_hash' => $o->content_hash,
            'created_by' => $o->creator?->name,
            'sent_at' => optional($o->sent_at)->format('Y-m-d H:i'),
            'sent_to' => $o->sent_to,
            'first_viewed_at' => optional($o->first_viewed_at)->format('Y-m-d H:i'),
            'view_count' => (int) $o->view_count,
            'approved_at' => optional($o->approved_at)->format('Y-m-d H:i'),
            'decided_at' => optional($o->decided_at)->format('Y-m-d H:i'),
            'decided_by_name' => $o->decided_by_name,
            'decline_reason' => $o->decline_reason,
            'cancellation_reason' => $o->cancellation_reason,
            'public_url' => in_array($o->status, [ChangeOrder::DRAFT, ChangeOrder::PENDING_APPROVAL], true)
                ? null : PublicDocument::url($o),
            'items' => $o->items->map(fn ($i) => [
                'id' => $i->id,
                'project_item_id' => $i->project_item_id,
                'product_id' => $i->product_id,
                'description' => $i->description,
                'unit' => $i->unit,
                'quantity' => (float) $i->quantity,
                'list_price' => (float) $i->list_price,
                'unit_price' => (float) $i->unit_price,
                'discount' => (float) $i->discount,
                'discount_percent' => $i->discountPercent(),
                'discount_reason' => $i->discount_reason,
                'approved_by' => $i->approver?->name,
                'awaiting_approval' => $i->discount_requested_by !== null && $i->discount_approved_by === null,
                'overridden' => $i->isOverridden(),
                'reduction' => $i->isReduction(),
                'tax_rate' => (float) $i->tax_rate,
                'tax_amount' => (float) $i->tax_amount,
                'line_total' => (float) $i->line_total,
                'notes' => $i->notes,
            ])->all(),
            'added' => $o->addedRequirements->map(fn ($r) => ['id' => $r->id, 'title' => $r->title, 'details' => $r->details, 'project_item_id' => $r->project_item_id])->all(),
            'removed' => $o->removedRequirements->map(fn ($r) => ['id' => $r->id, 'title' => $r->title, 'details' => $r->details])->all(),
            'policies' => CompanyPolicies::texts($o),
            'exceptions' => $o->policyExceptions->map(fn (PolicyException $e) => [
                'id' => $e->id,
                'key' => $e->policy_key,
                'label' => Registry::label($e->policy_key),
                'detected' => $e->detected,
                'reason' => $e->reason,
                'surcharge_amount' => $e->surcharge_amount === null ? null : (float) $e->surcharge_amount,
                'requested_by' => $e->requester?->name,
                'approved_by' => $e->approver?->name,
                'approved_at' => optional($e->approved_at)->format('Y-m-d H:i'),
            ])->all(),
            'unsettled' => $o->isFrozen() ? [] : collect(ChangeOrders::findings($o))
                ->reject(fn ($f) => $o->policyExceptions->contains('policy_key', $f->key()))
                ->map(fn ($f) => $f->toArray())->values()->all(),
            'approval' => $o->approval ? [
                'decision' => $o->approval->decision,
                'signer_name' => $o->approval->signer_name,
                'signer_title' => $o->approval->signer_title,
                'signer_email' => $o->approval->signer_email,
                'decided_at' => optional($o->approval->decided_at)->format('Y-m-d H:i'),
                'ip' => $o->approval->ip,
                'content_hash' => $o->approval->content_hash,
                'verified' => $o->approval->verify(),
            ] : null,
        ];
    }

    /* ─────────────────────────── الكتابة ─────────────────────────── */

    /** @return array<string, mixed> */
    private function rules(int $bid): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:5000'],
            'requested_by_party' => ['required', Rule::in(ChangeOrder::PARTIES)],
            'requested_by_name' => ['nullable', 'string', 'max:120'],
            'new_starts_at' => ['nullable', 'date'],
            'new_ends_at' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],

            'items' => ['nullable', 'array', 'max:200'],
            'items.*.project_item_id' => ['nullable', 'integer'],
            'items.*.product_id' => ['nullable',
                Rule::exists('products', 'id')->where('business_id', $bid)->whereNull('deleted_at')],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.unit' => ['nullable', 'string', 'max:40'],
            /* والكميّةُ موقَّعة: سالبٌ نقص — والصفرُ يُردّ في الخدمة */
            'items.*.quantity' => ['required', 'numeric', 'min:-1000000', 'max:1000000'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'items.*.discount_reason' => ['nullable', 'string', 'max:255'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],

            'requirements' => ['nullable', 'array'],
            'requirements.add' => ['nullable', 'array', 'max:100'],
            'requirements.add.*.title' => ['required', 'string', 'max:255'],
            'requirements.add.*.details' => ['nullable', 'string', 'max:5000'],
            'requirements.add.*.project_item_id' => ['nullable', 'integer'],
            'requirements.remove' => ['nullable', 'array', 'max:100'],
            'requirements.remove.*' => ['integer'],

            'exceptions' => ['nullable', 'array', 'max:20'],
            'exceptions.*.key' => ['required', 'string', Rule::in(Registry::keys())],
            'exceptions.*.reason' => ['nullable', 'string', 'max:500'],
            'exceptions.*.surcharge_amount' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
        ];
    }

    /** @return array<string, string> */
    private function labels(): array
    {
        return ['title' => __('عنوان التغيير'), 'items' => __('البنود'), 'requested_by_party' => __('من طلب التغيير')];
    }

    public function store(Request $request, int|string $projectId)
    {
        abort_if(! auth()->user()?->may(Permissions::CHANGE_ORDER_CREATE), 403);

        $project = $this->project($projectId);
        $data = $request->validate($this->rules($this->bid()), [], $this->labels());

        try {
            $order = ChangeOrders::create($project, $data, $data['items'] ?? [], $data['requirements'] ?? [],
                auth()->user(), $data['exceptions'] ?? []);
        } catch (RuntimeException $e) {
            return back()->withErrors(['items' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.changeOrders.show', $order->id)->with('toast', [
            'msg' => $order->status === ChangeOrder::PENDING_APPROVAL
                ? __('حُفظ أمر التغيير — وينتظر اعتمادًا قبل أن يُرسَل')
                : __('حُفظ أمر التغيير'),
            'type' => 'success',
        ]);
    }

    public function update(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::CHANGE_ORDER_CREATE), 403);

        $order = $this->find($id);
        $data = $request->validate($this->rules($this->bid()), [], $this->labels());

        try {
            $order = ChangeOrders::update($order, $data, $data['items'] ?? [], $data['requirements'] ?? [],
                auth()->user(), $data['exceptions'] ?? []);
        } catch (RuntimeException $e) {
            return back()->withErrors(['items' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.changeOrders.show', $order->id)->with('toast', [
            'msg' => __('حُفظ أمر التغيير'), 'type' => 'success',
        ]);
    }

    /* ─────────────────────────── الدورة ─────────────────────────── */

    public function approve(int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::QUOTATION_APPROVE)
            && ! auth()->user()?->may(Permissions::POLICY_OVERRIDE), 403);

        $order = $this->find($id);

        try {
            $order = ChangeOrders::approve($order, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'msg' => $order->status === ChangeOrder::PENDING_APPROVAL
                ? __('اعتُمد ما تملك اعتماده — ويبقى ما ينتظر غيرك')
                : __('اعتُمد الأمر — يمكن إرساله الآن'),
            'type' => 'success',
        ]);
    }

    public function send(int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::CHANGE_ORDER_SEND), 403);

        $order = $this->find($id);

        try {
            $order = ChangeOrders::send($order, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('أُرسل أمر التغيير :n', ['n' => ChangeOrders::label($order)]), 'type' => 'success']);
    }

    public function email(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::MESSAGING_SEND), 403);

        $data = $request->validate([
            'to' => ['required', 'email', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [], ['to' => __('بريد المُرسَل إليه'), 'note' => __('رسالة مرافقة')]);

        $order = $this->find($id);

        try {
            /* ويُقيَّد الإرسالُ على الأمر في سجلّ المراسلات — المرحلة 6 */
            Communications::about($order, fn () => ChangeOrders::email($order, $data['to'], $data['note'] ?? null, auth()->user()));
        } catch (RuntimeException $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('أُرسل إلى :to', ['to' => $data['to']]), 'type' => 'success']);
    }

    public function cancel(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::CHANGE_ORDER_CANCEL), 403);

        $data = $request->validate(['reason' => ['required', 'string', 'max:200']], [], ['reason' => __('سبب الإلغاء')]);
        $order = $this->find($id);

        try {
            ChangeOrders::cancel($order, $data['reason'], auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('أُلغي أمر التغيير'), 'type' => 'success']);
    }

    public function decision(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::CHANGE_ORDER_RECORD_DECISION), 403);

        $data = $request->validate([
            'decision' => ['required', Rule::in([ChangeOrder::ACCEPTED, ChangeOrder::DECLINED])],
            'decided_by_name' => ['nullable', 'string', 'max:120'],
            'decline_reason' => ['nullable', 'string', 'max:500'],
        ], [], ['decision' => __('القرار'), 'decline_reason' => __('سبب الرفض')]);

        $order = $this->find($id);

        try {
            ChangeOrders::recordDecision($order, $data['decision'], $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('سُجّل قرار العميل'), 'type' => 'success']);
    }

    /* ─────────────────────────── الورقة ─────────────────────────── */

    /**
     * ورقةُ الأمر مرسومةً — بابٌ واحد للشاشة والرابط العامّ. على نمط `QuotationController::paper`.
     *
     * @param  array<string, mixed>  $options
     */
    public static function paper(int $bid, ChangeOrder $order, array $options = []): View
    {
        $tpl = DocumentTemplates::settings($bid, self::PAPER_TYPE);
        $brand = InvoiceBranding::paper($bid);

        if (! $tpl['show_logo']) {
            unset($brand['logo']);
        }

        $snapshot = Snapshot::of($order, $bid);

        if (($stamped = Snapshot::seller($snapshot)) !== []) {
            $brand = array_intersect_key(array_filter(Arr::except($stamped, ['name', 'address'])), $brand) + $brand;
        }

        $order->loadMissing('items', 'project.customer', 'project.quotation', 'addedRequirements', 'removedRequirements');

        return view(Version::views($options['version'] ?? null).'.change-order', [
            'order' => $order,
            'project' => $order->project,
            'policies' => CompanyPolicies::texts($order),
            'approval' => $order->exists ? $order->approval : null,
            'currency' => Snapshot::currency($snapshot) ?: Money::of($bid),
            'tokens' => Branding::tokens($bid, DocumentRenderer::scale((string) $tpl['font'])),
            'coverImage' => Branding::cover($bid),
            'paper' => PaperSize::A4,
            'paperUrl' => $options['paperUrl'] ?? '',
            'business' => $brand,
            'headerNote' => $tpl['header'],
            'footerNote' => $tpl['footer'],
            'showNotes' => (bool) $tpl['show_notes'],
            'scale' => DocumentRenderer::scale((string) $tpl['font']),
            'vatNumber' => Vat::enabled($bid) && $tpl['show_vat_no']
                ? (Snapshot::stamped($order) ? Snapshot::vat($snapshot) : Paper::vatNumber($bid))
                : '',
            'generatedAt' => now()->format('Y-m-d H:i'),
        ]);
    }
}
