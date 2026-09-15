<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Support\Activity;
use App\Support\Books;
use App\Support\Demo;
use App\Support\ProductImages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * المحذوفات — الزرّ الذي يردّ ما أذهبته ضغطة.
 *
 * الحذف الناعم وحده لا يكفي: صفٌّ باقٍ في القاعدة لا يراه أحد إلا من يملك
 * وصولًا إليها، فيصير الاسترداد مكالمةً مع الدعم لا فعلًا يفعله صاحب المتجر.
 * هذه الشاشة هي ما يجعل «الحذف قابل للتراجع» جملةً صحيحة.
 */
class TrashController extends Controller
{
    public function restore(Request $request, int $id)
    {
        $type = self::typeOf($request);
        $row = self::findTrashed($type, $id);

        /*
         * والرمزُ المخفيّ يُفرَج عنه عند الحذف — فقد يكون شُغل.
         *
         * قيدُ التفرّد في نموذج المنتج يتجاوز المحذوف عمدًا: صنفٌ حُذف لا
         * يحجز رمزه ولا باركوده. فيُحذف «أ» ويُضاف «ب» بالباركود نفسه —
         * وكلاهما صحيح. ثمّ تُضغط «تراجع» فيعود «أ» حيًّا: صنفان حيّان
         * بباركودٍ واحد، والماسح يختار أحدهما فيُخصم من صنفٍ ويبقى الآخر
         * على الرفّ. ويظهر الفرق في الجرد بلا سبب يُعرف.
         *
         * والردّ هنا أسلم من التغيير الصامت: تسميةُ رمزٍ جديد لمنتجٍ يعود
         * تكتب في بضاعة التاجر ما لم يطلبه.
         */
        if ($type === 'product' && ($clash = self::codeClash($row))) {
            return back()->with('toast', [
                'msg' => __('تعذّرت الاستعادة: :code صار لمنتج «:name» — غيّره أولًا.', $clash),
                'type' => 'danger',
            ]);
        }

        /*
         * والسقف يُفحص قبل الإحياء.
         *
         * `PlanLimits` تُفرَض عند الإنشاء وحده، والاستعادة إنشاءٌ في أثرها:
         * يُحذف الفرع، ويُفتح غيرُه في مكانه، ثمّ تُضغط «تراجع» — فيصير
         * فرعان لباقةٍ بيعت بفرعٍ واحد. والحدُّ الذي يُلتفّ عليه بضغطتين ليس
         * حدًّا، وإنما وعدٌ يكسره أوّل من ينتبه إليه.
         *
         * والردّ في القناتين معًا: زرّ «تراجع» يعيش في شريط التنبيه، وهو لا
         * يعرض أخطاء النموذج — فرفضٌ في `withErrors` وحدها لا يراه أحد.
         */
        $row->restore();

        // المصروف يعود ومعه قيده في الدفتر — بمرجعه نفسه لا بمرجعٍ جديد
        if ($type === 'expense') {
            $row->transaction()->withTrashed()->restore();

            // وقيدُه المزدوج يُعاد بناؤه: حُذف مع المصروف فلا يُستعاد بالإحياء
            if ($row->isPaid()) {
                Books::recordExpense($row);
            }
        }

        $label = self::label($type, $row);

        Activity::log('restore', match ($type) {
            'product' => 'استعاد المنتج: ',
            'expense' => 'استعاد المصروف: ',
            'branch' => 'استعاد الفرع: ',
            'customer' => 'استعاد العميل: ',
        }.$label, ['subject_id' => $row->id, 'subject_type' => $type]);

        return back()->with('toast', [
            'msg' => __('تمت الاستعادة: :name', ['name' => $label]),
            'type' => 'success',
        ]);
    }

