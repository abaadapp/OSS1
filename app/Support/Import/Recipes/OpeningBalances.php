<?php

namespace App\Support\Import\Recipes;

use App\Models\CustomerInvoice;
use App\Models\SupplierInvoice;
use App\Support\CustomerInvoices as InvoiceService;
use App\Support\Import\Index;
use App\Support\Import\Provenance;
use App\Support\SupplierInvoices as SupplierInvoiceService;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * الأرصدةُ الافتتاحيّة — ما بقي على العميل أو للمورّد يومَ التحويل (§14).
 *
 * ═══ الاستراتيجيّةُ B: الرصيدُ لا المستند ═══
 *
 * ذمّةُ العميل تُكتب فاتورةً واحدة موسومةً `is_opening_balance`: بندٌ واحد
 * بلا ضريبة بتاريخ التحويل، تُصدرها `CustomerInvoices::issue` فيقيّدها الدفترُ
 * «ذمم العملاء» مدينةً و**الأرباح المحتجزة** دائنةً — لا المبيعات: الإيرادُ
 * أُكسب قبل هذا الدفتر. وتُخصَّص عليها التحصيلاتُ كأيّ فاتورة، وتُقرأ في كشف
 * العميل، ولا تدخل تقاريرَ الإيراد. وذمّةُ المورّد سندٌ موسومٌ كذلك —
 * «الأرباح المحتجزة» مدينةً و«ذمم الموردين» دائنةً.
 *
 * ═══ ولا يُعدّ الشيءُ مرّتين ═══
 *
 * يُرفض رصيدٌ افتتاحيٌّ لعميلٍ له فاتورةٌ تاريخيّةٌ قبل التحويل عليها
 * باقٍ، ورصيدٌ ثانٍ للطرف نفسِه. والصندوقُ والبنك ليسا هنا: للحساب البنكيّ
 * رصيدُه الافتتاحيُّ في بابه (`bank_accounts.opening_balance`).
 */
final class OpeningBalances extends Recipe
{
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
        $r = $unit[0];
        $errors = [];
        $warnings = [];
        $type = $r['party_type'] ?? 'customer';

        if ($this->ctx->cutover === null) {
            $errors[] = __('حدّد تاريخ التحويل أوّلًا — لا رصيدَ افتتاحيًّا بلا يومٍ يُقاس عليه');
        }
        if (self::money($r['amount'] ?? 0) <= 0) {
            $errors[] = __('الرصيدُ الافتتاحيّ يجب أن يكون أكبر من صفر');
        }
        if (! empty($r['as_of']) && $this->ctx->cutover !== null && $r['as_of'] > $this->ctx->cutover->toDateString()) {
            $errors[] = __('«حتى تاريخ» بعد تاريخ التحويل');
        }

        try {
            $party = $this->ref($type, $r['party'] ?? null, $type === 'customer' ? __('العميل') : __('المورّد'));

            if ($type === 'customer') {
                $open = CustomerInvoice::where('business_id', $this->ctx->businessId())->where('customer_id', $party->id)
                    ->where('status', CustomerInvoice::ISSUED)->where('is_opening_balance', false)
                    ->where('origin', Provenance::HISTORICAL)
                    ->when($this->ctx->cutover, fn ($q) => $q->whereDate('issued_at', '<', $this->ctx->cutover->toDateString()))
                    ->whereRaw(CustomerInvoice::outstandingSql().' > 0.0005')->count();
                if ($open > 0) {
                    $errors[] = __('للعميل :n فاتورةً تاريخيّةً قبل التحويل عليها باقٍ — الرصيدُ الافتتاحيُّ يعدّها مرّتين', ['n' => $open]);
                }
            }
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    public function match(array $unit): array
    {
        $r = $unit[0];
        $type = $r['party_type'] ?? 'customer';

        try {
            $party = $this->ref($type, $r['party'] ?? null, $type === 'customer' ? __('العميل') : __('المورّد'));
        } catch (RuntimeException $e) {
            return $this->verdict('conflict', [], $e->getMessage());
        }

        $index = $type === 'customer' ? 'invoice' : 'supplier_invoice';
        $ids = $this->ctx->index->find($index, 'opening', (string) $party->id);

        return $ids === []
            ? $this->verdict('new')
            : $this->verdict('exact', $ids, __('لهذا الطرف رصيدٌ افتتاحيٌّ من قبل'));
    }

    public function impact(array $unit): void
    {
        $r = $unit[0];
        $key = ($r['party_type'] ?? 'customer') === 'customer' ? 'opening_receivables' : 'opening_payables';
        $this->ctx->addFinancial($key, self::money($r['amount'] ?? 0));
        $this->ctx->addFinancial($key === 'opening_receivables' ? 'receivables' : 'payables', self::money($r['amount'] ?? 0));
    }

    public function create(array $unit, int $row): Model
    {
        $r = $unit[0];
        $type = $r['party_type'] ?? 'customer';
        $asOf = $r['as_of'] ?? $this->ctx->cutover?->toDateString() ?? now()->toDateString();
        $amount = self::money($r['amount']);
        $label = __('رصيد افتتاحيّ حتى :d', ['d' => $asOf]);
        $reference = $r['reference'] ?? __('افتتاحيّ :code', ['code' => $this->ctx->dataset->code]);

        if ($type === 'customer') {
            $customer = $this->ref('customer', $r['party'], __('العميل'));
            $invoice = InvoiceService::create($this->ctx->businessId(), $customer, [
                'issued_at' => $asOf, 'due_at' => $asOf, 'external_reference' => $reference,
                'notes' => $r['notes'] ?? null,
                'internal_notes' => __('رصيدٌ افتتاحيٌّ مستورَد من :ref', ['ref' => $this->ctx->dataset->code]),
            ], [['description' => $label, 'quantity' => 1, 'unit_price' => $amount, 'tax_rate' => 0]], $this->ctx->actor?->id);

            $invoice->forceFill(['number' => 'OB-'.$this->ctx->dataset->code.'-'.$row, 'is_opening_balance' => true, 'origin' => Provenance::HISTORICAL])->save();

            return InvoiceService::issue($invoice, $this->ctx->actor?->id);
        }

        $supplier = $this->ref('supplier', $r['party'], __('المورّد'));
        $invoice = SupplierInvoiceService::create($this->ctx->businessId(), [
            'supplier_id' => $supplier->id, 'supplier_ref' => 'OB-'.$this->ctx->dataset->code.'-'.$row,
            'issued_at' => $asOf, 'due_at' => $asOf, 'subtotal' => $amount, 'tax' => 0,
            'notes' => trim(implode("\n", array_filter([$label, $r['notes'] ?? null]))),
        ], $this->ctx->actor);
        $invoice->forceFill(['is_opening_balance' => true, 'origin' => Provenance::HISTORICAL])->saveQuietly();

        return SupplierInvoiceService::approve($invoice->fresh(), $this->ctx->actor, __('رصيدٌ افتتاحيّ'));
    }

    public function keys(Model $model): array
    {
        return $model instanceof CustomerInvoice
            ? ['opening' => (string) $model->customer_id, 'number' => Index::code($model->number)]
            : ['opening' => (string) $model->supplier_id];
    }

    /** نوعُ الفهرس بحسب الطرف — للتذكير بعد الإنشاء */
    public static function indexType(Model $model): string
    {
        return $model instanceof SupplierInvoice ? 'supplier_invoice' : 'invoice';
    }
}
