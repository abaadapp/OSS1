<?php

namespace App\Support\Import\Recipes;

use App\Models\SupplierInvoice;
use App\Support\Import\Index;
use App\Support\Import\Provenance;
use App\Support\SupplierInvoices as SupplierInvoiceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * سنداتُ الموردين التاريخيّة — من `SupplierInvoices::create` ثمّ `approve`.
 *
 * المعتمَدُ يكتب قيدَه بتاريخه (تكلفةً صافيةً من ضريبةٍ تُستردّ — كما في
 * الدفتر)، وما سُدّد منه يُكتب في `paid`. والمعلَّق يبقى معلَّقًا. ولا يُستورد
 * سندٌ معتمَد قبل تاريخ التحويل: ما قبله رصيدٌ افتتاحيٌّ للمورّد. ولا أمرَ
 * شراءٍ يُربط: أوامرُ الشراء التاريخيّة لا تُستورد (بياناتُها ناقصة عادةً).
 */
final class SupplierInvoices extends Recipe
{
    public function type(): string
    {
        return 'supplier_invoice';
    }

    public function onExact(): string
    {
        return 'skip';
    }

    public function check(array $unit): array
    {
        $r = $unit[0];
        $errors = [];
        $warnings = [];

        try {
            $this->ref('supplier', $r['supplier'] ?? null, __('المورّد'));
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
        if (! empty($r['project'])) {
            try {
                $this->ref('project', $r['project'], __('المشروع'));
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        $subtotal = self::money($r['subtotal'] ?? 0);
        $tax = self::money($r['tax'] ?? 0);
        if ($subtotal + $tax <= 0) {
            $errors[] = __('سندٌ بلا مبلغ');
        }
        if (isset($r['total']) && abs(self::money($r['total']) - ($subtotal + $tax)) > 0.0055) {
            $errors[] = __('إجماليُّ المصدر (:s) ≠ الصافي + الضريبة (:c)', ['s' => Index::money($r['total']), 'c' => Index::money($subtotal + $tax)]);
        }
        if (isset($r['paid']) && self::money($r['paid']) > $subtotal + $tax + 0.0005) {
            $errors[] = __('المسدَّد يفوق قيمة السند');
        }
        if (($r['status'] ?? 'approved') === 'approved' && $this->ctx->beforeCutover($r['issued_at'] ?? null)) {
            $errors[] = __('السند بتاريخ :d قبل تاريخ التحويل — ما قبله رصيدٌ افتتاحيٌّ للمورّد', ['d' => $r['issued_at']]);
        }
        if (isset($r['paid']) && self::money($r['paid']) > 0) {
            $warnings[] = __('سدادٌ بلا تاريخ في المصدر — يُقيَّد بتاريخ السند من البنك');
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    public function match(array $unit): array
    {
        $r = $unit[0];
        $supplierId = null;
        try {
            $supplierId = (int) $this->ref('supplier', $r['supplier'] ?? null, __('المورّد'))->id;
        } catch (RuntimeException) {
        }

        return $this->matchBy('supplier_invoice', [
            'external' => Index::code($r['external_id'] ?? null),
            'supplier_ref' => $supplierId === null ? null : $supplierId.'|'.Index::code($r['supplier_ref'] ?? null),
        ], []);
    }

    public function impact(array $unit): void
    {
        $r = $unit[0];
        if (($r['status'] ?? 'approved') !== 'approved') {
            return;
        }
        $total = self::money($r['subtotal'] ?? 0) + self::money($r['tax'] ?? 0);
        $this->ctx->addFinancial('supplier_cost', self::money($r['subtotal'] ?? 0));
        $this->ctx->addFinancial('payables', round($total - self::money($r['paid'] ?? 0), 3));
    }

    public function create(array $unit, int $row): Model
    {
        $r = $unit[0];
        $supplier = $this->ref('supplier', $r['supplier'], __('المورّد'));
        $project = empty($r['project']) ? null : $this->ref('project', $r['project'], __('المشروع'));

        $invoice = SupplierInvoiceService::create($this->ctx->businessId(), [
            'supplier_id' => $supplier->id,
            'supplier_ref' => $r['supplier_ref'],
            'issued_at' => $r['issued_at'],
            'due_at' => $r['due_at'] ?? null,
            'subtotal' => self::money($r['subtotal']),
            'tax' => self::money($r['tax'] ?? 0),
            'project_id' => $project?->id,
            'notes' => trim(implode("\n", array_filter([__('مستورَد من :ref', ['ref' => $this->ctx->dataset->code]), $r['notes'] ?? null]))),
        ], $this->ctx->actor);

        $invoice->forceFill(['origin' => Provenance::HISTORICAL])->saveQuietly();

        if (($r['status'] ?? 'approved') === 'approved') {
            $invoice = SupplierInvoiceService::approve($invoice->fresh(), $this->ctx->actor, __('اعتمادٌ تاريخيّ — من المصدر'));

            /* والمسدَّدُ قيدٌ من بابه — بتاريخ السند إذ لا تاريخَ سدادٍ في المصدر (ويُقال في التنبيه) */
            if (isset($r['paid']) && self::money($r['paid']) > 0) {
                $invoice = SupplierInvoiceService::settle($invoice, self::money($r['paid']), 'bank', Carbon::parse($r['issued_at']), $this->ctx->actor?->id);
            }
        }

        return $invoice->fresh();
    }

    public function keys(Model $model): array
    {
        /** @var SupplierInvoice $model */
        return ['supplier_ref' => $model->supplier_id.'|'.Index::code($model->supplier_ref)];
    }
}
