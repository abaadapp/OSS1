<?php

namespace App\Support;

use App\Models\Business;
use Illuminate\Support\Facades\DB;

/**
 * تسلسلُ أرقام المستندات — تحت قفلٍ على صفّ الشركة.
 *
 * وبلا قفل: نافذتان تُصدران في اللحظة نفسها تقرآن آخر رقمٍ فتكتبانه
 * كلتاهما، فيسقط أحدهما على فهرس التفرّد أو — وهو أسوأ — يمرّان على
 * قاعدةٍ بلا فهرس فيصير رقمان لورقتين.
 *
 * ═══ ولمَ خرج من `CustomerInvoices` ═══
 *
 * صار لعرض السعر تسلسلُه. ونسخُ الدالّة يعني نسخَ القفل ونسخَ قاعدة
 * «الأكبرُ عددًا لا الأحدثُ صفًّا» — وأوّلُ نسخةٍ تُكتب بلا قفلٍ تُخرج
 * رقمين لورقتين في يومٍ مزدحم، ولا يظهر ذلك إلّا حين يتّصل عميلان يذكران
 * الرقمَ نفسَه.
 *
 * ═══ وفي المرحلة 12 صار البابَ الوحيد ═══
 *
 * قيودُ اليوميّة وإشعاراتُ الاستلام وسنداتُ التسليم ومسيراتُ الرواتب كانت
 * تقرأ آخرَ رقمٍ بلا قفل، وفهرسُها فريد: دفعتان في اللحظة نفسها تسقط
 * إحداهما بخطأ قاعدةٍ لا يفهمه أحد. فكلُّ تسلسلٍ له فهرسُ تفرّدٍ يمرّ من هنا.
 */
final class Numbering
{
    /**
     * الرقمُ التالي لهذا النوع في هذه الشركة.
     *
     * ═══ والأكبرُ عددًا لا الأحدثُ صفًّا ═══
     *
     * الرقمُ يُقطع عند الإصدار لا عند الإنشاء، فمسودّةٌ كُتبت أمس تُصدَر
     * اليوم بعد واحدةٍ كُتبت صباحًا — وآخرُ صفٍّ ليس أكبرَ رقم، وقراءتُه
     * تُعيد رقمًا مستعمَلًا يردّه فهرسُ التفرّد في وجه من ضغط «إصدار».
     *
     * ═══ والقراءةُ في PHP لا `CAST` في SQL ═══
     *
     * نصٌّ غير رقميّ يتساهل معه SQLite ويرفضه PostgreSQL، فرقمٌ شاذٌّ واحد
     * من نسخةٍ مستعادة يُعطّل الإصدار كلَّه بعد النقل.
     *
     * @param  class-string  $model  النموذجُ الذي يحمل العمود `number`
     */
    public static function next(int $businessId, string $model, string $prefix, int $pad = 6, string $column = 'number'): string
    {
        return DB::transaction(function () use ($businessId, $model, $prefix, $pad, $column) {
            Business::whereKey($businessId)->lockForUpdate()->first();

            $width = strlen($prefix) + $pad;
            $top = (string) $model::where('business_id', $businessId)
                ->where($column, 'like', $prefix.'%')
                ->orderByDesc($column)->value($column);
            $digits = substr($top, strlen($prefix));

            /*
             * الطريقُ السريع: الأرقامُ بعرضٍ ثابتٍ مبطَّنةٍ بالأصفار، فأعلاها
             * نصًّا هو أعلاها عددًا — ويُقرأ من الفهرس بلا مسحٍ للجدول. وقيدُ
             * اليوميّة يُكتب مع كلّ دفعةٍ وكلّ صرف؛ فمسحُ مئة ألف رقمٍ في كلّ
             * مرّةٍ ليس ثمنًا يُدفع.
             *
             * ويُترك الطريقُ حين لا يصلح: رقمٌ شاذٌّ في القمّة، أو رقمٌ تجاوز
             * عرضَه (JV-1000000 يسبق JV-999999 نصًّا) — فيُمسح كما كان.
             */
            if ($top !== '' && ctype_digit($digits) && strlen($digits) === $pad
                && ! $model::where('business_id', $businessId)->where($column, 'like', $prefix.'%')
                    ->whereRaw('LENGTH('.$column.') > ?', [$width])->exists()) {
                $highest = (int) $digits;
            } else {
                $highest = (int) $model::where('business_id', $businessId)
                    ->where($column, 'like', $prefix.'%')
                    ->get([$column])
                    ->map(fn ($r) => (int) substr((string) $r->{$column}, strlen($prefix)))
                    ->max();
            }

            return $prefix.str_pad((string) ($highest + 1), $pad, '0', STR_PAD_LEFT);
        });
    }
}
