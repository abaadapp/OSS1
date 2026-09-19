<?php

namespace App\Support\Import;

use RuntimeException;

/**
 * الخريطةُ — من عمودٍ في الملفّ إلى حقلٍ من القائمة المسموحة.
 *
 * ═══ شكلُ الخريطة ═══
 *
 *     [
 *       'columns'   => [['source' => 'اسم العميل', 'target' => 'name', 'transforms' => ['squish'], 'map' => [...], 'default' => ...], …],
 *       'constants' => ['status' => 'issued'],   // حقلٌ بقيمةٍ ثابتة لكلّ الصفوف — لما ليس في الملفّ
 *     ]
 *
 * ولا تُقبل خريطةٌ تشير إلى حقلٍ خارج القائمة، أو إلى عمودٍ ليس في الملفّ،
 * أو إلى تحويلٍ لا يُعرف. والحقلُ بحسب نوعه يُحوَّل تلقائيًّا (تاريخٌ ·
 * مبلغٌ · بريدٌ · هاتف) بعد تحويلات المستخدم — فلا يصل الوصفةَ إلّا ما هو
 * بصيغته الصحيحة أو خطأٌ باسم صفّه.
 */
final class Mapper
{
    /**
     * فحصُ خريطةٍ قبل حفظها — وإرجاعُها منقّاة.
     *
     * @param  list<string>  $headers
     * @param  array<string, mixed>  $mapping
     * @return array{columns: list<array<string, mixed>>, constants: array<string, mixed>}
     */
    public static function validate(string $category, array $headers, array $mapping): array
    {
        $fields = Fields::for($category);

        if ($fields === []) {
            throw new RuntimeException(__('صنفٌ غير معروف: :c', ['c' => $category]));
        }

        $columns = [];
        $targets = [];

        foreach ($mapping['columns'] ?? [] as $entry) {
            $source = trim((string) ($entry['source'] ?? ''));
            $target = trim((string) ($entry['target'] ?? ''));

            if ($source === '' || $target === '') {
                continue;
            }
            if (! in_array($source, $headers, true)) {
                throw new RuntimeException(__('العمود «:c» ليس في الملفّ.', ['c' => $source]));
            }
            if (! isset($fields[$target])) {
                throw new RuntimeException(__('الحقل «:f» ليس من الحقول المسموحة لهذا الصنف.', ['f' => $target]));
            }
            if (isset($targets[$target])) {
                throw new RuntimeException(__('الحقل «:f» مربوطٌ بعمودين.', ['f' => $target]));
            }

            $transforms = array_values(array_filter(array_map('strval', (array) ($entry['transforms'] ?? []))));
            foreach ($transforms as $t) {
                if (! in_array($t, Transforms::NAMES, true)) {
                    throw new RuntimeException(__('تحويلٌ غير معروف: :name', ['name' => $t]));
                }
            }

            $map = [];
            foreach ((array) ($entry['map'] ?? []) as $from => $to) {
                if (is_scalar($to) || $to === null) {
                    $map[(string) $from] = $to;
                }
            }

            $columns[] = [
                'source' => $source,
                'target' => $target,
                'transforms' => $transforms,
                'map' => $map,
                'default' => isset($entry['default']) && is_scalar($entry['default']) ? $entry['default'] : null,
            ];
            $targets[$target] = true;
        }

        $constants = [];
        foreach ((array) ($mapping['constants'] ?? []) as $target => $value) {
            $target = (string) $target;
            if (! isset($fields[$target])) {
                throw new RuntimeException(__('الحقل «:f» ليس من الحقول المسموحة لهذا الصنف.', ['f' => $target]));
            }
            if (isset($targets[$target])) {
                throw new RuntimeException(__('الحقل «:f» مربوطٌ بعمودٍ وقيمةٍ ثابتة معًا.', ['f' => $target]));
            }
            if (! is_scalar($value) && $value !== null) {
                continue;
            }
            $constants[$target] = $value;
            $targets[$target] = true;
        }

        return ['columns' => $columns, 'constants' => $constants];
    }

