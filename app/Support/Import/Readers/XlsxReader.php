<?php

namespace App\Support\Import\Readers;

use App\Support\Import\FileGuard;
use Generator;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use RuntimeException;

/**
 * XLSX — على دفعاتٍ من الصفوف لا الملفَّ كلَّه.
 *
 * ═══ لا معادلةَ تُحسب ═══
 *
 * الخليّةُ تُقرأ بـ`getValue()` لا `getCalculatedValue()`: معادلةٌ في ملفٍّ
 * وصل بالبريد تبقى نصًّا («=SUM(A1)») ولا تُنفَّذ على الخادم أبدًا.
 * والتواريخُ تصل أرقامًا تسلسليّةً لأنّ الأنماط لا تُحمَّل (`readDataOnly`)
 * — وتحويلُ «تاريخ» يعرفها.
 *
 * ═══ وعلى دفعات ═══
 *
 * مرشّحُ قراءةٍ يحدّ الصفوفَ المحمَّلة في كلّ جولة (`CHUNK`)، فملفُّ مئة ألف
 * صفٍّ لا يسكن الذاكرةَ دفعةً واحدة.
 */
final class XlsxReader implements Reader
{
    public const CHUNK = 500;

    /** @var list<string> */
    private array $headers;

    private int $lastRow;

    private int $lastColumn;

    public function __construct(private readonly string $path)
    {
        $reader = $this->reader();
        $reader->setReadFilter(new RowFilter(1, 1));
        $sheet = $reader->load($this->path)->getActiveSheet();

        $info = $reader->listWorksheetInfo($this->path)[0] ?? null;
        $this->lastRow = (int) ($info['totalRows'] ?? $sheet->getHighestDataRow());
        $this->lastColumn = min((int) ($info['totalColumns'] ?? $sheet->getHighestDataColumn(1)), FileGuard::MAX_COLUMNS + 1);

        if ($this->lastColumn > FileGuard::MAX_COLUMNS) {
            throw new RuntimeException(__('الملفّ يتجاوز الحدَّ الأقصى للأعمدة (:max).', ['max' => FileGuard::MAX_COLUMNS]));
        }
        if ($this->lastRow - 1 > FileGuard::MAX_ROWS) {
            throw new RuntimeException(__('الملفّ يتجاوز الحدَّ الأقصى للصفوف (:max).', ['max' => number_format(FileGuard::MAX_ROWS)]));
        }

        $headers = [];
        for ($c = 1; $c <= $this->lastColumn; $c++) {
            $headers[] = trim((string) self::raw($sheet->getCell([$c, 1])));
        }
        /* وأعمدةٌ فارغةُ الرأس في ذيل الصفّ تُطرح */
        while ($headers !== [] && end($headers) === '') {
            array_pop($headers);
        }
        if ($headers === []) {
            throw new RuntimeException(__('الملفّ فارغ أو بلا سطر رأس.'));
        }

        $this->lastColumn = count($headers);
        $this->headers = CsvReader::dedupe($headers);
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
        $reader = $this->reader();
        $number = 0;

        for ($start = 2; $start <= $this->lastRow; $start += self::CHUNK) {
            $end = min($start + self::CHUNK - 1, $this->lastRow);
            $reader->setReadFilter(new RowFilter($start, $end));
            $book = $reader->load($this->path);
            $sheet = $book->getActiveSheet();

            for ($r = $start; $r <= $end; $r++) {
                $cells = [];
                $blank = true;
                for ($c = 1; $c <= $this->lastColumn; $c++) {
                    $value = self::raw($sheet->getCell([$c, $r]));
                    $blank = $blank && ($value === null || $value === '');
                    $cells[] = $value;
                }

                if ($blank) {
                    continue;
                }

                $number++;
                yield $number => $cells;
            }

            $book->disconnectWorksheets();
            unset($book, $sheet);
        }
    }

    /** القيمةُ كما كُتبت — والمعادلةُ نصُّها؛ ولا حسابَ */
    public static function raw(Cell $cell): string|int|float|null
    {
        $value = $cell->getValue();

        if ($value === null) {
            return null;
        }
        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return (string) $value;
    }

    private function reader(): Xlsx
    {
        $reader = new Xlsx;
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);

        return $reader;
    }
}
