<?php

namespace App\Support;

use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SupplierInvoice;
use App\Models\User;

/**
 * مشترياتُ المشروع — قراءةٌ فوق أوامر الشراء الحقيقيّة، لا نسخةٌ ثانية.
 *
 * ═══ المستندُ واحد ═══
 *
 * أمرُ الشراء على المشروع هو أمرُ الشراء نفسُه في قسم المشتريات
 * (`purchase_orders.project_id`): يُنشأ من شاشته ويُستلم من شاشته ويُفوتَر من
 * سنداته. وهذا التبويبُ يقرؤه مجمَّعًا للمشروع — عددٌ وقيمةٌ واستلامٌ وسنداتٌ
 * وموردون — ولا يكتب شيئًا.
 *
 * ═══ والمالُ لمن يملكه ═══
 *
 * المدفوعُ والمتبقّي على سندات المورّدين يُقرأ لمن يفتح السندات
 * (`Permissions::INVOICE_VIEW`)؛ وأوامرُ الشراء نفسُها لمن يفتح المشتريات.
 * والتكلفةُ في الربحيّة من `ProjectFinancials` بقواعدها: الأمرُ التزامٌ،
 * والسندُ المعتمَد فعليّ — لا يُحسب شيءٌ هنا مرّتين.
 */
final class ProjectPurchases
{
    /** @return array<string, mixed> */
    public static function for(Project $project, User $user): array
    {
        $money = $user->may(Permissions::INVOICE_VIEW);

        // ولمن قُيّد بفروعٍ أوامرُ فروعه وسنداتُها وحدها — انظر BranchAccess
        $orders = BranchAccess::limit(PurchaseOrder::where('business_id', $project->business_id))->where('project_id', $project->id)
            ->with(['supplier:id,name,supplier_type', 'items:id,purchase_order_id,line_kind,quantity,received_quantity'])
            ->orderByDesc('ordered_at')->orderByDesc('id')->get();

        $invoices = BranchAccess::limitThrough(SupplierInvoice::where('business_id', $project->business_id), 'purchaseOrder')->where('project_id', $project->id)
            ->with('supplier:id,name')->orderByDesc('issued_at')->orderByDesc('id')->get();

        $rows = $orders->map(function (PurchaseOrder $o) {
            $qty = (int) $o->items->sum('quantity');
            $received = (int) $o->items->sum('received_quantity');

            return [
                'id' => $o->id,
                'number' => $o->number,
                'supplier_id' => $o->supplier_id,
                'supplier' => $o->supplier?->name ?? $o->supplier_name,
                'status' => $o->status,
                'receipt' => match (true) {
                    $o->status === PurchaseOrders::DRAFT => 'draft',
                    $qty > 0 && $received >= $qty => 'received',
                    $received > 0 => 'partial',
                    default => 'pending',
                },
                'closed' => $o->closed_at !== null,
                'ordered_at' => optional($o->ordered_at)->format('Y-m-d'),
                'expected_delivery_at' => optional($o->expected_delivery_at)->format('Y-m-d'),
                'total' => round((float) $o->total, 3),
                'kinds' => $o->items->pluck('line_kind')->filter()->unique()->values()->all(),
                'items' => $o->items->count(),
            ];
        })->values();

        $live = $rows->where('receipt', '!=', 'draft');
        $approved = $invoices->where('approval_status', SupplierInvoices::APPROVED);

        $suppliers = $orders->groupBy(fn (PurchaseOrder $o) => $o->supplier_id ?? 'x'.$o->supplier_name)
            ->map(fn ($group) => [
                'id' => $group->first()->supplier_id,
                'name' => $group->first()->supplier?->name ?? $group->first()->supplier_name,
                'type' => $group->first()->supplier?->supplier_type,
                'orders' => $group->count(),
                'total' => round((float) $group->where('status', '!=', PurchaseOrders::DRAFT)->sum('total'), 3),
            ])->values()->all();

        return [
            'summary' => [
                'orders' => $rows->count(),
                'drafts' => $rows->where('receipt', 'draft')->count(),
                'total' => round((float) $live->sum('total'), 3),
                'received' => $live->where('receipt', 'received')->count(),
                'pending' => $live->filter(fn ($r) => $r['receipt'] !== 'received' && ! $r['closed'])->count(),
                'invoices' => $invoices->count(),
                'invoiced' => $money ? round((float) $approved->sum('total'), 3) : null,
                'paid' => $money ? round((float) $approved->sum('paid'), 3) : null,
                'outstanding' => $money ? round((float) $approved->sum(fn (SupplierInvoice $i) => $i->outstanding()), 3) : null,
            ],
            'orders' => $rows->all(),
            'invoices' => $invoices->map(fn (SupplierInvoice $i) => [
                'id' => $i->id,
                'ref' => $i->supplier_ref,
                'supplier' => $i->supplier?->name,
                'purchase_order_id' => $i->purchase_order_id,
                'approval_status' => $i->approval_status,
                'status' => $i->status,
                'issued_at' => optional($i->issued_at)->format('Y-m-d'),
                'total' => $money ? round((float) $i->total, 3) : null,
                'paid' => $money ? round((float) $i->paid, 3) : null,
                'outstanding' => $money ? $i->outstanding() : null,
            ])->values()->all(),
            'suppliers' => $suppliers,
            'kinds' => PurchaseOrderItem::LINE_KINDS,
            'statuses' => [PurchaseOrders::DRAFT, PurchaseOrders::SENT, PurchaseOrders::PARTIAL, PurchaseOrders::RECEIVED],
            'money' => $money,
            'may' => ['create' => $user->allows('purchases') && $project->isOpen()],
        ];
    }
}