    /**
     * محوٌ لا رجعة فيه — ولا زرّ «تراجع» بعده.
     *
     * قسمٌ اسمه «المحذوفات» لا يستطيع الحذف تسميةٌ في غير محلّها: من أدخل
     * منتجًا باسمٍ خاطئ، أو بيانات عميلٍ طلب حذفها، لم يكن له سبيل. والملفّ
     * يُمحى مع الصفّ — الصورة والمرفق كانا يبقيان على القرص بعد ذهاب ما
     * يشير إليهما، فلا أحد يعرف أنهما هناك ولا كيف يصل إليهما.
     */
    public function purge(Request $request, int $id)
    {
        self::gate();
        $type = self::typeOf($request);

        // لا مسار محوٍ للفرع أصلًا، والحارس هنا كي لا يُفتح واحدٌ سهوًا
        abort_unless(in_array($type, self::PURGEABLE, true), 404);

        $row = self::findTrashed($type, $id);
        $label = self::label($type, $row);

        self::purgeRow($type, $row);

        Activity::log('deleted', match ($type) {
            'product' => 'محا المنتج نهائيًّا: ',
            'expense' => 'محا المصروف نهائيًّا: ',
            'customer' => 'محا العميل نهائيًّا: ',
        }.$label, ['subject_id' => $id, 'subject_type' => $type]);

        return back()->with('toast', [
            'msg' => __('تم المحو النهائي: :name', ['name' => $label]),
            'type' => 'warning',
        ]);
    }

    /**
     * المحو الفعلي — يستدعيه الزرّ والأمر المجدول معًا.
     *
     * موضعٌ واحد لأن الفرق بين الطريقين توقيتٌ لا سلوك: ما ينساه أحدهما من
     * تنظيفٍ يترك ملفًّا يتيمًا على القرص لا يعرف أحد سببه.
     */
    public static function purgeRow(string $type, $row): void
    {
        /*
         * القيمة الخام لا المقروءة: `Product::getImageAttribute` يردّ رابطًا
         * جاهزًا للعرض (‎/storage/…‎، أو رابط صورةٍ بديلة إن لم تكن هناك صورة).
         * وتمرير الرابط إلى `delete` لا يجد شيئًا ولا يشتكي — فيُمحى الصفّ
         * ويبقى الملفّ. عطبٌ صامت لا يظهر إلا بعدّ ما على القرص.
         */
        $files = match ($type) {
            /*
             * ومنذ صار للمنتج معرضٌ لا صورةً واحدة، لم يعد العمود وحده كافيًا:
             * صفوفُ `product_images` يأخذها قيدُ المفتاح عند المحو، وملفّاتُها
             * لا يأخذها شيء — فتبقى على القرص بلا صفٍّ يشير إليها.
             */
            'product' => ProductImages::files($row),
            'expense' => array_values(array_filter([$row->getRawOriginal('attachment')])),
            default => [],
        };

        /*
         * وصورُ المنتج على القرص العامّ ومرفقُ المصروف على الخاصّ.
         *
         * فلا يكفي قرصٌ واحد: حذفُ المصروف من العامّ كان يترك فاتورته على
         * القرص الخاصّ بلا صفٍّ يشير إليها — ملفٌّ لا يُقرأ ولا يُمحى.
         */
        $disk = $type === 'expense' ? 'local' : 'public';

        foreach ($files as $file) {
            // رابطٌ خارجيّ لا ملفّ على قرصنا — لا يُمَسّ
            if ($file && ! str_starts_with($file, 'http')) {
                Storage::disk($disk)->delete($file);
            }
        }

        /*
         * رصيد المنتج في كل فرع يذهب معه. لا قيد مفتاحٍ يتكفّل بذلك —
         * `branch_stocks.product_id` عمودٌ عاديّ — فالصفوف تبقى تحمل كمّياتٍ
         * لمنتجٍ لا وجود له، ويحسبها تقرير «قيمة المخزون» في مجموعه.
         */
        if ($type === 'product') {
            BranchStock::where('product_id', $row->id)->delete();
        }

        /*
         * ومحوُ المصروف نهائيًّا لا يمحو قيده — يعكسه إن لم يكن معكوسًا.
         *
         * وأكثرُ ما يمرّ من هنا معكوسٌ أصلًا: الحذف إلى المهملات عكسه قبلُ،
         * فلا يجد هذا النداء قيدًا حيًّا وينصرف. وما بقي حيًّا (صفٌّ قديم
         * حُذف قبل هذه النسخة) يُعكس هنا بدل أن يختفي من الميزان صامتًا.
         */
        if ($type === 'expense') {
            $row->transaction()->withTrashed()->forceDelete();
            Books::unpostExpense($row, auth()->id(), __('محو المصروف نهائيًّا'));
        }

        $row->forceDelete();
    }
}
