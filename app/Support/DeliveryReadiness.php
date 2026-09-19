<?php

namespace App\Support;

use App\Models\DeliveryPackage;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * جاهزيّةُ الإتمام من جهة التسليم — قراءةٌ فوق حزم التسليم، لا حكمٌ ماليّ.
 *
 * تجيب: هل قُبلت حزمةٌ نهائيّة؟ هل بقي رفضٌ لم يُعالَج؟ هل هناك حزمةٌ
 * خرجت وتنتظر؟ — وتردّ موانعَ تُقرأ. وشروطُ الإقفال الماليّة (فاتورةٌ
 * مسودّة، تكلفةٌ ملتزَمة) لصاحبها في المرحلة 4: `Projects::close` تنادي
 * هذه وتضمّ موانعَها إلى موانعها.
 *
 * و`Projects::close` تنادي `forProject` وتضمّ موانعَها إلى موانع المال.
 */
final class DeliveryReadiness
{
    /**
     * @param  Collection<int, DeliveryPackage>  $packages  حزمُ الجهة الواحدة كلُّها
     */
    public static function of(Collection $packages): DeliveryReadinessVerdict
    {
        $accepted = $packages->where('status', DeliveryPackage::ACCEPTED);
        $awaiting = $packages->where('status', DeliveryPackage::SENT);
        $inPreparation = $packages->whereIn('status', [DeliveryPackage::DRAFT, DeliveryPackage::READY]);

        /* رفضٌ لم يُعالَج: مرفوضةٌ لم تحلّ محلَّها حزمةٌ لاحقة */
        $supersededIds = $packages->pluck('supersedes_id')->filter()->all();
        $unresolvedDeclines = $packages->where('status', DeliveryPackage::DECLINED)
            ->reject(fn (DeliveryPackage $p) => in_array($p->id, $supersededIds, true));

        $blockers = [];

        if ($packages->isEmpty()) {
            $blockers[] = __('لا حزمة تسليم بعد.');
        } elseif ($accepted->isEmpty()) {
            $blockers[] = __('لم يقبل العميل حزمة تسليم بعد.');
        }
        foreach ($unresolvedDeclines as $p) {
            $blockers[] = __('حزمة التسليم :label مرفوضة ولم تُعالَج.', ['label' => DeliveryPackages::label($p)]);
        }
        foreach ($awaiting as $p) {
            $blockers[] = __('حزمة التسليم :label أُرسلت وتنتظر قرار العميل.', ['label' => DeliveryPackages::label($p)]);
        }
        foreach ($inPreparation as $p) {
            $blockers[] = __('حزمة التسليم :label ما زالت قيد الإعداد.', ['label' => DeliveryPackages::label($p)]);
        }

        return new DeliveryReadinessVerdict(
            ready: $blockers === [],
            blockers: $blockers,
            acceptedCount: $accepted->count(),
            awaitingCount: $awaiting->count(),
            unresolvedDeclineCount: $unresolvedDeclines->count(),
            lastAcceptedAt: $accepted->max('accepted_at')?->toDateTimeString(),
        );
    }

    /** حزمُ المشروع كلُّها — وهي ما يسأله الإقفال */
    public static function forProject(Project $project): DeliveryReadinessVerdict
    {
        return self::of(DeliveryPackage::where('business_id', $project->business_id)
            ->where('project_id', $project->id)->orderBy('id')->get());
    }

    /** حزمُ عرضٍ مقبولٍ واحد — لحزمٍ لم تُربط بمشروع */
    public static function forQuotation(int $businessId, int $quotationId): DeliveryReadinessVerdict
    {
        return self::of(DeliveryPackage::where('business_id', $businessId)
            ->where('quotation_id', $quotationId)->orderBy('id')->get());
    }
}
