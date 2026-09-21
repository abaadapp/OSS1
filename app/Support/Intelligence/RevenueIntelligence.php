<?php

namespace App\Support\Intelligence;

use App\Models\ChangeOrder;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\Quotation;
use App\Support\Revenue;

/**
 * الإيرادُ بتعريفاته الخمسة — ولا تُخلط. المرحلة 11.
 *
 * • **المعروض** — قيمةُ ما خرج من عروضٍ في المدّة (أُرسل، بأيّ مصيرٍ بعده).
 * • **المتعاقَد** — ما قُبل في المدّة: عروضٌ قُبلت، وفروقُ أوامرِ تغييرٍ قُبلت.
 * • **المفوتَر** — فواتيرُ صادرة (`Revenue`).
 * • **المحصَّل** — دفعاتٌ قُبضت.
 * • **الذممُ** — ما بقي على الفواتير الصادرة.
 *
 * والقمعُ التجاريّ من دورة العرض نفسِها: مسودّةٌ لا تُعدّ إيرادًا محتمَلًا،
 * والمنتهيةُ تُشتقّ من `valid_until` كما في الشاشة.
 */
final class RevenueIntelligence
{
    /** @return array<string, mixed> */
    public static function ladder(int $bid, Period $period): array
    {
        $quoted = Quotation::where('business_id', $bid)
            ->whereIn('status', [Quotation::SENT, Quotation::ACCEPTED, Quotation::REJECTED, Quotation::SUPERSEDED])
            ->whereNotNull('sent_at')->where('sent_at', '>=', $period->startAt())->where('sent_at', '<', $period->endAt())
            ->toBase()->selectRaw('COALESCE(SUM(total - tax_total), 0) AS s, COUNT(*) AS c')->first();

        /* الدرجاتُ الخمس على وعاءٍ واحد — صافيةً من الضريبة كالمفوتَر — وإلّا قورن إجماليٌّ بصافٍ */
        $won = Quotation::where('business_id', $bid)->where('status', Quotation::ACCEPTED)
            ->whereNotNull('decided_at')->where('decided_at', '>=', $period->startAt())->where('decided_at', '<', $period->endAt())
            ->toBase()->selectRaw('COALESCE(SUM(total - tax_total), 0) AS s, COUNT(*) AS c')->first();

        $changes = ChangeOrder::where('business_id', $bid)->where('status', ChangeOrder::ACCEPTED)
            ->whereNotNull('decided_at')->where('decided_at', '>=', $period->startAt())->where('decided_at', '<', $period->endAt())
            ->toBase()->selectRaw('COALESCE(SUM(total_delta - tax_delta), 0) AS s, COUNT(*) AS c')->first();

        $revenue = Revenue::summary($bid, $period->start, $period->end);
        $collected = FinancialPerformance::collections($bid, $period);
        $receivables = FinancialPerformance::receivables($bid);

        return [
            'quoted' => ['value' => round((float) ($quoted->s ?? 0), 3), 'count' => (int) ($quoted->c ?? 0),
                'basis' => __('عروضٌ أُرسلت في المدّة — صافيةً من الضريبة')],
            'contracted' => ['value' => round((float) ($won->s ?? 0) + (float) ($changes->s ?? 0), 3), 'quotations' => (int) ($won->c ?? 0),
                'change_orders' => (int) ($changes->c ?? 0), 'basis' => __('عروضٌ قُبلت وفروقُ أوامرِ تغييرٍ قُبلت في المدّة — صافيةً من الضريبة')],
            'invoiced' => ['value' => $revenue['net'], 'gross' => $revenue['total'], 'count' => $revenue['count'],
                'basis' => __('فواتيرُ صادرة — صافيةً من الضريبة وبعد إشعارات الدائن')],
            'collected' => ['value' => $collected['total'], 'count' => $collected['count'], 'basis' => __('دفعاتٌ حيّة قُبضت في المدّة')],
            'outstanding' => ['value' => $receivables['outstanding'], 'overdue' => $receivables['overdue'],
                'basis' => __('الذممُ القائمة الآن — لا تتبع المدّة')],
        ];
    }

