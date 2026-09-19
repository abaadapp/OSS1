<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ImportDataset;
use App\Models\ImportMappingTemplate;
use App\Models\ImportRow;
use App\Models\ImportRun;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Import\Approval;
use App\Support\Import\Context;
use App\Support\Import\Cutover;
use App\Support\Import\Datasets;
use App\Support\Import\Fields;
use App\Support\Import\FileGuard;
use App\Support\Import\Index;
use App\Support\Import\Report;
use App\Support\Import\Rollback;
use App\Support\Import\Runner;
use App\Support\Import\Transforms;
use App\Support\Pagination;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * مركزُ استيراد البيانات — الشاشة. المرحلة 9.
 *
 * ولا حسابَ يقع هنا: التسجيلُ والقراءةُ في `Datasets`، والتنفيذُ في `Runner`،
 * والاعتمادُ في `Approval`، والتراجعُ في `Rollback`. ما في هذا الملفّ ثلاثة:
 * يقرأ الطلب، ويسأل عن الإذن، ويردّ شاشة. ولا مسارَ ملفٍّ يخرج إلى المتصفّح.
 */
class DataImportController extends Controller
{
    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    private function find(int|string $id): ImportDataset
    {
        return ImportDataset::where('business_id', $this->bid())->whereKey($id)->firstOrFail();
    }

    /** @return array<string, bool> */
    private function may(): array
    {
        $user = auth()->user();

        return [
            'manage' => (bool) $user?->may(Permissions::IMPORT_MANAGE),
            'execute' => (bool) $user?->may(Permissions::IMPORT_EXECUTE),
            'financial' => (bool) $user?->may(Permissions::IMPORT_FINANCIAL),
            'rollback' => (bool) $user?->may(Permissions::IMPORT_ROLLBACK),
        ];
    }

    /* ─────────────────────────── القائمة ─────────────────────────── */

