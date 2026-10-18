<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;

/**
 * كاتبُ الجداول للصيغ الثلاث — إكسل وCSV وPDF — من وصفٍ واحد.
 *
 * التقريرُ يُعطي أعمدتَه (`key` · `label` · `kind`) وصفوفَه وبطاقاتِه ومجاميعَه،
 * وهنا يُكتب كلُّ ذلك بترويسةٍ واحدة: اسمُ الشركة، والعنوان، والفترة، والمرشّحاتُ
 * المطبَّقة، ووقتُ التصدير. فلا يخرج ملفٌّ لا يقول أيَّ فترةٍ ولا أيَّ فرعٍ يحمل.
 *
 * ═══ والنصُّ نصٌّ في كلّ صيغة ═══
 *
 * إكسل يقرأ ما يبدأ بـ«=» معادلةً، وPhpSpreadsheet يكتبها معادلةً إن مُرّرت
 * كما هي: اسمُ عميلٍ كتبه أحدٌ «=HYPERLINK(…)» يصير رابطًا يُضغط. فخاناتُ
 * النصّ تُكتب نصًّا صريحًا في إكسل، وتُحرس بـ`Sheet::guard` في CSV.
 *
 * ═══ والمبلغُ رقمٌ يُجمع ═══
 *
 * يُكتب خامًا بمنازل العملة (`#,##0.000`) لا نصًّا منسَّقًا: أوّلُ ما يفعله
 * المحاسب أن يجمع عمودًا. وسطرُ «الإجمالي» من مجاميع التقرير نفسِه — بتعريف
 * شاشته (الصادرُ لا الملغى) — لا جمعٌ أعمى لكلّ صفّ.
 */
final class TableExport
{
    /*
     * ═══ سقوفُ الصيغ — بما تحتمله الذاكرةُ لا بما يُتمنّى ═══
     *
     * قيستْ على ألفِ صفٍّ من سجلّ النشاط: CSV يُكتب سطرًا سطرًا (~٣٤ م.ب للطلب
     * كلّه)، وإكسل يبني خلاياه في الذاكرة (~٢٥ م.ب لكلّ ألف صفّ)، وPDF يرسم
     * جدولَه كلَّه قبل أن يُخرجه (~١١٥ م.ب لكلّ ألف). فلكلّ صيغةٍ سقفُها، ويقول
     * الملفُّ إن بُلغ — ومن أراد الكلَّ صدّر CSV.
     */

    /** أقصى ما يحمله ملفُّ CSV */
    public const CSV_LIMIT = 20000;

    /** أقصى ما يحمله ملفُّ إكسل */
    public const SHEET_LIMIT = 10000;

    /** وأقصى ما تحمله ورقةُ PDF — أطولُ منها يُقرأ في إكسل لا على الورق */
    public const PDF_LIMIT = 1000;

    /** ذاكرةُ طلب التصدير لإكسل وPDF — يُرفع إليها الحدُّ إن كان أدنى، ولا يُخفض */
    public const MEMORY = '512M';

    public static function limit(string $format): int
    {
        return match ($format) {
            'pdf' => self::PDF_LIMIT,
            'csv' => self::CSV_LIMIT,
            default => self::SHEET_LIMIT,
        };
    }

    /**
     * يُهيّئ الطلبَ لملفٍّ كبير: ذاكرةٌ تتّسع لسقف الصيغة، ووقتٌ لا ينقطع في منتصفها.
     *
     * كان ملفُّ PDF لألف صفٍّ يموت صامتًا عند حدّ ١٢٨ م.ب — استجابةٌ فارغة بلا خطأ
     * يُقرأ. والحدُّ يُرفع لطلب التصدير وحده ولا يمسّ غيرَه.
     */
    public static function prepare(string $format): void
    {
        if ($format === 'csv') {
            return;
        }

        $current = self::bytes((string) ini_get('memory_limit'));
        if ($current !== -1 && $current < self::bytes(self::MEMORY)) {
            @ini_set('memory_limit', self::MEMORY);
        }
        @set_time_limit(180);
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }

    /**
     * @param  array{business: string, title: string, period: string, filters?: list<string>, generated?: string, branch?: string}  $meta
     * @param  list<array{key: string, label: string, kind: string}>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     * @param  list<array{label: string, value: string}>  $cards
     * @param  array<string, float|int>|null  $totals
     */
    public static function download(string $format, string $filename, array $meta, array $columns, iterable $rows, array $cards = [], ?array $totals = null, ?array $truncated = null): Response
    {
        $meta['generated'] ??= now()->format('Y-m-d H:i');
        self::prepare($format);

        return match ($format) {
            'xlsx' => self::xlsx($filename, $meta, $columns, $rows, $cards, $totals, $truncated),
            'csv' => self::csv($filename, $meta, $columns, $rows, $cards, $totals, $truncated),
            'pdf' => self::pdf($filename, $meta, $columns, $rows, $cards, $totals, $truncated),
            default => abort(404),
        };
    }

