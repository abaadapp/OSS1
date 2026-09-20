<?php

namespace App\Support\Intelligence;

use App\Models\Product;
use App\Models\ProjectItem;
use App\Models\QuotationItem;
use App\Support\Money;
use App\Support\Revenue;

/**
 * الخدماتُ والأصنافُ بالأرقام — من الكتالوج الفعليّ لا من قائمةٍ مكتوبة. المرحلة 11.
 *
 * لكلّ صنفٍ: كم مرّةً عُرض وبكم، وكم قُبل، وكم فُوتر (حيث يُتتبَّع بمعرّفه)،
 * وفي كم مشروعٍ استُعمل، ومتوسّطُ سعر بيعه، وسلوكُ الخصم، ومساهمتُه حيث
 * تُعرف تكلفتُه (`products.cost` — لقطةُ اليوم، تقديرٌ لا قيد).
 */
final class ServiceIntelligence
{
    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public static function list(int $bid, Period $period, int $limit = 200): array
    {
        $quoted = QuotationItem::query()->toBase()
            ->join('quotations', 'quotations.id', '=', 'quotation_items.quotation_id')
            ->where('quotations.business_id', $bid)->whereNotNull('quotation_items.product_id')
            ->whereIn('quotations.status', ['sent', 'accepted', 'rejected', 'superseded'])
            ->whereNotNull('quotations.sent_at')->where('quotations.sent_at', '>=', $period->startAt())->where('quotations.sent_at', '<', $period->endAt())
            ->selectRaw('quotation_items.product_id AS pid, COUNT(DISTINCT quotations.id) AS quotations, COALESCE(SUM(quotation_items.quantity), 0) AS qty')
            ->selectRaw('COALESCE(SUM(quotation_items.line_total - quotation_items.tax_amount), 0) AS value')
            ->selectRaw("COALESCE(SUM(CASE WHEN quotations.status = 'accepted' THEN quotation_items.quantity ELSE 0 END), 0) AS accepted_qty")
            ->selectRaw("COALESCE(SUM(CASE WHEN quotations.status = 'accepted' THEN quotation_items.line_total - quotation_items.tax_amount ELSE 0 END), 0) AS accepted_value")
            ->selectRaw('COALESCE(SUM(quotation_items.discount), 0) AS discount, COALESCE(SUM(quotation_items.list_price * quotation_items.quantity), 0) AS list_value')
            ->groupBy('quotation_items.product_id')->get()->keyBy('pid');

        $invoiced = Revenue::items($bid, $period->start, $period->end)
            ->whereNotNull('customer_invoice_items.product_id')
            ->selectRaw('customer_invoice_items.product_id AS pid, COALESCE(SUM(customer_invoice_items.line_total - customer_invoice_items.tax_amount), 0) AS value, COALESCE(SUM(customer_invoice_items.quantity), 0) AS qty, COUNT(DISTINCT customer_invoice_items.customer_invoice_id) AS invoices')
            ->groupBy('customer_invoice_items.product_id')->get()->keyBy('pid');

        $projects = ProjectItem::query()->toBase()
            ->join('projects', 'projects.id', '=', 'project_items.project_id')
            ->where('projects.business_id', $bid)->whereNotNull('project_items.product_id')
            ->whereDate('projects.created_at', '>=', $period->startDate())->whereDate('projects.created_at', '<', $period->endDate())
            ->selectRaw('project_items.product_id AS pid, COUNT(DISTINCT projects.id) AS n')
            ->groupBy('project_items.product_id')->get()->keyBy('pid');

        $ids = $quoted->keys()->merge($invoiced->keys())->merge($projects->keys())->unique()->values();
        $products = Product::where('business_id', $bid)->whereIn('id', $ids)->get(['id', 'name', 'kind', 'cost', 'price'])->keyBy('id');

        $rows = $ids->map(function ($id) use ($products, $quoted, $invoiced, $projects) {
            $p = $products[$id] ?? null;
            $q = $quoted[$id] ?? null;
            $i = $invoiced[$id] ?? null;
            $qty = (float) ($i->qty ?? 0);
            $cost = $p !== null && $p->cost !== null && (float) $p->cost > 0 ? (float) $p->cost : null;
            $listValue = (float) ($q->list_value ?? 0);

            return [
                'product_id' => (int) $id,
                'name' => $p->name ?? '—',
                'kind' => $p->kind ?? null,
                'quotations' => (int) ($q->quotations ?? 0),
                'quoted_quantity' => round((float) ($q->qty ?? 0), 3),
                'quoted_value' => round((float) ($q->value ?? 0), 3),
                'accepted_quantity' => round((float) ($q->accepted_qty ?? 0), 3),
                'accepted_value' => round((float) ($q->accepted_value ?? 0), 3),
                'invoiced_value' => round((float) ($i->value ?? 0), 3),
                'invoiced_quantity' => round($qty, 3),
                'invoices' => (int) ($i->invoices ?? 0),
                'projects' => (int) ($projects[$id]->n ?? 0),
                'average_selling_price' => $qty > 0 ? round((float) $i->value / $qty, 3) : null,
                'discount_percent' => $listValue > 0 ? round((float) $q->discount / $listValue * 100, 1) : null,
                'unit_cost' => $cost,
                'contribution' => $cost !== null && $qty > 0 ? round((float) $i->value - $cost * $qty, 3) : null,
                'contribution_basis' => $cost !== null ? __('تكلفةُ الصنف اليوم × الكميّة المفوتَرة — تقديرٌ لا قيد') : __('لا تكلفةَ مسجَّلة على الصنف'),
                'url' => route('admin.products.show', $id),
            ];
        })->sortByDesc('invoiced_value')->take($limit)->values();

        return [
            'rows' => $rows->all(),
            'totals' => [
                'products' => $rows->count(),
                'quoted_value' => round((float) $rows->sum('quoted_value'), 3),
                'accepted_value' => round((float) $rows->sum('accepted_value'), 3),
                'invoiced_value' => round((float) $rows->sum('invoiced_value'), 3),
            ],
            'period' => $period->toArray(),
            'currency' => Money::of($bid),
            'basis' => __('المعروضُ من بنود عروضٍ أُرسلت في المدّة؛ والمفوتَر من بنود فواتيرَ صادرة فيها بمعرّف الصنف — والبندُ الحرّ بلا صنفٍ لا يُنسب'),
        ];
    }
}
