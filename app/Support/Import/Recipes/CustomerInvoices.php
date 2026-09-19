<?php

namespace App\Support\Import\Recipes;

use App\Models\CustomerInvoice;
use App\Support\CustomerInvoices as InvoiceService;
use App\Support\Import\Index;
use App\Support\Import\Provenance;
use App\Support\LineTotals;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * فواتيرُ العملاء التاريخيّة — من باب `CustomerInvoices::create` ثمّ `issue`.
 *
 * ═══ الاستراتيجيّةُ A: مستندٌ كامل ═══
 *
 * تُكتب الفاتورةُ بتاريخها الأصليّ ورقمها القديم (`HIST-…`)، ويُصدرها
 * `issue()` فيقع قيدُها في الدفتر **بتاريخها** كما لو كانت هنا يومَها.
 * والمُلغاة تُكتب مسودّةً ثمّ تُلغى — فلا قيدَ ولا ذمّة. ولا تُستورد فاتورةٌ
 * قبل تاريخ التحويل: ما قبله رصيدٌ افتتاحيٌّ (§15) — وإلّا عُدّ الدَّينُ
 * مرّتين.
 *
 * ═══ ولا سدادَ هنا ═══
 *
 * فاتورةٌ سُدّدت قديمًا = فاتورةٌ + تحصيلٌ من ملفّ التحصيلات، لا حالٌ تُكتب.
 */
final class CustomerInvoices extends Recipe
{
    public const PREFIX = 'HIST-';

    public function type(): string
    {
        return 'invoice';
    }

    public function onExact(): string
    {
        return 'skip';
    }

