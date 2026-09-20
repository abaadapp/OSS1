<?php

namespace App\Support\Operations;

use App\Models\EventOperation;
use App\Models\OperationChecklist;
use App\Models\OperationChecklistItem;
use App\Models\OperationChecklistTemplate;
use App\Models\OperationChecklistTemplateItem;
use App\Models\User;
use App\Support\Activity;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * قوائمُ التحقّق — قوالبُ تُستنسخ للفعاليّة، وبنودٌ تُنجَز أو تُعفى بسبب.
 *
 * ═══ القالبُ يُكتب مرّةً ═══
 *
 * `ensure()` تُنشئ القوالبَ الثلاثة الجاهزة (قبل الفعاليّة · التجهيز ·
 * الإقفال) لشركةٍ لا قوالبَ لها — ولا تمسّ ما كُتب: قالبٌ عدّلته الشركة
 * يبقى كما عدّلته. والفعاليّةُ تأخذ **نسخةً** من القالب: تعديلُ القالب بعدها
 * لا يغيّر قائمةَ فعاليّةٍ جارية.
 *
 * ═══ والإعفاءُ ليس إنجازًا ═══
 *
 * بندٌ إلزاميّ يُعفى بيدٍ تملك `checklist.manage` وسببٍ يُكتب — ويبقى
 * ظاهرًا: تحذيرٌ في الجاهزيّة لا مانع.
 */
final class Checklists
{
    public const PHASE_LABELS = [
        OperationChecklistTemplate::PRE_EVENT => 'قبل الفعاليّة', OperationChecklistTemplate::SETUP => 'التجهيز',
        OperationChecklistTemplate::CLOSING => 'الإقفال', OperationChecklistTemplate::CUSTOM => 'مخصَّصة',
    ];

    /** @var array<string, array{name: string, items: list<array{0: string, 1: bool}>}> */
    public const DEFAULTS = [
        OperationChecklistTemplate::PRE_EVENT => ['name' => 'قبل الفعاليّة', 'items' => [
            ['استلام قائمة الحضور', true], ['اختبار نموذج التسجيل', true], ['اختبار تذاكر QR', true],
            ['اختبار الطابعات', true], ['اختبار الماسحات', true], ['تأكيد الإنترنت في المكان', true],
            ['تجهيز إنترنت احتياطي', false], ['عدّ مخزون البطاقات', true], ['شحن الأجهزة', true],
            ['تأكيد الفريق', true], ['تأكيد جهة الاتصال في المكان', true], ['تأكيد المواقف والدخول', false],
            ['ملفّات العلامة جاهزة', false],
        ]],
        OperationChecklistTemplate::SETUP => ['name' => 'التجهيز', 'items' => [
            ['طاولة التسجيل جاهزة', true], ['الطابعات متّصلة', true], ['طباعة بطاقة اختبار', true],
            ['الماسحات متّصلة', true], ['الإنترنت مستقرّ', true], ['جهاز احتياطي متوفّر', false],
        ]],
        OperationChecklistTemplate::CLOSING => ['name' => 'الإقفال', 'items' => [
            ['عدّ المعدّات', true], ['عدّ المستهلَكات', false], ['نسخ الملفّات احتياطيًّا', true],
            ['إرجاع الأجهزة', true], ['إخلاء المكان', true], ['إكمال ملاحظات الحوادث', true],
        ]],
    ];

    /* ─────────────────────────── القوالب ─────────────────────────── */

    /** إنشاءُ القوالب الجاهزة لشركةٍ لا قوالبَ لها — بلا مسّ ما كُتب */
    public static function ensure(int $businessId): void
    {
        if (OperationChecklistTemplate::where('business_id', $businessId)->exists()) {
            return;
        }

        DB::transaction(function () use ($businessId) {
            $order = 0;
            foreach (self::DEFAULTS as $phase => $preset) {
                $template = OperationChecklistTemplate::create([
                    'business_id' => $businessId, 'name' => $preset['name'], 'phase' => $phase, 'active' => true, 'sort_order' => $order++,
                ]);
                foreach ($preset['items'] as $i => [$title, $required]) {
                    OperationChecklistTemplateItem::create(['template_id' => $template->id, 'title' => $title, 'required' => $required, 'sort_order' => $i]);
                }
            }
        });
    }

