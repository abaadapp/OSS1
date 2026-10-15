<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\DocumentApproval;
use App\Models\PolicyException;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\Document\Snapshot;
use App\Support\Messaging\MessageIntent;
use App\Support\Messaging\Notifiers;
use App\Support\Policy\Finding;
use App\Support\Policy\Registry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * عروضُ الأسعار: كتابتُها، وتسعيرُها، وإرسالُها، وتنقيحُها.
 *
 * ═══ وهي ليست مستندًا محاسبيًّا ═══
 *
 * لا قيدَ يقع منها، ولا ذمّةَ تنشأ، ولا رقمَ يدخل إقرارًا. `Ledger` لا
 * يُنادى من هذا الملفّ ولا يجوز أن يُنادى: الإيرادُ يقع يومَ تُصدَر
 * الفاتورة — انظر `CustomerInvoices::issue` و`Revenue`.
 *
 * ═══ وجوهرُها ضبطُ السعر ═══
 *
 * الموظّفُ يختار الخدمةَ والكميّة. والسعرُ يأتي من الكتالوج **في الخادم**،
 * لا من الطلب. وكلُّ انحرافٍ عنه — نزولًا أو صعودًا — يحتاج إذنًا وسببًا،
 * وما تجاوز الحدَّ ينتظر اعتمادًا قبل أن يخرج.
 */
final class Quotations
{
    /** بادئةُ التسلسل — «Q-000001» */
    public const PREFIX = 'Q-';

    /** مفتاحُ حدّ الخصم في الإعدادات */
    public const THRESHOLD_KEY = 'quotation_discount_percent';

    /** ما يقع دونه الفرقُ فيُعدّ صفرًا — منازلُ المال ثلاث */
    private const EPSILON = 0.0005;

    /* ─────────────────────────── الترقيم ─────────────────────────── */

    /** رقمٌ متسلسلٌ داخل الشركة — ولا يُعاد استعمالُ رقمٍ أُلغي */
    public static function nextNumber(int $businessId): string
    {
        return Numbering::next($businessId, Quotation::class, self::PREFIX);
    }

    /* ─────────────────────────── السياسة ─────────────────────────── */

    /**
     * أعلى نسبةِ خصمٍ يمنحها من يملك `quotation.discount` بلا اعتماد.
     *
     * ═══ وصفرٌ افتراضًا — عمدًا ═══
     *
     * الافتراضُ في بابِ ضبطٍ يجب أن **يُغلقه** لا أن يفتحه: مفتاحٌ لم
     * يُضبط بعد يعني «لم يقرّر أحدٌ شيئًا»، وقراءتُه «بلا حدّ» تجعل أوّلَ
     * نظامٍ يُثبَّت يمنح موظّفيه خصمًا مفتوحًا حتى ينتبه صاحبُه. وهي
     * القاعدةُ نفسُها في `Permissions::abilities`: «الفشل في بابٍ يجب أن
     * يُغلقه لا أن يفتحه على مصراعيه».
     *
     * ولا يُثقل ذلك على صاحب العمل: من يملك `quotation.approve` — وهما
     * المالكُ ومديرُ الفرع — يعتمد خصمَه بنفسه في اللحظة نفسها، فلا ينتظر
     * أحدٌ نفسَه. والانتظارُ يقع على من لا يملك الاعتماد وحده.
     */
    public static function threshold(int $businessId): float
    {
        $value = Setting::where('business_id', $businessId)
            ->where('key', self::THRESHOLD_KEY)->value('value');

        return max(0.0, min(100.0, (float) ($value ?? 0)));
    }

    /* ─────────────────────────── التسعير ─────────────────────────── */

    /**
     * السعرُ المعتمد لبندٍ — من الخادم، ولا يُقبل من الطلب إطلاقًا.
     *
     * و`sellingPrice()` لا `price`: هي التي تطبّق خصمَ الصنف المحفوظ في
     * الكتالوج وتقصّه بين صفرٍ ومئة. وحسابُ سعرٍ ثانٍ هنا يجعل الكتالوجَ
     * يقول شيئًا والعرضَ يقول غيرَه.
     */
    public static function listPriceOf(?Product $product, float $offered): float
    {
        return $product === null ? round($offered, 3) : $product->sellingPrice();
    }

    /**
     * تصنيفُ بندٍ واحد: أهو بسعره، أم بخصم، أم فوق السعر؟
     *
     * @return array{kind: string, list: float, offered: float, discount: float, percent: float}
     */
    public static function classify(?Product $product, float $offered, float $quantity): array
    {
        $offered = round(max(0.0, $offered), 3);
        $list = self::listPriceOf($product, $offered);
        $qty = round(max(0.0, $quantity), 3);

        /*
         * والبندُ الحرُّ لا سعرَ كتالوجٍ له — فلا خصمَ عليه ولا تجاوز.
         *
         * ⚠️ وهذا بابٌ مفتوحٌ عن قصدٍ ويُقال صراحةً: من أراد الالتفافَ على
         * الضبط كتب بندًا حرًّا بالسعر الذي يريد بدل أن يخصم على بندٍ من
         * الكتالوج. ولا يُغلق هنا: البندُ الحرّ هو الطريقةُ الطبيعيّة لعرض
         * عملٍ مفصّل لا نظيرَ له في الكتالوج، ومنعُه يُعطّل أكثرَ ما تبيعه
         * الشركة. وحارسُه اليوم ثلاثة: `quotation.send` فعلٌ منفصل عن
         * الكتابة، وسجلُّ النشاط ينسب كلَّ بندٍ إلى كاتبه، والورقةُ تُقرأ
         * قبل أن تخرج. وإغلاقُه التامّ سياسةٌ في سجلّ المرحلة الثالثة.
         */
        if ($product === null) {
            return ['kind' => 'free', 'list' => $offered, 'offered' => $offered,
                'discount' => 0.0, 'percent' => 0.0];
        }

        if (abs($offered - $list) <= self::EPSILON) {
            return ['kind' => 'list', 'list' => $list, 'offered' => $offered,
                'discount' => 0.0, 'percent' => 0.0];
        }

        if ($offered > $list) {
            return ['kind' => 'override', 'list' => $list, 'offered' => $offered,
                'discount' => 0.0, 'percent' => 0.0];
        }

        return [
            'kind' => 'discount',
            'list' => $list,
            'offered' => $offered,
            'discount' => round(($list - $offered) * $qty, 3),
            'percent' => $list <= 0 ? 0.0 : round(($list - $offered) / $list * 100, 2),
        ];
    }

