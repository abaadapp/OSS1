<?php

namespace App\Http\Controllers;

use App\Support\Demo;
use App\Support\Reports;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * تصدير البيانات إلى CSV (متوافق مع Excel — يدعم العربية عبر BOM UTF-8).
 * يعتمد على طبقة Demo نفسها التي تعرض البيانات (محدودة بالمستأجر/الفرع).
 */
class ExportController extends Controller
{
    /* ------------------------------ النشاط التجاري ------------------------------ */

    public function reports()
    {
        // الفترة التي كان التاجر ينظر إليها — الملفّ يغادر الشاشة ولا يصحّحه
        // مبدّلٌ فوقه، فيحملها في أوّل سطرٍ منه وفي اسمه. والحمولة من مصدر
        // الشاشة نفسه — انظر Support\Reports::salesReport
        $report = Reports::salesReport(request()->query('range'));
        $range = $report['range'];

        $rows = [];
        $rows[] = [__('الفترة'), Demo::rangeLabel($range), ''];
        $rows[] = ['', '', ''];
        $rows[] = [__('— المؤشرات الرئيسية —'), '', ''];
        $rows[] = [__('المؤشر'), __('القيمة'), ''];
        foreach (Reports::summaryRows($report['summary']) as $s) {
            $rows[] = [$s['label'], $s['money'] ? number_format((float) $s['value'], 3, '.', '') : $s['value'], ''];
        }
        $rows[] = ['', '', ''];
        $rows[] = [__('— المبيعات —'), '', ''];
        $rows[] = [__('الفترة'), __('المبيعات'), __('عدد الطلبات')];
        $series = $report['salesSeries'];
        foreach ($series['full'] as $i => $label) {
            // ما لم يأتِ بعدُ لا يُكتب: صفٌّ بصفرٍ عن يوم غدٍ رقمٌ لا واقعة
            if (($series['data'][$i] ?? null) === null) {
                continue;
            }
            $rows[] = [$label, number_format((float) $series['data'][$i], 3, '.', ''), (int) ($series['counts'][$i] ?? 0)];
        }
        $rows[] = ['', '', ''];
        $rows[] = [__('— توزيع وسائل الدفع —'), '', ''];
        $rows[] = [__('الوسيلة'), __('الإجمالي'), __('عدد العمليات')];
        foreach (Demo::paymentBreakdown($range) as $m) {
            $rows[] = [$m['name'], number_format((float) $m['total'], 3, '.', ''), $m['count']];
        }
        $rows[] = ['', '', ''];
        $rows[] = [__('— الأكثر مبيعًا —'), '', ''];
        $rows[] = [__('المنتج'), __('المُباع'), __('الإيراد')];
        foreach ($report['topSellingProducts'] as $p) {
            $rows[] = [$p['name'], $p['sold'], number_format((float) $p['revenue'], 3, '.', '')];
        }

        return $this->stream('sales-report-'.$range, [__('العنصر'), __('القيمة 1'), __('القيمة 2')], $rows);
    }

    public function products()
    {
        $rows = array_map(fn ($p) => [
            $p['id'], $p['name'], $p['cat'], $p['sku'], $p['barcode'],
            number_format($p['price'], 3, '.', ''), number_format($p['cost'], 3, '.', ''),
            $p['qty'], $p['alert'], $p['stock_status'], $p['active'] ? __('مفعّل') : __('معطّل'),
        ], Demo::products(null, request()));

        return $this->stream('products', [__('المعرّف'), __('الاسم'), __('القسم'), 'SKU', __('الباركود'), __('السعر'), __('التكلفة'), __('الكمية'), __('حد التنبيه'), __('حالة المخزون'), __('الحالة')], $rows);
    }

    public function customers()
    {
        $rows = array_map(fn ($c) => [
            $c['id'], $c['name'], $c['phone'], $c['email'], $c['orders'],
            number_format($c['total_spent'], 3, '.', ''), $c['last_order'],
        ], Demo::customers(request()));

        // العددُ والإنفاقُ والأخيرُ من فواتيره الصادرة (`Demo::customers`) — فتُسمّى بها
        return $this->stream('customers', [__('المعرّف'), __('الاسم'), __('الهاتف'), __('البريد'), __('عدد الفواتير'), __('إجمالي الإنفاق'), __('آخر فاتورة')], $rows);
    }

