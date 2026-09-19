<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Customer;
use App\Models\DeliveryPackage;
use App\Models\DeliveryPackageItem;
use App\Models\DocumentApproval;
use App\Models\Project;
use App\Models\ProjectItem;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Document\Snapshot;
use App\Support\Messaging\MessageIntent;
use App\Support\Messaging\Notifiers;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * حزمُ التسليم — الدورةُ كلُّها في موضعٍ واحد.
 *
 * ═══ الدورة ═══
 *
 *   draft ──▶ ready ──▶ sent ──▶ accepted | declined
 *     ▲         │
 *     └─────────┘ (reopen)
 *   draft · ready · sent ──▶ cancelled
 *   declined ──▶ (revise) ──▶ draft جديدة تشير إلى ما حلّت محلَّه
 *
 * ولا قفزَ: لا تُرسَل مسودّةٌ لم تُفحص، ولا تُقبل حزمةٌ لم تُرسَل. وكلُّ
 * انتقالٍ يُقرأ تحت قفلٍ داخل معاملة — ضغطتان على «إرسال» تقرآن «جاهزة»
 * معًا خارج القفل فتقطعان رقمين لحزمةٍ واحدة.
 *
 * ═══ وما خرج دليل ═══
 *
 * عند الإرسال تُبصَم الحمولةُ (البنودُ وبصماتُ ملفّاتها) وتُختم بحال
 * الشركة. وقرارُ العميل من رابطه يقارن البصمةَ الآن بما خُتم ويكتب اللقطةَ
 * في `document_approvals` مرّةً — الآليّةُ نفسُها التي تقبل بها عروضُ
 * الأسعار، لا نسخةٌ ثانية منها.
 *
 * ═══ والمشروعُ أصلُ النطاق ═══
 *
 * حزمةٌ تتبع مشروعًا تسلّم من **بنوده**: كلُّ بندِ حزمةٍ يشير إلى بند
 * مشروعٍ يُقاس عليه — ما اعتُمد، وما سُلِّم قبلُ، وما بقي. ولا يُسلَّم فوق
 * المعتمَد. وعند القبول تُزاد `project_items.delivered_quantity` مرّةً
 * واحدة تحت قفل الحزمة: القبولُ لا يقع إلّا على مرسَلة، والمرسَلةُ تصير
 * مقبولةً في المعاملة نفسِها — فلا طريقَ إلى زيادةٍ ثانية.
 */
final class DeliveryPackages
{
    /** ما يُقبل رفعُه — تقاريرُ وصورٌ وملفّاتُ تصدير */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf', 'xlsx', 'xls', 'csv', 'docx', 'pptx', 'zip', 'mp4'];

    /** بالكيلوبايت — خمسةٌ وعشرون ميجابايت: تقريرٌ مصوَّر أو تسجيلٌ قصير */
    public const MAX_KB = 25600;

    /** كم بندًا في الحزمة — يكفي حدثًا بمخرجاته كلِّها */
    public const MAX_ITEMS = 60;

    private const PREFIX = 'DP-';

    /* ─────────────────────────── الإنشاء والتعديل ─────────────────────────── */

    /**
     * @param  array<string, mixed>  $data  `title` · `description` · `client_notes` · `internal_notes` · `contact_email` · `quotation_id`
     */
    public static function create(int $businessId, ?Customer $customer, array $data, ?User $actor = null): DeliveryPackage
    {
        $project = self::projectOf($businessId, $data['project_id'] ?? null);

        /* والمشروعُ يعرف عرضَه وعميلَه: ما لم يُسمَّ غيرُهما يُقرأ منه */
        $quotation = $project?->quotation ?? self::quotationOf($businessId, $data['quotation_id'] ?? null);

        if ($customer === null) {
            $customer = $project?->customer ?? $quotation?->customer;
        }

        $package = DeliveryPackage::create([
            'business_id' => $businessId,
            'customer_id' => $customer?->id,
            'quotation_id' => $quotation?->id,
            'project_id' => $project?->id,
            'status' => DeliveryPackage::DRAFT,
            'created_by' => $actor?->id,
        ] + self::attributes($data, $quotation ?? $project));

        Activity::log('created', 'أنشأ حزمة التسليم «'.$package->title.'»', [
            'subject_id' => $package->id, 'subject_type' => 'delivery_package', 'icon' => 'package',
        ]);

        return $package;
    }

    /** @param  array<string, mixed>  $data */
    public static function update(DeliveryPackage $package, array $data, ?User $actor = null): DeliveryPackage
    {
        self::mustBeEditable($package);

        $project = array_key_exists('project_id', $data)
            ? self::projectOf((int) $package->business_id, $data['project_id'])
            : $package->project;

        /*
         * ومشروعٌ يُبدَّل يُسقط وصلاتِ بنوده: بندُ مشروعٍ آخر لا يبقى معلّقًا
         * على حزمةٍ صارت لغيره — انظر `assertItemsBelongTo`.
         */
        if ((int) ($project?->id ?? 0) !== (int) ($package->project_id ?? 0)) {
            $package->items()->whereNotNull('project_item_id')->update(['project_item_id' => null]);
        }

        $quotation = array_key_exists('quotation_id', $data)
            ? self::quotationOf((int) $package->business_id, $data['quotation_id'])
            : $package->quotation;

        if ($project !== null && $quotation === null) {
            $quotation = $project->quotation;
        }

        $package->update(['quotation_id' => $quotation?->id, 'project_id' => $project?->id] + self::attributes($data, $quotation ?? $project)
            + (array_key_exists('customer_id', $data) ? ['customer_id' => self::customerId($package, $data['customer_id'])] : []));

        Activity::log('updated', 'عدّل حزمة التسليم '.self::label($package), [
            'subject_id' => $package->id, 'subject_type' => 'delivery_package',
        ]);

        return $package->fresh();
    }

