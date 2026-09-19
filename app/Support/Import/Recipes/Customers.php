<?php

namespace App\Support\Import\Recipes;

use App\Models\Customer;
use App\Support\Import\Index;
use App\Support\Import\Provenance;
use Illuminate\Database\Eloquent\Model;

/** العملاء — بياناتٌ مرجعيّة: الهاتفُ والبريدُ والرقمُ الضريبيُّ مفاتيحُ قويّة، والاسمُ ضعيف */
final class Customers extends Recipe
{
    public function type(): string
    {
        return 'customer';
    }

    public function check(array $unit): array
    {
        $r = $unit[0];
        $warnings = [];

        if (empty($r['phone']) && empty($r['email']) && empty($r['contact_phone'])) {
            $warnings[] = __('بلا هاتفٍ ولا بريد — لا سبيلَ للتواصل ولا للمطابقة القويّة');
        }
        if (! empty($r['payment_terms_days']) && $r['payment_terms_days'] > 365) {
            $warnings[] = __('مدّةُ سدادٍ فوق سنة');
        }

        return ['errors' => [], 'warnings' => $warnings];
    }

    public function match(array $unit): array
    {
        $r = $unit[0];

        return $this->matchBy('customer', [
            'external' => Index::code($r['external_id'] ?? null),
            'phone' => Index::phone($r['phone'] ?? null),
            'email' => Index::email($r['email'] ?? null),
            'tax' => Index::code($r['tax_number'] ?? null),
        ], [
            'name' => Index::name($r['name'] ?? null),
            'legal' => Index::name($r['legal_name'] ?? $r['name'] ?? null),
            'name_en' => Index::name($r['name_en'] ?? null),
        ]);
    }

    public function create(array $unit, int $row): Model
    {
        $r = $unit[0];

        return Customer::create([
            'business_id' => $this->ctx->businessId(),
            'name' => $r['name'],
            'name_en' => $r['name_en'] ?? null,
            'legal_name' => $r['legal_name'] ?? null,
            'customer_type' => $r['customer_type'] ?? 'شركة',
            'phone' => $r['phone'] ?? null,
            'email' => $r['email'] ?? null,
            'tax_number' => $r['tax_number'] ?? null,
            'commercial_registration' => $r['commercial_registration'] ?? null,
            'contact_person' => $r['contact_person'] ?? null,
            'contact_email' => $r['contact_email'] ?? null,
            'contact_phone' => $r['contact_phone'] ?? null,
            'address' => $r['address'] ?? null,
            'billing_address' => $r['billing_address'] ?? null,
            'department' => $r['department'] ?? null,
            'customer_reference' => $r['customer_reference'] ?? null,
            'payment_terms_days' => $r['payment_terms_days'] ?? null,
            'notes' => $r['notes'] ?? null,
            'origin' => Provenance::HISTORICAL,
        ]);
    }

    public function keys(Model $model): array
    {
        /** @var Customer $model */
        return ['phone' => Index::phone($model->phone), 'email' => Index::email($model->email), 'tax' => Index::code($model->tax_number),
            'name' => Index::name($model->name), 'legal' => Index::name($model->legal_name), 'name_en' => Index::name($model->name_en)];
    }
}