    /**
     * البنودُ مسعَّرةً ومصنَّفةً — وهذا هو الحارسُ الذي يردّ السعرَ المزوَّر.
     *
     * يُقرأ المنتجُ من **شركة العرض** لا من رقمٍ في الطلب، ويُقرأ سعرُه من
     * الكتالوج، ويُقارَن بالمعروض. فمن أرسل `list_price` في الطلب لم يرسل
     * شيئًا: القيمةُ تُطرح ولا تُقرأ.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{lines: array<int, array<string, mixed>>, needsApproval: bool, kinds: array<int, string>}
     */
    public static function price(int $businessId, array $lines, ?User $actor = null): array
    {
        $threshold = self::threshold($businessId);

        /* والمنتجاتُ تُقرأ دفعةً واحدة: بندٌ لبندٍ يعني استعلامًا لكلّ سطر */
        $ids = collect($lines)->pluck('product_id')->filter()->unique()->all();
        $catalog = $ids === []
            ? collect()
            : Product::where('business_id', $businessId)->whereKey($ids)->get()->keyBy('id');

        $priced = [];
        $kinds = [];
        $needsApproval = false;

        foreach ($lines as $line) {
            $productId = $line['product_id'] ?? null;
            $product = $productId ? $catalog->get((int) $productId) : null;

            /* ومعرّفٌ من شركةٍ أخرى يُردّ هنا لا في الشاشة */
            if ($productId && $product === null) {
                throw new RuntimeException(__('هذا البند ليس من كتالوج شركتك.'));
            }

            $qty = round((float) ($line['quantity'] ?? 1), 3);
            $verdict = self::classify($product, (float) ($line['unit_price'] ?? 0), $qty);
            $reason = trim((string) ($line['discount_reason'] ?? ''));

            self::assertAllowed($verdict, $reason, $actor);

            $overThreshold = $verdict['kind'] === 'discount' && $verdict['percent'] > $threshold + self::EPSILON;
            $selfApproves = $actor?->may(Permissions::QUOTATION_APPROVE) ?? false;
            $pending = ($overThreshold || $verdict['kind'] === 'override') && ! $selfApproves;

            if ($pending) {
                $needsApproval = true;
            }

            $kinds[] = $verdict['kind'];

            $priced[] = [
                'product_id' => $product?->id,
                'description' => trim((string) ($line['description'] ?? '')) ?: (string) $product?->name,
                'unit' => self::text($line['unit'] ?? null, 40),
                'quantity' => $qty,
                /* المجموعُ الفرعيُّ بسعر الكتالوج، والخصمُ الفرقُ — انظر `LineTotals` */
                'gross_unit' => max($verdict['list'], $verdict['offered']),
                'unit_price' => $verdict['offered'],
                'discount' => $verdict['discount'],
                'list_price' => $verdict['list'],
                'discount_reason' => $reason !== '' ? $reason : null,
                'discount_requested_by' => $verdict['kind'] === 'list' || $verdict['kind'] === 'free'
                    ? null : $actor?->id,
                /* ومن يملك الاعتماد يعتمد لحظتَه — ويُنسب إليه، فلا يضيع الأثر */
                'discount_approved_by' => ($verdict['kind'] !== 'list' && $verdict['kind'] !== 'free' && ! $pending)
                    ? $actor?->id : null,
                'discount_approved_at' => ($verdict['kind'] !== 'list' && $verdict['kind'] !== 'free' && ! $pending)
                    ? now() : null,
                'tax_rate' => Vat::rateFor($product, $businessId),
                'notes' => self::text($line['notes'] ?? null, 2000),
            ];
        }

        return ['lines' => $priced, 'needsApproval' => $needsApproval, 'kinds' => $kinds];
    }

    /**
     * أيملك هذا الفاعلُ أن يكتب هذا البند بهذا السعر؟
     *
     * والردُّ في الخادم لا في الشاشة: من يفتح أدوات المتصفّح يرسل ما يشاء.
     *
     * @param  array{kind: string, percent: float}  $verdict
     */
    private static function assertAllowed(array $verdict, string $reason, ?User $actor): void
    {
        if ($verdict['kind'] === 'list' || $verdict['kind'] === 'free') {
            return;
        }

        if ($verdict['kind'] === 'override') {
            if (! ($actor?->may(Permissions::QUOTATION_OVERRIDE_PRICE) ?? false)) {
                throw new RuntimeException(__('البيع فوق سعر الكتالوج يحتاج صلاحيةً لا تملكها.'));
            }
        } elseif (! ($actor?->may(Permissions::QUOTATION_DISCOUNT) ?? false)) {
            throw new RuntimeException(__('منح الخصم يحتاج صلاحيةً لا تملكها.'));
        }

        /*
         * والسببُ شرطٌ لا زينة.
         *
         * خصمٌ بلا سببٍ يُقرأ بعد سنةٍ فلا يُعرف أكان تفاوضًا أم خطأً أم
         * محاباة. وهو ما يجعل الأثرَ **أثرًا** لا سطرًا في جدول.
         */
        if ($reason === '') {
            throw new RuntimeException(__('اكتب سبب تغيير السعر — الخصم بلا سبب لا يُقرأ بعد سنة.'));
        }
    }

    /* ─────────────────────────── الحساب ─────────────────────────── */

    /**
     * الإجماليّات — بمحرّك الفاتورة نفسِه.
     *
     * @param  array<int, array<string, mixed>>  $priced  ما ردّته `price()`
     * @return array{items: array<int, array<string, mixed>>, subtotal: float, discount: float, tax: float, total: float}
     */
    public static function totals(int $businessId, array $priced): array
    {
        return LineTotals::compute($businessId, $priced, null, [
            'product_id', 'unit', 'list_price', 'notes',
            'discount_reason', 'discount_requested_by',
            'discount_approved_by', 'discount_approved_at',
        ]);
    }

    /* ─────────────────────────── الكتابة ─────────────────────────── */

    /**
     * أعمدةُ العرض كما تُكتب — من موضعٍ واحد تقرؤه المحفوظةُ والمعاينةُ معًا.
     *
     * ولو بُنيت المعاينةُ صفًّا ثانيًا مكتوبًا بيده لافترق عن المحفوظ عند
     * أوّل عمودٍ يُضاف: يُقرأ «القسم» من العميل في الحفظ ولا يُقرأ في
     * المعاينة، فيرى الموظّف ورقةً ويستلم عميلُه غيرَها.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $totals
     * @return array<string, mixed>
     */
    private static function attributes(int $businessId, ?Customer $customer, array $data, array $totals, ?int $keepContact = null): array
    {
        $issuedAt = isset($data['issued_at']) ? Carbon::parse($data['issued_at']) : now();

        /*
         * ═══ ومن يُوجَّه إليه العرض ═══
         *
         * شخصٌ مختارٌ من أشخاص اتصال الجهة (`customer_contact_id`) — مرجعٌ يبقى،
         * وأسماؤه وأرقامُه لقطةٌ في الورقة. وما كُتب بيدٍ يعلو على الاختيار، وما
         * لم يُكتب ولم يُختر يُقرأ من شخص الجهة الأساسيّ (أو أعمدتها القديمة) —
         * انظر `CustomerContacts::primary`.
         */
        $contact = CustomerContacts::pick($businessId, $customer?->id, $data['customer_contact_id'] ?? null, $keepContact);
        $fallback = $contact !== null
            ? ['name' => $contact->name, 'email' => $contact->email, 'phone' => $contact->phone]
            : CustomerContacts::primary($customer);

        return [
            'business_id' => $businessId,
            'customer_id' => $customer?->id,
            'title' => self::text($data['title'] ?? null, 255) ?? __('عرض سعر'),
            'event_location' => self::text($data['event_location'] ?? null, 255),
            'event_starts_at' => self::date($data['event_starts_at'] ?? null),
            'event_ends_at' => self::date($data['event_ends_at'] ?? null),
            'expected_attendees' => isset($data['expected_attendees']) && $data['expected_attendees'] !== ''
                ? max(0, (int) $data['expected_attendees']) : null,
            'issued_at' => $issuedAt->toDateString(),
            'valid_until' => self::date($data['valid_until'] ?? null),
            /*
             * وعملةُ الورقة عملةُ الشركة لا عملةُ عرض القارئ: `displayCurrency`
             * تفضيلُ مشاهدةٍ يُبدَّل من الترويسة، وورقةٌ خرجت لا يغيّر عملتَها
             * من فتحها.
             */
            'currency' => Currency::where('business_id', $businessId)
                ->where('is_base', true)->value('code'),
            'payment_terms_days' => isset($data['payment_terms_days']) && $data['payment_terms_days'] !== ''
                ? max(0, (int) $data['payment_terms_days'])
                : $customer?->payment_terms_days,
            'subtotal' => $totals['subtotal'],
            'discount_total' => $totals['discount'],
            'tax_total' => $totals['tax'],
            'total' => $totals['total'],
            /* لقطةُ العميل — ورقةٌ خرجت لا يغيّرها تبديلُ اسمٍ بعد سنة */
            'customer_name' => $customer?->legal_name ?: $customer?->name,
            'customer_tax_number' => $customer?->tax_number,
            'customer_cr' => $customer?->commercial_registration,
            'customer_address' => $customer?->billing_address ?: $customer?->address,
            'customer_reference' => self::text($data['customer_reference'] ?? null, 60),
            'department' => self::text($data['department'] ?? null, 120) ?? $customer?->department,
            'customer_contact_id' => $contact?->id,
            'attention_to' => self::text($data['attention_to'] ?? null, 120) ?? ($fallback['name'] ?? null),
            'contact_email' => self::text($data['contact_email'] ?? null, 120) ?? ($fallback['email'] ?? null),
            'contact_phone' => self::text($data['contact_phone'] ?? null, 40) ?? ($fallback['phone'] ?? null),
            'scope' => self::text($data['scope'] ?? null, 20000),
            'terms' => self::text($data['terms'] ?? null, 20000),
            'notes' => self::text($data['notes'] ?? null, 20000),
            /* وما لا يُطبع: عمودٌ آخر لا يبلغ ورقةَ العميل */
            'internal_notes' => self::text($data['internal_notes'] ?? null, 20000),
        ];
    }

