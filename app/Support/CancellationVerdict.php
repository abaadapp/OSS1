<?php

namespace App\Support;

use App\Models\CompanyPolicy;

/**
 * نتيجةُ تقييم مهلة الإلغاء — قيمةٌ تُقرأ ولا تُغيَّر. انظر `CancellationWindow`.
 */
final class CancellationVerdict
{
    public function __construct(
        public readonly ?CompanyPolicy $policy,
        public readonly ?string $eventStartsAt,
        public readonly string $requestedAt,
        /** كم يومًا بين الطلب والموعد — سالبٌ إن مضى الموعد، وnull بلا موعد */
        public readonly ?int $availableDays,
        public readonly int $requiredDays,
        public readonly bool $free,
        public readonly float $feePercent,
        public readonly float $feeAmount,
        public readonly float $baseAmount,
    ) {}

    /** أيلزم رسمٌ؟ — خارج المهلة وبنسبةٍ موجبة */
    public function requiresFee(): bool
    {
        return ! $this->free && $this->feeAmount > 0;
    }

    public function message(): string
    {
        if ($this->policy === null) {
            return __('لا سياسة إلغاء مكتوبة — لا يُحتسب رسم.');
        }
        if ($this->eventStartsAt === null) {
            return __('لا موعد للفعالية — لا تُقاس المهلة.');
        }
        if ($this->free) {
            return __('داخل مهلة الإلغاء المجّاني (:required يومًا قبل الموعد، والمتاح :available).', [
                'required' => $this->requiredDays, 'available' => max(0, (int) $this->availableDays),
            ]);
        }

        return __('خارج مهلة الإلغاء المجّاني (:required يومًا قبل الموعد، والمتاح :available) — يُحتسب :percent٪ من القيمة.', [
            'required' => $this->requiredDays, 'available' => max(0, (int) $this->availableDays), 'percent' => $this->feePercent,
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'policy_id' => $this->policy?->id,
            'policy_version' => $this->policy === null ? null : (int) $this->policy->version,
            'event_starts_at' => $this->eventStartsAt,
            'requested_at' => $this->requestedAt,
            'available_days' => $this->availableDays,
            'required_days' => $this->requiredDays,
            'free' => $this->free,
            'fee_percent' => $this->feePercent,
            'fee_amount' => $this->feeAmount,
            'base_amount' => $this->baseAmount,
            'message' => $this->message(),
        ];
    }
}
