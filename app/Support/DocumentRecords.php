<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\DocumentCategory;
use App\Models\DocumentRecord;
use App\Models\DocumentRecordFile;
use App\Models\EventOperation;
use App\Models\Organizer;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Automation\Settings as AutomationSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * مستنداتُ الشركة الداخليّة — حفظٌ ونسخٌ وانتهاءٌ يُسمع قبل وقوعه.
 *
 * ═══ الحالُ من التاريخ ═══
 *
 * لا عمودَ «قريب الانتهاء» يتعفّن: `DocumentRecord::status()` تقرأ تاريخَ الانتهاء
 * بتوقيت الشركة اليوم. والأرشفةُ وحدها قرارٌ يُخزَّن.
 *
 * ═══ الربطُ بقائمةٍ مغلقة ═══
 *
 * `documentable_type` مفتاحٌ قصير (`project` · `customer` · `supplier` · `employee` ·
 * `organizer` · `event_operation`) لا اسمُ صنف، والمعرّفُ يُتحقّق أنّه من الشركة نفسِها
 * قبل الحفظ. فلا يُربط مستندٌ بصفّ شركةٍ أخرى، ولا يُخزَّن اسمُ صنفٍ يتغيّر.
 *
 * ═══ والملفُّ خاصّ ═══
 *
 * على قرص `local` الخاصّ تحت `documents/{business}` — لا على `public` — ويُفتح من
 * بابٍ يسأل الشركةَ والقسم (`DocumentController::file`). وكلُّ نسخةٍ تُحفظ صفًّا في
 * `document_record_files` فلا يُمحى ما كان يثبته الملفُّ القديم.
 */
final class DocumentRecords
{
    /** «قريبُ الانتهاء» — ما بقي له هذا العددُ من الأيّام فأقلّ */
    public const EXPIRING_DAYS = 30;

    public const MAX_KB = 10240;

