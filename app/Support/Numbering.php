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
    public static function next(int $businessId, string $model, string $prefix): string
    {
        return DB::transaction(function () use ($businessId, $model, $prefix) {
            Business::whereKey($businessId)->lockForUpdate()->first();

            $highest = (int) $model::where('business_id', $businessId)
                ->where('number', 'like', $prefix.'%')
                ->get(['number'])
                ->map(fn ($r) => (int) substr((string) $r->number, strlen($prefix)))
                ->max();

            return $prefix.str_pad((string) ($highest + 1), 6, '0', STR_PAD_LEFT);
        });
    }
}
