<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الباقةُ تقول ممّ تتكوّن.
 *
 * ═══ ولماذا ليست `recipe_items` — وهو سؤالٌ وجيه ═══
 *
 * `recipe_items` تقول حرفيًّا: «هذا المبيع يتكوّن من هذه المبيعات
 * بكميّاتها»، وهو تعريفُ الباقة في الظاهر. وفحصُها يقول غير ذلك:
 *
 * • **حارسُها يمنع الباقةَ من أصلها.** `ProductCompositionController` يردّ
 *   بنصِّه: «هذا الصنف له وصفته الخاصة — المكوّنات أصنافُ مخزونٍ مباشرة لا
 *   باقات»، و`componentOptions` تستبعد كلَّ منتجٍ له وصفة. وباقةُ «مؤتمر
 *   ٥٠٠ شخص» تحوي خدماتٍ وباقاتٍ أصغر — وهو بعينه الممنوع هناك.
 *
 * • **`Recipe::units()` ترفع الكسرَ إلى عددٍ صحيح** لأنّ المخزون أعدادٌ
 *   صحيحة. فبندُ «يومان ونصف من فريقٍ ميدانيّ» يصير ثلاثة. وهو قرارٌ صائبٌ
 *   للرفّ وخاطئٌ للفاتورة.
 *
 * • **`wastage_percent`** — «وردةٌ من كلّ عشرين تُكسر» — بلا معنًى لخدمة،
 *   و**`variant_id`** مقاسُ تجزئة لا يعرفه عرضُ سعرٍ لوزارة.
 *
 * فالوصفةُ **قائمةُ موادّ تُخصم من الرفّ عند البيع**، والباقةُ **حزمةٌ
 * تجاريّة تُسعَّر وتُعرَض**. اسمُهما واحدٌ ومعناهما اثنان، وجمعُهما في
 * جدولٍ يجعل أوّلَ من يضيف قاعدةً لأحدهما يكسر الآخر بصمت.
 *
 * ═══ وما تفعله الباقةُ في عرض السعر ═══
 *
 * تُضاف بندًا واحدًا بسعرها، أو تُفَكّ إلى بنودها فيُسعَّر كلٌّ منها. وفي
 * الحالين تُنسخ البنودُ لقطةً في `quotation_items`: باقةٌ تتغيّر الشهرَ
 * القادم لا تُعيد كتابة عرضٍ خرج.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            /* الباقةُ نفسُها — حذفُها يأخذ بنودَها، فهي لا تعني شيئًا بدونها */
            $table->foreignId('package_id')->constrained('products')->cascadeOnDelete();
            /*
             * والعضوُ يُقيَّد لا يُحذف معه.
             *
             * خدمةٌ داخلةٌ في ثلاث باقاتٍ لا تُمحى بضغطةٍ من شاشة المنتجات —
             * والمنتجاتُ تُحذف حذفًا ناعمًا أصلًا فلا يُستدعى القيدُ في
             * الاستعمال العاديّ. وهو نمطُ `recipe_items.component_product_id`.
             */
            $table->foreignId('item_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 12, 3)->default(1);
            /*
             * ووحدةُ القياس نصٌّ لا قائمةٌ مغلقة.
             *
             * «يوم» و«شخص» و«م²» و«جهاز» — والقائمةُ لا تُحصى في نشاطٍ
             * يبيع ما يُطلب منه. ونصٌّ يكتبه من يعرف عملَه أصدقُ من قائمةٍ
             * يختار منها أقربَ الموجود.
             */
            $table->string('unit', 40)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            /* والعضوُ لا يتكرّر في الباقة: كميّتان لشيءٍ واحد لا يُعرف أيّهما */
            $table->unique(['package_id', 'item_id']);
            $table->index(['business_id', 'package_id']);
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_items');
    }
};
