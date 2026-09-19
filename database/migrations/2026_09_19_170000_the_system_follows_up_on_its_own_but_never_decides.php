<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * النظامُ يتابع بنفسه — ولا يقرّر بنفسه. المرحلة 8.
 *
 * ═══ ثلاثةُ جداول ═══
 *
 * `automation_rules` — ما قرّرته الشركةُ أن يُتابَع وحده: المُطلِق (فاتورةٌ
 * تستحقّ، عرضٌ ينتظر، مشروعٌ يبدأ…)، وخطواتُه (بعد كم يومًا وإلى من)،
 * وقناتُه وقالبُه من المرحلة 6، وفترةُ الهدوء، وهل يُقيَّد بساعات العمل.
 * ولا كودَ فيها: كلُّ حقلٍ قيمةٌ من قائمةٍ مغلقة يقرؤها المحرّك.
 *
 * `automation_runs` — كلُّ محاولةٍ للمحرّك على هدفٍ في خطوة: ماذا وجد،
 * وماذا قرّر، وماذا فعل، ولماذا لم يفعل. و`idempotency_key` فريدٌ: القاعدةُ
 * هي التي تمنع أن تُرسَل الرسالةُ نفسُها مرّتين — لا القفلُ ولا الذاكرة.
 *
 * `internal_notifications` — ما يُقال لموظّفٍ داخل النظام: مهمّةٌ متأخّرة،
 * مشروعٌ يبدأ غدًا، رسالةٌ لم تصل. سطرٌ يقود إلى مستنده ويُقرأ ويُطوى.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('name', 120);
            /* internal: لا يخرج من النظام · external: يبلغ العميل */
            $table->string('kind', 20)->default('internal');
            $table->string('trigger', 40);
            $table->boolean('enabled')->default(false);
            /* observe: يسجّل ما كان سيفعل · live: يفعل */
            $table->string('mode', 20)->default('observe');
            /* internal · email · whatsapp — قناةُ الخطوات الخارجيّة، والداخليّةُ في التطبيق (وبريدٌ إن كانت email) */
            $table->string('channel', 20)->default('internal');
            $table->string('template_key', 80)->nullable();
            /* [{offset: -3, audience: customer}, …] — الأيّامُ من مرساة المُطلِق، وإلى من */
            $table->json('steps');
            $table->string('send_time', 5)->nullable();
            $table->unsignedSmallInteger('cooldown_hours')->default(24);
            $table->boolean('business_hours')->default(true);
            $table->string('language', 5)->default('ar');
            $table->json('recipients')->nullable();
            $table->boolean('retry_once')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_run_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'key']);
            $table->index(['business_id', 'enabled']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('automation_rule_id')->constrained('automation_rules')->cascadeOnDelete();
            $table->string('trigger', 40);
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_label')->nullable();
            $table->smallInteger('step');
            $table->string('audience', 30);
            /* مرساةُ الخطوة (تاريخُ الاستحقاق، تاريخُ الإرسال…) — تتبدّل فتُعاد الخطوات */
            $table->string('occurrence', 40);
            $table->string('idempotency_key', 190)->unique();
            /* pending · executing · executed · observed · skipped · failed · cancelled */
            $table->string('status', 20)->default('pending');
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->string('result')->nullable();
            $table->string('skipped_reason', 60)->nullable();
            $table->foreignId('communication_id')->nullable()->constrained('communications')->nullOnDelete();
            $table->unsignedSmallInteger('notification_count')->default(0);
            $table->text('failure')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'created_at']);
            $table->index(['automation_rule_id', 'target_type', 'target_id']);
            $table->index(['status', 'scheduled_for']);
        });

        Schema::create('internal_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('automation_run_id')->nullable()->constrained('automation_runs')->nullOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_notifications');
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_rules');
    }
};