    /**
     * ورقةٌ **غيرُ محفوظة** — لمعاينة ما لم يُكتب بعد.
     *
     * ولا تلمس القاعدة: لا صفَّ يُكتب، ولا رقمَ يُقطع. ومن فتح شاشة الإنشاء
     * وكتب بندًا ثمّ تركها لا يجب أن يترك خلفه مسودّةً في دفتره ولا فجوةً
     * في تسلسل أرقامه.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function draft(int $businessId, ?Customer $customer, array $data, array $lines, ?User $actor = null): Quotation
    {
        if ($customer !== null && (int) $customer->business_id !== $businessId) {
            throw new RuntimeException(__('هذا العميل ليس من عملاء شركتك.'));
        }

        $totals = self::totals($businessId, self::price($businessId, $lines, $actor)['lines']);

        return self::shape($businessId, $customer, $data, $totals);
    }

    /**
     * عرضٌ على هيئته النهائيّة **بلا حفظ** — للمعاينة وللفحص قبل الكتابة.
     *
     * والبنودُ تُعلَّق بلا حفظ: `setRelation` تجعل `$quotation->items`
     * تردّ ما بُني هنا بدل أن تسأل القاعدةَ عن صفوفٍ لا وجود لها —
     * فالقالبُ يمرّ عليها كما يمرّ على بنود ورقةٍ محفوظة، وفحصُ السياسات
     * يقرأ تاريخَها وبنودَها كما يقرؤها في عرضٍ محفوظ.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $totals
     */
    private static function shape(int $businessId, ?Customer $customer, array $data, array $totals, ?int $keepContact = null): Quotation
    {
        $quotation = new Quotation(self::attributes($businessId, $customer, $data, $totals, $keepContact) + [
            'number' => null,
            'status' => Quotation::DRAFT,
            'revision' => 1,
        ]);

        $quotation->setRelation('items', collect($totals['items'])->map(
            fn (array $item) => new QuotationItem($item)
        ));

        if ($customer !== null) {
            $quotation->setRelation('customer', $customer);
        }

        return $quotation;
    }

    /**
     * إنشاءُ عرضٍ — مسودّةً دائمًا.
     *
     * ولا يخرج من هنا إلى العميل: الإرسالُ فعلٌ ثانٍ بصلاحيّةٍ ثانية، وهو
     * الذي يقطع الرقمَ ويختم الورقة.
     *
     * ═══ والسياساتُ تُفحص قبل الكتابة ═══
     *
     * فعاليّةٌ بعد ثلاثة أيّامٍ والمهلةُ سبعة: يُرصد الفرقُ، ويُطلب سببٌ
     * (`$exceptions`)، ويُكتب الاستثناءُ، وينتظر العرضُ اعتمادًا — إلّا
     * لمن يملك `policy.override` فيعتمد لحظتَه. وبلا سببٍ لا يُحفظ العرض.
     * انظر §18.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, array<string, mixed>>  $exceptions  أسبابُ الاستثناء بمفاتيح السياسات
     */
    public static function create(int $businessId, Customer $customer, array $data, array $lines, ?User $actor = null, array $exceptions = []): Quotation
    {
        if ((int) $customer->business_id !== $businessId) {
            throw new RuntimeException(__('هذا العميل ليس من عملاء شركتك.'));
        }

        $priced = self::price($businessId, $lines, $actor);
        $totals = self::totals($businessId, $priced['lines']);
        $settled = self::settle($businessId, self::shape($businessId, $customer, $data, $totals), $exceptions, $actor);

        return DB::transaction(function () use ($businessId, $customer, $data, $totals, $priced, $settled, $actor) {
            $quotation = Quotation::create(
                self::attributes($businessId, $customer, $data, $totals) + [
                    'number' => null,
                    'status' => $priced['needsApproval'] || $settled['pending']
                        ? Quotation::PENDING_APPROVAL : Quotation::DRAFT,
                    'revision' => 1,
                    'created_by' => $actor?->id,
                ]
            );

            self::writeItems($quotation, $totals['items']);
            self::writeExceptions($quotation, $settled['rows']);

            Activity::log('created', 'أنشأ عرض سعر — '.$customer->name, [
                'subject_id' => $quotation->id, 'subject_type' => 'quotation',
            ]);

            return $quotation->fresh('items');
        });
    }

