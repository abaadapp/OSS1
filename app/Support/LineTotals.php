<?php

namespace App\Support;

use App\Models\Setting;

/**
 * حسابُ بنود المستندات — في الخادم دائمًا، ومن موضعٍ واحد.
 *
 * ═══ ولمَ خرج من `CustomerInvoices` ═══
 *
 * كان هنا حسابُ الفاتورة وحدها. ولمّا جاء عرضُ السعر كان أمامه طريقان:
 * ينسخ الحسابَ أو يُشاركه. والنسخُ يعني أنّ قاعدةً تُصحَّح في أحدهما —
 * قصُّ الخصم عند الإجمالي، أو تقريبُ الضريبة، أو بندٌ معفًى بنسبة صفر —
 * لا تبلغ الآخر. فيُعرض على العميل مبلغٌ ثمّ تُصدَر له فاتورةٌ بغيره، ولا
 * سطرَ يقول لماذا.
 *
 * ولا يُقرأ إجماليٌّ من الواجهة: من يفتح أدوات المتصفّح يستطيع إرسال
 * إجماليٍّ صفرٍ لورقةٍ بمئة.
 *
 * ═══ والضريبةُ لقطة ═══
 *
 * النسبةُ تُقرأ من إعدادات الشركة لحظةَ الكتابة وتُحفظ في السطر. وتغييرُها
 * الشهرَ القادم لا يمسّ ورقةً خرجت.
 */
final class LineTotals
{
    /**
     * نسبةُ الضريبة لشركةٍ بعينها — لا لشركة القارئ.
     *
     * `Demo::vatSettings()` تقرأ شركةَ الجلسة — فلو حُسبت ورقةٌ لشركةٍ أخرى
     * (أمرُ صيانة، أو مهمّةٌ مجدولة) لحُسبت بنسبة شركةٍ غيرها.
     */
    public static function rate(int $businessId): float
    {
        return Vat::enabled($businessId)
            ? (float) (Setting::where('business_id', $businessId)->where('key', 'vat_rate')->value('value') ?? 5)
            : 0.0;
    }

    /**
     * البنودُ محسوبةً، والإجماليّاتُ فوقها.
     *
     * ═══ والخصمُ المحتسَب هو المطبَّق، لا المطلوب ═══
     *
     * البندُ يُقصَر على صفرٍ وعلى إجماليّه، ويُجمَع في الرأس **ما وقع
     * فعلًا** لا ما طُلب. وبغير ذلك يُخرج بندٌ بعشرةٍ وخصمٌ بخمسةٍ وعشرين
     * سطرًا مجموعُه صفرٌ ورأسًا سالبًا بخمسةَ عشر — ورقةٌ بنودُها لا تجمع
     * إلى مجموعها. والخصمُ السالب أسوأ: يُنقص من الخصم فيضخّم الإجماليَّ
     * بلا سطرٍ يقابله.
     *
     * فتصير `subtotal − discount + tax` مساويةً لمجموع البنود دائمًا، وهي
     * الخاصّيّةُ التي تُراجَع بها الورقة.
     *
     * ═══ و`gross_unit` لمن له سعران ═══
     *
     * فاتورةُ العميل لها سعرٌ واحد وخصمٌ يُكتب فوقه. وعرضُ السعر له سعرُ
     * كتالوجٍ وسعرٌ معروض، والخصمُ **الفرقُ بينهما** لا رقمٌ ثالث. فمن
     * أرسل `gross_unit` قال «احسب المجموعَ الفرعيَّ بهذا، والمعروضُ ما في
     * `unit_price`». وبغيابه يتساويان — وهو حالُ الفاتورة كما كان.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  list<string>  $carry  مفاتيحُ تُنقَل كما وردت إلى السطر المحسوب
     * @return array{items: array<int, array<string, mixed>>, subtotal: float, discount: float, tax: float, total: float}
     */
    public static function compute(int $businessId, array $lines, ?float $taxRate = null, array $carry = ['product_id']): array
    {
        $rate = $taxRate ?? self::rate($businessId);

        $items = [];
        $subtotal = 0.0;
        $discount = 0.0;
        $tax = 0.0;
        $sort = 0;

        foreach ($lines as $line) {
            $qty = round((float) ($line['quantity'] ?? 1), 3);
            $price = round((float) ($line['unit_price'] ?? 0), 3);
            $grossUnit = round((float) ($line['gross_unit'] ?? $price), 3);
            $lineDiscount = round((float) ($line['discount'] ?? 0), 3);

            if ($qty <= 0) {
                continue;
            }

            $gross = round($qty * $grossUnit, 3);
            $lineDiscount = round(min(max(0.0, $lineDiscount), $gross), 3);
            $net = round($gross - $lineDiscount, 3);
            // بندٌ معفًى يُكتب بنسبة صفر — والإعفاء لقطةٌ في السطر لا قاعدةٌ عامّة
            $lineRate = array_key_exists('tax_rate', $line) ? (float) $line['tax_rate'] : $rate;
            $lineTax = round($net * $lineRate / 100, 3);

            $subtotal = round($subtotal + $gross, 3);
            $discount = round($discount + $lineDiscount, 3);
            $tax = round($tax + $lineTax, 3);

            $row = [
                'description' => (string) ($line['description'] ?? ''),
                'quantity' => $qty,
                'unit_price' => $price,
                'discount' => $lineDiscount,
                'tax_rate' => $lineRate,
                'tax_amount' => $lineTax,
                'line_total' => round($net + $lineTax, 3),
                'sort_order' => $sort++,
            ];

            foreach ($carry as $key) {
                $row[$key] = $line[$key] ?? null;
            }

            $items[] = $row;
        }

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'total' => round($subtotal - $discount + $tax, 3),
        ];
    }
}
