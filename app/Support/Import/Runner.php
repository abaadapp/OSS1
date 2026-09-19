<?php

namespace App\Support\Import;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\ImportDataset;
use App\Models\ImportLink;
use App\Models\ImportRow;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Activity;
use App\Support\Import\Recipes\Recipe;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * المنفّذ — التجربةُ الجافّة والاستيرادُ من كودٍ واحد، والفرقُ كلمةٌ: أيكتب؟
 *
 * ═══ الوحدةُ تلو الوحدة ═══
 *
 * يُقرأ الملفُّ صفًّا صفًّا (لا كلَّه)، ويُربط كلُّ صفٍّ ويُحوَّل، وتُجمَّع صفوفُ
 * المستند الواحد وحدةً، ثمّ لكلّ وحدة: فحصٌ → مطابقةٌ → قرارٌ → (كتابةٌ).
 * والكتابةُ في معاملةٍ لكلّ وحدة: فاتورةٌ ببنودها كلُّها أو لا شيء. وخطأٌ في
 * وحدةٍ لا يوقف ما بعدها — إلّا عطبًا بنيويًّا (قاعدةٌ لا تجيب) فيُوقَف
 * التنفيذُ ويُقال.
 *
 * ═══ ولا يُكتب الشيءُ مرّتين ═══
 *
 * قبل كلّ كتابة يُسأل `import_links`: أللصفّ أثرٌ من قبل؟ فيُتخطّى. وقيدُ
 * الجدول الفريد يردّ ما سبق القراءةَ بلحظة.
 *
 * ═══ والتجربةُ لا تكتب كيانًا ═══
 *
 * تكتب صفوفَها ونتيجتَها وأثرَها الماليّ المحسوب — لا عميلًا ولا فاتورةً ولا
 * قيدًا. كلُّ ما تراه من «سيُنشأ» حسابٌ في الذاكرة وفهرسٌ في الذاكرة.
 */
final class Runner
{
    public const BATCH = 500;

    /** أخطاءٌ تُحفظ في ملخّص التجربة — لا كلُّها */
    public const KEEP_FINDINGS = 300;

    public static function dryRun(ImportDataset $dataset, ?User $actor = null): ImportRun
    {
        Datasets::assertOpen($dataset);
        self::assertRunnable($dataset);

        return self::execute($dataset, $actor, true);
    }

    public static function import(ImportDataset $dataset, ?User $actor = null): ImportRun
    {
        if (! ($actor?->may(Permissions::IMPORT_EXECUTE) ?? false)) {
            throw new RuntimeException(__('تنفيذُ الاستيراد يحتاج صلاحيّة التنفيذ.'));
        }
        if (Fields::isFinancial($dataset->category) && ! ($actor?->may(Permissions::IMPORT_FINANCIAL) ?? false)) {
            throw new RuntimeException(__('الاستيرادُ الماليّ يحتاج صلاحيّة الاستيراد الماليّ.'));
        }

        /* القفلُ: مجموعةٌ واحدة لا تُستورد مرّتين معًا، والاعتمادُ يُعاد فحصُه تحت القفل */
        $locked = DB::transaction(function () use ($dataset) {
            $locked = ImportDataset::where('business_id', $dataset->business_id)->lockForUpdate()->findOrFail($dataset->id);

            if ($locked->status === ImportDataset::IMPORTING) {
                throw new RuntimeException(__('هذه المجموعة تُستورد الآن.'));
            }
            if (! Approval::stands($locked)) {
                throw new RuntimeException(__('لا اعتمادَ قائمًا على هذه الصورة — أعد التجربة ثمّ الاعتماد.'));
            }
            self::assertRunnable($locked);
            self::assertChecksum($locked);

            $locked->update(['status' => ImportDataset::IMPORTING]);

            return $locked;
        });

        Activity::log('status', 'بدأ استيراد المجموعة '.$locked->code, [
            'subject_id' => $locked->id, 'subject_type' => 'import_dataset', 'icon' => 'database', 'color' => 'primary',
        ]);

        try {
            return self::execute($locked, $actor, false);
        } catch (Throwable $e) {
            $locked->update(['status' => ImportDataset::FAILED]);
            Activity::log('status', 'فشل استيراد المجموعة '.$locked->code.' — '.mb_substr($e->getMessage(), 0, 200), [
                'subject_id' => $locked->id, 'subject_type' => 'import_dataset', 'color' => 'danger',
            ]);
            throw $e;
        }
    }

