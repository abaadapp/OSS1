<?php

namespace App\Support\Import;

use App\Support\Digits;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * التحويلاتُ الآمنة — قائمةٌ مغلقةٌ بأسمائها، ولا كودَ يُنفَّذ.
 *
 * كلُّ تحويلٍ دالّةٌ معروفة: قصُّ الفراغ، توحيدُه، بريدٌ بحروفٍ صغيرة،
 * هاتفٌ بصيغةٍ دوليّة، تاريخٌ من صيغٍ شائعة ومن رقم Excel التسلسليّ، عددٌ
 * عشريٌّ من نصٍّ فيه فواصلُ وخاناتٌ عربيّة، منطقيٌّ من نعم/لا، وخريطةُ
 * قيمٍ ثابتة. وما ليس هنا لا يُطلب من ملفٍّ ولا يُكتب في خريطة.
 */
final class Transforms
{
    /** @var list<string> */
    public const NAMES = ['trim', 'squish', 'lower', 'upper', 'digits', 'email', 'phone', 'date', 'decimal', 'integer', 'boolean', 'map', 'default'];

    /**
     * تطبيقُ سلسلةِ تحويلاتٍ على قيمةٍ — بترتيبها.
     *
     * @param  list<string>  $names
     * @param  array<string, mixed>  $options  `map` · `default`
     */
    public static function apply(mixed $value, array $names, array $options = []): mixed
    {
        foreach ($names as $name) {
            $value = self::one($name, $value, $options);
        }

        return $value;
    }

    public static function one(string $name, mixed $value, array $options = []): mixed
    {
        if (! in_array($name, self::NAMES, true)) {
            throw new RuntimeException(__('تحويلٌ غير معروف: :name', ['name' => $name]));
        }

        if ($name === 'default') {
            return self::blank($value) ? ($options['default'] ?? null) : $value;
        }

        if (self::blank($value)) {
            return null;
        }

        return match ($name) {
            'trim' => is_string($value) ? trim($value) : $value,
            'squish' => is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : $value,
            'lower' => is_string($value) ? mb_strtolower($value) : $value,
            'upper' => is_string($value) ? mb_strtoupper($value) : $value,
            'digits' => is_string($value) ? Digits::western($value) : $value,
            'email' => self::email($value),
            'phone' => self::phone($value),
            'date' => self::date($value),
            'decimal' => self::decimal($value),
            'integer' => self::integer($value),
            'boolean' => self::boolean($value),
            'map' => self::map($value, $options['map'] ?? []),
        };
    }

    public static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    public static function email(mixed $value): ?string
    {
        $text = mb_strtolower(trim((string) $value));

        return filter_var($text, FILTER_VALIDATE_EMAIL) !== false ? $text : throw new RuntimeException(__('بريدٌ غير صالح: :v', ['v' => self::short($text)]));
    }

    public static function phone(mixed $value): ?string
    {
        $normalized = PhoneNumber::normalize(is_string($value) ? $value : (string) $value);

        return $normalized ?? throw new RuntimeException(__('هاتفٌ غير صالح: :v', ['v' => self::short((string) $value)]));
    }

    /**
     * تاريخٌ من نصٍّ أو رقم Excel — `Y-m-d`.
     *
     * الصيغُ المقبولة: ISO، وd/m/Y، وd-m-Y، وd.m.Y، وY/m/d، ورقمُ Excel
     * التسلسليّ (بين 1990 و2100). وm/d/Y **لا** — الملفّاتُ هنا عربيّةٌ
     * وبريطانيّةُ الترتيب، والغموضُ يُردّ لا يُخمَّن.
     */
    public static function date(mixed $value): ?string
    {
        if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d{4,6}(\.\d+)?$/', trim($value)))) {
            $serial = (float) $value;
            if ($serial >= 32874 && $serial <= 73415) { /* 1990-01-01 … 2100-12-31 */
                return Carbon::create(1899, 12, 30)->addDays((int) floor($serial))->toDateString();
            }
            throw new RuntimeException(__('تاريخٌ غير مفهوم: :v', ['v' => self::short((string) $value)]));
        }

