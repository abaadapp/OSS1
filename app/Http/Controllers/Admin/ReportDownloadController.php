<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Intelligence\ManagementReports;
use App\Support\Pdf;
use App\Support\ReportColumns;
use App\Support\ReportData;
use App\Support\ReportPeriod;
use App\Support\Reports;
use App\Support\Sheet;
use App\Support\TableExport;
use App\Support\XlsxStream;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * تنزيل أيّ تقريرٍ بالصيغ الثلاث المعتمدة — إكسل وPDF وCSV.
 *
 * وبابٌ واحد لا ثلاثةٌ لكلّ تقرير: أربعةٌ وعشرون تقريرًا في ثلاث صيغ تعني
 * اثنين وسبعين مسارًا ومتحكّمًا، تتفرّق أعمدتُها عن أعمدة الشاشة واحدًا بعد
 * واحد ولا يُكتشف الفرق إلا حين يُقارَن ملفٌّ بشاشته.
 *
 * والملفّ يقرأ ما تقرؤه الشاشة بعينه — `ReportData` نفسها بالمرشّحات نفسها
 * من سلسلة الاستعلام وبالفترة نفسها (`ReportPeriod`). فما في اليد هو ما كان
 * على الشاشة لحظة الضغط، لا فترةً أخرى ولا فرعًا آخر — ولا أوّلَ خمسمئة صفٍّ
 * منه: الشاشةُ تعرض سقفًا وتقوله، والملفُّ يحمل كلَّ ما طابق بلا سقف (`Rows`).
 * وPDF وحده له حدٌّ يُسأل عنه قبل التنزيل (`?probe=1`) — انظر `TableExport::pdfCut`.
 */
class ReportDownloadController extends Controller
{
    public function xlsx(Request $request, string $report): Response
    {
        return $this->export($request, $report, 'xlsx');
    }

    public function csv(Request $request, string $report): Response
    {
        return $this->export($request, $report, 'csv');
    }

    public function pdf(Request $request, string $report): Response
    {
        return $this->export($request, $report, 'pdf');
    }

