<?php

namespace App\Support;

use App\Models\GoodsReceiptNote;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoicePayment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * سنداتُ المورّدين — كتابةً، ومطابقةً، ثمّ اعتمادًا.
 *
 * ═══ مالكُ ذمّة المورّد واحد ═══
 *
 * أمرُ الشراء نيّةُ شراءٍ لا التزام. واستلامُ البضاعة حركةُ مخزون لا مال.
 * والذمّةُ تنشأ **باعتماد السند وحده** — ولو نشأت في موضعٍ ثانٍ لحُمّل
 * المورّد مرّتين عن شحنةٍ واحدة.
 *
 * وكان السند يُرحَّل لحظةَ كتابته: يُدخله المحاسب فيصير على المتجر دَينٌ في
 * الدفتر قبل أن يراه أحد.
 *
 * ═══ والمطابقةُ ثلاثيّة ═══
 *
 * ما طُلب (أمر الشراء) · ما وصل (الاستلامات المعتمَدة) · ما طُولبنا به
 * (السند). وسندٌ بمئةٍ على أمرٍ بثمانين، أو سندٌ بالكامل على شحنةٍ وصل
 * تسعون بالمئة منها — كلاهما يمرّ بلا سؤال ويُدفع.
 *
 * والحصيلةُ ثلاثةُ أحوال: مطابق، تنبيه، ممنوع. والممنوعُ لا يُعتمد إلّا
 * بتجاوزٍ **بسببٍ يُكتب ويُنسب** — ولا يقع صامتًا.
 */
final class SupplierInvoices
{
    public const PENDING = 'بانتظار الاعتماد';

    public const APPROVED = 'معتمد';

    public const REJECTED = 'مرفوض';

    public const CANCELLED = 'ملغاة';

    public const MATCHED = 'مطابق';

    public const WARNING = 'تنبيه';

    public const BLOCKED = 'ممنوع';

    /**
     * حدُّ التسامح: نصفُ بيسة.
     *
     * والفروقُ أدقُّ من ذلك تقريبُ قسمةٍ لا خلاف. ونسبةٌ مئويّة لا تصلح
     * هنا: خمسةٌ بالمئة من عشرة آلاف خمسُمئة ريال — ولا أحد يتسامح فيها.
     */
    private const TOLERANCE = 0.0005;

