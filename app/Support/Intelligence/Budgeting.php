<?php

namespace App\Support\Intelligence;

use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\User;
use App\Support\Activity;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * الموازنةُ السنويّة — بنودٌ على حسابات الشجرة شهرًا بشهر، وبنسخٍ لا تُمحى. المرحلة 11.
 *
 * ═══ الدورة ═══
 *
 * مسودّةٌ تُكتب وتُعدَّل → تُعتمد (واحدةٌ معتمَدة لكلّ سنة؛ السابقةُ تصير
 * «محلولةً محلَّها») → تُنقَّح: نسخةٌ جديدة من المعتمَدة تبدأ مسودّةً وتحمل
 * سببَ التنقيح ومن ومتى. وأرشيفٌ لما لا يُراد بعد. والمعتمَدةُ لا تُعدَّل
 * بنودُها أبدًا.
 *
 * ═══ والفعليُّ من الدفتر ═══
 *
 * بنودُها حساباتُ إيرادٍ ومصروفٍ من الشجرة، فالفعليُّ صافي سطورِ اليوميّة على
 * الحساب في الشهر — لا تعريفَ ثانيًا للمصروف.
 */
final class Budgeting
{
    public const STATUS_LABELS = [
        Budget::DRAFT => 'مسودّة', Budget::APPROVED => 'معتمَدة', Budget::SUPERSEDED => 'حلّت محلّها نسخة', Budget::ARCHIVED => 'مؤرشفة',
    ];

    /** @param  array<string, mixed>  $data */
    public static function create(int $bid, array $data, ?User $actor = null): Budget
    {
        $year = (int) ($data['fiscal_year'] ?? 0);
        if ($year < 2000 || $year > 2100) {
            throw new RuntimeException(__('السنة المالية غير صالحة.'));
        }

        return DB::transaction(function () use ($bid, $data, $year, $actor) {
            $version = (int) Budget::where('business_id', $bid)->where('fiscal_year', $year)->max('version') + 1;

            $budget = Budget::create([
                'business_id' => $bid, 'fiscal_year' => $year, 'version' => $version,
                'name' => mb_substr(trim((string) ($data['name'] ?? '')) ?: __('موازنة :y', ['y' => $year]), 0, 120),
                'status' => Budget::DRAFT, 'currency' => Money::of($bid)['code'] ?? null,
                'notes' => mb_substr(trim((string) ($data['notes'] ?? '')), 0, 5000) ?: null, 'created_by' => $actor?->id,
            ]);

            if (! empty($data['lines'])) {
                self::writeLines($budget, (array) $data['lines']);
            }

            Activity::log('created', 'أنشأ الموازنة «'.$budget->name.'» v'.$version.' للسنة '.$year, [
                'business_id' => $bid, 'subject_id' => $budget->id, 'subject_type' => 'budget', 'icon' => 'calculator',
            ]);

            return $budget->fresh('lines');
        });
    }

    /**
     * كتابةُ البنود — المسودّةُ وحدَها.
     *
     * @param  array<string, mixed>  $data
     */
    public static function update(Budget $budget, array $data, ?User $actor = null): Budget
    {
        if (! $budget->isDraft()) {
            throw new RuntimeException(__('لا تُعدَّل إلّا موازنةٌ مسودّة — نقّح المعتمَدة بنسخةٍ جديدة.'));
        }

        return DB::transaction(function () use ($budget, $data, $actor) {
            $update = [];
            if (array_key_exists('name', $data)) {
                $update['name'] = mb_substr(trim((string) $data['name']), 0, 120) ?: $budget->name;
            }
            if (array_key_exists('notes', $data)) {
                $update['notes'] = mb_substr(trim((string) $data['notes']), 0, 5000) ?: null;
            }
            $budget->update($update);

            if (array_key_exists('lines', $data)) {
                $budget->lines()->delete();
                self::writeLines($budget, (array) $data['lines']);
            }

            return $budget->fresh('lines');
        });
    }

