<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * حزمةُ التسليم تتبع مشروعَها — والقيدُ الآن بعد أن استقرّت جداولُ المرحلة 4.
 *
 * بُنيت حزمُ التسليم بالتوازي مع المشاريع في شجرةٍ واحدة، فتُرك عمودا
 * `project_id` و`project_item_id` بلا `FOREIGN KEY` عمدًا (انظر هجرتَها).
 * وهذه تُقيّدهما، وتضيف إلى المشروع ما يحتاجه الإلغاءُ المسيَّس: فاتورةُ
 * رسم الإلغاء (مرّةً — العمودُ نفسُه هو حارسُ التكرار) ولقطةُ حكم السياسة.
 *
 * ═══ إضافيّةٌ وآمنة ═══
 *
 * العمودان جديدان وفارغان في كلّ صفٍّ قائم، فالقيدُ لا يردّ شيئًا. وقبل
 * كتابته يُصفَّر ما لا يشير إلى صفٍّ حيّ — احتياطًا لا توقّعًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* وما لا يشير إلى صفٍّ حيّ يُصفَّر — قيدٌ يُضاف على بياناتٍ لا تُرضيه يسقط كلَّ الهجرة */
        DB::table('delivery_packages')->whereNotNull('project_id')
            ->whereNotIn('project_id', DB::table('projects')->select('id'))->update(['project_id' => null]);
        DB::table('delivery_package_items')->whereNotNull('project_item_id')
            ->whereNotIn('project_item_id', DB::table('project_items')->select('id'))->update(['project_item_id' => null]);

        Schema::table('delivery_packages', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
        });

        Schema::table('delivery_package_items', function (Blueprint $table) {
            $table->foreign('project_item_id')->references('id')->on('project_items')->nullOnDelete();
        });

        Schema::table('projects', function (Blueprint $table) {
            /* رسمُ الإلغاء — فاتورةٌ واحدة؛ والعمودُ هو القيد: لا تُصدَر ثانيةٌ ما دام مملوءًا */
            $table->foreignId('cancellation_fee_invoice_id')->nullable()->after('cancellation_reason')
                ->constrained('customer_invoices')->nullOnDelete();
            /* حكمُ سياسة الإلغاء لحظةَ الطلب — يُقرأ بعد سنةٍ كما كان، ولو تغيّرت السياسة */
            $table->json('cancellation_policy')->nullable()->after('cancellation_fee_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancellation_fee_invoice_id');
            $table->dropColumn('cancellation_policy');
        });

        Schema::table('delivery_package_items', function (Blueprint $table) {
            $table->dropForeign(['project_item_id']);
        });

        Schema::table('delivery_packages', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });
    }
};
