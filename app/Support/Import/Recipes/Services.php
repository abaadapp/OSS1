<?php

namespace App\Support\Import\Recipes;

use App\Models\Product;
use App\Support\Import\Index;
use App\Support\Import\Provenance;
use Illuminate\Database\Eloquent\Model;

/** الخدماتُ والمنتجات — الرمزُ (SKU) والمعرِّفُ القديم مفاتيحُ قويّة، والاسمُ ضعيف */
final class Services extends Recipe
{
    public function type(): string
    {
        return 'product';
    }

    public function check(array $unit): array
    {
        $r = $unit[0];
        $errors = [];
        $warnings = [];

        if (isset($r['price']) && $r['price'] < 0) {
            $errors[] = __('سعرٌ سالب');
        }
        if (isset($r['tax']) && ($r['tax'] < 0 || $r['tax'] > 100)) {
            $errors[] = __('نسبةُ ضريبةٍ خارج المدى');
        }
        if (! isset($r['price'])) {
            $warnings[] = __('بلا سعر — سيُحفظ بصفر');
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    public function match(array $unit): array
    {
        $r = $unit[0];

        return $this->matchBy('product', [
            'external' => Index::code($r['external_id'] ?? null),
            'sku' => Index::code($r['sku'] ?? null),
        ], ['name' => Index::name($r['name'] ?? null), 'name_en' => Index::name($r['name_en'] ?? null)]);
    }

    public function create(array $unit, int $row): Model
    {
        $r = $unit[0];

        return Product::create([
            'business_id' => $this->ctx->businessId(),
            'name' => $r['name'],
            'name_en' => $r['name_en'] ?? null,
            'kind' => $r['kind'] ?? Product::SERVICE,
            'sku' => $r['sku'] ?? null,
            'price' => self::money($r['price'] ?? 0),
            'cost' => self::money($r['cost'] ?? 0),
            'tax' => isset($r['tax']) ? round((float) $r['tax'], 2) : null,
            'description' => $r['description'] ?? null,
            'active' => $r['active'] ?? true,
            'quantity' => 0,
            'origin' => Provenance::HISTORICAL,
        ]);
    }

    public function keys(Model $model): array
    {
        return ['sku' => Index::code($model->sku), 'name' => Index::name($model->name), 'name_en' => Index::name($model->name_en)];
    }
}
