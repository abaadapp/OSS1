<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * فروعُ العمل — من أين يعمل الموظّف، في مكانٍ واحد.
 *
 * ═══ المعنى ═══
 *
 * - صاحبُ النشاط: كلُّ فروع نشاطه، دائمًا.
 * - موظّفٌ بلا صفوفٍ في `branch_user`: كلُّ فروع النشاط. هكذا كان كلُّ
 *   موظّفٍ قبل أن يُفرض الإذن، وفراغٌ يصير منعًا يُقفل الموظفين صباحَ النشر.
 * - موظّفٌ له صفوف: هذه الفروع وحدها. والصفُّ يُقرأ من الجدول نفسه لا من
 *   العلاقة: فرعٌ حُذف (حذفًا ليّنًا) يبقى صفُّه، فيبقى صاحبُه مقيّدًا — ولو
 *   قُرئت العلاقةُ لسقط الفرعُ المحذوف منها، فصار الموظّفُ «بلا قيود» وفُتحت
 *   له الفروع كلُّها بحذف فرعه.
 * - فرعٌ من نشاطٍ آخر مرفوضٌ أبدًا، ولو ورد في الصفوف.
 *
 * ═══ و«كل الفروع» ═══
 *
 * `current_branch = null` في الجلسة تعني «كل الفروع» — ولمن قُيّد تعني فروعَه
 * هو، لا فروعَ النشاط. فالاستعلامُ الذي يتبع الفرعَ المختار يمرّ بـ`scope()`،
 * والذي يعرض مستنداتِ الفروع بلا اختيارٍ يمرّ بـ`limit()`، وما يكتب في فرعٍ
 * يسأل `canAccess()`. ولا يُكرَّر `$user->branches()` في المتحكّمات.
 */
final class BranchAccess
{
    /** هل هذا الموظّف محصورٌ في فروعٍ بعينها؟ */
    public static function restricts(?User $user): bool
    {
        if (! $user || ! $user->isEmployee()) {
            return false;
        }

        return DB::table('branch_user')->where('user_id', $user->id)->exists();
    }

    /**
     * الفروعُ القائمة التي يعمل فيها — مرتّبةً بالمعرّف.
     *
     * @return list<int>
     */
    public static function allowedBranchIds(?User $user): array
    {
        if (! $user || ! $user->business_id) {
            return [];
        }

        $ids = self::restricts($user)
            ? self::granted($user)->pluck('branch_user.branch_id')
            : Branch::where('business_id', $user->business_id)->pluck('id');

        return $ids->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    /** هل يدخل هذا الفرعَ ويعمل فيه؟ */
    public static function canAccess(?User $user, ?int $branchId): bool
    {
        if (! $user || ! $user->business_id || ! $branchId) {
            return false;
        }

        if (! self::restricts($user)) {
            return Branch::where('business_id', $user->business_id)->whereKey($branchId)->exists();
        }

        return self::granted($user)->where('branch_user.branch_id', $branchId)->exists();
    }

    /**
     * الفرعُ المختار في الجلسة — بعد أن يُسأل عنه.
     *
     * فرعٌ أُزيل من فروع الموظّف وهو واقفٌ عليه يبقى في جلسته، والطلبُ
     * التالي كان سيعرض بياناتِه. فيُمحى هنا قبل أن يقرأه أحد، ويعود العرضُ
     * إلى «كل الفروع» — أي كلِّ فروعه هو.
     */
    public static function current(?User $user = null): ?int
    {
        $id = (int) session('current_branch');
        if (! $id) {
            return null;
        }

        $user ??= auth()->user();
        if (! self::canAccess($user, $id)) {
            session()->forget('current_branch');

            return null;
        }

        return $id;
    }

    /**
     * ما يغطّيه العرضُ الآن: null = لا حدّ (كلّ فروع النشاط)، وإلا قائمةٌ.
     *
     * فرعٌ مختار ← [هو]. «كل الفروع» لمقيَّدٍ ← فروعُه. «كل الفروع» لغيره ← null.
     *
     * @return list<int>|null
     */
    public static function currentAllowedBranchIds(?User $user = null): ?array
    {
        $user ??= auth()->user();

        if ($current = self::current($user)) {
            return [$current];
        }

        return self::restricts($user) ? self::allowedBranchIds($user) : null;
    }

    /**
     * حدُّ العرض الذي يتبع الفرعَ المختار.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function scope(Builder $query, ?User $user = null, string $column = 'branch_id'): Builder
    {
        return self::filter($query, self::currentAllowedBranchIds($user), $column);
    }

    /**
     * حدُّ الإذن وحده — لما يعرض مستنداتِ الفروع كلّها بلا اختيار.
     *
     * قائمةُ أوامر الشراء أو سندات الاستلام لا تتبع الشريط: صاحبُ النشاط
     * يراها كلَّها في أيّ فرعٍ وقف. فلا يغيّرها هذا له، ويحصرها للمقيَّد في فروعه.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function limit(Builder $query, ?User $user = null, string $column = 'branch_id'): Builder
    {
        $user ??= auth()->user();
        if (! self::restricts($user)) {
            return $query;
        }

        return $query->whereIn($column, self::granted($user)->select('branch_user.branch_id'));
    }

    /**
     * صفوفُ الإذن التي تشير إلى فرعٍ قائمٍ في نشاطه — وما سواها لا يفتح شيئًا.
     */
    private static function granted(User $user): \Illuminate\Database\Query\Builder
    {
        return DB::table('branch_user')
            ->join('branches', 'branches.id', '=', 'branch_user.branch_id')
            ->where('branch_user.user_id', $user->id)
            ->where('branches.business_id', $user->business_id)
            ->whereNull('branches.deleted_at');
    }

    /**
     * يطبّق نطاقًا محسوبًا: null بلا حدّ، ورقمٌ فرعٌ واحد، وقائمةٌ فروعٌ.
     *
     * والقائمةُ الفارغة لا تعني «بلا حدّ»: مقيَّدٌ حُذفت فروعُه كلُّها لا يرى
     * شيئًا — لا يرى كلَّ شيء.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @param  int|list<int>|null  $branches
     * @return T
     */
    public static function filter(Builder $query, int|array|null $branches, string $column = 'branch_id'): Builder
    {
        if ($branches === null) {
            return $query;
        }

        return is_int($branches)
            ? $query->where($column, $branches)
            : $query->whereIn($column, $branches);
    }
}