    public function index(Request $request): Response
    {
        $bid = $this->bid();

        $page = ImportDataset::where('business_id', $bid)->with('creator')
            ->when($request->string('status')->toString(), fn ($q, $s) => in_array($s, ImportDataset::STATUSES, true) ? $q->where('status', $s) : $q)
            ->when($request->string('category')->toString(), fn ($q, $c) => in_array($c, Fields::CATEGORIES, true) ? $q->where('category', $c) : $q)
            ->orderByDesc('id')->paginate(25)->withQueryString();

        return Inertia::render('Admin/DataImport/Index', [
            'datasets' => collect($page->items())->map(fn (ImportDataset $d) => $this->row($d))->all(),
            'pagination' => Pagination::meta($page),
            'filters' => $request->only('status', 'category'),
            'categories' => collect(Fields::CATEGORIES)->map(fn ($c) => ['value' => $c, 'label' => Fields::label($c), 'financial' => Fields::isFinancial($c)])->all(),
            'statuses' => collect(ImportDataset::STATUSES)->map(fn ($s) => ['value' => $s, 'label' => Datasets::statusLabel($s)])->all(),
            'cutover' => Cutover::date($bid)?->toDateString(),
            'templates' => ImportMappingTemplate::where('business_id', $bid)->orderBy('name')->get(['id', 'name', 'category', 'version'])->all(),
            'limits' => ['max_mb' => (int) (FileGuard::MAX_BYTES / 1048576), 'max_rows' => FileGuard::MAX_ROWS],
            'may' => $this->may(),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(ImportDataset $d): array
    {
        $t = $d->validation['totals'] ?? null;

        return [
            'id' => $d->id,
            'code' => $d->code,
            'name' => $d->name,
            'category' => $d->category,
            'category_label' => Fields::label($d->category),
            'financial' => Fields::isFinancial($d->category),
            'rows' => $d->row_count,
            'status' => $d->status,
            'status_label' => Datasets::statusLabel($d->status),
            'strategy' => $d->strategy,
            'uploaded_at' => optional($d->created_at)->format('Y-m-d H:i'),
            'uploaded_by' => $d->creator?->name,
            'validation' => $t === null ? null : ['errors' => (int) ($t['errors'] ?? 0), 'conflicts' => (int) ($t['conflicts'] ?? 0), 'warnings' => (int) ($t['warnings'] ?? 0)],
            'imported_at' => optional($d->imported_at)->format('Y-m-d H:i'),
            'balanced' => $d->reconciliation['balanced'] ?? null,
            'rolled_back' => $d->rollback !== null,
        ];
    }

    /* ─────────────────────────── التسجيل ─────────────────────────── */

    public function store(Request $request)
    {
        abort_if(! auth()->user()?->may(Permissions::IMPORT_MANAGE), 403);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:25600', 'extensions:csv,xlsx'],
            'name' => ['nullable', 'string', 'max:160'],
            'category' => ['required', Rule::in(Fields::CATEGORIES)],
            'source' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], ['file.extensions' => __('الصيغ المقبولة: CSV وXLSX فقط.')], ['file' => __('الملفّ'), 'category' => __('الصنف')]);

        try {
            $dataset = Datasets::upload($this->bid(), $request->file('file'), $data, auth()->user());
            $dataset = Datasets::profile($dataset, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        } catch (Throwable $e) {
            return back()->withErrors(['file' => __('تعذّرت قراءة الملفّ — تأكّد من صيغته: :m', ['m' => mb_substr($e->getMessage(), 0, 120)])]);
        }

        return redirect()->route('admin.imports.show', $dataset->id)->with('toast', ['msg' => __('سُجّلت المجموعة :code وقُرئت', ['code' => $dataset->code]), 'type' => 'success']);
    }

    /* ─────────────────────────── الشاشة ─────────────────────────── */

    public function show(int|string $id): Response
    {
        $dataset = $this->find($id)->load('creator', 'approver', 'importer', 'dryRun', 'importRun', 'template');
        $may = $this->may();
        $mask = ! $may['manage'];
        $bid = $this->bid();

        $dry = $dataset->dryRun;
        $conflicts = $dry === null ? [] : ImportRow::where('run_id', $dry->id)->where('outcome', ImportRow::CONFLICT)
            ->orderBy('row_number')->limit(200)->get()->map(fn (ImportRow $r) => [
                'row' => $r->row_number, 'source_ref' => $r->source_ref, 'match' => $r->match,
                'messages' => $r->messages ?? [], 'decision' => $dataset->resolutions[(string) $r->row_number] ?? null,
                'candidates' => $this->candidates($dataset, $r),
            ])->all();

        return Inertia::render('Admin/DataImport/Show', [
            'dataset' => $this->row($dataset) + [
                'source' => $dataset->source,
                'original_filename' => $dataset->original_filename,
                'extension' => $dataset->extension,
                'size' => $dataset->size,
                'checksum' => $dataset->checksum,
                'columns' => $dataset->columns ?? [],
                'column_count' => $dataset->column_count,
                'profile' => $dataset->profile,
                'sample' => $mask ? $this->maskSample($dataset) : ($dataset->sample ?? []),
                'mapping' => $dataset->mapping,
                'mapping_version' => $dataset->mapping_version,
                'template' => $dataset->template?->only('id', 'name', 'version'),
                'validation' => $dataset->validation,
                'resolutions' => $dataset->resolutions ?? [],
                'approved_at' => optional($dataset->approved_at)->format('Y-m-d H:i'),
                'approved_by' => $dataset->approver?->name,
                'approval_stands' => Approval::stands($dataset),
                'imported_by' => $dataset->importer?->name,
                'reconciliation' => $dataset->reconciliation,
                'rollback' => $dataset->rollback,
                'notes' => $dataset->notes,
                'is_open' => $dataset->isOpen(),
                'is_imported' => $dataset->isImported(),
            ],
            'fields' => collect(Fields::for($dataset->category))->map(fn ($f, $k) => ['key' => $k] + $f)->values()->all(),
            'transforms' => Transforms::NAMES,
            'strategies' => [
                ['value' => ImportDataset::HISTORICAL, 'label' => __('مستندات كاملة (تاريخي)')],
                ['value' => ImportDataset::OPENING_BALANCE, 'label' => __('رصيد افتتاحي فقط')],
            ],
            'templates' => ImportMappingTemplate::where('business_id', $bid)->where('category', $dataset->category)->orderBy('name')->get(['id', 'name', 'version'])->all(),
            'dry_run' => $dry === null ? null : $this->run($dry),
            'import_run' => $dataset->importRun === null ? null : $this->run($dataset->importRun),
            'conflicts' => $conflicts,
            'runs' => $dataset->runs()->with('user')->limit(20)->get()->map(fn (ImportRun $r) => $this->run($r))->all(),
            'rollback_eligibility' => $dataset->isImported() && $may['rollback'] ? Rollback::eligibility($dataset) : null,
            'activity' => ActivityLog::where('business_id', $bid)->where('subject_type', 'import_dataset')->where('subject_id', $dataset->id)
                ->orderByDesc('id')->limit(50)->get()->map(fn ($a) => ['at' => optional($a->created_at)->format('Y-m-d H:i'), 'user' => $a->user_name ?? null, 'text' => $a->description])->all(),
            'cutover' => Cutover::date($bid)?->toDateString(),
            'may' => $may,
        ]);
    }

    /** @return array<string, mixed> */
    private function run(ImportRun $r): array
    {
        return [
            'id' => $r->id, 'kind' => $r->kind, 'status' => $r->status, 'by' => $r->user?->name,
            'started_at' => optional($r->started_at)->format('Y-m-d H:i'), 'finished_at' => optional($r->finished_at)->format('Y-m-d H:i'),
            'totals' => $r->totals, 'financial' => $r->financial, 'findings' => array_slice($r->findings ?? [], 0, 100),
            'reconciliation' => $r->reconciliation, 'error' => $r->error,
            'mapping_version' => $r->mapping_version, 'checksum' => $r->checksum, 'strategy' => $r->strategy,
        ];
    }

    /** المرشَّحون للربط — بأسمائهم لا بكلّ حقولهم */
    private function candidates(ImportDataset $dataset, ImportRow $r): array
    {
        $finding = collect($dataset->validation['findings'] ?? [])->firstWhere('row', $r->row_number);
        $ids = $finding['candidates'] ?? [];
        if ($ids === [] || $r->entity_type === null && $dataset->category === '') {
            return [];
        }

        $index = new Index((int) $dataset->business_id);
        $type = Runner::recipe($dataset->category, new Context($dataset, null, true))->type();

        return collect($ids)->map(function ($id) use ($index, $type) {
            $m = $index->get($type, (int) $id);

            return $m === null ? null : ['id' => (int) $id, 'label' => (string) ($m->name ?? $m->number ?? $m->supplier_ref ?? $m->code ?? $m->id)];
        })->filter()->values()->all();
    }

    private function maskSample(ImportDataset $dataset): array
    {
        $sensitive = [];
        foreach ($dataset->profile['columns'] ?? [] as $i => $c) {
            if (in_array($c['kind'] ?? '', ['email', 'phone'], true)) {
                $sensitive[$i] = true;
            }
        }

        return array_map(fn ($row) => array_map(fn ($v, $i) => isset($sensitive[$i]) ? Datasets::maskValue($v) : $v, $row, array_keys($row)), $dataset->sample ?? []);
    }

    /** معاينةٌ مرقَّمة — JSON للتبويب */
    public function preview(Request $request, int|string $id)
    {
        $dataset = $this->find($id);

        try {
            return response()->json(Datasets::preview($dataset, (int) $request->integer('page', 1), (int) $request->integer('per_page', 50), ! $this->may()['manage']));
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /** صفوفُ تنفيذٍ — مرقَّمةً وبحسب النتيجة */
    public function rows(Request $request, int|string $id, int|string $run)
    {
        $dataset = $this->find($id);
        $run = ImportRun::where('dataset_id', $dataset->id)->findOrFail($run);
        $outcome = $request->string('outcome')->toString();

        $page = ImportRow::where('run_id', $run->id)
            ->when($outcome !== '', fn ($q) => $q->where('outcome', $outcome))
            ->orderBy('row_number')->paginate(100)->withQueryString();

        return response()->json([
            'rows' => collect($page->items())->map(fn (ImportRow $r) => [
                'row' => $r->row_number, 'source_ref' => $r->source_ref, 'outcome' => $r->outcome, 'level' => $r->level,
                'match' => $r->match, 'messages' => $r->messages ?? [], 'entity_type' => $r->entity_type ? class_basename($r->entity_type) : null, 'entity_id' => $r->entity_id,
            ])->all(),
            'pagination' => Pagination::meta($page),
        ]);
    }

    /* ─────────────────────────── الكتابة ─────────────────────────── */

    private function guarded(callable $action, string $ok)
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return back()->withErrors(['import' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => $ok, 'type' => 'success']);
    }

    public function profile(int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::IMPORT_MANAGE), 403);
        $dataset = $this->find($id);

        return $this->guarded(fn () => Datasets::profile($dataset, auth()->user()), __('قُرئ الملفّ'));
    }

    public function mapping(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::IMPORT_MANAGE), 403);
        $dataset = $this->find($id);

