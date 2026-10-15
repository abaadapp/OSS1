<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المهمّةُ قد تقف على فعاليّة — وتبلغ صاحبَها على واتساب.
 *
 * ═══ لا جدولَ مهامٍ ثانٍ ═══
 *
 * `project_tasks` يبقى مصدرَ الحقيقة، ويزيد عمودًا اختياريًّا يقول على أيّ
 * فعاليّةٍ تقف المهمّة. وواتساب إشعارٌ لا حالة: ما يُرسَل يقرأ المهمّةَ لحظتَه.
 *
 * ═══ وما يُضاف لذلك ═══
 *
 *  • `event_operations.tasks_published_at` — «نُشرت المهام على الفريق»: مرساةُ
 *    رسالة التعيين الأولى في الأتمتة.
 *  • `event_task_links` — رابطٌ لكلّ موظّفٍ في كلّ فعاليّة: رمزٌ لا يُخمَّن يُحفظ
 *    بصمتُه (sha256) للبحث، ونسختُه مشفّرةً ليُعاد بناءُ الرابط نفسِه في التذكير،
 *    وله انتهاء. ويفتح مهامَّ صاحبه في فعاليّته وحدها.
 *  • `event_task_dispatches` — قيدُ الإرسال لكلّ موظّف: مفتاحُه الفريد
 *    (الشركة · الفعاليّة · الموظّف · المُطلِق · الخطوة) هو منعُ التكرار.
 *  • `communication_templates.header_variables` و`button_variable` — خاناتُ
 *    ترويسة قالب Meta وزرّه ذي الرابط، بالاسم كخانات المتن.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->foreignId('event_operation_id')->nullable()->after('project_id')->constrained('event_operations')->nullOnDelete();
            $table->index(['event_operation_id', 'assignee_id', 'status']);
        });

        Schema::table('event_operations', function (Blueprint $table) {
            $table->timestamp('tasks_published_at')->nullable()->after('organizers_lead_user_id');
            $table->foreignId('tasks_published_by')->nullable()->after('tasks_published_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('communication_templates', function (Blueprint $table) {
            $table->json('header_variables')->nullable()->after('variables');
            $table->string('button_variable', 60)->nullable()->after('header_variables');
        });

        Schema::create('event_task_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_operation_id')->constrained('event_operations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('token');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['event_operation_id', 'user_id']);
            $table->index(['business_id', 'user_id']);
        });

        Schema::create('event_task_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_operation_id')->constrained('event_operations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('trigger', 40);
            $table->string('step', 40);
            $table->string('channel', 20);
            $table->string('status', 20);
            $table->unsignedInteger('open_count')->default(0);
            $table->foreignId('communication_id')->nullable()->constrained('communications')->nullOnDelete();
            $table->foreignId('automation_run_id')->nullable()->constrained('automation_runs')->nullOnDelete();
            $table->string('failure', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'event_operation_id', 'user_id', 'trigger', 'step'], 'event_task_dispatches_once');
            $table->index(['event_operation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_task_dispatches');
        Schema::dropIfExists('event_task_links');

        Schema::table('communication_templates', function (Blueprint $table) {
            $table->dropColumn(['header_variables', 'button_variable']);
        });

        Schema::table('event_operations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tasks_published_by');
            $table->dropColumn('tasks_published_at');
        });

        Schema::table('project_tasks', function (Blueprint $table) {
            $table->dropIndex(['event_operation_id', 'assignee_id', 'status']);
            $table->dropConstrainedForeignId('event_operation_id');
        });
    }
};
