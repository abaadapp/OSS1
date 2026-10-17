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
     * و`$withUnbranched` لمستندٍ فرعُه اختياريّ: فاتورةُ عميلٍ صدرت من مشروعٍ
     * بلا فرع مستندُ الشركة لا مستندُ فرعٍ آخر، فتُرى. أمّا ما يحرّك رصيدَ فرعٍ
     * (طلبٌ، أمرُ شراء، استلام، تسوية، حركة) فلا يُرى إلّا في فروعه: ورقةٌ بلا
     * فرعٍ منها قديمةٌ، وفعلُها يقع على الفرع الأوّل وقد لا يكون من فروعه.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function limit(Builder $query, ?User $user = null, string $column = 'branch_id', bool $withUnbranched = false): Builder
    {
        $user ??= auth()->user();
        if (! self::restricts($user)) {
            return $query;
        }

        $granted = self::granted($user)->select('branch_user.branch_id');

        return $withUnbranched
            ? $query->where(fn ($w) => $w->whereIn($column, $granted)->orWhereNull($column))
            : $query->whereIn($column, $granted);
    }

    /**
     * حدُّ الإذن عبر المستند الأصل — لسجلٍّ لا عمودَ فرعٍ له ويتبع ما يحمله.
     *
     * سندُ المورّد بلا `branch_id`، لكنّه يتبع أمرَ شرائه، والإشعارُ الدائن
     * يتبع فاتورته. فيُرى ما كان أصلُه من فروعه، وما لا أصلَ له (سندٌ بلا أمر
     * شراء) ليس من فرعٍ أصلًا فيبقى مرئيًّا — إلّا أن يُطلب غيرُ ذلك.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function limitThrough(Builder $query, string $relation, ?User $user = null, bool $withoutParent = true, bool $parentUnbranched = false): Builder
    {
        $user ??= auth()->user();
        if (! self::restricts($user)) {
            return $query;
        }

        return $query->where(fn ($w) => $w
            ->whereHas($relation, fn ($parent) => self::limit($parent, $user, $parent->qualifyColumn('branch_id'), $parentUnbranched))
            ->when($withoutParent, fn ($w) => $w->orWhereDoesntHave($relation)));
    }

    /**
     * فواتيرُ العملاء: فرعُها اختياريّ — وما بلا فرعٍ مستندُ الشركة يُرى.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function invoices(Builder $query, ?User $user = null, string $column = 'branch_id'): Builder
    {
        return self::limit($query, $user, $column, true);
    }

    /**
     * إنشاءُ فرعٍ لصاحب النشاط ولمن لا تُقيَّد فروعه.
     *
     * فرعٌ يُنشئه موظّفٌ محصورٌ في مسقط لا يقع في فروعه: لا يراه في المبدّل
     * ولا يعمل فيه، ويبقى فرعًا في الشركة أنشأه من لا يدخله. فالإنشاءُ لمن
     * يرى الفروع كلَّها.
     */
    public static function mayCreateBranch(?User $user): bool
    {
        return $user !== null && $user->business_id !== null && ! self::restricts($user);
    }

    /**
     * صفوفُ الفروع المسجّلة للموظّف ولو حُذف الفرع — لسلّة المحذوفات.
     *
     * فرعٌ محذوفٌ لا يفتح شيئًا (انظر granted)، لكنّ استعادته فعلٌ على فرعٍ
     * بعينه: يستعيد المقيَّدُ فرعَه هو، لا فرعًا لم يكن من فروعه.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function limitListed(Builder $query, ?User $user = null, string $column = 'id'): Builder
    {
        $user ??= auth()->user();
        if (! self::restricts($user)) {
            return $query;
        }

        return $query->whereIn($column, DB::table('branch_user')->where('user_id', $user->id)->select('branch_id'));
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
