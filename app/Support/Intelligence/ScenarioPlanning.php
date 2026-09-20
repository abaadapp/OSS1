<?php

namespace App\Support\Intelligence;

use App\Models\FinancialScenario;
use App\Models\FinancialScenarioAssumption;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * السيناريوهاتُ الماليّة — محاكاةٌ لا تمسّ قيدًا ولا مستندًا. المرحلة 11.
 *
 * ثلاثةٌ جاهزة (أساسٌ · متحفّظ · نموّ) تُكتب مرّةً لشركةٍ لا سيناريوَ لها،
 * وما تشاء الشركةُ بعدها. والافتراضاتُ من قائمةٍ مغلقة بمبلغٍ أو نسبة.
 */
final class ScenarioPlanning
{
    public const KEY_LABELS = [
        'revenue_change_pct' => 'تغيّر الإيراد المتوقَّع (٪)',
        'collection_delay_days' => 'تأخّر التحصيل (أيّام)',
        'expense_change_pct' => 'تغيّر المصروفات (٪)',
        'one_time_outflow' => 'مصروف لمرّة واحدة',
        'monthly_outflow' => 'مصروف شهريّ جديد',
        'monthly_inflow' => 'دخل شهريّ جديد',
        'expected_contract' => 'عقد متوقَّع',
    ];

    public const KIND_LABELS = [
        FinancialScenario::BASE => 'الأساس', FinancialScenario::CONSERVATIVE => 'متحفّظ',
        FinancialScenario::GROWTH => 'نموّ', FinancialScenario::CUSTOM => 'مخصَّص',
    ];

    /** الثلاثةُ الجاهزة — بلا مسّ ما كُتب */
    public static function ensure(int $bid): void
    {
        if (FinancialScenario::where('business_id', $bid)->exists()) {
            return;
        }

        DB::transaction(function () use ($bid) {
            FinancialScenario::create(['business_id' => $bid, 'name' => 'الأساس', 'kind' => FinancialScenario::BASE, 'description' => 'الافتراضات الحاليّة بلا تغيير']);

            $c = FinancialScenario::create(['business_id' => $bid, 'name' => 'متحفّظ', 'kind' => FinancialScenario::CONSERVATIVE, 'description' => 'إيرادٌ أقلّ وتحصيلٌ أبطأ ومصروفاتٌ أعلى']);
            FinancialScenarioAssumption::create(['scenario_id' => $c->id, 'key' => 'revenue_change_pct', 'label' => 'إيراد أقلّ', 'percent' => -20, 'sort_order' => 0]);
            FinancialScenarioAssumption::create(['scenario_id' => $c->id, 'key' => 'collection_delay_days', 'label' => 'تحصيل أبطأ', 'amount' => 30, 'sort_order' => 1]);
            FinancialScenarioAssumption::create(['scenario_id' => $c->id, 'key' => 'expense_change_pct', 'label' => 'مصروفات أعلى', 'percent' => 10, 'sort_order' => 2]);

            $g = FinancialScenario::create(['business_id' => $bid, 'name' => 'نموّ', 'kind' => FinancialScenario::GROWTH, 'description' => 'إيرادٌ أعلى مع توظيفٍ واستثمار']);
            FinancialScenarioAssumption::create(['scenario_id' => $g->id, 'key' => 'revenue_change_pct', 'label' => 'إيراد أعلى', 'percent' => 25, 'sort_order' => 0]);
            FinancialScenarioAssumption::create(['scenario_id' => $g->id, 'key' => 'expense_change_pct', 'label' => 'مصروفات أعلى', 'percent' => 15, 'sort_order' => 1]);
        });
    }

    /** @param  array<string, mixed>  $data */
    public static function create(int $bid, array $data, ?User $actor = null): FinancialScenario
    {
        $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 120);
        if ($name === '') {
            throw new RuntimeException(__('اسمُ السيناريو مطلوب.'));
        }

