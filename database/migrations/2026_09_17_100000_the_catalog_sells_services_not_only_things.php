<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الكتالوجُ يبيع خدماتٍ لا أصنافًا وحدها.
 *
 * ═══ ولماذا عمودٌ لا نموذجٌ ثانٍ ═══
 *
 * `products` يحمل اليوم: الاسمَ والوصفَ والرمزَ والسعرَ والتكلفةَ والضريبةَ
 * والخصمَ والصورةَ والتصنيف — و**كلُّها صحيحةٌ لخدمة**. ولا يخرج عنها إلّا
 * ثلاثة: `quantity` و`alert_qty` و`barcode`. فخدمةٌ في جدولٍ ثانٍ تعني
 * تكرارَ ثلاثةَ عشرَ عمودًا وعلاقتين وشاشةً وحارسَ عزلٍ ومسارَ تصدير —
 * ليُكسَب إخفاءُ ثلاثة أعمدة.
 *
 * وأسوأُ منه أثرُه على من يقرأ: بندُ عرضٍ يشير إلى «منتج أو خدمة» يحتاج
 * عمودين متعدّدي الأشكال بدل مفتاحٍ واحد، وكلُّ استعلامٍ يجمع ما بيع يصير
 * اتّحادَ جدولين.
 *
 *   item     — عتادٌ يُشترى ويُستهلك: بطاقاتٌ ولانياردُ وأحبار. كميّتُه تُتابَع.
 *   service  — عملٌ يُباع بالوقت أو بالمهمّة: تشغيلُ تسجيلٍ وفريقٌ ميدانيّ. لا رفَّ له.
 *   package  — باقةٌ تحوي بنودًا — انظر `package_items`.
 *
 * ═══ و«item» افتراضًا: لا صفَّ قائمٍ يتغيّر ═══
 *
 * كلُّ ما في الكتالوج اليوم صنفٌ ماديّ، والافتراضُ يقوله بلا هجرةِ بيانات.
 * وما يُضاف بعد اليوم يختار صاحبُه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('kind', 20)->default('item')->after('category_id');
        });

        /*
         * والفهرسُ مركّبٌ على الشركة أوّلًا.
         *
         * لا يُسأل «أين kind = service» في النظام كلّه؛ يُسأل «خدماتُ هذه
         * الشركة» — وفهرسٌ على العمود وحده يمرّ على صفوف كلّ الشركات ثمّ
         * يصفّي. وهو نمطُ `products` نفسِه في بقيّة فهارسه.
         */
        Schema::table('products', function (Blueprint $table) {
            $table->index(['business_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'kind']);
            $table->dropColumn('kind');
        });
    }
};