    /** @param  array<string, mixed>  $data */
    public static function createTemplate(int $businessId, array $data, ?User $actor = null): OperationChecklistTemplate
    {
        $name = EventOperations::text($data['name'] ?? null, 120);
        if ($name === null) {
            throw new RuntimeException(__('اسمُ القالب مطلوب.'));
        }
        $phase = $data['phase'] ?? OperationChecklistTemplate::CUSTOM;
        if (! in_array($phase, OperationChecklistTemplate::PHASES, true)) {
            throw new RuntimeException(__('مرحلةُ القالب غير معروفة.'));
        }

        return DB::transaction(function () use ($businessId, $data, $name, $phase) {
            $template = OperationChecklistTemplate::create([
                'business_id' => $businessId, 'name' => $name, 'phase' => $phase,
                'description' => EventOperations::text($data['description'] ?? null, 2000),
                'active' => (bool) ($data['active'] ?? true),
                'sort_order' => (int) OperationChecklistTemplate::where('business_id', $businessId)->max('sort_order') + 1,
            ]);
            self::writeTemplateItems($template, (array) ($data['items'] ?? []));

            return $template->fresh('items');
        });
    }

    /** @param  array<string, mixed>  $data */
    public static function updateTemplate(OperationChecklistTemplate $template, array $data, ?User $actor = null): OperationChecklistTemplate
    {
        return DB::transaction(function () use ($template, $data) {
            $update = [];
            if (array_key_exists('name', $data)) {
                $update['name'] = EventOperations::text($data['name'], 120) ?? $template->name;
            }
            if (array_key_exists('phase', $data) && in_array($data['phase'], OperationChecklistTemplate::PHASES, true)) {
                $update['phase'] = $data['phase'];
            }
            if (array_key_exists('description', $data)) {
                $update['description'] = EventOperations::text($data['description'], 2000);
            }
            if (array_key_exists('active', $data)) {
                $update['active'] = (bool) $data['active'];
            }
            $template->update($update);

            if (array_key_exists('items', $data)) {
                $template->items()->delete();
                self::writeTemplateItems($template, (array) $data['items']);
            }

            return $template->fresh('items');
        });
    }

    public static function deleteTemplate(OperationChecklistTemplate $template, ?User $actor = null): void
    {
        $template->delete(); /* والقوائمُ المستنسخة تبقى — `template_id` يصير فارغًا */
    }

    /** @param  array<int, mixed>  $items */
    private static function writeTemplateItems(OperationChecklistTemplate $template, array $items): void
    {
        $order = 0;
        foreach ($items as $raw) {
            $title = EventOperations::text(is_array($raw) ? ($raw['title'] ?? null) : $raw, 200);
            if ($title === null) {
                continue;
            }
            OperationChecklistTemplateItem::create([
                'template_id' => $template->id, 'title' => $title,
                'required' => is_array($raw) ? (bool) ($raw['required'] ?? true) : true, 'sort_order' => $order++,
            ]);
        }
        if ($order === 0) {
            throw new RuntimeException(__('القالب يحتاج بندًا واحدًا على الأقلّ.'));
        }
    }

    /* ─────────────────────────── الإلحاق ─────────────────────────── */

    /** القوالبُ الفعّالة كلُّها (قبل · تجهيز · إقفال) — عند إنشاء الفعاليّة */
    public static function attachDefaults(EventOperation $operation, ?User $actor = null): void
    {
        self::ensure((int) $operation->business_id);

        $templates = OperationChecklistTemplate::where('business_id', $operation->business_id)->where('active', true)
            ->whereIn('phase', [OperationChecklistTemplate::PRE_EVENT, OperationChecklistTemplate::SETUP, OperationChecklistTemplate::CLOSING])
            ->orderBy('sort_order')->orderBy('id')->with('items')->get();

        foreach ($templates as $template) {
            self::attach($operation, $template, $actor);
        }
    }

    /** نسخةٌ من القالب للفعاليّة — ولا تُلحق القائمةُ نفسُها مرّتين */
    public static function attach(EventOperation $operation, OperationChecklistTemplate $template, ?User $actor = null): OperationChecklist
    {
        if ((int) $template->business_id !== (int) $operation->business_id) {
            throw new RuntimeException(__('هذا القالب ليس من شركتك.'));
        }

        return DB::transaction(function () use ($operation, $template) {
            $existing = OperationChecklist::where('event_operation_id', $operation->id)->where('template_id', $template->id)->first();
            if ($existing !== null) {
                return $existing;
            }

            $checklist = OperationChecklist::create([
                'business_id' => $operation->business_id, 'event_operation_id' => $operation->id, 'template_id' => $template->id,
                'name' => $template->name, 'phase' => $template->phase,
                'sort_order' => (int) OperationChecklist::where('event_operation_id', $operation->id)->max('sort_order') + 1,
            ]);
            foreach ($template->items as $item) {
                OperationChecklistItem::create([
                    'business_id' => $operation->business_id, 'checklist_id' => $checklist->id, 'title' => $item->title,
                    'required' => (bool) $item->required, 'status' => OperationChecklistItem::PENDING, 'sort_order' => (int) $item->sort_order,
                ]);
            }

            return $checklist;
        });
    }

