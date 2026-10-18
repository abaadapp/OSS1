<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Reports;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportExportController extends Controller
{
    /** الصف الحالي أثناء بناء الورقة */
    private int $row = 5;

    /**
     * رأسُ عمودٍ ماليّ — باسمه ثمّ رمزِ عملة المتجر بين قوسين.
     *
     * وكان مكتوبًا «(ر.ع)» في عشرة رؤوس: تاجرٌ في دبي يفتح ملفَّ إكسل
     * مصدَّرًا من نظامه فيجد عمودًا اسمُه «المبيعات (ر.ع)» وأرقامُه بالدرهم.
     * ولا يظهر في اختبارٍ مكتوبٍ بالريال — انظر `Support\Money`.
     */
    private function moneyHead(string $label): string
    {
        return __($label).' ('.Demo::baseCurrency()['code'].')';
    }

    /**
     * الفترة التي كان التاجر ينظر إليها لحظة الضغط على «تصدير».
     *
     * كان الملفّ يخرج بفترته الخاصّة مهما اختار: من يقرأ تقرير «اليوم» ويضغط
     * تصدير يخرج باثني عشر شهرًا ولا سطر فيه يقول ذلك.
     */
    private function range(): string
    {
        return Demo::range(request()->query('range'));
    }

    /**
     * تجهيز ورقة RTL بترويسة موحّدة، وإرجاع [الورقة، دالة العنوان، دالة رأس الجدول].
     *
     * $perBranch: هل يرشّح هذا التقرير بالفرع فعلًا؟ — انظر Demo::scopeName.
     * الورقة التي تُجمع على المتجر كلّه لا تنسب نفسها إلى فرعٍ مختارٍ في
     * الشريط، وإلا خرج ملفّان لفرعين يحملان الأرقام نفسها بترويستين.
     */
    private function sheet(Spreadsheet $spreadsheet, string $reportTitle, ?string $range = null, bool $perBranch = false, ?string $rangeLabel = null): array
    {
        $business = Demo::business(auth()->user()->business_id ?? Demo::bid());

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->setTitle($reportTitle);

        $sheet->setCellValue('A1', $business['name'] ?? 'Event Plus OSS');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', $reportTitle.' — '.now()->format('Y-m-d H:i'));
        $sheet->setCellValue('A3', __('الفرع').': '.Demo::scopeName($perBranch));

        // الفترة تُطبع دائمًا حتى في الأوراق التي لا فترة لها (جرد، منتجات):
        // سطرٌ ناقص أسهل أن يُقرأ على أنه «كل شيء» من سطرٍ مكتوب
        if ($range !== null) {
            $sheet->setCellValue('A4', __('الفترة').': '.($rangeLabel ?? Demo::rangeLabel($range)));
            $sheet->getStyle('A4')->getFont()->setBold(true);
        }

        $this->row = $range !== null ? 6 : 5;
        $title = function (string $text) use ($sheet) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", $text);
            $sheet->mergeCells("A{$r}:C{$r}");
            $sheet->getStyle("A{$r}")->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("A{$r}:C{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('111111');
            $sheet->getStyle("A{$r}:C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $this->row++;
        };
        $head = function (array $cols) use ($sheet) {
            $r = $this->row;
            $sheet->fromArray($cols, null, "A{$r}");
            $last = chr(ord('A') + count($cols) - 1);
            $sheet->getStyle("A{$r}:{$last}{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}:{$last}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F0F0EE');
            $this->row++;
        };

        return [$sheet, $title, $head];
    }

    /** رأس جدول مسطّح بخلفية سوداء — يُرجع رقم أول صف بيانات */
    private function tableHead($sheet, array $cols): int
    {
        $r = $this->row;
        $last = chr(ord('A') + count($cols) - 1);
        $sheet->fromArray($cols, null, "A{$r}");
        $sheet->getStyle("A{$r}:{$last}{$r}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$r}:{$last}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('111111');
        $sheet->getStyle("A{$r}:{$last}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $this->row++;

        return $this->row;
    }

    /** إنهاء الورقة: تنسيق المبالغ + عرض تلقائي + تنزيل */
    private function download(Spreadsheet $spreadsheet, $sheet, array $moneyCells, string $filename)
    {
        // تنسيق الأرقام بثلاث خانات عشرية (يمنع ظهور 132.02000000000001)
        foreach ($moneyCells as $cell) {
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.000');
        }
        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** تصدير المنتجات كملف Excel حقيقي (xlsx) */
    public function xlsx()
    {
        // الورقة تُبنى من حمولة الشاشة نفسها — انظر Support\Reports::salesReport
        $report = Reports::salesReport(request()->query(), true);
        $range = $report['range'];
        $spreadsheet = new Spreadsheet;
        [$sheet, $title, $head] = $this->sheet($spreadsheet, __('تقرير الإيرادات'), $range, false, $report['rangeLabel']);
        $money = [];

        /*
         * المؤشرات: بطاقات الشاشة نفسها بفترتها.
         *
         * كانت `Demo::adminStats()` — أرقامُ اليوم والشهر مهما كانت الفترة
         * المطلوبة، ومحصورةٌ بالفرع الحالي بينما ما تحتها في الورقة ليس
         * كذلك. فتخرج ورقةٌ ترويستها «اليوم» وأوّل جدولٍ فيها الشهرُ كلّه.
         */
        $title(__('المؤشرات الرئيسية'));
        $head([__('المؤشر'), __('القيمة')]);
        foreach (Reports::summaryRows($report['summary']) as $s) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", $s['label']);
            $sheet->setCellValue("B{$r}", $s['value']);
            if ($s['money']) {
                $money[] = "B{$r}";
            }
            $this->row++;
        }
        $this->row++;

        // المبيعات على محور الفترة — ساعاتٍ أو أيّامًا أو أشهرًا، وبعدد الطلبات
        $series = $report['salesSeries'];
        $title(__('الإيرادات').' — '.$report['rangeLabel']);
        $head([__('الفترة'), $this->moneyHead('الإيرادات'), __('عدد الفواتير')]);
        foreach ($series['full'] as $i => $label) {
            // ما لم يأتِ بعدُ لا يُكتب: صفٌّ بصفرٍ عن يوم غدٍ رقمٌ لا واقعة
            if (($series['data'][$i] ?? null) === null) {
                continue;
            }
            $r = $this->row;
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", round((float) ($series['data'][$i] ?? 0), 3));
            $sheet->setCellValue("C{$r}", (int) ($series['counts'][$i] ?? 0));
            $money[] = "B{$r}";
            $this->row++;
        }
        $this->row++;

        // وسائل الدفع — من الطلبات كما في مخطّط الشاشة، لا من دفتر المقبوضات
        $title(__('توزيع وسائل الدفع'));
        $head([__('الوسيلة'), $this->moneyHead('الإجمالي'), __('عدد العمليات')]);
        foreach (Reports::salesPayments($report) as $m) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", $m['name']);
            $sheet->setCellValue("B{$r}", $m['total']);
            $sheet->setCellValue("C{$r}", $m['count']);
            $money[] = "B{$r}";
            $this->row++;
        }
        $this->row++;

        // أفضل المنتجات — بترتيب الإيراد كما في جدول الشاشة، لا بترتيب الكمية
        $title(__('الأعلى إيرادًا'));
        $head([__('المنتج'), __('القسم'), __('المُباع'), $this->moneyHead('الإيراد')]);
        foreach ($report['topSellingProducts'] as $p) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", $p['name']);
            $sheet->setCellValue("B{$r}", $p['cat']);
            $sheet->setCellValue("C{$r}", (int) $p['sold']);
            $sheet->setCellValue("D{$r}", $p['revenue']);
            $money[] = "D{$r}";
            $this->row++;
        }

        Activity::log('report', 'صدّر تقرير الإيرادات (Excel)');

        return $this->download($spreadsheet, $sheet, $money, 'revenue-report-'.Reports::salesSlug($report).'-'.now()->format('Y-m-d').'.xlsx');
    }

    public function productsXlsx()
    {
        $spreadsheet = new Spreadsheet;
        [$sheet, $title, $head] = $this->sheet($spreadsheet, __('المنتجات'));

        $firstDataRow = $this->tableHead($sheet, [__('المعرّف'), __('الاسم'), __('القسم'), 'SKU', __('الباركود'), $this->moneyHead('السعر'), $this->moneyHead('التكلفة'), __('الكمية'), __('حد التنبيه'), __('حالة المخزون'), __('الحالة')]);
        $money = [];
        foreach (Demo::products(null, request()) as $p) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", (int) $p['id']);
            $sheet->setCellValue("B{$r}", $p['name']);
            $sheet->setCellValue("C{$r}", $p['cat']);
            $sheet->setCellValueExplicit("D{$r}", (string) $p['sku'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("E{$r}", (string) $p['barcode'], DataType::TYPE_STRING);
            $sheet->setCellValue("F{$r}", round((float) $p['price'], 3));
            $sheet->setCellValue("G{$r}", round((float) $p['cost'], 3));
            $sheet->setCellValue("H{$r}", (int) $p['qty']);
            $sheet->setCellValue("I{$r}", (int) $p['alert']);
            $sheet->setCellValue("J{$r}", $p['stock_status']);
            $sheet->setCellValue("K{$r}", $p['active'] ? __('مفعّل') : __('معطّل'));
            $money[] = "F{$r}";
            $money[] = "G{$r}";
            $this->row++;
        }

        // تجميد الترويسة حتى تبقى أسماء الأعمدة ظاهرة عند التمرير
        $sheet->freezePane("A{$firstDataRow}");

        Activity::log('report', 'صدّر المنتجات (Excel)');

        return $this->download($spreadsheet, $sheet, $money, 'products-'.now()->format('Y-m-d').'.xlsx');
    }

    /** تصدير جرد المخزون كملف Excel حقيقي (xlsx) */
    public function inventoryXlsx()
    {
        $spreadsheet = new Spreadsheet;
        [$sheet, $title, $head] = $this->sheet($spreadsheet, __('جرد المخزون'), null, perBranch: true);

        $firstDataRow = $this->tableHead($sheet, [__('المعرّف'), __('المنتج'), 'SKU', __('الكمية الحالية'), __('الحد الأدنى'), $this->moneyHead('القيمة'), __('حالة المخزون'), __('آخر تحديث')]);
        $money = [];

        foreach (Demo::inventory() as $i) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", (int) $i['id']);
            $sheet->setCellValue("B{$r}", $i['name']);
            $sheet->setCellValueExplicit("C{$r}", (string) $i['sku'], DataType::TYPE_STRING);
            $sheet->setCellValue("D{$r}", (int) $i['qty']);
            $sheet->setCellValue("E{$r}", (int) $i['min']);
            $sheet->setCellValue("F{$r}", round((float) $i['value'], 3));
            $sheet->setCellValue("G{$r}", $i['status']);
            $sheet->setCellValue("H{$r}", $i['updated']);
            $money[] = "F{$r}";

            // إبراز الأصناف المنخفضة أو المنتهية بلون تحذيري
            if ((int) $i['qty'] <= 0) {
                $sheet->getStyle("A{$r}:H{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDE8E8');
            } elseif ((int) $i['qty'] <= (int) $i['min']) {
                $sheet->getStyle("A{$r}:H{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF3E2');
            }
            $this->row++;
        }

        $sheet->freezePane("A{$firstDataRow}");

        Activity::log('report', 'صدّر جرد المخزون (Excel)');

        return $this->download($spreadsheet, $sheet, $money, 'inventory-'.now()->format('Y-m-d').'.xlsx');
    }

    /** تصدير المعاملات المالية كملف Excel حقيقي (xlsx) */
    public function financeXlsx()
    {
        /*
         * نصفا الملفّ على فترةٍ واحدة.
         *
         * كانت المؤشّرات تُقرأ بلا فترةٍ فتسقط على الشهر، والجدولُ بلا فترةٍ
         * فيسقط على كلّ الفترات — فيقرأ التاجر «الدخل ١٠٠» فوق جدولٍ مجموعُه
         * ألف، ولا سطر في الورقة يقول إنهما لا يقيسان الشيء نفسه. ولم يكن
         * الملفّ يقبل فترةً أصلًا: يخرج بفترته الخاصّة مهما اختار.
         */
        $range = $this->range();

        $spreadsheet = new Spreadsheet;
        [$sheet, $title, $head] = $this->sheet($spreadsheet, __('المعاملات المالية'), $range);
        $money = [];

        // المؤشرات المالية
        $title(__('المؤشرات المالية'));
        $head([__('المؤشر'), __('القيمة'), __('التغيّر')]);
        foreach (Demo::financeStats($range) as $st) {
            $sheet->fromArray([$st['label'], $st['value'], $st['trend'] ?? '—'], null, 'A'.$this->row);
            $this->row++;
        }
        $this->row++;

        // المعاملات
        // و«الحالة» عمودٌ في الملفّ: الملغاة تُوسم كما تُوسم في الشاشة
        $firstDataRow = $this->tableHead($sheet, [__('المرجع'), __('التاريخ'), __('البيان'), __('الوسيلة'), __('النوع'), $this->moneyHead('المبلغ'), __('الموظف'), __('الحالة')]);
        // بلا سقف: هذا هو الباب إلى الدفتر كاملًا (انظر Demo::transactions)
        foreach (Demo::transactions($range, null) as $t) {
            $r = $this->row;
            $sheet->setCellValueExplicit("A{$r}", (string) $t['id'], DataType::TYPE_STRING);
            $sheet->setCellValue("B{$r}", $t['date']);
            $sheet->setCellValue("C{$r}", $t['description']);
            $sheet->setCellValue("D{$r}", $t['method']);
            $sheet->setCellValue("E{$r}", $t['type']);
            $sheet->setCellValue("F{$r}", round((float) $t['amount'], 3));
            $sheet->setCellValue("G{$r}", $t['employee']);
            $sheet->setCellValue("H{$r}", $t['cancelled'] ? __('ملغاة') : '—');
            $money[] = "F{$r}";

            // والملغاة أوّلًا: صفٌّ لا يُجمع لا يُقرأ كصفٍّ يُجمع
            if ($t['cancelled']) {
                $sheet->getStyle("A{$r}:H{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF3C7');
            } elseif ($t['type'] !== 'دخل') {
                // تمييز المصروفات بالأحمر الفاتح لتُقرأ بنظرة
                $sheet->getStyle("A{$r}:H{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDF0F0');
            }
            $this->row++;
        }
        $sheet->freezePane("A{$firstDataRow}");

        Activity::log('report', 'صدّر المعاملات المالية (Excel)');

        return $this->download($spreadsheet, $sheet, $money, 'finance-'.now()->format('Y-m-d').'.xlsx');
    }

    /** تصدير المصروفات كملف Excel */
    public function expensesXlsx()
    {
        $spreadsheet = new Spreadsheet;
        [$sheet] = $this->sheet($spreadsheet, __('المصروفات'));
        $firstDataRow = $this->tableHead($sheet, [__('التاريخ'), __('النوع'), __('الوصف'), $this->moneyHead('المبلغ'), __('الطريقة'), __('الموظف')]);
        $money = [];
        foreach (Demo::expenses(request()) as $e) {
            $r = $this->row;
            $sheet->setCellValue("A{$r}", $e['date']);
            $sheet->setCellValue("B{$r}", $e['type']);
            $sheet->setCellValue("C{$r}", $e['description']);
            $sheet->setCellValue("D{$r}", round((float) $e['amount'], 3));
            $sheet->setCellValue("E{$r}", $e['method']);
            $sheet->setCellValue("F{$r}", $e['employee']);
            $money[] = "D{$r}";
            $this->row++;
        }
        $sheet->freezePane("A{$firstDataRow}");
        Activity::log('report', 'صدّر المصروفات (Excel)');

        return $this->download($spreadsheet, $sheet, $money, 'expenses-'.now()->format('Y-m-d').'.xlsx');
    }
}
