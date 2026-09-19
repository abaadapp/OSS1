<?php

namespace App\Support\Import\Recipes;

use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Support\CustomerPayments as PaymentService;
use App\Support\Import\Index;
use App\Support\Import\Provenance;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * تحصيلاتُ العملاء التاريخيّة — من باب `CustomerPayments::record`.
 *
 * تُخصَّص على الفاتورة المذكورة (برقمها القديم أو رقم النظام) إن كانت
 * صادرةً وعليها رصيد؛ وما لا فاتورةَ له يُخصَّص على الأقدم كما يفعل النظامُ
 * نفسُه (`CustomerPayments::record`)، وما زاد رصيدٌ للعميل. ودفعةٌ تفوق
 * ما بقي على فاتورتها تُوقَف: دفعةٌ يتيمةٌ تحتاج يدًا لا تخمينًا. ولا تُستورد
 * دفعةٌ قبل تاريخ التحويل: ما قبله في الرصيد الافتتاحيّ.
 */
final class Payments extends Recipe
{
    public function type(): string
    {
        return 'payment';
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

        $customer = null;
        try {
            $customer = $this->ref('customer', $r['customer'] ?? null, __('العميل'));
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }

        if (self::money($r['amount'] ?? 0) <= 0) {
            $errors[] = __('مبلغُ التحصيل يجب أن يكون أكبر من صفر');
        }
        if ($this->ctx->beforeCutover($r['occurred_at'] ?? null)) {
            $errors[] = __('التحصيل بتاريخ :d قبل تاريخ التحويل — ما قبله داخلٌ في الرصيد الافتتاحيّ', ['d' => $r['occurred_at']]);
        }

        if (! empty($r['invoice'])) {
            try {
                $invoice = $this->ref('invoice', $r['invoice'], __('الفاتورة'));
                if ($customer !== null && (int) $invoice->customer_id !== (int) $customer->id) {
                    $errors[] = __('الفاتورة :n ليست لهذا العميل', ['n' => $invoice->number]);
                } elseif ($invoice->status !== CustomerInvoice::ISSUED) {
                    $errors[] = __('الفاتورة :n ليست صادرة — لا تُخصَّص عليها دفعة', ['n' => $invoice->number]);
                } elseif ($invoice->outstanding() + 0.0005 < self::money($r['amount'] ?? 0)) {
                    $errors[] = __('الدفعة (:a) تفوق ما بقي على الفاتورة :n (:o)', ['a' => Index::money($r['amount']), 'n' => $invoice->number, 'o' => Index::money($invoice->outstanding())]);
                }
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        } else {
            $warnings[] = __('بلا فاتورة — يُخصَّص على أقدم فواتير العميل كما يفعل النظام، وما زاد يبقى رصيدًا له');
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    public function match(array $unit): array
    {
        $r = $unit[0];
        $customerId = null;
        try {
            $customerId = (int) $this->ref('customer', $r['customer'] ?? null, __('العميل'))->id;
        } catch (RuntimeException) {
        }

        return $this->matchBy('payment', [
            'external' => Index::code($r['external_id'] ?? $r['number'] ?? null),
            'reference' => Index::code($r['number'] ?? null),
        ], $customerId === null ? [] : ['customer_date_amount' => $customerId.'|'.$r['occurred_at'].'|'.Index::money($r['amount'] ?? 0)]);
    }

    public function externalId(array $unit): ?string
    {
        return parent::externalId($unit) ?? (isset($unit[0]['number']) ? mb_substr((string) $unit[0]['number'], 0, 64) : null);
    }

    public function impact(array $unit): void
    {
        $this->ctx->addFinancial('collections', self::money($unit[0]['amount'] ?? 0));
        $this->ctx->addFinancial('receivables', -self::money($unit[0]['amount'] ?? 0));
    }

    public function create(array $unit, int $row): Model
    {
        $r = $unit[0];
        $customer = $this->ref('customer', $r['customer'], __('العميل'));
        $allocations = [];

        if (! empty($r['invoice'])) {
            $invoice = $this->ref('invoice', $r['invoice'], __('الفاتورة'));
            $allocations[(int) $invoice->id] = self::money($r['amount']);
        }

        $payment = PaymentService::record($this->ctx->businessId(), $customer, self::money($r['amount']), [
            'method' => $r['method'] ?? 'تحويل',
            'occurred_at' => $r['occurred_at'],
            'external_reference' => $r['number'] ?? null,
            'notes' => trim(implode("\n", array_filter([__('مستورَد من :ref', ['ref' => $this->ctx->dataset->code]), $r['notes'] ?? null]))),
        ], $allocations, $this->ctx->actor?->id);

        $payment->forceFill(['origin' => Provenance::HISTORICAL])->saveQuietly();

        return $payment->fresh();
    }

    public function keys(Model $model): array
    {
        /** @var CustomerPayment $model */
        return ['reference' => Index::code($model->external_reference),
            'customer_date_amount' => $model->customer_id.'|'.optional($model->occurred_at)->format('Y-m-d').'|'.Index::money($model->amount)];
    }
}
