<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\StockLosses;
use App\Support\Waste;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * تعديلات المخزون — تلفٌ وفقدٌ وتصحيحُ عدّ.
 *
 * وشاشتُها («سجل المخزون») خرجت من الواجهة؛ وبقي بابُ الكتابة بلا شاشة لأنّ
 * فيه قيدَ الخسارة في الدفترين (`StockLosses`). والتسوياتُ التي تكتبها
 * مستهلكاتُ الفعاليّة لا تمرّ من هنا — تكتبها `Operations\Consumables`.
 *
 * التعديل ليس تصحيحًا للرقم وحده: قطعةٌ تلفت مالٌ ضاع، فتنقص من المخزون
 * وتُقيَّد خسارةً في الدفتر. والاكتفاء بتنقيص العدد يُبقي قيمة المخزون في
 * الميزانية كما كانت — فيظهر المتجر أغنى ممّا هو بقيمة كلّ ما تلف عنده.
 *
 * والتكلفة تُنسخ لحظةَ التعديل: تكلفة المنتج متوسّطٌ يتحرّك مع كل شراء،
 * فقراءتها اليوم عن تلفٍ وقع قبل سنة تُعطي رقمًا لم يقع.
 */
class StockAdjustmentController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    public function store(Request $request)
    {
        $bid = $this->bid();

        $data = $request->validate([
            /*
             * الفرع صريحٌ لا مأخوذٌ من الجلسة.
             *
             * كان يُقرأ من `Demo::currentBranchId()`، وهي تُرجع null في وضع
             * «كل الفروع» — وهو وضع الجلسة الافتراضيّ. فالتعديل كان يزيد
             * إجماليّ الشركة ولا يمسّ رصيد فرعٍ واحد، فينكسر الثابت «مجموع
             * الفروع = كمية المنتج» بلا خطأ ولا أثرٍ في أيّ تقرير.
             *
             * والجرد — وهو العملية الشقيقة — يطلب الفرع صراحةً منذ البداية.
             */
            'branch_id' => ['required', 'integer'],
            'product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $bid)],
            /*
             * عددٌ صحيح لا كسر.
             *
             * `products.quantity` و`branch_stocks.quantity` عمودان صحيحان،
             * فنصفُ قطعةٍ لا موضع لها فيهما. وقبولُها ثمّ قصُّها إلى صفرٍ
             * بصمتٍ أسوأ من ردّها: من كتب «٢٫٥» يُنتظر أن يتحرّك المخزون
             * بشيء، فلا يتحرّك — ولا يُقال له لماذا.
             */
            'quantity_delta' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', Rule::in(StockAdjustment::REASONS)],
            'notes' => ['nullable', 'string', 'max:500'],
            'adjusted_at' => ['required', 'date'],
        ], [
            'quantity_delta.not_in' => __('تعديلٌ بصفرٍ لا يُغيّر شيئًا'),
            'quantity_delta.integer' => __('الكمية أعدادٌ صحيحة — لا كسور'),
            // النصّ نفسه الذي تستعمله حركة المخزون اليدوية — لا صياغةٌ ثانية
            // لنفس المعنى تُترجم مرّتين وتفترقان
            'branch_id.required' => __('يجب تحديد الفرع قبل أي إضافة أو تعديل على المخزون.'),
        ]);

        $branch = Branch::where('business_id', $bid)->find($data['branch_id']);
        if (! $branch) {
            return back()->withInput()->withErrors(['branch_id' => __('الفرع المحدد غير صالح.')]);
        }

        $product = Product::where('business_id', $bid)->findOrFail($data['product_id']);
        /*
         * رقمٌ واحد صحيح يتحرّك به كلُّ شيء: الكمية، ورصيد الفرع، وسجلّ
         * التعديل، وحركة المخزون، والقيد المالي.
         *
         * وسببُ الهالك ينقص المخزون دائمًا: كان النموذج يقبل «تلف ‎+٦» فيزيد
         * المخزون بحجّة أنّ بضاعةً تلفت — وهو ما لا معنى له في أيّ قراءة،
         * ويسمّم كلّ تقريرٍ يجمع الخسائر. والتصحيح في الخادم لا في الشاشة:
         * يكتب المستخدم «٦» كما ينطقها، ويجعلها الخادم ‎−٦، فلا يُطلب من أحدٍ
         * أن يتذكّر إشارةً.
         *
         * والصفوف القديمة لا تُمسّ — تُقرأ وتُبلَّغ في شاشة التحليلات.
         */
        $delta = (int) Waste::normalizeDelta($data['reason'], (int) $data['quantity_delta']);

        $cost = round((float) $product->cost, 3);
        $value = round(abs($delta) * $cost, 3);

        try {
            DB::transaction(function () use ($bid, $branch, $product, $delta, $cost, $value, $data) {
                $adjustment = StockAdjustment::create([
                    'business_id' => $bid,
                    'branch_id' => $branch->id,
                    'product_id' => $product->id,
                    'number' => StockAdjustment::nextNumber($bid),
                    'quantity_delta' => $delta,
                    // تُنسخ لحظتها: المتوسّط يتحرّك مع كل شراء
                    'cost_at_time' => $cost,
                    'reason' => $data['reason'],
                    'notes' => $data['notes'] ?? null,
                    'created_by' => auth()->id(),
                    'adjusted_at' => $data['adjusted_at'],
                ]);

                /*
                 * التوزيع قبل التغيير لا بعده.
                 *
                 * `ensureAllocated` تُعطى الكمية التي تُنقل إلى الفرع الأوّل
                 * حين لا يكون للمنتج صفُّ فرعٍ بعد. وكانت تُنادى بعد
                 * `increment`، فتُعطى الكمية الجديدة: فمنتجٌ كميّته صفر —
                 * وكلّ نسخةٍ من زرّ «نسخ المنتج» كذلك — يُعدَّل بخمسة، فيصير
                 * الإجماليّ خمسة ويُنشأ له صفُّ فرعٍ بخمسة ثمّ تُضاف خمسةٌ
                 * أخرى: عشرةٌ في الفروع وخمسةٌ في الإجماليّ.
                 *
                 * وهو الترتيب نفسه في الاستلام والبيع وإشعار التسليم.
                 */
                BranchStock::ensureAllocated($bid, $product->id, (int) $product->quantity);

                /*
                 * ═══ ولا يُتلَف في فرعٍ ما ليس فيه ═══
                 *
                 * الكمية لا تنزل تحت الصفر — رصيدٌ سالب يُفسد كلّ ما يُبنى
                 * عليه: قيمةُ المخزون تصير سالبة، و«المنخفض» يمتلئ بأصنافٍ
                 * لا وجود لها، ونقطة البيع تبيع ما ليس عندها.
                 *
                 * وكان الحارسُ يقيس **إجماليّ الشركة** والكتابةُ تقع على
                 * **فرعٍ بعينه**. جرّبتُها: عشرٌ في مسقط وصفرٌ في صلالة، ثمّ
                 * «تلف ٨» على صلالة — فمرّت بلا رسالة، وصار رصيدُ صلالة
                 * **ناقصَ ثمانية**: بضاعةٌ تلفت في فرعٍ لم تدخله قطّ.
                 *
                 * والبابُ الشقيق — حركةُ المخزون اليدويّة — يقيس دفترَ الفرع
                 * منذ إصلاحه، بهذه الرسالة بحروفها. وبابان يحرسان الحدث نفسه
                 * بقاعدتين يفترقان يومًا — وقد افترقا.
                 *
                 * والقراءةُ داخل المعاملة بعد التوزيع: منتجٌ لم يُوزَّع بعدُ
                 * رصيدُه كلُّه في الفرع الأوّل، وهي قاعدةُ `books` نفسُها.
                 */
                $book = BranchStock::bookOf($bid, $product->id, $branch->id);

                if ($delta < 0 && $book + $delta < 0) {
                    throw new RuntimeException(__('رصيد :branch من هذا الصنف :n فقط — لا يمكن صرف أكثر منه.', [
                        'branch' => $branch->name, 'n' => $book,
                    ]));
                }

                $product->increment('quantity', $delta);

                BranchStock::adjust($bid, $branch->id, $product->id, $delta);

                InventoryMovement::create([
                    'business_id' => $bid,
                    'branch_id' => $branch->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'type' => $delta > 0 ? 'إضافة كمية' : 'خصم كمية',
                    'quantity' => ($delta > 0 ? '+' : '').$delta,
                    'employee_name' => auth()->user()->name,
                ]);

                /*
                 * الحدثُ يُكتب في الدفترين — والوصفةُ في `StockLosses`.
                 *
                 * كان هذا الباب يُرحّل إلى الأستاذ ولا يكتب صفَّ مصروف،
                 * والجردُ — وهو البابُ الشقيق — يكتب الصفَّ ولا يُرحّل. فخسارةُ
                 * التلف المسجَّلة هنا لا تُنقص الربح الذي يقرؤه التاجر أبدًا،
                 * لأنّ الربح يُقرأ من جدول المصروفات لا من الأستاذ.
                 *
                 * ومنتجٌ بلا تكلفة لا قيد له — لا مبلغ يُقيَّد.
                 */
                StockLosses::record(
                    $bid,
                    $value,
                    $delta < 0,
                    __('تعديل مخزون: ').$data['reason'].' — '.$product->name,
                    Carbon::parse($data['adjusted_at']),
                    $branch->id,
                    auth()->id(),
                    auth()->user()?->name,
                    $data['reason'],
                    $adjustment,
                );
            });
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['quantity_delta' => $e->getMessage()]);
        }

        Activity::log(
            'created',
            'عدّل مخزون '.$product->name.' بمقدار '.$delta.' — '.$data['reason'],
            ['subject_id' => $product->id]
        );

        return back()->with('toast', ['msg' => __('سُجّل التعديل'), 'type' => 'success']);
    }
}
