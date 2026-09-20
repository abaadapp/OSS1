<?php

namespace App\Support\Intelligence;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPaymentAllocation;
use App\Models\Project;
use App\Models\Quotation;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * العملاءُ بالأرقام — وقائعُ لا أحكام. المرحلة 11.
 *
 * لا «عميلٌ جيّد» ولا «عميلٌ سيّئ» ولا درجةٌ تُخترع: عروضٌ ونسبةُ قبولٍ
 * ومتعاقَدٌ ومفوتَرٌ ومحصَّلٌ وذممٌ ومتأخّرٌ ومشاريعُ وأوّلُ تعاملٍ وآخرُه.
 * ومن أراد الحكمَ حكم بنفسه.
 *
 * والتركيزُ يُقاس ولا يُصنَّف: حصّةُ أكبر عميلٍ وأكبرِ خمسةٍ وعشرةٍ من
 * الإيراد الصافي في المدّة — وعتبةُ «الخطر» تضبطها الشركةُ لا الكود.
 */
final class CustomerIntelligence
{
    /**
     * صفٌّ لكلّ عميلٍ في المدّة — تجميعاتٌ في القاعدة ثمّ ضمٌّ بالمعرّف.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public static function list(int $bid, Period $period, int $limit = 200): array
    {
        $invoiced = RevenueIntelligence::invoicedByCustomer($bid, $period);
        $collected = RevenueIntelligence::collectedByCustomer($bid, $period);
        $outstanding = RevenueIntelligence::outstandingByCustomer($bid);

        $quotes = Quotation::where('business_id', $bid)->whereNotNull('customer_id')
            ->where('created_at', '>=', $period->startAt())->where('created_at', '<', $period->endAt())
            ->toBase()->selectRaw('customer_id, COUNT(*) AS c')
            ->selectRaw("SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) AS a")
            ->selectRaw("SUM(CASE WHEN status IN ('accepted','rejected') THEN 1 ELSE 0 END) AS d")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'accepted' THEN total - tax_total ELSE 0 END), 0) AS won")
            ->groupBy('customer_id')->get()->keyBy('customer_id');

        $projects = Project::where('business_id', $bid)->whereNotIn('status', [Project::DRAFT, Project::CANCELLED])
            ->whereDate('created_at', '>=', $period->startDate())->whereDate('created_at', '<', $period->endDate())
            ->toBase()->selectRaw('customer_id, COUNT(*) AS c, COALESCE(SUM(approved_value), 0) AS v')
            ->selectRaw("SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) AS done")
            ->groupBy('customer_id')->get()->keyBy('customer_id');

        $firstLast = CustomerInvoice::where('business_id', $bid)->where('status', CustomerInvoice::ISSUED)->where('is_opening_balance', false)
            ->toBase()->selectRaw('customer_id, MIN(issued_at) AS f, MAX(issued_at) AS l')->groupBy('customer_id')->get()->keyBy('customer_id');

        $ids = collect(array_keys($invoiced))->merge($quotes->keys())->merge($projects->keys())->merge(array_keys($outstanding))
            ->unique()->filter()->values();
        $customers = Customer::where('business_id', $bid)->whereIn('id', $ids)->get(['id', 'name'])->keyBy('id');

        $rows = $ids->map(function ($id) use ($customers, $invoiced, $collected, $outstanding, $quotes, $projects, $firstLast) {
            $q = $quotes[$id] ?? null;
            $p = $projects[$id] ?? null;
            $decided = (int) ($q->d ?? 0);
            $pc = (int) ($p->c ?? 0);

            return [
                'customer_id' => (int) $id,
                'customer' => $customers[$id]->name ?? '—',
                'quotations' => (int) ($q->c ?? 0),
                'accepted' => (int) ($q->a ?? 0),
                'acceptance_rate' => $decided > 0 ? round((int) ($q->a ?? 0) / $decided * 100, 1) : null,
                'contracted' => round((float) ($q->won ?? 0), 3),
                'invoiced' => $invoiced[$id]['net'] ?? 0.0,
                'invoices' => $invoiced[$id]['invoices'] ?? 0,
                'collected' => $collected[$id] ?? 0.0,
                'outstanding' => $outstanding[$id]['outstanding'] ?? 0.0,
                'overdue' => $outstanding[$id]['overdue'] ?? 0.0,
                'projects' => $pc,
                'completed_projects' => (int) ($p->done ?? 0),
                'average_project_value' => $pc > 0 ? round((float) $p->v / $pc, 3) : null,
                'first_invoice_at' => $firstLast[$id]->f ?? null,
                'last_invoice_at' => $firstLast[$id]->l ?? null,
                'url' => route('admin.customers.show', $id),
            ];
        })->sortByDesc('invoiced')->take($limit)->values();

        $total = round((float) $rows->sum('invoiced'), 3);

        return [
            'rows' => $rows->all(),
            'totals' => [
                'customers' => $rows->count(),
                'invoiced' => $total,
                'collected' => round((float) $rows->sum('collected'), 3),
                'outstanding' => round((float) $rows->sum('outstanding'), 3),
                'overdue' => round((float) $rows->sum('overdue'), 3),
            ],
            'period' => $period->toArray(),
            'currency' => Money::of($bid),
            'basis' => __('المفوتَر صافيًا من الضريبة على فواتيرَ صادرة في المدّة؛ والمحصَّل دفعاتٌ قُبضت فيها؛ والذممُ قائمةٌ الآن'),
        ];
    }

    /**
     * تركيزُ الإيراد — حصصٌ لا أحكام.
     *
     * @return array<string, mixed>
     */
    public static function concentration(int $bid, Period $period, ?array $thresholds = null): array
    {
        $thresholds ??= Thresholds::for($bid);
        $by = collect(RevenueIntelligence::invoicedByCustomer($bid, $period))->sortByDesc('net')->values();
        $total = round((float) $by->sum('net'), 3);

        if ($total <= 0.0005) {
            return ['status' => Completeness::INSUFFICIENT, 'reasons' => [__('لا إيرادَ مفوتَرًا في المدّة')], 'total' => 0.0, 'customers' => 0, 'top' => [],
                'top1_percent' => null, 'top5_percent' => null, 'top10_percent' => null, 'threshold_percent' => $thresholds['customer_concentration_percent'], 'above_threshold' => false];
        }

        $names = Customer::where('business_id', $bid)->whereIn('id', $by->pluck('customer_id'))->pluck('name', 'id');
        $share = fn (int $n) => round((float) $by->take($n)->sum('net') / $total * 100, 1);
        $top1 = $share(1);

        return [
            'status' => Completeness::COMPLETE,
            'reasons' => [],
            'total' => $total,
            'customers' => $by->count(),
            'top' => $by->take(10)->map(fn ($r) => ['customer_id' => $r['customer_id'], 'customer' => $names[$r['customer_id']] ?? '—',
                'net' => $r['net'], 'percent' => round($r['net'] / $total * 100, 1), 'url' => route('admin.customers.show', $r['customer_id'])])->values()->all(),
            'top1_percent' => $top1,
            'top5_percent' => $share(5),
            'top10_percent' => $share(10),
            'threshold_percent' => $thresholds['customer_concentration_percent'],
            'above_threshold' => $top1 >= $thresholds['customer_concentration_percent'],
            'basis' => __('حصّةُ كلّ عميل من الإيراد الصافي المفوتَر في المدّة'),
        ];
    }

    /**
     * متوسّطُ تأخّر السداد — أيّامٌ بين الاستحقاق والتحصيل الكامل، للفواتير التي سُدّدت في المدّة.
     */
    public static function averagePaymentDelay(int $bid, Period $period): ?float
    {
        $rows = CustomerPaymentAllocation::query()->toBase()
            ->join('customer_payments', 'customer_payments.id', '=', 'customer_payment_allocations.customer_payment_id')
            ->join('customer_invoices', 'customer_invoices.id', '=', 'customer_payment_allocations.customer_invoice_id')
            ->where('customer_payments.business_id', $bid)->whereNull('customer_payments.cancelled_at')
            ->whereNotNull('customer_invoices.due_at')
            ->where('customer_payments.occurred_at', '>=', $period->startAt())->where('customer_payments.occurred_at', '<', $period->endAt())
            ->get(['customer_invoices.due_at', 'customer_payments.occurred_at']);

        if ($rows->count() < 3) {
            return null;
        }

        return round((float) $rows->avg(fn ($r) => Carbon::parse($r->due_at)->diffInDays(Carbon::parse($r->occurred_at), false)), 1);
    }
}
