<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الشركةُ تحفظ مستنداتها — وتسمع قبل أن تنتهي.
 *
 * ═══ غيرُ أوراق العملاء ═══
 *
 * `document_links` و`document_approvals` لأوراقٍ تخرج إلى العميل ليقرأها ويوقّعها.
 * وهذه مستنداتٌ داخليّة: سجلٌّ تجاريّ، شهادةُ ضريبة، تأمين، عقد، تصريح، رخصة،
 * مستندُ موظّفٍ أو مورّدٍ أو مشروعٍ أو فعاليّة.
 *
 * ═══ ما يُحفظ ═══
 *
 *  • `document_categories` — تصنيفاتٌ تُدار من الشاشة.
 *  • `document_records` — المستند: عنوانُه ورقمُه ومُصدِرُه وتاريخا إصداره وانتهائه
 *    والمسؤولُ عنه وملاحظاتُه وملفُّه الحاليّ. والحالُ (نشط · قريب الانتهاء · منتهٍ)
 *    تُحسب من التاريخ لا تُخزَّن فتتعفّن؛ والأرشفةُ وحدها تُخزَّن (`archived_at`).
 *    وربطُه اختياريّ بـ`documentable_type` (مفتاحٌ قصيرٌ من قائمةٍ مغلقة لا اسمُ صنف)
 *    و`documentable_id` — والشركةُ نفسُها حين لا ربط.
 *  • `document_record_files` — كلُّ نسخةٍ رُفعت: لا يُمحى الملفُّ القديم حين تأتي نسخةٌ
 *    جديدة، فيبقى ما كان يثبته.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['business_id', 'name']);
        });

        Schema::create('document_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_category_id')->nullable()->constrained('document_categories')->nullOnDelete();
            $table->string('title', 200);
            $table->string('document_number', 120)->nullable();
            $table->string('issuer', 160)->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('file_path', 500)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->string('file_mime', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('notes')->nullable();
            $table->string('documentable_type', 40)->nullable();
            $table->unsignedBigInteger('documentable_id')->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['business_id', 'archived_at', 'expires_at']);
            $table->index(['documentable_type', 'documentable_id']);
        });

        Schema::create('document_record_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_record_id')->constrained('document_records')->cascadeOnDelete();
            $table->string('path', 500);
            $table->string('name', 255);
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['business_id', 'document_record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_record_files');
        Schema::dropIfExists('document_records');
        Schema::dropIfExists('document_categories');
    }
};