    /** الاعتمادُ — واحدةٌ معتمَدة لكلّ سنة */
    public static function approve(Budget $budget, ?User $actor = null): Budget
    {
        if (! ($actor?->may(Permissions::BUDGETS_APPROVE) ?? false)) {
            throw new RuntimeException(__('اعتمادُ الموازنة يحتاج صلاحيّة الاعتماد.'));
        }
        if ($budget->isApproved()) {
            return $budget;
        }
        if (! $budget->isDraft()) {
            throw new RuntimeException(__('لا تُعتمد إلّا موازنةٌ مسودّة.'));
        }
        if (! $budget->lines()->exists()) {
            throw new RuntimeException(__('الموازنة بلا بنود — لا تُعتمد فارغة.'));
        }

        return DB::transaction(function () use ($budget, $actor) {
            $previous = Budget::where('business_id', $budget->business_id)->where('fiscal_year', $budget->fiscal_year)
                ->where('status', Budget::APPROVED)->whereKeyNot($budget->id)->get();
            foreach ($previous as $p) {
                $p->update(['status' => Budget::SUPERSEDED, 'superseded_at' => now()]);
            }

            $budget->update(['status' => Budget::APPROVED, 'approved_at' => now(), 'approved_by' => $actor?->id]);

            Activity::log('status', 'اعتمد الموازنة «'.$budget->name.'» v'.$budget->version.($previous->isNotEmpty() ? ' — وحلّت محلّ v'.$previous->pluck('version')->implode('، ') : ''), [
                'business_id' => (int) $budget->business_id, 'subject_id' => $budget->id, 'subject_type' => 'budget', 'color' => 'success',
            ]);

            return $budget->fresh();
        });
    }

