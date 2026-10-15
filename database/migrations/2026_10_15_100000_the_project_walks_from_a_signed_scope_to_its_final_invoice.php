<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المشروعُ يمشي من نطاقٍ يوقّعه العميل إلى فاتورته النهائية.
 *
 * ═══ نطاقُ العمل مستندٌ لا تبويب ═══
 *
 * `ProjectScope` يقرأ النطاقَ من العرض وأوامر التغيير — وهو صحيحٌ ولا
 * يُمسّ. لكنّ ما يوقّعه العميلُ ورقةٌ: وصفُ التنفيذ وما يشمله وما لا
 * يشمله ومسؤوليّاتُ كلّ طرف، فوق البنود والمتطلّبات كما هي الآن. فلها
 * جدولُها، **صفٌّ لكلّ نسخة**: النسخةُ التي وُقّعت لا تُكتب فوقها أخرى،
 * وما يتغيّر بعدها نسخةٌ جديدة (`supersedes_id`) تُوقَّع من جديد.
 *
 * ═══ والفاتورةُ تقول دورَها في المشروع ═══
 *
 * دفعةٌ مقدّمة أم فاتورةٌ نهائيّة — عمودٌ في الفاتورة نفسِها لا جدولٌ
 * ثانٍ، و`billing_basis` يحفظ كيف حُسب المبلغ (نسبةٌ أو مبلغ، وقيمةُ
 * المشروع يومَها) ومفتاحَ الطلب الذي يمنع ضغطتين من فاتورتين.
 *
 * ═══ والمسارُ يُقرأ من الحقيقة ═══
 *
 * `workflow_stage` ليس حالةً تُكتب فتصدق: هو المرحلةُ التي يعمل عليها
 * الفريقُ الآن، لا تتجاوز أوّلَ مرحلةٍ لم تتمّ — انظر `ProjectWorkflow`.
 * والإعفاءُ من الدفعة المقدّمة قرارٌ باسم صاحبه وسببه لا فراغٌ يُفسَّر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scopes_of_work', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            /* العرضُ الذي بدأ منه المشروع — مرجعٌ يُطبع ويُبصَم */
            $table->foreignId('quotation_id')->nullable()->constrained('quotations')->nullOnDelete();
            /* النسخةُ التي حلّت هذه محلَّها — والأولى بلا سابق */
            $table->foreignId('supersedes_id')->nullable()->constrained('scopes_of_work')->nullOnDelete();
            $table->unsignedInteger('revision');
            $table->string('number', 80);
            /* draft · ready · sent · signed · declined · superseded */
            $table->string('status', 20)->default('draft');

            /* العميلُ وجهةُ الاتّصال — كما تُطبع، لا كما في دفتر العملاء اليوم */
            $table->string('customer_name', 255)->nullable();
            $table->string('contact_person', 120)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('contact_email', 120)->nullable();

            /* المشروعُ والفعاليّة */
            $table->string('project_name', 255);
            $table->string('event_type', 60)->nullable();
            $table->string('location', 255)->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->string('schedule_notes', 500)->nullable();

            /* ما يُكتب بيد — ولا يُكتب بعد الإرسال */
            $table->text('description')->nullable();
            $table->text('inclusions')->nullable();
            $table->text('exclusions')->nullable();
            $table->text('company_responsibilities')->nullable();
            $table->text('client_responsibilities')->nullable();
            $table->text('terms')->nullable();
            /* القيمةُ المرجعيّة تظهر للعميل أم لا — قرارُ من يكتب النطاق */
            $table->boolean('show_value')->default(false);

            /* البنودُ والمتطلّباتُ والقيمةُ كما يقولها `ProjectScope` لحظةَ الكتابة */
            $table->json('scope_snapshot');
            $table->char('content_hash', 64)->nullable();
            $table->json('document_snapshot')->nullable();

            $table->timestamp('ready_at')->nullable();
            $table->foreignId('ready_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('decided_at')->nullable();
            $table->string('decided_by_name', 120)->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestamp('superseded_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /* نسخةٌ واحدةٌ بكلّ رقم — بقيدٍ لا بشرطٍ في الكود */
            $table->unique(['project_id', 'revision']);
            $table->index(['business_id', 'status']);
        });

        Schema::table('customer_invoices', function (Blueprint $table) {
            /* project_deposit · project_final — ولا شيء لفاتورةٍ عاديّة */
            $table->string('billing_role', 24)->nullable();
            $table->json('billing_basis')->nullable();
            $table->index(['project_id', 'billing_role']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->string('workflow_stage', 24)->nullable();
            $table->timestamp('deposit_waived_at')->nullable();
            $table->foreignId('deposit_waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deposit_waiver_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deposit_waived_by');
            $table->dropColumn(['workflow_stage', 'deposit_waived_at', 'deposit_waiver_reason']);
        });

        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'billing_role']);
            $table->dropColumn(['billing_role', 'billing_basis']);
        });

        Schema::dropIfExists('scopes_of_work');
    }
};
