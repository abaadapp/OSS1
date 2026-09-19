<?php

namespace App\Support\Import;

use App\Models\Business;
use App\Models\ImportDataset;
use App\Models\ImportMappingTemplate;
use App\Models\User;
use App\Support\Activity;
use App\Support\Import\Readers\Reader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * سجلُّ المجموعات — التسجيلُ والقراءةُ والربطُ والحال.
 *
 * ═══ الملفُّ في القرص الخاصّ ═══
 *
 * `storage/app/private/imports/{business}/{code}.{ext}` — لا رابطَ عامًّا ولا
 * مسارًا يُعرض. ويُقرأ من هنا وحدَه: الخزنةُ في المستودع مجلّدُ عملٍ للبشر،
 * والتطبيقُ يملك نسختَه.
 *
 * ═══ والحالُ تنتقل من هنا وحدَها ═══
 *
 * كلُّ انتقالٍ فعلٌ مسمًّى يفحص ما قبله: لا ربطَ قبل قراءة، ولا تجربةَ قبل
 * ربطٍ مكتمل، ولا اعتمادَ بلا تجربةٍ ناجحة، ولا استيرادَ بلا اعتماد. وتعديلُ
 * الربط أو الاستراتيجيّة بعد الاعتماد **يُسقطه** — انظر `Approval`.
 */
final class Datasets
{
    public const DISK = 'local';

    public const PREFIX = 'DATA-';

    /**
     * تسجيلُ ملفٍّ مرفوعٍ من الشاشة.
     *
     * @param  array<string, mixed>  $data  `name` · `category` · `source` · `notes`
     */
    public static function upload(int $businessId, UploadedFile $file, array $data, ?User $actor = null): ImportDataset
    {
        $extension = FileGuard::extension((string) $file->getClientOriginalName());
        FileGuard::inspect((string) $file->getRealPath(), $extension);

        return self::register($businessId, (string) $file->getRealPath(), (string) $file->getClientOriginalName(), $extension, $data, $actor);
    }

    /**
     * تسجيلُ ملفٍّ من مسارٍ محلّيّ — من سطر الأوامر (الخزنة).
     *
     * @param  array<string, mixed>  $data
     */
    public static function registerPath(int $businessId, string $path, array $data, ?User $actor = null): ImportDataset
    {
        $real = realpath($path);

        if ($real === false || ! is_file($real)) {
            throw new RuntimeException(__('الملفّ غير موجود.'));
        }

        $extension = FileGuard::extension(basename($real));
        FileGuard::inspect($real, $extension);

        return self::register($businessId, $real, basename($real), $extension, $data, $actor);
    }

    /** @param  array<string, mixed>  $data */
    private static function register(int $businessId, string $realPath, string $originalName, string $extension, array $data, ?User $actor): ImportDataset
    {
        $category = (string) ($data['category'] ?? '');

        if (! in_array($category, Fields::CATEGORIES, true)) {
            throw new RuntimeException(__('صنفٌ غير معروف: :c', ['c' => $category]));
        }

        $checksum = hash_file('sha256', $realPath);
        $size = (int) filesize($realPath);

        /* والملفُّ نفسُه مرّتين يُقال: البصمةُ ذاتُها مسجَّلةٌ باسمٍ آخر */
        $twin = ImportDataset::where('business_id', $businessId)->where('checksum', $checksum)
            ->whereNotIn('status', [ImportDataset::CANCELLED])->first();
        if ($twin !== null) {
            throw new RuntimeException(__('هذا الملفّ مسجَّلٌ من قبل باسم :code.', ['code' => $twin->code]));
        }

        return DB::transaction(function () use ($businessId, $realPath, $originalName, $extension, $data, $actor, $category, $checksum, $size) {
            $code = self::nextCode($businessId);
            $path = 'imports/'.$businessId.'/'.$code.'.'.$extension;

            $stream = fopen($realPath, 'r');
            if ($stream === false || ! Storage::disk(self::DISK)->writeStream($path, $stream)) {
                throw new RuntimeException(__('تعذّر حفظ الملفّ.'));
            }
            if (is_resource($stream)) {
                fclose($stream);
            }

            $dataset = ImportDataset::create([
                'business_id' => $businessId,
                'code' => $code,
                'name' => self::text($data['name'] ?? null, 160) ?? pathinfo($originalName, PATHINFO_FILENAME),
                'category' => $category,
                'source' => self::text($data['source'] ?? null, 160),
                'original_filename' => mb_substr($originalName, 0, 255),
                'extension' => $extension,
                'mime' => mime_content_type($realPath) ?: null,
                'path' => $path,
                'checksum' => $checksum,
                'size' => $size,
                'status' => ImportDataset::REGISTERED,
                'notes' => self::text($data['notes'] ?? null, 5000),
                'metadata' => ['registered_via' => $data['via'] ?? 'upload'],
                'created_by' => $actor?->id,
            ]);

            Activity::log('created', 'سجّل مجموعة بيانات '.$dataset->code.' — '.$dataset->name.' ('.Fields::label($category).')', [
                'business_id' => $businessId, 'subject_id' => $dataset->id, 'subject_type' => 'import_dataset', 'icon' => 'database',
            ]);

            return $dataset;
        });
    }