    /** خانةُ صفٍّ بنوع عمودها — المبلغُ والعددُ رقمان، والنصُّ نصٌّ أو «—» */
    public static function cell(array $column, array $row): mixed
    {
        $value = $row[$column['key']] ?? null;

        if ($column['kind'] === 'money' || $column['kind'] === 'number') {
            return $value === null || $value === '' ? 0 : $value;
        }

        if (is_bool($value)) {
            return $value ? __('نعم') : __('لا');
        }

        return $value === null || $value === '' ? '—' : (string) $value;
    }

    /** سطرُ الإجمالي: عنوانُه في أوّل عمودٍ نصّيّ، ومجموعُ كلّ عمودٍ له مجموع */
    private static function totalsLine(array $columns, array $totals): array
    {
        $line = [];
        $labelled = false;
        foreach ($columns as $column) {
            if (array_key_exists($column['key'], $totals)) {
                $line[] = $totals[$column['key']];
            } elseif (! $labelled) {
                $line[] = $totals['_label'] ?? __('الإجمالي');
                $labelled = true;
            } else {
                $line[] = '';
            }
        }

        return $line;
    }

    /** سطورُ الترويسة: الشركةُ والعنوانُ والفترةُ والمرشّحاتُ ووقتُ التصدير */
    private static function header(array $meta): array
    {
        $lines = [
            [$meta['business']],
            [$meta['title']],
            [__('الفترة').': '.$meta['period']],
        ];
        foreach ($meta['filters'] ?? [] as $filter) {
            $lines[] = [$filter];
        }
        $lines[] = [__('تاريخ التصدير').': '.$meta['generated']];

        return $lines;
    }

    private static function truncatedNote(?array $truncated): ?string
    {
        return $truncated === null ? null : __('يحمل الملفّ :shown من :total صفًّا — ضيّق الفترة أو المرشّحات لتصدير الباقي.', [
            'shown' => number_format((int) $truncated['shown']),
            'total' => number_format((int) $truncated['total']),
        ]);
    }

    /* ============================== إكسل ============================== */

    private static function xlsx(string $filename, array $meta, array $columns, iterable $rows, array $cards, ?array $totals, ?array $truncated): Response
    {
        $spreadsheet = new Spreadsheet;
        self::sheet($spreadsheet->getActiveSheet(), $meta, $columns, $rows, $cards, $totals, $truncated);

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * ورقةٌ واحدة — تُستعمل للتقرير المسطَّح ولكلّ لسانٍ في التقرير ذي الأقسام.
     *
     * @param  iterable<array<string, mixed>>  $rows
     */
    public static function sheet(Worksheet $sheet, array $meta, array $columns, iterable $rows, array $cards = [], ?array $totals = null, ?array $truncated = null): void
    {
        $sheet->setRightToLeft(true);
        $last = Coordinate::stringFromColumnIndex(max(1, count($columns)));

        $row = 1;
        foreach (self::header($meta) as $i => $line) {
            $sheet->setCellValueExplicit('A'.$row, (string) $line[0], DataType::TYPE_STRING);
            if ($i <= 2) {
                $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize($i === 0 ? 14 : 11);
            }
            $row++;
        }

        // المؤشّرات فوق الجدول: من يفتح الورقة يقرأ الخلاصة قبل الصفوف
        if ($cards !== []) {
            $row++;
            foreach ($cards as $card) {
                $sheet->setCellValueExplicit('A'.$row, (string) $card['label'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('B'.$row, (string) $card['value'], DataType::TYPE_STRING);
                $row++;
            }
        }
        $row++;

        $head = $row;
        foreach ($columns as $i => $column) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1).$row, (string) $column['label'], DataType::TYPE_STRING);
        }
        $style = $sheet->getStyle("A{$row}:{$last}{$row}");
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('111111');
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $row++;

        $first = $row;
        foreach ($rows as $line) {
            self::writeLine($sheet, $columns, $row, array_map(fn ($c) => self::cell($c, $line), $columns));
            $row++;
        }
        /* فترةٌ بلا صفوف تقول ذلك — ولو كتبت سطرَ إجماليٍّ أصفارًا تحته */
        if ($row === $first) {
            $sheet->setCellValueExplicit('A'.$row, __('لا بيانات في هذه الفترة'), DataType::TYPE_STRING);
            $row++;
            $first = $row;
        }

        if ($totals !== null && $totals !== []) {
            self::writeLine($sheet, $columns, $row, self::totalsLine($columns, $totals));
            $sheet->getStyle("A{$row}:{$last}{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}:{$last}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
            $row++;
        }

        if ($row > $first) {
            foreach ($columns as $i => $column) {
                $letter = Coordinate::stringFromColumnIndex($i + 1);
                if ($column['kind'] === 'money') {
                    $sheet->getStyle("{$letter}{$first}:{$letter}".($row - 1))->getNumberFormat()->setFormatCode('#,##0.000');
                } elseif ($column['kind'] === 'number') {
                    $sheet->getStyle("{$letter}{$first}:{$letter}".($row - 1))->getNumberFormat()->setFormatCode('#,##0.###');
                }
            }
        }

        if (($note = self::truncatedNote($truncated)) !== null) {
            $row++;
            $sheet->setCellValueExplicit('A'.$row, $note, DataType::TYPE_STRING);
            $sheet->getStyle('A'.$row)->getFont()->setBold(true)->getColor()->setRGB('9A3412');
        }

        // رأسُ الجدول يبقى ظاهرًا حين تُمرَّر الصفوف
        $sheet->freezePane('A'.($head + 1));

        foreach ($columns as $i => $column) {
            $letter = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->getColumnDimension($letter)->setWidth(match ($column['kind']) {
                'money' => 16,
                'number' => 12,
                default => min(48, max(14, mb_strlen((string) $column['label']) + 6)),
            });
        }
    }

    /** سطرٌ بأنواع خاناته: النصُّ صريحٌ لا يُقرأ معادلة، والرقمُ رقم */
    private static function writeLine(Worksheet $sheet, array $columns, int $row, array $cells): void
    {
        foreach (array_values($cells) as $i => $value) {
            $coordinate = Coordinate::stringFromColumnIndex($i + 1).$row;
            if (is_int($value) || is_float($value)) {
                $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_NUMERIC);
            } else {
                $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
            }
        }
    }

