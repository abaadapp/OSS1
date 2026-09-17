<?php

namespace App\Support;

use App\Models\Currency;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\Document\Snapshot;
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
    private static function attributes(int $businessId, ?Customer $customer, array $data, array $totals): array
    {
        $issuedAt = isset($data['issued_at']) ? Carbon::parse($data['issued_at']) : now();

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
            'attention_to' => self::text($data['attention_to'] ?? null, 120) ?? $customer?->contact_person,
            'contact_email' => self::text($data['contact_email'] ?? null, 120) ?? $customer?->contact_email,
            'contact_phone' => self::text($data['contact_phone'] ?? null, 40) ?? $customer?->contact_phone,
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

        $quotation = new Quotation(self::attributes($businessId, $customer, $data, $totals) + [
            'number' => null,
            'status' => Quotation::DRAFT,
            'revision' => 1,
        ]);

        /*
         * والبنودُ تُعلَّق بلا حفظ: `setRelation` تجعل `$quotation->items`
         * تردّ ما بُني هنا بدل أن تسأل القاعدةَ عن صفوفٍ لا وجود لها —
         * فالقالبُ يمرّ عليها كما يمرّ على بنود ورقةٍ محفوظة.
         */
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
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function create(int $businessId, Customer $customer, array $data, array $lines, ?User $actor = null): Quotation
    {
        if ((int) $customer->business_id !== $businessId) {
            throw new RuntimeException(__('هذا العميل ليس من عملاء شركتك.'));
        }

        $priced = self::price($businessId, $lines, $actor);
        $totals = self::totals($businessId, $priced['lines']);

        return DB::transaction(function () use ($businessId, $customer, $data, $totals, $priced, $actor) {
            $quotation = Quotation::create(
                self::attributes($businessId, $customer, $data, $totals) + [
                    'number' => null,
                    'status' => $priced['needsApproval'] ? Quotation::PENDING_APPROVAL : Quotation::DRAFT,
                    'revision' => 1,
                    'created_by' => $actor?->id,
                ]
            );

            self::writeItems($quotation, $totals['items']);

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
     */
    public static function update(Quotation $quotation, array $data, array $lines, ?User $actor = null): Quotation
    {
        if ($quotation->isFrozen()) {
            throw new RuntimeException(__('هذا العرض خرج إلى العميل — لا يُعدَّل. أنشئ تنقيحًا جديدًا.'));
        }

        $businessId = (int) $quotation->business_id;
        $customer = $quotation->customer ?: Customer::where('business_id', $businessId)
            ->whereKey($quotation->customer_id)->firstOrFail();

        $priced = self::price($businessId, $lines, $actor);
        $totals = self::totals($businessId, $priced['lines']);

        return DB::transaction(function () use ($quotation, $businessId, $customer, $data, $totals, $priced) {
            $quotation->update(self::attributes($businessId, $customer, $data, $totals) + [
                'status' => $priced['needsApproval'] ? Quotation::PENDING_APPROVAL : Quotation::DRAFT,
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

        return DB::transaction(function () use ($quotation, $actor) {
            $quotation->update([
                'status' => Quotation::DRAFT,
                'approved_by' => $actor?->id,
                'approved_at' => now(),
            ]);

            $quotation->items()->whereNull('discount_approved_by')
                ->whereNotNull('discount_requested_by')
                ->update(['discount_approved_by' => $actor?->id, 'discount_approved_at' => now()]);

            Activity::log('status', 'اعتمد خصم عرض السعر '.self::label($quotation), [
                'subject_id' => $quotation->id, 'subject_type' => 'quotation',
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
                'approved_by', 'approved_at', 'sent_by', 'sent_at',
                'first_viewed_at', 'last_viewed_at', 'view_count',
                'decided_at', 'decided_by', 'decided_by_name', 'rejection_reason',
                'cancelled_at', 'cancelled_by', 'cancellation_reason',
                'document_snapshot', 'content_hash',
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
                'approved_by', 'approved_at', 'sent_by', 'sent_at',
                'first_viewed_at', 'last_viewed_at', 'view_count',
                'decided_at', 'decided_by', 'decided_by_name', 'rejection_reason',
                'cancelled_at', 'cancelled_by', 'cancellation_reason',
                'document_snapshot', 'content_hash',
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

    /** إلغاءٌ داخليّ — قبل أن يُقرَّر فيه */
    public static function cancel(Quotation $quotation, string $reason, ?User $actor = null): Quotation
    {
        if (in_array($quotation->status, [Quotation::ACCEPTED, Quotation::SUPERSEDED], true)) {
            throw new RuntimeException(__('لا يُلغى عرضٌ قُبل أو حلّ محلَّه تنقيح.'));
        }
        if ($quotation->status === Quotation::CANCELLED) {
            return $quotation;
        }

        $quotation->update([
            'status' => Quotation::CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $actor?->id,
            'cancellation_reason' => self::text($reason, 255),
        ]);

        Activity::log('deleted', 'ألغى عرض السعر '.self::label($quotation).' — '.$reason, [
            'subject_id' => $quotation->id, 'subject_type' => 'quotation',
        ]);

        return $quotation->fresh();
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

        $quotation->update([
            'status' => $accepted ? Quotation::ACCEPTED : Quotation::REJECTED,
            'decided_at' => now(),
            'decided_by' => $actor?->id,
            'decided_by_name' => self::text($data['decided_by_name'] ?? null, 120),
            'rejection_reason' => $accepted ? null : $reason,
        ]);

        Activity::log('status', ($accepted ? 'سجّل قبول العميل لعرض السعر ' : 'سجّل رفض العميل لعرض السعر ')
            .self::label($quotation), [
                'subject_id' => $quotation->id, 'subject_type' => 'quotation',
            ]);

        return $quotation->fresh();
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
        $payload = [
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
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
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
