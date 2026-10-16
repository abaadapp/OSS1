<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentCategory;
use App\Models\DocumentRecord;
use App\Models\User;
use App\Support\Demo;
use App\Support\DocumentRecords;
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
 * «المستندات» — سجلُّ مستندات الشركة الداخليّة وانتهاؤها.
 *
 * القسمُ `documents` يقرأ السجلَّ ويفتح الملفّات؛ والإضافةُ والتعديلُ ورفعُ نسخةٍ
 * جديدة والأرشفةُ والتصنيفاتُ بـ`documents.manage`. وكلُّ مستندٍ يُقرأ من شركة
 * الطالب لا من رقمٍ في الرابط — والملفُّ كذلك: لا يُفتح مستندُ شركةٍ أخرى بعنوانٍ مباشر.
 */
class DocumentController extends Controller
{
    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    private function find(int|string $id): DocumentRecord
    {
        return DocumentRecord::where('business_id', $this->bid())->whereKey($id)->with('category', 'responsible')->firstOrFail();
    }

    private function gate(): void
    {
        abort_if(! auth()->user()?->may(Permissions::DOCUMENTS_MANAGE), 403);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'category_id' => ['nullable', 'integer'],
            'document_number' => ['nullable', 'string', 'max:120'],
            'issuer' => ['nullable', 'string', 'max:160'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'link_type' => ['nullable', Rule::in(array_keys(DocumentRecords::LINKS))],
            'link_id' => ['nullable', 'integer'],
            'responsible_id' => ['nullable', 'integer'],
            'file' => ['nullable', 'file', 'max:'.DocumentRecords::MAX_KB, 'extensions:'.implode(',', DocumentRecords::EXTENSIONS)],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'file.extensions' => __('الصيغ المدعومة: PDF، صور، Word، Excel.'),
            'file.max' => __('أقصى حجم 10 ميجابايت.'),
        ];
    }

