<?php

namespace App\Support\Operations;

use App\Models\Asset;
use App\Models\EventOperation;
use App\Models\OperationIncident;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * حوادثُ الميدان — تُسجَّل سريعًا وتُسنَد وتُحلّ وتُقفل.
 *
 * حادثٌ حرجٌ لم يُحلّ يمنع الإتمامَ حتى يُحلّ أو يُقرَّ بحمله صراحةً —
 * فلا يُدفن شيءٌ تحت «مكتملة».
 */
final class Incidents
{
    public const CATEGORY_LABELS = [
        'equipment' => 'معدّات', 'network' => 'شبكة وإنترنت', 'consumables' => 'مستهلَكات', 'attendee' => 'حضور', 'venue' => 'المكان',
        'staff' => 'فريق', 'damage' => 'تلف', 'delay' => 'تأخير', 'safety' => 'سلامة', 'other' => 'أخرى',
    ];

    public const SEVERITY_LABELS = [
        OperationIncident::LOW => 'منخفض', OperationIncident::MEDIUM => 'متوسّط', OperationIncident::HIGH => 'عالٍ', OperationIncident::CRITICAL => 'حرج',
    ];

    public const STATUS_LABELS = [
        OperationIncident::OPEN => 'مفتوح', OperationIncident::INVESTIGATING => 'قيد المعالجة', OperationIncident::RESOLVED => 'حُلّ', OperationIncident::CLOSED => 'مقفل',
    ];

    /** @param  array<string, mixed>  $data */
    public static function report(EventOperation $operation, array $data, ?User $actor = null): OperationIncident
    {
        $title = EventOperations::text($data['title'] ?? null, 200);
        if ($title === null) {
            throw new RuntimeException(__('عنوانُ الحادث مطلوب.'));
        }
        $category = $data['category'] ?? 'other';
        if (! in_array($category, OperationIncident::CATEGORIES, true)) {
            throw new RuntimeException(__('تصنيفُ الحادث غير معروف.'));
        }
        $severity = $data['severity'] ?? OperationIncident::MEDIUM;
        if (! in_array($severity, OperationIncident::SEVERITIES, true)) {
            throw new RuntimeException(__('شدّةُ الحادث غير معروفة.'));
        }

        $assetId = null;
        if (filled($data['asset_id'] ?? null)) {
            $assetId = Asset::where('business_id', $operation->business_id)->whereKey((int) $data['asset_id'])->value('id');
            if ($assetId === null) {
                throw new RuntimeException(__('هذه القطعة ليست من شركتك.'));
            }
        }

        $incident = DB::transaction(function () use ($operation, $data, $title, $category, $severity, $assetId, $actor) {
            $incident = OperationIncident::create([
                'business_id' => $operation->business_id,
                'event_operation_id' => $operation->id,
                'reported_by' => $actor?->id,
                'category' => $category,
                'severity' => $severity,
                'title' => $title,
                'description' => EventOperations::text($data['description'] ?? null, 5000),
                'occurred_at' => EventOperations::datetime($data['occurred_at'] ?? null) ?? now(),
                'status' => OperationIncident::OPEN,
                'assigned_to' => EventOperations::userIdOf((int) $operation->business_id, $data['assigned_to'] ?? null),
                'asset_id' => $assetId,
            ]);

            /* تلفٌ على قطعةٍ بعينها — تصير تالفةً في سجلّها حتى يُراجَع */
            if ($category === 'damage' && $assetId !== null) {
                $asset = Asset::find($assetId);
                if ($asset !== null && ! in_array($asset->status, [Asset::DAMAGED, Asset::LOST, Asset::RETIRED], true)) {
                    Assets::setStatus($asset, Asset::DAMAGED, __('حادث: :title', ['title' => $title]), $actor);
                }
            }

            return $incident;
        });

        Activity::log('created', 'سجّل حادثًا في الفعاليّة '.$operation->code.' — '.$title.' ('.__(self::SEVERITY_LABELS[$severity]).')', [
            'subject_id' => $incident->id, 'subject_type' => 'operation_incident', 'icon' => 'alert-triangle',
            'color' => in_array($severity, [OperationIncident::HIGH, OperationIncident::CRITICAL], true) ? 'danger' : 'warning',
        ]);

        return $incident;
    }

