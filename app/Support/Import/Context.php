<?php

namespace App\Support\Import;

use App\Models\ImportDataset;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * سياقُ الاستيراد — ما تحتاجه الوصفةُ لتقرأ وتكتب، ومن أيّ باب.
 *
 * ═══ سياقٌ صريح لا تعطيلٌ عامّ ═══
 *
 * الاستيرادُ لا يُرسل بريدًا ولا واتساب، ولا يُنشئ مشروعًا من قبول عرض، ولا
 * يُصدر فاتورةَ اليوم: لأنّ الوصفةَ تنادي خدماتِ المجال في **وضع الإنشاء**
 * وحدَه (`create` · `issue` · `record`) ولا تنادي `send` ولا `decide`.
 * فلا حارسَ يُعطَّل — الأبوابُ التي تُرسل لا تُطرق أصلًا.
 */
final class Context
{
    public readonly Index $index;

    public readonly ?Carbon $cutover;

    /** ما تراكم من أثر ماليّ خلال التنفيذ — للتجربة والاستيراد معًا */
    public array $financial = [
        'receivables' => 0.0, 'payables' => 0.0, 'revenue' => 0.0, 'tax' => 0.0,
        'collections' => 0.0, 'expenses' => 0.0, 'supplier_cost' => 0.0, 'opening_receivables' => 0.0, 'opening_payables' => 0.0,
    ];

    public function __construct(
        public readonly ImportDataset $dataset,
        public readonly ?User $actor,
        public readonly bool $dryRun,
    ) {
        $this->index = new Index((int) $dataset->business_id);
        $this->cutover = Cutover::date((int) $dataset->business_id);
    }

    public function businessId(): int
    {
        return (int) $this->dataset->business_id;
    }

    public function beforeCutover(?string $date): bool
    {
        return $this->cutover !== null && $date !== null && Carbon::parse($date)->lt($this->cutover);
    }

    /**
     * حلُّ مرجعٍ إلى كيانٍ قائم — بمعرِّفه القديم، أو بمفتاحٍ معياريّ (هاتفٌ ·
     * بريدٌ · رقمٌ)، أو باسمه إن كان الاسمُ واحدًا لا يلتبس.
     *
     * @throws RuntimeException حين يلتبس أو لا يوجد
     */
    public function resolve(string $type, mixed $value, string $label): Model
    {
        if (Transforms::blank($value)) {
            throw new RuntimeException(__(':label فارغ', ['label' => $label]));
        }

        $text = trim((string) $value);

        if (($hit = $this->index->byExternal($type, $text)) !== null) {
            return $hit;
        }

        $keys = match ($type) {
            'customer' => ['phone' => Index::phone($text), 'email' => Index::email($text), 'tax' => Index::code($text), 'reference' => Index::code($text)],
            'supplier' => ['phone' => Index::phone($text), 'email' => Index::email($text)],
            'project' => ['code' => Index::code($text)],
            'invoice' => ['number' => Index::code($text), 'reference' => Index::code($text), 'number_hist' => Index::code('HIST-'.$text)],
            default => [],
        };

        foreach ($keys as $key => $needle) {
            $ids = $this->index->find($type, $key === 'number_hist' ? 'number' : $key, $needle);
            if (count($ids) === 1) {
                return $this->index->get($type, $ids[0]);
            }
            if (count($ids) > 1) {
                throw new RuntimeException(__(':label «:v» يطابق أكثر من سجلّ — حدّده بمعرِّفه', ['label' => $label, 'v' => Transforms::short($text)]));
            }
        }

        foreach (['name', 'legal', 'name_en'] as $key) {
            $ids = $this->index->find($type, $key, Index::name($text));
            if (count($ids) === 1) {
                return $this->index->get($type, $ids[0]);
            }
            if (count($ids) > 1) {
                throw new RuntimeException(__(':label «:v» يطابق أكثر من سجلّ بالاسم — حدّده بمعرِّفه', ['label' => $label, 'v' => Transforms::short($text)]));
            }
        }

        throw new RuntimeException(__(':label «:v» غير موجود — استورد العملاء/المورّدين أوّلًا', ['label' => $label, 'v' => Transforms::short($text)]));
    }

    public function addFinancial(string $key, float $amount): void
    {
        $this->financial[$key] = round(($this->financial[$key] ?? 0.0) + $amount, 3);
    }
}
