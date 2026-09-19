<?php

namespace App\Support\Automation;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\User;
use App\Support\Communication\Subjects;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * مُطلِقٌ — يعرف كيف يجد أهدافَه بالنافذة، ويعيد فحصَها قبل الفعل، ويصفها.
 *
 * ═══ الكشفُ بالاستعلام لا بالمسح ═══
 *
 * `candidates()` تسأل القاعدةَ عمّا مرساتُه (تاريخُ الاستحقاق، تاريخُ
 * الإرسال، تاريخُ البدء) داخل نافذةٍ محسوبةٍ من خطوات القاعدة — لا تحمّل
 * الجدولَ كلَّه لتفحصه في PHP.
 *
 * ═══ والتأهيلُ لحظةَ الفعل ═══
 *
 * `qualify()` تُنادى على نسخةٍ طازجة من الهدف قبل كلّ تنفيذ: عرضٌ قُبل
 * منذ التقييم، وفاتورةٌ سُدّدت، ومهمّةٌ أُنجزت — كلُّها تُتخطّى بسببها.
 */
abstract class Trigger
{
    abstract public function key(): string;

    abstract public function label(): string;

    /** نوعُ الموضوع في المرحلة 6 (`quotation`، `customer_invoice`…) — أو null لما لا يبلغ العميل */
    abstract public function subjectType(): ?string;

    /** @return list<string> */
    abstract public function audiences(): array;

    /**
     * الأهدافُ التي مرساتُها بين التاريخين (شاملًا).
     *
     * @return iterable<Model>
     */
    abstract public function candidates(int $businessId, string $from, string $to, AutomationRule $rule): iterable;

    /** مرساةُ الهدف الآن — تاريخٌ `Y-m-d` أو وقتٌ كامل، أو null إن زالت */
    abstract public function anchor(Model $target): ?string;

    /** null = مؤهَّل؛ وإلّا سببُ التخطّي (مفتاحٌ من `Engine::REASONS`) */
    abstract public function qualify(Model $target, array $step, AutomationRule $rule): ?string;

    /** السطرُ الذي يقرؤه الموظّف: ماذا وماذا يُفعل */
    abstract public function describe(Model $target, array $step): string;

    abstract public function url(Model $target): string;

    /** هل المرساةُ لحظةٌ لا يومًا؟ — فتُنفَّذ الخطوةُ فورًا لا في ساعة الإرسال */
    public function immediate(): bool
    {
        return false;
    }

    /** الموضوعُ في المرحلة 6 — الهدفُ نفسُه غالبًا */
    public function subject(Model $target): ?Model
    {
        return $target;
    }

    public function targetLabel(Model $target): string
    {
        return (string) (Subjects::label($this->subject($target)) ?? $this->describe($target, ['offset' => 0, 'audience' => '']));
    }

    /** الهدفُ طازجًا من القاعدة — داخل الشركة وحدَها */
    public function find(int $businessId, string $type, int $id): ?Model
    {
        if (! class_exists($type)) {
            return null;
        }

        /** @var class-string<Model> $type */
        $query = $type::query()->whereKey($id);

        return (new $type)->getConnection()->getSchemaBuilder()->hasColumn((new $type)->getTable(), 'business_id')
            ? $query->where('business_id', $businessId)->first()
            : $query->first();
    }

    /**
     * فعلٌ خاصّ بدل التنبيه الداخليّ القياسيّ — أو null ليفعل المحرّكُ المعتاد.
     *
     * @param  Collection<int, User>  $users
     * @return array{result: string, notification_count?: int, communication_id?: ?int}|null
     */
    public function handle(AutomationRun $run, AutomationRule $rule, Model $target, array $step, Collection $users, Settings $settings): ?array
    {
        return null;
    }
}
