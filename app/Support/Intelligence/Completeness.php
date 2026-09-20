<?php

namespace App\Support\Intelligence;

/**
 * اكتمالُ البيانات تحت رقمٍ إداريّ — ثلاثُ حالاتٍ وسببٌ لكلٍّ.
 *
 * رقمٌ واثقٌ على بياناتٍ ناقصة أخطرُ من رقمٍ غائب: هامشٌ يُعرض ٤٠٪ وثلاثةُ
 * سنداتٍ بلا مشروع تجعله ٢٠٪. فكلُّ مقياسٍ مهمّ يحمل هذا معه، والشاشةُ
 * تقوله كما هو.
 */
final class Completeness
{
    public const COMPLETE = 'complete';

    public const PARTIAL = 'partial';

    public const INSUFFICIENT = 'insufficient';

    /** @param  list<string>  $reasons */
    public function __construct(public readonly string $status, public readonly array $reasons = []) {}

    public static function complete(): self
    {
        return new self(self::COMPLETE);
    }

    /** @param  list<string>  $reasons */
    public static function partial(array $reasons): self
    {
        return new self(self::PARTIAL, array_values(array_filter($reasons)));
    }

    /** @param  list<string>  $reasons */
    public static function insufficient(array $reasons): self
    {
        return new self(self::INSUFFICIENT, array_values(array_filter($reasons)));
    }

    /** @return array{status: string, reasons: list<string>} */
    public function toArray(): array
    {
        return ['status' => $this->status, 'reasons' => $this->reasons];
    }
}
