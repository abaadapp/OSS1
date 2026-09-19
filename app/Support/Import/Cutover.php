<?php

namespace App\Support\Import;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * تاريخُ التحويل — يومٌ يبدأ منه الدفترُ في النظام (§15).
 *
 * قبله: تاريخٌ يُلخَّص أرصدةً افتتاحيّة. ومنه: مستنداتٌ كاملة. والحقيقةُ
 * الاقتصاديّةُ الواحدة تظهر مرّةً: فاتورةٌ قبل التحويل لا تُستورد مستندًا
 * إن كان رصيدُ عميلها يدخل افتتاحيًّا — والعكس.
 */
final class Cutover
{
    public const KEY = 'import_cutover_date';

    public static function date(int $businessId): ?Carbon
    {
        $value = Setting::where('business_id', $businessId)->where('key', self::KEY)->value('value');

        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function set(int $businessId, ?string $date): void
    {
        $value = blank($date) ? null : Carbon::parse((string) $date)->toDateString();

        Setting::updateOrCreate(['business_id' => $businessId, 'key' => self::KEY], ['value' => $value]);
    }

    /** أهذا التاريخُ قبل التحويل؟ — وبلا تحويلٍ محدَّد لا شيءَ قبله */
    public static function isBefore(int $businessId, ?string $date): bool
    {
        $cutover = self::date($businessId);

        return $cutover !== null && $date !== null && Carbon::parse($date)->lt($cutover);
    }
}
