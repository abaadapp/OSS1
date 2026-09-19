<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * كلُّ رسالةٍ إلى عميلٍ في السجلّ — المرحلة 6.
 *
 * ═══ ثلاثةُ جداول ═══
 *
 * `communications` — الرسالةُ نفسُها: من الشركة إلى من، عبر أيّ قناة، بأيّ
 * قالب، عن أيّ مستند، ومتى، وماذا قال المزوّد عنها. صفٌّ واحدٌ لكلّ محاولةِ
 * إرسال؛ والإعادةُ صفٌّ جديد يشير إلى الأصل (`retry_of_id`) — سلسلةٌ تُقرأ لا
 * صفٌّ يُعاد كتابتُه.
 *
 * `communication_events` — ما قاله المزوّد بعد الإرسال: وصلت، قُرئت، ارتدّت،
 * فشلت. وكلُّ خبرٍ له معرّفٌ عند المزوّد، والقيدُ الفريد عليه هو ما يجعل
 * الـwebhook المكرَّر بلا أثر — انظر `Communications::applyEvent`.
 *
 * `communication_templates` — ما تملكه الشركةُ من قوالب فوق الكتالوج المكتوب
 * في الكود: اسمُ قالب واتساب المعتمَد عند Meta، ولغتُه، وحالُه، وما تختاره
 * من متغيّرات. والبريدُ قالبُه في الكود (`DocumentMail`)، ويُسمّى هنا ليُعرض
 * ويُعطَّل — لا ليُعاد بناؤه.
 *
 * ═══ ولا سرَّ فيها ═══
 *
 * لا رمزَ وصول، ولا مفتاحَ مزوّد، ولا نصَّ رسالةٍ كاملًا: العنوانُ والقالبُ
 * والمتغيّرات تكفي لمعرفة ما أُرسل، وما يُخزَّن من ردّ المزوّد يُنقّى من كلّ
 * ما ليس خبرًا عن الرسالة — انظر `Communications::sanitize`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            /*
             * والمشروعُ عمودٌ خاصّ فوق الموضوع المتعدّد الأشكال: رسالةٌ عن
             * حزمة تسليمٍ أو أمرِ تغييرٍ أو فاتورةٍ تتبع مشروعًا، وشاشةُ
             * المشروع تسأل «كلُّ ما قيل للعميل في هذا المشروع» بسؤالٍ واحد.
             */
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('channel', 20);
            $table->string('direction', 10)->default('outbound');
            /* المُرسَل إليه مطبَّعًا: بريدٌ بحروفٍ صغيرة، أو رقمٌ دوليٌّ بلا رموز */
            $table->string('recipient', 190);
            $table->string('recipient_name', 190)->nullable();
            $table->string('template_key', 80);
            $table->string('title');
            $table->string('language', 5)->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('provider_message_id', 190)->nullable();
            $table->string('status', 20)->default('queued');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            /* والاسمُ يبقى بعد حذف الحساب — والنظامُ فاعلٌ حين لا موظّف */
            $table->string('initiated_by_name', 190)->nullable();
            $table->foreignId('retry_of_id')->nullable()->constrained('communications')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'channel', 'status']);
            $table->index(['business_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            /*
             * ومعرّفُ المزوّد فريدٌ عنده — فيُجعل فريدًا عندنا: الـwebhook يجد
             * صفًّا واحدًا أو لا شيء، ولا يكتب خبرًا على صفّين.
             */
            $table->unique(['provider', 'provider_message_id']);
        });

        Schema::create('communication_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('communication_id')->constrained('communications')->cascadeOnDelete();
            $table->string('provider', 40);
            /* معرّفُ الخبر عند المزوّد — أو مركَّبٌ من رسالته وحاله ووقته */
            $table->string('event_id', 190)->nullable();
            $table->string('kind', 30);
            $table->string('status', 20)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->string('code', 80)->nullable();
            $table->text('description')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
            $table->index(['communication_id', 'occurred_at']);
        });

        Schema::create('communication_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('key', 80);
            $table->string('channel', 20);
            $table->string('name', 120);
            $table->string('purpose', 40);
            $table->string('language', 5)->default('ar');
            $table->string('status', 20)->default('active');
            /* اسمُ القالب كما اعتمدته Meta — ورمزُ لغته عندها (`ar` · `en_US`) */
            $table->string('provider_template', 190)->nullable();
            $table->string('provider_language', 10)->nullable();
            /* المتغيّراتُ بترتيبها — تُملأ في {{1}} {{2}} … بهذا الترتيب */
            $table->json('variables')->nullable();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'key', 'channel', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_templates');
        Schema::dropIfExists('communication_events');
        Schema::dropIfExists('communications');
    }
};
