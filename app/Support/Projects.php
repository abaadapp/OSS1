<?php

namespace App\Support;

use App\Models\Business;
use App\Models\ChangeOrder;
use App\Models\CustomerContact;
use App\Models\CustomerInvoice;
use App\Models\DeliveryPackage;
use App\Models\EventOperation;
use App\Models\Project;
use App\Models\ProjectItem;
use App\Models\ProjectMember;
use App\Models\ProjectRequirement;
use App\Models\ProjectTask;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Support\Policy\Registry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * المشاريع: من العرض المقبول، وتثبيتُ المتطلّبات، والفريقُ والمهامّ، والإقفال.
 *
 * ═══ المشروعُ يقرأ ولا يكتب ═══
 *
 * لا `Ledger` هنا ولا يجوز. والمالُ في مستنداته — انظر `ProjectFinancials`.
 *
 * ═══ والنطاقُ المعتمَد لا يُمسّ من هنا ═══
 *
 * البنودُ تُنسخ من العرض مرّةً، والمتطلّباتُ تُجمَّد عند التثبيت. وما بعده
 * بابُه `ChangeOrders` وحده.
 */
final class Projects
{
    /** بادئةُ الرمز — «EP-2026-014» */
    public const PREFIX = 'EP-';

    /* ─────────────────────────── من العرض ─────────────────────────── */

