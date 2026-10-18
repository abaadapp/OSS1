<?php

namespace App\Support\Exports;

use App\Models\CustomerInvoice;
use App\Support\Demo;
use App\Support\Receivables;
use Illuminate\Http\Request;

/**
 * الذمم المدينة — الفواتيرُ القائمةُ الآن، كما تقرؤها شاشتُها (`Receivables::openInvoices`).
 *
 * لقطةٌ حاليّة لا حركةُ مدّة: ما على العملاء اليومَ بعد كلّ سدادٍ وإشعار دائن.
 * ولا يُقرأ «ما كان عليهم في يومٍ مضى» من هنا — المتبقّي يُحسب من السداد حتى
 * الآن، وإعادتُه إلى تاريخٍ سابق قراءةٌ أخرى لا يدّعيها هذا الملفّ.
 */
final class ReceivablesDataset extends Dataset
{
    public static function slug(): string
    {
        return 'receivables';
    }

    public function title(): string
    {
        return __('الذمم المدينة');
    }

    public function validate(Request $request): void {}

    public function columns(Request $request): array
    {
        return [
            self::col('number', 'رقم الفاتورة'), self::col('customer', 'العميل'), self::col('issued_at', 'تاريخ الإصدار'),
            self::col('due_at', 'الاستحقاق'), self::col('days_overdue', 'أيام التأخّر', 'number'), self::col('state', 'السداد'),
            self::col('total', 'الإجمالي', 'money'), self::col('outstanding', 'المتبقّي', 'money'),
        ];
    }

    public function data(Request $request, int $bid, int $limit): array
    {
        $open = Receivables::openInvoices($bid);
        $rows = $open->take($limit)->map(fn (CustomerInvoice $i) => [
            'number' => $i->number,
            'customer' => $i->customer?->name ?? '—',
            'issued_at' => optional($i->issued_at)->format('Y-m-d'),
            'due_at' => optional($i->due_at)->format('Y-m-d'),
            'days_overdue' => $i->daysOverdue(),
            'state' => $i->paymentState(),
            'total' => round((float) $i->total, 3),
            'outstanding' => $i->outstanding(),
        ])->values()->all();
        $totals = Receivables::totals($bid);

        return [
            'rows' => $rows,
            'total' => $open->count(),
            'totals' => ['total' => round((float) $open->sum('total'), 3), 'outstanding' => round((float) $open->sum(fn ($i) => $i->outstanding()), 3)],
            'cards' => [
                ['label' => __('لك الآن'), 'value' => Demo::moneyBase((float) ($totals['total'] ?? 0))],
                ['label' => __('متأخّر'), 'value' => Demo::moneyBase((float) ($totals['overdue'] ?? 0))],
            ],
        ];
    }

    public function periodLabel(Request $request): string
    {
        return __('لقطة حاليّة حتى :date', ['date' => now()->toDateString()]);
    }
}
