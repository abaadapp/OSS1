<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الفعاليّةُ تُنفَّذ على الأرض لا على الورق وحده — المرحلة 10.
 *
 * ═══ المشروعُ يبقى الحاويةَ التجاريّة ═══
 *
 * العميلُ والعرضُ والنطاقُ والقيمةُ والفواتيرُ والإقفالُ في `projects` كما
 * كانت. وما يُضاف هنا تشغيلٌ: متى وأين ومن ومعدّاتٌ وقوائمُ وحوادث. ولا عمودَ
 * يُضاف إلى المشروع.
 *
 * ═══ الأصلُ التشغيليّ ليس أصلًا محاسبيًّا ═══
 *
 * `fixed_assets` دفترُ إهلاكٍ — تكلفةٌ وعمرٌ وقسط. و`assets` هويّةُ قطعةٍ
 * بعينها: رقمٌ تسلسليٌّ وحالٌ ومكان ومن أخذها ومتى ردّها. وقد يشير الثاني
 * إلى الأوّل (`fixed_asset_id`) ولا يحلّ أحدُهما محلّ الآخر. وما لا هويّةَ له
 * (حبالٌ وحوامل بطاقات وورق) يبقى في `products` بمخزونه — ويُذكر في
 * المتطلّبات بـ`product_id` لا بصفٍّ ثانٍ.
 *
 * ═══ والحجزُ لا يتداخل ═══
 *
 * قطعةٌ واحدة لا تُحجز لفعاليّتين في وقتٍ واحد. والفهرسُ على
 * (asset_id, reserved_from, reserved_until) للقراءة؛ والحارسُ الفعليُّ قفلُ
 * صفّ الأصل داخل معاملة الحجز — انظر `Operations\Reservations::reserve`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            /* «OP-000012» — يُقطع عند الإنشاء داخل قفل صفّ الشركة */
            $table->string('code', 40);
            $table->string('name');
            $table->string('status', 20)->default('planning');

            $table->string('venue_name', 160)->nullable();
            $table->string('venue_address')->nullable();
            $table->text('venue_notes')->nullable();

            /* الجدولُ الزمنيّ — كلُّها بتوقيت الخادم؛ و`timezone` للعرض والحساب */
            $table->dateTime('setup_starts_at')->nullable();
            $table->dateTime('doors_open_at')->nullable();
            $table->dateTime('event_starts_at')->nullable();
            $table->dateTime('event_ends_at')->nullable();
            $table->dateTime('teardown_starts_at')->nullable();
            $table->dateTime('teardown_ends_at')->nullable();
            $table->string('timezone', 64)->default('Asia/Muscat');

            $table->foreignId('operations_lead_id')->nullable()->constrained('users')->nullOnDelete();

            /* تجاوزُ الجاهزيّة — من ولماذا ومتى وإلى أيّ حال؛ ولا يُمحى */
            $table->json('readiness_override')->nullable();

            $table->dateTime('went_live_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'event_starts_at']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('operation_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_operation_id')->constrained('event_operations')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type', 20)->default('other');
            $table->string('status', 20)->default('pending');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            /* فريقٌ أو متعاقدٌ خارجيّ بلا حساب — اسمٌ يُكتب */
            $table->string('responsible_name', 120)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['event_operation_id', 'starts_at']);
            $table->index(['event_operation_id', 'status']);
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            /* «AST-000123» — فريدٌ في الشركة */
            $table->string('asset_code', 20);
            $table->string('name', 160);
            $table->string('category', 60);
            $table->string('brand', 80)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('serial_number', 120)->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_cost', 12, 3)->nullable();
            $table->string('status', 20)->default('available');
            $table->string('condition', 20)->default('good');
            $table->string('location', 120)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            /* الجسرُ إلى دفتر الإهلاك — اختياريّ */
            $table->foreignId('fixed_asset_id')->nullable()->constrained('fixed_assets')->nullOnDelete();
            $table->dateTime('retired_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'asset_code']);
            $table->unique(['business_id', 'serial_number']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'category']);
        });

        Schema::create('operation_equipment_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_operation_id')->constrained('event_operations')->cascadeOnDelete();
            /* asset_category: قطعٌ ذاتُ هويّة · product: صنفٌ من المخزون · external: نصٌّ حرّ (تأجيرٌ وعميل وغيرهما) */
            $table->string('kind', 20)->default('asset_category');
            $table->string('asset_category', 60)->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description', 200);
            $table->unsignedInteger('required_quantity')->default(1);
            $table->unsignedInteger('assigned_quantity')->default(0);
            $table->string('source', 20)->default('owned');
            /* حرجٌ: نقصُه يمنع الجاهزيّة · احتياطيّ: قطعةٌ بديلة */
            $table->boolean('critical')->default(true);
            $table->boolean('backup')->default(false);
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['event_operation_id', 'kind']);
        });

        Schema::create('asset_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('event_operation_id')->constrained('event_operations')->cascadeOnDelete();
            $table->foreignId('requirement_id')->nullable()->constrained('operation_equipment_requirements')->nullOnDelete();
            $table->dateTime('reserved_from');
            $table->dateTime('reserved_until');
            /* reserved · checked_out · returned · cancelled */
            $table->string('status', 20)->default('reserved');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('checked_out_at')->nullable();
            $table->foreignId('checked_out_by')->nullable()->constrained('users')->nullOnDelete();
            /* إلى من سُلّمت — موظّفٌ مسؤول عنها في الميدان */
            $table->foreignId('custodian_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('checkout_condition', 20)->nullable();
            $table->text('checkout_notes')->nullable();

            $table->dateTime('returned_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('return_condition', 20)->nullable();
            $table->text('missing_accessories')->nullable();
            $table->text('damage_notes')->nullable();

            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'reserved_from', 'reserved_until']);
            $table->index(['asset_id', 'status']);
            $table->index(['event_operation_id', 'status']);
            $table->index(['business_id', 'status', 'reserved_until']);
        });

        Schema::create('operation_crew_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_operation_id')->constrained('event_operations')->cascadeOnDelete();
            /* موظّفٌ من النظام — أو متعاقدٌ مؤقّت باسمه وهاتفه بلا حساب */
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('external_name', 120)->nullable();
            $table->string('external_phone', 40)->nullable();
            $table->string('role', 40);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            /* planned · confirmed · checked_in · checked_out · no_show · cancelled */
            $table->string('status', 20)->default('planned');
            /* pending · briefed · not_required */
            $table->string('briefing_status', 20)->default('pending');
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('checked_out_at')->nullable();
            $table->dateTime('notified_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'starts_at']);
            $table->index(['event_operation_id', 'status']);
        });

        Schema::create('operation_checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            /* pre_event · setup · closing · custom */
            $table->string('phase', 20)->default('custom');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['business_id', 'phase']);
        });

        Schema::create('operation_checklist_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('operation_checklist_templates')->cascadeOnDelete();
            $table->string('title', 200);
            $table->boolean('required')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('operation_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_operation_id')->constrained('event_operations')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('operation_checklist_templates')->nullOnDelete();
            $table->string('name', 120);
            $table->string('phase', 20)->default('custom');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['event_operation_id', 'phase']);
        });

        Schema::create('operation_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_id')->constrained('operation_checklists')->cascadeOnDelete();
            $table->string('title', 200);
            $table->boolean('required')->default(true);
            /* pending · done · blocked · waived */
            $table->string('status', 20)->default('pending');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('due_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            /* دليلُ الإتمام نصًّا — ملاحظةٌ أو مرجعٌ؛ ولا تخزينَ ملفّاتٍ ثانٍ */
            $table->text('evidence_note')->nullable();
            $table->string('blocked_reason')->nullable();
            /* التجاوزُ: بندٌ إلزاميّ أُعفي بسببٍ ومن — ويبقى مقروءًا */
            $table->string('waived_reason')->nullable();
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['checklist_id', 'status']);
        });

        Schema::create('operation_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_operation_id')->constrained('event_operations')->cascadeOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 30)->default('other');
            /* low · medium · high · critical */
            $table->string('severity', 10)->default('medium');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->dateTime('occurred_at');
            /* open · investigating · resolved · closed */
            $table->string('status', 20)->default('open');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            /* أُقرّ بحمله إلى ما بعد الإتمام — حادثٌ حرج لم يُحلّ ولم يُخفَ */
            $table->boolean('carried_forward')->default(false);
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['event_operation_id', 'status']);
            $table->index(['business_id', 'severity', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_incidents');
        Schema::dropIfExists('operation_checklist_items');
        Schema::dropIfExists('operation_checklists');
        Schema::dropIfExists('operation_checklist_template_items');
        Schema::dropIfExists('operation_checklist_templates');
        Schema::dropIfExists('operation_crew_assignments');
        Schema::dropIfExists('asset_reservations');
        Schema::dropIfExists('operation_equipment_requirements');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('operation_schedule_items');
        Schema::dropIfExists('event_operations');
    }
};