    /** @return array<string, mixed> */
    private static function attributes(array $data, Quotation|Project|null $quotation): array
    {
        $out = [];

        foreach (['title' => 160, 'contact_email' => 120] as $key => $max) {
            if (array_key_exists($key, $data)) {
                $out[$key] = self::text($data[$key], $max);
            }
        }
        foreach (['description', 'client_notes', 'internal_notes'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = self::text($data[$key], 4000);
            }
        }

        /* وعنوانٌ فارغ يرث عنوانَ العرض أو اسمَ المشروع، وبريدٌ فارغ يرث بريدَ جهته */
        if ($quotation !== null && blank($out['title'] ?? null)) {
            $out['title'] = self::text($quotation instanceof Project ? $quotation->name : $quotation->title, 160);
        }
        if ($quotation !== null && blank($out['contact_email'] ?? null) && filled($quotation->contact_email)) {
            $out['contact_email'] = $quotation->contact_email;
        }

        return $out;
    }

    /** العرضُ من شركة الحزمة لا من رقمٍ في الطلب — ومقبولٌ وحدَه */
    private static function quotationOf(int $businessId, mixed $id): ?Quotation
    {
        if (blank($id)) {
            return null;
        }

        $quotation = Quotation::where('business_id', $businessId)->whereKey((int) $id)->first();

        if ($quotation === null) {
            throw new RuntimeException(__('عرض السعر غير موجود.'));
        }
        if ($quotation->status !== Quotation::ACCEPTED) {
            throw new RuntimeException(__('لا تُبنى حزمة تسليم إلا على عرضٍ مقبول.'));
        }

        return $quotation;
    }

    /** المشروعُ من شركة الحزمة لا من رقمٍ في الطلب — ومفتوحٌ أو مُقفَل، لا ملغى */
    private static function projectOf(int $businessId, mixed $id): ?Project
    {
        if (blank($id)) {
            return null;
        }

        $project = Project::where('business_id', $businessId)->whereKey((int) $id)->first();

        if ($project === null) {
            throw new RuntimeException(__('المشروع غير موجود.'));
        }
        if ($project->status === Project::CANCELLED) {
            throw new RuntimeException(__('لا تُبنى حزمة تسليم على مشروعٍ ملغى.'));
        }

        return $project;
    }

    /**
     * بندُ مشروعٍ يُسلَّم منه — من مشروع الحزمة وحدَه.
     *
     * والفحصُ بالمشروع لا بالشركة: بندُ مشروعٍ آخر للشركة نفسِها مرفوضٌ كما
     * يُرفض بندُ شركةٍ أخرى — `Projects::itemIdOf` تسأل السؤالَ نفسَه.
     */
    private static function projectItemOf(DeliveryPackage $package, mixed $id): ?ProjectItem
    {
        if (blank($id)) {
            return null;
        }

        if ($package->project_id === null) {
            throw new RuntimeException(__('اربط الحزمة بمشروعٍ قبل أن تُسلِّم من بنوده.'));
        }

        $item = ProjectItem::where('project_id', $package->project_id)
            ->where('business_id', $package->business_id)->whereKey((int) $id)->first();

        if ($item === null) {
            throw new RuntimeException(__('هذا البند ليس من بنود المشروع.'));
        }

        return $item;
    }

    /**
     * ما بقي من بند مشروعٍ بعد ما سُلِّم — وما تحمله حزمٌ خرجت ولم تُقرَّر.
     *
     * الحزمةُ الحاليّة تُستثنى: هي تسأل «كم بقي لي؟» لا «كم بقي بعدي؟».
     */
    public static function remainingOf(ProjectItem $item, ?int $excludingPackageId = null): float
    {
        $inFlight = (float) DeliveryPackageItem::where('project_item_id', $item->id)
            ->when($excludingPackageId, fn ($q) => $q->where('delivery_package_id', '!=', $excludingPackageId))
            ->whereHas('package', fn ($q) => $q->where('status', DeliveryPackage::SENT))
            ->sum('quantity');

        return round((float) $item->quantity - (float) $item->delivered_quantity - $inFlight, 3);
    }

    private static function customerId(DeliveryPackage $package, mixed $id): ?int
    {
        if (blank($id)) {
            return null;
        }

        $customer = Customer::where('business_id', $package->business_id)->whereKey((int) $id)->first();

        if ($customer === null) {
            throw new RuntimeException(__('العميل غير موجود.'));
        }

        return (int) $customer->id;
    }

