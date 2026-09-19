<?php

namespace App\Support\Import\Recipes;

use App\Support\Import\Context;
use App\Support\Import\Index;
use Illuminate\Database\Eloquent\Model;

/**
 * وصفةُ صنف — كيف يُفحص ويُطابَق ويُكتب سجلٌّ واحد.
 *
 * ═══ الوحدةُ ═══
 *
 * «وحدة» = سجلٌّ أو أكثر من الملفّ تصير كيانًا واحدًا: صفٌّ للعميل، وصفوفٌ
 * برقمٍ واحد لفاتورةٍ ببنودها. كلُّ دوالّ الوصفة تأخذ وحدةً (`list<record>`).
 *
 * ═══ والكتابةُ من خدمات المجال ═══
 *
 * `create()` تنادي `CustomerInvoices::create` و`issue`، و`CustomerPayments::record`،
 * و`SupplierInvoices::create` و`approve` — لا `DB::insert`. فالقيدُ يقع حيث
 * يقع دائمًا، والحرّاسُ في مكانهم. وما ليس له خدمةُ إنشاءٍ تاريخيّ (المشروعُ
 * والعرضُ) يُكتب في **حاله التاريخيّة** بلا دليلِ دورةٍ لم يمرّ بها.
 */
abstract class Recipe
{
    public function __construct(protected readonly Context $ctx) {}

    /** نوعُ الكيان الأوّل في الفهرس (`customer` · `invoice` …) */
    abstract public function type(): string;

    /**
     * فحصُ الجودة على الوحدة: أخطاءٌ تمنع، وتنبيهاتٌ تُقال.
     *
     * @param  list<array<string, mixed>>  $unit
     * @return array{errors: list<string>, warnings: list<string>}
     */
    abstract public function check(array $unit): array;

    /**
     * المطابقةُ على ما في الشركة.
     *
     * @param  list<array<string, mixed>>  $unit
     * @return array{kind: string, id: ?int, candidates: list<int>, why: ?string}
     */
    abstract public function match(array $unit): array;

    /**
     * الكتابة — تعيد الكيانَ المنشأ.
     *
     * @param  list<array<string, mixed>>  $unit
     */
    abstract public function create(array $unit, int $row): Model;

    /** ما يُحدَّث على كيانٍ قائم عند الربط — لا شيءَ افتراضًا: الربطُ لا يكتب فوق ما في النظام */
    public function link(array $unit, Model $existing): void {}

    /** الأثرُ الماليّ للوحدة — تُنادى في التجربة والاستيراد معًا، قبل الكتابة */
    public function impact(array $unit): void {}

    /** المعرِّفُ القديم في الوحدة إن وُجد */
    public function externalId(array $unit): ?string
    {
        $value = $unit[0]['external_id'] ?? null;

        return $value === null ? null : mb_substr((string) $value, 0, 64);
    }

    /** مفاتيحُ الفهرسة لكيانٍ أُنشئ للتوّ */
    abstract public function keys(Model $model): array;

    /** ماذا يُفعل بالمطابقة التامّة افتراضًا — ربطٌ للبيانات المرجعيّة، وتخطٍّ للمستندات */
    public function onExact(): string
    {
        return 'link';
    }

    /** @param  list<int>  $ids */
    protected function verdict(string $kind, array $ids = [], ?string $why = null): array
    {
        return ['kind' => $kind, 'id' => $kind === 'exact' && count($ids) === 1 ? $ids[0] : null, 'candidates' => array_values(array_unique($ids)), 'why' => $why];
    }

    /**
     * مطابقةٌ بمفاتيحَ قويّة ثمّ ضعيفة: قويٌّ واحد = تامّة؛ قويّان يختلفان = تعارض؛ ضعيفٌ = محتمَلة.
     *
     * @param  array<string, mixed>  $strong  المفتاحُ ⇽ القيمة
     * @param  array<string, mixed>  $weak
     */
    protected function matchBy(string $type, array $strong, array $weak): array
    {
        $hits = [];
        foreach ($strong as $key => $value) {
            foreach ($this->ctx->index->find($type, $key, $value) as $id) {
                $hits[$id] = ($hits[$id] ?? 0) + 1;
            }
        }

        if (count($hits) === 1) {
            return $this->verdict('exact', array_keys($hits));
        }
        if (count($hits) > 1) {
            return $this->verdict('conflict', array_keys($hits), __('مفاتيحُ قويّة تشير إلى أكثر من سجلّ'));
        }

        $probable = [];
        foreach ($weak as $key => $value) {
            foreach ($this->ctx->index->find($type, $key, $value) as $id) {
                $probable[$id] = true;
            }
        }

        return $probable === [] ? $this->verdict('new') : $this->verdict('probable', array_keys($probable), __('يطابق بالاسم فقط'));
    }

    protected static function money(mixed $v): float
    {
        return round((float) ($v ?? 0), 3);
    }

    protected function ref(string $type, mixed $value, string $label): Model
    {
        return $this->ctx->resolve($type, $value, $label);
    }

    protected static function label(array $unit, string $field): string
    {
        return (string) ($unit[0][$field] ?? '');
    }

    protected static function code(mixed $value): ?string
    {
        return Index::code($value);
    }
}