    /** «DATA-0001» — تسلسلٌ لا يُعاد استعمالُه، تحت قفل صفّ الشركة */
    public static function nextCode(int $businessId): string
    {
        return DB::transaction(function () use ($businessId) {
            Business::whereKey($businessId)->lockForUpdate()->first();

            $highest = (int) ImportDataset::where('business_id', $businessId)
                ->get(['code'])->map(fn ($r) => (int) substr((string) $r->code, strlen(self::PREFIX)))->max();

            return self::PREFIX.str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
        });
    }

    /* ─────────────────────────── القراءة ─────────────────────────── */

    public static function absolutePath(ImportDataset $dataset): string
    {
        return Storage::disk(self::DISK)->path($dataset->path);
    }

    public static function reader(ImportDataset $dataset): Reader
    {
        $path = self::absolutePath($dataset);

        if (! is_file($path)) {
            throw new RuntimeException(__('ملفّ المجموعة لم يعد على القرص.'));
        }
        if (hash_file('sha256', $path) !== $dataset->checksum) {
            throw new RuntimeException(__('بصمة الملفّ على القرص لا تطابق ما سُجّل — لا يُقرأ.'));
        }

        return FileGuard::reader($path, $dataset->extension);
    }

    /** القراءةُ الأولى: الأعمدةُ والعيّنةُ والظنون — وتنتقل إلى «تحتاج ربطًا» */
    public static function profile(ImportDataset $dataset, ?User $actor = null): ImportDataset
    {
        self::assertOpen($dataset);

        $reader = self::reader($dataset);
        $profile = Profiler::profile($reader);

        $dataset->update([
            'columns' => $reader->headers(),
            'column_count' => $reader->columnCount(),
            'row_count' => $profile['row_count'],
            'profile' => ['columns' => $profile['columns'], 'duplicates' => $profile['duplicates'], 'formulas' => $profile['formulas'], 'probed' => $profile['probed']],
            'sample' => $profile['sample'],
            'status' => $dataset->mapping ? ImportDataset::MAPPED : ImportDataset::NEEDS_MAPPING,
        ]);

        Activity::log('updated', 'قرأ مجموعة البيانات '.$dataset->code.' — '.$profile['row_count'].' صفًّا و'.$reader->columnCount().' عمودًا', [
            'subject_id' => $dataset->id, 'subject_type' => 'import_dataset',
        ]);

        return $dataset->fresh();
    }

    /**
     * معاينةٌ محدودة — صفحةٌ من الصفوف الخام، والقيمُ الحسّاسة مقنَّعةٌ لمن لا يملك رؤيتها.
     *
     * @return array{rows: list<array{number: int, cells: list<mixed>}>, page: int, per_page: int, total: int}
     */
    public static function preview(ImportDataset $dataset, int $page = 1, int $perPage = 50, bool $mask = false): array
    {
        $perPage = max(10, min(200, $perPage));
        $page = max(1, $page);
        $skip = ($page - 1) * $perPage;
        $rows = [];
        $sensitive = self::sensitiveColumns($dataset);

        foreach (self::reader($dataset)->rows() as $number => $cells) {
            if ($number <= $skip) {
                continue;
            }
            if (count($rows) >= $perPage) {
                break;
            }
            $rows[] = ['number' => $number, 'cells' => array_map(
                fn ($v, $i) => $mask && isset($sensitive[$i]) ? self::maskValue($v) : Profiler::display($v),
                $cells, array_keys($cells),
            )];
        }

        return ['rows' => $rows, 'page' => $page, 'per_page' => $perPage, 'total' => (int) ($dataset->row_count ?? 0)];
    }