    /** @param  array<string, mixed>  $data */
    public static function update(OperationIncident $incident, array $data, ?User $actor = null): OperationIncident
    {
        $update = [];
        if (array_key_exists('assigned_to', $data)) {
            $update['assigned_to'] = EventOperations::userIdOf((int) $incident->business_id, $data['assigned_to']);
        }
        if (array_key_exists('severity', $data) && in_array($data['severity'], OperationIncident::SEVERITIES, true)) {
            $update['severity'] = $data['severity'];
        }
        if (array_key_exists('category', $data) && in_array($data['category'], OperationIncident::CATEGORIES, true)) {
            $update['category'] = $data['category'];
        }
        if (array_key_exists('title', $data)) {
            $update['title'] = EventOperations::text($data['title'], 200) ?? $incident->title;
        }
        if (array_key_exists('description', $data)) {
            $update['description'] = EventOperations::text($data['description'], 5000);
        }
        if (array_key_exists('status', $data) && $data['status'] === OperationIncident::INVESTIGATING && $incident->status === OperationIncident::OPEN) {
            $update['status'] = OperationIncident::INVESTIGATING;
        }

        $incident->update($update);

        return $incident->fresh();
    }

    /** الحلُّ — بنصٍّ يقول ما فُعل. آمنٌ عند التكرار */
    public static function resolve(OperationIncident $incident, string $resolution, ?User $actor = null): OperationIncident
    {
        $resolution = trim($resolution);
        if ($resolution === '') {
            throw new RuntimeException(__('اكتب كيف عولج الحادث.'));
        }
        if (in_array($incident->status, [OperationIncident::RESOLVED, OperationIncident::CLOSED], true)) {
            return $incident;
        }

        $incident->update(['status' => OperationIncident::RESOLVED, 'resolved_at' => now(), 'resolved_by' => $actor?->id, 'resolution' => mb_substr($resolution, 0, 5000)]);

        Activity::log('status', 'حلّ الحادث «'.$incident->title.'» في الفعاليّة '.(string) $incident->operation?->code.' — '.$resolution, [
            'subject_id' => $incident->id, 'subject_type' => 'operation_incident', 'icon' => 'check-circle', 'color' => 'success',
        ]);

        return $incident->fresh();
    }

    public static function close(OperationIncident $incident, ?User $actor = null): OperationIncident
    {
        if ($incident->status === OperationIncident::CLOSED) {
            return $incident;
        }
        if ($incident->status !== OperationIncident::RESOLVED) {
            throw new RuntimeException(__('لا يُقفل حادثٌ لم يُحلّ.'));
        }

        $incident->update(['status' => OperationIncident::CLOSED]);

        return $incident->fresh();
    }

    /** إعادةُ فتح حادثٍ حُلّ — تبيّن أنّه لم يُحلّ */
    public static function reopen(OperationIncident $incident, ?User $actor = null): OperationIncident
    {
        if ($incident->isUnresolved()) {
            return $incident;
        }

        $incident->update(['status' => OperationIncident::OPEN, 'resolved_at' => null, 'resolved_by' => null, 'carried_forward' => false]);

        Activity::log('status', 'أعاد فتح الحادث «'.$incident->title.'»', [
            'subject_id' => $incident->id, 'subject_type' => 'operation_incident', 'color' => 'warning',
        ]);

        return $incident->fresh();
    }

    /** الإقرارُ بحمل حادثٍ حرج إلى ما بعد الإتمام — بسببٍ يُكتب */
    public static function carryForward(OperationIncident $incident, string $reason, ?User $actor = null): OperationIncident
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException(__('اكتب لماذا يُحمل الحادث إلى ما بعد الإتمام.'));
        }
        if (! $incident->isUnresolved()) {
            throw new RuntimeException(__('الحادث محلولٌ أصلًا.'));
        }

        $incident->update(['carried_forward' => true, 'metadata' => ($incident->metadata ?? []) + ['carried_forward' => ['reason' => mb_substr($reason, 0, 255), 'by' => $actor?->name, 'at' => now()->toDateTimeString()]]]);

        Activity::log('status', 'أقرّ بحمل الحادث «'.$incident->title.'» إلى ما بعد إتمام الفعاليّة — '.$reason, [
            'subject_id' => $incident->id, 'subject_type' => 'operation_incident', 'icon' => 'shield-alert', 'color' => 'danger',
        ]);

        return $incident->fresh();
    }

    /**
     * الحوادثُ الحرجة المفتوحة في الشركة — لمركز العمليات.
     *
     * @return Collection<int, OperationIncident>
     */
    public static function active(int $businessId, int $limit = 50): Collection
    {
        return OperationIncident::where('business_id', $businessId)
            ->whereIn('status', OperationIncident::UNRESOLVED)
            ->whereIn('severity', [OperationIncident::HIGH, OperationIncident::CRITICAL])
            ->with('operation:id,code,name,status', 'assignee:id,name')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 ELSE 1 END")->orderByDesc('occurred_at')->limit($limit)->get();
    }
}