        return DB::transaction(function () use ($bid, $data, $name, $actor) {
            $scenario = FinancialScenario::create([
                'business_id' => $bid, 'name' => $name, 'kind' => FinancialScenario::CUSTOM,
                'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 2000) ?: null, 'active' => true, 'created_by' => $actor?->id,
            ]);
            self::writeAssumptions($scenario, (array) ($data['assumptions'] ?? []));

            Activity::log('created', 'أنشأ السيناريو المالي «'.$name.'»', [
                'business_id' => $bid, 'subject_id' => $scenario->id, 'subject_type' => 'financial_scenario', 'icon' => 'git-branch',
            ]);

            return $scenario->fresh('assumptions');
        });
    }

    /** @param  array<string, mixed>  $data */
    public static function update(FinancialScenario $scenario, array $data, ?User $actor = null): FinancialScenario
    {
        return DB::transaction(function () use ($scenario, $data, $actor) {
            $update = [];
            if (array_key_exists('name', $data)) {
                $update['name'] = mb_substr(trim((string) $data['name']), 0, 120) ?: $scenario->name;
            }
            if (array_key_exists('description', $data)) {
                $update['description'] = mb_substr(trim((string) $data['description']), 0, 2000) ?: null;
            }
            if (array_key_exists('active', $data)) {
                $update['active'] = (bool) $data['active'];
            }
            $scenario->update($update);

            if (array_key_exists('assumptions', $data)) {
                $scenario->assumptions()->delete();
                self::writeAssumptions($scenario, (array) $data['assumptions']);
            }

            Activity::log('updated', 'عدّل السيناريو المالي «'.$scenario->name.'»', [
                'business_id' => (int) $scenario->business_id, 'subject_id' => $scenario->id, 'subject_type' => 'financial_scenario',
            ]);

            return $scenario->fresh('assumptions');
        });
    }

    public static function delete(FinancialScenario $scenario, ?User $actor = null): void
    {
        $scenario->delete(); /* والتوقّعاتُ التي بُنيت عليه تبقى بلقطتها — `scenario_id` يصير فارغًا */
    }

    /** @param  array<int, mixed>  $rows */
    private static function writeAssumptions(FinancialScenario $scenario, array $rows): void
    {
        $order = 0;
        foreach ($rows as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $key = (string) ($raw['key'] ?? '');
            if (! in_array($key, FinancialScenarioAssumption::KEYS, true)) {
                throw new RuntimeException(__('افتراضٌ غير معروف: :k', ['k' => $key]));
            }
            $amount = isset($raw['amount']) && is_numeric($raw['amount']) ? round((float) $raw['amount'], 3) : null;
            $percent = isset($raw['percent']) && is_numeric($raw['percent']) ? round((float) $raw['percent'], 3) : null;
            if (in_array($key, ['revenue_change_pct', 'expense_change_pct'], true) && $percent === null) {
                throw new RuntimeException(__('الافتراض «:k» يحتاج نسبة.', ['k' => __(self::KEY_LABELS[$key])]));
            }
            if (! in_array($key, ['revenue_change_pct', 'expense_change_pct'], true) && $amount === null) {
                throw new RuntimeException(__('الافتراض «:k» يحتاج مبلغًا.', ['k' => __(self::KEY_LABELS[$key])]));
            }
            if ($percent !== null && ($percent < -100 || $percent > 500)) {
                throw new RuntimeException(__('النسبة بين -100 و500.'));
            }

            FinancialScenarioAssumption::create([
                'scenario_id' => $scenario->id, 'key' => $key,
                'label' => mb_substr(trim((string) ($raw['label'] ?? '')) ?: __(self::KEY_LABELS[$key]), 0, 160),
                'amount' => $amount, 'percent' => $percent,
                'starts_on' => filled($raw['starts_on'] ?? null) ? Carbon::parse((string) $raw['starts_on'])->toDateString() : null,
                'months' => isset($raw['months']) && is_numeric($raw['months']) ? max(1, min(60, (int) $raw['months'])) : null,
                'notes' => mb_substr(trim((string) ($raw['notes'] ?? '')), 0, 255) ?: null,
                'sort_order' => $order++,
            ]);
        }
    }
}