    /**
     * تعديلُ مسودّة — وما خرج لا يُعدَّل.
     *
     * ═══ والردُّ في الخادم لا في الشاشة ═══
     *
     * عرضٌ أُرسل إلى وزارةٍ ثمّ غُيّرت بنودُه في القاعدة يعني ورقتين
     * مختلفتين تحملان الرقمَ نفسَه: واحدةٌ في يد العميل وواحدةٌ في النظام.
     * والسبيلُ إلى التغيير تنقيحٌ يحفظ سابقَه — انظر `revise`.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, array<string, mixed>>  $exceptions
     */
    public static function update(Quotation $quotation, array $data, array $lines, ?User $actor = null, array $exceptions = []): Quotation
    {
        if ($quotation->isFrozen()) {
            throw new RuntimeException(__('هذا العرض خرج إلى العميل — لا يُعدَّل. أنشئ تنقيحًا جديدًا.'));
        }

        $businessId = (int) $quotation->business_id;
        $customer = $quotation->customer ?: Customer::where('business_id', $businessId)
            ->whereKey($quotation->customer_id)->firstOrFail();

        $priced = self::price($businessId, $lines, $actor);
        $totals = self::totals($businessId, $priced['lines']);
        $settled = self::settle($businessId, self::shape($businessId, $customer, $data, $totals, $quotation->customer_contact_id), $exceptions, $actor);

        return DB::transaction(function () use ($quotation, $businessId, $customer, $data, $totals, $priced, $settled) {
            $quotation->update(self::attributes($businessId, $customer, $data, $totals, $quotation->customer_contact_id) + [
                'status' => $priced['needsApproval'] || $settled['pending']
                    ? Quotation::PENDING_APPROVAL : Quotation::DRAFT,
                /*
                 * وتعديلٌ يمحو اعتمادًا سابقًا.
                 *
                 * اعتُمد خصمٌ بعشرين، ثمّ غُيّرت البنود — فالاعتمادُ وقع على
                 * ورقةٍ لم تعد قائمة. وإبقاؤه يجعل من اعتمد مسؤولًا عمّا لم
                 * يره.
                 */
                'approved_by' => null,
                'approved_at' => null,
            ]);

            $quotation->items()->delete();
            self::writeItems($quotation, $totals['items']);

            /* والاستثناءاتُ تُعاد كتابتُها مع البنود: اعتمادُها وقع على ورقةٍ لم تعد قائمة */
            $quotation->policyExceptions()->delete();
            self::writeExceptions($quotation, $settled['rows']);

            Activity::log('updated', 'عدّل عرض السعر '.self::label($quotation), [
                'subject_id' => $quotation->id, 'subject_type' => 'quotation',
            ]);

            return $quotation->fresh('items');
        });
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private static function writeItems(Quotation $quotation, array $items): void
    {
        foreach ($items as $item) {
            QuotationItem::create($item + ['quotation_id' => $quotation->id]);
        }
    }

    /* ─────────────────────────── السياسات ─────────────────────────── */

    /**
     * ما خالفه العرضُ من سياسات — وهل لكلّ مخالفةٍ سببٌ مكتوب.
     *
     * تُعيد صفوفَ الاستثناء الجاهزةَ للكتابة، وتقول هل ينتظر العرضُ
     * اعتمادًا. وتُرمى إن رُصدت مخالفةٌ بلا سبب: **لا يُحفظ عرضٌ يخالف
     * سياسةً في صمت** — والشاشةُ تعرف المخالفةَ من المعاينة قبل الحفظ
     * فتسأل عن السبب هناك.
     *
     * ومن يملك `policy.override` يعتمد لحظتَه — كما يعتمد صاحبُ
     * `quotation.approve` خصمَه. ولا ينتظر أحدٌ نفسَه.
     *
     * @param  array<int, array<string, mixed>>  $requested  `[['key' => …, 'reason' => …, 'surcharge_amount' => …], …]`
     * @return array{rows: list<array<string, mixed>>, pending: bool}
     */
    private static function settle(int $businessId, Quotation $shaped, array $requested, ?User $actor): array
    {
        $findings = CompanyPolicies::findings($businessId, $shaped);

        if ($findings === []) {
            return ['rows' => [], 'pending' => false];
        }

        $byKey = collect($requested)->keyBy(fn ($row) => (string) ($row['key'] ?? ''));
        $mayOverride = (bool) $actor?->may(Permissions::POLICY_OVERRIDE);
        $rows = [];
        $pending = false;

        foreach ($findings as $finding) {
            $input = $byKey->get($finding->key(), []);
            $reason = self::text($input['reason'] ?? null, 500);

            if ($reason === null || $reason === '') {
                throw new RuntimeException(__(':finding اكتب سبب الاستثناء ليُحفظ العرض.', [
                    'finding' => $finding->message,
                ]));
            }

            $rows[] = self::exceptionRow($finding, $reason, $input['surcharge_amount'] ?? null, $actor, $mayOverride);

            if ($finding->requiresApproval && ! $mayOverride) {
                $pending = true;
            }
        }

        return ['rows' => $rows, 'pending' => $pending];
    }

    /**
     * صفُّ استثناءٍ — ما رصده النظام وما كتبه الموظّف ومن اعتمد.
     *
     * والرسومُ **ما اختاره الموظّف** لا ما اقترحته السياسة: الاقتراحُ في
     * `detected` يُقرأ بعد سنةٍ إلى جانب ما فُرض فعلًا، فيُرى من تنازل وكم.
     *
     * @return array<string, mixed>
     */
    public static function exceptionRow(Finding $finding, string $reason, mixed $surcharge, ?User $actor, bool $approved): array
    {
        return [
            'policy_key' => $finding->key(),
            'company_policy_id' => $finding->policy->id,
            'policy_version' => (int) $finding->policy->version,
            'detected' => $finding->detected + ['suggested_surcharge' => $finding->suggestedSurcharge],
            'reason' => $reason,
            'surcharge_amount' => $surcharge !== null && $surcharge !== ''
                ? round(max(0.0, (float) $surcharge), 3)
                : $finding->suggestedSurcharge,
            'requested_by' => $actor?->id,
            'approved_by' => $approved ? $actor?->id : null,
            'approved_at' => $approved ? now() : null,
        ];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private static function writeExceptions(Quotation $quotation, array $rows): void
    {
        foreach ($rows as $row) {
            $exception = $quotation->policyExceptions()->create($row + ['business_id' => $quotation->business_id]);

            /* سياسةٌ تُجوُوزت ← المفتاح · ما رُصد · السبب · من اعتمد · الرسوم (§24) */
            Activity::log('status', 'تجاوز سياسة «'.Registry::label($row['policy_key']).'» في عرض السعر '
                .self::label($quotation).' — '.$row['reason']
                .($exception->approved_at ? ' · اعتُمد' : ' · ينتظر اعتمادًا'), [
                    'subject_id' => $quotation->id, 'subject_type' => 'quotation',
                    'icon' => 'shield-alert', 'color' => 'warning',
                ]);
        }
    }

    /**
     * ما خالفه عرضٌ محفوظ الآن — للشاشة وللإرسال.
     *
     * @return list<Finding>
     */
    public static function findings(Quotation $quotation): array
    {
        $quotation->loadMissing('items');

        return CompanyPolicies::findings((int) $quotation->business_id, $quotation);
    }

    /* ─────────────────────────── الدورة ─────────────────────────── */

    /**
     * اعتمادُ ما تجاوز الحدّ — فيصير العرضُ قابلًا للإرسال.
     *
     * ويُنسب الاعتمادُ إلى صاحبه في موضعين: رأسُ العرض، وكلُّ بندٍ كان
     * ينتظر. فمن قرأ البندَ بعد سنةٍ عرف من أذن به بلا أن يفتح الرأس.
     */
    public static function approve(Quotation $quotation, ?User $actor = null): Quotation
    {
        if ($quotation->status !== Quotation::PENDING_APPROVAL) {
            throw new RuntimeException(__('هذا العرض لا ينتظر اعتمادًا.'));
        }

        /*
         * ═══ واعتمادان بصلاحيّتين ═══
         *
         * الخصمُ يعتمده صاحبُ `quotation.approve`، واستثناءُ السياسة يعتمده
         * صاحبُ `policy.override`. ومن يملك إحداهما يعتمد ما يملك ويترك
         * الأخرى لصاحبها — والعرضُ لا يخرج حتى يكتمل الاثنان.
         */
        $mayDiscount = (bool) $actor?->may(Permissions::QUOTATION_APPROVE);
        $mayPolicy = (bool) $actor?->may(Permissions::POLICY_OVERRIDE);

        if (! $mayDiscount && ! $mayPolicy) {
            throw new RuntimeException(__('لا تملك اعتماد هذا العرض.'));
        }

        return DB::transaction(function () use ($quotation, $actor, $mayDiscount, $mayPolicy) {
            $approvedSomething = false;

            if ($mayDiscount) {
                $count = $quotation->items()->whereNull('discount_approved_by')
                    ->whereNotNull('discount_requested_by')
                    ->update(['discount_approved_by' => $actor?->id, 'discount_approved_at' => now()]);

                if ($count > 0) {
                    $approvedSomething = true;
                    Activity::log('status', 'اعتمد خصم عرض السعر '.self::label($quotation), [
                        'subject_id' => $quotation->id, 'subject_type' => 'quotation',
                    ]);
                }
            }

            if ($mayPolicy) {
                $count = $quotation->policyExceptions()->pending()
                    ->update(['approved_by' => $actor?->id, 'approved_at' => now()]);

                if ($count > 0) {
                    $approvedSomething = true;
                    Activity::log('status', 'اعتمد استثناء السياسة في عرض السعر '.self::label($quotation), [
                        'subject_id' => $quotation->id, 'subject_type' => 'quotation',
                        'icon' => 'shield-check',
                    ]);
                }
            }

            $stillPending = $quotation->items()->whereNull('discount_approved_by')
                ->whereNotNull('discount_requested_by')->exists()
                || $quotation->policyExceptions()->pending()->exists();

            if (! $approvedSomething && $stillPending) {
                throw new RuntimeException(__('ما ينتظره هذا العرض يعتمده غيرك.'));
            }

            $quotation->update($stillPending ? [] : [
                'status' => Quotation::DRAFT,
                'approved_by' => $actor?->id,
                'approved_at' => now(),
            ]);

            return $quotation->fresh('items');
        });
    }

    /**
     * الإرسال — وهنا يُقطع الرقمُ وتُختم الورقة وتُجمَّد.
     *
     * ولا يُرسَل ما أُرسل: نداءان لا يقطعان رقمين ولا يعيدان الختم.
     */
    public static function send(Quotation $quotation, ?User $actor = null): Quotation
    {
        return DB::transaction(function () use ($quotation, $actor) {
            /*
             * والحالةُ تُقرأ تحت قفلٍ داخل المعاملة لا قبلها.
             *
             * ضغطتان على «إرسال» — أو ردٌّ يبطؤ فيُعاد الطلب — كانتا تقرآن
             * «مسودة» كلتاهما خارج المعاملة فتمضيان، فيُقطع رقمان لورقةٍ
             * واحدة. وهو الدرسُ نفسُه في `CustomerInvoices::issue`.
             */
            $locked = Quotation::where('business_id', $quotation->business_id)
                ->lockForUpdate()->findOrFail($quotation->id);

            if ($locked->status === Quotation::SENT) {
                return $locked;
            }
            if ($locked->status === Quotation::PENDING_APPROVAL) {
                throw new RuntimeException(__('هذا العرض ينتظر اعتمادًا — لا يخرج قبله.'));
            }
            if ($locked->status !== Quotation::DRAFT) {
                throw new RuntimeException(__('لا يُرسَل إلا عرضٌ في المسودّة.'));
            }
            /*
             * وعرضٌ بلا بنودٍ لا يُرسَل — ولا يُشترط مبلغٌ موجب.
             *
             * الفاتورةُ تشترط مبلغًا لأنّها تُنشئ ذمّة، وذمّةٌ بصفرٍ عبث.
             * والعرضُ قد يحمل بندًا بلا مقابل ضمن حزمة — «التدريب: مجّانًا»
             * — وهو وعدٌ صحيح. فالحارسُ على الفراغ لا على الصفر.
             */
            if ($locked->items()->count() === 0) {
                throw new RuntimeException(__('لا يُرسَل عرضٌ بلا بنود.'));
            }

            $locked->setRelation('items', $locked->items()->get());

            /*
             * ═══ والسياساتُ تُفحص ثانيةً لحظةَ الخروج ═══
             *
             * المسودّةُ فُحصت يومَ حُفظت — وقد تُضاف سياسةٌ بعدها، أو يُنقَّح
             * عرضٌ فيرث تاريخًا صار عاجلًا. فكلُّ مخالفةٍ قائمةٍ الآن يجب
             * أن يقابلها استثناءٌ **معتمَد**؛ وإلّا لا يخرج.
             */
            $exceptions = $locked->policyExceptions()->get();

            if ($exceptions->contains(fn (PolicyException $e) => ! $e->isApproved())) {
                throw new RuntimeException(__('هذا العرض ينتظر اعتماد استثناءٍ من سياسة — لا يخرج قبله.'));
            }

            foreach (CompanyPolicies::findings((int) $locked->business_id, $locked) as $finding) {
                if (! $exceptions->contains('policy_key', $finding->key())) {
                    throw new RuntimeException(__('هذا العرض يخالف سياسة «:policy» ولم يُسجَّل له استثناء — احفظه مرّةً أخرى مع السبب.', [
                        'policy' => Registry::label($finding->key()),
                    ]));
                }
            }

            /* ونصُّ السياسات يُختم قبل البصمة: البصمةُ تقرؤه (§18) */
            $locked->policy_snapshot = CompanyPolicies::snapshot((int) $locked->business_id, $locked);

            $locked->update([
                /*
                 * والتنقيحُ يرث رقمَ جذره: «Q-000004 — النسخة الثانية» عرضٌ
                 * واحدٌ نُقّح، لا عرضان. ورقمان لسلسلةٍ واحدة يجعلان العميل
                 * يسأل أيّهما يعتمد.
                 */
                'number' => $locked->number ?: self::chainNumber($locked),
                'status' => Quotation::SENT,
                'sent_at' => now(),
                'sent_by' => $actor?->id,
                /* وتُختم بحال الشركة لحظةَ الخروج — انظر `Document\Snapshot` */
                Snapshot::COLUMN => Snapshot::capture((int) $locked->business_id),
                'policy_snapshot' => $locked->policy_snapshot,
                'content_hash' => self::contentHash($locked),
            ]);

            Activity::log('status', 'أرسل عرض السعر '.self::label($locked).' بمبلغ '.$locked->total, [
                'subject_id' => $locked->id, 'subject_type' => 'quotation',
            ]);

            return $locked->fresh('items');
        });
    }

    /**
     * رقمُ السلسلة — رقمُ الجذر إن كان له، وإلّا رقمٌ جديد.
     *
     * والقراءةُ من الجذر لا من «أحدث تنقيحٍ سابق»: سلسلةٌ نُقّحت ثلاثًا
     * تقرأ الرقمَ نفسَه في كلّ مرّة مهما تغيّر ترتيبُ الإرسال.
     */
    private static function chainNumber(Quotation $quotation): string
    {
        $rootNumber = $quotation->root_id === null
            ? null
            : Quotation::where('business_id', $quotation->business_id)
                ->whereKey($quotation->root_id)->value('number');

        return $rootNumber ?: self::nextNumber((int) $quotation->business_id);
    }

    /**
     * التنقيح — نسخةٌ جديدةٌ تبدأ مسودّةً، والأصلُ يبقى ويصير «حلّ محلّه».
     *
     * ولا يُمحى تاريخ: ما خرج إلى عميلٍ يبقى كما خرج، ويُقرأ بعد سنةٍ كما
     * قرأه صاحبُه يومَها.
     */
    public static function revise(Quotation $quotation, ?User $actor = null): Quotation
    {
        if (! in_array($quotation->status, [Quotation::SENT, Quotation::REJECTED], true)) {
            throw new RuntimeException(__('لا يُنقَّح إلا عرضٌ أُرسل أو رُفض.'));
        }

        return DB::transaction(function () use ($quotation, $actor) {
            $rootId = $quotation->rootId();

            /*
             * وأعلى تنقيحٍ في السلسلة كلِّها لا «تنقيحي + ١».
             *
             * من نقّح النسخةَ الأولى بعد أن صارت الثانية موجودةً يكتب ثالثةً
             * لا ثانيةً — وإلّا اصطدم بفهرس التفرّد (الرقم + التنقيح).
             */
            $highest = (int) Quotation::where('business_id', $quotation->business_id)
                ->where(fn ($q) => $q->whereKey($rootId)->orWhere('root_id', $rootId))
                ->max('revision');

            $copy = $quotation->replicate([
                'status', 'revision', 'root_id',
                'approved_by', 'approved_at', 'sent_by', 'sent_at', 'sent_to',
                'first_viewed_at', 'last_viewed_at', 'view_count',
                'decided_at', 'decided_by', 'decided_by_name', 'rejection_reason',
                'cancelled_at', 'cancelled_by', 'cancellation_reason',
                'document_snapshot', 'policy_snapshot', 'content_hash',
            ]);

            $copy->fill([
                'status' => Quotation::DRAFT,
                'revision' => $highest + 1,
                'root_id' => $rootId,
                /* والرقمُ يبقى رقمَ السلسلة — هو العرضُ نفسُه في نسخةٍ ثانية */
                'number' => $quotation->number,
                'view_count' => 0,
                'created_by' => $actor?->id,
            ]);
            $copy->save();

            foreach ($quotation->items as $item) {
                $row = $item->replicate(['quotation_id']);
                $row->quotation_id = $copy->id;
                $row->save();
            }

            /*
             * والاستثناءاتُ تُحمل بأسبابها ويُعاد اعتمادُها.
             *
             * التنقيحُ ورقةٌ جديدة، ومن اعتمد استثناءً على الأولى لم ير
             * الثانية. فالسببُ يُنقل — كتبه صاحبُه مرّة — والاعتمادُ يُطلب
             * ثانيةً. ومخالفةٌ لم يكن لها سببٌ تُترك: `send` يقولها بوضوح.
             */
            $carried = $quotation->policyExceptions->mapWithKeys(fn (PolicyException $e) => [
                $e->policy_key => ['key' => $e->policy_key, 'reason' => $e->reason, 'surcharge_amount' => $e->surcharge_amount],
            ]);
            $copy->setRelation('items', $copy->items()->get());
            $mayOverride = (bool) $actor?->may(Permissions::POLICY_OVERRIDE);
            $rows = [];
            $pending = false;

            foreach (CompanyPolicies::findings((int) $copy->business_id, $copy) as $finding) {
                $input = $carried->get($finding->key());

                if ($input === null) {
                    continue;
                }

                $rows[] = self::exceptionRow($finding, (string) $input['reason'], $input['surcharge_amount'], $actor, $mayOverride);
                $pending = $pending || ($finding->requiresApproval && ! $mayOverride);
            }

            self::writeExceptions($copy, $rows);

            if ($pending) {
                $copy->update(['status' => Quotation::PENDING_APPROVAL]);
            }

            $quotation->update(['status' => Quotation::SUPERSEDED]);

            Activity::log('updated', 'أنشأ تنقيحًا لعرض السعر '.self::label($copy), [
                'subject_id' => $copy->id, 'subject_type' => 'quotation',
            ]);

            return $copy->fresh('items');
        });
    }

    /**
     * نسخةٌ مستقلّة — عرضٌ جديدٌ بمحتوى قديم.
     *
     * وهي غيرُ التنقيح: التنقيحُ نسخةٌ ثانية من **العرض نفسِه** لعميله
     * نفسِه، وهذه **عرضٌ آخر** يبدأ من صفر — لفعاليّةٍ تشبه سابقتَها أو
     * لعميلٍ آخر. فلها رقمُها وسلسلتُها.
     */
    public static function duplicate(Quotation $quotation, ?User $actor = null): Quotation
    {
        return DB::transaction(function () use ($quotation, $actor) {
            $copy = $quotation->replicate([
                'number', 'status', 'revision', 'root_id',
                'approved_by', 'approved_at', 'sent_by', 'sent_at', 'sent_to',
                'first_viewed_at', 'last_viewed_at', 'view_count',
                'decided_at', 'decided_by', 'decided_by_name', 'rejection_reason',
                'cancelled_at', 'cancelled_by', 'cancellation_reason',
                'document_snapshot', 'policy_snapshot', 'content_hash',
            ]);

            $copy->fill([
                'number' => null,
                'status' => Quotation::DRAFT,
                'revision' => 1,
                'root_id' => null,
                'view_count' => 0,
                'issued_at' => now()->toDateString(),
                'created_by' => $actor?->id,
            ]);
            $copy->save();

            foreach ($quotation->items as $item) {
                $row = $item->replicate(['quotation_id']);
                $row->quotation_id = $copy->id;
                $row->save();
            }

            Activity::log('created', 'نسخ عرض السعر '.self::label($quotation), [
                'subject_id' => $copy->id, 'subject_type' => 'quotation',
            ]);

            return $copy->fresh('items');
        });
    }

    /**
     * إلغاءٌ داخليّ — قبل أن يُقرَّر فيه. وتحت قفل الصفّ على حاله **الآن**.
     *
     * قبولٌ من الرابط سبق الإلغاءَ بلحظة: نموذجٌ فُتح على «مُرسَل» يكتب
     * «ملغى» فوق عرضٍ قُبل وأُنشئ مشروعُه. فيُقرأ الصفُّ مقفولًا ويُحكم عليه.
     */
    public static function cancel(Quotation $quotation, string $reason, ?User $actor = null): Quotation
    {
        return DB::transaction(function () use ($quotation, $reason, $actor) {
            $locked = Quotation::where('business_id', $quotation->business_id)
                ->lockForUpdate()->findOrFail($quotation->id);

            if (in_array($locked->status, [Quotation::ACCEPTED, Quotation::SUPERSEDED], true)) {
                throw new RuntimeException(__('لا يُلغى عرضٌ قُبل أو حلّ محلَّه تنقيح.'));
            }
            if ($locked->status === Quotation::CANCELLED) {
                return $locked;
            }

            $locked->update([
                'status' => Quotation::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $actor?->id,
                'cancellation_reason' => self::text($reason, 255),
            ]);

            Activity::log('deleted', 'ألغى عرض السعر '.self::label($locked).' — '.$reason, [
                'subject_id' => $locked->id, 'subject_type' => 'quotation',
            ]);

            return $locked->fresh();
        });
    }

    /**
     * تسجيلُ قرار العميل — قبولًا أو رفضًا.
     *
     * ويُسجَّل نيابةً في هذه المرحلة: القبولُ من الرابط العامّ بتوقيعٍ
     * إلكترونيّ مرحلةٌ مستقلّة. وحتى تُبنى، فالمكالمةُ الهاتفيّة والبريدُ
     * هما ما يقع فعلًا — ونظامٌ لا يسجّلهما يترك أكثرَ القرارات خارجه.
     *
     * @param  array<string, mixed>  $data
     */
    public static function recordDecision(Quotation $quotation, string $decision, array $data = [], ?User $actor = null): Quotation
    {
        if ($quotation->status !== Quotation::SENT) {
            throw new RuntimeException(__('لا يُسجَّل قرارٌ إلا على عرضٍ أُرسل.'));
        }

        $accepted = $decision === Quotation::ACCEPTED;

        if (! $accepted && $decision !== Quotation::REJECTED) {
            throw new RuntimeException(__('قرارٌ غير معروف.'));
        }

        /* والرفضُ بلا سببٍ لا يُقرأ بعد شهر: لماذا خسرنا هذا العرض؟ */
        $reason = self::text($data['rejection_reason'] ?? null, 255);

        if (! $accepted && ($reason === null || $reason === '')) {
            throw new RuntimeException(__('اكتب سبب الرفض — عرضٌ يُخسر بلا سببٍ مكتوب لا يُتعلَّم منه.'));
        }

        return DB::transaction(function () use ($quotation, $accepted, $reason, $data, $actor) {
            /* وتحت قفل: تسجيلان معًا لا يُنشئان مشروعين — انظر `Projects::fromQuotation` */
            $locked = Quotation::where('business_id', $quotation->business_id)
                ->lockForUpdate()->findOrFail($quotation->id);

            if ($locked->status !== Quotation::SENT) {
                throw new RuntimeException(__('لا يُسجَّل قرارٌ إلا على عرضٍ أُرسل.'));
            }

            /*
             * والقبولُ نيابةً يُفحص كما يُفحص من الرابط: الصفُّ الآن ببصمة
             * الإرسال. فلا يُسجَّل باسم العميل قبولُ ورقةٍ غيرِ التي رآها.
             * وعرضٌ خرج قبل أن تُختم البصمات (بلا `content_hash`) يمرّ كما كان.
             */
            if ($accepted && $locked->content_hash !== null) {
                $locked->setRelation('items', $locked->items()->get());

                if (! Fingerprint::matches(self::hashPayload($locked), $locked->content_hash)) {
                    throw new RuntimeException(__('تغيّر محتوى هذا العرض بعد إرساله — لا يُسجَّل قبولُه بهذه الصورة.'));
                }
            }

            $locked->update([
                'status' => $accepted ? Quotation::ACCEPTED : Quotation::REJECTED,
                'decided_at' => now(),
                'decided_by' => $actor?->id,
                'decided_by_name' => self::text($data['decided_by_name'] ?? null, 120),
                'rejection_reason' => $accepted ? null : $reason,
            ]);

            Activity::log('status', ($accepted ? 'سجّل قبول العميل لعرض السعر ' : 'سجّل رفض العميل لعرض السعر ')
                .self::label($locked), [
                    'subject_id' => $locked->id, 'subject_type' => 'quotation',
                ]);

            if ($accepted) {
                Projects::fromQuotation($locked->fresh('items'), $actor);
            }

            return $locked->fresh();
        });
    }

    /**
     * قرارُ العميل **من رابطه** — دليلٌ يُكتب، لا خبرٌ يُسجَّل.
     *
     * ═══ ما يجعله دليلًا ═══
     *
     * • البصمةُ تُحسب من الصفّ **الآن** وتُقارَن بما خُتم عند الإرسال: لو
     *   مُسّ العرضُ في القاعدة بعد خروجه رُدّ القرارُ — العميلُ يقبل ما
     *   رآه، لا ما صار.
     * • اللقطةُ تُكتب في `document_approvals` مع الموقّع ونصِّ إقراره
     *   والعنوانِ والمتصفّح.
     * • والصفُّ واحدٌ لكلّ عرض — بقيدٍ في القاعدة لا بشرطٍ هنا.
     *
     * ═══ وتحت قفل ═══
     *
     * ضغطتان على «أقبل» تقرآن «أُرسل» معًا خارج القفل فتكتبان قرارين.
     * والقفلُ يُسلسلهما، والقيدُ يردّ الثانية إن مرّت.
     *
     * @param  array<string, mixed>  $signer  `name` · `title` · `email` · `phone` · `consent` · `decline_reason`
     * @param  array<string, mixed>  $meta  `ip` · `user_agent`
     */
    public static function decide(Quotation $quotation, string $decision, array $signer, array $meta = []): Quotation
    {
        $accepted = $decision === DocumentApproval::ACCEPTED;

        if (! $accepted && $decision !== DocumentApproval::DECLINED) {
            throw new RuntimeException(__('قرارٌ غير معروف.'));
        }

        $name = self::text($signer['name'] ?? null, 120);

        if ($name === null || $name === '') {
            throw new RuntimeException(__('اكتب اسمك ليُسجَّل القرار باسمه.'));
        }

        $declineReason = self::text($signer['decline_reason'] ?? null, 500);

        if (! $accepted && ($declineReason === null || $declineReason === '')) {
            throw new RuntimeException(__('اكتب سبب الاعتذار — يساعدنا على تحسين ما نعرضه.'));
        }

        return DB::transaction(function () use ($quotation, $accepted, $name, $signer, $declineReason, $meta) {
            $locked = Quotation::where('business_id', $quotation->business_id)
                ->lockForUpdate()->findOrFail($quotation->id);

            if (! $locked->isDecidable()) {
                throw new RuntimeException($locked->isExpired()
                    ? __('انتهت صلاحية هذا العرض — تواصل مع الشركة لتجديده.')
                    : __('هذا العرض لا يقبل قرارًا الآن.'));
            }

            $locked->setRelation('items', $locked->items()->get());
            $payload = self::hashPayload($locked);

            if (! Fingerprint::matches($payload, $locked->content_hash)) {
                throw new RuntimeException(__('تغيّر محتوى هذا العرض بعد إرساله — لا يُقبل بهذه الصورة. تواصل مع الشركة.'));
            }

            try {
                $approval = DocumentApproval::create([
                    'business_id' => $locked->business_id,
                    'approvable_type' => $locked->getMorphClass(),
                    'approvable_id' => $locked->id,
                    'provider' => DocumentApproval::INTERNAL,
                    'decision' => $accepted ? DocumentApproval::ACCEPTED : DocumentApproval::DECLINED,
                    'content_hash' => $locked->content_hash,
                    'accepted_snapshot' => $payload,
                    'signer_name' => $name,
                    'signer_title' => self::text($signer['title'] ?? null, 120),
                    'signer_email' => self::text($signer['email'] ?? null, 120),
                    'signer_phone' => self::text($signer['phone'] ?? null, 40),
                    'consent_text' => $accepted ? self::text($signer['consent'] ?? null, 1000) : null,
                    'decline_reason' => $accepted ? null : $declineReason,
                    'decided_at' => now(),
                    'ip' => self::text($meta['ip'] ?? null, 45),
                    'user_agent' => self::text($meta['user_agent'] ?? null, 255),
                ]);
            } catch (QueryException) {
                /* القيدُ الفريد ردّ قرارًا ثانيًا سبقه أوّل — على SQLite حيث لا قفل */
                throw new RuntimeException(__('قُرّر في هذا العرض قبل لحظات.'));
            }

            $locked->update([
                'status' => $accepted ? Quotation::ACCEPTED : Quotation::REJECTED,
                'decided_at' => $approval->decided_at,
                'decided_by' => null,
                'decided_by_name' => $name,
                'rejection_reason' => $accepted ? null : $declineReason,
            ]);

            /* قرارُ عميل ← من الرابط: الاسم · البريد · IP · الوقت · البصمة (§24) */
            Activity::log('status', ($accepted ? 'قبل العميل عرض السعر ' : 'اعتذر العميل عن عرض السعر ')
                .self::label($locked).' إلكترونيًّا — '.$name
                .(filled($approval->signer_email) ? ' <'.$approval->signer_email.'>' : ''), [
                    'business_id' => $locked->business_id,
                    'subject_id' => $locked->id, 'subject_type' => 'quotation',
                    'icon' => $accepted ? 'file-check' : 'file-x',
                    'color' => $accepted ? 'success' : 'danger',
                ]);

            /* والقبولُ يُنشئ المشروعَ في المعاملة نفسِها — مرّةً، انظر `Projects::fromQuotation` */
            if ($accepted) {
                Projects::fromQuotation($locked->fresh('items'));
            }

            return $locked->fresh();
        });
    }

    /* ─────────────────────────── الإيصال ─────────────────────────── */

    /**
     * إرسالُ الرابط بالبريد — أوّلَ مرّةٍ عرضًا، وبعدها تذكيرًا.
     *
     * والإرسالُ هنا **إيصالٌ** لا تحويلُ حال: `send` أخرجت الورقةَ وقطعت
     * رقمَها وختمتها، وهذه توصلها. ولا تُنادى قبلها — رابطُ مسودّةٍ يفتح
     * على ٤٠٤ في يد العميل.
     *
     * والقناةُ تقول عن نفسها هل تُوصِل (`Notifier::configured`): بريدٌ إلى
     * `log` «ينجح» في نظر لارافيل والرسالةُ في ملفّ على الخادم — فيُرفض
     * هنا لا في الشاشة وحدها.
     */
    public static function email(Quotation $quotation, string $to, ?string $note, ?User $actor = null): void
    {
        if (! $quotation->isDecidable()) {
            throw new RuntimeException($quotation->isExpired()
                ? __('انتهت صلاحية هذا العرض — نقّحه قبل أن تذكّر به.')
                : __('لا يُرسَل بالبريد إلا عرضٌ خرج وينتظر قرارًا.'));
        }

        $notifier = Notifiers::email();

        if (! $notifier->configured()) {
            throw new RuntimeException(__('إرسالُ البريد غير مضبوط على الخادم — انسخ الرابط وأرسله بنفسك.'));
        }

        $business = Business::find($quotation->business_id);
        $reminder = $quotation->sent_to !== null;
        $label = self::label($quotation);
        $currency = Money::of((int) $quotation->business_id);

        $paragraphs = array_values(array_filter([
            filled($quotation->attention_to) ? __('السيد/ة :name المحترم/ة،', ['name' => $quotation->attention_to]) : __('تحية طيبة،'),
            $reminder
                ? __('نذكّركم بعرض السعر :label الخاص بـ«:title» — وهو صالح حتى :date.', [
                    'label' => $label, 'title' => $quotation->title,
                    'date' => optional($quotation->valid_until)->format('Y-m-d') ?: '—',
                ])
                : __('يسرّنا أن نرفق عرض السعر :label الخاص بـ«:title» بقيمة :total — وهو صالح حتى :date.', [
                    'label' => $label, 'title' => $quotation->title,
                    'total' => Money::format((float) $quotation->total, $currency),
                    'date' => optional($quotation->valid_until)->format('Y-m-d') ?: '—',
                ]),
            self::text($note, 2000),
            __('يمكنكم قراءة العرض كاملًا وقبوله إلكترونيًا من الرابط أدناه.'),
        ], fn ($p) => filled($p)));

        $result = $notifier->send(new MessageIntent(
            to: $to,
            subject: ($reminder ? __('تذكير: ') : '').__('عرض سعر :label — :company', [
                'label' => $label, 'company' => $business?->name ?? '',
            ]),
            paragraphs: $paragraphs,
            url: PublicDocument::url($quotation),
            action: __('فتح عرض السعر'),
            templateKey: $reminder ? 'quotation.reminder' : 'quotation.link',
            senderName: $business?->name,
            replyTo: filled($actor?->email) ? $actor->email : (filled($business?->email) ? $business->email : null),
            footer: $business?->name,
        ));

        if (! $result->sent) {
            throw new RuntimeException((string) $result->reason);
        }

        if (! $reminder) {
            $quotation->forceFill(['sent_to' => self::text($to, 120)])->saveQuietly();
        }

        /* عرضٌ أُرسل ← من · إلى أيّ بريد · متى (§24) */
        Activity::log('updated', ($reminder ? 'أرسل تذكيرًا بعرض السعر ' : 'أرسل عرض السعر ').$label.' بالبريد إلى '.$to, [
            'subject_id' => $quotation->id, 'subject_type' => 'quotation', 'icon' => 'mail',
        ]);
    }

    /**
     * خبرُ القرار — إلى الشركة، وإيصالٌ إلى من قرّر.
     *
     * وأفضلُ جهدٍ لا شرط: القرارُ كُتب وثبت، وبريدٌ يسقط لا يُسقطه. فلا
     * استثناءَ يخرج من هنا، وما فشل يُقيَّد في سجلّ الأخطاء.
     */
    public static function notifyDecision(Quotation $quotation, DocumentApproval $approval): void
    {
        $notifier = Notifiers::email();

        if (! $notifier->configured()) {
            return;
        }

        $business = Business::find($quotation->business_id);
        $label = self::label($quotation);
        $accepted = $approval->isAccepted();

        if (Mailer::deliverable($business)) {
            $notifier->send(new MessageIntent(
                to: (string) $business->email,
                subject: $accepted
                    ? __('قبل العميل عرض السعر :label', ['label' => $label])
                    : __('اعتذر العميل عن عرض السعر :label', ['label' => $label]),
                paragraphs: array_values(array_filter([
                    __(':name — :customer', ['name' => $approval->signer_name, 'customer' => (string) $quotation->customer_name])
                        .(filled($approval->signer_title) ? ' ('.$approval->signer_title.')' : ''),
                    $accepted
                        ? __('قبل العرض إلكترونيًا في :at.', ['at' => $approval->decided_at?->format('Y-m-d H:i')])
                        : __('اعتذر عن العرض في :at — السبب: :reason', [
                            'at' => $approval->decided_at?->format('Y-m-d H:i'), 'reason' => (string) $approval->decline_reason,
                        ]),
                    filled($approval->ip) ? __('من العنوان :ip', ['ip' => $approval->ip]) : null,
                ])),
                url: route('admin.quotations.show', $quotation->id),
                action: __('فتح العرض في النظام'),
                templateKey: $accepted ? 'quotation.accepted' : 'quotation.declined',
                senderName: $business->name,
            ));
        }

        if ($accepted && filled($approval->signer_email)) {
            $notifier->send(new MessageIntent(
                to: (string) $approval->signer_email,
                subject: __('تأكيد قبولكم لعرض السعر :label — :company', ['label' => $label, 'company' => $business?->name ?? '']),
                paragraphs: [
                    __('السيد/ة :name المحترم/ة،', ['name' => $approval->signer_name]),
                    __('نشكركم على قبول عرض السعر :label الخاص بـ«:title». هذا تأكيد بأن قبولكم سُجّل في :at.', [
                        'label' => $label, 'title' => $quotation->title,
                        'at' => $approval->decided_at?->format('Y-m-d H:i'),
                    ]),
                    __('بصمة المستند: :hash', ['hash' => $approval->content_hash]),
                ],
                url: PublicDocument::url($quotation),
                action: __('فتح عرض السعر'),
                templateKey: 'quotation.receipt',
                senderName: $business?->name,
                replyTo: filled($business?->email) ? $business->email : null,
                footer: $business?->name,
            ));
        }
    }

    /**
     * فتحَه العميلُ — واقعةٌ تُختم ولا تُغيّر حالًا.
     *
     * و«أوّلُ فتحٍ» يُكتب مرّةً: هو ما يُقاس به «أُرسل ولم يُفتح قطّ»، وأوّلُ
     * إعادة كتابةٍ له تمحو الخبرَ كلَّه. و«آخرُ فتح» يُكتب دائمًا.
     *
     * ولا يُقيَّد في سجلّ النشاط: من يفتح رابطًا عامًّا ليس مستخدمًا في
     * النظام، و`Activity::log` تنسب الفعلَ إلى فاعلٍ — فكانت تكتب «زائر»
     * صفًّا في كلّ تحديثِ صفحة.
     */
    public static function markViewed(Quotation $quotation): void
    {
        $quotation->forceFill([
            'first_viewed_at' => $quotation->first_viewed_at ?? now(),
            'last_viewed_at' => now(),
            'view_count' => (int) $quotation->view_count + 1,
        ])->saveQuietly();
    }

    /* ─────────────────────────── البصمة ─────────────────────────── */

    /**
     * بصمةُ ما خرج — `sha256` على البنود والإجماليّات والشروط.
     *
     * وهي ما يجعل القبولَ **دليلًا** حين يُبنى: سجلُّ القبول يحمل البصمة،
     * فيُثبَت بعد سنةٍ أنّ ما قُبل هو هذا بعينه لا نسخةٌ عُدّلت بعده.
     *
     * والترتيبُ مثبَّت (`sort_order` ثمّ `id`) والأرقامُ مصوغةٌ بثلاث منازل:
     * بصمةٌ تتغيّر لأنّ القاعدة ردّت الصفوفَ بترتيبٍ آخر ليست بصمة.
     */
    public static function contentHash(Quotation $quotation): string
    {
        return Fingerprint::of(self::hashPayload($quotation));
    }

    /**
     * الحمولةُ التي تُبصَم — وهي نفسُها ما يُكتب في `accepted_snapshot`.
     *
     * فالدليلُ يحمل ما بُصم لا وصفًا له: من شكّ بعد سنةٍ أعاد الحسابَ
     * على اللقطة وقارن — انظر `DocumentApproval::verify`.
     *
     * @return array<string, mixed>
     */
    public static function hashPayload(Quotation $quotation): array
    {
        return [
            'items' => $quotation->items->map(fn (QuotationItem $i) => [
                'p' => $i->product_id,
                'd' => (string) $i->description,
                'u' => (string) $i->unit,
                'q' => number_format((float) $i->quantity, 3, '.', ''),
                'l' => number_format((float) $i->list_price, 3, '.', ''),
                'n' => number_format((float) $i->unit_price, 3, '.', ''),
                'x' => number_format((float) $i->discount, 3, '.', ''),
                'r' => number_format((float) $i->tax_rate, 2, '.', ''),
                't' => number_format((float) $i->line_total, 3, '.', ''),
            ])->values()->all(),
            'totals' => [
                number_format((float) $quotation->subtotal, 3, '.', ''),
                number_format((float) $quotation->discount_total, 3, '.', ''),
                number_format((float) $quotation->tax_total, 3, '.', ''),
                number_format((float) $quotation->total, 3, '.', ''),
            ],
            'terms' => [
                (string) $quotation->scope,
                (string) $quotation->terms,
                (string) $quotation->notes,
                (string) $quotation->valid_until?->toDateString(),
                (string) $quotation->payment_terms_days,
            ],
            /*
             * وسياساتُ الشركة كما خُتمت: مفتاحٌ ونسخةٌ ونصّان. فتعديلُ
             * سياسةٍ بعد الإرسال لا يمسّ بصمةَ ما خرج — والعميلُ يقبل
             * النصَّ الذي قرأه بعينه.
             */
            'policies' => collect($quotation->policy_snapshot ?? [])->map(fn (array $p) => [
                'k' => (string) ($p['key'] ?? ''),
                'v' => (int) ($p['version'] ?? 0),
                'a' => (string) ($p['text_ar'] ?? ''),
                'e' => (string) ($p['text_en'] ?? ''),
            ])->values()->all(),
        ];
    }

    /* ─────────────────────────── الباقات ─────────────────────────── */

    /**
     * باقةٌ مفكوكةً إلى بنودها — بأسعار الكتالوج لكلٍّ منها.
     *
     * وتُفَكّ في الخادم لا في الشاشة: الأسعارُ تأتي من الكتالوج، والشاشةُ
     * لا تُؤتمن على واحدٍ منها.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function expand(Product $package): array
    {
        if (($package->kind ?? Product::ITEM) !== Product::PACKAGE) {
            return [];
        }

        return $package->packageItems()->with('item')->get()
            ->filter(fn ($row) => $row->item !== null)
            ->map(fn ($row) => [
                'product_id' => $row->item_id,
                'description' => (string) $row->item->name,
                'unit' => $row->unit,
                'quantity' => (float) $row->quantity,
                'unit_price' => $row->item->sellingPrice(),
            ])->values()->all();
    }

    /* ─────────────────────────── أدوات ─────────────────────────── */

    /** اسمُ العرض في السجلّ — رقمُه وتنقيحُه، أو «مسودّة» إن لم يُرقَّم */
    public static function label(Quotation $quotation): string
    {
        if (! $quotation->number) {
            return __('مسودة');
        }

        return $quotation->revision > 1
            ? $quotation->number.' ('.__('تنقيح :n', ['n' => $quotation->revision]).')'
            : (string) $quotation->number;
    }

    /** نصٌّ مقصوصٌ على طوله — والفارغُ `null` لا سلسلةً خاوية */
    private static function text(mixed $value, int $max): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /** تاريخٌ أو `null` — ونصٌّ لا يُقرأ تاريخًا لا يُكتب صفرًا */
    private static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