    /** التنقيحُ — نسخةٌ جديدة من المعتمَدة، مسودّةً، بسببٍ ومن ومتى */
    public static function revise(Budget $budget, string $reason, ?User $actor = null): Budget
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException(__('اكتب سبب التنقيح.'));
        }
        if (! $budget->isApproved()) {
            throw new RuntimeException(__('لا يُنقَّح إلّا موازنةٌ معتمَدة.'));
        }
        if (Budget::where('business_id', $budget->business_id)->where('fiscal_year', $budget->fiscal_year)->where('status', Budget::DRAFT)->exists()) {
            throw new RuntimeException(__('ثمّة مسودّةٌ قائمة لهذه السنة — أكملها أو أرشفها.'));
        }

        return DB::transaction(function () use ($budget, $reason, $actor) {
            $version = (int) Budget::where('business_id', $budget->business_id)->where('fiscal_year', $budget->fiscal_year)->max('version') + 1;
            $copy = Budget::create([
                'business_id' => $budget->business_id, 'fiscal_year' => $budget->fiscal_year, 'version' => $version,
                'name' => $budget->name, 'status' => Budget::DRAFT, 'currency' => $budget->currency, 'notes' => $budget->notes,
                'revision_reason' => mb_substr($reason, 0, 255), 'revised_from_id' => $budget->id, 'created_by' => $actor?->id,
            ]);
            foreach ($budget->lines as $line) {
                BudgetLine::create(['budget_id' => $copy->id, 'account_id' => $line->account_id, 'kind' => $line->kind, 'month' => $line->month,
                    'planned_amount' => $line->planned_amount, 'notes' => $line->notes]);
            }

            Activity::log('created', 'نقّح الموازنة «'.$budget->name.'» إلى v'.$version.' — '.$reason, [
                'business_id' => (int) $budget->business_id, 'subject_id' => $copy->id, 'subject_type' => 'budget', 'icon' => 'git-branch', 'color' => 'warning',
            ]);

            return $copy->fresh('lines');
        });
    }

    public static function archive(Budget $budget, ?User $actor = null): Budget
    {
        if ($budget->isApproved()) {
            throw new RuntimeException(__('المعتمَدة لا تُؤرشف — نقّحها أو اعتمد غيرها.'));
        }
        if ($budget->status === Budget::ARCHIVED) {
            return $budget;
        }
        $budget->update(['status' => Budget::ARCHIVED, 'archived_at' => now()]);

        Activity::log('status', 'أرشف الموازنة «'.$budget->name.'» v'.$budget->version, [
            'business_id' => (int) $budget->business_id, 'subject_id' => $budget->id, 'subject_type' => 'budget', 'color' => 'gray',
        ]);

        return $budget->fresh();
    }

    /** المعتمَدةُ لسنةٍ — أو لا شيء */
    public static function approvedFor(int $bid, int $year): ?Budget
    {
        return Budget::where('business_id', $bid)->where('fiscal_year', $year)->where('status', Budget::APPROVED)->first();
    }

    /**
     * الحساباتُ التي تقبل بندَ موازنة — إيرادٌ ومصروفٌ قابلةٌ للترحيل.
     *
     * @return list<array{id: int, code: string, name: string, kind: string}>
     */
    public static function accounts(int $bid): array
    {
        Ledger::seedChart($bid);

        return Account::where('business_id', $bid)->whereIn('type', ['إيراد', 'مصروف'])->orderBy('code')->get()
            ->filter(fn (Account $a) => $a->isPostable())
            ->map(fn (Account $a) => ['id' => (int) $a->id, 'code' => (string) $a->code, 'name' => (string) $a->name, 'kind' => $a->type === 'إيراد' ? BudgetLine::REVENUE : BudgetLine::EXPENSE])
            ->values()->all();
    }

    /** @param  array<int, mixed>  $lines */
    private static function writeLines(Budget $budget, array $lines): void
    {
        $accounts = collect(self::accounts((int) $budget->business_id))->keyBy('id');
        $seen = [];

        foreach ($lines as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $accountId = (int) ($raw['account_id'] ?? 0);
            $month = (int) ($raw['month'] ?? 0);
            $amount = round((float) ($raw['planned_amount'] ?? 0), 3);
            if (! isset($accounts[$accountId])) {
                throw new RuntimeException(__('الحساب ليس من شجرة شركتك أو لا يقبل موازنة.'));
            }
            if ($month < 1 || $month > 12) {
                throw new RuntimeException(__('الشهر بين 1 و12.'));
            }
            if ($amount < 0) {
                throw new RuntimeException(__('مبلغُ الموازنة لا يكون سالبًا.'));
            }
            $key = $accountId.'-'.$month;
            if (isset($seen[$key])) {
                throw new RuntimeException(__('بندٌ مكرَّر للحساب والشهر نفسِهما.'));
            }
            $seen[$key] = true;
            if ($amount <= 0.0005) {
                continue;
            }

            BudgetLine::create([
                'budget_id' => $budget->id, 'account_id' => $accountId, 'kind' => $accounts[$accountId]['kind'], 'month' => $month,
                'planned_amount' => $amount, 'notes' => mb_substr(trim((string) ($raw['notes'] ?? '')), 0, 255) ?: null,
            ]);
        }
    }

    /**
     * الموازنةُ مقابلَ الفعليّ — بالشهر أو الربع أو السنة، وبالحساب.
     *
     * الفعليُّ صافي سطور اليوميّة على الحساب في المدّة. والانحرافُ = الفعليّ − الموازنة:
     * للمصروف موجبُه إنفاقٌ فوق الخطّة، وللإيراد موجبُه تحصيلٌ فوق الخطّة — ويُقال
     * بالوقائع لا بـ«جيّد/سيّئ».
     *
     * @return array<string, mixed>
     */
    public static function versusActual(Budget $budget, string $grouping = 'month'): array
    {
        $bid = (int) $budget->business_id;
        $year = (int) $budget->fiscal_year;
        $lines = $budget->lines()->with('account')->get();
        $accounts = $lines->pluck('account')->unique('id')->filter()->values();

        $groups = match ($grouping) {
            'quarter' => [[1, 3], [4, 6], [7, 9], [10, 12]],
            'year' => [[1, 12]],
            default => array_map(fn ($m) => [$m, $m], range(1, 12)),
        };

        $rows = [];
        foreach ($accounts as $account) {
            $kind = $account->type === 'إيراد' ? BudgetLine::REVENUE : BudgetLine::EXPENSE;
            $buckets = [];
            foreach ($groups as [$from, $to]) {
                $period = Period::between($bid, Carbon::create($year, $from, 1)->startOfMonth(), Carbon::create($year, $to, 1)->startOfMonth()->addMonth());
                $planned = round((float) $lines->where('account_id', $account->id)->whereBetween('month', [$from, $to])->sum('planned_amount'), 3);
                $actual = FinancialPerformance::accountNet($account, $period);
                $variance = round($actual - $planned, 3);
                $buckets[] = [
                    'label' => $grouping === 'month' ? sprintf('%04d-%02d', $year, $from) : ($grouping === 'quarter' ? 'Q'.(int) ceil($to / 3) : (string) $year),
                    'planned' => $planned,
                    'actual' => $actual,
                    'variance' => $variance,
                    'variance_percent' => $planned > 0.0005 ? round($variance / $planned * 100, 1) : null,
                    'reading' => self::reading($kind, $variance, $planned),
                    'elapsed' => $period->end->lte(now()),
                ];
            }
            $planned = round((float) array_sum(array_column($buckets, 'planned')), 3);
            $actual = round((float) array_sum(array_column($buckets, 'actual')), 3);
            $rows[] = [
                'account_id' => (int) $account->id, 'code' => (string) $account->code, 'name' => (string) $account->name, 'kind' => $kind,
                'buckets' => $buckets, 'planned' => $planned, 'actual' => $actual, 'variance' => round($actual - $planned, 3),
                'variance_percent' => $planned > 0.0005 ? round(($actual - $planned) / $planned * 100, 1) : null,
                'reading' => self::reading($kind, round($actual - $planned, 3), $planned),
            ];
        }

        $sum = fn (string $kind, string $key) => round((float) collect($rows)->where('kind', $kind)->sum($key), 3);

        return [
            'budget' => ['id' => $budget->id, 'name' => $budget->name, 'version' => $budget->version, 'fiscal_year' => $year, 'status' => $budget->status],
            'grouping' => $grouping,
            'rows' => $rows,
            'totals' => [
                'revenue' => ['planned' => $sum(BudgetLine::REVENUE, 'planned'), 'actual' => $sum(BudgetLine::REVENUE, 'actual'), 'variance' => round($sum(BudgetLine::REVENUE, 'actual') - $sum(BudgetLine::REVENUE, 'planned'), 3)],
                'expense' => ['planned' => $sum(BudgetLine::EXPENSE, 'planned'), 'actual' => $sum(BudgetLine::EXPENSE, 'actual'), 'variance' => round($sum(BudgetLine::EXPENSE, 'actual') - $sum(BudgetLine::EXPENSE, 'planned'), 3)],
            ],
            'currency' => Money::of($bid),
            'basis' => __('الفعليُّ صافي سطور اليوميّة على الحساب في المدّة؛ والانحرافُ = الفعليّ − الموازنة'),
        ];
    }

    /** قراءةُ الانحراف بالوقائع */
    public static function reading(string $kind, float $variance, float $planned): string
    {
        if ($planned <= 0.0005 && abs($variance) <= 0.0005) {
            return 'no_plan';
        }
        if (abs($variance) <= 0.0005) {
            return 'on_plan';
        }
        if ($kind === BudgetLine::EXPENSE) {
            return $variance > 0 ? 'spent_above_plan' : 'spent_below_plan';
        }

        return $variance > 0 ? 'earned_above_plan' : 'earned_below_plan';
    }

    public const READING_LABELS = [
        'no_plan' => 'بلا خطّة', 'on_plan' => 'على الخطّة', 'spent_above_plan' => 'إنفاقٌ فوق الخطّة', 'spent_below_plan' => 'إنفاقٌ دون الخطّة',
        'earned_above_plan' => 'إيرادٌ فوق الخطّة', 'earned_below_plan' => 'إيرادٌ دون الخطّة',
    ];
}
