<?php

namespace App\Support\Import\Recipes;

use App\Models\Customer;
use App\Support\Import\Index;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * جهاتُ الاتصال — لا كيانَ مستقلًّا لها اليوم: تُكتب في حقول جهة الاتصال على العميل.
 *
 * الصفُّ يُربط دائمًا بعميلٍ قائم (لا يُنشأ عميلٌ من جهة اتصال)، ويملأ
 * `contact_person` وبريدَه وهاتفَه إن كانت فارغة — ولا يكتب فوق ما في النظام.
 */
final class Contacts extends Recipe
{
    public function type(): string
    {
        return 'customer';
    }

    public function check(array $unit): array
    {
        $errors = [];
        try {
            $this->ref('customer', $unit[0]['customer'] ?? null, __('العميل'));
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }

        return ['errors' => $errors, 'warnings' => []];
    }

    public function match(array $unit): array
    {
        try {
            $customer = $this->ref('customer', $unit[0]['customer'] ?? null, __('العميل'));
        } catch (RuntimeException $e) {
            return $this->verdict('conflict', [], $e->getMessage());
        }

        return $this->verdict('exact', [(int) $customer->id]);
    }

    public function create(array $unit, int $row): Model
    {
        throw new RuntimeException(__('جهةُ الاتصال تُربط بعميلٍ قائم ولا تُنشئ عميلًا.'));
    }

    public function link(array $unit, Model $existing): void
    {
        /** @var Customer $existing */
        $r = $unit[0];
        $existing->update([
            'contact_person' => $existing->contact_person ?: ($r['contact_person'] ?? null),
            'contact_email' => $existing->contact_email ?: ($r['contact_email'] ?? null),
            'contact_phone' => $existing->contact_phone ?: ($r['contact_phone'] ?? null),
            'department' => $existing->department ?: ($r['department'] ?? null),
        ]);
    }

    public function keys(Model $model): array
    {
        return ['name' => Index::name($model->name)];
    }
}
