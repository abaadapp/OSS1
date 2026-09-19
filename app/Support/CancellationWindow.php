<?php

namespace App\Support;

use App\Models\CompanyPolicy;
use Illuminate\Support\Carbon;

/**
 * مهلةُ الإلغاء — أهو داخل المهلة المجّانيّة أم خارجها، وما يترتّب عليه.
 *
 * ═══ قاعدةٌ تُحسب، لا فعلٌ يُنفَّذ ═══
 *
 * تقرأ سياسةَ `cancellation_window` القائمة (`days` · `fee_percent`) وتقيس
 * الموعدَ المطلوب على موعد الفعاليّة، وتردّ نتيجةً: كم يومًا بقي، وهل
 * الإلغاءُ مجّانيّ، وكم الرسمُ إن لم يكن. **ولا تكتب شيئًا**: لا فاتورةَ
 * ولا حالةَ مشروع.
 *
 * ═══ POST-PHASE-4 INTEGRATION ═══
 *
 * • تُنادى من مسار إلغاء المشروع (`Projects::cancel`) بموعد الفعاليّة
 *   وقيمة المشروع الحاليّة.
 * • رسمُ الإلغاء يُصدَر فاتورةَ عميلٍ عبر `CustomerInvoices::issue()`
 *   ببندٍ واحدٍ يحمل `project_id` — مرّةً، تحت قفل المشروع. لا هنا.
 * • والاستثناءُ من الرسم في `policy_exceptions` بـ`exceptionable = Project`
 *   وباعتماد `Permissions::POLICY_OVERRIDE` — الآليّةُ القائمة نفسُها.
 *
 * ولا تُسجَّل في `Policy\Registry` بعد: هي ليست فحصًا على عرضٍ قبل خروجه
 * كأختها `LeadTimeCheck`، بل حسابٌ لحظةَ طلب الإلغاء.
 */
final class CancellationWindow
{
    public const KEY = 'cancellation_window';

    /**
     * تقييمُ إلغاءٍ يُطلب في `$requestedAt` لفعاليّةٍ تبدأ في `$eventStartsAt`.
     *
     * والسياسةُ تُقرأ من الشركة ما لم تُمرَّر: عميلٌ بعقدٍ إطاريّ له سياستُه
     * — من يعرفها يمرّرها.
     */
    public static function evaluate(
        int $businessId,
        Carbon|string|null $eventStartsAt,
        float $baseAmount,
        Carbon|string|null $requestedAt = null,
        ?CompanyPolicy $policy = null,
    ): CancellationVerdict {
        $policy ??= self::policy($businessId);
        $requested = Carbon::parse($requestedAt ?? now())->startOfDay();
        $starts = $eventStartsAt === null ? null : Carbon::parse($eventStartsAt)->startOfDay();

        /* بلا سياسةٍ لا رسم: ما لم تكتبه الشركةُ لا يُحتسب على عميلها */
        if ($policy === null) {
            return new CancellationVerdict(null, $starts?->toDateString(), $requested->toDateString(),
                $starts === null ? null : (int) $requested->diffInDays($starts, absolute: false), 0, true, 0.0, 0.0, $baseAmount);
        }

        $required = max(0, (int) $policy->param('days', 0));
        $percent = max(0.0, (float) $policy->param('fee_percent', 0));

        /* وبلا موعدٍ لا يُقاس شيء — فيُعامَل داخل المهلة، والقرارُ لمن يقرأ */
        if ($starts === null) {
            return new CancellationVerdict($policy, null, $requested->toDateString(), null, $required, true, $percent, 0.0, $baseAmount);
        }

        $available = (int) $requested->diffInDays($starts, absolute: false);
        $free = $available >= $required;
        $fee = $free || $percent <= 0 ? 0.0 : round($baseAmount * $percent / 100, 3);

        return new CancellationVerdict($policy, $starts->toDateString(), $requested->toDateString(),
            $available, $required, $free, $percent, $fee, $baseAmount);
    }

    /** سياسةُ الإلغاء العامّةُ النافذة للشركة — أو null فلا سياسة */
    public static function policy(int $businessId): ?CompanyPolicy
    {
        return CompanyPolicies::inForce($businessId)
            ->first(fn (CompanyPolicy $p) => $p->key === self::KEY && $p->scope_type === CompanyPolicy::GENERAL);
    }
}