    /**
     * الموردون — قائمةُ أسماءٍ وأرقامِ تواصل كالعملاء، فتُصدَّر مثلهم.
     *
     * وعدد أوامر الشراء عمودٌ فيها: هو السؤال الأوّل عن أي مورّد — من
     * يُشترى منه فعلًا، ومن بقي اسمًا بلا أمرٍ واحد.
     */
    public function suppliers()
    {
        $rows = array_map(fn ($s) => [
            $s['id'], $s['name'], $s['service'] ?? '', $s['phone'] ?? '', $s['email'] ?? '',
            $s['contact'] ?? '', $s['orders_count'],
        ], Demo::suppliers());

        return $this->stream('suppliers', [__('المعرّف'), __('الاسم'), __('الخدمة / النشاط'), __('الهاتف'), __('البريد'), __('مسؤول التواصل'), __('أوامر الشراء')], $rows);
    }

    public function transactions()
    {
        // الفترة تتبع الشاشة كما في أخواتها — انظر ReportExportController::financeXlsx
        $range = Demo::range(request()->query('range'));

        $rows = array_map(fn ($t) => [
            $t['id'], $t['date'], $t['description'], $t['method'], $t['type'],
            number_format($t['amount'], 3, '.', ''), $t['employee'],
            // والملغاة تُوسم في الملفّ كما تُوسم في الشاشة — انظر Demo::transactions
            $t['cancelled'] ? __('ملغاة') : '—',
        ], Demo::transactions($range, null));   // بلا سقف: الملفّ هو الدفتر كاملًا

        return $this->stream('transactions', [__('المرجع'), __('التاريخ'), __('الوصف'), __('الطريقة'), __('النوع'), __('المبلغ'), __('الموظف'), __('الحالة')], $rows);
    }

    public function expenses()
    {
        $rows = array_map(fn ($e) => [
            $e['date'], $e['type'], $e['description'],
            number_format($e['amount'], 3, '.', ''), $e['method'], $e['employee'],
        ], Demo::expenses(request()));

        return $this->stream('expenses', [__('التاريخ'), __('النوع'), __('الوصف'), __('المبلغ'), __('الطريقة'), __('الموظف')], $rows);
    }

    public function inventory()
    {
        $rows = array_map(fn ($p) => [
            $p['id'], $p['name'], $p['sku'], $p['qty'], $p['min'],
            number_format($p['value'], 3, '.', ''), $p['status'], $p['updated'],
        ], Demo::inventory());

        return $this->stream('inventory', [__('المعرّف'), __('المنتج'), 'SKU', __('الكمية الحالية'), __('الحد الأدنى'), __('القيمة'), __('حالة المخزون'), __('آخر تحديث')], $rows);
    }

    

    /* ------------------------------ المولّد ------------------------------ */

    private function stream(string $name, array $headers, array $rows): StreamedResponse
    {
        $filename = "eventplus-{$name}-".now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM لدعم العربية في Excel
            /*
             * و`escape` يُمرَّر فارغًا صراحةً — لا يُترك لافتراضِ PHP.
             *
             * الافتراضيُّ شرطةٌ مائلة، وليس من CSV في شيء: حقلٌ فيه شرطةٌ
             * قبل علامة اقتباس — كمقاس «5 بوصة» يُكتب بعلامةٍ بعد شرطة —
             * يُكتب فيقرؤه إكسل ومَن سواه محرَّفًا. القيمةُ تتبدّل بصمتٍ
             * ولا شيء يقول إنّها تبدّلت. والقياسيُّ (RFC 4180) لا escape
             * فيه: الاقتباسُ يُضاعَف.
             *
             * وPHP 8.4 تُحذّر من تركه، وPHP 9 تجعل الفارغَ افتراضًا —
             * فتمريرُه اليوم يُصلح التلفَ ويُثبّت السلوك قبل أن يتبدّل.
             */
            fputcsv($out, $headers, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map([\App\Support\Sheet::class, 'guard'], $row), escape: '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
