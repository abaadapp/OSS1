<?php

namespace App\Support;

use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * إيرادُ الشركة — بابُ القراءة الوحيد.
 *
 * ═══ لماذا وُجد هذا الملفّ ═══
 *
 * كانت طبقةُ التحليل كلُّها تقرأ `orders`: اثنا عشر موضعًا في `ReportData`،
 * واثنان في `DashboardMetrics`، واثنان وثلاثون في `Demo` — وصفرٌ يقرأ
 * `customer_invoices`. **ولا مسارَ HTTP واحدٍ يُنشئ `Order`** منذ رُفعت نقطة
 * البيع: الكاتبان الوحيدان بذرةٌ وأمرُ طرفيّة. فجدولُ الطلبات في تثبيتِ
 * Event Plus فارغٌ ويبقى فارغًا، واللوحةُ والتقاريرُ تقرأ صفرًا مهما فوترت
 * الشركةُ وحصّلت.
 *
 * وأخطرُها **الإقرار الضريبيّ**: ضريبةُ المخرجات كانت تُجمع من `orders` وحدها،
 * ففاتورةٌ تصدر اليوم بضريبةِ ٥٪ لا تظهر في الإقرار. النظامُ يقول «ضريبةٌ
 * مستحقّة عليك: صفر» ودفترُ الأستاذ فيه رصيدٌ في `tax_payable` — رقمان يقولان
 * الشيءَ نفسَه ويفترقان.
 *
 * ═══ ولماذا بابٌ واحد لا تحويلٌ في كلّ موضع ═══
 *
 * أربعون موضعًا تُعيد كتابةَ «ما الذي يُعدّ إيرادًا» أربعين مرّة تفترق عند
 * أوّل تعديل: أحدُها ينسى استبعادَ الملغاة، وآخرُ يجمع المسودّات. فالتعريفُ
 * هنا مرّةً واحدة، ومن أراد إيرادًا سأل هذا الصنف.
 *
 * ═══ التعريف ═══
 *
 * **الإيرادُ فاتورةُ عميلٍ صادرة** — لا مسودّة ولا ملغاة:
 *
 * | الحال | تُحسب؟ | لماذا |
 * |---|---|---|
 * | مسودّة | ❌ | لا ذمّةَ لها ولا قيدَ ولا رقمَ محجوز — ورقةٌ لم تصدر بعد |
 * | صادرة | ✅ | `CustomerInvoices::post` كتبت قيدَها في الدفتر |
 * | ملغاة | ❌ | `Ledger::reverse` عكست قيدَها |
 *
 * **وتاريخُها `issued_at`** — لا `created_at`. هو التاريخُ الذي يمرّره
 * `CustomerInvoices::post` إلى `Ledger::post` حرفًا بحرف، فما يقوله التقرير
 * عن شهرٍ هو ما يقوله ميزانُ المراجعة عنه.
 *
 * **والوعاءُ `subtotal - discount_total`** — لا `total - tax`. هو ما يُقيَّد
 * دائنًا في حساب `sales` بالضبط (انظر `CustomerInvoices::post`).
 *
 * **وإشعارُ الدائن يُنقص** الإيرادَ والضريبةَ معًا — وهو ما يفعله قيدُه:
 * `sales_returns` مدينًا و`tax_payable` مدينًا.
 *
 * ═══ وما لا يُحسب هنا ═══
 *
 * فاتورةٌ تغطّي طلباتٍ (`coversOrders`) لا قيدَ لها — رُحّل كلُّ طلبٍ فيها
 * لحظةَ وقوعه. وهي محسوبةٌ هنا مع أخواتها عمدًا: جدولُ `orders` في الإنتاج
 * صفر (قِيس)، فلا فاتورةَ تغطّي شيئًا، ولا ازدواجَ يقع. ويومَ يُسقَط `orders`
 * كما في §30 يزول السؤالُ كلُّه.
 */
class Revenue
{
    /** عمودُ التاريخ — تاريخُ المستند لا وقتُ كتابته */
    public const DATE = 'issued_at';

