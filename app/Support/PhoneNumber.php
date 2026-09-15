<?php

namespace App\Support;

/**
 * تطبيع الرقم — موضعٌ واحد يعرف كيف يُقرأ رقمُ هاتفٍ كُتب بأيّ شكل.
 *
 * الناس يكتبون أرقامهم كما اعتادوا: «91234567»، «+968 9123 4567»،
 * «00968-91234567»، «٩١٢٣٤٥٦٧» بالأرقام العربية. وهي كلُّها رقمٌ واحد —
 * فالمطابقةُ عليها كما كُتبت تجعل العميلَ الواحد عميلين.
 *
 * ولا يُكتب الناتج في صفّ العميل: التطبيع للمطابقة لا للبيانات. من كتب رقمه
 * بشكلٍ يفهمه هو يجده كما كتبه — وتصحيحُ بيانات الناس بلا طلبهم يُفسد أكثر
 * ممّا يُصلح.
 */
class PhoneNumber
{
    /**
     * الرقم بصيغته الدوليّة، أو لا شيء إن لم يكن رقمًا يصلح.
     *
     * @param  string|null  $default  مفتاح الدولة للأرقام المحلّية — عُمان افتراضًا
     */
    public static function normalize(?string $raw, ?string $default = null): ?string
    {
        if (blank($raw)) {
            return null;
        }

        $default = $default ?: (string) config('app.country_code', '968');
        // وجدولُ الخانات في `Digits` — يقرؤه الورقُ والهاتفُ معًا، ونسختان منه تفترقان
        $digits = preg_replace('/\D+/', '', (string) Digits::western($raw)) ?? '';

        if ($digits === '') {
            return null;
        }

        // 00 بادئةُ الاتصال الدولي في كثيرٍ من الدول — تُستبدل ولا تُرسَل
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        /*
         * الرقم المحلّي يُلبَس مفتاح دولته.
         *
         * رقم عُمان ثمانية، والصفر البادئ عادةُ الشبكات الأرضية في دولٍ أخرى
         * فيُسقَط قبل إضافة المفتاح — و«0» ليست جزءًا من رقمٍ دوليّ أبدًا.
         */
        if (strlen($digits) <= 9) {
            $digits = $default.ltrim($digits, '0');
        }

        // أقصر رقمٍ دوليٍّ صالح ثمانية، وأطولُه خمسة عشر (E.164)
        return (strlen($digits) >= 8 && strlen($digits) <= 15) ? $digits : null;
    }

    /** هل يصلح رقمًا؟ */
    public static function isValid(?string $raw): bool
    {
        return self::normalize($raw) !== null;
    }
}