    /**
     * العرضُ المقبول يصير مشروعًا — مرّةً واحدة.
     *
     * تُنادى من داخل معاملة القبول (`Quotations::decide` و`recordDecision`).
     * ومن أعاد النداء — ضغطةٌ ثانية أو طلبٌ مكرَّر — وجد مشروعَه القائم:
     * يُقرأ أوّلًا، وقيدُ `unique(quotation_id)` يردّ ما مرّ من بين القراءتين.
     */
    public static function fromQuotation(Quotation $quotation, ?User $actor = null): Project
    {
        if ($quotation->status !== Quotation::ACCEPTED) {
            throw new RuntimeException(__('لا يُنشأ مشروعٌ إلا من عرضٍ قُبل.'));
        }

        $existing = Project::where('business_id', $quotation->business_id)
            ->where('quotation_id', $quotation->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $quotation->loadMissing('items', 'customer');

        /*
         * ومن اختير في العرض يبقى مختارًا في مشروعه — ما لم يُعطَّل بعده.
         *
         * المشروعُ مستندٌ جديد، والمعطَّلُ لا يُوجَّه إليه مستندٌ جديد: يأخذ
         * المشروعُ أساسيَّ الجهة الآن مرجعًا ولقطة. والعرضُ نفسُه لا يُمسّ.
         */
        $chosen = $quotation->customer_contact_id
            ? CustomerContact::where('business_id', $quotation->business_id)->find($quotation->customer_contact_id)
            : null;
        $retired = $chosen !== null && ! $chosen->active;
        $primary = $retired || blank($quotation->attention_to) ? CustomerContacts::primary($quotation->customer) : null;

        try {
            return DB::transaction(function () use ($quotation, $actor, $retired, $primary) {
                $project = Project::create([
                    'business_id' => $quotation->business_id,
                    'customer_id' => $quotation->customer_id,
                    'code' => self::nextCode((int) $quotation->business_id),
                    'name' => (string) $quotation->title,
                    'status' => Project::CONFIRMED,
                    'quotation_id' => $quotation->id,
                    'location' => $quotation->event_location,
                    'starts_at' => $quotation->event_starts_at?->toDateString(),
                    'ends_at' => $quotation->event_ends_at?->toDateString(),
                    /* ومن اختير في العرض من أشخاص اتصال الجهة يبقى مختارًا في مشروعه — والأسماءُ لقطة */
                    'customer_contact_id' => $retired ? ($primary['id'] ?? null) : $quotation->customer_contact_id,
                    'contact_person' => $retired ? ($primary['name'] ?? null) : ($quotation->attention_to ?: ($primary['name'] ?? null)),
                    'contact_phone' => $retired ? ($primary['phone'] ?? null) : $quotation->contact_phone,
                    'contact_email' => $retired ? ($primary['email'] ?? null) : $quotation->contact_email,
                    'quoted_value' => (float) $quotation->total,
                    /* الأصلُ — لقطةٌ لا تُكتب بعدها */
                    'approved_value' => (float) $quotation->total,
                    'owner_id' => $actor?->id ?? $quotation->created_by,
                    'notes' => $quotation->scope,
                    'internal_notes' => $quotation->internal_notes,
                    'created_by' => $actor?->id,
                    /* العرضُ تمّ بقبوله — والمسارُ يبدأ من نطاق العمل (`ProjectWorkflow`) */
                    'workflow_stage' => ProjectWorkflow::SCOPE,
                ]);

                foreach ($quotation->items as $item) {
                    self::copyItem($project, $item);
                }

                Activity::log('created', 'أُنشئ المشروع '.$project->code.' من العرض '.Quotations::label($quotation), [
                    'business_id' => $project->business_id,
                    'subject_id' => $project->id, 'subject_type' => 'project',
                    'icon' => 'briefcase', 'color' => 'success',
                ]);

                return $project;
            });
        } catch (QueryException $e) {
            /* القيدُ الفريد ردّ إنشاءً ثانيًا سبقه أوّل — فيُقرأ ما سبق */
            $winner = Project::where('business_id', $quotation->business_id)
                ->where('quotation_id', $quotation->id)->first();

            if ($winner === null) {
                throw $e;
            }

            return $winner;
        }
    }

    /** بندُ العرض بندَ مشروع — الكميّةُ الأصليّة، والسعرُ المعروض، والضريبةُ كما كانت */
    private static function copyItem(Project $project, QuotationItem $item): ProjectItem
    {
        $quantity = round((float) $item->quantity, 3);
        $unitPrice = round((float) $item->unit_price, 3);
        $taxRate = round((float) $item->tax_rate, 2);

        return ProjectItem::create([
            'business_id' => $project->business_id,
            'project_id' => $project->id,
            'product_id' => $item->product_id,
            'quotation_item_id' => $item->id,
            'description' => (string) $item->description,
            'unit' => $item->unit,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => $taxRate,
            'line_total' => ProjectItem::lineTotal($quantity, $unitPrice, $taxRate),
            'status' => ProjectItem::PENDING,
            'sort_order' => (int) $item->sort_order,
        ]);
    }

    /**
     * رمزٌ متسلسلٌ في السنة — «EP-2026-014». وتحت قفل صفّ الشركة كما في `Numbering`.
     */
    public static function nextCode(int $businessId): string
    {
        return DB::transaction(function () use ($businessId) {
            Business::whereKey($businessId)->lockForUpdate()->first();

            $prefix = self::PREFIX.now()->year.'-';

            $highest = (int) Project::where('business_id', $businessId)
                ->where('code', 'like', $prefix.'%')
                ->get(['code'])
                ->map(fn ($r) => (int) substr((string) $r->code, strlen($prefix)))
                ->max();

            return $prefix.str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
        });
    }

    /* ─────────────────────────── التفاصيل ─────────────────────────── */

    /**
     * تعديلُ ما ليس نطاقًا: الاسمُ والموقعُ وجهةُ الاتّصال والمسؤول.
     *
     * ولا موعدَ هنا: الموعدُ من النطاق المعتمَد، ولا يتغيّر إلّا بأمر تغيير.
     *
     * @param  array<string, mixed>  $data
     */
    public static function update(Project $project, array $data, ?User $actor = null): Project
    {
        if (! $project->isOpen() && $project->status !== Project::DRAFT) {
            throw new RuntimeException(__('هذا المشروع مُقفَل — لا يُعدَّل.'));
        }

        $businessId = (int) $project->business_id;
        $ownerId = isset($data['owner_id']) && $data['owner_id'] !== '' && $data['owner_id'] !== null
            ? (int) $data['owner_id'] : null;

        if ($ownerId !== null && ! User::where('business_id', $businessId)->whereKey($ownerId)->exists()) {
            throw new RuntimeException(__('هذا الموظّف ليس من شركتك.'));
        }

        /* شخصُ الاتصال المختار — من أشخاص جهة المشروع، وما لم يُرسَل يبقى كما كان */
        $contactId = array_key_exists('customer_contact_id', $data)
            ? CustomerContacts::pick($businessId, $project->customer_id, $data['customer_contact_id'], $project->customer_contact_id)?->id
            : $project->customer_contact_id;

        $project->update([
            'customer_contact_id' => $contactId,
            'name' => self::text($data['name'] ?? null, 255) ?? $project->name,
            'event_type' => self::text($data['event_type'] ?? null, 60),
            'location' => self::text($data['location'] ?? null, 255),
            'contact_person' => self::text($data['contact_person'] ?? null, 120),
            'contact_phone' => self::text($data['contact_phone'] ?? null, 40),
            'contact_email' => self::text($data['contact_email'] ?? null, 120),
            'owner_id' => $ownerId,
            'notes' => self::text($data['notes'] ?? null, 20000),
            'internal_notes' => array_key_exists('internal_notes', $data)
                ? self::text($data['internal_notes'], 20000) : $project->internal_notes,
        ]);

        Activity::log('updated', 'عدّل بيانات المشروع '.$project->code, [
            'subject_id' => $project->id, 'subject_type' => 'project',
        ]);

        return $project->fresh();
    }

    /* ─────────────────────────── المتطلّبات ─────────────────────────── */

    /**
     * تثبيتُ المتطلّبات — مرّةً، ولا يُعاد.
     *
     * بعدها تُجمَّد صفوفُ الأصل: لا تُعدَّل ولا تُحذف من هنا، وتُضاف أو
     * تُزال بأمر تغييرٍ مقبول. ما ثُبّت هو ما سيُحاسَب عليه الفريقُ ويقيسه
     * العميل — ولا يُصحَّح بصمت.
     */
    public static function baseline(Project $project, ?User $actor = null): Project
    {
        return DB::transaction(function () use ($project, $actor) {
            $locked = Project::where('business_id', $project->business_id)
                ->lockForUpdate()->findOrFail($project->id);

            if ($locked->isBaselined()) {
                throw new RuntimeException(__('ثُبّتت متطلّبات هذا المشروع من قبل — ولا يُعاد التثبيت.'));
            }

            if (! $locked->isOpen()) {
                throw new RuntimeException(__('لا تُثبَّت متطلّبات مشروعٍ مُقفَل أو ملغى.'));
            }

            $locked->update([
                'requirements_baselined_at' => now(),
                'requirements_baselined_by' => $actor?->id,
            ]);

            $count = (int) $locked->requirements()->baseline()->count();

            /* متطلّباتٌ ثُبّتت ← من · متى · كم صفًّا (§24) */
            Activity::log('status', 'ثبّت متطلّبات المشروع '.$locked->code.' — '.$count.' متطلّبًا', [
                'subject_id' => $locked->id, 'subject_type' => 'project',
                'icon' => 'lock', 'color' => 'success',
            ]);

            return $locked->fresh();
        });
    }

    /**
     * إضافةُ متطلَّبٍ — قبل التثبيت وحده.
     *
     * @param  array<string, mixed>  $data
     */
    public static function addRequirement(Project $project, array $data, ?User $actor = null): ProjectRequirement
    {
        self::assertRequirementsEditable($project);

        $row = ProjectRequirement::create([
            'business_id' => $project->business_id,
            'project_id' => $project->id,
            'project_item_id' => self::itemIdOf($project, $data['project_item_id'] ?? null),
            'title' => self::text($data['title'] ?? null, 255) ?? '',
            'details' => self::text($data['details'] ?? null, 5000),
            'sort_order' => (int) ($project->requirements()->max('sort_order') ?? 0) + 1,
            'created_by' => $actor?->id,
        ]);

        Activity::log('created', 'أضاف متطلّبًا إلى المشروع '.$project->code.' — '.$row->title, [
            'subject_id' => $project->id, 'subject_type' => 'project',
        ]);

        return $row;
    }

    /** @param  array<string, mixed>  $data */
    public static function updateRequirement(ProjectRequirement $requirement, array $data, ?User $actor = null): ProjectRequirement
    {
        $project = $requirement->project;
        self::assertRequirementsEditable($project);
        self::assertRequirementFree($requirement);

        $requirement->update([
            'project_item_id' => self::itemIdOf($project, $data['project_item_id'] ?? null),
            'title' => self::text($data['title'] ?? null, 255) ?? $requirement->title,
            'details' => self::text($data['details'] ?? null, 5000),
        ]);

        Activity::log('updated', 'عدّل متطلّبًا في المشروع '.$project->code.' — '.$requirement->title, [
            'subject_id' => $project->id, 'subject_type' => 'project',
        ]);

        return $requirement->fresh();
    }

    public static function deleteRequirement(ProjectRequirement $requirement, ?User $actor = null): void
    {
        $project = $requirement->project;
        self::assertRequirementsEditable($project);
        self::assertRequirementFree($requirement);

        $title = $requirement->title;
        $requirement->delete();

        Activity::log('deleted', 'حذف متطلّبًا من المشروع '.$project->code.' — '.$title, [
            'subject_id' => $project->id, 'subject_type' => 'project',
        ]);
    }

    /**
     * أتُعدَّل المتطلّباتُ مباشرةً؟ — قبل التثبيت وحده.
     *
     * والردُّ في الخادم لا في الشاشة: من فتح أدوات المتصفّح يرسل ما يشاء.
     */
    private static function assertRequirementsEditable(Project $project): void
    {
        if ($project->isBaselined()) {
            throw new RuntimeException(__('ثُبّتت متطلّبات هذا المشروع — التغيير بأمر تغييرٍ يقبله العميل.'));
        }

        if (! $project->isOpen() && $project->status !== Project::DRAFT) {
            throw new RuntimeException(__('هذا المشروع مُقفَل — لا يُعدَّل.'));
        }
    }

    /**
     * أليس هذا المتطلَّبُ دليلًا في يد أمر تغيير؟
     *
     * ما أضافه أمرٌ يُكتب من محرّر الأمر لا من هنا. وما وسمه أمرٌ **خرج**
     * للإزالة دخل في بصمته: تعديلُ عنوانه يجعل ما رآه العميل غيرَ ما في
     * الصفّ، فيُردّ قبولُه بلا أن يعرف أحدٌ لماذا. فيُمنع من مصدره.
     */
    private static function assertRequirementFree(ProjectRequirement $requirement): void
    {
        /* ويُقرأ الصفُّ الآن لا نموذجٌ فُتح قبل أن يحجزه أمر */
        $requirement->refresh();

        if ($requirement->change_order_id !== null) {
            throw new RuntimeException(__('هذا المتطلَّب أضافه أمرُ تغيير — يُعدَّل من الأمر لا من هنا.'));
        }

        $holder = $requirement->removedBy;

        if ($holder !== null && $holder->isFrozen()) {
            throw new RuntimeException(__('هذا المتطلَّب في أمر تغييرٍ خرج إلى العميل (:order) — لا يُمسّ حتى يُقرَّر.', [
                'order' => ChangeOrders::label($holder),
            ]));
        }
    }

    /** معرّفُ بندٍ من هذا المشروع — أو null، ولا معرّفَ من مشروعٍ آخر */
    public static function itemIdOf(Project $project, mixed $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }

        $item = ProjectItem::where('project_id', $project->id)->whereKey((int) $id)->first();

        if ($item === null) {
            throw new RuntimeException(__('هذا البند ليس من بنود المشروع.'));
        }

        return (int) $item->id;
    }

