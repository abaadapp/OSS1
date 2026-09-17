<?php

namespace App\Support;

use App\Models\CompanyPolicy;
use App\Models\Product;
use App\Models\Quotation;
use App\Support\Policy\Finding;
use App\Support\Policy\Registry;
use Illuminate\Support\Collection;

/**
 * سياساتُ الشركة على عرضٍ بعينه — ما يسري منها، وما خُولف، وما يُختم.
 *
 * ═══ ثلاثةُ أسئلةٍ لا أكثر ═══
 *
 * • `applicable` — أيُّ السياسات تنطبق على هذا العرض؟ (النطاق)
 * • `findings`   — أيُّها خُولفت؟ (الفحص — لما له فحص)
 * • `snapshot`   — ما نصُّها الآن ليُختم في العرض عند إرساله؟
 *
 * ═══ والنصُّ من الختم لا من الجدول بعد الإرسال ═══
 *
 * `texts()` تقرأ `policy_snapshot` لما خرج، والجدولَ الحيَّ لما لم يخرج.
 * فورقةٌ أُرسلت في مارس تُطبع في يونيو بنصّ مارس — ولو غُيّرت السياسةُ
 * بينهما ثلاثًا. انظر §18: «أيَّ نصٍّ قَبِل العميل».
 */
class CompanyPolicies
{
    /** @return Collection<int, CompanyPolicy> */
    public static function inForce(int $businessId): Collection
    {
        return CompanyPolicy::where('business_id', $businessId)->inForce()
            ->orderBy('id')->get();
    }

    /**
     * ما ينطبق منها على هذا العرض — بالنطاق.
     *
     * عامّةٌ دائمًا؛ أو لعميله؛ أو لصنفٍ من بنوده؛ أو لتصنيفِ صنفٍ منها.
     * وسياستان بالمفتاح نفسِه — عامّةٌ وخاصّةٌ بالعميل — تُقرأ **الأخصّ**:
     * عميلٌ بعقدٍ إطاريّ له مهلتُه لا مهلةُ الجميع.
     *
     * @return Collection<int, CompanyPolicy>
     */
    public static function applicable(int $businessId, Quotation $quotation): Collection
    {
        $policies = self::inForce($businessId);

        if ($policies->isEmpty()) {
            return $policies;
        }

        $productIds = $quotation->items->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique();
        $categoryIds = $productIds->isEmpty()
            ? collect()
            : Product::whereIn('id', $productIds)->where('business_id', $businessId)
                ->pluck('category_id')->filter()->map(fn ($id) => (int) $id)->unique();

        $matching = $policies->filter(fn (CompanyPolicy $p) => match ($p->scope_type) {
            null, '' => true,
            CompanyPolicy::SCOPE_CUSTOMER => (int) $p->scope_id === (int) $quotation->customer_id,
            CompanyPolicy::SCOPE_PRODUCT => $productIds->contains((int) $p->scope_id),
            CompanyPolicy::SCOPE_CATEGORY => $categoryIds->contains((int) $p->scope_id),
            default => false,
        });

        /*
         * والأخصُّ يحجب الأعمّ في المفتاح الواحد: العميلُ أخصُّ من الصنف،
         * والصنفُ من التصنيف، والتصنيفُ من العامّ. ولا يحجب مفتاحٌ مفتاحًا
         * آخر: مهلةُ الطلب والإلغاءُ يسريان معًا.
         */
        $rank = [CompanyPolicy::SCOPE_CUSTOMER => 3, CompanyPolicy::SCOPE_PRODUCT => 2, CompanyPolicy::SCOPE_CATEGORY => 1];

        return $matching
            ->sortByDesc(fn (CompanyPolicy $p) => ($rank[$p->scope_type] ?? 0) * 1000000 + $p->id)
            ->unique('key')
            ->sortBy('id')
            ->values();
    }

    /**
     * ما خُولف — فحصٌ لكلّ سياسةٍ لها فحص.
     *
     * @return list<Finding>
     */
    public static function findings(int $businessId, Quotation $quotation): array
    {
        $findings = [];

        foreach (self::applicable($businessId, $quotation) as $policy) {
            $check = Registry::check((string) $policy->key);
            $finding = $check?->evaluate($policy, $quotation);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * الختمُ — ما يُكتب في `policy_snapshot` لحظةَ الإرسال.
     *
     * والنصّان معًا لا نصُّ لغة القارئ: العميلُ يفتح رابطَه بلغته هو،
     * والختمُ يجب أن يحمل ما سيقرؤه أيًّا كانت.
     *
     * @return list<array<string, mixed>>
     */
    public static function snapshot(int $businessId, Quotation $quotation): array
    {
        return self::applicable($businessId, $quotation)->map(fn (CompanyPolicy $p) => [
            'key' => $p->key,
            'policy_id' => $p->id,
            'version' => (int) $p->version,
            'params' => $p->params ?? [],
            'text_ar' => (string) $p->text_ar,
            'text_en' => $p->text_en,
        ])->all();
    }

    /**
     * النصوصُ كما تُرسم على الورقة — من الختم لما خرج، ومن الجدول لما لم يخرج.
     *
     * @return list<array{cap: string, text: string}>
     */
    public static function texts(Quotation $quotation, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        $rows = $quotation->isFrozen()
            ? ($quotation->policy_snapshot ?? [])
            : self::snapshot((int) $quotation->business_id, $quotation);

        return collect($rows)->map(function (array $row) use ($locale) {
            $text = $locale === 'en' && filled($row['text_en'] ?? null) ? $row['text_en'] : ($row['text_ar'] ?? '');

            /* والعنوانُ مفتاحًا عربيًّا لا مترجَمًا: الورقةُ تكتبه بلغتين عبر `Paper::pair` */
            return ['cap' => Registry::KEYS[$row['key'] ?? '']['label'] ?? (string) ($row['key'] ?? ''), 'text' => (string) $text];
        })->filter(fn ($r) => $r['text'] !== '')->values()->all();
    }
}