    /** أعمدةٌ تبدو بريدًا أو هاتفًا — تُقنَّع في المعاينة لمن لا يملك المال والبيانات */
    private static function sensitiveColumns(ImportDataset $dataset): array
    {
        $out = [];
        foreach ($dataset->profile['columns'] ?? [] as $i => $column) {
            if (in_array($column['kind'] ?? '', ['email', 'phone'], true)) {
                $out[$i] = true;
            }
        }

        return $out;
    }

    public static function maskValue(mixed $value): ?string
    {
        if (Transforms::blank($value)) {
            return null;
        }
        $text = (string) $value;
        $len = mb_strlen($text);

        return $len <= 4 ? str_repeat('•', $len) : mb_substr($text, 0, 2).str_repeat('•', min(8, $len - 4)).mb_substr($text, -2);
    }

    /* ─────────────────────────── الربط ─────────────────────────── */

    /**
     * حفظُ خريطةٍ — ويرتفع رقمُ إصدارها، ويسقط اعتمادٌ سابق.
     *
     * @param  array<string, mixed>  $mapping
     */
    public static function map(ImportDataset $dataset, array $mapping, ?User $actor = null, ?int $templateId = null): ImportDataset
    {
        self::assertOpen($dataset);

        if ($dataset->columns === null) {
            throw new RuntimeException(__('اقرأ الملفّ أوّلًا.'));
        }

        $clean = Mapper::validate($dataset->category, $dataset->columns, $mapping);
        $missing = Mapper::missing($dataset->category, $clean);

        $dataset->update([
            'mapping' => $clean,
            'mapping_version' => (int) $dataset->mapping_version + 1,
            'template_id' => $templateId,
            'status' => $missing === [] ? ImportDataset::MAPPED : ImportDataset::NEEDS_MAPPING,
            'validation' => null,
            'resolutions' => null,
        ]);
        Approval::revoke($dataset, __('تغيّرت الخريطة'));

        Activity::log('updated', 'ربط أعمدة المجموعة '.$dataset->code.' — الإصدار '.$dataset->mapping_version, [
            'subject_id' => $dataset->id, 'subject_type' => 'import_dataset',
        ]);

        return $dataset->fresh();
    }

    /** الاستراتيجيّةُ الماليّة (§17) — مستنداتٌ كاملة أو رصيدٌ افتتاحيٌّ فقط؛ وتغييرُها يُسقط الاعتماد */
    public static function strategy(ImportDataset $dataset, ?string $strategy, ?User $actor = null): ImportDataset
    {
        self::assertOpen($dataset);

        if ($strategy !== null && ! in_array($strategy, [ImportDataset::HISTORICAL, ImportDataset::OPENING_BALANCE], true)) {
            throw new RuntimeException(__('استراتيجيّةٌ غير معروفة.'));
        }
        if ($strategy === ImportDataset::OPENING_BALANCE && $dataset->category !== 'opening_balances') {
            throw new RuntimeException(__('استراتيجيّة الرصيد الافتتاحيّ لصنف «الأرصدة الافتتاحيّة» وحده — المستنداتُ الكاملةُ تُستورد تاريخيًّا.'));
        }
        if ($strategy === ImportDataset::HISTORICAL && $dataset->category === 'opening_balances') {
            throw new RuntimeException(__('الأرصدةُ الافتتاحيّة لا تُستورد مستنداتٍ تاريخيّة.'));
        }

        $dataset->update(['strategy' => $strategy, 'validation' => null]);
        Approval::revoke($dataset, __('تغيّرت الاستراتيجيّة'));

        Activity::log('updated', 'حدّد استراتيجيّة المجموعة '.$dataset->code.' — '.($strategy ?? '—'), [
            'subject_id' => $dataset->id, 'subject_type' => 'import_dataset',
        ]);

        return $dataset->fresh();
    }

    /**
     * قراراتُ التكرار — صفٌّ ⇽ create · link:<id> · skip
     *
     * @param  array<int|string, string>  $decisions
     */
    public static function resolve(ImportDataset $dataset, array $decisions, ?User $actor = null): ImportDataset
    {
        self::assertOpen($dataset);

        $clean = [];
        foreach ($decisions as $row => $decision) {
            $row = (int) $row;
            $decision = trim((string) $decision);
            if ($row <= 0 || $decision === '') {
                continue;
            }
            if (! in_array($decision, ['create', 'skip'], true) && ! preg_match('/^link:\d+$/', $decision)) {
                throw new RuntimeException(__('قرارٌ غير معروف للصفّ :row.', ['row' => $row]));
            }
            $clean[(string) $row] = $decision;
        }

        $dataset->update(['resolutions' => array_replace($dataset->resolutions ?? [], $clean)]);
        Approval::revoke($dataset, __('تغيّرت قرارات التكرار'));

        Activity::log('updated', 'قرّر في تكرارات المجموعة '.$dataset->code.' — '.count($clean).' صفًّا', [
            'subject_id' => $dataset->id, 'subject_type' => 'import_dataset',
        ]);

        return $dataset->fresh();
    }