    private static function mustBeEditable(DeliveryPackage $package): void
    {
        if ($package->status !== DeliveryPackage::DRAFT) {
            throw new RuntimeException($package->isFrozen()
                ? __('هذه الحزمة خرجت أو أُغلقت — لا تُعدَّل. أعدّ حزمة جديدة إن لزم.')
                : __('أعد الحزمة إلى المسودّة قبل تعديلها.'));
        }
    }

    /* ─────────────────────────── البنود ─────────────────────────── */

    /**
     * بندٌ يُضاف — ملفٌّ أو سطرُ خدمة.
     *
     * والملفُّ يستقرّ على القرص قبل أن يُكتب صفُّه، وبصمتُه تُحسب مرّةً هنا:
     * صفٌّ يشير إلى ملفٍّ لم يُكتب رابطٌ ميّت، وبصمةٌ تُحسب عند كلّ إرسال
     * تقرأ ملفًّا كبيرًا مرّتين.
     *
     * @param  array<string, mixed>  $data  `kind` · `title` · `description` · `quantity` · `unit` · `project_item_id`
     */
    public static function addItem(DeliveryPackage $package, array $data, ?UploadedFile $file = null, ?User $actor = null): DeliveryPackageItem
    {
        self::mustBeEditable($package);

        if ($package->items()->count() >= self::MAX_ITEMS) {
            throw new RuntimeException(__('بلغت الحزمة حدّها من البنود (:n).', ['n' => self::MAX_ITEMS]));
        }

        $kind = in_array($data['kind'] ?? null, DeliveryPackageItem::KINDS, true) ? $data['kind'] : DeliveryPackageItem::FILE;

        if ($kind === DeliveryPackageItem::FILE && $file === null) {
            throw new RuntimeException(__('أرفق الملف — بندُ الملفّ بلا ملفّ لا يُثبت شيئًا.'));
        }

        /* وبندُ النطاق يُقرأ من المشروع — ويرث وصفَه ووحدتَه وما بقي منه */
        $scope = $kind === DeliveryPackageItem::ITEM ? self::projectItemOf($package, $data['project_item_id'] ?? null) : null;

        $title = self::text($data['title'] ?? null, 160) ?? ($scope?->description) ?? ($file?->getClientOriginalName());

        if ($title === null || $title === '') {
            throw new RuntimeException(__('اكتب عنوان البند.'));
        }

        $stored = null;

        if ($file !== null) {
            $path = $file->store('delivery-packages/'.$package->business_id, 'local');
            $stored = [
                'path' => $path,
                'name' => self::text($file->getClientOriginalName(), 255),
                'mime' => self::text($file->getClientMimeType(), 120),
                'size' => $file->getSize(),
                'checksum' => hash_file('sha256', Storage::disk('local')->path($path)),
                'uploaded_by' => $actor?->id,
            ];
        }

        $item = DeliveryPackageItem::create([
            'business_id' => $package->business_id,
            'delivery_package_id' => $package->id,
            'kind' => $kind,
            'title' => $title,
            'description' => self::text($data['description'] ?? null, 2000),
            'quantity' => $kind === DeliveryPackageItem::ITEM
                ? (filled($data['quantity'] ?? null) ? round((float) $data['quantity'], 3) : ($scope === null ? null : max(0.0, self::remainingOf($scope, $package->id))))
                : null,
            'unit' => $kind === DeliveryPackageItem::ITEM ? (self::text($data['unit'] ?? null, 40) ?? $scope?->unit) : null,
            'project_item_id' => $scope?->id,
            'sort_order' => (int) ($package->items()->max('sort_order') ?? 0) + 1,
        ] + ($stored ?? []));

        Activity::log('updated', 'أضاف إلى حزمة التسليم '.self::label($package).' البند «'.$title.'»', [
            'subject_id' => $package->id, 'subject_type' => 'delivery_package',
        ]);

        return $item;
    }

    /**
     * حذفُ بند — والملفُّ معه إن لم يشاركه بندٌ آخر.
     *
     * حزمةٌ نُسخت بعد رفضٍ تشير بنودُها إلى الملفّات نفسِها (لا تُنسخ على
     * القرص). فحذفُ بندٍ في الجديدة لا يمحو دليلَ القديمة.
     */
    public static function removeItem(DeliveryPackageItem $item, ?User $actor = null): void
    {
        $package = $item->package;

        self::mustBeEditable($package);

        $path = $item->path;
        $title = $item->title;
        $item->delete();

        if ($path && ! DeliveryPackageItem::where('path', $path)->exists()) {
            Storage::disk('local')->delete($path);
        }

        Activity::log('updated', 'حذف من حزمة التسليم '.self::label($package).' البند «'.$title.'»', [
            'subject_id' => $package->id, 'subject_type' => 'delivery_package',
        ]);
    }

    /* ─────────────────────────── الجاهزيّة ─────────────────────────── */

