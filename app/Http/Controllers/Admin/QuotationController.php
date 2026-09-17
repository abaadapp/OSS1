<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quotation;
use App\Support\Demo;
use App\Support\Document\Branding;
use App\Support\Document\PaperSize;
use App\Support\Document\Snapshot;
use App\Support\Document\Version;
use App\Support\DocumentRenderer;
use App\Support\DocumentTemplates;
use App\Support\InvoiceBranding;
use App\Support\Money;
use App\Support\Pagination;
use App\Support\Paper;
use App\Support\Permissions;
use App\Support\PublicDocument;
use App\Support\Quotations;
use App\Support\Search;
use App\Support\Vat;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * عروضُ الأسعار — الشاشةُ والورقة.
 *
 * ولا حسابَ يقع هنا: التسعيرُ والضبطُ والدورةُ في `App\Support\Quotations`.
 * ما في هذا الملفّ ثلاثة: يقرأ الطلب، ويسأل عن الإذن، ويردّ شاشةً أو ورقة.
 */
class QuotationController extends Controller
{
    /** اسمُ ورقته في «قوالب الأوراق» — يُكتب مرّةً لا في كلّ نداء */
    public const PAPER_TYPE = 'quotation';

    /** كم صفًّا في الصفحة — وما سواها يسقط إلى العشرين */
    private const PER_PAGE = [20, 50, 100];

    /**
     * كم بندًا يُرسَل إلى محرّر العرض.
     *
     * والكتالوجُ يُرسل كاملًا لأنّ المحرّرَ يبحث فيه محلّيًّا بلا رحلةٍ إلى
     * الخادم مع كلّ حرف. وحدٌّ يمنع شركةً بعشرة آلاف صنفٍ من أن تُحمّل
     * الشاشةُ مصفوفةً بحجم ميغابايت — وهو حدُّ `CustomerInvoiceController`
     * نفسُه.
     */
    private const CATALOG_LIMIT = 500;

    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    /** العرضُ من شركة الطالب لا من رقمٍ في الرابط */
    private function find(int|string $id): Quotation
    {
        return Quotation::where('business_id', $this->bid())
            ->whereKey($id)->with('items', 'customer')->firstOrFail();
    }

    /**
     * ما يملكه من يقرأ — تُرسَل مع الشاشة فلا تُرسم مقابضُ يردّها الخادم.
     *
     * «بابٌ معروضٌ لا يُفتح أسوأ من بابٍ لا يُعرض»: من يضغط «إرسال» فيُردّ
     * بـ٤٠٣ لا يعرف أنّه لم يُمنح — يظنّ النظامَ معطوبًا.
     *
     * @return array<string, bool|float>
     */
    private function may(): array
    {
        $user = auth()->user();

        return [
            'create' => (bool) $user?->may(Permissions::QUOTATION_CREATE),
            'send' => (bool) $user?->may(Permissions::QUOTATION_SEND),
            'discount' => (bool) $user?->may(Permissions::QUOTATION_DISCOUNT),
            'override_price' => (bool) $user?->may(Permissions::QUOTATION_OVERRIDE_PRICE),
            'approve' => (bool) $user?->may(Permissions::QUOTATION_APPROVE),
            'cancel' => (bool) $user?->may(Permissions::QUOTATION_CANCEL),
            'record_decision' => (bool) $user?->may(Permissions::QUOTATION_RECORD_DECISION),
            /*
             * وهويّةُ الورقة إعدادُ شركةٍ لا فعلَ عرض — فتُقاس بقسم
             * الإعدادات، كما في شاشة الفاتورة.
             */
            'brand' => (bool) $user?->allows('settings'),
            /* والحدُّ يُرسَل ليُرى: من يخصم يعرف متى ينتظر اعتمادًا قبل أن يخصم */
            'discount_threshold' => Quotations::threshold($this->bid()),
        ];
    }

    /* ─────────────────────────── القائمة ─────────────────────────── */

