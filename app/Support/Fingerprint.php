<?php

namespace App\Support;

/**
 * بصمةُ حمولةٍ — `sha256` على صيغةٍ واحدةٍ لا تتبدّل.
 *
 * ومن موضعٍ واحدٍ تقرؤه البصمةُ عند الإرسال (`Quotations::contentHash`)
 * وإعادةُ الفحص بعد سنة (`DocumentApproval::verify`): لو اختلفت رايةٌ
 * واحدةٌ في `json_encode` بين الموضعين لقالت البصمةُ «لا يطابق» عن
 * مستندٍ لم يُمسّ — وهو أسوأ من ألّا تُفحص.
 */
class Fingerprint
{
    /** @param  array<string, mixed>  $payload */
    public static function of(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @param  array<string, mixed>  $payload */
    public static function matches(array $payload, ?string $hash): bool
    {
        return $hash !== null && hash_equals($hash, self::of($payload));
    }
}