    /* ─────────────────────────── الفريق والمهامّ ─────────────────────────── */

    public static function addMember(Project $project, int $userId, ?string $role, ?User $actor = null): ProjectMember
    {
        $user = User::where('business_id', $project->business_id)->whereKey($userId)->first();

        if ($user === null) {
            throw new RuntimeException(__('هذا الموظّف ليس من شركتك.'));
        }

        $member = ProjectMember::firstOrCreate(
            ['project_id' => $project->id, 'user_id' => $user->id],
            ['business_id' => $project->business_id, 'role_in_project' => self::text($role, 60), 'assigned_at' => now()],
        );

        if ($member->wasRecentlyCreated) {
            Activity::log('updated', 'أضاف '.$user->name.' إلى فريق المشروع '.$project->code, [
                'subject_id' => $project->id, 'subject_type' => 'project',
            ]);
        } elseif ($role !== null) {
            $member->update(['role_in_project' => self::text($role, 60)]);
        }

        return $member;
    }

    public static function removeMember(ProjectMember $member, ?User $actor = null): void
    {
        $project = $member->project;
        $name = $member->user?->name ?? '';
        $member->delete();

        Activity::log('updated', 'أخرج '.$name.' من فريق المشروع '.$project->code, [
            'subject_id' => $project->id, 'subject_type' => 'project',
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public static function addTask(Project $project, array $data, ?User $actor = null): ProjectTask
    {
        return ProjectTask::create([
            'business_id' => $project->business_id,
            'project_id' => $project->id,
            'title' => self::text($data['title'] ?? null, 255) ?? '',
            'description' => self::text($data['description'] ?? null, 5000),
            'assignee_id' => self::userIdOf($project, $data['assignee_id'] ?? null),
            'due_at' => self::date($data['due_at'] ?? null),
            'status' => ProjectTask::OPEN,
            'priority' => in_array($data['priority'] ?? null, ProjectTask::PRIORITIES, true) ? $data['priority'] : 'normal',
            'created_by' => $actor?->id,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public static function updateTask(ProjectTask $task, array $data, ?User $actor = null): ProjectTask
    {
        $status = in_array($data['status'] ?? null, ProjectTask::STATUSES, true) ? $data['status'] : $task->status;

        $task->update([
            'title' => self::text($data['title'] ?? null, 255) ?? $task->title,
            'description' => array_key_exists('description', $data) ? self::text($data['description'], 5000) : $task->description,
            'assignee_id' => array_key_exists('assignee_id', $data) ? self::userIdOf($task->project, $data['assignee_id']) : $task->assignee_id,
            'due_at' => array_key_exists('due_at', $data) ? self::date($data['due_at']) : $task->due_at,
            'priority' => in_array($data['priority'] ?? null, ProjectTask::PRIORITIES, true) ? $data['priority'] : $task->priority,
            'status' => $status,
            'completed_at' => $status === ProjectTask::DONE ? ($task->completed_at ?? now()) : null,
        ]);

        return $task->fresh();
    }

    private static function userIdOf(Project $project, mixed $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }

        $user = User::where('business_id', $project->business_id)->whereKey((int) $id)->first();

        if ($user === null) {
            throw new RuntimeException(__('هذا الموظّف ليس من شركتك.'));
        }

        return (int) $user->id;
    }

    /* ─────────────────────────── الدورة ─────────────────────────── */

    /** بدءُ التنفيذ · التأجيلُ · الاستئناف — انتقالاتٌ بين الحالات المفتوحة */
    public static function transition(Project $project, string $to, ?string $reason = null, ?User $actor = null): Project
    {
        $allowed = match ($project->status) {
            Project::CONFIRMED => [Project::IN_PROGRESS, Project::ON_HOLD, Project::CANCELLED],
            Project::IN_PROGRESS => [Project::ON_HOLD, Project::CANCELLED],
            Project::ON_HOLD => [Project::IN_PROGRESS, Project::CANCELLED],
            Project::DRAFT => [Project::CANCELLED],
            default => [],
        };

        if (! in_array($to, $allowed, true)) {
            throw new RuntimeException(__('لا ينتقل المشروع من «:from» إلى «:to».', ['from' => $project->status, 'to' => $to]));
        }

        /* والإلغاءُ له بابُه: سياسةٌ تُقاس ورسمٌ قد يُفوتَر — انظر `cancel` */
        if ($to === Project::CANCELLED) {
            return self::cancel($project, (string) $reason, $actor);
        }

        $project->update([
            'status' => $to,
            'cancelled_at' => $to === Project::CANCELLED ? now() : null,
            'cancellation_reason' => $to === Project::CANCELLED ? self::text($reason, 255) : null,
        ]);

        Activity::log('status', 'نقل المشروع '.$project->code.' إلى «'.$to.'»'.($reason ? ' — '.$reason : ''), [
            'subject_id' => $project->id, 'subject_type' => 'project',
            'color' => $to === Project::CANCELLED ? 'danger' : 'primary',
        ]);

        return $project->fresh();
    }

    /**
     * الإقفال — ولا يُقفَل ما عليه التزامٌ لم يصر تكلفةً بعد.
     *
     * الملتزَم (§16): أمرُ شراءٍ لم يُغطِّه سندٌ معتمَد، أو سندٌ معلَّق، أو
     * مصروفٌ لم يُسدَّد. ومشروعٌ يُقفَل وعليه ذلك يُقفَل على ربحٍ سيتغيّر
     * غدًا. ولا فاتورةَ مسودّة: ورقةٌ لم تُصدَر بعدُ لا تُترك خلف بابٍ مُقفَل.
     *
     * وتُكتب لقطةُ الأرقام: الربحيّةُ مشتقّةٌ من مستنداتٍ قد تُلغى بعد سنة،
     * واللقطةُ تقول «هذا ما كان عند الإقفال».
     */
    public static function close(Project $project, ?User $actor = null): Project
    {
        return DB::transaction(function () use ($project, $actor) {
            $locked = Project::where('business_id', $project->business_id)
                ->lockForUpdate()->findOrFail($project->id);

            if ($locked->isClosed()) {
                return $locked;
            }

            if (! $locked->isOpen()) {
                throw new RuntimeException(__('لا يُقفَل إلا مشروعٌ قائم.'));
            }

            $summary = ProjectFinancials::summary($locked);

            $blockers = self::closeBlockers($locked, $summary);
            if ($blockers !== []) {
                throw new RuntimeException($blockers[0]);
            }

            $locked->update([
                'status' => Project::CLOSED,
                'closed_at' => now(),
                'closed_by' => $actor?->id,
                'financials_frozen' => $summary + ['frozen_at' => now()->toDateTimeString()],
            ]);

            /* مشروعٌ أُقفل ← من · متى · لقطةُ الأرقام (§24) */
            Activity::log('status', 'أقفل المشروع '.$locked->code.' — ربحٌ محقَّق '.number_format($summary['realized_gross_profit'], 3), [
                'subject_id' => $locked->id, 'subject_type' => 'project',
                'icon' => 'lock', 'color' => 'success',
            ]);

            return $locked->fresh();
        });
    }

    /**
     * ما يمنع إقفالَ المشروع الآن — بالنصّ نفسِه الذي يردّ به الإقفال.
     *
     * الشاشةُ تعرضه قبل أن يُضغط الزرّ، و`close` يرمي أوّلَه: مصدرٌ واحدٌ
     * للموانع فلا تقول الصفحةُ «جاهز» ويرفض الإقفال.
     *
     * - نطاقُ عملٍ لم يوقّعه العميل — مسارُ المشروع يبدأ منه (`ScopesOfWork`).
     * - تنفيذٌ لم يكتمل: لا فعاليّةَ أُتمّت، أو فعاليّةٌ قائمةٌ لم تنتهِ.
     * - التزامٌ لم يصر تكلفةً (أمرُ شراءٍ مفتوح، فاتورةُ مورّدٍ معلَّقة…).
     * - فاتورةُ عميلٍ مسودّة.
     * - أمرُ تغييرٍ ينتظر قرارًا: قبولُه بعد الإقفال يغيّر نطاقًا جُمّدت أرقامُه.
     * - تسليمٌ لم يُقبل — المرحلة 5: حزمةٌ قَبِلها العميل هي ما يقول «انتهى العمل».
     * - قيمةٌ لم تُفوتَر كلُّها: المفوتَرُ الصافي دون القيمة الحاليّة بعد أوامر التغيير.
     *
     * والمشروعُ المستورَد من التاريخ لا يُسأل عن نطاقٍ موقَّع ولا عن فعاليّة —
     * جرى قبل هذا النظام.
     *
     * @param  array<string, mixed>|null  $summary  ملخّصُ المال إن حُسب قبلُ
     * @return list<string>
     */
    public static function closeBlockers(Project $project, ?array $summary = null): array
    {
        $summary ??= ProjectFinancials::summary($project);
        $out = [];

        if ($summary['committed'] > 0.0005) {
            $out[] = __('على هذا المشروع التزامٌ لم يصر تكلفةً بعد (:amount) — سوِّه قبل الإقفال.', [
                'amount' => number_format($summary['committed'], 3),
            ]);
        }

        if ($project->invoices()->where('status', CustomerInvoice::DRAFT)->exists()) {
            $out[] = __('على هذا المشروع فاتورةٌ مسودّة — أصدرها أو احذفها قبل الإقفال.');
        }

        $pending = $project->changeOrders()
            ->whereIn('status', [ChangeOrder::PENDING_APPROVAL, ChangeOrder::SENT])->count();

        if ($pending > 0) {
            $out[] = __('على هذا المشروع :n أمرَ تغييرٍ ينتظر قرارًا — قرّر فيه أو ألغِه قبل الإقفال.', ['n' => $pending]);
        }

        $delivery = DeliveryReadiness::forProject($project);

        if (! $delivery->ready) {
            $out[] = __('لا يُقفَل المشروع: :why', ['why' => implode(' · ', $delivery->blockers)]);
        }

        /* وموانعُ المسار — بعد موانع المال والتسليم القديمة، فلا يتبدّل أوّلُ ما يُقال عنها */
        if (! ScopesOfWork::isSigned($project)) {
            $out[] = __('لم يوقّع العميل نطاق العمل بعد — أرسله للتوقيع قبل الإقفال.');
        }

        $execution = ProjectWorkflow::execution(
            EventOperation::where('business_id', $project->business_id)->where('project_id', $project->id)->get(['id', 'status', 'code']),
            ScopesOfWork::required($project),
        );
        foreach ($execution['pending'] as $why) {
            $out[] = $why;
        }

        $unbilled = round((float) $summary['current_value'] - (float) $summary['net_invoiced'], 3);

        if ($unbilled > 0.0005) {
            $out[] = __('لم تُفوتر قيمة المشروع كلّها — باقٍ :amount. أصدر الفاتورة النهائية قبل الإقفال.', [
                'amount' => number_format($unbilled, 3),
            ]);
        }

        return $out;
    }

    /**
     * حكمُ سياسة الإلغاء على هذا المشروع لو أُلغي الآن — للشاشة قبل القرار.
     *
     * يُقاس على موعد الفعاليّة **الحاليّ** (بعد أوامر التأجيل) وعلى قيمة
     * النطاق **الحاليّة** (بعد أوامر التغيير) — ما يُحاسَب عليه العميل هو ما
     * اتُّفق عليه آخرًا. و**صافيةً من الضريبة**: القيمةُ المعتمَدة إجماليٌّ
     * بضريبته، والرسمُ يُفوتَر ببنده فتُضاف ضريبتُه هناك — نسبةٌ من إجماليٍّ
     * ثمّ ضريبةٌ عليها ضريبةٌ على ضريبة.
     */
    public static function cancellationVerdict(Project $project): CancellationVerdict
    {
        $gross = ProjectScope::currentValue($project);
        $rate = Vat::rate((int) $project->business_id);
        $net = $rate <= 0 || $gross <= 0 ? $gross : round($gross / (1 + $rate / 100), 3);

        return CancellationWindow::evaluate((int) $project->business_id, $project->starts_at, $net);
    }

    /**
     * الإلغاء — بابٌ واحد: تُقاس السياسة، ويُفوتَر الرسمُ أو يُعتمَد استثناؤه.
     *
     * ═══ الحكمُ يُقرأ من `CancellationWindow` لا يُعاد حسابُه ═══
     *
     * داخلَ المهلة: يُلغى بلا رسم. خارجَها بنسبةٍ موجبة: إمّا فاتورةُ رسمٍ
     * تُصدَر عبر `CustomerInvoices` — ببندٍ واحدٍ يحمل `project_id` والسياسةَ
     * ونسبتَها — وإمّا استثناءٌ في `policy_exceptions` باسم من يملك
     * `POLICY_OVERRIDE` وسببِه. ولا تجاوزَ بلا الفعل: من لا يملكه يُفوتَر أو
     * يطلب ممّن يملكه.
     *
     * ═══ ومرّةً واحدة ═══
     *
     * تحت قفل صفّ المشروع: ملغًى يُعاد فيردّ نفسَه، و`cancellation_fee_invoice_id`
     * يقول إن كان الرسمُ صدر. والفاتورةُ والحالةُ في معاملةٍ واحدة — لا
     * فاتورةَ بلا إلغاء ولا إلغاءَ ضاعت فاتورتُه.
     *
     * @param  string|null  $exceptionReason  سببُ الإعفاء من الرسم — لمن يملك التجاوز
     */
    public static function cancel(Project $project, string $reason, ?User $actor = null, ?string $exceptionReason = null): Project
    {
        $reason = self::text($reason, 255);

        if ($reason === null) {
            throw new RuntimeException(__('اكتب سبب الإلغاء.'));
        }

        return DB::transaction(function () use ($project, $reason, $actor, $exceptionReason) {
            $locked = Project::where('business_id', $project->business_id)
                ->lockForUpdate()->findOrFail($project->id);

            if ($locked->status === Project::CANCELLED) {
                return $locked;
            }
            if (! in_array($locked->status, [Project::DRAFT, Project::CONFIRMED, Project::IN_PROGRESS, Project::ON_HOLD], true)) {
                throw new RuntimeException(__('لا ينتقل المشروع من «:from» إلى «:to».', ['from' => $locked->status, 'to' => Project::CANCELLED]));
            }

            /* وحزمةُ تسليمٍ عند العميل تنتظر قرارَه: تُقرَّر أو تُلغى من بابها قبل إلغاء المشروع */
            if (DeliveryPackage::where('project_id', $locked->id)->where('status', DeliveryPackage::SENT)->exists()) {
                throw new RuntimeException(__('على هذا المشروع حزمةُ تسليمٍ عند العميل — قرّر فيها أو ألغِها قبل إلغاء المشروع.'));
            }

            $verdict = self::cancellationVerdict($locked);
            $exceptionReason = self::text($exceptionReason, 500);
            $snapshot = $verdict->toArray() + ['exception_id' => null, 'fee_invoice_id' => null];

            Activity::log('status', 'قِيست سياسة الإلغاء على المشروع '.$locked->code.' — '.$verdict->message(), [
                'subject_id' => $locked->id, 'subject_type' => 'project', 'icon' => 'shield-alert', 'color' => 'warning',
            ]);

            if ($verdict->requiresFee()) {
                if ($exceptionReason !== null) {
                    /* الإعفاءُ فعلٌ باسمه — لا يُمنح لمن لا يملكه */
                    if (! $actor?->may(Permissions::POLICY_OVERRIDE)) {
                        throw new RuntimeException(__('الإعفاء من رسم الإلغاء يحتاج صلاحية تجاوز السياسة — اطلبه ممّن يملكها أو أصدر الرسم.'));
                    }

                    $exception = $locked->policyExceptions()->create([
                        'business_id' => $locked->business_id,
                        'policy_key' => CancellationWindow::KEY,
                        'company_policy_id' => $verdict->policy?->id,
                        'policy_version' => (int) ($verdict->policy?->version ?? 1),
                        'detected' => $verdict->toArray(),
                        'reason' => $exceptionReason,
                        'surcharge_amount' => $verdict->feeAmount,
                        'requested_by' => $actor?->id,
                        'approved_by' => $actor?->id,
                        'approved_at' => now(),
                    ]);
                    $snapshot['exception_id'] = $exception->id;

                    Activity::log('status', 'أعفى المشروع '.$locked->code.' من رسم الإلغاء ('.number_format($verdict->feeAmount, 3).') — '.$exceptionReason, [
                        'subject_id' => $locked->id, 'subject_type' => 'project', 'icon' => 'shield-check', 'color' => 'warning',
                    ]);
                } elseif ($locked->cancellation_fee_invoice_id === null) {
                    $invoice = self::issueCancellationFee($locked, $verdict, $reason, $actor);
                    $snapshot['fee_invoice_id'] = $invoice->id;
                    $locked->cancellation_fee_invoice_id = $invoice->id;
                } else {
                    $snapshot['fee_invoice_id'] = $locked->cancellation_fee_invoice_id;
                }
            }

            /*
             * وما لم يُقرَّر من أوامر التغيير يُلغى معه: مشروعٌ ملغًى لا نطاقَ
             * له يتغيّر، وأمرٌ مُرسَل يبقى بيد العميل بابٌ يُفتح بعد الإلغاء.
             * ومن بابه (`ChangeOrders::cancel`) لا بكتابةٍ هنا — فيُفرَج عمّا
             * حجزه ويُسجَّل باسمه.
             */
            foreach ($locked->changeOrders()
                ->whereIn('status', [ChangeOrder::DRAFT, ChangeOrder::PENDING_APPROVAL, ChangeOrder::SENT])
                ->get() as $order) {
                ChangeOrders::cancel($order, __('أُلغي المشروع :code', ['code' => $locked->code]), $actor);
            }

            $locked->update([
                'status' => Project::CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'cancellation_fee_invoice_id' => $locked->cancellation_fee_invoice_id,
                'cancellation_policy' => $snapshot,
            ]);

            Activity::log('status', 'ألغى المشروع '.$locked->code.' — '.$reason, [
                'subject_id' => $locked->id, 'subject_type' => 'project', 'color' => 'danger',
            ]);

            return $locked->fresh();
        });
    }

    /**
     * فاتورةُ رسم الإلغاء — عبر `CustomerInvoices` لا بيدٍ في الجداول.
     *
     * بندٌ واحد يقول ما هو ومن أين: المشروعُ والسياسةُ ونسبتُها. وتُصدَر
     * فورًا: الرسمُ ذمّةٌ تنشأ بالإلغاء نفسِه لا مسودّةٌ تنتظر.
     */
    private static function issueCancellationFee(Project $locked, CancellationVerdict $verdict, string $reason, ?User $actor): CustomerInvoice
    {
        $customer = $locked->customer;

        if ($customer === null) {
            throw new RuntimeException(__('لا عميل للمشروع — لا تُصدَر فاتورة رسم الإلغاء.'));
        }

        $invoice = CustomerInvoices::create((int) $locked->business_id, $customer, [
            'project_id' => $locked->id,
            'issued_at' => now()->toDateString(),
            'notes' => __('رسم إلغاء المشروع :code — :reason', ['code' => $locked->code, 'reason' => $reason]),
            'internal_notes' => __('سياسة «:policy»: :message', [
                'policy' => Registry::label(CancellationWindow::KEY), 'message' => $verdict->message(),
            ]),
        ], [[
            'description' => __('رسم إلغاء — :name (:percent٪ من قيمة المشروع :code وفق سياسة الإلغاء)', [
                'name' => $locked->name, 'percent' => rtrim(rtrim(number_format($verdict->feePercent, 2, '.', ''), '0'), '.'), 'code' => $locked->code,
            ]),
            'quantity' => 1,
            'unit_price' => $verdict->feeAmount,
        ]], $actor?->id);

        /* والرسمُ ليس فوترةً لنطاق المشروع — فلا يُقاس بما بقي من قيمته (`ProjectBilling::capped`) */
        $invoice->forceFill(['billing_role' => ProjectBilling::CANCELLATION_FEE])->save();

        $issued = CustomerInvoices::issue($invoice, $actor?->id);

        Activity::log('created', 'أصدر فاتورة رسم الإلغاء '.$issued->number.' للمشروع '.$locked->code.' بمبلغ '.number_format((float) $issued->total, 3), [
            'subject_id' => $issued->id, 'subject_type' => 'customer_invoice', 'icon' => 'receipt', 'color' => 'danger',
        ]);

        return $issued;
    }

    /** إعادةُ الفتح — واللقطةُ تبقى: ما كان يومَ الإقفال لا يُمحى بفتحه */
    public static function reopen(Project $project, ?User $actor = null): Project
    {
        if (! $project->isClosed()) {
            throw new RuntimeException(__('لا يُعاد فتحُ إلا مشروعٍ مُقفَل.'));
        }

        $project->update(['status' => Project::IN_PROGRESS, 'closed_at' => null, 'closed_by' => null]);

        Activity::log('status', 'أعاد فتح المشروع '.$project->code, [
            'subject_id' => $project->id, 'subject_type' => 'project', 'icon' => 'unlock', 'color' => 'warning',
        ]);

        return $project->fresh();
    }

    /* ─────────────────────────── أدوات ─────────────────────────── */

    public static function text(mixed $value, int $max): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    public static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
