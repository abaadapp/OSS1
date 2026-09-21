<?php

namespace App\Support\Import;

use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\ImportDataset;
use App\Models\ImportLink;
use App\Models\ImportRun;
use App\Models\SupplierInvoice;
use App\Support\SupplierInvoices;

/**
 * المطابقةُ بعد الاستيراد — هل ما دخل هو ما كان في الملفّ؟ (§26)
 *
 * العدُّ لكلّ صنف: صفوفُ المصدر، ووحداتُه، وما أُنشئ ورُبط وتُخطّي وتعارض
 * وأخطأ، وما في الوجهة من أثر. والمالُ لما هو ماليّ: مجموعُ ما حسبته التجربةُ
 * من المصدر مقابلَ مجموعِ ما كُتب فعلًا في الكيانات المنشأة — والفرقُ يجب أن
 * يكون صفرًا. ولا تُدّعى مطابقةٌ ماليّة لصنفٍ لا مالَ فيه.
 */
final class Reconciliation
{
    /** @return array<string, mixed> */
    public static function for(ImportDataset $dataset, ImportRun $run): array
    {
        $t = $run->totals ?? [];
        $links = ImportLink::where('dataset_id', $dataset->id)->whereNull('rolled_back_at');
        $created = (clone $links)->where('action', ImportLink::CREATED)->get(['linkable_type', 'linkable_id']);
        $linkedCount = (clone $links)->where('action', ImportLink::LINKED)->count();

        $out = [
            'source_rows' => (int) ($dataset->row_count ?? $t['rows'] ?? 0),
            'source_units' => (int) ($t['units'] ?? 0),
            'created' => (int) ($t['created'] ?? 0),
            'linked' => (int) ($t['linked'] ?? 0),
            'skipped' => (int) ($t['skipped'] ?? 0),
            'conflicts' => (int) ($t['conflicts'] ?? 0),
            'errors' => (int) ($t['errors'] ?? 0),
            'destination_created' => $created->count(),
            'destination_linked' => $linkedCount,
            'financial' => null,
            'balanced' => true,
        ];

        /* ما أُنشئ في هذا التنفيذ له أثرٌ بعدده — لا أكثر ولا أقلّ */
        $thisRun = ImportLink::where('run_id', $run->id)->where('action', ImportLink::CREATED)->count();
        $out['destination_created_this_run'] = $thisRun;
        $out['balanced'] = $thisRun === $out['created'];

        if (! Fields::isFinancial($dataset->category)) {
            return $out;
        }

        $expected = $run->financial ?? [];
        $ids = ImportLink::where('run_id', $run->id)->where('action', ImportLink::CREATED)->pluck('linkable_id')->map(fn ($v) => (int) $v)->all();

        $out['financial'] = match ($dataset->category) {
            'customer_invoices', 'opening_balances' => self::invoices($dataset, $ids, $expected),
            'payments' => self::payments($ids, $expected),
            'expenses' => self::expenses($ids, $expected),
            'supplier_invoices' => self::supplierInvoices($ids, $expected),
            default => null,
        };

        if ($out['financial'] !== null) {
            $out['balanced'] = $out['balanced'] && abs((float) $out['financial']['difference']) <= 0.0005;
        }

        return $out;
    }

    private static function invoices(ImportDataset $dataset, array $ids, array $expected): array
    {
        $rows = CustomerInvoice::whereIn('id', $ids)->withMoney()->get();
        $supplierRows = $dataset->category === 'opening_balances' ? SupplierInvoice::whereIn('id', $ids)->get() : collect();

        $issued = $rows->where('status', CustomerInvoice::ISSUED);
        $importedTotal = round((float) $issued->sum('total') + (float) $supplierRows->sum('total'), 3);
        $importedTax = round((float) $issued->sum('tax_total'), 3);
        $importedOutstanding = round((float) $issued->sum(fn (CustomerInvoice $i) => $i->outstanding()) + (float) $supplierRows->sum(fn ($s) => $s->outstanding()), 3);
        $sourceTotal = round((float) ($expected['receivables'] ?? 0) + (float) ($expected['opening_payables'] ?? 0), 3);

        return [
            'source_total' => $sourceTotal,
            'imported_total' => $importedTotal,
            'source_tax' => round((float) ($expected['tax'] ?? 0), 3),
            'imported_tax' => $importedTax,
            'imported_outstanding' => $importedOutstanding,
            'difference' => round($importedTotal - $sourceTotal, 3),
            'tax_difference' => round($importedTax - (float) ($expected['tax'] ?? 0), 3),
        ];
    }

    private static function payments(array $ids, array $expected): array
    {
        $imported = round((float) CustomerPayment::whereIn('id', $ids)->whereNull('cancelled_at')->sum('amount'), 3);
        $source = round((float) ($expected['collections'] ?? 0), 3);

        return ['source_total' => $source, 'imported_total' => $imported, 'difference' => round($imported - $source, 3)];
    }

    private static function expenses(array $ids, array $expected): array
    {
        $imported = round((float) Expense::whereIn('id', $ids)->sum('amount'), 3);
        $source = round((float) ($expected['expenses'] ?? 0), 3);

        return ['source_total' => $source, 'imported_total' => $imported, 'difference' => round($imported - $source, 3)];
    }

    private static function supplierInvoices(array $ids, array $expected): array
    {
        $rows = SupplierInvoice::whereIn('id', $ids)->get();
        $approved = $rows->where('approval_status', SupplierInvoices::APPROVED);
        $imported = round((float) $approved->sum('subtotal'), 3);
        $source = round((float) ($expected['supplier_cost'] ?? 0), 3);
        $outstanding = round((float) $approved->sum(fn ($s) => $s->outstanding()), 3);

        return ['source_total' => $source, 'imported_total' => $imported, 'difference' => round($imported - $source, 3),
            'source_outstanding' => round((float) ($expected['payables'] ?? 0), 3), 'imported_outstanding' => $outstanding];
    }
}