    /**
     * الفواتيرُ الصادرةُ في مدّة — أساسُ كلّ قراءةٍ هنا.
     *
     * والحدُّ الأعلى مفتوحٌ دائمًا (`<` لا `<=`): `issued_at` عمودُ تاريخٍ بلا
     * ساعة، و`between` تشمل الطرفين — فما وقع على الحدّ يُحسب في المدّتين
     * معًا. وهو العطبُ نفسُه الذي أُصلح في `Demo::adminStats`.
     */
    public static function query(int $bid, ?Carbon $start = null, ?Carbon $end = null, ?int $branchId = null): Builder
    {
        return CustomerInvoice::query()
            ->where('business_id', $bid)
            ->where('status', CustomerInvoice::ISSUED)
            /* والرصيدُ الافتتاحيُّ ذمّةٌ لا إيراد: قُيّد على الأرباح المحتجزة لا المبيعات — المرحلة 9 */
            ->where('is_opening_balance', false)
            ->when($start, fn ($q) => $q->whereDate(self::DATE, '>=', $start->toDateString()))
            ->when($end, fn ($q) => $q->whereDate(self::DATE, '<', $end->toDateString()))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
    }

    /**
     * إشعاراتُ الدائن في المدّة — بتاريخها هي لا بتاريخ فاتورتها.
     *
     * إشعارٌ يصدر في مارس على فاتورةِ يناير يُنقص إيرادَ **مارس**: هكذا قُيّد
     * في الدفتر، وهكذا يُقدَّم في الإقرار. ولو نُسب إلى يناير لتغيّر إقرارٌ
     * قُدِّم وأُقفل.
     */
    public static function credits(int $bid, ?Carbon $start = null, ?Carbon $end = null): Builder
    {
        return CustomerCreditNote::query()
            ->where('business_id', $bid)
            ->when($start, fn ($q) => $q->whereDate('issued_at', '>=', $start->toDateString()))
            ->when($end, fn ($q) => $q->whereDate('issued_at', '<', $end->toDateString()));
    }

    /**
     * صورةُ الإيراد في مدّة — نداءان لا نداءٌ لكلّ رقم.
     *
     * @return array{gross: float, taxable: float, tax: float, count: int,
     *               credited: float, creditedTax: float,
     *               total: float, net: float, netTax: float}
     */
    public static function summary(int $bid, ?Carbon $start = null, ?Carbon $end = null, ?int $branchId = null): array
    {
        /*
         * و`toBase()` في كلّ تجميعٍ هنا.
         *
         * `Eloquent\Builder::get()` يبني **نموذجًا لكلّ صفٍّ يعود** — وصفُّ
         * التجميع صفٌّ. فبطاقةٌ تسأل «كم بعنا اليوم؟» كانت تبني `CustomerInvoice`
         * كاملًا لتقرأ منه رقمًا واحدًا، واللوحةُ تسأل ستَّ مرّاتٍ فتبني ستّة.
         * وهو ما يحرسه `test_the_dashboard_does_not_load_the_whole_tables`.
         */
        $row = self::query($bid, $start, $end, $branchId)->toBase()
            ->selectRaw('COALESCE(SUM(total), 0) as gross')
            ->selectRaw('COALESCE(SUM(subtotal - discount_total), 0) as taxable')
            ->selectRaw('COALESCE(SUM(tax_total), 0) as tax')
            ->selectRaw('COUNT(*) as c')
            ->first();

        /*
         * وإشعاراتُ الدائن لا تُقيَّد بالفرع.
         *
         * `customer_credit_notes` بلا `branch_id` — والقيدُ يأخذ فرعَ
         * الفاتورة. فقراءةٌ بفرعٍ تُرجع إيرادَه كاملًا بلا إنقاصِ ما رُدّ منه.
         * وهو نقصٌ مقصود: بطاقاتُ الفرع تقديريّة، والإقرارُ الضريبيّ يُقرأ
         * للشركة كلِّها بلا فرع.
         */
        $credits = $branchId === null
            ? self::credits($bid, $start, $end)->toBase()
                ->selectRaw('COALESCE(SUM(amount), 0) as a')
                ->selectRaw('COALESCE(SUM(tax_amount), 0) as t')
                ->first()
            : null;

        $gross = round((float) ($row->gross ?? 0), 3);
        $taxable = round((float) ($row->taxable ?? 0), 3);
        $tax = round((float) ($row->tax ?? 0), 3);
        $credited = round((float) ($credits->a ?? 0), 3);
        $creditedTax = round((float) ($credits->t ?? 0), 3);

        return [
            'gross' => $gross,
            'taxable' => $taxable,
            'tax' => $tax,
            'count' => (int) ($row->c ?? 0),
            'credited' => $credited,
            'creditedTax' => $creditedTax,
            // الإجماليُّ بعد المردودات — وهو ما تقرؤه البطاقاتُ والتقارير
            'total' => round($gross - $credited, 3),
            // الوعاءُ بعد المردودات — ما يبقى دائنًا في `sales`
            'net' => round($taxable - ($credited - $creditedTax), 3),
            // ضريبةُ المخرجات بعد المردودات — ما يبقى دائنًا في `tax_payable`
            'netTax' => round($tax - $creditedTax, 3),
        ];
    }