        $data = $request->validate([
            'columns' => ['nullable', 'array', 'max:150'],
            'columns.*.source' => ['nullable', 'string', 'max:255'],
            'columns.*.target' => ['nullable', 'string', 'max:64'],
            'columns.*.transforms' => ['nullable', 'array', 'max:8'],
            'columns.*.transforms.*' => ['string', Rule::in(Transforms::NAMES)],
            'columns.*.map' => ['nullable', 'array', 'max:200'],
            'columns.*.default' => ['nullable', 'string', 'max:255'],
            'constants' => ['nullable', 'array', 'max:40'],
            'template_id' => ['nullable', 'integer'],
        ]);

        return $this->guarded(fn () => Datasets::map($dataset, ['columns' => $data['columns'] ?? [], 'constants' => $data['constants'] ?? []], auth()->user()), __('حُفظت الخريطة'));
    }

    public function strategy(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::IMPORT_MANAGE), 403);
        $dataset = $this->find($id);
        $data = $request->validate(['strategy' => ['nullable', Rule::in([ImportDataset::HISTORICAL, ImportDataset::OPENING_BALANCE])]]);

        return $this->guarded(fn () => Datasets::strategy($dataset, $data['strategy'] ?? null, auth()->user()), __('حُفظت الاستراتيجيّة'));
    }

    public function resolve(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::IMPORT_MANAGE), 403);
        $dataset = $this->find($id);
        $data = $request->validate(['decisions' => ['required', 'array', 'max:5000'], 'decisions.*' => ['string', 'max:24']]);

        return $this->guarded(fn () => Datasets::resolve($dataset, $data['decisions'], auth()->user()), __('حُفظت القرارات — أعد التجربة'));
    }

    public function dryRun(int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::IMPORT_MANAGE), 403);
        $dataset = $this->find($id);

        return $this->guarded(fn () => Runner::dryRun($dataset, auth()->user()), __('اكتملت التجربة'));
    }

    public function approve(int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::IMPORT_EXECUTE), 403);
        $dataset = $this->find($id);

        return $this->guarded(fn () => Approval::approve($dataset, auth()->user()), __('اعتُمد الاستيراد — نفّذه حين تشاء'));
    }

    public function import(int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::IMPORT_EXECUTE), 403);
        $dataset = $this->find($id);

        return $this->guarded(fn () => Runner::import($dataset, auth()->user()), __('اكتمل الاستيراد'));
    }

    public function rollback(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::IMPORT_ROLLBACK), 403);
        $dataset = $this->find($id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => __('سبب التراجع')]);

        return $this->guarded(fn () => Rollback::execute($dataset, $data['reason'], auth()->user()), __('نُفّذ التراجع'));
    }

    public function cancel(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::IMPORT_MANAGE), 403);
        $dataset = $this->find($id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => __('السبب')]);

        return $this->guarded(fn () => Datasets::cancel($dataset, $data['reason'], auth()->user()), __('أُلغيت المجموعة'));
    }

    public function saveTemplate(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::IMPORT_MANAGE), 403);
        $dataset = $this->find($id);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']], [], ['name' => __('اسم القالب')]);

        return $this->guarded(fn () => Datasets::saveTemplate($dataset, $data['name'], auth()->user()), __('حُفظ القالب'));
    }

    public function applyTemplate(Request $request, int|string $id)
    {
        abort_if(! auth()->user()?->may(Permissions::IMPORT_MANAGE), 403);
        $dataset = $this->find($id);
        $data = $request->validate(['template_id' => ['required', 'integer']]);
        $template = ImportMappingTemplate::where('business_id', $this->bid())->findOrFail($data['template_id']);

        return $this->guarded(fn () => Datasets::applyTemplate($dataset, $template, auth()->user()), __('طُبّق القالب'));
    }

    /** تاريخُ التحويل — قرارٌ ماليٌّ للشركة كلِّها */
    public function cutover(Request $request)
    {
        abort_if(! auth()->user()?->may(Permissions::IMPORT_FINANCIAL), 403);
        $data = $request->validate(['cutover' => ['nullable', 'date']]);

        Cutover::set($this->bid(), $data['cutover'] ?? null);
        Activity::log('updated', 'حدّد تاريخ التحويل — '.($data['cutover'] ?? '—'), ['subject_type' => 'import_dataset']);

        return back()->with('toast', ['msg' => __('حُفظ تاريخ التحويل'), 'type' => 'success']);
    }

    /** التقريرُ الدائم — نصًّا بلا بياناتٍ حسّاسة */
    public function report(int|string $id)
    {
        $dataset = $this->find($id);
        $run = $dataset->importRun;
        abort_if($run === null || $run->report === null, 404);

        return response(Report::markdown($run->report), 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$dataset->code.'-import-report.md"',
        ]);
    }
}