    public function index(Request $request): Response
    {
        $bid = $this->bid();

        $per = (int) $request->integer('per_page');
        $per = in_array($per, self::PER_PAGE, true) ? $per : self::PER_PAGE[0];

        $page = Quotation::where('business_id', $bid)->with('customer')
            // والمعاملُ من `Search::like` لا مكتوبًا بيده: `like` على Postgres
            // حسّاسٌ لحالة الأحرف و`ilike` ليس كذلك
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('number', Search::like(), "%{$s}%")
                    ->orWhere('title', Search::like(), "%{$s}%")
                    ->orWhere('customer_reference', Search::like(), "%{$s}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', Search::like(), "%{$s}%"))
            ))
            ->when($request->integer('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
            // والحالُ المعروضة لا المخزَّنة وحدَها: «منتهية» تُرشَّح بالقاعدة
            ->when($request->string('state')->toString(), fn ($q, $s) => $q->presented($s))
            ->when($request->date('from'), fn ($q, $d) => $q->whereDate('issued_at', '>=', $d))
            ->when($request->date('to'), fn ($q, $d) => $q->whereDate('issued_at', '<=', $d))
            ->orderByDesc('id')
            ->paginate($per)
            ->withQueryString();

        return Inertia::render('Admin/Quotations/Index', [
            'quotations' => collect($page->items())->map(fn ($q) => $this->row($q))->all(),
            /* وشكلُ الترقيم من `Pagination::meta` — تقرؤه `DataTable` في خمس شاشات */
            'pagination' => Pagination::meta($page),
            'filters' => $request->only('q', 'customer_id', 'state', 'from', 'to', 'per_page'),
            'states' => [
                Quotation::DRAFT, Quotation::PENDING_APPROVAL, 'unopened',
                'awaiting_client', 'expired', Quotation::ACCEPTED,
                Quotation::REJECTED, Quotation::CANCELLED, Quotation::SUPERSEDED,
            ],
            'customers' => Customer::where('business_id', $bid)->orderBy('name')->get(['id', 'name'])->all(),
            'totals' => $this->totals($bid),
            'may' => $this->may(),
        ]);
    }

    /**
     * أرقامُ رأس الشاشة — ما ينتظر قرارًا، وما ينتظر اعتمادًا.
     *
     * وتُحسب بالقاعدة لا بعدّ صفوفِ الصفحة: صفحةٌ من عشرين تقول «٣ منتهية»
     * وفي الدفتر عشرون.
     *
     * @return array<string, float|int>
     */
    private function totals(int $bid): array
    {
        $base = fn () => Quotation::where('business_id', $bid);

        return [
            'awaiting_client' => (int) $base()->awaitingClient()->count(),
            'awaiting_approval' => (int) $base()->awaitingApproval()->count(),
            'expired' => (int) $base()->expired()->count(),
            /* وقيمةُ ما ينتظر قرارًا: هو خطُّ الأعمال القادم لا عددُ أوراق */
            'pipeline' => round((float) $base()->awaitingClient()->sum('total'), 3),
            'accepted' => round((float) $base()->where('status', Quotation::ACCEPTED)->sum('total'), 3),
        ];
    }

    /** @return array<string, mixed> */
    private function row(Quotation $q): array
    {
        return [
            'id' => $q->id,
            'number' => $q->number,
            'label' => Quotations::label($q),
            'revision' => $q->revision,
            'title' => $q->title,
            'customer' => $q->customer?->name ?? $q->customer_name ?? '—',
            'customer_id' => $q->customer_id,
            'status' => $q->status,
            'state' => $q->presentedState(),
            'issued_at' => optional($q->issued_at)->format('Y-m-d'),
            'valid_until' => optional($q->valid_until)->format('Y-m-d'),
            'days_left' => $q->daysLeft(),
            'total' => (float) $q->total,
            'view_count' => (int) $q->view_count,
            'event_starts_at' => optional($q->event_starts_at)->format('Y-m-d'),
        ];
    }

    /* ─────────────────────────── الشاشات ─────────────────────────── */

    public function show(int|string $id): Response
    {
        $quotation = $this->find($id);
        $bid = $this->bid();

        $quotation->load('items.product', 'items.approver', 'creator');

        return Inertia::render('Admin/Quotations/Show', [
            'quotation' => $this->detail($quotation),
            'paper' => self::paper($bid, $quotation)->render(),
            'may' => $this->may(),
            /* وسلسلةُ التنقيحات تُعرض كاملة: من قرأ النسخةَ الثانية يجد الأولى */
            'chain' => $this->chain($quotation),
        ]);
    }

    /**
     * سلسلةُ التنقيحات — الجذرُ وما تفرّع عنه، مرتّبةً.
     *
     * @return array<int, array<string, mixed>>
     */
    private function chain(Quotation $quotation): array
    {
        $rootId = $quotation->rootId();

        return Quotation::where('business_id', $quotation->business_id)
            ->where(fn ($q) => $q->whereKey($rootId)->orWhere('root_id', $rootId))
            ->orderBy('revision')
            ->get(['id', 'number', 'revision', 'status', 'total', 'sent_at'])
            ->map(fn (Quotation $q) => [
                'id' => $q->id,
                'label' => Quotations::label($q),
                'revision' => $q->revision,
                'status' => $q->status,
                'total' => (float) $q->total,
                'sent_at' => optional($q->sent_at)->format('Y-m-d'),
                'current' => $q->id === $quotation->id,
            ])->all();
    }

    /** @return array<string, mixed> */
    private function detail(Quotation $q): array
    {
        return $this->row($q) + [
            'event_ends_at' => optional($q->event_ends_at)->format('Y-m-d'),
            'event_location' => $q->event_location,
            'expected_attendees' => $q->expected_attendees,
            'customer_reference' => $q->customer_reference,
            'department' => $q->department,
            'attention_to' => $q->attention_to,
            'contact_email' => $q->contact_email,
            'contact_phone' => $q->contact_phone,
            'payment_terms_days' => $q->payment_terms_days,
            'scope' => $q->scope,
            'terms' => $q->terms,
            'notes' => $q->notes,
            'internal_notes' => $q->internal_notes,
            'subtotal' => (float) $q->subtotal,
            'discount_total' => (float) $q->discount_total,
            'tax_total' => (float) $q->tax_total,
            'created_by' => $q->creator?->name,
            'sent_at' => optional($q->sent_at)->format('Y-m-d H:i'),
            'first_viewed_at' => optional($q->first_viewed_at)->format('Y-m-d H:i'),
            'last_viewed_at' => optional($q->last_viewed_at)->format('Y-m-d H:i'),
            'approved_at' => optional($q->approved_at)->format('Y-m-d H:i'),
            'decided_at' => optional($q->decided_at)->format('Y-m-d H:i'),
            'decided_by_name' => $q->decided_by_name,
            'rejection_reason' => $q->rejection_reason,
            'cancellation_reason' => $q->cancellation_reason,
            'content_hash' => $q->content_hash,
            /*
             * ورابطُ العميل يُبنى لما **خرج** وحدَه.
             *
             * ولا يكفي استثناءُ المسودّة: عرضٌ ينتظر اعتمادًا لم يخرج بعد،
             * و`PublicDocumentController` يردّه بـ٤٠٤. فلو رُسم له زرُّ نسخٍ
             * لأرسله صاحبُه إلى العميل فيفتح صفحةَ «غير موجود» — وهو أسوأ من
             * غياب الزرّ: يظنّ النظامَ معطوبًا ويعيد الإرسال.
             *
             * و`PublicDocument::url` تكتب صفًّا في جدول الروابط عند أوّل
             * نداء، فلا تُنادى لما لا يُعرض رابطُه أصلًا.
             */
            'public_url' => in_array($q->status, [Quotation::DRAFT, Quotation::PENDING_APPROVAL], true)
                ? null
                : PublicDocument::url($q),
            'frozen' => $q->isFrozen(),
            'items' => $q->items->map(fn ($i) => [
                'id' => $i->id,
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
                'tax_rate' => (float) $i->tax_rate,
                'tax_amount' => (float) $i->tax_amount,
                'line_total' => (float) $i->line_total,
                'notes' => $i->notes,
            ])->all(),
        ];
    }

    /**
     * شاشةُ الكتابة — للجديد ولتعديل مسودّة معًا.
     *
     * وشاشةٌ واحدة لا اثنتان: «أنشئ» و«عدّل» نموذجٌ واحد بحقولٍ واحدة،
     * وشاشتان تفترقان عند أوّل حقلٍ يُضاف — فيُكتب في الإنشاء ولا يُقرأ
     * في التعديل.
     */
    public function create(Request $request): Response
    {
        abort_if(! auth()->user()?->may(Permissions::QUOTATION_CREATE), 403);

        $bid = $this->bid();
        $editing = $request->integer('id')
            ? $this->find($request->integer('id'))
            : null;

        if ($editing?->isFrozen()) {
            abort(403, __('هذا العرض خرج إلى العميل — لا يُعدَّل. أنشئ تنقيحًا جديدًا.'));
        }

        return Inertia::render('Admin/Quotations/Create', [
            'quotation' => $editing ? $this->detail($editing->load('items')) : null,
            'customers' => Customer::where('business_id', $bid)->orderBy('name')
                ->limit(self::CATALOG_LIMIT)
                ->get(['id', 'name', 'legal_name', 'department', 'contact_person',
                    'contact_email', 'contact_phone', 'payment_terms_days'])->all(),
            'catalog' => $this->catalog($bid),
            'may' => $this->may(),
            'vat_rate' => Vat::rate($bid),
            'currency' => Money::of($bid),
        ]);
    }

    /**
     * الكتالوجُ كما يقرؤه المحرّر — بسعره المعتمد لا بسعره الخام.
     *
     * و`sellingPrice()` لا `price`: هي ما سيقرؤه الخادمُ عند الحفظ. ولو
     * أرسلت الشاشةُ `price` لرأى الموظّفُ رقمًا ثمّ حُسب له غيرُه — ولظُنّ
     * أنّه خصمٌ وهو ليس كذلك.
     *
     * @return array<int, array<string, mixed>>
     */
    private function catalog(int $bid): array
    {
        $products = Product::where('business_id', $bid)
            ->where('active', true)
            ->with('packageItems.item:id,name,price,discount')
            ->orderBy('name')
            ->limit(self::CATALOG_LIMIT)
            ->get(['id', 'name', 'sku', 'kind', 'price', 'discount', 'tax', 'category_id']);

        return $products->map(fn (Product $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $p->sku,
            'kind' => $p->kind ?? Product::ITEM,
            'list_price' => $p->sellingPrice(),
            'tax_rate' => Vat::rateFor($p, $bid),
            /* وبنودُ الباقة تُرسَل معها: من اختارها يراها قبل أن يفكّها */
            'members' => $p->packageItems->map(fn ($row) => [
                'product_id' => $row->item_id,
                'description' => $row->item?->name,
                'unit' => $row->unit,
                'quantity' => (float) $row->quantity,
                'unit_price' => $row->item?->sellingPrice() ?? 0.0,
            ])->filter(fn ($m) => $m['description'] !== null)->values()->all(),
        ])->all();
    }

    /* ─────────────────────────── الكتابة ─────────────────────────── */

    /**
     * قواعدُ النموذج — واحدةٌ للإنشاء والتعديل والمعاينة.
     *
     * ولا `list_price` فيها: السعرُ المعتمد يُقرأ من الكتالوج في الخادم،
     * وما يُرسَل باسمه يُطرح ولا يُقرأ. انظر `Quotations::price`.
     *
     * @return array<string, mixed>
     */
    private function rules(int $bid, bool $requireCustomer = true): array
    {
        return [
            'customer_id' => [$requireCustomer ? 'required' : 'nullable',
                Rule::exists('customers', 'id')->where('business_id', $bid)->whereNull('deleted_at')],
            'title' => ['required', 'string', 'max:255'],
            'issued_at' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'event_location' => ['nullable', 'string', 'max:255'],
            'event_starts_at' => ['nullable', 'date'],
            'event_ends_at' => ['nullable', 'date', 'after_or_equal:event_starts_at'],
            'expected_attendees' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'customer_reference' => ['nullable', 'string', 'max:60'],
            'department' => ['nullable', 'string', 'max:120'],
            'attention_to' => ['nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'email', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'scope' => ['nullable', 'string', 'max:20000'],
            'terms' => ['nullable', 'string', 'max:20000'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'internal_notes' => ['nullable', 'string', 'max:20000'],

            'items' => ['required', 'array', 'min:1', 'max:200'],
            /* ومعرّفُ الصنف يُقيَّد بالشركة هنا وفي `Quotations::price` معًا */
            'items.*.product_id' => ['nullable',
                Rule::exists('products', 'id')->where('business_id', $bid)->whereNull('deleted_at')],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.unit' => ['nullable', 'string', 'max:40'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'items.*.discount_reason' => ['nullable', 'string', 'max:255'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    private function labels(): array
    {
        return [
            'customer_id' => __('العميل'),
            'title' => __('عنوان الفعالية'),
            'valid_until' => __('صالح حتى'),
            'items' => __('البنود'),
        ];
    }

    public function store(Request $request)
    {
        abort_if(! auth()->user()?->may(Permissions::QUOTATION_CREATE), 403);

        $bid = $this->bid();
        $data = $request->validate($this->rules($bid), [], $this->labels());

        $customer = Customer::where('business_id', $bid)->findOrFail($data['customer_id']);

        try {
            $quotation = Quotations::create($bid, $customer, $data, $data['items'], auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['items' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.quotations.show', $quotation->id)->with('toast', [
            'msg' => $quotation->status === Quotation::PENDING_APPROVAL
                ? __('حُفظ العرض — وينتظر اعتماد الخصم قبل أن يُرسَل')
                : __('حُفظ العرض'),
            'type' => 'success',
        ]);
    }

    public function update(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::QUOTATION_CREATE), 403);

        $quotation = $this->find($id);
        $data = $request->validate($this->rules($this->bid()), [], $this->labels());

        try {
            $quotation = Quotations::update($quotation, $data, $data['items'], auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['items' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.quotations.show', $quotation->id)->with('toast', [
            'msg' => $quotation->status === Quotation::PENDING_APPROVAL
                ? __('حُفظ العرض — وينتظر اعتماد الخصم قبل أن يُرسَل')
                : __('حُفظ العرض'),
            'type' => 'success',
        ]);
    }

    /**
     * معاينةُ الورقة قبل حفظها — تُرسم ولا تُكتب.
     *
     * و`POST` لا `GET`: النموذجُ كلُّه يرحل معها — بنودٌ وأسعارٌ وشروط —
     * وشريطُ عنوانٍ يحمل عرضَ جهةٍ كاملًا يُسجَّل في كلّ سجلّ وصولٍ بينه
     * وبين الخادم.
     */
    public function preview(Request $request)
    {
        abort_if(! auth()->user()?->may(Permissions::QUOTATION_CREATE), 403);

        $bid = $this->bid();
        $data = $request->validate($this->rules($bid, requireCustomer: false), [], $this->labels());

        $customer = isset($data['customer_id'])
            ? Customer::where('business_id', $bid)->find($data['customer_id'])
            : null;

        try {
            $quotation = Quotations::draft($bid, $customer, $data, $data['items'], auth()->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['html' => self::paper($bid, $quotation)->render()]);
    }

    /* ─────────────────────────── الدورة ─────────────────────────── */

    /*
     * ═══ والعرضُ يُجلب **قبل** `try` لا داخله ═══
     *
     * `ModelNotFoundException` ترث `RuntimeException`. فلو وقع الجلبُ داخل
     * الالتقاط لابتُلع الأربعُمئةُ وأربعة وصار الردُّ تحويلةً برسالة — ومن
     * فتح عرضَ شركةٍ أخرى بمعرّفٍ خمّنه يُقال له «حدث خطأ» بدل أن يُقال له
     * إنّه لا وجود له. وهو أسوأ من الخطأ نفسِه: يُخبره أنّ ثمّة شيئًا هناك.
     *
     * فالجلبُ أوّلًا، والالتقاطُ لقواعد العمل وحدها.
     */
    public function approve(int|string $id)
    {
        // واعتمادُ ما تجاوز الحدَّ يُبطل حدًّا وضعه صاحبُ العمل — فعلٌ باسمه
        abort_if(! auth()->user()?->may(Permissions::QUOTATION_APPROVE), 403);

        $quotation = $this->find($id);

        try {
            Quotations::approve($quotation, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['quotation' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('اعتُمد الخصم'), 'type' => 'success']);
    }

    public function send(int|string $id)
    {
        // والإرسالُ يُخرج الورقةَ إلى جهةٍ تحتجّ بها — فعلٌ باسمه لا بفتح القسم
        abort_if(! auth()->user()?->may(Permissions::QUOTATION_SEND), 403);

        $found = $this->find($id);

        try {
            $quotation = Quotations::send($found, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['quotation' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'msg' => __('أُرسل العرض :n', ['n' => Quotations::label($quotation)]), 'type' => 'success',
        ]);
    }

    public function revise(int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::QUOTATION_CREATE), 403);

        $quotation = $this->find($id);

        try {
            $copy = Quotations::revise($quotation, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['quotation' => $e->getMessage()]);
        }

        return redirect()->route('admin.quotations.show', $copy->id)->with('toast', [
            'msg' => __('أُنشئ التنقيح :n', ['n' => $copy->revision]), 'type' => 'success',
        ]);
    }

    public function duplicate(int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::QUOTATION_CREATE), 403);

        $copy = Quotations::duplicate($this->find($id), auth()->user());

        return redirect()->route('admin.quotations.show', $copy->id)->with('toast', [
            'msg' => __('نُسخ العرض'), 'type' => 'success',
        ]);
    }

    public function cancel(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::QUOTATION_CANCEL), 403);

        $data = $request->validate(['reason' => ['required', 'string', 'max:200']], [], [
            'reason' => __('سبب الإلغاء'),
        ]);

        $quotation = $this->find($id);

        try {
            Quotations::cancel($quotation, $data['reason'], auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['quotation' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('أُلغي العرض'), 'type' => 'success']);
    }

    public function decision(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::QUOTATION_RECORD_DECISION), 403);

        $data = $request->validate([
            'decision' => ['required', Rule::in([Quotation::ACCEPTED, Quotation::REJECTED])],
            'decided_by_name' => ['nullable', 'string', 'max:120'],
            'rejection_reason' => ['nullable', 'string', 'max:200'],
        ], [], ['decision' => __('القرار'), 'rejection_reason' => __('سبب الرفض')]);

        $quotation = $this->find($id);

        try {
            Quotations::recordDecision($quotation, $data['decision'], $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('سُجّل قرار العميل'), 'type' => 'success']);
    }

    /* ─────────────────────────── الورقة ─────────────────────────── */

    /**
     * ورقةُ العرض مرسومةً — بابٌ واحدٌ تقرأ منه الشاشةُ والطباعةُ والرابطُ العامّ.
     *
     * ولو بُنيت في ثلاثة مواضع لافترقت عند أوّل متغيّرٍ يُضاف: يُضبط
     * الشعارُ فيُرى في موضعين ويغيب عن ثالث. وهي القاعدةُ نفسُها المكتوبة
     * في `DocumentRenderer` و`CustomerInvoiceController::paper`.
     *
     * @param  array<string, mixed>|null  $override  قيمٌ لم تُحفظ بعد — للمعاينة
     * @param  array<string, mixed>  $options
     */
    public static function paper(
        int $bid,
        Quotation $quotation,
        ?array $override = null,
        array $options = [],
    ): View {
        $tpl = DocumentTemplates::settings($bid, self::PAPER_TYPE, $override);

        /*
         * وهويّةُ الورقة من `InvoiceBranding` كورقة الفاتورة.
         *
         * هي المالكُ الواحد لـ«ما تُطبع به أوراقُ العميل»: الاسمُ المعروض،
         * والشعارُ مضمَّنًا، **ولا مفتاحَ عنوانٍ فيها أصلًا** — وهو شرطُ
         * صاحب النظام على ما يمضي إلى جهة. وعرضُ السعر منها: يخرج إلى
         * العميل كما تخرج الفاتورة.
         */
        $brand = InvoiceBranding::paper($bid);

        if (! $tpl['show_logo']) {
            unset($brand['logo']);
        }

        /* ولقطةُ الورقة تسبق حالَ الشركة اليوم — انظر `Document\Snapshot` */
        $snapshot = Snapshot::of($quotation, $bid);

        /*
         * و`array_intersect_key` لا دمجٌ مطلق: دمجُ اللقطة يُدخل `address`
         * من الباب الخلفيّ، فتخرج الأوراقُ القديمة بعنوان المبنى والجديدةُ
         * بدونه. انظر `CustomerInvoiceController::paper`.
         */
        if (($stamped = Snapshot::seller($snapshot)) !== []) {
            $brand = array_intersect_key(
                array_filter(Arr::except($stamped, ['name', 'address'])),
                $brand,
            ) + $brand;
        }

        return view(Version::views($options['version'] ?? null).'.quotation', [
            'quotation' => $quotation,
            /* وعملةُ الورقة من لقطتها إن كانت مختومة — انظر `Support\Money` */
            'currency' => Snapshot::currency($snapshot) ?: Money::of($bid),
            'tokens' => Branding::tokens($bid, DocumentRenderer::scale((string) $tpl['font'])),
            'coverImage' => Branding::cover($bid),
            /* وعرضُ السعر ورقةُ A4 دائمًا — لا يُطبع على شريطٍ حراريّ */
            'paper' => PaperSize::A4,
            'paperUrl' => $options['paperUrl'] ?? '',
            'business' => $brand,
            'headerNote' => $tpl['header'],
            'footerNote' => $tpl['footer'],
            'showNotes' => (bool) $tpl['show_notes'],
            'scale' => DocumentRenderer::scale((string) $tpl['font']),
            /* والرقمُ الضريبيُّ من اللقطة: ورقةُ يناير تحمل رقمَ يناير */
            'vatNumber' => Vat::enabled($bid) && $tpl['show_vat_no']
                ? (Snapshot::stamped($quotation)
                    ? Snapshot::vat($snapshot)
                    : Paper::vatNumber($bid))
                : '',
            'generatedAt' => now()->format('Y-m-d H:i'),
        ]);
    }
}
