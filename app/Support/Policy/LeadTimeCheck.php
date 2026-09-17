<?php

namespace App\Support\Policy;

use App\Models\CompanyPolicy;
use App\Models\Quotation;
use Illuminate\Support\Carbon;

/**
 * مهلةُ الطلب — كم يومًا بين العرض وبدء الفعاليّة.
 *
 * السياسةُ تقول «سبعةُ أيّامٍ على الأقلّ»، والعرضُ يقول «الفعاليّةُ بعد
 * ثلاثة». فيُرصد الفرقُ، وتُقترح رسومُ استعجالٍ من معاملات السياسة —
 * والموظّفُ يكتب السببَ، ومن يملك `policy.override` يعتمد.
 *
 * وعرضٌ بلا تاريخِ فعاليّةٍ لا يُفحص: لا مهلةَ لما لا موعدَ له.
 */
final class LeadTimeCheck implements Check
{
    public function evaluate(CompanyPolicy $policy, Quotation $subject): ?Finding
    {
        $required = max(0, (int) $policy->param('days', 0));

        if ($required === 0 || $subject->event_starts_at === null) {
            return null;
        }

        /*
         * ومن تاريخ العرض لا من اليوم: عرضٌ كُتب أمسِ لفعاليّةٍ بعد أسبوع
         * يُقرأ اليومَ بالمهلة نفسِها التي كُتب بها. والمسودّةُ بلا تاريخٍ
         * تُقرأ من اليوم.
         */
        $from = ($subject->issued_at ?? now())->copy()->startOfDay();
        $starts = Carbon::parse($subject->event_starts_at)->startOfDay();
        $available = (int) $from->diffInDays($starts, absolute: false);

        if ($available >= $required) {
            return null;
        }

        $percent = max(0.0, (float) $policy->param('surcharge_percent', 0));
        $surcharge = $percent > 0 ? round((float) $subject->subtotal * $percent / 100, 3) : null;

        return new Finding(
            $policy,
            __('طلبٌ عاجل — المهلة :required أيام والمتاح :available.', [
                'required' => $required, 'available' => max(0, $available),
            ]),
            [
                'required_days' => $required,
                'available_days' => $available,
                'event_starts_at' => $starts->toDateString(),
                'from' => $from->toDateString(),
                'surcharge_percent' => $percent,
            ],
            $surcharge,
        );
    }
}