    /**
     * ما يمنع الحزمةَ من أن تكون جاهزة — رسائلُ تُقرأ، لا استثناءٌ يُرمى.
     *
     * تقرؤها الشاشةُ لترسم ما ينقص، و`ready()` لترفض. ومصدرٌ واحد: شرطٌ
     * في الشاشة وآخرُ في الخادم يفترقان يومًا.
     *
     * @return list<string>
     */
    public static function blockers(DeliveryPackage $package): array
    {
        $package->loadMissing('items');
        $out = [];

        if (blank($package->title)) {
            $out[] = __('لا عنوان للحزمة.');
        }
        if ($package->customer_id === null) {
            $out[] = __('لا عميل للحزمة — لمن تُسلَّم؟');
        }
        if ($package->items->isEmpty()) {
            $out[] = __('لا بنود في الحزمة — ما الذي يُسلَّم؟');
        }

        foreach ($package->items as $item) {
            if ($item->isFile() && (! $item->hasFile() || ! Storage::disk('local')->exists($item->path))) {
                $out[] = __('البند «:title» ملفٌّ بلا ملفٍّ على القرص.', ['title' => $item->title]);
            }
            if ($item->kind === DeliveryPackageItem::ITEM && $item->quantity !== null && $item->quantity < 0) {
                $out[] = __('البند «:title» بكميّةٍ سالبة.', ['title' => $item->title]);
            }
        }

        return array_merge($out, self::scopeBlockers($package));
    }

    /**
     * موانعُ النطاق: بندٌ من مشروعٍ آخر، أو كميّةٌ فوق ما بقي من المعتمَد.
     *
     * تُقرأ عند الجاهزيّة والإرسال **وعند القبول تحت القفل**: أمرُ تغييرٍ
     * يُنقص الكميّةَ بين الإرسال والقبول يجعل ما خرج فوق المعتمَد، فيُردّ
     * القبولُ لا تُقلَّم الكميّة بصمت.
     *
     * @return list<string>
     */
    private static function scopeBlockers(DeliveryPackage $package): array
    {
        $package->loadMissing('items.projectItem');
        $out = [];
        $byScope = [];

        foreach ($package->items as $item) {
            if ($item->project_item_id === null) {
                continue;
            }

            $scope = $item->projectItem;

            if ($scope === null || (int) $scope->project_id !== (int) $package->project_id) {
                $out[] = __('البند «:title» يشير إلى بندٍ ليس من هذا المشروع.', ['title' => $item->title]);

                continue;
            }

            $byScope[$scope->id] = ($byScope[$scope->id] ?? 0.0) + (float) $item->quantity;
        }

        foreach ($byScope as $scopeId => $quantity) {
            $scope = $package->items->firstWhere('project_item_id', $scopeId)->projectItem;
            $remaining = self::remainingOf($scope, $package->id);

            if ($quantity - $remaining > 0.0005) {
                $out[] = __('البند «:title»: :quantity فوق ما بقي من المعتمَد (:remaining من :approved).', [
                    'title' => $scope->description,
                    'quantity' => rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.'),
                    'remaining' => rtrim(rtrim(number_format(max(0.0, $remaining), 3, '.', ''), '0'), '.'),
                    'approved' => rtrim(rtrim(number_format((float) $scope->quantity, 3, '.', ''), '0'), '.'),
                ]);
            }
        }

        return $out;
    }

    /** مسودّة ← جاهزة — بعد أن تمرّ الموانعُ كلُّها */
    public static function ready(DeliveryPackage $package, ?User $actor = null): DeliveryPackage
    {
        return DB::transaction(function () use ($package, $actor) {
            $locked = self::lock($package);

            if ($locked->status === DeliveryPackage::READY) {
                return $locked;
            }
            if ($locked->status !== DeliveryPackage::DRAFT) {
                throw new RuntimeException(__('لا تُجهَّز إلا حزمةٌ في المسودّة.'));
            }

            $blockers = self::blockers($locked);

            if ($blockers !== []) {
                throw new RuntimeException(implode(' ', $blockers));
            }

            $locked->update([
                'status' => DeliveryPackage::READY,
                'prepared_by' => $actor?->id,
                'prepared_at' => now(),
            ]);

            Activity::log('status', 'جهّز حزمة التسليم '.self::label($locked).' للإرسال', [
                'subject_id' => $locked->id, 'subject_type' => 'delivery_package',
            ]);

            return $locked->fresh('items');
        });
    }

    /** جاهزة ← مسودّة — لتُعدَّل ثمّ تُفحص ثانيةً */
    public static function reopen(DeliveryPackage $package, ?User $actor = null): DeliveryPackage
    {
        return DB::transaction(function () use ($package) {
            $locked = self::lock($package);

            if ($locked->status === DeliveryPackage::DRAFT) {
                return $locked;
            }
            if ($locked->status !== DeliveryPackage::READY) {
                throw new RuntimeException(__('لا تُعاد إلى المسودّة إلا حزمةٌ جاهزة لم تُرسَل.'));
            }

            $locked->update(['status' => DeliveryPackage::DRAFT, 'prepared_by' => null, 'prepared_at' => null]);

            Activity::log('status', 'أعاد حزمة التسليم '.self::label($locked).' إلى المسودّة', [
                'subject_id' => $locked->id, 'subject_type' => 'delivery_package',
            ]);

            return $locked->fresh('items');
        });
    }

    /* ─────────────────────────── الإرسال ─────────────────────────── */

