<?php

namespace App\Support\Automation;

use App\Support\Automation\Triggers\CommunicationFailed;
use App\Support\Automation\Triggers\DeliveryAwaiting;
use App\Support\Automation\Triggers\ExpenseUnpaid;
use App\Support\Automation\Triggers\InvoiceDue;
use App\Support\Automation\Triggers\MorningBrief;
use App\Support\Automation\Triggers\ProjectClosable;
use App\Support\Automation\Triggers\ProjectStart;
use App\Support\Automation\Triggers\PurchaseOrderLate;
use App\Support\Automation\Triggers\QuotationAwaiting;
use App\Support\Automation\Triggers\QuotationExpiry;
use App\Support\Automation\Triggers\SupplierInvoiceDue;
use App\Support\Automation\Triggers\TaskDue;
use RuntimeException;

/** المُطلِقاتُ المعروفة — قائمةٌ مغلقة؛ ما ليس فيها لا يُقيَّم */
final class Triggers
{
    /** @var array<string, class-string<Trigger>> */
    public const ALL = [
        'quotation_awaiting' => QuotationAwaiting::class,
        'quotation_expiry' => QuotationExpiry::class,
        'project_start' => ProjectStart::class,
        'task_due' => TaskDue::class,
        'invoice_due' => InvoiceDue::class,
        'supplier_invoice_due' => SupplierInvoiceDue::class,
        'expense_unpaid' => ExpenseUnpaid::class,
        'purchase_order_late' => PurchaseOrderLate::class,
        'delivery_awaiting' => DeliveryAwaiting::class,
        'communication_failed' => CommunicationFailed::class,
        'project_closable' => ProjectClosable::class,
        'morning_brief' => MorningBrief::class,
    ];

    public static function for(string $key): Trigger
    {
        $class = self::ALL[$key] ?? null;

        if ($class === null) {
            throw new RuntimeException(__('مُطلِقٌ غير معروف.'));
        }

        return new $class;
    }

    public static function exists(string $key): bool
    {
        return isset(self::ALL[$key]);
    }
}
