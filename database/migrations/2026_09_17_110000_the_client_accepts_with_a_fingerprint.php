<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قبولُ العميل — سطرٌ يُكتب مرّةً ولا يُعدَّل.
 *
 * ═══ ولمَ جدولٌ مستقلّ لا أعمدةٌ في العرض ═══
 *
 * العرضُ فيه `decided_at` و`decided_by_name` منذ المرحلة 2 — وهما يكفيان
 * لقرارٍ **سجّله موظّفٌ** بعد مكالمة. أمّا قرارٌ ضغطه العميلُ بنفسه من
 * رابطه فهو **دليل** يُحتجّ به بعد سنة، والدليلُ يحتاج ما لا يحتاجه
 * الخبر: أيَّ نصٍّ بعينه قَبِل (البصمة واللقطة)، ومن أين (العنوان
 * والمتصفّح)، وبأيّ إقرار (نصُّ الموافقة)، ومن هو (الاسمُ والصفةُ
 * والبريد).
 *
 * وجدولٌ متعدّدُ الأشكال لا عمودٌ في `quotations`: حزمةُ التسليم في
 * المرحلة 5 تُقبل بالآليّة نفسِها، والدليلُ شكلٌ واحدٌ لكلّ ما يُقبل.
 *
 * ═══ ولا `state` فيه ═══
 *
 * «ينتظر» و«فُتح» و«انتهى» تُقرأ من العرض نفسِه (`sent_at` ·
 * `first_viewed_at` · `valid_until`)، والصفُّ هنا لا يُكتب إلّا لحظةَ
 * القرار — فحالُه واحدةٌ دائمًا: قُرّر. وعمودٌ لا يحمل إلّا قيمةً واحدة
 * ليس عمودًا.
 *
 * ═══ ولا `document_link_id` ═══
 *
 * رموزُ الروابط **خارج النسخة الاحتياطيّة** عمدًا (`TenantTables::NOT_MINE`):
 * من ملكها فتح المستندَ بلا حساب. ومفتاحٌ خارجيٌّ إليها يجعل الاستعادةَ
 * تسقط على صفٍّ لا يعود. والرابطُ واحدٌ لكلّ مستندٍ أصلًا، فلا يضيف
 * المعرّفُ شيئًا لا يقوله `approvable_id`.
 *
 * ═══ واللقطةُ هي ما بُصم ═══
 *
 * `accepted_snapshot` الحمولةُ نفسُها التي حُسب منها `content_hash` — لا
 * صورةٌ ثانيةٌ للورقة. فمن شكّ بعد سنةٍ أعاد الحسابَ على اللقطة وقارن.
 * والدليلُ الذي لا يُعاد فحصُه شهادةٌ لا دليل.
 *
 * ═══ و`provider` منذ اليوم ═══
 *
 * `internal` اليوم، وعميلٌ يشترط توقيعًا معتمَدًا غدًا يُخدَم بمزوّدٍ
 * خارجيّ يكتب `external_id` في العمود نفسِه — ولا يمسّ نموذجَ البيانات.
 * انظر §19 من وثيقة المعماريّة: هذا **قبولٌ إلكترونيٌّ موثَّق** لا توقيعٌ
 * معتمَدٌ قانونًا، ويكفي بين شركةٍ وعميلها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            /* Quotation اليوم — وDeliveryPackage في المرحلة 5 */
            $table->morphs('approvable');

            $table->string('provider', 20)->default('internal');
            $table->string('external_id', 120)->nullable();

            /* accepted | declined */
            $table->string('decision', 20);
            $table->char('content_hash', 64);
            $table->json('accepted_snapshot');

            /* الموقّع — كما كتب نفسَه، لا كما نعرفه في دفتر العملاء */
            $table->string('signer_name', 120);
            $table->string('signer_title', 120)->nullable();
            $table->string('signer_email', 120)->nullable();
            $table->string('signer_phone', 40)->nullable();
            /* ونصُّ الإقرار الذي وافق عليه — بحرفه، لا مفتاحُه */
            $table->text('consent_text')->nullable();
            $table->text('decline_reason')->nullable();

            $table->dateTime('decided_at');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamps();

            /*
             * وقرارٌ واحدٌ لكلّ مستند — بقيدٍ لا بشرطٍ في الكود.
             *
             * ضغطتان على «أقبل» — أو ردٌّ يبطؤ فيُعاد الطلب — تمرّان معًا
             * على «هل قُرّر؟» فتجيب كلتاهما «لا». والقيدُ يردّ الثانية.
             */
            $table->unique(['approvable_type', 'approvable_id']);
            $table->index(['business_id', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_approvals');
    }
};
