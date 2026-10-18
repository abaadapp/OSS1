<?php

namespace App\Support\Exports;

/**
 * فهرسُ القوائم التي تُصدَّر — مفتاحُها في المسار (`->defaults('dataset', …)`).
 *
 * والمفتاحُ يأتي من تعريف المسار لا من الرابط: لا يُطلب بالرابط قائمةٌ لم
 * يُسجَّل لها مسارٌ تحت قسمها.
 */
final class Datasets
{
    /** @var array<string, class-string<Dataset>> */
    public const ALL = [
        'transactions' => TransactionsDataset::class,
        'journal' => JournalDataset::class,
        'expenses' => ExpensesDataset::class,
        'dues' => DuesDataset::class,
        'receivables' => ReceivablesDataset::class,
        'bank-statement' => BankStatementDataset::class,
        'cheques' => ChequesDataset::class,
        'customer-invoices' => CustomerInvoicesDataset::class,
        'purchase-orders' => PurchaseOrdersDataset::class,
        'supplier-invoices' => SupplierInvoicesDataset::class,
        'supplier-payments' => SupplierPaymentsDataset::class,
        'projects' => ProjectsDataset::class,
        'payroll' => PayrollDataset::class,
        'payroll-payments' => PayrollPaymentsDataset::class,
        'activity' => ActivityDataset::class,
    ];

    public static function make(string $key): Dataset
    {
        abort_unless(isset(self::ALL[$key]), 404);

        return new (self::ALL[$key]);
    }
}