    public static function cancel(ImportDataset $dataset, string $reason, ?User $actor = null): ImportDataset
    {
        if ($dataset->isImported() || $dataset->status === ImportDataset::IMPORTING) {
            throw new RuntimeException(__('لا تُلغى مجموعةٌ استُوردت — التراجعُ بابُه آخر.'));
        }

        $dataset->update(['status' => ImportDataset::CANCELLED, 'cancelled_at' => now(),
            'metadata' => ($dataset->metadata ?? []) + ['cancel_reason' => mb_substr($reason, 0, 255)]]);

        Activity::log('deleted', 'ألغى مجموعة البيانات '.$dataset->code.' — '.$reason, [
            'subject_id' => $dataset->id, 'subject_type' => 'import_dataset',
        ]);

        return $dataset->fresh();
    }

    /* ─────────────────────────── القوالب ─────────────────────────── */

    /** حفظُ خريطة المجموعة قالبًا يُعاد استعمالُه — أو تحديثُ قالبٍ باسمه */
    public static function saveTemplate(ImportDataset $dataset, string $name, ?User $actor = null): ImportMappingTemplate
    {
        $name = self::text($name, 120) ?? throw new RuntimeException(__('اكتب اسم القالب.'));

        if (! $dataset->mapping) {
            throw new RuntimeException(__('لا خريطةَ تُحفظ بعد.'));
        }

        $template = ImportMappingTemplate::where('business_id', $dataset->business_id)->where('name', $name)->first();

        if ($template === null) {
            return ImportMappingTemplate::create([
                'business_id' => $dataset->business_id, 'name' => $name, 'category' => $dataset->category,
                'mapping' => $dataset->mapping, 'version' => 1, 'created_by' => $actor?->id,
            ]);
        }
        if ($template->category !== $dataset->category) {
            throw new RuntimeException(__('القالب «:n» لصنفٍ آخر.', ['n' => $name]));
        }

        $template->update(['mapping' => $dataset->mapping, 'version' => (int) $template->version + 1]);

        return $template->fresh();
    }

    /** تطبيقُ قالبٍ على مجموعة — الأعمدةُ الغائبةُ عن الملفّ تُطرح ويُقال */
    public static function applyTemplate(ImportDataset $dataset, ImportMappingTemplate $template, ?User $actor = null): ImportDataset
    {
        if ((int) $template->business_id !== (int) $dataset->business_id || $template->category !== $dataset->category) {
            throw new RuntimeException(__('هذا القالب ليس لهذا الصنف.'));
        }

        $headers = $dataset->columns ?? [];
        $mapping = $template->mapping;
        $mapping['columns'] = array_values(array_filter($mapping['columns'] ?? [], fn ($c) => in_array($c['source'] ?? '', $headers, true)));

        return self::map($dataset, $mapping, $actor, $template->id);
    }

    /* ─────────────────────────── أدوات ─────────────────────────── */

    public static function assertOpen(ImportDataset $dataset): void
    {
        if (! $dataset->isOpen()) {
            throw new RuntimeException(__('هذه المجموعة :s — لا تُعدَّل.', ['s' => self::statusLabel($dataset->status)]));
        }
    }

    public static function statusLabel(string $status): string
    {
        return __(match ($status) {
            ImportDataset::REGISTERED => 'مسجَّلة',
            ImportDataset::PROFILED => 'مقروءة',
            ImportDataset::NEEDS_MAPPING => 'تحتاج ربطًا',
            ImportDataset::MAPPED => 'مربوطة',
            ImportDataset::VALIDATION_FAILED => 'فشل الفحص',
            ImportDataset::NEEDS_REVIEW => 'تنتظر مراجعة',
            ImportDataset::READY => 'جاهزة',
            ImportDataset::APPROVED => 'معتمَدة',
            ImportDataset::IMPORTING => 'تُستورد',
            ImportDataset::IMPORTED => 'مستورَدة',
            ImportDataset::VERIFIED => 'مطابَقة',
            ImportDataset::FAILED => 'فشلت',
            ImportDataset::CANCELLED => 'ملغاة',
            default => $status,
        });
    }

    public static function text(mixed $value, int $max): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
