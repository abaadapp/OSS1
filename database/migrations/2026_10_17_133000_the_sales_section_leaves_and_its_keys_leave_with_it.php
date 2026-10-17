<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * خرجت «المبيعات» من النظام — فيخرج مفتاحاها من القوائم المخصّصة.
 *
 * ═══ ما كان ═══
 *
 * `orders` قسمٌ يفتح شاشةَ الطلبات القديمة، وكان يفتح معها فواتيرَ العملاء.
 * و`order.edit` فعلٌ يصحّح فاتورةَ طلبٍ صدرت. وكلاهما يُكتب في
 * `users.permissions` لمن خُصّصت صلاحياتُه يدويًّا.
 *
 * ═══ وما يفعله هذا ═══
 *
 * ينزعهما من كلّ قائمةٍ مخصّصة — **ولا يحوّل `orders` إلى `finance`**:
 * فواتيرُ العملاء صارت تحت «المالية»، ومن مُنح «المبيعات» ليقرأ طلبًا أو
 * يكتب عرضًا لم يُمنح بها الدفترَ والذمم. ومن يحتاج الفواتير يُمنح «المالية»
 * بالاسم. والأفعالُ الحسّاسة على الفاتورة (`customerInvoice.issue` وأخواتُها)
 * تبقى في قوائم أصحابها كما هي: تعمل يومَ يُمنحون القسم.
 *
 * ولو بقي المفتاحان لما فتحا شيئًا — لا مسارَ يُنسب إليهما بعد اليوم — لكنّ
 * نموذجَ الموظّف يرسل القائمةَ كما هي في كلّ حفظ، والتحقّقُ يقبل ما في
 * الكتالوج وحده: فكان حفظُ رقم هاتف موظّفٍ قديمٍ سيُردّ بمفتاحٍ لا يراه أحد.
 *
 * ومن يتبع دورَه (`NULL`) لا يُمسّ: الدورُ نُزع منه القسمُ في `Permissions::MAP`.
 *
 * والتنبيهاتُ المخصّصة التي تفتح «المبيعات» تفتح «المالية» — انظر أسفل.
 *
 * ═══ ولماذا يحمل قائمتَه بنفسه ═══
 *
 * كما فعل سلفُه (2026_09_08_210000): ما يُقرأ من الكود يتغيّر بعد سنة،
 * والهجرةُ تُقرأ كما رُحّلت.
 *
 * ═══ ولا تراجعَ يعيدهما ═══
 *
 * لا يُعرف بعد الترحيل من كان يحملهما، وإعادتُهما للجميع تفتح بابًا لم
 * يُفتح لهم. والتراجعُ عن هذا الفرع كلِّه يُعيد القسمَ في الكود، وصاحبُ
 * العمل يمنحه من شاشة الموظّف لمن يريد.
 */
return new class extends Migration
{
    private const RETIRED = ['orders', 'order.edit'];

    public function up(): void
    {
        DB::table('users')->whereNotNull('permissions')->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $list = json_decode($row->permissions ?? 'null', true);

                    if (! is_array($list) || array_intersect($list, self::RETIRED) === []) {
                        continue;
                    }

                    $next = array_values(array_unique(array_diff($list, self::RETIRED)));

                    DB::table('users')->where('id', $row->id)
                        ->update(['permissions' => json_encode($next, JSON_UNESCAPED_UNICODE)]);
                }
            });

        /*
         * وتنبيهٌ مخصّصٌ كان يفتح «المبيعات» — وكانت تقوده إلى فواتير العملاء —
         * يفتح «المالية»: بابَ الفواتير اليوم. والقسمُ يُتحقّق منه عند الحفظ
         * بكتالوج الأقسام، فصفٌّ يبقى على `orders` لا يُحفظ تعديلُه.
         */
        DB::table('custom_alerts')->where('section', 'orders')->update(['section' => 'finance']);
    }

    public function down(): void
    {
        //
    }
};
