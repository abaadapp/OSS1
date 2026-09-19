<?php

namespace App\Support\Import\Recipes;

use App\Models\Currency;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Support\Import\Index;
use App\Support\Import\Provenance;
use App\Support\LineTotals;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * عروضُ الأسعار التاريخيّة — ورقةٌ برقمها وتاريخها وحالها، بلا دورة.
 *
 * لا `send` ولا `decide`: لا رقمَ يُقطع من تسلسل اليوم (رقمُها القديم
 * ببادئة `HIST-`)، ولا ختمَ ولا بصمةَ ولا `DocumentApproval` — ما قبله
 * العميل قديمًا حالٌ تُكتب وتاريخٌ واسمٌ من المصدر، لا دليلٌ يُختلق. ولا
 * مشروعَ يُولد من قبولٍ تاريخيّ: المشاريعُ تُستورد من ملفّها.
 */
final class Quotations extends Recipe
{
    public const PREFIX = 'HIST-';

    public function type(): string
    {
        return 'quotation';
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

        foreach ($unit as $r) {
            if (($r['customer'] ?? null) !== ($head['customer'] ?? null) || ($r['issued_at'] ?? null) !== ($head['issued_at'] ?? null)) {
                $errors[] = __('صفوفُ العرض الواحد تختلف في العميل أو التاريخ');
                break;
            }
        }

        $lines = self::lines($unit, $warnings);
        if ($lines === []) {
            $errors[] = __('عرضٌ بلا بنودٍ ولا إجماليّ');
        }

        $computed = LineTotals::compute($this->ctx->businessId(), $lines);
        $source = $head['total'] ?? null;
        if ($source !== null && abs(self::money($source) - (float) $computed['total']) > 0.0005) {
            $errors[] = __('إجماليُّ المصدر (:s) لا يطابق المحسوبَ من البنود (:c)', ['s' => Index::money($source), 'c' => Index::money($computed['total'])]);
        }

        if (($head['status'] ?? null) === Quotation::SENT) {
            $warnings[] = __('عرضٌ ما زال «مُرسَلًا» — يُستورد بلا رابطٍ ولا تذكير');
        }
        if (in_array($head['status'] ?? null, [Quotation::ACCEPTED, Quotation::REJECTED], true) && empty($head['decided_at'])) {
            $warnings[] = __('قرارٌ بلا تاريخ — يُكتب تاريخُ الإصدار');
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

        return $this->matchBy('quotation', [
            'external' => Index::code($head['external_id'] ?? $head['number'] ?? null),
            'number' => Index::code(self::PREFIX.($head['number'] ?? '')),
        ], $customerId === null ? [] : ['customer_date_total' => $customerId.'|'.$head['issued_at'].'|'.Index::money($computed['total'])]);
    }

    public function externalId(array $unit): ?string
    {
        return parent::externalId($unit) ?? mb_substr((string) $unit[0]['number'], 0, 64);
    }

    public function create(array $unit, int $row): Model
    {
        $head = $unit[0];
        $customer = $this->ref('customer', $head['customer'], __('العميل'));
        $bid = $this->ctx->businessId();
        $totals = LineTotals::compute($bid, self::lines($unit, $w), null, ['product_id', 'unit', 'list_price', 'notes']);
        $status = $head['status'];
        $decided = in_array($status, [Quotation::ACCEPTED, Quotation::REJECTED], true);

        $quotation = Quotation::create([
            'business_id' => $bid,
            'customer_id' => $customer->id,
            'number' => self::PREFIX.$head['number'],
            'status' => $status,
            'revision' => 1,
            'title' => $head['title'],
            'event_location' => $head['event_location'] ?? null,
            'event_starts_at' => $head['event_starts_at'] ?? null,
            'issued_at' => $head['issued_at'],
            'valid_until' => $head['valid_until'] ?? null,
            'currency' => Currency::where('business_id', $bid)->where('is_base', true)->value('code'),
            'subtotal' => $totals['subtotal'],
            'discount_total' => $totals['discount'],
            'tax_total' => $totals['tax'],
            'total' => $totals['total'],
            'customer_name' => $customer->legal_name ?: $customer->name,
            'customer_tax_number' => $customer->tax_number,
            'notes' => $head['notes'] ?? null,
            'internal_notes' => __('مستورَد من :ref — الرقم القديم :n', ['ref' => $this->ctx->dataset->code, 'n' => $head['number']]),
            'sent_at' => $head['issued_at'],
            'decided_at' => $decided ? ($head['decided_at'] ?? $head['issued_at']) : null,
            'decided_by_name' => $decided ? ($head['decided_by_name'] ?? null) : null,
            'cancelled_at' => $status === Quotation::CANCELLED ? ($head['decided_at'] ?? $head['issued_at']) : null,
            'created_by' => $this->ctx->actor?->id,
            'origin' => Provenance::HISTORICAL,
        ]);

        foreach ($totals['items'] as $item) {
            QuotationItem::create(array_replace($item, ['quotation_id' => $quotation->id, 'list_price' => $item['unit_price']]));
        }

        return $quotation;
    }

    public function keys(Model $model): array
    {
        /** @var Quotation $model */
        return ['number' => Index::code($model->number),
            'customer_date_total' => $model->customer_id.'|'.optional($model->issued_at)->format('Y-m-d').'|'.Index::money($model->total)];
    }

    /**
     * البنودُ من صفوف الوحدة — وإن غاب التفصيلُ فبندٌ واحد بالإجماليّ.
     *
     * @return list<array<string, mixed>>
     */
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

        if ($lines === [] && isset($unit[0]['total'])) {
            $rate = (float) ($unit[0]['tax_rate'] ?? 0);
            $net = $rate > 0 ? round((float) $unit[0]['total'] / (1 + $rate / 100), 3) : (float) $unit[0]['total'];
            $lines[] = ['description' => $unit[0]['title'] ?? $unit[0]['description'] ?? __('بند تاريخي'), 'quantity' => 1, 'unit_price' => $net, 'tax_rate' => $rate];
            $warnings[] = __('بلا تفصيل بنود — بندٌ واحد من الإجماليّ');
        }

        return $lines;
    }
}
