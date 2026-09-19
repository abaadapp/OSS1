<?php

namespace App\Support\Import\Recipes;

use App\Models\Supplier;
use App\Support\Import\Index;
use App\Support\Import\Provenance;
use Illuminate\Database\Eloquent\Model;

/** المورّدون — كالعملاء: الهاتفُ والبريدُ مفاتيحُ قويّة، والاسمُ ضعيف */
final class Suppliers extends Recipe
{
    public function type(): string
    {
        return 'supplier';
    }

    public function check(array $unit): array
    {
        $r = $unit[0];
        $warnings = empty($r['phone']) && empty($r['email']) ? [__('بلا هاتفٍ ولا بريد')] : [];

        return ['errors' => [], 'warnings' => $warnings];
    }

    public function match(array $unit): array
    {
        $r = $unit[0];

        return $this->matchBy('supplier', [
            'external' => Index::code($r['external_id'] ?? null),
            'phone' => Index::phone($r['phone'] ?? null),
            'email' => Index::email($r['email'] ?? null),
        ], ['name' => Index::name($r['name'] ?? null), 'name_en' => Index::name($r['name_en'] ?? null)]);
    }

    public function create(array $unit, int $row): Model
    {
        $r = $unit[0];

        return Supplier::create([
            'business_id' => $this->ctx->businessId(),
            'name' => $r['name'],
            'name_en' => $r['name_en'] ?? null,
            'phone' => $r['phone'] ?? null,
            'email' => $r['email'] ?? null,
            'contact_person' => $r['contact_person'] ?? null,
            'notes' => $r['notes'] ?? null,
            'origin' => Provenance::HISTORICAL,
        ]);
    }

    public function keys(Model $model): array
    {
        return ['phone' => Index::phone($model->phone), 'email' => Index::email($model->email), 'name' => Index::name($model->name), 'name_en' => Index::name($model->name_en)];
    }
}
