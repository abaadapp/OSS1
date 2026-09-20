<?php

namespace App\Support\Operations;

/**
 * نتيجةُ `OperationReadiness` — قيمةٌ تُقرأ، لا صحيحٌ/خطأ.
 *
 * `blockers` ما يمنع، و`warnings` ما يُقال ولا يمنع، و`checks` كلُّ ما فُحص
 * بنتيجته — فتعرض الشاشةُ الأخضرَ أيضًا لا الأحمرَ وحده.
 */
final class OperationReadinessVerdict
{
    /**
     * @param  list<array{key: string, message: string}>  $blockers
     * @param  list<array{key: string, message: string}>  $warnings
     * @param  list<array{key: string, label: string, ok: bool, severity: string, detail: ?string}>  $checks
     */
    public function __construct(
        public readonly string $phase,
        public readonly bool $ready,
        public readonly array $blockers,
        public readonly array $warnings,
        public readonly array $checks,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'phase' => $this->phase,
            'ready' => $this->ready,
            'blockers' => $this->blockers,
            'warnings' => $this->warnings,
            'checks' => $this->checks,
        ];
    }

    /** @return list<string> */
    public function blockerMessages(): array
    {
        return array_column($this->blockers, 'message');
    }
}
