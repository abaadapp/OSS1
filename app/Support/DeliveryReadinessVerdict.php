<?php

namespace App\Support;

/** نتيجةُ `DeliveryReadiness` — قيمةٌ تُقرأ */
final class DeliveryReadinessVerdict
{
    /** @param  list<string>  $blockers */
    public function __construct(
        public readonly bool $ready,
        public readonly array $blockers,
        public readonly int $acceptedCount,
        public readonly int $awaitingCount,
        public readonly int $unresolvedDeclineCount,
        public readonly ?string $lastAcceptedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ready' => $this->ready,
            'blockers' => $this->blockers,
            'accepted_count' => $this->acceptedCount,
            'awaiting_count' => $this->awaitingCount,
            'unresolved_decline_count' => $this->unresolvedDeclineCount,
            'last_accepted_at' => $this->lastAcceptedAt,
        ];
    }
}
