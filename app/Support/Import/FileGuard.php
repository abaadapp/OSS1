<?php

namespace App\Support\Import;

use App\Support\Import\Readers\CsvReader;
use App\Support\Import\Readers\Reader;
use App\Support\Import\Readers\XlsxReader;
use RuntimeException;
use ZipArchive;

/**
 * حارسُ الملفّ — ما يُقبل وما يُردّ قبل أن يُقرأ حرفٌ منه.
 *
 * الملفُّ الوارد لا يُوثَق به: امتدادٌ من قائمةٍ مغلقة (CSV · XLSX)، ونوعٌ
 * حقيقيٌّ من محتواه لا من اسمه، وحجمٌ محدود، وأرشيفُ XLSX يُفحص قبل فكّه
 * (قنبلةُ ضغطٍ صغيرةٌ على القرص هائلةٌ في الذاكرة). ولا يُنفَّذ منه شيء:
 * لا SQL ولا PHP ولا معادلات.
 */
final class FileGuard
{
    public const MAX_BYTES = 25 * 1024 * 1024;

    public const MAX_ROWS = 100_000;

    public const MAX_COLUMNS = 100;

    /** أقصى حجمٍ مفكوكٍ لأرشيف XLSX — ونسبةُ ضغطٍ فوقها يُردّ الملفّ */
    public const MAX_UNPACKED = 200 * 1024 * 1024;

    public const MAX_RATIO = 200;

    /** @var array<string, list<string>> الامتدادُ ⇽ أنواعُ MIME التي يقبلها */
    public const TYPES = [
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    ];

    /** الامتدادُ المقبول من اسم الملفّ — أو استثناء */
    public static function extension(string $filename): string
    {
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        if (! isset(self::TYPES[$ext])) {
            throw new RuntimeException(__('الصيغ المقبولة: CSV وXLSX فقط.'));
        }

        return $ext;
    }

    /** فحصُ ملفٍّ على القرص قبل تسجيله: الحجمُ والنوعُ والأرشيف */
    public static function inspect(string $path, string $extension): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(__('تعذّر قراءة الملفّ.'));
        }

        $size = (int) filesize($path);

        if ($size <= 0) {
            throw new RuntimeException(__('الملفّ فارغ.'));
        }
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException(__('حجم الملفّ يتجاوز :mb ميغابايت.', ['mb' => (int) (self::MAX_BYTES / 1048576)]));
        }

        $mime = (string) (mime_content_type($path) ?: '');

        if ($mime !== '' && ! in_array($mime, self::TYPES[$extension], true)) {
            throw new RuntimeException(__('محتوى الملفّ لا يطابق امتداده (:mime).', ['mime' => $mime]));
        }

        if ($extension === 'xlsx') {
            self::inspectArchive($path, $size);
        } else {
            self::inspectText($path);
        }
    }

    /** القارئُ المناسب — بعد الفحص */
    public static function reader(string $path, string $extension): Reader
    {
        return $extension === 'xlsx' ? new XlsxReader($path) : new CsvReader($path);
    }

    /**
     * قيمةٌ تبدو معادلةً — تُقال ولا تُنفَّذ.
     *
     * ما يبدأ بـ`=` أو `@`، أو بـ`+`/`-` يتبعها حرفٌ أو قوس: يقرؤه Excel
     * معادلةً لو صُدِّر يومًا. يُحفظ نصًّا كما هو ويُنبَّه عليه.
     */
    public static function looksLikeFormula(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        $first = $value[0];

        if ($first === '=' || $first === '@') {
            return true;
        }

        return ($first === '+' || $first === '-') && isset($value[1]) && (ctype_alpha($value[1]) || $value[1] === '(');
    }

    private static function inspectArchive(string $path, int $size): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(__('ملفّ XLSX تالف — لا يُفتح أرشيفُه.'));
        }

        $unpacked = 0;
        $hasWorkbook = false;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $name = (string) $stat['name'];
            /* ولا مسارَ يخرج من الأرشيف */
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                $zip->close();
                throw new RuntimeException(__('ملفّ XLSX يحمل مسارًا غير مقبول.'));
            }
            if ($name === 'xl/workbook.xml') {
                $hasWorkbook = true;
            }
            if (str_starts_with($name, 'xl/vbaProject') || str_ends_with($name, '.bin') && str_contains($name, 'vba')) {
                $zip->close();
                throw new RuntimeException(__('ملفّات الماكرو غير مقبولة.'));
            }
            $unpacked += (int) $stat['size'];
        }

        $zip->close();

        if (! $hasWorkbook) {
            throw new RuntimeException(__('ملفّ XLSX تالف — لا دفترَ عملٍ فيه.'));
        }
        if ($unpacked > self::MAX_UNPACKED || ($size > 0 && $unpacked / $size > self::MAX_RATIO)) {
            throw new RuntimeException(__('ملفّ XLSX مضغوطٌ بنسبةٍ غير معقولة — رُفض.'));
        }
    }

    private static function inspectText(string $path): void
    {
        $handle = fopen($path, 'r');
        $head = $handle === false ? '' : (string) fread($handle, 8192);
        if ($handle !== false) {
            fclose($handle);
        }

        /* محتوًى ثنائيّ في ملفٍّ يدّعي أنّه نصّ */
        if (str_contains($head, "\0") || str_starts_with($head, 'PK') || str_starts_with($head, '%PDF') || str_starts_with($head, "\xD0\xCF\x11\xE0")) {
            throw new RuntimeException(__('محتوى الملفّ ليس نصًّا — CSV يُقبل نصًّا فقط.'));
        }
    }
}
