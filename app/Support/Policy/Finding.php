<?php

namespace App\Support\Policy;

use App\Models\CompanyPolicy;

/**
 * ما رصده فحصٌ — وصفٌ لا فعل.
 *
 * `detected` أرقامٌ لا جملة («المطلوب 7، المتاح 3»): تُخزَّن في الاستثناء
 * وتُقرأ بعد سنةٍ بأيّ لغة. و`message` جملتُها للشاشة الآن.
 */
final class Finding
{
    /**
     * @param  array<string, mixed>  $detected
     */
    public function __construct(
        public readonly CompanyPolicy $policy,
        public readonly string $message,
        public readonly array $detected,
        public readonly ?float $suggestedSurcharge = null,
        public readonly bool $requiresApproval = true,
    ) {}

    public function key(): string
    {
        return (string) $this->policy->key;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'label' => Registry::label($this->key()),
            'policy_id' => $this->policy->id,
            'policy_version' => (int) $this->policy->version,
            'message' => $this->message,
            'detected' => $this->detected,
            'suggested_surcharge' => $this->suggestedSurcharge,
            'requires_approval' => $this->requiresApproval,
        ];
    }
}