    /* ─────────────────────────── التنفيذ ─────────────────────────── */

    private static function execute(ImportDataset $dataset, ?User $actor, bool $dry): ImportRun
    {
        $ctx = new Context($dataset, $actor, $dry);
        $recipe = self::recipe($dataset->category, $ctx);
        $mapping = $dataset->mapping;
        $headers = $dataset->columns;
        $grouped = in_array($dataset->category, Fields::GROUPED, true);
        $groupKey = Fields::GROUP_KEY[$dataset->category] ?? null;

        $run = ImportRun::create([
            'business_id' => $dataset->business_id, 'dataset_id' => $dataset->id,
            'kind' => $dry ? ImportRun::DRY_RUN : ImportRun::IMPORT, 'status' => ImportRun::RUNNING,
            'checksum' => $dataset->checksum, 'mapping_version' => $dataset->mapping_version, 'strategy' => $dataset->strategy,
            'user_id' => $actor?->id, 'started_at' => now(),
        ]);

        $totals = ['rows' => 0, 'units' => 0, 'created' => 0, 'linked' => 0, 'skipped' => 0, 'conflicts' => 0, 'errors' => 0, 'warnings' => 0, 'already' => 0];
        $findings = [];
        $pending = [];
        $seen = [];
        $existing = self::existingLinks($dataset, $ctx->index->morph($recipe->type()));
        $resolutions = $dataset->resolutions ?? [];
        $aborted = null;

        $flush = function () use (&$pending) {
            if ($pending !== []) {
                ImportRow::insert($pending);
                $pending = [];
            }
        };

        $handle = function (array $unit, int $firstRow, array $rows, array $mapErrors, array $mapWarnings) use (
            &$totals, &$findings, &$pending, &$existing, $resolutions, $recipe, $ctx, $dataset, $run, $dry, $flush
        ): void {
            $totals['units']++;
            $result = self::unit($recipe, $ctx, $dataset, $run, $unit, $firstRow, $mapErrors, $mapWarnings, $resolutions, $existing, $dry);

            $totals[$result['bucket']]++;
            $totals['warnings'] += count($result['warnings']);
            if ($result['already']) {
                $totals['already']++;
            }

            foreach ($rows as $number) {
                $pending[] = [
                    'business_id' => $dataset->business_id, 'dataset_id' => $dataset->id, 'run_id' => $run->id,
                    'row_number' => $number, 'source_ref' => $dataset->sourceRef($number),
                    'outcome' => $result['outcome'], 'level' => $result['level'],
                    'messages' => json_encode(array_slice(array_merge($result['errors'], $result['warnings']), 0, 20), JSON_UNESCAPED_UNICODE),
                    'match' => $result['match'], 'entity_type' => $result['entity_type'], 'entity_id' => $result['entity_id'],
                    'fingerprint' => null, 'created_at' => now(), 'updated_at' => now(),
                ];
            }

            if (count($findings) < self::KEEP_FINDINGS && ($result['errors'] !== [] || $result['outcome'] === ImportRow::CONFLICT)) {
                $findings[] = ['row' => $firstRow, 'level' => $result['level'], 'outcome' => $result['outcome'],
                    'match' => $result['match'], 'candidates' => $result['candidates'], 'messages' => array_slice($result['errors'], 0, 5)];
            }

            if (count($pending) >= self::BATCH) {
                $flush();
            }
        };

        try {
            $buffer = null;

            foreach (Datasets::reader($dataset)->rows() as $number => $cells) {
                $totals['rows']++;
                $mapped = Mapper::row($dataset->category, $mapping, $headers, $cells);
                $record = $mapped['record'];

                if (! $grouped) {
                    $handle([$record], $number, [$number], $mapped['errors'], $mapped['warnings']);

                    continue;
                }

                $key = Index::code($record[$groupKey] ?? null) ?? '#'.$number;

                if ($buffer !== null && $buffer['key'] === $key) {
                    $buffer['unit'][] = $record;
                    $buffer['rows'][] = $number;
                    $buffer['errors'] = array_merge($buffer['errors'], $mapped['errors']);
                    $buffer['warnings'] = array_merge($buffer['warnings'], $mapped['warnings']);

                    continue;
                }

                if ($buffer !== null) {
                    $handle($buffer['unit'], $buffer['rows'][0], $buffer['rows'], $buffer['errors'], $buffer['warnings']);
                }

                $errors = $mapped['errors'];
                if (isset($seen[$key])) {
                    $errors[] = __('الرقم :n تكرّر في موضعٍ آخر من الملفّ (الصفّ :r) — صفوفُ المستند الواحد تُكتب متتاليةً', ['n' => $record[$groupKey] ?? '', 'r' => $seen[$key]]);
                }
                $seen[$key] = $number;
                $buffer = ['key' => $key, 'unit' => [$record], 'rows' => [$number], 'errors' => $errors, 'warnings' => $mapped['warnings']];
            }

            if ($buffer !== null) {
                $handle($buffer['unit'], $buffer['rows'][0], $buffer['rows'], $buffer['errors'], $buffer['warnings']);
            }
        } catch (Throwable $e) {
            $aborted = $e;
        }

        $flush();

        $status = match (true) {
            $aborted !== null => ImportRun::FAILED,
            $totals['errors'] > 0 => $dry ? ImportRun::FAILED : ImportRun::PARTIAL,
            $totals['conflicts'] > 0 => ImportRun::PARTIAL,
            default => ImportRun::SUCCESS,
        };

        $run->update([
            'status' => $status, 'finished_at' => now(), 'totals' => $totals,
            'financial' => Fields::isFinancial($dataset->category) ? $ctx->financial : null,
            'findings' => $findings,
            'error' => $aborted === null ? null : mb_substr($aborted->getMessage(), 0, 1000),
        ]);

        self::settle($dataset, $run, $dry, $aborted);

        return $run->fresh();
    }

