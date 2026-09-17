<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * العرضُ يحمل شروطَه المختومة، ويعرف إلى من ذهب.
 *
 * ═══ `policy_snapshot` — وهو ما رُفض في المرحلة 2 باسمٍ آخر ═══
 *
 * رُفض `terms_snapshot` هناك لأنّ الشروطَ كانت في أعمدة الصفّ نفسِه
 * (`scope` · `terms` · `notes`) مجمَّدةً بمنع التعديل بعد الإرسال — ونسخةٌ
 * ثانيةٌ منها مصدرٌ ثانٍ للحقيقة نفسِها.
 *
 * وسياساتُ الشركة **خارج الصفّ**: تعيش في `company_policies` وتُعدَّل بعد
 * أن يخرج العرضُ إلى عميله. فما رآه العميلُ يومَها لا يقوله جدولٌ حيٌّ
 * بعد شهر — يقوله ختمٌ أُخذ لحظةَ الخروج. فهذه ليست نسخةً من الحقيقة، هي
 * الحقيقةُ الوحيدةُ عمّا خرج. وتدخل في `content_hash` مع البنود.
 *
 * ═══ `sent_to` ═══
 *
 * البريدُ الذي ذهب إليه العرضُ أوّلَ مرّة. «عرضٌ أُرسل ← إلى أيّ بريد»
 * من الأثر الحسّاس في §24، ويُقرأ بعد شهرٍ حين لا يردّ العميل: «هل
 * أرسلناه أصلًا، وإلى من؟». ولا `emailed_at`: وقتُ كلّ إرسالٍ في سجلّ
 * النشاط، وأوّلُه وحدَه ما يحتاجه الصفّ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->json('policy_snapshot')->nullable()->after('document_snapshot');
            $table->string('sent_to', 120)->nullable()->after('sent_by');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['policy_snapshot', 'sent_to']);
        });
    }
};