    /**
     * التقرير وبياناته — بحارس قسمه لا بصلاحية «التقارير».
     *
     * المسارات تحت `admin.reports.*` فيقيسها حارس المسار بصلاحية الفهرس،
     * وهذه قراءاتٌ على أقسامٍ أخرى: رواتبُ الموظفين، وإنفاقُ العملاء،
     * وحركةُ المال. والتنزيل يُخرجها من النظام إلى ملفٍّ يُرسَل — فحراسته
     * أولى لا أهون. والتقريرُ الإداريّ يُسأل فوق ذلك عن إذن تحليله، كما في شاشته.
     *
     * @return array{0: array, 1: array, 2: ?ReportPeriod}
     */
    private function load(Request $request, string $report, string $format): array
    {
        abort_unless(ReportColumns::has($report) || ReportColumns::sectioned($report), 404);

        /* وتقاريرُ الإدارة (المرحلة 11) لا تحمل مسارًا باسمها — تُقاس بمفتاحها */
        $section = Reports::sectionForKey($report) ?? Reports::sectionForRoute('admin.reports.'.$report);
        abort_if($section === null, 404);
        abort_unless(
            auth()->user()?->allows($section),
            403,
            __('ليس لديك صلاحية للوصول إلى قسم «:section».', ['section' => $section]),
        );
        if (in_array($report, ManagementReports::KEYS, true)) {
            abort_unless(auth()->user()?->may(ManagementReports::action($report)), 403);
        }

        $filters = $request->query();
        unset($filters['probe'], $filters['partial']);
        $filters['_export'] = true;

        /*
         * والفترةُ لا تُفرض على تقريرٍ لا يعرفها: الهالك يُرشَّح بمدّةٍ بحدّين
         * (`from` و`to`)، وتقاريرُ الإدارة بمدّتها (`period`)، والرصيدُ الحاليّ
         * (المخزون) بلا مدّةٍ أصلًا. ولكلٍّ حارسُه: مدّةٌ خاطئةٌ تُردّ بـ422 لا بملفٍّ
         * لمدّةٍ غير التي طُلبت.
         */
        $period = null;
        if (Reports::isSnapshot($report)) {
            unset($filters['range'], $filters['from'], $filters['to']);
        } elseif (ReportColumns::sectioned($report)) {
            $this->guardSectionedPeriod($report, $filters);
        } else {
            $period = ReportPeriod::strict($filters);
            $filters = array_merge($filters, $period->query());
        }

        try {
            $data = ReportData::$report(Demo::bid(), $filters);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return [$filters, $data, $period];
    }

    /** مدّةُ الهالك وتقاريرِ الإدارة — تُفحص قبل أن تُقرأ */
    private function guardSectionedPeriod(string $report, array $filters): void
    {
        if ($report === 'waste') {
            $from = is_string($filters['from'] ?? null) && $filters['from'] !== '' ? $filters['from'] : now()->startOfMonth()->toDateString();
            $to = is_string($filters['to'] ?? null) && $filters['to'] !== '' ? $filters['to'] : now()->toDateString();
            abort_if(($error = ReportPeriod::check($from, $to)) !== null, 422, (string) $error);

            return;
        }

        try {
            ManagementReports::period(Demo::bid(), $filters);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    /** عنوان التقرير كما في الفهرس — لا اسمٌ ثانٍ للشيء الواحد */
    private function title(string $report): string
    {
        foreach (Reports::ALL as $entry) {
            if ($entry['key'] === $report) {
                return __($entry['title']);
            }
        }

        return __('تقرير');
    }

    /**
     * المؤشّرات بطاقاتٍ للورق — بترتيب الشاشة نفسه.
     *
     * والمبالغ تُنسَّق هنا لا في القالب: القالب لا يعرف أيُّ مفتاحٍ مبلغٌ
     * وأيُّه عدد، فيطبع الرصيد بثلاث خاناتٍ عشرية والعدد كذلك.
     */
    private function cards(array $summary): array
    {
        $out = [];
        foreach ($summary as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $out[] = [
                'label' => __(self::LABELS[$key] ?? $key),
                'value' => is_float($value) ? Demo::money($value) : (string) ($value ?? '—'),
            ];
        }

        return array_slice($out, 0, 4);
    }

    /** أسماء مفاتيح المؤشّرات بالعربية — تُقرأ على الورق لا كمفاتيح */
    private const LABELS = [
        'income' => 'المقبوضات', 'outgo' => 'المدفوعات', 'net' => 'الصافي', 'count' => 'العدد',
        'total' => 'الإجمالي', 'average' => 'المتوسّط', 'topType' => 'أعلى نوع', 'topTotal' => 'قيمته',
        'lines' => 'الأسطر', 'matched' => 'المطابَق', 'unmatched' => 'غير المطابَق',
        'cancelled' => 'الملغاة', 'products' => 'المنتجات', 'revenue' => 'الإيراد', 'profit' => 'الربح',
        'sold' => 'ما بيع منها', 'items' => 'الأصناف', 'quantity' => 'الكمية', 'value' => 'القيمة',
        'below' => 'تحت الحدّ', 'received' => 'المستلَمة', 'pending' => 'المعلّقة',
        'suppliers' => 'المورّدون', 'active' => 'النشط', 'orders' => 'الطلبات',
        'users' => 'المستخدمون', 'topAction' => 'أكثر إجراء', 'topCount' => 'مرّاته',
        'used' => 'المستخدَم', 'uses' => 'مرات الاستخدام', 'discount' => 'الخصومات',
        'operations' => 'عمليات الجرد', 'shortage' => 'قيمة النقص', 'surplus' => 'قيمة الزيادة',
        'staff' => 'الموظفون', 'sellers' => 'من أصدر فواتير', 'topName' => 'الأعلى', 'topSales' => 'إيراداته',
        'customers' => 'العملاء',
        // فواتير العملاء
        'outstanding' => 'الباقي', 'overdue' => 'المتأخّرة', 'drafts' => 'المسودّات',
        'paid' => 'المسدَّد', 'returns' => 'المردودات', 'invoices' => 'الفواتير',
        // تقاريرُ الإدارة والضريبة
        'collected' => 'المحصَّل', 'taxable' => 'الخاضع للضريبة', 'output' => 'ضريبة المخرجات', 'input' => 'ضريبة المدخلات',
        'due' => 'الصافي المستحقّ',
    ];

    /** ما يُكتب في ترويسة الملفّ عن مدّته — مسمّاةً كانت أو بحدّين، أو رصيدًا بتاريخه */
    private function periodLabel(string $report, array $data, ?ReportPeriod $period): string
    {
        if (Reports::isSnapshot($report)) {
            return ReportPeriod::asOf();
        }

        return $period?->label() ?? $data['periodLabel'] ?? Demo::rangeLabel('month');
    }

    private function filename(string $report, array $filters, ?ReportPeriod $period): string
    {
        // الرصيدُ الحاليّ باسم تاريخه: report-inventory-as-of-2026-10-18
        if (Reports::isSnapshot($report)) {
            return 'report-'.$report.'-as-of-'.ReportPeriod::today();
        }

        $slug = $period?->slug()
            ?? match (true) {
                $report === 'waste' => ($filters['from'] ?? now()->startOfMonth()->toDateString()).'_'.($filters['to'] ?? now()->toDateString()),
                ($filters['period'] ?? null) === 'custom' => ($filters['from'] ?? '').'_'.($filters['to'] ?? ''),
                default => (string) ($filters['period'] ?? 'this_month'),
            };

        return 'report-'.$report.'-'.preg_replace('/[^A-Za-z0-9_\-]/', '', $slug).'-'.now()->format('Y-m-d');
    }

    /**
     * المرشّحاتُ المطبَّقة بأسمائها — تُكتب في ترويسة الملفّ.
     *
     * ورقةٌ تقول «فواتير سبتمبر» ولا تقول «فرع صلالة» تُقرأ على أنها الفروعُ كلّها.
     * والقيمةُ باسمها من منتقيات التقرير لا برقمها: «الفرع: 3» لا يُقرأ.
     *
     * @return list<string>
     */
    private function filterLines(array $filters, array $data): array
    {
        $labels = [
            'branch_id' => ['الفرع', 'branches'], 'status' => ['الحالة', 'statuses'], 'state' => ['حالة السداد', 'states'],
            'type' => ['النوع', 'types'], 'method' => ['الوسيلة', 'methods'], 'match_status' => ['المطابقة', 'statuses'],
            'supplier_id' => ['المورّد', 'suppliers'], 'category_id' => ['القسم', 'categories'], 'user_id' => ['المستخدم', 'users'],
            'action' => ['الإجراء', 'actions'], 'reason' => ['النتيجة', 'reasons'], 'q' => ['البحث', null], 'below' => ['تحت الحدّ فقط', null],
            'product_id' => ['الصنف', null],
        ];

        $lines = [];
        foreach ($labels as $key => [$label, $options]) {
            $value = $filters[$key] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }
            $name = $value;
            if ($options !== null) {
                foreach ($data['options'][$options] ?? [] as $option) {
                    if ((string) $option['value'] === $value) {
                        $name = (string) $option['label'];
                        break;
                    }
                }
            }
            $lines[] = __($label).': '.($key === 'below' ? __('نعم') : $name);
        }

        return $lines;
    }

    private function meta(string $report, array $filters, array $data, ?ReportPeriod $period): array
    {
        return [
            'business' => Demo::business(auth()->user()->business_id ?? Demo::bid())['name'] ?? 'Event Plus',
            'title' => $this->title($report),
            'period' => $this->periodLabel($report, $data, $period),
            // الرصيدُ الحاليّ يقول تاريخَه سطرًا كاملًا — لا «الفترة: …»
            'periodLine' => Reports::isSnapshot($report) ? ReportPeriod::asOf() : null,
            'filters' => $this->filterLines($filters, $data),
            'branch' => Demo::scopeName(false),
            'generated' => now()->format('Y-m-d H:i'),
        ];
    }

    private function export(Request $request, string $report, string $format): Response
    {
        /* الذاكرةُ والوقتُ قبل قراءة الصفوف — التقاريرُ ذاتُ الأقسام تُكتب هنا لا في `TableExport` */
        TableExport::prepare($format);
        [$filters, $data, $period] = $this->load($request, $report, $format);

        /* PDF يُسأل عن طوله قبل أن يُرسم: زرُّ «تصدير» يُنبّه إن جاوز حدَّه */
        $count = ReportColumns::sectioned($report)
            ? array_sum(array_map('count', $data['sections'] ?? []))
            : count($data['rows'] ?? []);
        if ($format === 'pdf' && $request->boolean('probe')) {
            return TableExport::probe($count);
        }
        $cut = $format === 'pdf' ? TableExport::pdfCut($count, $request->boolean('partial')) : null;

        Activity::log('report', 'صدّر '.$this->title($report).' ('.['xlsx' => 'Excel', 'csv' => 'CSV', 'pdf' => 'PDF'][$format].')');

        if (ReportColumns::sectioned($report)) {
            return $this->sectioned($format, $report, $filters, $data, $cut);
        }

        return TableExport::download(
            $format,
            $this->filename($report, $filters, $period),
            $this->meta($report, $filters, $data, $period),
            ReportColumns::for($report),
            $data['rows'] ?? [],
            $this->cards($data['summary'] ?? []),
            $data['totals'] ?? null,
            $cut,
        );
    }

    /* ======================= التقاريرُ ذاتُ الأقسام ======================= */

    /**
     * ورقةٌ لكلّ قراءة في ملفّ إكسل واحد، وأقسامٌ متتابعةٌ في CSV وPDF.
     *
     * من فتح الملفّ وجد ستّ ألسنةٍ يقرأ منها ما يريد ويجمع عمودَه — بدل ستّة
     * جداولَ ملصوقةٍ في ورقةٍ واحدة لا يُعرف أين ينتهي أوّلُها.
     */
    private function sectioned(string $format, string $report, array $filters, array $data, ?array $cut = null): Response
    {
        $meta = $this->meta($report, $filters, $data, null);
        $name = $this->filename($report, $filters, null);

        if ($format === 'xlsx') {
            // لسانٌ لكلّ قسم — اسمُه يُقصّ عند ٣١ محرفًا ويُنظَّف مما يرفضه إكسل (`XlsxStream`)،
            // واللسانُ الأوّل هو ما يُفتح عليه الملفّ
            $book = new XlsxStream;
            foreach ($data['sections'] as $section => $rows) {
                TableExport::sheet($book, __($section), ['title' => $meta['title'].' — '.__($section)] + $meta, ReportColumns::sectionColumns($report, $section), $rows);
            }

            return TableExport::book($book->finish(), $name);
        }

        if ($format === 'csv') {
            $cards = $this->cards($data['summary'] ?? []);

            return response()->streamDownload(function () use ($meta, $cards, $report, $data) {
                $out = fopen('php://output', 'w');
                // BOM: بلا هذه تفتح إكسل العربيةَ رموزًا
                fwrite($out, "\xEF\xBB\xBF");
                $put = fn (array $line) => fputcsv($out, array_map([Sheet::class, 'guard'], $line), escape: '');
                $put([$meta['title'], TableExport::periodLine($meta)]);
                foreach ($meta['filters'] as $line) {
                    $put([$line]);
                }
                $put([__('تاريخ التصدير').': '.$meta['generated']]);
                foreach ($cards as $card) {
                    $put([$card['label'], $card['value']]);
                }
                /*
                 * الملفّ الواحد لا يحمل أوراقًا، فتُكتب الأقسام متتابعةً بعنوانٍ
                 * لكلٍّ وسطرٍ فارغ بينها — وإلّا التصق جدولٌ بجدولٍ وقُرئا واحدًا.
                 */
                foreach ($data['sections'] as $section => $rows) {
                    $put([]);
                    $put(['— '.__($section).' —']);
                    $columns = ReportColumns::sectionColumns($report, $section);
                    $put(array_column($columns, 'label'));
                    foreach ($rows as $line) {
                        $put(array_map(fn ($c) => TableExport::cell($c, $line), $columns));
                    }
                }
                fclose($out);
            }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $sections = [];
        $room = $cut['shown'] ?? PHP_INT_MAX;
        foreach ($data['sections'] as $section => $rows) {
            // ورقةٌ جزئيّةٌ طُلبت صراحةً: الأقسامُ بترتيبها حتى حدّ PDF
            $rows = array_slice($rows, 0, max(0, $room));
            $room -= count($rows);
            $columns = ReportColumns::sectionColumns($report, $section);
            $sections[] = [
                'title' => __($section),
                'headings' => array_column($columns, 'label'),
                'rows' => array_map(fn ($l) => array_map(fn ($c) => TableExport::printable($c, TableExport::cell($c, $l)), $columns), $rows),
            ];
        }

        $html = view('pdf.report', [
            'business' => Demo::business(auth()->user()->business_id ?? Demo::bid()),
            'branch' => $meta['branch'],
            'title' => $meta['title'],
            'rangeLabel' => $meta['period'],
            'periodLine' => $meta['periodLine'],
            'filters' => $meta['filters'],
            'generatedAt' => $meta['generated'],
            'cards' => $this->cards($data['summary'] ?? []),
            'headings' => [],
            'rows' => [],
            'totals' => null,
            'sections' => $sections,
            'partial' => TableExport::partialNote($cut),
        ])->render();

        /*
         * والتقريرُ العريض يخرج عرضيًّا: جدولٌ بسبعة أعمدةٍ أو أكثر على ورقةٍ
         * قائمة يخرج بأعمدةٍ ملتصقة تُقرأ بالتخمين.
         */
        $wide = collect(ReportColumns::sectionsOf($report))
            ->contains(fn ($section) => count(ReportColumns::sectionColumns($report, $section)) >= 7);

        return Pdf::a4($html, $name, $wide);
    }
}
