<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أمرُ شراءٍ استُلم كلُّه وفوتره المورّد بأقلّ — الباقي ليس التزامًا.
 *
 * كان رصيدُ الأمر بعد سنداته يُقرأ «ملتزَمًا» إلى الأبد، فيمنع إقفالَ
 * المشروع عن فرقٍ لن يأتي سندٌ به. ولا يُمحى الرقمُ ولا يُعدَّل الأمر:
 * يُقفَل بيد من يملك ذلك وبسببٍ مكتوب، ويبقى كما كُتب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('received_at');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            $table->string('closing_note', 255)->nullable()->after('closed_by');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['closed_at', 'closing_note']);
        });
    }
};