    /** بندٌ يُضاف بيدٍ إلى قائمة فعاليّة */
    public static function addItem(OperationChecklist $checklist, array $data, ?User $actor = null): OperationChecklistItem
    {
        $title = EventOperations::text($data['title'] ?? null, 200);
        if ($title === null) {
            throw new RuntimeException(__('عنوانُ البند مطلوب.'));
        }

        return OperationChecklistItem::create([
            'business_id' => $checklist->business_id, 'checklist_id' => $checklist->id, 'title' => $title,
            'required' => (bool) ($data['required'] ?? true), 'status' => OperationChecklistItem::PENDING,
            'assigned_user_id' => EventOperations::userIdOf((int) $checklist->business_id, $data['assigned_user_id'] ?? null),
            'due_at' => EventOperations::datetime($data['due_at'] ?? null),
            'sort_order' => (int) OperationChecklistItem::where('checklist_id', $checklist->id)->max('sort_order') + 1,
        ]);
    }

    /* ─────────────────────────── الإنجاز ─────────────────────────── */

    /** الإنجازُ — آمنٌ عند التكرار: بندٌ مُنجَز لا يُكتب ثانيةً ولا يُسجَّل ثانيةً */
    public static function complete(OperationChecklistItem $item, ?string $evidence, ?User $actor = null): OperationChecklistItem
    {
        return DB::transaction(function () use ($item, $evidence, $actor) {
            $locked = OperationChecklistItem::where('business_id', $item->business_id)->lockForUpdate()->findOrFail($item->id);

            if ($locked->status === OperationChecklistItem::DONE) {
                return $locked;
            }

            $locked->update([
                'status' => OperationChecklistItem::DONE, 'completed_at' => now(), 'completed_by' => $actor?->id,
                'evidence_note' => EventOperations::text($evidence, 2000) ?? $locked->evidence_note, 'blocked_reason' => null,
            ]);

            return $locked->fresh();
        });
    }

    public static function reopen(OperationChecklistItem $item, ?User $actor = null): OperationChecklistItem
    {
        if ($item->status === OperationChecklistItem::PENDING) {
            return $item;
        }
        $item->update(['status' => OperationChecklistItem::PENDING, 'completed_at' => null, 'completed_by' => null, 'blocked_reason' => null, 'waived_reason' => null, 'waived_by' => null]);

        return $item->fresh();
    }

    public static function block(OperationChecklistItem $item, string $reason, ?User $actor = null): OperationChecklistItem
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException(__('اكتب ما يعطّل البند.'));
        }
        $item->update(['status' => OperationChecklistItem::BLOCKED, 'blocked_reason' => mb_substr($reason, 0, 255)]);

        return $item->fresh();
    }

    /** الإعفاءُ — بإذنٍ وسبب، ويُقيَّد في السجلّ */
    public static function waive(OperationChecklistItem $item, string $reason, ?User $actor = null): OperationChecklistItem
    {
        if (! ($actor?->may(Permissions::CHECKLIST_MANAGE) ?? false)) {
            throw new RuntimeException(__('إعفاءُ بندٍ إلزاميّ يحتاج صلاحيّة قوائم التحقّق.'));
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException(__('اكتب سبب الإعفاء.'));
        }
        if ($item->status === OperationChecklistItem::WAIVED) {
            return $item;
        }

        $item->update(['status' => OperationChecklistItem::WAIVED, 'waived_reason' => mb_substr($reason, 0, 255), 'waived_by' => $actor?->id]);

        Activity::log('status', 'أعفى بند التحقّق «'.$item->title.'» في الفعاليّة '.(string) $item->checklist?->operation?->code.' — '.$reason, [
            'subject_id' => $item->checklist?->event_operation_id, 'subject_type' => 'event_operation', 'icon' => 'shield-alert', 'color' => 'danger',
        ]);

        return $item->fresh();
    }

    /* ─────────────────────────── القراءة ─────────────────────────── */

    /**
     * التقدّمُ لكلّ قائمة — والإلزاميُّ الناقص.
     *
     * @return list<array{id: int, name: string, phase: string, total: int, done: int, waived: int, blocked: int, required_pending: int}>
     */
    public static function progress(EventOperation $operation): array
    {
        $operation->loadMissing('checklists.items');

        return $operation->checklists->map(function (OperationChecklist $c) {
            $items = $c->items;

            return [
                'id' => $c->id, 'name' => $c->name, 'phase' => $c->phase,
                'total' => $items->count(),
                'done' => $items->where('status', OperationChecklistItem::DONE)->count(),
                'waived' => $items->where('status', OperationChecklistItem::WAIVED)->count(),
                'blocked' => $items->where('status', OperationChecklistItem::BLOCKED)->count(),
                'required_pending' => $items->filter(fn (OperationChecklistItem $i) => $i->required && ! $i->isSettled())->count(),
            ];
        })->values()->all();
    }
}