    /**
     * وحدةٌ واحدة: فحصٌ → مطابقةٌ → قرارٌ → كتابةٌ (إن لم تكن تجربة).
     *
     * @return array<string, mixed>
     */
    private static function unit(Recipe $recipe, Context $ctx, ImportDataset $dataset, ImportRun $run, array $unit, int $row,
        array $mapErrors, array $mapWarnings, array $resolutions, array &$existing, bool $dry): array
    {
        $out = ['outcome' => ImportRow::ERROR, 'level' => 'error', 'bucket' => 'errors', 'errors' => $mapErrors, 'warnings' => $mapWarnings,
            'match' => null, 'candidates' => [], 'entity_type' => null, 'entity_id' => null, 'already' => false];
        $type = $ctx->index->morph($recipe->type());

        /* أثرٌ من استيرادٍ سابق: الصفُّ كُتب من قبل — لا يُكتب ثانيةً */
        if (isset($existing[$row])) {
            $out['outcome'] = $dry ? ImportRow::WOULD_SKIP : ImportRow::SKIPPED;
            $out['level'] = 'info';
            $out['bucket'] = 'skipped';
            $out['errors'] = [];
            $out['warnings'][] = __('مستورَدٌ من قبل — الأثر :ref', ['ref' => $dataset->sourceRef($row)]);
            $out['entity_type'] = $type;
            $out['entity_id'] = $existing[$row];
            $out['already'] = true;

            return $out;
        }

        if ($mapErrors !== []) {
            return $out;
        }

        try {
            $quality = $recipe->check($unit);
        } catch (Throwable $e) {
            $quality = ['errors' => [mb_substr($e->getMessage(), 0, 300)], 'warnings' => []];
        }
        $out['errors'] = $quality['errors'];
        $out['warnings'] = array_merge($out['warnings'], $quality['warnings']);

        if ($out['errors'] !== []) {
            return $out;
        }

        $verdict = $recipe->match($unit);
        $out['match'] = $verdict['kind'];
        $out['candidates'] = $verdict['candidates'];

        $decision = $resolutions[(string) $row] ?? null;
        $action = null;
        $target = null;

        if ($decision === 'skip') {
            $action = 'skip';
        } elseif ($decision === 'create') {
            $action = 'create';
        } elseif (is_string($decision) && str_starts_with($decision, 'link:')) {
            $target = (int) substr($decision, 5);
            if ($ctx->index->get($recipe->type(), $target) === null) {
                $out['errors'][] = __('قرارُ الربط يشير إلى سجلٍّ ليس من شركتك أو لم يعد موجودًا');

                return $out;
            }
            $action = 'link';
        } elseif ($verdict['kind'] === 'exact') {
            $action = $recipe->onExact();
            $target = $verdict['id'];
            if ($action === 'link' && $target === null) {
                $action = 'skip';
            }
        } elseif ($verdict['kind'] === 'new') {
            $action = 'create';
        } else {
            $out['outcome'] = ImportRow::CONFLICT;
            $out['level'] = 'warning';
            $out['bucket'] = 'conflicts';
            $out['warnings'][] = $verdict['why'] ?? __('تطابقٌ محتمَل يحتاج قرارًا');

            return $out;
        }

        if ($action === 'skip') {
            $out['outcome'] = $dry ? ImportRow::WOULD_SKIP : ImportRow::SKIPPED;
            $out['level'] = 'info';
            $out['bucket'] = 'skipped';
            $out['entity_type'] = $type;
            $out['entity_id'] = $target ?? $verdict['id'];
            if (! $dry && $out['entity_id'] !== null) {
                self::trace($dataset, $run, $row, $recipe, $unit, $type, (int) $out['entity_id'], ImportLink::LINKED);
                $existing[$row] = (int) $out['entity_id'];
            }

            return $out;
        }

        try {
            $recipe->impact($unit);
        } catch (Throwable) {
        }

        if ($dry) {
            $out['outcome'] = $action === 'link' ? ImportRow::WOULD_LINK : ImportRow::WOULD_CREATE;
            $out['level'] = 'info';
            $out['bucket'] = $action === 'link' ? 'linked' : 'created';
            $out['entity_type'] = $type;
            $out['entity_id'] = $target;

            /* والفهرسُ يتعلّم ما «سيُنشأ»: صفٌّ لاحق يشير إليه يجده */
            if ($action === 'create') {
                $ghost = self::ghost($recipe, $unit);
                if ($ghost !== null) {
                    $ctx->index->remember($recipe->type(), $ghost, $recipe->keys($ghost), $recipe->externalId($unit));
                }
            }

            return $out;
        }

        try {
            $entity = DB::transaction(function () use ($recipe, $unit, $row, $action, $target, $ctx, $dataset, $run, $type) {
                if ($action === 'link') {
                    $model = $ctx->index->get($recipe->type(), (int) $target);
                    $recipe->link($unit, $model);
                    self::trace($dataset, $run, $row, $recipe, $unit, $type, (int) $model->getKey(), ImportLink::LINKED);

                    return $model;
                }

                $model = $recipe->create($unit, $row);
                self::trace($dataset, $run, $row, $recipe, $unit, $model->getMorphClass(), (int) $model->getKey(), ImportLink::CREATED);

                return $model;
            });
        } catch (Throwable $e) {
            if (self::structural($e)) {
                throw $e;
            }
            $out['errors'][] = mb_substr($e->getMessage(), 0, 300);
            $out['outcome'] = ImportRow::ERROR;

            return $out;
        }

        if ($action === 'create') {
            $ctx->index->remember(method_exists($recipe, 'indexType') ? $recipe::indexType($entity) : $recipe->type(), $entity, $recipe->keys($entity), $recipe->externalId($unit));
        }

        $existing[$row] = (int) $entity->getKey();
        $out['outcome'] = $action === 'link' ? ImportRow::LINKED : ImportRow::CREATED;
        $out['level'] = 'info';
        $out['bucket'] = $action === 'link' ? 'linked' : 'created';
        $out['entity_type'] = $entity->getMorphClass();
        $out['entity_id'] = (int) $entity->getKey();

        return $out;
    }