    /**
     * الحقولُ الإلزاميّةُ التي لم تُربط بعدُ
     *
     * @return list<string>
     */
    public static function missing(string $category, array $mapping): array
    {
        $mapped = array_merge(
            array_column($mapping['columns'] ?? [], 'target'),
            array_keys($mapping['constants'] ?? []),
        );

        return array_values(array_diff(Fields::required($category), $mapped));
    }

    /**
     * صفٌّ من الملفّ إلى سجلٍّ مُحوَّل — والأخطاءُ باسم الحقل.
     *
     * @param  list<string>  $headers
     * @param  list<mixed>  $cells
     * @return array{record: array<string, mixed>, errors: list<string>, warnings: list<string>}
     */
    public static function row(string $category, array $mapping, array $headers, array $cells): array
    {
        $fields = Fields::for($category);
        $index = array_flip($headers);
        $record = [];
        $errors = [];
        $warnings = [];

        foreach ($mapping['columns'] ?? [] as $entry) {
            $target = $entry['target'];
            $position = $index[$entry['source']] ?? null;
            $raw = $position === null ? null : ($cells[$position] ?? null);

            if (FileGuard::looksLikeFormula($raw)) {
                $warnings[] = __('«:f» يبدو معادلةً — حُفظ نصًّا ولم يُحسب', ['f' => $fields[$target]['label']]);
            }

            try {
                $value = Transforms::apply($raw, $entry['transforms'] ?? [], ['map' => $entry['map'] ?? [], 'default' => $entry['default'] ?? null]);
                $value = self::coerce($fields[$target], $value);
            } catch (RuntimeException $e) {
                $errors[] = $fields[$target]['label'].': '.$e->getMessage();
                $value = null;
            }

            $record[$target] = $value;
        }

        foreach ($mapping['constants'] ?? [] as $target => $value) {
            try {
                $record[$target] = self::coerce($fields[$target], $value);
            } catch (RuntimeException $e) {
                $errors[] = $fields[$target]['label'].': '.$e->getMessage();
            }
        }

        foreach ($fields as $name => $field) {
            if ($field['required'] && Transforms::blank($record[$name] ?? null)) {
                $errors[] = __('«:f» إلزاميّ وهو فارغ', ['f' => $field['label']]);
            }
        }

        return ['record' => $record, 'errors' => $errors, 'warnings' => $warnings];
    }

    /** الحقلُ بنوعه: ما ليس بصيغته يُردّ باسمه */
    private static function coerce(array $field, mixed $value): mixed
    {
        if (Transforms::blank($value)) {
            return null;
        }

        return match ($field['type']) {
            'string', 'text', 'ref' => self::text($value, $field['max']),
            'email' => Transforms::email($value),
            'phone' => Transforms::phone($value),
            'date' => Transforms::date($value),
            'decimal' => Transforms::decimal($value),
            'integer' => Transforms::integer($value),
            'boolean' => is_bool($value) ? $value : Transforms::boolean($value),
            'enum' => self::enum($value, $field['values'] ?? []),
            default => $value,
        };
    }

    private static function text(mixed $value, ?int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', is_float($value) ? rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') : (string) $value));

        if ($max !== null && mb_strlen($text) > $max) {
            throw new RuntimeException(__('أطول من الحدّ (:max حرفًا)', ['max' => $max]));
        }

        return $text;
    }

    private static function enum(mixed $value, array $values): string
    {
        $text = trim((string) $value);

        foreach ($values as $allowed) {
            if (mb_strtolower($text) === mb_strtolower((string) $allowed)) {
                return (string) $allowed;
            }
        }

        throw new RuntimeException(__('قيمةٌ ليست من المسموح (:allowed): :v', ['allowed' => implode(' · ', $values), 'v' => Transforms::short($text)]));
    }
}
