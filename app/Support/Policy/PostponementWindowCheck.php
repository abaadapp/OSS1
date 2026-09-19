<?php

namespace App\Support\Policy;

use App\Models\ChangeOrder;
use App\Models\CompanyPolicy;
use App\Models\Quotation;
use Illuminate\Support\Carbon;

/**
 * نافذةُ التأجيل — كم يومًا بين طلب التأجيل والموعد القائم.
 *
 * السياسةُ تقول «يُقبل التأجيل مرّةً إذا طُلب قبل الفعاليّة بسبعة أيّام».
 * وأمرُ التغيير يحمل موعدًا جديدًا لفعاليّةٍ بعد ثلاثة — فيُرصد الفرق.
 * وتأجيلٌ ثانٍ بعد تأجيلٍ قُبل يُرصد أيضًا: النصُّ الذي قَبِله العميل
 * يقول «مرّةً».
 *
 * ولا يُفحص إلّا أمرُ تغييرٍ يحمل موعدًا: العرضُ لا يؤجَّل، والأمرُ الذي
 * لا يمسّ الموعدَ لا يؤجِّل.
 */
final class PostponementWindowCheck implements Check
{
    public function evaluate(CompanyPolicy $policy, Quotation|ChangeOrder $subject): ?Finding
    {
        if (! $subject instanceof ChangeOrder || $subject->new_starts_at === null) {
            return null;
        }

        $project = $subject->project;
        $current = $project?->starts_at;

        if ($project === null || $current === null) {
            return null;
        }

        $required = max(0, (int) $policy->param('days', 0));

        /* ومن يوم كتابة الأمر لا من اليوم: أمرٌ كُتب أمسِ يُقرأ اليومَ بمهلته */
        $from = ($subject->created_at ?? now())->copy()->startOfDay();
        $available = (int) $from->diffInDays(Carbon::parse($current)->startOfDay(), absolute: false);

        $prior = (int) ChangeOrder::where('project_id', $project->id)
            ->accepted()->whereNotNull('new_starts_at')
            ->when($subject->exists, fn ($q) => $q->whereKeyNot($subject->id))
            ->count();

        $late = $required > 0 && $available < $required;

        if (! $late && $prior === 0) {
            return null;
        }

        return new Finding(
            $policy,
            $late
                ? __('تأجيلٌ متأخّر — المهلة :required أيام والمتاح :available.', [
                    'required' => $required, 'available' => max(0, $available),
                ])
                : __('تأجيلٌ ثانٍ — والسياسةُ تقبل التأجيل مرّةً واحدة.'),
            [
                'required_days' => $required,
                'available_days' => $available,
                'current_starts_at' => Carbon::parse($current)->toDateString(),
                'new_starts_at' => $subject->new_starts_at->toDateString(),
                'prior_postponements' => $prior,
                'from' => $from->toDateString(),
            ],
        );
    }
}