    public function check(array $unit): array
    {
        $head = $unit[0];
        $errors = [];
        $warnings = [];

        try {
            $this->ref('customer', $head['customer'] ?? null, __('العميل'));
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }

        if (! empty($head['project'])) {
            try {
                $this->ref('project', $head['project'], __('المشروع'));
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        foreach ($unit as $r) {
            if (($r['customer'] ?? null) !== ($head['customer'] ?? null) || ($r['issued_at'] ?? null) !== ($head['issued_at'] ?? null)) {
                $errors[] = __('صفوفُ الفاتورة الواحدة تختلف في العميل أو التاريخ');
                break;
            }
        }

        if ($this->ctx->beforeCutover($head['issued_at'] ?? null)) {
            $errors[] = __('الفاتورة بتاريخ :d قبل تاريخ التحويل — ما قبله يُستورد رصيدًا افتتاحيًّا لا مستندًا', ['d' => $head['issued_at']]);
        }
        if (! empty($head['due_at']) && $head['due_at'] < $head['issued_at']) {
            $warnings[] = __('الاستحقاقُ قبل الإصدار');
        }

        $lines = self::lines($unit, $warnings);
        if ($lines === []) {
            $errors[] = __('فاتورةٌ بلا بنودٍ ولا إجماليّ');
        }

        $computed = LineTotals::compute($this->ctx->businessId(), $lines);
        if (isset($head['total']) && abs(self::money($head['total']) - (float) $computed['total']) > 0.0055) {
            $errors[] = __('إجماليُّ المصدر (:s) لا يطابق المحسوبَ من البنود (:c)', ['s' => Index::money($head['total']), 'c' => Index::money($computed['total'])]);
        }
        if (isset($head['tax']) && abs(self::money($head['tax']) - (float) $computed['tax']) > 0.0055) {
            $errors[] = __('ضريبةُ المصدر (:s) لا تطابق المحسوبة (:c)', ['s' => Index::money($head['tax']), 'c' => Index::money($computed['tax'])]);
        }
        if ((float) $computed['total'] <= 0 && ($head['status'] ?? 'issued') !== 'cancelled') {
            $errors[] = __('فاتورةٌ بلا مبلغ');
        }
        if ((float) $computed['tax'] <= 0 && (float) $computed['subtotal'] > 0 && $this->ctx->businessId() && empty($head['tax_rate']) && ! isset($head['tax'])) {
            $warnings[] = __('بلا ضريبة — تأكّد أنّ الفاتورة قبل تطبيق الضريبة أو معفاة');
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    public function match(array $unit): array
    {
        $head = $unit[0];
        $customerId = null;
        try {
            $customerId = (int) $this->ref('customer', $head['customer'] ?? null, __('العميل'))->id;
        } catch (RuntimeException) {
        }
        $computed = LineTotals::compute($this->ctx->businessId(), self::lines($unit, $w));

        return $this->matchBy('invoice', [
            'external' => Index::code($head['external_id'] ?? $head['number'] ?? null),
            'number' => Index::code(self::PREFIX.($head['number'] ?? '')),
            'reference' => Index::code($head['number'] ?? null),
        ], $customerId === null ? [] : ['customer_date_total' => $customerId.'|'.$head['issued_at'].'|'.Index::money($computed['total'])]);
    }

    public function externalId(array $unit): ?string
    {
        return parent::externalId($unit) ?? mb_substr((string) $unit[0]['number'], 0, 64);
    }

    public function impact(array $unit): void
    {
        $head = $unit[0];
        if (($head['status'] ?? 'issued') === 'cancelled') {
            return;
        }
        $t = LineTotals::compute($this->ctx->businessId(), self::lines($unit, $w));
        $this->ctx->addFinancial('receivables', (float) $t['total']);
        $this->ctx->addFinancial('revenue', round((float) $t['subtotal'] - (float) $t['discount'], 3));
        $this->ctx->addFinancial('tax', (float) $t['tax']);
    }

    public function create(array $unit, int $row): Model
    {
        $head = $unit[0];
        $customer = $this->ref('customer', $head['customer'], __('العميل'));
        $project = empty($head['project']) ? null : $this->ref('project', $head['project'], __('المشروع'));

        $invoice = InvoiceService::create($this->ctx->businessId(), $customer, [
            'issued_at' => $head['issued_at'],
            'due_at' => $head['due_at'] ?? $head['issued_at'],
            'external_reference' => $head['number'],
            'po_number' => $head['po_number'] ?? null,
            'contract_number' => $head['contract_number'] ?? null,
            'department' => $head['department'] ?? null,
            'project_id' => $project?->id,
            'notes' => $head['notes'] ?? null,
            'internal_notes' => __('مستورَد من :ref — الرقم القديم :n', ['ref' => $this->ctx->dataset->code, 'n' => $head['number']]),
        ], self::lines($unit, $w), $this->ctx->actor?->id);

        /* الرقمُ القديم ببادئته، والمنشأُ — قبل الإصدار كي يُصدَر بهما */
        $invoice->forceFill(['number' => self::PREFIX.$head['number'], 'origin' => Provenance::HISTORICAL])->save();

        if (($head['status'] ?? 'issued') === 'cancelled') {
            $invoice->forceFill(['status' => CustomerInvoice::CANCELLED, 'cancelled_at' => now(),
                'cancellation_reason' => __('ملغاة في المصدر')])->save();

            return $invoice->fresh();
        }

        return InvoiceService::issue($invoice, $this->ctx->actor?->id);
    }

    public function keys(Model $model): array
    {
        /** @var CustomerInvoice $model */
        return ['number' => Index::code($model->number), 'reference' => Index::code($model->external_reference),
            'customer_date_total' => $model->customer_id.'|'.optional($model->issued_at)->format('Y-m-d').'|'.Index::money($model->total)];
    }

    /** @return list<array<string, mixed>> */
    public static function lines(array $unit, ?array &$warnings): array
    {
        $warnings ??= [];
        $lines = [];

        foreach ($unit as $r) {
            if (! empty($r['description']) && isset($r['unit_price'])) {
                $lines[] = ['description' => $r['description'], 'quantity' => $r['quantity'] ?? 1, 'unit_price' => $r['unit_price'],
                    'tax_rate' => (float) ($r['tax_rate'] ?? 0)];
            }
        }

        if ($lines === []) {
            $head = $unit[0];
            $net = isset($head['net']) ? (float) $head['net'] : null;
            $tax = isset($head['tax']) ? (float) $head['tax'] : null;
            $total = isset($head['total']) ? (float) $head['total'] : null;

            if ($net === null && $total !== null) {
                $net = $tax !== null ? round($total - $tax, 3) : (isset($head['tax_rate']) && (float) $head['tax_rate'] > 0
                    ? round($total / (1 + (float) $head['tax_rate'] / 100), 3) : $total);
            }
            if ($net !== null) {
                $rate = $tax !== null && $net > 0 ? round($tax / $net * 100, 2) : (float) ($head['tax_rate'] ?? 0);
                $lines[] = ['description' => $head['description'] ?? __('فاتورة تاريخية :n', ['n' => $head['number'] ?? '']),
                    'quantity' => 1, 'unit_price' => $net, 'tax_rate' => $rate];
                $warnings[] = __('بلا تفصيل بنود — بندٌ واحد من الإجماليّ');
            }
        }

        return $lines;
    }
}