    /* =============================== CSV =============================== */

    private static function csv(string $filename, array $meta, array $columns, iterable $rows, array $cards, ?array $totals, ?array $truncated): Response
    {
        return response()->streamDownload(function () use ($meta, $columns, $rows, $cards, $totals, $truncated) {
            $out = fopen('php://output', 'w');
            // BOM: بلا هذه تفتح إكسل العربيةَ رموزًا
            fwrite($out, "\xEF\xBB\xBF");
            $put = fn (array $line) => fputcsv($out, array_map([Sheet::class, 'guard'], $line), escape: '');

            foreach (self::header($meta) as $line) {
                $put($line);
            }
            foreach ($cards as $card) {
                $put([$card['label'], $card['value']]);
            }
            $put([]);
            $put(array_column($columns, 'label'));
            $written = 0;
            foreach ($rows as $line) {
                $put(array_map(fn ($c) => self::cell($c, $line), $columns));
                $written++;
            }
            if ($written === 0) {
                $put([__('لا بيانات في هذه الفترة')]);
            }
            if ($totals !== null && $totals !== []) {
                $put(self::totalsLine($columns, $totals));
            }
            if (($note = self::truncatedNote($truncated)) !== null) {
                $put([]);
                $put([$note]);
            }
            fclose($out);
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* =============================== PDF =============================== */

    private static function pdf(string $filename, array $meta, array $columns, iterable $rows, array $cards, ?array $totals, ?array $truncated): Response
    {
        $lines = [];
        foreach ($rows as $line) {
            $lines[] = array_map(fn ($c) => self::printable($c, self::cell($c, $line)), $columns);
        }

        $html = view('pdf.report', [
            'business' => Demo::business(auth()->user()?->business_id ?? Demo::bid()) ?: ['name' => $meta['business']],
            'branch' => $meta['branch'] ?? '',
            'title' => $meta['title'],
            'rangeLabel' => $meta['period'],
            'filters' => $meta['filters'] ?? [],
            'generatedAt' => $meta['generated'],
            'cards' => $cards,
            'headings' => array_column($columns, 'label'),
            'rows' => $lines,
            'totals' => $totals !== null && $totals !== []
                ? array_map(fn ($v, $c) => is_int($v) || is_float($v) ? self::printable($c, $v) : $v, self::totalsLine($columns, $totals), $columns)
                : null,
            'sections' => [],
            'truncated' => $truncated,
        ])->render();

        // جدولٌ بسبعة أعمدةٍ أو أكثر يخرج عرضيًّا: الأعمدةُ لا تلتصق والأسماءُ لا تنكسر
        return Pdf::a4($html, $filename, count($columns) >= 7);
    }

    /** الخانةُ للورق: المبلغُ بمنازل العملة، والعددُ بفواصله */
    public static function printable(array $column, mixed $value): string
    {
        return match ($column['kind']) {
            'money' => Demo::moneyBase((float) $value),
            'number' => is_numeric($value) ? (string) (0 + $value) : (string) $value,
            default => (string) $value,
        };
    }
}
