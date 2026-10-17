<?php

namespace App\Http\Middleware;

use App\Support\BranchAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * فرعٌ يُرسَل في الطلب يُسأل عنه قبل أن يصل متحكّمه.
 *
 * إخفاءُ الفرع من القوائم لا يمنع كتابته في طلبٍ يدويّ: `branch_id=٣` في
 * نموذج الجرد أو أمر الشراء أو حركة المخزون أو مرشّح التقرير. وحارسٌ في
 * كلّ متحكّمٍ يُنسى في المتحكّم التالي — فيُسأل هنا مرّةً لكلّ مسارٍ في اللوحة.
 *
 * ولا يمسّ من لم يُقيَّد: فرعٌ من نشاطٍ آخر يردّه متحكّمه كما كان («الفرع
 * المحدد غير صالح»)، ولا يتغيّر لصاحب النشاط ولا لموظّفٍ بلا فروعٍ محدّدة شيء.
 */
class EnforceBranchAccess
{
    /** الحقولُ التي تحمل فرعًا يُكتب فيه أو يُقرأ منه */
    private const FIELDS = ['branch_id', 'default_branch_id'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && BranchAccess::restricts($user)) {
            foreach (self::FIELDS as $field) {
                foreach ((array) $request->input($field) as $value) {
                    if ($value === null || $value === '' || $value === 'all') {
                        continue;
                    }

                    abort_unless(
                        is_numeric($value) && BranchAccess::canAccess($user, (int) $value),
                        403,
                        __('هذا الفرع ليس من فروع عملك.'),
                    );
                }
            }
        }

        return $next($request);
    }
}
