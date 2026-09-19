<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أثرُ الاستيراد يحفظ المعرِّفَ القديم — المرحلة 9.
 *
 * صفُّ الفاتورة القديمة يقول «العميل C-17»، و«C-17» ليس اسمًا ولا هاتفًا:
 * هو معرِّفُ النظام القديم الذي دخل به العميلُ في مجموعةٍ سابقة. فيُحفظ على
 * الأثر (`import_links.external_id`) ويُفهرَس، فتجده الفاتورةُ بلا تخمين.
 *
 * وفي هجرةٍ مستقلّة لأنّ الجدولَ خرج في هجرةٍ سبقت: قاعدةٌ هاجرت قبلها لا
 * تُعاد، وتُسأل عن العمود قبل أن يُضاف.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('import_links', 'external_id')) {
            return;
        }

        Schema::table('import_links', function (Blueprint $table) {
            $table->string('external_id', 64)->nullable()->after('source_ref');
            $table->index(['business_id', 'linkable_type', 'external_id'], 'import_links_external');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('import_links', 'external_id')) {
            return;
        }

        Schema::table('import_links', function (Blueprint $table) {
            $table->dropIndex('import_links_external');
            $table->dropColumn('external_id');
        });
    }
};
