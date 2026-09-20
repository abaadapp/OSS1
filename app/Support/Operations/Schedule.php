<?php

namespace App\Support\Operations;

use App\Models\EventOperation;
use App\Models\OperationScheduleItem;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * الجدولُ التشغيليّ — ما يقع ومتى ومن مسؤولُه، و«ماذا الآن».
 *
 * ليست مهامَّ المشروع (`ProjectTask` — عملٌ داخليٌّ بموعدِ يوم) بل دقائقُ
 * يوم الفعاليّة: خروجُ المعدّات، وصولُ الفريق، فتحُ الأبواب، الفكّ.
 */
final class Schedule
{
    public const TYPE_LABELS = [
        'logistics' => 'لوجستيات', 'setup' => 'تجهيز', 'technical' => 'فنّي', 'registration' => 'تسجيل', 'briefing' => 'إحاطة',
        'live' => 'أثناء الفعاليّة', 'teardown' => 'فكّ', 'return' => 'إرجاع', 'other' => 'أخرى',
    ];

    public const STATUS_LABELS = [
        OperationScheduleItem::PENDING => 'قادم', OperationScheduleItem::READY => 'جاهز', OperationScheduleItem::IN_PROGRESS => 'جارٍ',
        OperationScheduleItem::COMPLETED => 'تمّ', OperationScheduleItem::SKIPPED => 'تُخطّي', OperationScheduleItem::BLOCKED => 'معطَّل',
    ];

    /** @param  array<string, mixed>  $data */
    public static function add(EventOperation $operation, array $data, ?User $actor = null): OperationScheduleItem
    {
        if (! $operation->isActive()) {
            throw new RuntimeException(__('لا يُعدَّل جدولُ فعاليّةٍ خُتمت.'));
        }

        $item = OperationScheduleItem::create(self::attributes($operation, $data, null) + [
            'business_id' => $operation->business_id,
            'event_operation_id' => $operation->id,
            'status' => OperationScheduleItem::PENDING,
            'sort_order' => (int) OperationScheduleItem::where('event_operation_id', $operation->id)->max('sort_order') + 1,
        ]);

        return $item;
    }

    /** @param  array<string, mixed>  $data */
    public static function update(OperationScheduleItem $item, array $data, ?User $actor = null): OperationScheduleItem
    {
        $operation = $item->operation;
        if (! $operation->isActive()) {
            throw new RuntimeException(__('لا يُعدَّل جدولُ فعاليّةٍ خُتمت.'));
        }

        $item->update(self::attributes($operation, $data, $item));

        return $item->fresh();
    }

    public static function remove(OperationScheduleItem $item, ?User $actor = null): void
    {
        if (! $item->operation->isActive()) {
            throw new RuntimeException(__('لا يُعدَّل جدولُ فعاليّةٍ خُتمت.'));
        }
        $item->delete();
    }

    /** تغييرُ حال بند — من شاشة الموقع غالبًا. آمنٌ عند التكرار */
    public static function setStatus(OperationScheduleItem $item, string $status, ?User $actor = null): OperationScheduleItem
    {
        if (! in_array($status, OperationScheduleItem::STATUSES, true)) {
            throw new RuntimeException(__('حالُ البند غير معروفة.'));
        }
        if ($item->status === $status) {
            return $item;
        }

        $item->update([
            'status' => $status,
            'completed_at' => $status === OperationScheduleItem::COMPLETED ? ($item->completed_at ?? now()) : null,
            'completed_by' => $status === OperationScheduleItem::COMPLETED ? ($item->completed_by ?? $actor?->id) : null,
        ]);

        if (in_array($status, [OperationScheduleItem::COMPLETED, OperationScheduleItem::BLOCKED, OperationScheduleItem::SKIPPED], true)) {
            Activity::log('status', 'بند الجدول «'.$item->title.'» في الفعاليّة '.(string) $item->operation?->code.' صار «'.__(self::STATUS_LABELS[$status]).'»', [
                'subject_id' => $item->event_operation_id, 'subject_type' => 'event_operation',
                'color' => $status === OperationScheduleItem::BLOCKED ? 'danger' : ($status === OperationScheduleItem::COMPLETED ? 'success' : 'warning'),
            ]);
        }

        return $item->fresh();
    }