    /**
     * جاهزة ← مرسَلة — الرقمُ يُقطع والحمولةُ تُبصَم وتُختم.
     *
     * ومرسَلةٌ تُعاد فتردّ نفسَها: ضغطتان لا تقطعان رقمين ولا تبصمان مرّتين.
     */
    public static function send(DeliveryPackage $package, ?User $actor = null): DeliveryPackage
    {
        return DB::transaction(function () use ($package, $actor) {
            $locked = self::lock($package);

            if ($locked->status === DeliveryPackage::SENT) {
                return $locked;
            }
            if ($locked->status === DeliveryPackage::DRAFT) {
                throw new RuntimeException(__('جهّز الحزمة أولًا — لا تُرسَل مسودّةٌ لم تُفحص.'));
            }
            if ($locked->status !== DeliveryPackage::READY) {
                throw new RuntimeException(__('لا تُرسَل إلا حزمةٌ جاهزة.'));
            }

            /* والموانعُ تُفحص ثانيةً لحظةَ الخروج: ملفٌّ قد يُحذف من القرص بين الجاهزيّة والإرسال */
            $blockers = self::blockers($locked);

            if ($blockers !== []) {
                throw new RuntimeException(implode(' ', $blockers));
            }

            $locked->setRelation('items', $locked->items()->get());

            /* والرقمُ قبل البصمة: هو جزءٌ منها، وبصمةٌ على رقمٍ لم يُكتب بعد لا تطابق أبدًا */
            $locked->number = $locked->number ?: Numbering::next((int) $locked->business_id, DeliveryPackage::class, self::PREFIX);

            $locked->update([
                'number' => $locked->number,
                'status' => DeliveryPackage::SENT,
                'sent_at' => now(),
                'sent_by' => $actor?->id,
                Snapshot::COLUMN => Snapshot::capture((int) $locked->business_id),
                'content_hash' => self::contentHash($locked),
            ]);

            Activity::log('status', 'أرسل حزمة التسليم '.self::label($locked).' إلى العميل', [
                'subject_id' => $locked->id, 'subject_type' => 'delivery_package', 'icon' => 'send',
            ]);

            return $locked->fresh('items');
        });
    }

    /**
     * إرسالُ الرابط بالبريد — إيصالٌ لا تحويلُ حال. انظر `Quotations::email`.
     */
    public static function email(DeliveryPackage $package, string $to, ?string $note, ?User $actor = null): void
    {
        if (! $package->isDecidable()) {
            throw new RuntimeException(__('لا يُرسَل بالبريد إلا حزمةٌ خرجت وتنتظر قرارًا.'));
        }

        $notifier = Notifiers::email();

        if (! $notifier->configured()) {
            throw new RuntimeException(__('إرسالُ البريد غير مضبوط على الخادم — انسخ الرابط وأرسله بنفسك.'));
        }

        $business = Business::find($package->business_id);
        $reminder = $package->sent_to !== null;
        $label = self::label($package);

        $result = $notifier->send(new MessageIntent(
            to: $to,
            subject: ($reminder ? __('تذكير: ') : '').__('حزمة التسليم :label — :company', [
                'label' => $label, 'company' => $business?->name ?? '',
            ]),
            paragraphs: array_values(array_filter([
                __('تحية طيبة،'),
                $reminder
                    ? __('نذكّركم بحزمة التسليم :label الخاصة بـ«:title» — بانتظار اعتمادكم.', ['label' => $label, 'title' => $package->title])
                    : __('يسرّنا إبلاغكم بإتمام «:title». تجدون في الرابط أدناه حزمة التسليم :label بمخرجاتها وملفّاتها.', ['label' => $label, 'title' => $package->title]),
                self::text($note, 2000),
                __('يمكنكم مراجعة ما سُلِّم واعتماده إلكترونيًا من الرابط أدناه.'),
            ], fn ($p) => filled($p))),
            url: self::url($package),
            action: __('فتح حزمة التسليم'),
            templateKey: $reminder ? 'delivery.reminder' : 'delivery.link',
            senderName: $business?->name,
            replyTo: filled($actor?->email) ? $actor->email : (filled($business?->email) ? $business->email : null),
            footer: $business?->name,
            /* وتُقيَّد على الحزمة في سجلّ المراسلات — المرحلة 6 */
            about: $package,
            businessId: (int) $package->business_id,
        ));

        if (! $result->sent) {
            throw new RuntimeException((string) $result->reason);
        }

        if (! $reminder) {
            $package->forceFill(['sent_to' => self::text($to, 120)])->saveQuietly();
        }

        Activity::log('updated', ($reminder ? 'أرسل تذكيرًا بحزمة التسليم ' : 'أرسل حزمة التسليم ').$label.' بالبريد إلى '.$to, [
            'subject_id' => $package->id, 'subject_type' => 'delivery_package', 'icon' => 'mail',
        ]);
    }

    /** رابطُ العميل — رمزُ `document_links` نفسُه، على بابِ التسليم */
    public static function url(DeliveryPackage $package): ?string
    {
        $token = PublicDocument::token($package);

        return $token === null ? null : route('delivery.show', $token);
    }

    /* ─────────────────────────── القرار ─────────────────────────── */

