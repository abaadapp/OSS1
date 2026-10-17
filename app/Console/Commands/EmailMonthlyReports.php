<?php

namespace App\Console\Commands;

use App\Mail\MonthlyReportMail;
use App\Models\Business;
use App\Support\Money;
use App\Support\Revenue;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * إرسال تقرير أداء شهري بالبريد لأصحاب المتاجر (بيانات الشهر السابق).
 * يُجدول شهريًا في routes/console.php. تشغيل يدوي: php artisan reports:email
 */
class EmailMonthlyReports extends Command
{
    protected $signature = 'reports:email {--month= : شهر بصيغة YYYY-MM (افتراضي: الشهر السابق)}';

    protected $description = 'إرسال تقرير الأداء الشهري بالبريد لأصحاب المتاجر';

    public function handle(): int
    {
        $ref = $this->option('month')
            ? Carbon::createFromFormat('Y-m', $this->option('month'))->startOfMonth()
            : now()->subMonthNoOverflow()->startOfMonth();
        $start = $ref->copy()->startOfMonth();
        $period = $start->locale('ar')->translatedFormat('F Y');

        $sent = 0;
        Business::whereNotNull('email')->each(function (Business $b) use ($start, $period, &$sent) {
            /*
             * من فواتير العملاء الصادرة (`Revenue`) — كالملخّص اليوميّ.
             *
             * وكان يقرأ الطلبات القديمة: جدولٌ لا يُكتب فيه منذ رُفعت نقطة
             * البيع، فلا يصل التقرير أحدًا مهما أصدرت الشركةُ من فواتير.
             */
            $next = $start->copy()->addMonthNoOverflow();
            $summary = Revenue::summary((int) $b->id, $start, $next);
            $sales = (float) $summary['total'];
            $invoices = (int) $summary['count'];

            if ($invoices === 0 && $sales <= 0) {
                return; // لا نرسل لمتجر بلا نشاط
            }

            // وأكثرُ ما بيع بالوصف: بندُ الفاتورة نصٌّ حرٌّ قد لا يُنسب إلى صنف
            $topItem = Revenue::items((int) $b->id, $start, $next)
                ->selectRaw('customer_invoice_items.description as name, SUM(customer_invoice_items.quantity) as q')
                ->groupBy('customer_invoice_items.description')->orderByDesc('q')->first();

            // وعملةُ صاحب التقرير لا عملةُ من يشغّل الأمر — لا جلسةَ هنا
            $cur = Money::of((int) $b->id);
            $money = fn ($v) => Money::format((float) $v, $cur);
            $stats = [
                ['label' => __('إجمالي المبيعات'), 'value' => $money($sales)],
                ['label' => __('عدد الفواتير'), 'value' => (string) $invoices],
                ['label' => __('متوسط قيمة الفاتورة'), 'value' => $money($invoices ? $sales / $invoices : 0)],
                ['label' => __('المنتج الأكثر مبيعًا'), 'value' => $topItem->name ?? '—'],
            ];

            Mail::to($b->email)->send(new MonthlyReportMail($b->name, $period, $stats, $cur));
            $this->line("✓ {$b->name} → {$b->email}");
            $sent++;
        });

        $this->info($sent ? __('تم إرسال :count تقرير عن :period.', ['count' => $sent, 'period' => $period]) : __('لا توجد شركات ذات نشاط لإرسال تقارير.'));

        return self::SUCCESS;
    }
}
