<?php

namespace App\Support;

use Closure;
use Illuminate\Http\JsonResponse;
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
 * إكسل يقرأ ما يبدأ بـ«=» معادلةً: اسمُ عميلٍ كتبه أحدٌ «=HYPERLINK(…)» يصير
 * رابطًا يُضغط. فخاناتُ النصّ تُكتب نصًّا مضمَّنًا في إكسل (`XlsxStream`)،
 * وتُحرس بـ`Sheet::guard` في CSV.
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
     * ═══ كلُّ ما طابق — وحدُّ الورق وحده ═══
     *
     * CSV وإكسل يُكتبان صفًّا صفًّا (`Rows` · `XlsxStream`): الذاكرةُ دفعةٌ واحدةٌ
     * مهما طال الجدول، فلا سقفَ يبتر. وPDF يرسم جدولَه كلَّه قبل أن يُخرجه (~١١٥
     * م.ب لكلّ ألف صفّ)، فله حدٌّ — وما فوقه لا يُقدَّم ملفًّا كاملًا: يُقال قبل
     * التنزيل ويُقترح إكسل أو CSV، ولا يخرج جزؤه إلّا لمن طلبه صراحةً بعد أن قرأ.
     */

    /** أقصى ما تحمله ورقةُ PDF — أطولُ منها يُقرأ في إكسل لا على الورق */
    public const PDF_LIMIT = 1000;

    /** ذاكرةُ طلب PDF — يُرفع إليها الحدُّ إن كان أدنى، ولا يُخفض */
    public const MEMORY = '512M';

    /**
     * يُهيّئ الطلبَ لملفٍّ كبير: وقتٌ لا ينقطع في منتصفه، وذاكرةٌ تتّسع لورق PDF.
     *
     * كان ملفُّ PDF لألف صفٍّ يموت صامتًا عند حدّ ١٢٨ م.ب — استجابةٌ فارغة بلا خطأ
     * يُقرأ. والحدُّ يُرفع لطلب التصدير وحده ولا يمسّ غيرَه.
     */
    public static function prepare(string $format): void
    {
        @set_time_limit($format === 'pdf' ? 180 : 900);

        if ($format !== 'pdf') {
            return;
        }

        $current = self::bytes((string) ini_get('memory_limit'));
        if ($current !== -1 && $current < self::bytes(self::MEMORY)) {
            @ini_set('memory_limit', self::MEMORY);
        }
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
     * عددُ صفوف الملفّ وحدُّ PDF — يسأل عنه زرُّ «تصدير» قبل أن ينزّل PDF (`?probe=1`).
     */
    public static function probe(int $rows): JsonResponse
    {
        return response()->json(['rows' => $rows, 'limit' => self::PDF_LIMIT, 'fits' => $rows <= self::PDF_LIMIT]);
    }

    /**
     * PDF أطولُ من حدّه لا يُقدَّم ملفًّا كاملًا.
     *
     * يُردّ بشرحٍ (422) — إلّا أن يطلب صاحبُه أوّلَ الصفوف صراحةً (`partial=1`) بعد
     * أن قرأ التنبيه في الشاشة، فيخرج الجزءُ ويقول ذلك في رأس ورقته.
     *
     * @return array{shown: int, total: int}|null
     */
    public static function pdfCut(int $rows, bool $partial): ?array
    {
        if ($rows <= self::PDF_LIMIT) {
            return null;
        }

        abort_unless($partial, 422, self::tooLongForPdf($rows));

        return ['shown' => self::PDF_LIMIT, 'total' => $rows];
    }

    /** لماذا لا يخرج PDF كاملًا — وما البديل */
    public static function tooLongForPdf(int $rows): string
    {
        return __('النتائج :rows صفًّا، وملفّ PDF يتّسع لـ:limit صفّ على الأكثر. لتحصل على كلّ النتائج صدّر Excel أو CSV.', [
            'rows' => number_format($rows),
            'limit' => number_format(self::PDF_LIMIT),
        ]);
    }

    /**
     * @param  array{business: string, title: string, period: string, periodLine?: ?string, filters?: list<string>, generated?: string, branch?: string}  $meta
     * @param  list<array{key: string, label: string, kind: string}>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     * @param  list<array{label: string, value: string}>  $cards
     * @param  array<string, float|int|string>|Closure(): array<string, float|int|string>|null  $totals  مصفوفةٌ — أو دالّةٌ تُقرأ بعد الصفوف لمجاميعَ تُجمع أثناءها
     * @param  array{shown: int, total: int}|null  $cut  ملفُّ PDF جزئيٌّ طُلب صراحةً
     */
    public static function download(string $format, string $filename, array $meta, array $columns, iterable $rows, array $cards = [], array|Closure|null $totals = null, ?array $cut = null): Response
    {
        $meta['generated'] ??= now()->format('Y-m-d H:i');
        self::prepare($format);

        return match ($format) {
            'xlsx' => self::xlsx($filename, $meta, $columns, $rows, $cards, $totals),
            'csv' => self::csv($filename, $meta, $columns, $rows, $cards, $totals),
            'pdf' => self::pdf($filename, $meta, $columns, $rows, $cards, $totals, $cut),
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

    /** المجاميعُ بعد الصفوف — ما يُجمع أثناء الكتابة يُقرأ حين تنتهي */
    private static function totals(array|Closure|null $totals): ?array
    {
        $totals = $totals instanceof Closure ? $totals() : $totals;

        return $totals === null || $totals === [] ? null : $totals;
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

    /**
     * سطرُ المدّة في الترويسة.
     *
     * الرصيدُ الحاليّ (المخزون، الذمم) لا فترةَ له: يُكتب «الرصيد الحالي بتاريخ …»
     * كما هو (`periodLine`) — لا «الفترة: هذا الشهر» فيُقرأ رصيدُ اليوم مجموعَ شهر.
     */
    public static function periodLine(array $meta): string
    {
        return $meta['periodLine'] ?? __('الفترة').': '.$meta['period'];
    }

    /** سطورُ الترويسة: الشركةُ والعنوانُ والمدّةُ والمرشّحاتُ ووقتُ التصدير */
    private static function header(array $meta): array
    {
        $lines = [
            [$meta['business']],
            [$meta['title']],
            [self::periodLine($meta)],
        ];
        foreach ($meta['filters'] ?? [] as $filter) {
            $lines[] = [$filter];
        }
        $lines[] = [__('تاريخ التصدير').': '.$meta['generated']];

        return $lines;
    }

    /** ورقةُ PDF الجزئيّة تقول ذلك في رأسها — والإجماليُّ تحتها لكلّ النتائج */
    public static function partialNote(?array $cut): ?string
    {
        return $cut === null ? null : __('ملفٌّ جزئيّ: يحمل أوّل :shown من :total صفًّا (حدّ ملفّ PDF). لكلّ النتائج صدّر Excel أو CSV.', [
            'shown' => number_format((int) $cut['shown']),
            'total' => number_format((int) $cut['total']),
        ]);
    }

    /* ============================== إكسل ============================== */

    private static function xlsx(string $filename, array $meta, array $columns, iterable $rows, array $cards, array|Closure|null $totals): Response
    {
        $book = new XlsxStream;
        self::sheet($book, mb_substr($meta['title'], 0, 31), $meta, $columns, $rows, $cards, $totals);

        return self::book($book->finish(), $filename);
    }

    /**
     * الملفُّ المحزوم يُرسَل ثمّ يُحذف — يُبنى قبل الإرسال فيُعرف حجمُه، ويُردّ
     * خطأُ بنائه صفحةً لا ملفًّا مكسورًا.
     */
    public static function book(string $path, string $filename): Response
    {
        $size = filesize($path);
        register_shutdown_function(fn () => @unlink($path));

        return response()->streamDownload(function () use ($path) {
            readfile($path);
            @unlink($path);
        }, $filename.'.xlsx', array_filter([
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Length' => $size !== false ? (string) $size : null,
        ]));
    }

    /**
     * ورقةٌ واحدة — تُستعمل للتقرير المسطَّح ولكلّ لسانٍ في التقرير ذي الأقسام.
     *
     * @param  iterable<array<string, mixed>>  $rows
     */
    public static function sheet(XlsxStream $book, string $name, array $meta, array $columns, iterable $rows, array $cards = [], array|Closure|null $totals = null): void
    {
        $header = self::header($meta);
        // رأسُ الجدول يبقى ظاهرًا حين تُمرَّر الصفوف: بعد الترويسة والمؤشّرات وسطرٍ فارغ
        $head = count($header) + ($cards !== [] ? count($cards) + 1 : 0) + 2;

        $book->open($name, array_map(fn ($column) => match ($column['kind']) {
            'money' => 16,
            'number' => 12,
            default => min(48, max(14, mb_strlen((string) $column['label']) + 6)),
        }, $columns), $head);

        foreach ($header as $i => $line) {
            $book->line([(string) $line[0]], match ($i) {
                0 => XlsxStream::TITLE,
                1, 2 => XlsxStream::BOLD,
                default => XlsxStream::PLAIN,
            });
        }

        // المؤشّرات فوق الجدول: من يفتح الورقة يقرأ الخلاصة قبل الصفوف
        if ($cards !== []) {
            $book->blank();
            foreach ($cards as $card) {
                $book->line([(string) $card['label'], (string) $card['value']]);
            }
        }
        $book->blank();

        $book->line(array_map(fn ($column) => (string) $column['label'], $columns), XlsxStream::HEAD);

        $kinds = array_map(fn ($column) => match ($column['kind']) {
            'money' => XlsxStream::MONEY,
            'number' => XlsxStream::NUMBER,
            default => XlsxStream::PLAIN,
        }, $columns);

        $written = 0;
        foreach ($rows as $line) {
            $book->line(self::typed($columns, $line), $kinds);
            $written++;
        }
        /* فترةٌ بلا صفوف تقول ذلك — ولو كتبت سطرَ إجماليٍّ أصفارًا تحته */
        if ($written === 0) {
            $book->line([__('لا بيانات في هذه الفترة')]);
        }

        if (($sum = self::totals($totals)) !== null) {
            $book->line(self::totalsLine($columns, $sum), array_map(fn ($kind) => match ($kind) {
                XlsxStream::MONEY => XlsxStream::TOTAL_MONEY,
                XlsxStream::NUMBER => XlsxStream::TOTAL_NUMBER,
                default => XlsxStream::TOTAL,
            }, $kinds));
        }
    }

    /** خاناتُ سطرٍ بأنواعها: المبلغُ رقمٌ يُجمع، والنصُّ نصّ */
    private static function typed(array $columns, array $row): array
    {
        return array_map(function ($column) use ($row) {
            $value = self::cell($column, $row);
            $numeric = $column['kind'] === 'money' || $column['kind'] === 'number';

            return $numeric && is_string($value) && is_numeric($value) ? 0 + $value : $value;
        }, $columns);
    }

    /* =============================== CSV =============================== */

    private static function csv(string $filename, array $meta, array $columns, iterable $rows, array $cards, array|Closure|null $totals): Response
    {
        return response()->streamDownload(function () use ($meta, $columns, $rows, $cards, $totals) {
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
                // يُدفع إلى المتصفّح دفعةً دفعة: التنزيلُ يبدأ ولا ينتظر آخرَ صفّ
                if (++$written % 1000 === 0) {
                    fflush($out);
                    flush();
                }
            }
            if ($written === 0) {
                $put([__('لا بيانات في هذه الفترة')]);
            }
            if (($sum = self::totals($totals)) !== null) {
                $put(self::totalsLine($columns, $sum));
            }
            fclose($out);
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* =============================== PDF =============================== */

    private static function pdf(string $filename, array $meta, array $columns, iterable $rows, array $cards, array|Closure|null $totals, ?array $cut): Response
    {
        $lines = [];
        foreach ($rows as $line) {
            if ($cut !== null && count($lines) >= $cut['shown']) {
                break;
            }
            $lines[] = array_map(fn ($c) => self::printable($c, self::cell($c, $line)), $columns);
        }

        /* ومجاميعُ تُجمع أثناء الصفوف لا تُطبع تحت ورقةٍ جزئيّة: هي لما طُبع لا للكلّ */
        $sum = $cut !== null && $totals instanceof Closure ? null : self::totals($totals);

        $html = view('pdf.report', [
            'business' => Demo::business(auth()->user()?->business_id ?? Demo::bid()) ?: ['name' => $meta['business']],
            'branch' => $meta['branch'] ?? '',
            'title' => $meta['title'],
            'rangeLabel' => $meta['period'],
            'periodLine' => $meta['periodLine'] ?? null,
            'filters' => $meta['filters'] ?? [],
            'generatedAt' => $meta['generated'],
            'cards' => $cards,
            'headings' => array_column($columns, 'label'),
            'rows' => $lines,
            'totals' => $sum !== null
                ? array_map(fn ($v, $c) => is_int($v) || is_float($v) ? self::printable($c, $v) : $v, self::totalsLine($columns, $sum), $columns)
                : null,
            'sections' => [],
            'partial' => self::partialNote($cut),
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