    /**
     * قرارُ العميل **من رابطه** — دليلٌ يُكتب مرّةً. انظر `Quotations::decide`.
     *
     * @param  array<string, mixed>  $signer  `name` · `title` · `email` · `phone` · `consent` · `decline_reason`
     * @param  array<string, mixed>  $meta  `ip` · `user_agent`
     */
    public static function decide(DeliveryPackage $package, string $decision, array $signer, array $meta = []): DeliveryPackage
    {
        $accepted = $decision === DocumentApproval::ACCEPTED;

        if (! $accepted && $decision !== DocumentApproval::DECLINED) {
            throw new RuntimeException(__('قرارٌ غير معروف.'));
        }

        $name = self::text($signer['name'] ?? null, 120);

        if ($name === null || $name === '') {
            throw new RuntimeException(__('اكتب اسمك ليُسجَّل القرار باسمه.'));
        }

        $declineReason = self::text($signer['decline_reason'] ?? null, 500);

        if (! $accepted && ($declineReason === null || $declineReason === '')) {
            throw new RuntimeException(__('اكتب سبب الرفض — ليُعالَج ما ينقص.'));
        }

        return DB::transaction(function () use ($package, $accepted, $name, $signer, $declineReason, $meta) {
            $locked = self::lock($package);

            if (! $locked->isDecidable()) {
                throw new RuntimeException($locked->isDecided()
                    ? __('قُرّر في هذه الحزمة من قبل.')
                    : __('هذه الحزمة لا تقبل قرارًا الآن.'));
            }

            $locked->setRelation('items', $locked->items()->get());
            $payload = self::hashPayload($locked);

            if (! Fingerprint::matches($payload, $locked->content_hash)) {
                throw new RuntimeException(__('تغيّر محتوى هذه الحزمة بعد إرسالها — لا تُقبل بهذه الصورة. تواصل مع الشركة.'));
            }

            try {
                $approval = DocumentApproval::create([
                    'business_id' => $locked->business_id,
                    'approvable_type' => $locked->getMorphClass(),
                    'approvable_id' => $locked->id,
                    'provider' => DocumentApproval::INTERNAL,
                    'decision' => $accepted ? DocumentApproval::ACCEPTED : DocumentApproval::DECLINED,
                    'content_hash' => $locked->content_hash,
                    'accepted_snapshot' => $payload,
                    'signer_name' => $name,
                    'signer_title' => self::text($signer['title'] ?? null, 120),
                    'signer_email' => self::text($signer['email'] ?? null, 120),
                    'signer_phone' => self::text($signer['phone'] ?? null, 40),
                    'consent_text' => $accepted ? self::text($signer['consent'] ?? null, 1000) : null,
                    'decline_reason' => $accepted ? null : $declineReason,
                    'decided_at' => now(),
                    'ip' => self::text($meta['ip'] ?? null, 45),
                    'user_agent' => self::text($meta['user_agent'] ?? null, 255),
                ]);
            } catch (QueryException) {
                /* القيدُ الفريد ردّ قرارًا ثانيًا سبقه أوّل — على SQLite حيث لا قفل */
                throw new RuntimeException(__('قُرّر في هذه الحزمة قبل لحظات.'));
            }

            self::applyDecision($locked, $accepted, $approval->decided_at, null, $name, $declineReason);

            Activity::log('status', ($accepted ? 'قبل العميل حزمة التسليم ' : 'رفض العميل حزمة التسليم ')
                .self::label($locked).' إلكترونيًّا — '.$name
                .(filled($approval->signer_email) ? ' <'.$approval->signer_email.'>' : ''), [
                    'business_id' => $locked->business_id,
                    'subject_id' => $locked->id, 'subject_type' => 'delivery_package',
                    'icon' => $accepted ? 'package-check' : 'package-x',
                    'color' => $accepted ? 'success' : 'danger',
                ]);

            return $locked->fresh('items');
        });
    }

    /**
     * قرارٌ يسجّله موظّفٌ نيابةً — خبرٌ لا دليل: بريدٌ أو اتّصالٌ قال «تمّ».
     *
     * @param  array<string, mixed>  $data  `decided_by_name` · `decline_reason`
     */
    public static function recordDecision(DeliveryPackage $package, string $decision, array $data = [], ?User $actor = null): DeliveryPackage
    {
        $accepted = $decision === DeliveryPackage::ACCEPTED;

        if (! $accepted && $decision !== DeliveryPackage::DECLINED) {
            throw new RuntimeException(__('قرارٌ غير معروف.'));
        }

        $reason = self::text($data['decline_reason'] ?? null, 500);

        if (! $accepted && ($reason === null || $reason === '')) {
            throw new RuntimeException(__('اكتب سبب الرفض — ليُعالَج ما ينقص.'));
        }

        return DB::transaction(function () use ($package, $accepted, $data, $reason, $actor) {
            $locked = self::lock($package);

            if (! $locked->isDecidable()) {
                throw new RuntimeException($locked->isDecided()
                    ? __('قُرّر في هذه الحزمة من قبل.')
                    : __('لا يُسجَّل قرارٌ إلا على حزمةٍ أُرسلت.'));
            }

            self::applyDecision($locked, $accepted, now(), $actor?->id, self::text($data['decided_by_name'] ?? null, 120), $reason);

            Activity::log('status', ($accepted ? 'سجّل قبول العميل لحزمة التسليم ' : 'سجّل رفض العميل لحزمة التسليم ').self::label($locked), [
                'subject_id' => $locked->id, 'subject_type' => 'delivery_package',
            ]);

            return $locked->fresh('items');
        });
    }

