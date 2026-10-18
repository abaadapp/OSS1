<?php

namespace App\Support\Exports;

use Closure;
use Countable;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use IteratorAggregate;

/**
 * صفوفُ ملفٍّ من استعلامٍ مرتَّب — كلُّ ما طابق، دفعةً بعد دفعة، بلا سقف.
 *
 * كان الملفُّ يُبنى صفوفًا في الذاكرة حتى سقفٍ لكلّ صيغة (عشرون ألفًا لـCSV،
 * وعشرةُ آلافٍ لإكسل)، ويقول في آخره إنّه بُتر. ومحاسبٌ يجمع عمودًا لا يقرأ
 * السطرَ الأخير. فصارت الصفوفُ تُقرأ حين تُكتب: خمسمئةً خمسمئة، والذاكرةُ دفعةٌ
 * واحدةٌ لا الجدولُ كلّه.
 *
 * ═══ ولقطةُ المفاتيح قبل الدفعات ═══
 *
 * الدفعاتُ بالإزاحة (`offset`) تُسقط صفًّا أو تكرّره إن كُتب في الجدول شيءٌ أثناء
 * التصدير: قيدٌ جديدٌ في أعلى القائمة يدفع ما تحته صفًّا، فيُقرأ آخرُ الدفعة
 * مرّتين. فتُقرأ مفاتيحُ ما طابق أوّلًا مرّةً واحدةً بترتيب الشاشة، ثمّ تُجلب
 * الصفوفُ بمفاتيحها: لا يتكرّر صفٌّ ولا يسقط، والترتيبُ ترتيبُ الشاشة بعينه.
 */
final class Rows implements Countable, IteratorAggregate
{
    /** حجمُ الدفعة */
    public const CHUNK = 500;

    private ?int $counted = null;

    /**
     * @param  Closure(Collection): iterable<array<string, mixed>>  $chunk  دفعةُ نماذج ← صفوفُها
     * @param  Closure(): int|null  $counter  عددُ الصفوف إن لم يكن عددَ النماذج (قيدٌ بسطوره)
     */
    private function __construct(
        private readonly Builder $query,
        private readonly Closure $chunk,
        private readonly ?Closure $counter = null,
    ) {}

    /** صفٌّ لكلّ نموذج */
    public static function each(Builder $query, callable $row): self
    {
        $row = $row(...);

        return new self($query, fn (Collection $models) => $models->map(fn ($model) => $row($model))->all());
    }

    /**
     * دفعةٌ كاملةٌ في كلّ مرّة — لمن يقرأ ما حول الصفّ (مراحلُ المشاريع دفعةً،
     * أو سطورُ القيد صفوفًا) — وعددُ الصفوف من `$counter` إن لم يكن عددَ النماذج.
     */
    public static function chunked(Builder $query, callable $chunk, ?callable $counter = null): self
    {
        return new self($query, $chunk(...), $counter === null ? null : $counter(...));
    }

    /** عددُ الصفوف التي سيحملها الملفّ — بلا قراءتها */
    public function count(): int
    {
        return $this->counted ??= $this->counter !== null
            ? (int) ($this->counter)()
            : (clone $this->query)->toBase()->getCountForPagination();
    }

    public function getIterator(): Generator
    {
        $key = $this->query->getModel()->getQualifiedKeyName();

        foreach (array_chunk($this->keys($key), self::CHUNK) as $slice) {
            $position = array_flip($slice);
            $models = (clone $this->query)->whereIn($key, $slice)->get()
                ->sortBy(fn ($model) => $position[$model->getKey()] ?? PHP_INT_MAX)
                ->values();

            foreach (($this->chunk)($models) as $row) {
                yield $row;
            }
        }
    }

    /**
     * مفاتيحُ ما طابق بترتيب الاستعلام — عمودٌ واحدٌ يُقرأ سطرًا سطرًا.
     *
     * واستعلامٌ اختار أعمدتَه يبقى كما هو ويُضاف المفتاحُ إليها: قد يرتّب بعمودٍ
     * مستعارٍ منها (`withMoney`)، ونزعُها يُسقط الترتيب.
     *
     * @return list<int|string>
     */
    private function keys(string $key): array
    {
        $base = (clone $this->query)->toBase();
        $base->columns === null ? $base->select($key.' as __row_key') : $base->addSelect($key.' as __row_key');

        $keys = [];
        foreach ($base->cursor() as $record) {
            $keys[] = $record->__row_key;
        }

        return $keys;
    }
}