    /** أثرُ الصفّ — «DATA-0001#ROW-42 → Customer#17» */
    private static function trace(ImportDataset $dataset, ImportRun $run, int $row, Recipe $recipe, array $unit, string $type, int $id, string $action): void
    {
        ImportLink::create([
            'business_id' => $dataset->business_id, 'dataset_id' => $dataset->id, 'run_id' => $run->id,
            'row_number' => $row, 'source_ref' => $dataset->sourceRef($row), 'external_id' => $recipe->externalId($unit),
            'linkable_type' => $type, 'linkable_id' => $id, 'action' => $action,
        ]);
    }

    /** كيانٌ في الذاكرة لا يُحفظ — كي تجده الصفوفُ اللاحقة في التجربة */
    private static function ghost(Recipe $recipe, array $unit): ?Model
    {
        $class = match ($recipe->type()) {
            'customer' => Customer::class,
            'supplier' => Supplier::class,
            'product' => Product::class,
            'project' => Project::class,
            'quotation' => Quotation::class,
            'invoice' => CustomerInvoice::class,
            'payment' => CustomerPayment::class,
            'expense' => Expense::class,
            'supplier_invoice' => SupplierInvoice::class,
            default => null,
        };
        if ($class === null) {
            return null;
        }

        $r = $unit[0];
        $ghost = new $class;
        $ghost->forceFill(array_intersect_key($r, array_flip([
            'name', 'name_en', 'legal_name', 'phone', 'email', 'tax_number', 'customer_reference', 'sku', 'code', 'number', 'total', 'amount',
            'customer_id', 'supplier_id', 'issued_at', 'occurred_at', 'spent_at', 'type', 'external_reference', 'supplier_ref',
        ])));
        /* معرِّفٌ سالبٌ فريد في الذاكرة — لا يصطدم بصفٍّ حقيقيّ */
        $ghost->setAttribute($ghost->getKeyName(), -(crc32(json_encode($r, JSON_UNESCAPED_UNICODE)) % 1_000_000_000));

        return $ghost;
    }