    /**
     * «ماذا الآن» — الجاري، والقادمُ، وما فات موعدُه ولم يُغلَق.
     *
     * @return array{current: list<OperationScheduleItem>, next: list<OperationScheduleItem>, overdue: list<OperationScheduleItem>}
     */
    public static function now(EventOperation $operation, ?Carbon $at = null): array
    {
        $at ??= now();
        $items = $operation->scheduleItems;

        $current = $items->filter(fn (OperationScheduleItem $i) => $i->status === OperationScheduleItem::IN_PROGRESS
            || (! $i->isSettled() && $i->starts_at !== null && $i->starts_at->lte($at) && ($i->ends_at === null ? $i->starts_at->copy()->addMinutes(30)->gte($at) : $i->ends_at->gte($at))));

        $next = $items->filter(fn (OperationScheduleItem $i) => ! $i->isSettled() && $i->status !== OperationScheduleItem::IN_PROGRESS
            && $i->starts_at !== null && $i->starts_at->gt($at))->sortBy('starts_at')->take(3);

        $overdue = $items->filter(fn (OperationScheduleItem $i) => ! $i->isSettled() && $i->status !== OperationScheduleItem::IN_PROGRESS
            && ($i->ends_at ?? $i->starts_at) !== null && ($i->ends_at ?? $i->starts_at->copy()->addMinutes(30))->lt($at))->sortBy('starts_at');

        return ['current' => $current->values()->all(), 'next' => $next->values()->all(), 'overdue' => $overdue->values()->all()];
    }

    /** @param  array<string, mixed>  $data */
    private static function attributes(EventOperation $operation, array $data, ?OperationScheduleItem $current): array
    {
        $out = [];

        if (array_key_exists('title', $data) || $current === null) {
            $title = EventOperations::text($data['title'] ?? null, 255);
            if ($title === null) {
                throw new RuntimeException(__('عنوانُ البند مطلوب.'));
            }
            $out['title'] = $title;
        }
        if (array_key_exists('description', $data)) {
            $out['description'] = EventOperations::text($data['description'], 5000);
        }
        if (array_key_exists('type', $data) || $current === null) {
            $type = $data['type'] ?? 'other';
            if (! in_array($type, OperationScheduleItem::TYPES, true)) {
                throw new RuntimeException(__('نوعُ البند غير معروف.'));
            }
            $out['type'] = $type;
        }
        if (array_key_exists('starts_at', $data)) {
            $out['starts_at'] = EventOperations::datetime($data['starts_at']);
        }
        if (array_key_exists('ends_at', $data)) {
            $out['ends_at'] = EventOperations::datetime($data['ends_at']);
        }
        $starts = $out['starts_at'] ?? $current?->starts_at;
        $ends = $out['ends_at'] ?? $current?->ends_at;
        if ($starts !== null && $ends !== null && $ends->lt($starts)) {
            throw new RuntimeException(__('نهاية البند قبل بدايته.'));
        }
        if (array_key_exists('responsible_user_id', $data)) {
            $out['responsible_user_id'] = EventOperations::userIdOf((int) $operation->business_id, $data['responsible_user_id']);
        }
        if (array_key_exists('responsible_name', $data)) {
            $out['responsible_name'] = EventOperations::text($data['responsible_name'], 120);
        }
        if (array_key_exists('notes', $data)) {
            $out['notes'] = EventOperations::text($data['notes'], 2000);
        }
        if (array_key_exists('sort_order', $data)) {
            $out['sort_order'] = max(0, (int) $data['sort_order']);
        }

        return $out;
    }
}
