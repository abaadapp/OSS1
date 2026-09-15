<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * لا متجرَ على الإنترنت يُنشَر إليه.
 *
 * `businesses.site_slug` كان عنوانَ متجر التاجر تحت نطاق المنصّة، و
 * `products.published` مفتاحَ «اعرض هذا الصنف لزوّاره». وعارضُ المتاجر
 * (`storefront/`) حُذف بمشروعه كلِّه، ومعه المسارُ والنطاقُ والقوالب.
 *
 * فبقي مفتاحان في شاشة المنتج يقولان لصاحبه إنّه ينشر شيئًا: يطفئ «عرضه
 * في متجرك على الإنترنت» فيُحفظ العمودُ ولا يتغيّر شيءٌ في أيّ مكان —
 * ومقبضٌ يُدار ولا يُدير أسوأ من غيابه، لأنّه يَعِد.
 */
return new class extends Migration
{
    public function up(): void
    {
        // والقيدُ الفريد يسبق عمودَه — وإلّا رفض SQLite الإسقاط
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropUnique('businesses_site_slug_unique');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('site_slug');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('published');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('site_slug')->nullable()->unique('businesses_site_slug_unique');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('published')->default(true);
        });
    }
};
