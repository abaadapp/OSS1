<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * لا صندوقَ ولا ورديّاتٍ ولا سلّةً معلّقة.
 *
 * ثلاثةُ آثارٍ خلّفتها نقطةُ البيع بعد إزالتها:
 *
 * ١) `shifts` و`shift_movements` — دفترُ الدرج: يُفتح ويُقفل ويُعدّ ما فيه.
 *    لا مسار ولا شاشة ولا نموذجٌ يقرؤهما منذ حُذف الصندوق، وقد قال ذلك
 *    تعليقٌ في `OrderCorrection` صراحةً: «الورديّات لا وجود لها في هذا
 *    النظام».
 *
 * ٢) `orders.shift_id` — الوردية التي وقعت فيها البيعة. لا يكتبها شيء.
 *
 * ٣) `orders.is_held` — السلّةُ المعلَّقة: زبونٌ يتركها على الشاشة ويعود.
 *    لا زرَّ يُعلّق بعد اليوم، فبقي العمودُ يُقرأ في تسعةَ عشرَ موضعًا
 *    بشرطٍ لا يُسقط صفًّا واحدًا — `where('is_held', false)` على جدولٍ
 *    كلُّ صفوفه صفر.
 *
 * ولا بيانات تضيع: الجدولان فارغان في كلّ قاعدةٍ لم تعمل عليها نقطةُ بيع،
 * و`is_held` صفرٌ في كلّ صفّ. ومن كان له تاريخُ ورديّاتٍ فعليّ فليأخذ نسخةً
 * قبل الترحيل — هذه هجرةٌ لا تُعيد ما تُسقطه.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * والفهرسُ يسبق عمودَه.
         *
         * SQLite يرفض إسقاط عمودٍ عليه فهرس ويقول «no such column» عن
         * العمود الذي طُلب إسقاطُه — رسالةٌ تقود إلى غير موضع العطب.
         */
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_shift_id_index');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shift_id', 'is_held']);
        });

        Schema::dropIfExists('shift_movements');
        Schema::dropIfExists('shifts');
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('shift_id')->nullable()->index('orders_shift_id_index');
            $table->boolean('is_held')->default(false);
        });

        /*
         * والجدولان لا يُعادان: بنيتُهما تخصّ صندوقًا لم يعد في النظام، وردُّ
         * هيكلٍ فارغٍ لا يردّ ما كان فيه. ومن أراد استعادتهما فمن نسخته.
         */
    }
};