    /** الصفوفُ التي لها أثرٌ قائم من استيرادٍ سابق لهذه المجموعة */
    private static function existingLinks(ImportDataset $dataset, string $type): array
    {
        return ImportLink::where('dataset_id', $dataset->id)->whereNull('rolled_back_at')
            ->pluck('linkable_id', 'row_number')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * خطأٌ بنيويّ يوقف التنفيذ — لا خطأُ صفّ.
     *
     * انقطاعُ الاتصال بالقاعدة (SQLSTATE 08xxx) أو خطأُ ذاكرةٍ يوقف الكلَّ:
     * ما بعده لن ينجح. أمّا قيدٌ على صفٍّ (فريدٌ · غيرُ فارغ · مفتاحٌ أجنبيّ)
     * فخطأُ ذلك الصفّ وحده — يُقال باسمه ويمضي التنفيذ.
     */
    private static function structural(Throwable $e): bool
    {
        if ($e instanceof \Error) {
            return true;
        }

        $state = (string) ($e instanceof QueryException ? ($e->getPrevious()?->getCode() ?? $e->getCode()) : $e->getCode());

        return ($e instanceof \PDOException || $e instanceof QueryException)
            && (str_starts_with($state, '08') || str_contains(strtolower($e->getMessage()), 'gone away'));
    }

    private static function assertRunnable(ImportDataset $dataset): void
    {
        if ($dataset->columns === null) {
            throw new RuntimeException(__('اقرأ الملفّ أوّلًا.'));
        }
        if (! $dataset->mapping || Mapper::missing($dataset->category, $dataset->mapping) !== []) {
            throw new RuntimeException(__('الخريطة ناقصة: :f', ['f' => implode('، ', array_map(
                fn ($k) => Fields::for($dataset->category)[$k]['label'] ?? $k, Mapper::missing($dataset->category, $dataset->mapping ?? []),
            ))]));
        }
        if (Fields::isFinancial($dataset->category) && $dataset->strategy === null) {
            throw new RuntimeException(__('حدّد استراتيجيّة الاستيراد الماليّ أوّلًا: مستنداتٌ كاملة أو رصيدٌ افتتاحيّ.'));
        }
    }

    private static function assertChecksum(ImportDataset $dataset): void
    {
        $path = Datasets::absolutePath($dataset);
        if (! is_file($path) || hash_file('sha256', $path) !== $dataset->checksum) {
            throw new RuntimeException(__('بصمة الملفّ تغيّرت — لا يُستورد.'));
        }
    }

    /** ما يصير إليه سجلُّ المجموعة بعد التنفيذ */
    private static function settle(ImportDataset $dataset, ImportRun $run, bool $dry, ?Throwable $aborted): void
    {
        $t = $run->totals;

        if ($dry) {
            $dataset->update([
                'dry_run_id' => $run->id,
                'validation' => ['run_id' => $run->id, 'at' => now()->toDateTimeString(), 'totals' => $t, 'findings' => array_slice($run->findings ?? [], 0, 100)],
                'status' => match (true) {
                    $aborted !== null, $t['errors'] > 0 => ImportDataset::VALIDATION_FAILED,
                    $t['conflicts'] > 0 => ImportDataset::NEEDS_REVIEW,
                    default => ImportDataset::READY,
                },
                'approved_at' => null, 'approved_by' => null, 'approved_fingerprint' => null,
            ]);

            Activity::log('status', 'جرّب استيراد المجموعة '.$dataset->code.' — سيُنشأ '.$t['created'].' · يُربط '.$t['linked'].' · يُتخطّى '.$t['skipped'].' · تعارضات '.$t['conflicts'].' · أخطاء '.$t['errors'], [
                'subject_id' => $dataset->id, 'subject_type' => 'import_dataset', 'icon' => 'flask-conical',
                'color' => $t['errors'] > 0 ? 'danger' : ($t['conflicts'] > 0 ? 'warning' : 'success'),
            ]);

            return;
        }

        $reconciliation = Reconciliation::for($dataset->fresh(), $run);
        $run->update(['reconciliation' => $reconciliation, 'report' => Report::build($dataset->fresh(), $run->fresh())]);

        $dataset->update([
            'import_run_id' => $run->id,
            'imported_at' => now(),
            'imported_by' => $run->user_id,
            'reconciliation' => $reconciliation,
            'status' => match (true) {
                $aborted !== null => ImportDataset::FAILED,
                $reconciliation['balanced'] && $t['errors'] === 0 && $t['conflicts'] === 0 => ImportDataset::VERIFIED,
                default => ImportDataset::IMPORTED,
            },
        ] + ($reconciliation['balanced'] && $t['errors'] === 0 ? ['verified_at' => now()] : []));

        Activity::log('status', ($aborted !== null ? 'توقّف استيراد المجموعة ' : 'اكتمل استيراد المجموعة ').$dataset->code.' — أُنشئ '.$t['created'].' · رُبط '.$t['linked'].' · تُخطّي '.$t['skipped'].' · أخطاء '.$t['errors'].($reconciliation['balanced'] ? ' · مطابَق' : ' · فرقُ مطابقة'), [
            'subject_id' => $dataset->id, 'subject_type' => 'import_dataset', 'icon' => 'database',
            'color' => $aborted !== null || $t['errors'] > 0 ? 'danger' : 'success',
        ]);
    }

    public static function recipe(string $category, Context $ctx): Recipe
    {
        return match ($category) {
            'customers' => new Recipes\Customers($ctx),
            'contacts' => new Recipes\Contacts($ctx),
            'suppliers' => new Recipes\Suppliers($ctx),
            'services' => new Recipes\Services($ctx),
            'projects' => new Recipes\Projects($ctx),
            'quotations' => new Recipes\Quotations($ctx),
            'customer_invoices' => new Recipes\CustomerInvoices($ctx),
            'payments' => new Recipes\Payments($ctx),
            'expenses' => new Recipes\Expenses($ctx),
            'supplier_invoices' => new Recipes\SupplierInvoices($ctx),
            'opening_balances' => new Recipes\OpeningBalances($ctx),
            default => throw new RuntimeException(__('صنفٌ غير معروف: :c', ['c' => $category])),
        };
    }
}
