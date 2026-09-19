<?php

namespace App\Support\Import;

use App\Support\Import\Readers\Reader;
use App\Support\PhoneNumber;

/**
 * قراءةُ الملفّ قبل أن يُربط — ماذا فيه؟ لا ماذا يعني.
 *
 * لكلّ عمود: كم صفًّا فارغًا، وكم قيمةً مميّزة، وما الذي **يبدو** عليه
 * (بريدٌ · هاتفٌ · تاريخٌ · مبلغٌ · معرِّف). والظنُّ إشارةٌ للربط لا
 * قرارٌ فيه: عمودُ «الرقم» قد يكون هاتفًا وقد يكون رقمَ فاتورة — والشاشةُ
 * تعرض الظنَّ ويقرّر الإنسان.
 *
 * ويُقرأ عددٌ محدود من الصفوف للعيّنة والظنّ، ويُعَدّ الباقي عدًّا.
 */
final class Profiler
{
    public const SAMPLE_ROWS = 20;

    public const PROBE_ROWS = 1000;

    /**
     * @return array{row_count: int, columns: list<array<string, mixed>>, sample: list<list<mixed>>, duplicates: list<array<string, mixed>>, formulas: int}
     */
    public static function profile(Reader $reader): array
    {
        $headers = $reader->headers();
        $width = count($headers);
        $stats = array_fill(0, $width, ['empty' => 0, 'email' => 0, 'phone' => 0, 'date' => 0, 'number' => 0, 'amount' => 0, 'formula' => 0, 'values' => []]);
        $sample = [];
        $count = 0;
        $probed = 0;
        $formulas = 0;

        foreach ($reader->rows() as $number => $cells) {
            $count++;

            if ($count <= self::SAMPLE_ROWS) {
                $sample[] = array_map(fn ($v) => self::display($v), $cells);
            }

            if ($probed >= self::PROBE_ROWS) {
                continue;
            }
            $probed++;

            for ($i = 0; $i < $width; $i++) {
                $value = $cells[$i] ?? null;

                if (Transforms::blank($value)) {
                    $stats[$i]['empty']++;

                    continue;
                }

                if (FileGuard::looksLikeFormula($value)) {
                    $stats[$i]['formula']++;
                    $formulas++;
                }

                $text = trim((string) $value);
                if (count($stats[$i]['values']) < 2000) {
                    $stats[$i]['values'][$text] = ($stats[$i]['values'][$text] ?? 0) + 1;
                }

                if (filter_var(mb_strtolower($text), FILTER_VALIDATE_EMAIL)) {
                    $stats[$i]['email']++;
                }
                if (preg_match('/^[+\d\s()\-٠-٩]{7,20}$/u', $text) && PhoneNumber::isValid($text)) {
                    $stats[$i]['phone']++;
                }
                if (self::isDate($value)) {
                    $stats[$i]['date']++;
                }
                if (self::isNumber($value)) {
                    $stats[$i]['number']++;
                    if (preg_match('/[.,٫]\d{1,3}$/u', $text) || is_float($value)) {
                        $stats[$i]['amount']++;
                    }
                }
            }
        }

        $columns = [];
        $duplicates = [];

        foreach ($headers as $i => $name) {
            $s = $stats[$i];
            $filled = max(0, $probed - $s['empty']);
            $distinct = count($s['values']);
            $repeated = array_filter($s['values'], fn ($n) => $n > 1);

            $kind = match (true) {
                $filled === 0 => 'empty',
                $s['email'] >= $filled * 0.7 => 'email',
                $s['phone'] >= $filled * 0.7 => 'phone',
                $s['date'] >= $filled * 0.7 => 'date',
                $s['amount'] >= $filled * 0.6 => 'amount',
                $s['number'] >= $filled * 0.9 => 'number',
                $distinct === $filled && $filled > 3 => 'identifier',
                default => 'text',
            };

            $columns[] = [
                'name' => $name,
                'kind' => $kind,
                'filled' => $filled,
                'empty_percent' => $probed === 0 ? 0 : (int) round($s['empty'] / $probed * 100),
                'distinct' => $distinct,
                'formulas' => $s['formula'],
            ];

            if (in_array($kind, ['email', 'phone', 'identifier'], true) && $repeated !== []) {
                $duplicates[] = ['column' => $name, 'repeated_values' => count($repeated), 'kind' => $kind];
            }
        }

        return [
            'row_count' => $count,
            'probed' => $probed,
            'columns' => $columns,
            'sample' => $sample,
            'duplicates' => $duplicates,
            'formulas' => $formulas,
        ];
    }

    /** قيمةٌ كما تُعرض في العيّنة — نصٌّ محدود، ورقمُ Excel كما هو */
    public static function display(mixed $value): string|int|float|null
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $text = (string) $value;

        return mb_strlen($text) > 120 ? mb_substr($text, 0, 120).'…' : $text;
    }

    private static function isDate(mixed $value): bool
    {
        try {
            return Transforms::date($value) !== null && ! (is_string($value) && preg_match('/^\d{4,6}$/', trim($value)));
        } catch (\Throwable) {
            return false;
        }
    }

    private static function isNumber(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return true;
        }

        try {
            Transforms::decimal($value);

            return (bool) preg_match('/^[\s()\-]*[\d٠-٩][\d٠-٩.,٫٬\s]*[\s)]*$/u', trim((string) $value));
        } catch (\Throwable) {
            return false;
        }
    }
}