    /**
     * كتابةُ السند — بلا قيد.
     *
     * @param  array<string, mixed>  $data
     */
    public static function create(int $businessId, array $data, ?User $by = null): SupplierInvoice
    {
        $subtotal = round((float) ($data['subtotal'] ?? 0), 3);
        $tax = round((float) ($data['tax'] ?? 0), 3);
        $total = round($subtotal + $tax, 3);

        if ($total <= 0) {
            throw new RuntimeException(__('السند بلا مبلغ لا يُسجَّل'));
        }

        return DB::transaction(function () use ($businessId, $data, $subtotal, $tax, $total, $by) {
            $invoice = SupplierInvoice::create([
                'business_id' => $businessId,
                'supplier_id' => $data['supplier_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                /* والمشروعُ يُورَث من أمر الشراء إن لم يُذكر: سندٌ على أمرِ مشروعٍ سندُ ذلك المشروع */
                'project_id' => $data['project_id'] ?? (isset($data['purchase_order_id'])
                    ? PurchaseOrder::where('business_id', $businessId)->whereKey($data['purchase_order_id'])->value('project_id')
                    : null),
                'supplier_ref' => $data['supplier_ref'],
                'issued_at' => $data['issued_at'],
                'due_at' => $data['due_at'] ?? null,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
                'attachment' => $data['attachment'] ?? null,
                'attachment_name' => $data['attachment_name'] ?? null,
                /* طبيعةُ التكلفة بيد المحاسب — أو تُشتقّ من الأمر عند الاعتماد (المرحلة 12) */
                'cost_kind' => in_array($data['cost_kind'] ?? null, self::COST_KINDS, true) ? $data['cost_kind'] : null,
                'approval_status' => self::PENDING,
                'submitted_by' => $by?->id,
            ]);

            // والحصيلةُ تُكتب عند الكتابة لتُقرأ في الطابور — وتُعاد عند الاعتماد
            $match = self::match($invoice);
            $invoice->update(['match_status' => $match['status'], 'match_notes' => implode("\n", $match['notes'])]);

            Activity::log('created', 'سجّل سند مورّد '.$invoice->supplier_ref.' بقيمة '.$total.' — بانتظار الاعتماد', [
                'subject_id' => $invoice->id, 'subject_type' => 'supplier_invoice',
            ]);

            return $invoice->fresh();
        });
    }

    /**
     * المطابقةُ الثلاثيّة — على مستوى القيمة لا السطر.
     *
     * وسندُ المورّد في هذا النظام إجماليٌّ بلا بنود: يُدخله المحاسب رقمين —
     * مبلغًا وضريبة — كما هو في ورقة المورّد. فالمطابقةُ بالقيمة والكميّة
     * الإجماليّة لا بالسطر، ولا يُخترع لها جدولُ بنودٍ لا يملأه أحد.
     *
     * @return array{status: string, notes: list<string>}
     */
    public const COST_INVENTORY = 'inventory';

    public const COST_DIRECT = 'direct';

    public const COST_MIXED = 'mixed';

    public const COST_KINDS = [self::COST_INVENTORY, self::COST_DIRECT];

    public const COST_LABELS = [
        self::COST_INVENTORY => 'مخزون — أصنافٌ تدخل الرفّ',
        self::COST_DIRECT => 'تكلفة مباشرة — خدماتٌ ومشترياتٌ لا تدخل الرفّ',
        self::COST_MIXED => 'مختلط — مخزونٌ وتكلفةٌ معًا بحسب بنود الأمر',
    ];

    /**
     * كيف يُقسَم صافي السند بين المخزون والمشتريات المباشرة.
     *
     * ═══ القاعدة ═══
     *
     * ما قاله المحاسبُ بيده (`cost_kind`) يُطاع كلُّه. وإلّا فمن أمر الشراء:
     * حصّةُ المخزون = مجموعُ سطور الأصناف المخزونة ÷ مجموعِ السطور، والباقي
     * تكلفةٌ مباشرة. وسندٌ بلا أمرٍ — أو أمرٌ بلا صنفٍ مخزون — تكلفةٌ مباشرة
     * كلُّه: خدمةٌ اشتُريت لفعاليّةٍ لا بضاعةٌ للرفّ.
     *
     * والأصنافُ المخزونة تُقرأ من الكتالوج (`Product::isStocked`): الخدمةُ
     * والباقةُ لا رفَّ لهما ولو حملتا `product_id`.
     *
     * @return array{inventory: float, direct: float, kind: string}
     */
    public static function costSplit(SupplierInvoice $invoice, float $cost): array
    {
        $cost = round($cost, 3);

        if ($invoice->cost_kind === self::COST_INVENTORY) {
            return ['inventory' => $cost, 'direct' => 0.0, 'kind' => self::COST_INVENTORY];
        }
        if ($invoice->cost_kind === self::COST_DIRECT) {
            return ['inventory' => 0.0, 'direct' => $cost, 'kind' => self::COST_DIRECT];
        }

        $order = $invoice->purchase_order_id
            ? PurchaseOrder::where('business_id', $invoice->business_id)->with('items.product')->find($invoice->purchase_order_id)
            : null;

        if ($order === null || $order->items->isEmpty()) {
            return ['inventory' => 0.0, 'direct' => $cost, 'kind' => self::COST_DIRECT];
        }

        $lineValue = fn ($item) => (float) $item->line_total > 0.0005 ? (float) $item->line_total : (float) $item->cost * (float) $item->quantity;
        $all = round((float) $order->items->sum($lineValue), 3);
        $stocked = round((float) $order->items->filter(fn ($item) => $item->product !== null && $item->product->isStocked())->sum($lineValue), 3);

        if ($all <= 0.0005 || $stocked <= 0.0005) {
            return ['inventory' => 0.0, 'direct' => $cost, 'kind' => self::COST_DIRECT];
        }
        if (abs($all - $stocked) <= 0.0005) {
            return ['inventory' => $cost, 'direct' => 0.0, 'kind' => self::COST_INVENTORY];
        }

        $inventory = round($cost * $stocked / $all, 3);

        return ['inventory' => $inventory, 'direct' => round($cost - $inventory, 3), 'kind' => self::COST_MIXED];
    }

    public static function match(SupplierInvoice $invoice): array
    {
        $notes = [];
        $blocked = false;
        $po = $invoice->purchase_order_id
            ? PurchaseOrder::where('business_id', $invoice->business_id)->find($invoice->purchase_order_id)
            : null;

        if (! $po) {
            // سندٌ بلا أمر شراء ليس عطبًا: خدمةٌ أو شراءٌ عاجل — ولا يُطابَق
            return ['status' => self::MATCHED, 'notes' => []];
        }

        /*
         * والمورّدُ أوّلًا: سندٌ من مورّدٍ على أمرِ مورّدٍ آخر يخلط الحسابين،
         * فيُدفع لمن لا يستحقّ ويبقى المستحقُّ مطلوبًا.
         */
        if ((int) $po->supplier_id !== (int) $invoice->supplier_id) {
            $notes[] = __('السند لمورّدٍ غير مورّد أمر الشراء');
            $blocked = true;
        }

        $poTotal = round((float) $po->total, 3);
        $invTotal = round((float) $invoice->total, 3);
        $diff = round($invTotal - $poTotal, 3);

        if ($diff > self::TOLERANCE) {
            $notes[] = __('فاتورة المورد أعلى من أمر الشراء بـ :v', ['v' => number_format($diff, 3)]);
            $blocked = true;
        } elseif ($diff < -self::TOLERANCE) {
            // الأقلُّ تنبيهٌ لا منع: شحنةٌ ناقصة تُفوتَر بما وصل، وهذا صحيح
            $notes[] = __('فاتورة المورد أقلّ من أمر الشراء بـ :v', ['v' => number_format(abs($diff), 3)]);
        }

        /*
         * وما وصل فعلًا: الاستلاماتُ **المعتمَدة** وحدها.
         *
         * والمعلَّقُ لا يُحسب واصلًا — وإلّا لصار كتابةُ استلامٍ بلا اعتماد
         * طريقًا إلى تمرير سند. وهو ما فُتح البابُ لإغلاقه.
         */
        $items = $po->items()->get(['quantity', 'received_quantity', 'line_kind']);
        $ordered = (float) $items->sum('quantity');
        $received = (float) $items->sum('received_quantity');

        /*
         * والخدمةُ تُقال بلغتها: «تأكيدُ تنفيذ» لا «استلام».
         *
         * القاعدةُ واحدة على الأمر كلِّه — ما ثبت من بضاعةٍ وخدمةٍ معًا — وإنّما
         * يُقال الناقصُ من كلٍّ باسمه، فيعرف من يعتمد أهي شحنةٌ لم تصل أم خدمةٌ
         * لم يُؤكَّد تنفيذُها. انظر `GoodsReceipts` («والخدمةُ تُنفَّذ»).
         */
        $services = $items->where('line_kind', PurchaseOrderItem::SERVICE);
        $goods = $items->reject(fn ($i) => $i->line_kind === PurchaseOrderItem::SERVICE);
        $n = fn (float $v) => rtrim(rtrim(number_format($v, 3), '0'), '.');

        if ($ordered > 0 && $received + self::TOLERANCE < $ordered) {
            $goodsOrdered = (float) $goods->sum('quantity');
            $goodsReceived = (float) $goods->sum('received_quantity');
            if ($goodsOrdered > 0 && $goodsReceived + self::TOLERANCE < $goodsOrdered) {
                $notes[] = __('تم استلام :got من :want وحدة فقط', ['got' => $n($goodsReceived), 'want' => $n($goodsOrdered)]);
            }

            $servicesOrdered = (float) $services->sum('quantity');
            $servicesDone = (float) $services->sum('received_quantity');
            if ($servicesOrdered > 0 && $servicesDone + self::TOLERANCE < $servicesOrdered) {
                $notes[] = __('تم تأكيد تنفيذ :got من :want من الخدمات فقط', ['got' => $n($servicesDone), 'want' => $n($servicesOrdered)]);
            }

            /*
             * والسندُ الكاملُ على استلامٍ ناقص يُمنع: هو أشيعُ ما يُدفع بلا
             * حقّ — بضاعةٌ لم تصل يُدفع ثمنُها ثمّ يُطالَب المورّد بها بعد
             * شهرين وقد نُسي.
             */
            if ($diff >= -self::TOLERANCE) {
                $notes[] = __('والسند بقيمة أمر الشراء كاملًا');
                $blocked = true;
            }
        }

        if ($ordered > 0 && $received <= 0) {
            $notes[] = $goods->isEmpty()
                ? __('لم يُعتمد تأكيد تنفيذ أيّ خدمة على هذا الأمر بعد')
                : __('لم يُعتمد أيُّ استلامٍ على هذا الأمر بعد');
            $blocked = true;
        }

        return [
            'status' => $blocked ? self::BLOCKED : ($notes === [] ? self::MATCHED : self::WARNING),
            'notes' => $notes,
        ];
    }

    /**
     * الاعتماد — وهنا وحدَه تنشأ الذمّة.
     *
     * والوسمُ لا يقع إن سقط القيد: المعاملةُ تلفّ الاثنين، فسندٌ يقول
     * «معتمد» بلا قيدٍ في الدفتر لا يُكتب أصلًا.
     *
     * @throws RuntimeException
     */
    /**
     * سدادُ سندٍ معتمَد — القيدُ والدفعةُ والمسدَّدُ في معاملةٍ واحدة تحت قفل.
     *
     * كان في متحكّم السندات؛ خرج إلى هنا لمّا صار الاستيرادُ التاريخيُّ
     * (المرحلة 9) يكتب سدادًا قديمًا — فبابٌ واحد يقيّد «ذمم الموردين» مدينةً
     * والصندوقَ أو البنكَ دائنًا، لا نسختان.
     *
     * ═══ ولكلّ دفعةٍ صفُّها وإيصالُها ═══
     *
     * القيدُ يبقى مصدرَ الحقيقة، ومعه صفٌّ في `supplier_invoice_payments` يشير إليه
     * (`journal_entry_id` فريد) ويحفظ مصدرَ الدفعة ومن سجّلها وإيصالَها. والاثنان في
     * المعاملة نفسِها: لا دفعةَ بلا قيد، ولا قيدَ سدادٍ بلا دفعة، ولا مرّتين.
     *
     * والإيصالُ يُكتب على القرص الخاصّ قبل المعاملة ويُمحى إن سقطت — كما في سند
     * المورّد والمصروف — فلا يبقى ملفٌّ بلا دفعة، ولا دفعةٌ تشير إلى ملفٍّ لم يُكتب.
     *
     * ═══ والطلبُ المكرَّر لا يدفع مرّتين ═══
     *
     * `$requestKey` مفتاحُ نموذج السداد: ضغطةٌ ثانية أو إعادةُ إرسالٍ بالمفتاح نفسِه
     * تعود بالدفعة الأولى ولا تكتب شيئًا (يُسأل عنه قبل الرفع، ثمّ تحت القفل — فلا
     * يُترك للفهرس الفريد أن يصطدم ويُجهض المعاملة على PostgreSQL). وتجاوزُ المستحقّ
     * تحت التزامن يمنعه قفلُ صفّ السند: الثاني ينتظر الأوّل ثمّ يقرأ ما بقي.
     *
     * والدفعةُ تُعاد على السند الراجع علاقةً (`payment`)، و`wasRecentlyCreated` عليها
     * يقول أهي جديدةٌ أم تكرارُ طلبٍ سبق.
     *
     * @param  'cash'|'bank'  $from
     */
    public static function settle(
        SupplierInvoice $invoice,
        float $amount,
        string $from,
        Carbon $paidAt,
        ?int $userId = null,
        ?UploadedFile $receipt = null,
        ?string $requestKey = null,
    ): SupplierInvoice {
        $amount = round($amount, 3);

        if ($amount <= 0) {
            throw new RuntimeException(__('مبلغُ السداد يجب أن يكون أكبر من صفر.'));
        }
        if (! in_array($from, SupplierInvoicePayment::SOURCES, true)) {
            throw new RuntimeException(__('مصدرُ السداد الصندوقُ أو البنك.'));
        }

        $bid = (int) $invoice->business_id;
        $key = self::requestKey($requestKey);

        /* إعادةُ الطلب نفسِه — قبل أن يُرفع ملفٌّ لا حاجة إليه */
        if ($key !== null && ($existing = self::paymentByKey($bid, $key, $invoice)) !== null) {
            return $invoice->fresh()->setRelation('payment', $existing);
        }

        $stored = $receipt?->store("supplier-invoice-payments/{$bid}", 'local') ?: null;
        if ($receipt !== null && $stored === null) {
            /* إيصالٌ أُرفق ولم يُكتب — لا تُسجَّل دفعةٌ يظنّ صاحبُها أنّ إيصالَها معها */
            throw new RuntimeException(__('تعذّر حفظ الإيصال — لم تُسجَّل الدفعة، أعد المحاولة.'));
        }

        try {
            [$fresh, $payment] = DB::transaction(function () use ($invoice, $amount, $from, $paidAt, $userId, $receipt, $stored, $key, $bid) {
                $locked = SupplierInvoice::where('business_id', $bid)->lockForUpdate()->findOrFail($invoice->id);

                /* والمفتاحُ ثانيةً تحت القفل — طلبان بالمفتاح نفسِه وصلا معًا */
                if ($key !== null && ($existing = self::paymentByKey($bid, $key, $locked)) !== null) {
                    return [$locked, $existing];
                }

                if ($locked->approval_status !== self::APPROVED) {
                    throw new RuntimeException(__('لا يُسدَّد سندٌ لم يُعتمد — اعتمده أوّلًا.'));
                }
                if ($amount - $locked->outstanding() > 0.0005) {
                    throw new RuntimeException(__('المستحقّ على هذا السند :v فقط', [
                        'v' => number_format($locked->outstanding(), 3),
                    ]));
                }

                $entry = Ledger::post(
                    $bid,
                    __('سداد سند مورّد: ').$locked->supplier_ref,
                    [
                        ['account' => 'payable', 'debit' => $amount, 'memo' => $locked->supplier?->name],
                        ['account' => $from, 'credit' => $amount],
                    ],
                    $paidAt,
                    'سداد مورّد',
                    null,
                    $userId,
                    $locked,
                );

                $payment = SupplierInvoicePayment::create([
                    'business_id' => $bid,
                    'supplier_invoice_id' => $locked->id,
                    'journal_entry_id' => $entry->id,
                    'amount' => $amount,
                    'paid_at' => $paidAt->toDateString(),
                    'paid_from' => $from,
                    'receipt' => $stored,
                    'receipt_name' => $stored ? mb_substr($receipt->getClientOriginalName(), 0, 255) : null,
                    'idempotency_key' => $key,
                    'created_by' => $userId,
                ]);

                $locked->update(['paid' => round((float) $locked->paid + $amount, 3)]);
                $locked->syncStatus();

                return [$locked->fresh(), $payment];
            });
        } catch (Throwable $e) {
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }

            throw $e;
        }

        /* تكرارٌ وُجد تحت القفل: الدفعةُ الأولى بإيصالها، وملفُّ هذا الطلب لا يُستعمل */
        if ($stored && ! $payment->wasRecentlyCreated) {
            Storage::disk('local')->delete($stored);
        }

        return $fresh->setRelation('payment', $payment);
    }