    /**
     * القمعُ التجاريّ للعروض المنشأة في المدّة.
     *
     * @return array<string, mixed>
     */
    public static function funnel(int $bid, Period $period): array
    {
        $base = Quotation::where('business_id', $bid)
            ->where('created_at', '>=', $period->startAt())->where('created_at', '<', $period->endAt());

        $rows = (clone $base)->toBase()->selectRaw('status, COUNT(*) AS c, COALESCE(SUM(total), 0) AS s')->groupBy('status')->get()->keyBy('status');

        /* والمنتهيةُ تُشتقّ: مُرسَلةٌ فات تاريخُ صلاحيّتها */
        $expired = (clone $base)->where('status', Quotation::SENT)->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', now()->toDateString())
            ->toBase()->selectRaw('COUNT(*) AS c, COALESCE(SUM(total), 0) AS s')->first();
        $viewed = (clone $base)->whereNotNull('first_viewed_at')->count();

        $stage = fn (string $status) => ['count' => (int) ($rows[$status]->c ?? 0), 'value' => round((float) ($rows[$status]->s ?? 0), 3)];
        $sent = $stage(Quotation::SENT);
        $expiredStage = ['count' => (int) ($expired->c ?? 0), 'value' => round((float) ($expired->s ?? 0), 3)];
        $openSent = ['count' => max(0, $sent['count'] - $expiredStage['count']), 'value' => round(max(0, $sent['value'] - $expiredStage['value']), 3)];

        $accepted = $stage(Quotation::ACCEPTED);
        $rejected = $stage(Quotation::REJECTED);
        $decided = $accepted['count'] + $rejected['count'] + $expiredStage['count'];

        $avgDays = (clone $base)->where('status', Quotation::ACCEPTED)->whereNotNull('sent_at')->whereNotNull('decided_at')
            ->get(['sent_at', 'decided_at'])
            ->map(fn ($q) => $q->sent_at->diffInHours($q->decided_at) / 24);

        $total = (clone $base)->count();
        $value = round((float) (clone $base)->sum('total'), 3);

        return [
            'period' => $period->toArray(),
            'stages' => [
                ['key' => 'draft', 'label' => __('مسودّة'), 'committed' => false] + $stage(Quotation::DRAFT),
                ['key' => 'pending_approval', 'label' => __('تنتظر اعتمادًا'), 'committed' => false] + $stage(Quotation::PENDING_APPROVAL),
                ['key' => 'sent', 'label' => __('مُرسَلة'), 'committed' => false] + $openSent,
                ['key' => 'viewed', 'label' => __('فُتحت'), 'committed' => false, 'count' => $viewed, 'value' => null],
                ['key' => 'accepted', 'label' => __('مقبولة'), 'committed' => true] + $accepted,
                ['key' => 'rejected', 'label' => __('مرفوضة'), 'committed' => false] + $rejected,
                ['key' => 'expired', 'label' => __('منتهية'), 'committed' => false] + $expiredStage,
                ['key' => 'cancelled', 'label' => __('ملغاة'), 'committed' => false] + $stage(Quotation::CANCELLED),
                ['key' => 'superseded', 'label' => __('نُقّحت'), 'committed' => false] + $stage(Quotation::SUPERSEDED),
            ],
            'total' => ['count' => $total, 'value' => $value],
            'acceptance_rate' => $decided > 0 ? round($accepted['count'] / $decided * 100, 1) : null,
            'acceptance_basis' => __('المقبولة من كلّ ما حُسم: مقبولة + مرفوضة + منتهية'),
            'average_value' => $total > 0 ? round($value / $total, 3) : null,
            'average_days_to_accept' => $avgDays->isEmpty() ? null : round((float) $avgDays->avg(), 1),
            'decided' => $decided,
        ];
    }

    /**
     * ما فُوتر على العملاء في المدّة بالعميل — صافيًا.
     *
     * @return array<int, array{customer_id: int, net: float, gross: float, invoices: int}>
     */
    public static function invoicedByCustomer(int $bid, Period $period): array
    {
        $rows = Revenue::query($bid, $period->start, $period->end)->toBase()->whereNotNull('customer_id')
            ->selectRaw('customer_id, COALESCE(SUM(subtotal - discount_total), 0) AS n, COALESCE(SUM(total), 0) AS g, COUNT(*) AS c')
            ->groupBy('customer_id')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->customer_id => ['customer_id' => (int) $r->customer_id, 'net' => round((float) $r->n, 3), 'gross' => round((float) $r->g, 3), 'invoices' => (int) $r->c]])
            ->all();

        /*
         * وإشعاراتُ الدائن تُنقص حصّةَ صاحبها — كما تُنقص إيرادَ الشركة في
         * `Revenue::summary`. كانت تُغفَل هنا فيفترق العميلُ عن الشركة بمقدار
         * ما رُدّ له (المرحلة 12 §21).
         */
        $credits = CustomerCreditNote::query()->toBase()
            ->where('customer_credit_notes.business_id', $bid)
            ->whereDate('customer_credit_notes.issued_at', '>=', $period->startDate())->whereDate('customer_credit_notes.issued_at', '<', $period->endDate())
            ->join('customer_invoices', 'customer_invoices.id', '=', 'customer_credit_notes.customer_invoice_id')
            ->whereNotNull('customer_invoices.customer_id')
            ->selectRaw('customer_invoices.customer_id AS cid, COALESCE(SUM(customer_credit_notes.amount - customer_credit_notes.tax_amount), 0) AS n, COALESCE(SUM(customer_credit_notes.amount), 0) AS g')
            ->groupBy('customer_invoices.customer_id')->get();
        foreach ($credits as $c) {
            $id = (int) $c->cid;
            $rows[$id] ??= ['customer_id' => $id, 'net' => 0.0, 'gross' => 0.0, 'invoices' => 0];
            $rows[$id]['net'] = round($rows[$id]['net'] - (float) $c->n, 3);
            $rows[$id]['gross'] = round($rows[$id]['gross'] - (float) $c->g, 3);
        }

        return $rows;
    }

    /** المحصَّلُ في المدّة بالعميل */
    public static function collectedByCustomer(int $bid, Period $period): array
    {
        return Revenue::collections($bid, $period->startAt(), $period->endAt())->toBase()->whereNotNull('customer_payments.customer_id')
            ->selectRaw('customer_payments.customer_id AS cid, COALESCE(SUM(customer_payments.amount), 0) AS s')
            ->groupBy('customer_payments.customer_id')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->cid => round((float) $r->s, 3)])->all();
    }

    /** الذممُ القائمة بالعميل — الآن */
    public static function outstandingByCustomer(int $bid): array
    {
        return CustomerInvoice::where('business_id', $bid)->where('status', CustomerInvoice::ISSUED)->toBase()
            ->selectRaw('customer_id, COALESCE(SUM('.CustomerInvoice::outstandingSql().'), 0) AS o')
            ->selectRaw('COALESCE(SUM(CASE WHEN due_at IS NOT NULL AND due_at < ? THEN '.CustomerInvoice::outstandingSql().' ELSE 0 END), 0) AS od', [now()->toDateString()])
            ->groupBy('customer_id')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->customer_id => ['outstanding' => round(max(0, (float) $r->o), 3), 'overdue' => round(max(0, (float) $r->od), 3)]])->all();
    }
}