    /** الإجماليُّ وحده — بعد المردودات */
    public static function total(int $bid, ?Carbon $start = null, ?Carbon $end = null, ?int $branchId = null): float
    {
        return self::summary($bid, $start, $end, $branchId)['total'];
    }

    /** عددُ الفواتير الصادرة */
    public static function count(int $bid, ?Carbon $start = null, ?Carbon $end = null, ?int $branchId = null): int
    {
        return self::query($bid, $start, $end, $branchId)->count();
    }

    /* ======================== قراءاتٌ مجمَّعة ======================== */

    /**
     * سلسلةٌ زمنيّة — التقويمُ من المحرّك لا من PHP.
     *
     * وتجميعُ آلاف الصفوف في الذاكرة لرسم اثني عشر عمودًا يقرأ الجدولَ كلَّه
     * بلا سبب.
     *
     * ═══ والساعةُ من وقتِ الإصدار ═══
     *
     * `issued_at` عمودُ **تاريخ** بلا ساعة، فتجميعُه بالساعة يضع اليومَ كلَّه
     * في الخانة صفر. و`issued_by_at` ختمُ اللحظة التي ضُغط فيها «إصدار»
     * — يكتبه `CustomerInvoices::issue` دائمًا. فمن طلب يومًا بساعاته يُقرأ
     * له ختمُ اللحظة، وما فوقه يُقرأ بتاريخ المستند.
     *
     * @return Collection<string, array{sum: float, count: int}>
     */
    public static function series(int $bid, string $unit, Carbon $start, Carbon $end, ?int $branchId = null): Collection
    {
        $driver = DB::connection()->getDriverName();
        $column = $unit === 'hour' ? 'issued_by_at' : self::DATE;

        $format = match ([$driver, $unit]) {
            ['pgsql', 'hour'] => "to_char({$column}, 'HH24')",
            ['pgsql', 'day'] => "to_char({$column}, 'YYYY-MM-DD')",
            ['pgsql', 'month'] => "to_char({$column}, 'YYYY-MM')",
            ['mysql', 'hour'], ['mariadb', 'hour'] => "DATE_FORMAT({$column}, '%H')",
            ['mysql', 'day'], ['mariadb', 'day'] => "DATE_FORMAT({$column}, '%Y-%m-%d')",
            ['mysql', 'month'], ['mariadb', 'month'] => "DATE_FORMAT({$column}, '%Y-%m')",
            default => match ($unit) {
                'hour' => "strftime('%H', {$column})",
                'day' => "strftime('%Y-%m-%d', {$column})",
                default => "strftime('%Y-%m', {$column})",
            },
        };

        return self::query($bid, $start, $end->copy()->addDay(), $branchId)->toBase()
            ->selectRaw("{$format} as bucket, SUM(total) as s, COUNT(*) as c")
            ->groupBy('bucket')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->bucket => [
                'sum' => round((float) $r->s, 3),
                'count' => (int) $r->c,
            ]]);
    }

    /**
     * الإيرادُ لكلّ عميل — بالمعرّف لا بالاسم.
     *
     * الاسمُ منسوخٌ في `customer_name` لحظةَ الإصدار (ختمُ الورقة)، فعميلٌ
     * أُعيدت تسميتُه ينقسم صفّين ويفقد تاريخَه. وكان تجميعُ الطلبات بالاسم
     * لهذا السبب نفسِه ناقصًا.
     *
     * @return Collection<int, object{customer_id: int, total: float, invoices: int}>
     */
    public static function byCustomer(int $bid, ?Carbon $start = null, ?Carbon $end = null, int $limit = 50): Collection
    {
        return self::query($bid, $start, $end)->toBase()
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, SUM(total) as t, COUNT(*) as c')
            ->groupBy('customer_id')
            ->orderByDesc('t')
            ->limit($limit)
            ->get();
    }

    /**
     * الإيرادُ لكلّ موظّف — بمن **أنشأ** الورقة.
     *
     * ولا عمودَ لمن أصدرها: `issued_by_at` ختمُ وقتٍ بلا فاعل. ومن كتب
     * الفاتورة هو صاحبُ الصفقة غالبًا، ومن أصدرها قد يكون المحاسبَ الذي
     * يعتمد أوراقَ الجميع — فنسبةُ الإيراد إليه تجعله وحده «البائع».
     *
     * @return Collection<int, object>
     */
    public static function byUser(int $bid, ?Carbon $start = null, ?Carbon $end = null): Collection
    {
        return self::query($bid, $start, $end)->toBase()
            ->whereNotNull('created_by')
            ->selectRaw('created_by, SUM(total) as s, COUNT(*) as c')
            ->groupBy('created_by')
            ->get()
            ->keyBy('created_by');
    }

    /**
     * بنودُ الفواتير الصادرة — أساسُ القراءة بالمنتج والقسم والتكلفة.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public static function items(int $bid, ?Carbon $start = null, ?Carbon $end = null, ?int $branchId = null)
    {
        return DB::table('customer_invoice_items')
            ->join('customer_invoices', 'customer_invoices.id', '=', 'customer_invoice_items.customer_invoice_id')
            ->where('customer_invoices.business_id', $bid)
            ->where('customer_invoices.status', CustomerInvoice::ISSUED)
            ->where('customer_invoices.is_opening_balance', false)
            ->when($start, fn ($q) => $q->whereDate('customer_invoices.'.self::DATE, '>=', $start->toDateString()))
            ->when($end, fn ($q) => $q->whereDate('customer_invoices.'.self::DATE, '<', $end->toDateString()))
            ->when($branchId, fn ($q) => $q->where('customer_invoices.branch_id', $branchId));
    }

    /* ========================= التحصيل ========================= */

    /**
     * ما قُبض فعلًا — سنداتُ القبض غير الملغاة.
     *
     * ═══ ولمَ بابٌ ثانٍ ═══
     *
     * الفاتورةُ التزامٌ والتحصيلُ قبض، وهما سؤالان مختلفان. وكانت شاشاتٌ
     * تسأل «كيف دفع الناس؟» فتقرأ `orders.payment_method` — والفاتورةُ لا
     * وسيلةَ دفعٍ فيها أصلًا: تُصدَر اليوم ويُقبض ثمنُها بعد شهرٍ بشيك، وقد
     * يُقبض على دفعتين بوسيلتين. فالوسيلةُ تُسأل عن السند لا عن الورقة.
     *
     * ═══ والمرتجعُ ليس مقبوضًا ═══
     *
     * شيكٌ رجع من البنك مالٌ لم يصل: قيدُه عُكس وذمّةُ العميل عادت عليه.
     * والقاعدةُ هي قاعدةُ `CustomerInvoice::paidSql` حرفًا بحرف — ولو
     * افترقتا لَقالت شاشةٌ «حُصِّل» وأخرى «عليه دَين».
     */
    public static function collections(int $bid, ?Carbon $start = null, ?Carbon $end = null): Builder
    {
        /*
         * والأعمدةُ مسبوقةٌ باسم جدولها.
         *
         * `byMethod` تضمّ إليها `customer_payment_allocations` و
         * `customer_invoices`، وفي الثلاثة `business_id` — فشرطٌ بلا بادئة
         * يُرفض بـ«ambiguous column». والبادئةُ هنا لا هناك كي لا تُعاد
         * كتابتُها عند كلّ ضمٍّ جديد.
         */
        return CustomerPayment::query()
            ->where('customer_payments.business_id', $bid)
            ->whereNull('customer_payments.cancelled_at')
            ->where(fn ($q) => $q->whereNull('customer_payments.cheque_status')
                ->orWhere('customer_payments.cheque_status', '<>', Cheques::BOUNCED))
            ->when($start, fn ($q) => $q->where('customer_payments.occurred_at', '>=', $start))
            ->when($end, fn ($q) => $q->where('customer_payments.occurred_at', '<', $end));
    }

    /**
     * توزيعُ المقبوض على وسائله.
     *
     * ═══ وكيف يتبع الفرع ═══
     *
     * السندُ نفسُه بلا فرع: يُقبض في الشركة لا في فرع. لكنّ **تخصيصاته**
     * تعرف فرعَها — كلُّ تخصيصٍ يقع على فاتورةٍ لها `branch_id`. فمن سأل عن
     * فرعٍ يُجمع له مجموعُ التخصيصات الواقعة على فواتيره: رقمٌ دقيقٌ لا
     * يُضاعَف، لأنّ التخصيص يخصّ فاتورةً واحدة.
     *
     * ومن لم يسأل عن فرعٍ يُجمع له السندُ كاملًا — بما فيه ما لم يُخصَّص بعد
     * (دفعةٌ مقدَّمةٌ لم تُفوتَر). ولو جُمعت التخصيصاتُ دائمًا لسقط ذلك المال
     * من اللوحة وهو في الصندوق.
     *
     * @return Collection<int, array{key: string, total: float, count: int}>
     */
    public static function byMethod(int $bid, ?Carbon $start = null, ?Carbon $end = null, ?int $branchId = null): Collection
    {
        $rows = $branchId === null
            ? self::collections($bid, $start, $end)->toBase()
                ->selectRaw('method, SUM(amount) as s, COUNT(*) as c')
                ->groupBy('method')->orderByDesc('s')->get()
            : self::collections($bid, $start, $end)->toBase()
                ->join('customer_payment_allocations as a', 'a.customer_payment_id', '=', 'customer_payments.id')
                ->join('customer_invoices as i', 'i.id', '=', 'a.customer_invoice_id')
                ->where('i.branch_id', $branchId)
                ->selectRaw('customer_payments.method as method, SUM(a.amount) as s, COUNT(DISTINCT customer_payments.id) as c')
                ->groupBy('customer_payments.method')->orderByDesc('s')->get();

        return $rows->map(fn ($r) => [
            'key' => (string) $r->method,
            'total' => round((float) $r->s, 3),
            'count' => (int) $r->c,
        ]);
    }

    /* ========================= التكلفة ========================= */

    /**
     * تكلفةُ ما بيع — **تقديرٌ لا قيدٌ محاسبيّ**، ويُقال ذلك حيث يُعرض.
     *
     * ═══ ولمَ تقدير ═══
     *
     * `order_items` كانت تحمل عمودَ `cost` — لقطةَ التكلفة لحظةَ البيع.
     * و`customer_invoice_items` لا تحمله: فاتورةُ العميل مستندٌ ماليّ، لا
     * تعرف من أين جاءت البضاعة. فالتكلفةُ تُقرأ من `products.cost` **اليوم**
     * لا يومَ البيع، فشراءٌ بسعرٍ أعلى الأسبوعَ الماضي يُنقص ربحَ الشهر
     * الماضي بأثرٍ رجعيّ.
     *
     * ولم يُضَف العمودُ في هذه المرحلة عمدًا: Event Plus تبيع **خدماتٍ**
     * أوّلًا، وبندُ خدمةٍ بلا `product_id` تكلفتُه صفرٌ هنا — وهو الصواب.
     * وتكلفةُ المشروع الحقيقيّة تُجمع من المشتريات وسندات الموردين
     * والمصروفات، لا من بطاقة صنف (انظر §16 في وثيقة المعمارية).
     */
    public static function cogs(int $bid, ?Carbon $start = null, ?Carbon $end = null, ?int $branchId = null): float
    {
        $row = self::items($bid, $start, $end, $branchId)
            ->join('products', 'products.id', '=', 'customer_invoice_items.product_id')
            ->selectRaw('COALESCE(SUM(COALESCE(products.cost, 0) * customer_invoice_items.quantity), 0) as c')
            ->first();

        return round((float) ($row->c ?? 0), 3);
    }
}
