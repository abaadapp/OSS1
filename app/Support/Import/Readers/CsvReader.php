<?php

namespace App\Support\Import\Readers;

use App\Support\Import\FileGuard;
use Generator;
use RuntimeException;
use SplFileObject;

/**
 * CSV — سطرًا سطرًا من القرص.
 *
 * الفاصلُ يُستنتج من سطر الرأس (فاصلة · فاصلةٌ منقوطة · جدولة)، والترميزُ
 * من أوّل الملفّ (UTF-8 أو Windows-1256 كما في `Sheet::encoding`)، وعلامةُ
 * BOM تُطرح. ولا يُقرأ السطرُ إلّا حين يُطلب.
 */
final class CsvReader implements Reader
{
    /** @var list<string> */
    private array $headers;

    private string $delimiter;

    private string $encoding;

    public function __construct(private readonly string $path)
    {
        $this->encoding = self::encoding($path);
        $this->delimiter = self::delimiter($this->firstLine());
        $this->headers = $this->readHeaders();
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function columnCount(): int
    {
        return count($this->headers);
    }

    public function rows(): Generator
    {
        $file = $this->open();
        $number = 0;
        $first = true;

        foreach ($file as $line) {
            if ($first) {
                $first = false;

                continue;
            }

            if ($line === [null] || $line === false || $line === null) {
                continue;
            }

            $number++;

            if ($number > FileGuard::MAX_ROWS) {
                throw new RuntimeException(__('الملفّ يتجاوز الحدَّ الأقصى للصفوف (:max).', ['max' => number_format(FileGuard::MAX_ROWS)]));
            }

            yield $number => $this->cells($line);
        }
    }

    /** @return list<string|null> */
    private function cells(array $line): array
    {
        $width = count($this->headers);
        $out = [];

        for ($i = 0; $i < $width; $i++) {
            $value = $line[$i] ?? null;
            $out[] = $value === null ? null : $this->text((string) $value);
        }

        return $out;
    }

    /** @return list<string> */
    private function readHeaders(): array
    {
        $file = $this->open();
        $line = $file->current();

        if (! is_array($line) || $line === [null]) {
            throw new RuntimeException(__('الملفّ فارغ أو بلا سطر رأس.'));
        }

        $headers = array_map(fn ($h) => trim($this->text((string) ($h ?? ''))), $line);
        $headers = self::dedupe($headers);

        if (count($headers) > FileGuard::MAX_COLUMNS) {
            throw new RuntimeException(__('الملفّ يتجاوز الحدَّ الأقصى للأعمدة (:max).', ['max' => FileGuard::MAX_COLUMNS]));
        }

        return $headers;
    }

    /** أعمدةٌ بلا اسمٍ أو باسمٍ مكرَّر تُسمّى بموضعها — الربطُ يحتاج مفاتيحَ فريدة */
    public static function dedupe(array $headers): array
    {
        $seen = [];
        $out = [];

        foreach (array_values($headers) as $i => $h) {
            $name = $h === '' ? 'column_'.($i + 1) : $h;
            $base = $name;
            $n = 2;
            while (isset($seen[$name])) {
                $name = $base.' ('.$n++.')';
            }
            $seen[$name] = true;
            $out[] = $name;
        }

        return $out;
    }

    private function open(): SplFileObject
    {
        $file = new SplFileObject($this->path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl($this->delimiter, '"', '\\');
        $file->rewind();

        return $file;
    }

    private function firstLine(): string
    {
        $handle = fopen($this->path, 'r');
        $line = $handle === false ? '' : (string) fgets($handle, 65536);
        if ($handle !== false) {
            fclose($handle);
        }

        return $line;
    }

    private function text(string $value): string
    {
        $value = str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;

        if ($this->encoding !== 'UTF-8') {
            $converted = @mb_convert_encoding($value, 'UTF-8', $this->encoding);
            $value = $converted === false ? $value : $converted;
        }

        return $value;
    }

    /** الترميزُ من أوّل 64 كيلوبايت — UTF-8 وإلّا Windows-1256 */
    public static function encoding(string $path): string
    {
        $handle = fopen($path, 'r');
        $head = $handle === false ? '' : (string) fread($handle, 65536);
        if ($handle !== false) {
            fclose($handle);
        }

        return $head === '' || mb_check_encoding($head, 'UTF-8') ? 'UTF-8' : 'Windows-1256';
    }

    public static function delimiter(string $line): string
    {
        $best = ',';
        $max = -1;

        foreach ([',', ';', "\t", '|'] as $candidate) {
            $count = substr_count($line, $candidate);
            if ($count > $max) {
                $max = $count;
                $best = $candidate;
            }
        }

        return $best;
    }
}
