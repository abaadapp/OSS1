<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * العميلُ يردّ على واتساب — والشركةُ تقرأ وتجيب.
 *
 * ═══ جدولان لا عمودٌ في المراسلات ═══
 *
 * `communications` سجلُّ ما أرسلته الشركةُ بقالبٍ عن مستند — ولا نصَّ فيه
 * عمدًا. والمحادثةُ نصٌّ حرٌّ في الاتجاهين، فلها جدولاها: المحادثةُ رقمٌ
 * واحدٌ في شركةٍ واحدة، والرسالةُ صفٌّ تحتها. ورسالةُ القالب التي خرجت من
 * مستندٍ تظهر في المحادثة أيضًا — صفٌّ يشير إلى قيدها (`communication_id`)
 * ولا يُكرّره.
 *
 * ═══ ونافذةُ الأربع والعشرين ساعة ═══
 *
 * Meta لا تقبل نصًّا حرًّا إلّا في يومٍ من آخر رسالةٍ كتبها العميل — فآخرُ
 * واردٍ عمودٌ في المحادثة (`last_inbound_at`) لا سؤالٌ يُحسب في كلّ فتح.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            /* رقمُ العميل دوليًّا بلا رموز — كما تكتبه Meta في `wa_id` */
            $table->string('wa_id', 20);
            /* الاسمُ كما سمّى العميلُ نفسَه في واتساب */
            $table->string('contact_name', 190)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->string('last_preview', 190)->nullable();
            $table->string('last_direction', 10)->nullable();
            $table->string('last_status', 20)->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'wa_id']);
            $table->index(['business_id', 'last_message_at']);
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            /* رسالةُ قالبٍ خرجت من مستند — قيدُها في المراسلات */
            $table->foreignId('communication_id')->nullable()->constrained('communications')->nullOnDelete();
            $table->string('direction', 10);
            /* text · image · video · audio · document · sticker · location · template · … */
            $table->string('type', 20)->default('text');
            $table->text('body')->nullable();
            $table->string('media_id', 190)->nullable();
            $table->string('media_mime', 120)->nullable();
            $table->string('media_filename', 190)->nullable();
            /* نسخةُ الملفّ على القرص بعد أوّل فتح — Meta تمحو روابطها بعد أيّام */
            $table->string('media_path')->nullable();
            $table->string('provider_message_id', 190)->nullable();
            $table->string('status', 20);
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sent_by_name', 190)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
            /* والواردُ المكرَّر من Meta يُردّ هنا — لا صفّين لرسالةٍ واحدة */
            $table->unique(['business_id', 'provider_message_id']);
            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_conversations');
    }
};