    /** @var list<string> */
    public const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'doc', 'docx', 'xls', 'xlsx'];

    /** @var array<string, class-string> ما يُربط به المستند — ولا غيرُه */
    public const LINKS = [
        'project' => Project::class,
        'customer' => Customer::class,
        'supplier' => Supplier::class,
        'employee' => User::class,
        'organizer' => Organizer::class,
        'event_operation' => EventOperation::class,
    ];

    public const LINK_LABELS = [
        'project' => 'مشروع', 'customer' => 'عميل', 'supplier' => 'مورد',
        'employee' => 'موظف', 'organizer' => 'منظم', 'event_operation' => 'فعالية',
    ];

    public const STATUS_LABELS = [
        DocumentRecord::ACTIVE => 'نشط', DocumentRecord::EXPIRING => 'قريب الانتهاء',
        DocumentRecord::EXPIRED => 'منتهٍ', DocumentRecord::ARCHIVED => 'مؤرشف',
    ];

    /** تصنيفاتٌ تبدأ بها الشركة — تُعدَّل وتُعطَّل ويُزاد عليها */
    public const DEFAULT_CATEGORIES = [
        'سجل تجاري', 'شهادة ضريبة', 'تأمين', 'عقد', 'تصريح', 'رخصة',
        'مستند موظف', 'مستند مورد', 'مستند مشروع', 'مستند فعالية', 'أخرى',
    ];

    public static function today(int $businessId): Carbon
    {
        return Carbon::now(AutomationSettings::for($businessId)->timezone)->startOfDay();
    }

    public static function ensureCategories(int $businessId): void
    {
        if (DocumentCategory::where('business_id', $businessId)->exists()) {
            return;
        }

        foreach (self::DEFAULT_CATEGORIES as $i => $name) {
            DocumentCategory::create(['business_id' => $businessId, 'name' => $name, 'sort_order' => $i]);
        }
    }

    /* ─────────────────────────── الحفظ ─────────────────────────── */

    /**
     * إضافةُ مستندٍ أو تعديلُه — والملفُّ إن جاء نسخةٌ جديدة لا تمحو ما قبلها.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(int $businessId, array $data, ?UploadedFile $file = null, ?User $actor = null, ?DocumentRecord $document = null): DocumentRecord
    {
        $fields = self::fields($businessId, $data, $document);

        if ($document === null && $file === null) {
            throw new RuntimeException(__('أرفق ملف المستند.'));
        }

        /* الملفُّ قبل المعاملة، ويُمحى إن سقطت — كما في أمر الشراء والمصروف */
        $stored = $file?->store("documents/{$businessId}", 'local');

        try {
            return DB::transaction(function () use ($businessId, $fields, $file, $stored, $actor, $document) {
                if ($document === null) {
                    $document = DocumentRecord::create($fields + ['business_id' => $businessId, 'created_by' => $actor?->id, 'updated_by' => $actor?->id]);
                    Activity::log('created', 'أضاف المستند «'.$document->title.'»'.self::linkPhrase($document), self::log($document, 'file-plus'));
                } else {
                    $responsibleBefore = (int) ($document->responsible_user_id ?? 0);
                    $document->update($fields + ['updated_by' => $actor?->id]);
                    Activity::log('updated', 'عدّل المستند «'.$document->title.'»', self::log($document, 'pencil'));

                    if ($responsibleBefore !== (int) ($document->responsible_user_id ?? 0)) {
                        Activity::log('updated', 'غيّر مسؤول المستند «'.$document->title.'» إلى '.($document->fresh('responsible')->responsible?->name ?? 'لا أحد'), self::log($document, 'user-check'));
                    }
                }

                if ($file !== null && $stored) {
                    self::attach($document, $file, $stored, $actor);
                }

                return $document->fresh(['category', 'responsible']);
            });
        } catch (Throwable $e) {
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }

            throw $e;
        }
    }

    /** أرشفةٌ لا حذف — يبقى المستندُ وملفّاتُه، ويخرج من التنبيه والشارة */
    public static function archive(DocumentRecord $document, ?User $actor = null): DocumentRecord
    {
        if ($document->archived_at !== null) {
            return $document;
        }

        $document->update(['archived_at' => now(), 'updated_by' => $actor?->id]);
        Activity::log('status', 'أرشف المستند «'.$document->title.'»', self::log($document, 'archive'));

        return $document->fresh();
    }

    public static function restore(DocumentRecord $document, ?User $actor = null): DocumentRecord
    {
        if ($document->archived_at === null) {
            return $document;
        }

        $document->update(['archived_at' => null, 'updated_by' => $actor?->id]);
        Activity::log('status', 'أعاد المستند «'.$document->title.'» من الأرشيف', self::log($document, 'archive-restore'));

        return $document->fresh();
    }

    /* ─────────────────────────── التصنيفات ─────────────────────────── */

    public static function saveCategory(int $businessId, string $name, ?DocumentCategory $category = null, ?bool $active = null): DocumentCategory
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw new RuntimeException(__('اسم التصنيف مطلوب — حتى 80 حرفًا.'));
        }

        $clash = DocumentCategory::where('business_id', $businessId)->where('name', $name)
            ->when($category, fn ($q) => $q->whereKeyNot($category->id))->exists();
        if ($clash) {
            throw new RuntimeException(__('هذا التصنيف موجود.'));
        }

        if ($category === null) {
            return DocumentCategory::create(['business_id' => $businessId, 'name' => $name,
                'sort_order' => (int) DocumentCategory::where('business_id', $businessId)->max('sort_order') + 1]);
        }

        $category->update(['name' => $name] + ($active === null ? [] : ['active' => $active]));

        return $category->fresh();
    }

    /** حذفُ تصنيفٍ لا مستندَ تحته — وما تحته مستندٌ يُعطَّل */
    public static function deleteCategory(DocumentCategory $category): void
    {
        if (DocumentRecord::where('document_category_id', $category->id)->exists()) {
            throw new RuntimeException(__('تحت هذا التصنيف مستندات — عطّله بدل حذفه.'));
        }

        $category->delete();
    }

    /* ─────────────────────────── القراءة ─────────────────────────── */

    /**
     * «مستنداتٌ تحتاج إجراء» — للّوحة والشارة.
     *
     * @return array{needs_action: int, expiring: int, expired: int, nearest: ?array{id: int, title: string, expires_at: string, status: string}}
     */
    public static function summary(int $businessId): array
    {
        $today = self::today($businessId);
        $base = fn () => DocumentRecord::where('business_id', $businessId)->whereNull('archived_at')->whereNotNull('expires_at');

        $expired = (int) $base()->whereDate('expires_at', '<', $today->toDateString())->count();
        $expiring = (int) $base()->whereDate('expires_at', '>=', $today->toDateString())
            ->whereDate('expires_at', '<=', $today->copy()->addDays(self::EXPIRING_DAYS)->toDateString())->count();
        $nearest = $base()->whereDate('expires_at', '>=', $today->toDateString())->orderBy('expires_at')->orderBy('id')->first();

        return [
            'needs_action' => $expired + $expiring,
            'expiring' => $expiring,
            'expired' => $expired,
            'nearest' => $nearest === null ? null : [
                'id' => $nearest->id, 'title' => $nearest->title,
                'expires_at' => $nearest->expires_at->toDateString(), 'status' => $nearest->status($today),
            ],
        ];
    }

    /** عددُ ما يحتاج إجراءً — شارةُ القائمة الجانبيّة، استعلامٌ واحدٌ رخيص */
    public static function needsAction(int $businessId): int
    {
        return (int) DocumentRecord::where('business_id', $businessId)->needsAction(self::today($businessId))->count();
    }

    /** @return array<string, mixed> */
    public static function present(DocumentRecord $d, Carbon $today, bool $withFileUrl = true): array
    {
        $linked = $d->linked();

        return [
            'id' => $d->id,
            'title' => $d->title,
            'category_id' => $d->document_category_id,
            'category' => $d->category?->name,
            'document_number' => $d->document_number,
            'issuer' => $d->issuer,
            'issued_at' => optional($d->issued_at)->toDateString(),
            'expires_at' => optional($d->expires_at)->toDateString(),
            'days_left' => $d->expires_at ? (int) $today->diffInDays(Carbon::parse($d->expires_at->toDateString(), $today->getTimezone()), false) : null,
            'status' => $d->status($today),
            'notes' => $d->notes,
            'link_type' => $d->documentable_type,
            'link_id' => $d->documentable_id,
            'link_label' => $linked === null ? null : self::label($d->documentable_type, $linked),
            'responsible_id' => $d->responsible_user_id,
            'responsible' => $d->responsible?->name,
            'file_name' => $d->file_name,
            'file_size' => $d->file_size,
            'file_url' => $withFileUrl && $d->file_path ? route('admin.documents.file', $d->id) : null,
            'archived' => $d->archived_at !== null,
        ];
    }

    /** @return array<string, list<array{id: int, label: string}>> ما يُختار منه الربطُ — داخل الشركة */
    public static function linkOptions(int $businessId): array
    {
        $limit = 500;

        return [
            'project' => Project::where('business_id', $businessId)->orderByDesc('id')->limit($limit)->get(['id', 'code', 'name'])->map(fn ($p) => ['id' => $p->id, 'label' => $p->code.' — '.$p->name])->all(),
            'customer' => Customer::where('business_id', $businessId)->orderBy('name')->limit($limit)->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'label' => $c->name])->all(),
            'supplier' => Supplier::where('business_id', $businessId)->orderBy('name')->limit($limit)->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'label' => $s->name])->all(),
            'employee' => User::where('business_id', $businessId)->orderBy('name')->limit($limit)->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'label' => $u->name])->all(),
            'organizer' => Organizer::where('business_id', $businessId)->orderBy('name')->limit($limit)->get(['id', 'name'])->map(fn ($o) => ['id' => $o->id, 'label' => $o->name])->all(),
            'event_operation' => EventOperation::where('business_id', $businessId)->orderByDesc('id')->limit($limit)->get(['id', 'code', 'name'])->map(fn ($o) => ['id' => $o->id, 'label' => $o->code.' — '.$o->name])->all(),
        ];
    }

    /* ─────────────────────────── أدوات ─────────────────────────── */

    /** @return array<string, mixed> الحقولُ بعد التحقّق — والمعرّفاتُ من الشركة نفسِها */
    private static function fields(int $businessId, array $data, ?DocumentRecord $document): array
    {
        $title = trim((string) ($data['title'] ?? $document?->title ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            throw new RuntimeException(__('عنوان المستند مطلوب — حتى 200 حرف.'));
        }

        $categoryId = $data['category_id'] ?? null;
        if (filled($categoryId) && ! DocumentCategory::where('business_id', $businessId)->whereKey((int) $categoryId)->exists()) {
            throw new RuntimeException(__('هذا التصنيف ليس من تصنيفات شركتك.'));
        }

        $type = filled($data['link_type'] ?? null) ? (string) $data['link_type'] : null;
        $linkId = $type !== null && filled($data['link_id'] ?? null) ? (int) $data['link_id'] : null;
        if ($type !== null) {
            $class = self::LINKS[$type] ?? throw new RuntimeException(__('نوع الربط غير معروف.'));
            if ($linkId === null || ! $class::where('business_id', $businessId)->whereKey($linkId)->exists()) {
                throw new RuntimeException(__('ما يُربط به المستند ليس من شركتك.'));
            }
        }

        $responsible = filled($data['responsible_id'] ?? null) ? (int) $data['responsible_id'] : null;
        if ($responsible !== null && ! User::where('business_id', $businessId)->whereKey($responsible)->exists()) {
            throw new RuntimeException(__('هذا الموظّف ليس من شركتك.'));
        }

        $issued = self::date($data['issued_at'] ?? null);
        $expires = self::date($data['expires_at'] ?? null);
        if ($issued !== null && $expires !== null && $expires < $issued) {
            throw new RuntimeException(__('تاريخ الانتهاء قبل تاريخ الإصدار.'));
        }

        $text = fn (string $key, int $max) => filled($data[$key] ?? null) ? mb_substr(trim((string) $data[$key]), 0, $max) : null;

        return [
            'title' => $title,
            'document_category_id' => filled($categoryId) ? (int) $categoryId : null,
            'document_number' => $text('document_number', 120),
            'issuer' => $text('issuer', 160),
            'issued_at' => $issued,
            'expires_at' => $expires,
            'notes' => $text('notes', 5000),
            'documentable_type' => $type,
            'documentable_id' => $linkId,
            'responsible_user_id' => $responsible,
        ];
    }

    private static function attach(DocumentRecord $document, UploadedFile $file, string $stored, ?User $actor): void
    {
        $had = $document->file_path !== null;
        DocumentRecordFile::create([
            'business_id' => $document->business_id, 'document_record_id' => $document->id,
            'path' => $stored, 'name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => $file->getClientMimeType(), 'size' => $file->getSize(), 'uploaded_by' => $actor?->id,
        ]);
        $document->update(['file_path' => $stored, 'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'file_mime' => $file->getClientMimeType(), 'file_size' => $file->getSize()]);

        if ($had) {
            Activity::log('updated', 'رفع نسخة جديدة من المستند «'.$document->title.'» — '.$file->getClientOriginalName(), self::log($document, 'upload'));
        }
    }

    private static function date(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            throw new RuntimeException(__('تاريخٌ غير مقروء.'));
        }
    }

    private static function label(?string $type, $model): string
    {
        return match ($type) {
            'project', 'event_operation' => $model->code.' — '.$model->name,
            default => (string) $model->name,
        };
    }

    private static function linkPhrase(DocumentRecord $document): string
    {
        $linked = $document->linked();

        return $linked === null ? '' : ' ('.__(self::LINK_LABELS[$document->documentable_type]).': '.self::label($document->documentable_type, $linked).')';
    }

    /** @return array<string, mixed> */
    private static function log(DocumentRecord $document, string $icon): array
    {
        return ['business_id' => (int) $document->business_id, 'subject_id' => $document->id, 'subject_type' => 'document', 'icon' => $icon];
    }
}