    /** @return array<string, mixed> */
    private function catalog(int $bid): array
    {
        $t = fn (array $map) => collect($map)->map(fn ($l) => __($l))->all();

        return [
            'categories' => DocumentCategory::where('business_id', $bid)->withCount('documents')->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (DocumentCategory $c) => ['id' => $c->id, 'name' => $c->name, 'active' => (bool) $c->active, 'documents' => $c->documents_count])->all(),
            'statuses' => $t(DocumentRecords::STATUS_LABELS),
            'link_types' => $t(DocumentRecords::LINK_LABELS),
            'users' => User::where('business_id', $bid)->where('status', 'نشط')->orderBy('name')->get(['id', 'name'])->all(),
            'extensions' => DocumentRecords::EXTENSIONS,
        ];
    }

    public function index(Request $request): Response
    {
        $bid = $this->bid();
        DocumentRecords::ensureCategories($bid);
        $today = DocumentRecords::today($bid);

        $filters = [
            'q' => Search::term($request),
            'category' => $request->integer('category') ?: null,
            'status' => in_array($request->query('status'), DocumentRecord::STATUSES, true) ? $request->query('status') : null,
            'link_type' => array_key_exists((string) $request->query('link_type'), DocumentRecords::LINKS) ? $request->query('link_type') : null,
            'link_id' => $request->integer('link_id') ?: null,
        ];

        $like = Search::like();
        $soon = $today->copy()->addDays(DocumentRecords::EXPIRING_DAYS)->toDateString();
        $documents = DocumentRecord::where('business_id', $bid)
            ->when($filters['q'], fn ($q, $s) => $q->where(fn ($w) => $w->where('title', $like, "%{$s}%")
                ->orWhere('document_number', $like, "%{$s}%")->orWhere('issuer', $like, "%{$s}%")))
            ->when($filters['category'], fn ($q, $c) => $q->where('document_category_id', $c))
            ->when($filters['link_type'], fn ($q, $t) => $q->where('documentable_type', $t))
            ->when($filters['link_type'] && $filters['link_id'], fn ($q) => $q->where('documentable_id', $filters['link_id']))
            ->when($filters['status'], fn ($q, $st) => match ($st) {
                DocumentRecord::ARCHIVED => $q->whereNotNull('archived_at'),
                DocumentRecord::EXPIRED => $q->whereNull('archived_at')->whereDate('expires_at', '<', $today->toDateString()),
                DocumentRecord::EXPIRING => $q->whereNull('archived_at')->whereDate('expires_at', '>=', $today->toDateString())->whereDate('expires_at', '<=', $soon),
                default => $q->whereNull('archived_at')->where(fn ($w) => $w->whereNull('expires_at')->orWhereDate('expires_at', '>', $soon)),
            }, fn ($q) => $q->whereNull('archived_at'))
            ->with('category:id,name', 'responsible:id,name')
            /* ما ينتهي أوّلًا أوّلُ ما يُرى — وما لا انتهاءَ له آخرًا */
            ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')->orderBy('expires_at')->orderByDesc('id')
            ->limit(500)->get();

        $live = fn () => DocumentRecord::where('business_id', $bid)->whereNull('archived_at');

        return Inertia::render('Admin/Documents/Index', [
            'documents' => $documents->map(fn (DocumentRecord $d) => DocumentRecords::present($d, $today))->all(),
            'stats' => DocumentRecords::summary($bid) + [
                'total' => (int) $live()->count(),
                'archived' => (int) DocumentRecord::where('business_id', $bid)->whereNotNull('archived_at')->count(),
            ],
            'filters' => $filters,
            'links' => DocumentRecords::linkOptions($bid),
            'may' => ['manage' => (bool) auth()->user()->may(Permissions::DOCUMENTS_MANAGE)],
        ] + $this->catalog($bid));
    }

    public function show(int|string $id): Response
    {
        $document = $this->find($id)->load('files.uploader:id,name');
        $today = DocumentRecords::today($this->bid());

        return Inertia::render('Admin/Documents/Show', [
            'document' => DocumentRecords::present($document, $today),
            'files' => $document->files->map(fn ($f) => [
                'id' => $f->id, 'name' => $f->name, 'size' => $f->size, 'uploaded_by' => $f->uploader?->name,
                'at' => optional($f->created_at)->format('Y-m-d H:i'), 'current' => $f->path === $document->file_path,
            ])->all(),
            'links' => DocumentRecords::linkOptions($this->bid()),
            'may' => ['manage' => (bool) auth()->user()->may(Permissions::DOCUMENTS_MANAGE)],
        ] + $this->catalog($this->bid()));
    }

    public function store(Request $request)
    {
        $this->gate();
        $data = $request->validate($this->rules(), $this->messages(), ['title' => __('العنوان'), 'file' => __('الملف')]);

        try {
            $document = DocumentRecords::save($this->bid(), $data, $request->file('file'), auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['document' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.documents.show', $document->id)->with('toast', ['msg' => __('أُضيف المستند'), 'type' => 'success']);
    }

    /** تعديلٌ — ونسخةٌ جديدة إن أُرفق ملفّ (POST: النموذجُ يحمل ملفًّا) */
    public function update(Request $request, int|string $id)
    {
        $this->gate();
        $document = $this->find($id);
        $data = $request->validate($this->rules(), $this->messages(), ['title' => __('العنوان'), 'file' => __('الملف')]);

        try {
            DocumentRecords::save($this->bid(), $data, $request->file('file'), auth()->user(), $document);
        } catch (RuntimeException $e) {
            return back()->withErrors(['document' => $e->getMessage()])->withInput();
        }

        return back()->with('toast', ['msg' => __('حُفظ المستند'), 'type' => 'success']);
    }

    public function archive(int|string $id)
    {
        $this->gate();
        DocumentRecords::archive($this->find($id), auth()->user());

        return back()->with('toast', ['msg' => __('أُرشف المستند'), 'type' => 'success']);
    }

    public function restore(int|string $id)
    {
        $this->gate();
        DocumentRecords::restore($this->find($id), auth()->user());

        return back()->with('toast', ['msg' => __('أُعيد المستند من الأرشيف'), 'type' => 'success']);
    }

    /** الملفُّ — من شركة الطالب ومن قرصٍ خاصّ، ولا يُفتح بعنوانٍ مباشر */
    public function file(int|string $id, ?int $fileId = null): StreamedResponse
    {
        $document = $this->find($id);
        $file = $fileId === null ? null : $document->files()->whereKey($fileId)->first();
        $path = $file?->path ?? $document->file_path;
        $name = $file?->name ?? $document->file_name;

        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $name ?: ('document.'.pathinfo($path, PATHINFO_EXTENSION)), ['Content-Disposition' => 'inline']);
    }

    public function version(int|string $id, int|string $fid): StreamedResponse
    {
        return $this->file($id, (int) $fid);
    }

    /* ─────────────────────────── التصنيفات ─────────────────────────── */

    public function storeCategory(Request $request)
    {
        $this->gate();
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);

        try {
            DocumentRecords::saveCategory($this->bid(), $data['name']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['category' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('أُضيف التصنيف'), 'type' => 'success']);
    }

    public function updateCategory(Request $request, int|string $id)
    {
        $this->gate();
        $category = DocumentCategory::where('business_id', $this->bid())->whereKey($id)->firstOrFail();
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'active' => ['nullable', 'boolean']]);

        try {
            DocumentRecords::saveCategory($this->bid(), $data['name'], $category, array_key_exists('active', $data) ? (bool) $data['active'] : null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['category' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('حُفظ التصنيف'), 'type' => 'success']);
    }

    public function destroyCategory(int|string $id)
    {
        $this->gate();
        $category = DocumentCategory::where('business_id', $this->bid())->whereKey($id)->firstOrFail();

        try {
            DocumentRecords::deleteCategory($category);
        } catch (RuntimeException $e) {
            return back()->withErrors(['category' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('حُذف التصنيف'), 'type' => 'success']);
    }
}