    /**
     * أثرُ القرار على الصفّ — من الرابط أو من موظّف.
     *
     * ═══ والقبولُ يُسلِّم من النطاق — مرّةً ═══
     *
     * لكلّ بندٍ له `project_item_id` تُزاد `delivered_quantity` بكميّته،
     * تحت قفل صفّ البند. ومرّةً واحدة بالبناء لا بالعدّ: هذه لا تُنادى إلّا
     * من `decide`/`recordDecision` وكلتاهما تشترط «مرسَلة» تحت قفل الحزمة
     * وتجعلها «مقبولة» في المعاملة نفسِها — فالنداءُ الثاني لا يصل إلى هنا.
     *
     * وما يتجاوز المعتمَد يُردّ لا يُقلَّم: أمرُ تغييرٍ أنقص النطاقَ بعد
     * الإرسال يجعل القبولَ بهذه الصورة كذبًا على الدفتر.
     */
    private static function applyDecision(DeliveryPackage $locked, bool $accepted, $at, ?int $byId, ?string $byName, ?string $reason): void
    {
        if ($accepted && $locked->items->contains(fn (DeliveryPackageItem $i) => $i->project_item_id !== null)) {
            $blockers = self::scopeBlockers($locked);

            if ($blockers !== []) {
                throw new RuntimeException(__('لا يُقبل التسليم بهذه الصورة — :why', ['why' => implode(' ', $blockers)]));
            }

            foreach ($locked->items as $item) {
                if ($item->project_item_id === null || (float) $item->quantity <= 0) {
                    continue;
                }

                $scope = ProjectItem::whereKey($item->project_item_id)->lockForUpdate()->first();

                if ($scope === null) {
                    continue;
                }

                $delivered = round((float) $scope->delivered_quantity + (float) $item->quantity, 3);

                $scope->update([
                    'delivered_quantity' => $delivered,
                    'status' => $delivered + 0.0005 >= (float) $scope->quantity ? ProjectItem::DELIVERED : ProjectItem::IN_PROGRESS,
                ]);
            }
        }

        $locked->update([
            'status' => $accepted ? DeliveryPackage::ACCEPTED : DeliveryPackage::DECLINED,
            'accepted_at' => $accepted ? $at : null,
            'declined_at' => $accepted ? null : $at,
            'decided_by' => $byId,
            'decided_by_name' => $byName,
            'decline_reason' => $accepted ? null : $reason,
        ]);
    }

    /**
     * خبرُ القرار إلى الشركة — بأفضل جهد: القرارُ ثبت، وبريدٌ يسقط لا يُسقطه.
     */
    public static function notifyDecision(DeliveryPackage $package, DocumentApproval $approval): void
    {
        $notifier = Notifiers::email();
        $business = Business::find($package->business_id);

        if (! $notifier->configured() || ! Mailer::deliverable($business)) {
            return;
        }

        $label = self::label($package);
        $accepted = $approval->isAccepted();

        $notifier->send(new MessageIntent(
            to: (string) $business->email,
            subject: $accepted
                ? __('قبل العميل حزمة التسليم :label', ['label' => $label])
                : __('رفض العميل حزمة التسليم :label', ['label' => $label]),
            paragraphs: array_values(array_filter([
                $approval->signer_name.(filled($approval->signer_title) ? ' ('.$approval->signer_title.')' : ''),
                $accepted
                    ? __('قبل الحزمة إلكترونيًا في :at.', ['at' => $approval->decided_at?->format('Y-m-d H:i')])
                    : __('رفض الحزمة في :at — السبب: :reason', [
                        'at' => $approval->decided_at?->format('Y-m-d H:i'), 'reason' => (string) $approval->decline_reason,
                    ]),
                filled($approval->ip) ? __('من العنوان :ip', ['ip' => $approval->ip]) : null,
            ])),
            url: route('admin.deliveries.show', $package->id),
            action: __('فتح الحزمة في النظام'),
            templateKey: $accepted ? 'delivery.accepted' : 'delivery.declined',
            senderName: $business->name,
            about: $package,
            businessId: (int) $package->business_id,
        ));
    }

    /* ─────────────────────────── الإلغاء والتنقيح ─────────────────────────── */

    /** إلغاءٌ داخليّ — قبل القرار وحدَه؛ وما قُرّر فيه دليلٌ لا يُلغى */
    public static function cancel(DeliveryPackage $package, string $reason, ?User $actor = null): DeliveryPackage
    {
        $reason = self::text($reason, 200);

        if ($reason === null || $reason === '') {
            throw new RuntimeException(__('اكتب سبب الإلغاء.'));
        }

        return DB::transaction(function () use ($package, $reason, $actor) {
            $locked = self::lock($package);

            if ($locked->status === DeliveryPackage::CANCELLED) {
                return $locked;
            }
            if ($locked->isDecided()) {
                throw new RuntimeException(__('قُرّر في هذه الحزمة — لا تُلغى. أعدّ حزمة جديدة إن لزم.'));
            }

            $locked->update([
                'status' => DeliveryPackage::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $actor?->id,
                'cancellation_reason' => $reason,
            ]);

            Activity::log('status', 'ألغى حزمة التسليم '.self::label($locked).' — '.$reason, [
                'subject_id' => $locked->id, 'subject_type' => 'delivery_package', 'color' => 'danger',
            ]);

            return $locked->fresh('items');
        });
    }