    /** مفتاحُ الطلب — حروفٌ وأرقامٌ وشرطاتٌ حتى 64، أو لا مفتاح */
    private static function requestKey(?string $key): ?string
    {
        $key = $key === null ? '' : (string) preg_replace('/[^A-Za-z0-9_-]/', '', $key);

        return $key === '' ? null : mb_substr($key, 0, 64);
    }

    /** دفعةٌ سبقت بهذا المفتاح — ولا يصلح مفتاحُ دفعةٍ على سندٍ آخر */
    private static function paymentByKey(int $businessId, string $key, SupplierInvoice $invoice): ?SupplierInvoicePayment
    {
        $payment = SupplierInvoicePayment::where('business_id', $businessId)->where('idempotency_key', $key)->first();

        if ($payment !== null && (int) $payment->supplier_invoice_id !== (int) $invoice->id) {
            throw new RuntimeException(__('مفتاحُ هذا الطلب لدفعةٍ على سندٍ آخر — أعد فتح نموذج السداد.'));
        }

        return $payment;
    }

    public static function approve(SupplierInvoice $invoice, ?User $by = null, ?string $overrideReason = null): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice, $by, $overrideReason) {
            $locked = SupplierInvoice::where('business_id', $invoice->business_id)
                ->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->approval_status === self::APPROVED) {
                throw new RuntimeException(__('هذا السند معتمدٌ مسبقًا'));
            }
            if (in_array($locked->approval_status, [self::REJECTED, self::CANCELLED], true)) {
                throw new RuntimeException(__('هذا السند مرفوضٌ أو ملغًى — لا يُعتمد'));
            }

            // والمبلغُ يُحسب من طرفيه ثانيةً: عمودٌ عُبث به لا يُرحَّل
            $total = round((float) $locked->subtotal + (float) $locked->tax, 3);

            if (abs($total - (float) $locked->total) > self::TOLERANCE) {
                throw new RuntimeException(__('إجمالي السند لا يساوي مجموعه — لا يُعتمد'));
            }
            if ($total <= 0) {
                throw new RuntimeException(__('السند بلا مبلغ لا يُعتمد'));
            }

            /*
             * والمطابقةُ تُعاد عند الاعتماد لا تُقرأ من عمودٍ كُتب أمس:
             * استلامٌ اعتُمد بين الأمرين يغيّر الحصيلة، ومن يوقّع اليوم
             * يوقّع على حال اليوم.
             */
            $match = self::match($locked);

            if ($match['status'] === self::BLOCKED) {
                $reason = trim((string) $overrideReason);

                if ($reason === '') {
                    throw new RuntimeException(
                        __('السند لا يطابق أمره: :why — اكتب سبب التجاوز إن أردت اعتماده.', [
                            'why' => implode('، ', $match['notes']),
                        ])
                    );
                }

                if (! $by?->may(Permissions::INVOICE_OVERRIDE)) {
                    throw new RuntimeException(__('تجاوزُ عدم المطابقة ليس من صلاحياتك — اطلبه ممّن يملكه.'));
                }

                $locked->update([
                    'override_by' => $by->id,
                    'override_at' => now(),
                    'override_reason' => $reason,
                ]);

                Activity::log('updated', 'تجاوز عدم مطابقة السند '.$locked->supplier_ref.' — '.$reason, [
                    'subject_id' => $locked->id, 'subject_type' => 'supplier_invoice',
                ]);
            }

            /*
             * والضريبةُ تتبع تسجيلَ المتجر، لا قاعدةً واحدةً للاثنين.
             *
             * ═══ ما كان ═══
             *
             * كانت تدخل في تكلفة المخزون دائمًا. وهذا صوابٌ لمن **لم** يُسجَّل
             * في الضريبة: هي عنده جزءٌ من ثمن البضاعة لا شيءٌ يُستردّ.
             *
             * أمّا المسجَّل فيستردّها — وتقريرُ الإقرار يخصمها من ضريبة
             * مخرجاته. فكانت الخمسةُ ريالاتٍ الواحدة تُعدّ مرّتين: أصلًا في
             * المخزون **و** خصمًا في الإقرار. يقرأ التاجر مخزونًا أغلى ممّا
             * كلّفه، وميزانًا لا يعرف الضريبةَ التي سيستردّها.
             *
             * ═══ وإلى أين تذهب ═══
             *
             * إلى «الضريبة المستحقّة» (2300) مدينةً — وهو الحساب الذي تُقيَّد
             * فيه ضريبةُ المبيعات دائنةً. فيصير رصيدُه صافي ما يُدفع للجهة:
             * الرقمُ نفسه الذي يقوله تقريرُ الإقرار. حسابٌ واحد لا يفترق عن
             * تقريره.
             */
            $tax = round((float) $locked->tax, 3);
            $registered = Vat::enabled($locked->business_id);
            $cost = $registered ? round($total - $tax, 3) : $total;

            /* والرصيدُ الافتتاحيُّ ذمّةٌ للمورّد لا بضاعةً دخلت — يُقابَل بالأرباح المحتجزة (المرحلة 9) */
            $split = ['inventory' => 0.0, 'direct' => 0.0, 'kind' => null];
            if ($locked->is_opening_balance) {
                $lines = [['account' => 'retained_earnings', 'debit' => $total, 'memo' => $locked->supplier?->name]];
            } else {
                /*
                 * ماذا اشترى السند؟ — المرحلة 12 §18.
                 *
                 * كان كلُّ سندٍ `Dr مخزون`. وشركةُ الفعاليّات تشتري خدماتٍ في
                 * أكثر أيّامها، فبقيت تكلفتُها أصلًا لا يُستهلك. فيُقرأ أمرُ
                 * الشراء: بنودُ الأصناف المخزونة مخزونٌ، وما سواها مشترياتٌ
                 * مباشرة (5150) — انظر `costSplit`.
                 */
                $split = self::costSplit($locked, $cost);
                $lines = [];
                if ($split['inventory'] > 0.0005) {
                    $lines[] = ['account' => 'inventory', 'debit' => $split['inventory'], 'memo' => $locked->supplier?->name];
                }
                if ($split['direct'] > 0.0005) {
                    $lines[] = ['account' => 'direct_purchases', 'debit' => $split['direct'], 'memo' => $locked->supplier?->name];
                }

                if ($registered && $tax > 0) {
                    $lines[] = ['account' => 'tax_payable', 'debit' => $tax];
                }
            }

            $lines[] = ['account' => 'payable', 'credit' => $total];

            Ledger::post(
                $locked->business_id,
                __('سند مورّد: ').$locked->supplier_ref,
                $lines,
                Carbon::parse($locked->issued_at),
                'سند مورّد',
                null,
                $by?->id,
                $locked,
            );

            $locked->update([
                'approval_status' => self::APPROVED,
                'approved_at' => now(),
                'approved_by' => $by?->id,
                'match_status' => $match['status'],
                'match_notes' => implode("\n", $match['notes']),
                /* ما رُحِّل فعلًا — يُكتب مرّةً ولا يُعاد اشتقاقه */
                'cost_kind' => $split['kind'] ?? $locked->cost_kind,
                'inventory_cost' => $locked->is_opening_balance ? null : $split['inventory'],
                'direct_cost' => $locked->is_opening_balance ? null : $split['direct'],
            ]);

            $locked->syncStatus();

            Activity::log('updated', 'اعتمد سند المورّد '.$locked->supplier_ref.' بقيمة '.$total, [
                'subject_id' => $locked->id, 'subject_type' => 'supplier_invoice',
            ]);

            return $locked->fresh();
        });
    }

    /** الرفض — بسببٍ مكتوب، ولا قيدَ له، ولا يُمحى */
    public static function reject(SupplierInvoice $invoice, string $reason, ?User $by = null): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice, $reason, $by) {
            $locked = SupplierInvoice::where('business_id', $invoice->business_id)
                ->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->approval_status === self::APPROVED) {
                throw new RuntimeException(__('هذا السند معتمَد — قيدُه في الدفتر، ولا يُرفض بعده'));
            }
            if ($locked->approval_status === self::REJECTED) {
                throw new RuntimeException(__('هذا السند مرفوضٌ مسبقًا'));
            }

            $locked->update([
                'approval_status' => self::REJECTED,
                'rejected_at' => now(),
                'rejected_by' => $by?->id,
                'rejection_reason' => $reason,
            ]);

            Activity::log('updated', 'رفض سند المورّد '.$locked->supplier_ref.' — '.$reason, [
                'subject_id' => $locked->id, 'subject_type' => 'supplier_invoice',
            ]);

            return $locked->fresh();
        });
    }

    /**
     * إلغاءُ سندٍ معتمَد — عكسًا لا محوًا.
     *
     * ═══ المعتمَدُ لا يُحذف ═══
     *
     * قيدُه في الدفتر. ومحوُ الصفّ يمحو الذمّة ويترك تكلفةَ المخزون مرحَّلةً
     * بلا ما يقابلها — فيتضخّم المخزون في الميزانية بمبلغٍ لا يقابله دَينٌ
     * ولا نقدٌ خرج، ولا يتوازن شيء.
     *
     * والعكسُ يُبقي الأثر: القيدُ الأصلُ يبقى مقروءًا بتاريخه، وقيدٌ ثانٍ
     * يُلغيه بتاريخ اليوم. فمن يقرأ الدفتر بعد سنةٍ يرى ما وقع ومتى صُحّح —
     * لا فراغًا لا يُشرح.
     *
     * @throws RuntimeException
     */
    public static function cancel(SupplierInvoice $invoice, string $reason, ?User $by = null): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice, $reason, $by) {
            $locked = SupplierInvoice::where('business_id', $invoice->business_id)
                ->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->approval_status !== self::APPROVED) {
                throw new RuntimeException(__('لا يُلغى إلّا سندٌ معتمَد — وغيرُ المعتمَد يُرفض أو يُحذف'));
            }

            /*
             * وسندٌ خرج مقابله مال لا يُلغى من هنا.
             *
             * عكسُ الذمّة وحدَها يترك قيدَ السداد يتيمًا: نقدٌ خرج مقابل دَينٍ
             * لا وجود له. وطريقُه أن يُسترَدّ المال أو يُقيَّد إشعارٌ دائن.
             */
            if ((float) $locked->paid > 0) {
                throw new RuntimeException(__('سُدّد من هذا السند — لا يُلغى بعد أن خرج مقابله مال'));
            }

            foreach (JournalEntry::where('business_id', $locked->business_id)
                ->where('sourceable_type', SupplierInvoice::class)
                ->where('sourceable_id', $locked->id)
                ->where('source', 'سند مورّد')
                ->whereNull('reversed_at')->get() as $entry) {
                Ledger::reverse($entry, null, $by?->id, __('إلغاء سند مورّد: ').$reason);
            }

            $locked->update([
                'approval_status' => self::CANCELLED,
                'rejection_reason' => $reason,
                'rejected_at' => now(),
                'rejected_by' => $by?->id,
            ]);

            Activity::log('updated', 'ألغى سند المورّد '.$locked->supplier_ref.' — '.$reason, [
                'subject_id' => $locked->id, 'subject_type' => 'supplier_invoice',
            ]);

            return $locked->fresh();
        });
    }

    /**
     * قيمةُ ما وصل فعلًا على أمرٍ — من الاستلامات المعتمَدة وحدها.
     *
     * تُقرأ في الشاشة بجوار قيمة السند: من يعتمد يريد الرقمين معًا.
     */
    public static function receivedValue(int $purchaseOrderId): float
    {
        return round((float) GoodsReceiptNote::where('purchase_order_id', $purchaseOrderId)
            ->where('status', GoodsReceipts::APPROVED)
            ->join('goods_receipt_note_items as i', 'i.goods_receipt_note_id', '=', 'goods_receipt_notes.id')
            ->sum(DB::raw('i.quantity * i.cost')), 3);
    }
}