        $text = trim((string) Digits::western((string) $value));
        $text = preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?.*$/', '', $text) ?? $text; /* وقتٌ ملحق يُطرح */

        foreach (['Y-m-d', 'Y/m/d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y', 'd-m-y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat('!'.$format, $text);
            } catch (\Throwable) {
                continue;
            }
            if ($parsed !== null && $parsed->format($format) === $text && $parsed->year >= 1990 && $parsed->year <= 2100) {
                return $parsed->toDateString();
            }
        }

        throw new RuntimeException(__('تاريخٌ غير مفهوم: :v', ['v' => self::short($text)]));
    }

    /** عددٌ عشريٌّ من «1,250.500» و«١٢٥٠٫٥» و«OMR 1,250» */
    public static function decimal(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 3);
        }

        $text = (string) Digits::western((string) $value);
        $text = str_replace(['٫', '٬', ' ', "\u{A0}"], ['.', ',', '', ''], $text);
        $text = preg_replace('/[^\d.,\-()]/u', '', $text) ?? '';
        $negative = str_contains($text, '(') || str_starts_with($text, '-');
        $text = str_replace(['(', ')', '-'], '', $text);

        /*
         * الفاصلةُ والنقطة: الأخيرةُ منهما عشريّةٌ والأخرى ألوف («1.250,50» أوروبيّة).
         * وفاصلةٌ وحدَها تتبعها ثلاثُ خاناتٍ ألوفٌ — وإلّا عشريّة.
         */
        if (str_contains($text, ',') && str_contains($text, '.')) {
            $text = strrpos($text, ',') > strrpos($text, '.')
                ? str_replace('.', '', str_replace(',', '#', $text))
                : str_replace(',', '', $text);
            $text = str_replace('#', '.', $text);
        } elseif (str_contains($text, ',')) {
            $tail = strlen($text) - strrpos($text, ',') - 1;
            $text = $tail === 3 ? str_replace(',', '', $text) : str_replace(',', '.', $text);
        }

        if ($text === '' || ! is_numeric($text)) {
            throw new RuntimeException(__('مبلغٌ غير مفهوم: :v', ['v' => self::short((string) $value)]));
        }

        return round((float) $text * ($negative ? -1 : 1), 3);
    }

    public static function integer(mixed $value): ?int
    {
        $decimal = self::decimal($value);

        if ($decimal === null || abs($decimal - round($decimal)) > 0.0005) {
            throw new RuntimeException(__('عددٌ صحيحٌ متوقَّع: :v', ['v' => self::short((string) $value)]));
        }

        return (int) round($decimal);
    }

    public static function boolean(mixed $value): ?bool
    {
        $text = mb_strtolower(trim((string) Digits::western((string) $value)));

        return match ($text) {
            '1', 'true', 'yes', 'y', 'نعم', 'صح', 'نشط', 'active', 'on' => true,
            '0', 'false', 'no', 'n', 'لا', 'خطأ', 'غير نشط', 'inactive', 'off' => false,
            default => throw new RuntimeException(__('قيمةٌ منطقيّةٌ متوقَّعة: :v', ['v' => self::short($text)])),
        };
    }

    /** خريطةُ قيمٍ ثابتة — «تمّ» → «issued»؛ والمجهولُ يُردّ لا يُمرَّر */
    public static function map(mixed $value, array $map): mixed
    {
        $key = trim((string) $value);

        if (array_key_exists($key, $map)) {
            return $map[$key];
        }

        $lower = mb_strtolower($key);
        foreach ($map as $from => $to) {
            if (mb_strtolower(trim((string) $from)) === $lower) {
                return $to;
            }
        }

        throw new RuntimeException(__('قيمةٌ ليست في الخريطة: :v', ['v' => self::short($key)]));
    }

    /** مقتطفٌ للرسالة — لا القيمةُ كلُّها في سجلّ */
    public static function short(string $value): string
    {
        return mb_strlen($value) > 24 ? mb_substr($value, 0, 24).'…' : $value;
    }
}