    /**
     * حزمةٌ جديدة بعد رفض — تُنسخ البنودُ مسودّةً، والمرفوضةُ تبقى كما هي.
     *
     * والملفّاتُ لا تُنسخ على القرص: البندُ الجديد يشير إلى الملفّ نفسِه
     * ببصمته نفسِها. وحذفُه من الجديدة لا يمحوه ما دام في القديمة — انظر
     * `removeItem`.
     */
    public static function revise(DeliveryPackage $package, ?User $actor = null): DeliveryPackage
    {
        if ($package->status !== DeliveryPackage::DECLINED) {
            throw new RuntimeException(__('لا تُعاد إلا حزمةٌ رفضها العميل.'));
        }

        return DB::transaction(function () use ($package, $actor) {
            $package->loadMissing('items');

            $copy = DeliveryPackage::create([
                'business_id' => $package->business_id,
                'customer_id' => $package->customer_id,
                'quotation_id' => $package->quotation_id,
                'project_id' => $package->project_id,
                'title' => $package->title,
                'description' => $package->description,
                'client_notes' => $package->client_notes,
                'internal_notes' => $package->internal_notes,
                'contact_email' => $package->contact_email,
                'status' => DeliveryPackage::DRAFT,
                'created_by' => $actor?->id,
                'supersedes_id' => $package->id,
            ]);

            foreach ($package->items as $item) {
                DeliveryPackageItem::create([
                    'business_id' => $item->business_id,
                    'delivery_package_id' => $copy->id,
                    'project_item_id' => $item->project_item_id,
                    'kind' => $item->kind,
                    'title' => $item->title,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'path' => $item->path,
                    'name' => $item->name,
                    'mime' => $item->mime,
                    'size' => $item->size,
                    'checksum' => $item->checksum,
                    'uploaded_by' => $item->uploaded_by,
                    'sort_order' => $item->sort_order,
                ]);
            }

            Activity::log('created', 'أعدّ حزمة تسليم جديدة بعد رفض '.self::label($package), [
                'subject_id' => $copy->id, 'subject_type' => 'delivery_package', 'icon' => 'package',
            ]);

            return $copy->fresh('items');
        });
    }

    /* ─────────────────────────── الفتح والبصمة ─────────────────────────── */

    /** فتحَها العميلُ — واقعةٌ تُختم ولا تُغيّر حالًا. انظر `Quotations::markViewed` */
    public static function markViewed(DeliveryPackage $package): void
    {
        $package->forceFill([
            'first_viewed_at' => $package->first_viewed_at ?? now(),
            'last_viewed_at' => now(),
            'view_count' => (int) $package->view_count + 1,
        ])->saveQuietly();
    }

    public static function contentHash(DeliveryPackage $package): string
    {
        return Fingerprint::of(self::hashPayload($package));
    }

    /**
     * الحمولةُ التي تُبصَم — وهي نفسُها ما يُكتب في `accepted_snapshot`.
     *
     * البنودُ بترتيبها، وبصماتُ الملفّات لا أسماؤها: ملفٌّ يُستبدل باسمه
     * نفسِه يغيّر البصمة. والأرقامُ بثلاث منازل: بصمةٌ تتغيّر لأنّ القاعدة
     * ردّت `2` مرّةً و`2.000` مرّة ليست بصمة.
     *
     * @return array<string, mixed>
     */
    public static function hashPayload(DeliveryPackage $package): array
    {
        $package->loadMissing('items');

        return [
            'number' => (string) $package->number,
            'title' => (string) $package->title,
            'description' => (string) $package->description,
            'client_notes' => (string) $package->client_notes,
            'customer_id' => (int) $package->customer_id,
            'quotation_id' => (int) $package->quotation_id,
            'items' => $package->items->map(fn (DeliveryPackageItem $i) => [
                'kind' => (string) $i->kind,
                'title' => (string) $i->title,
                'description' => (string) $i->description,
                'quantity' => $i->quantity === null ? null : number_format((float) $i->quantity, 3, '.', ''),
                'unit' => (string) $i->unit,
                'name' => (string) $i->name,
                'size' => (int) $i->size,
                'checksum' => (string) $i->checksum,
                'project_item_id' => $i->project_item_id === null ? null : (int) $i->project_item_id,
            ])->values()->all(),
        ];
    }

    public static function label(DeliveryPackage $package): string
    {
        return $package->number ?: __('مسودة').' #'.$package->id;
    }

    /* ─────────────────────────── أدوات ─────────────────────────── */

    private static function lock(DeliveryPackage $package): DeliveryPackage
    {
        return DeliveryPackage::where('business_id', $package->business_id)
            ->lockForUpdate()->findOrFail($package->id);
    }

    private static function text(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === null || $value === '' ? null : mb_substr($value, 0, $max);
    }
}
