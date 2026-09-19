<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * العرضُ المقبول يصير مشروعًا — حاويةً تشغيليّةً لا مستندًا ماليًّا.
 *
 * ═══ المشروعُ يقرأ ولا يكتب (§16) ═══
 *
 * لا قيدَ يقع منه، ولا ذمّةَ تنشأ به. تُقيَّد مستنداتُه — فاتورةُ العميل
 * وسندُ المورّد والمصروف — وهو يجمعها بعمود `project_id` عليها ويقرؤها.
 * فالأعمدةُ الخمسة على الجداول القائمة `NULL` افتراضًا: لا هجرةَ بيانات،
 * ولا تغييرَ سلوكٍ لصفٍّ قائم.
 *
 * ═══ وبندُ المشروع غيرُ بند العرض (§5.1) ═══
 *
 * بندُ العرض وعدٌ مجمَّدٌ مبصوم لا يتغيّر. وبندُ المشروع **الكميّةُ
 * المعتمَدةُ الحاليّة**: تُنسخ من العرض يومَ القبول، ولا يغيّرها بعدها إلّا
 * قبولُ أمر تغيير. والمتطلَّبُ شرطٌ يُستوفى عند التسليم — لا سعرَ له ولا
 * كميّة، فلا يصلح بندًا؛ ويبقى بعد أن تُنجَز مهامُّه، فلا يصلح مهمّة.
 *
 * ═══ و`unique(quotation_id)` ═══
 *
 * عرضٌ مقبولٌ واحدٌ = مشروعٌ واحد. والقيدُ في القاعدة لا في الشرط: ضغطتان
 * على «أقبل» — أو طلبٌ يُعاد — تقرآن «لا مشروعَ بعد» معًا خارج القفل،
 * والقيدُ يردّ الثانية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            /* «EP-2026-014» — يُقطع عند الإنشاء: المشروعُ لا مسودّةَ له */
            $table->string('code', 40);
            $table->string('name');
            $table->string('status', 20)->default('confirmed');

            /* العرضُ المعتمَد — والأصلُ التجاريُّ الذي لا يُمسّ بعد القبول */
            $table->foreignId('quotation_id')->nullable()->unique()->constrained('quotations')->nullOnDelete();

            /* ─ الفعاليّة — الموعدُ الحاليّ؛ والأصليُّ على العرض ─ */
            $table->string('event_type', 60)->nullable();
            $table->string('location')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->string('contact_person', 120)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('contact_email', 120)->nullable();

            /*
             * ─ القيمة ─
             * `approved_value` **الأصلُ** لحظةَ القبول. والحاليّةُ تُشتقّ:
             * الأصلُ + فروقُ أوامر التغيير المقبولة — ولا عمودَ لها، فعمودان
             * يقولان الشيء نفسه يفترقان يومًا.
             */
            $table->decimal('quoted_value', 12, 3)->default(0);
            $table->decimal('approved_value', 12, 3)->default(0);

            /* ─ تثبيتُ المتطلّبات — مرّةً، ولا إعادةَ تثبيت ─ */
            $table->dateTime('requirements_baselined_at')->nullable();
            $table->foreignId('requirements_baselined_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            /* ─ الإقفال — ولقطةُ الأرقام كما كانت يومَه ─ */
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('financials_frozen')->nullable();

            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'customer_id']);
            $table->index(['business_id', 'starts_at']);
        });

        Schema::create('project_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            /* أصلُه في العرض — وnull لما أضافه أمرُ تغيير (عمودُه في الهجرة التالية) */
            $table->foreignId('quotation_item_id')->nullable()->constrained('quotation_items')->nullOnDelete();

            $table->string('description');
            $table->string('unit', 40)->nullable();
            /* الكميّةُ المعتمَدةُ الحاليّة — بابُها الواحد `ChangeOrders::apply` */
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('unit_price', 12, 3)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('line_total', 12, 3)->default(0);
            /* تزيد مع حزم التسليم — المرحلة 5 */
            $table->decimal('delivered_quantity', 12, 3)->default(0);
            $table->string('status', 20)->default('pending');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('project_id');
        });

        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role_in_project', 60)->nullable();
            $table->dateTime('assigned_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
        });

        Schema::create('project_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_at')->nullable();
            $table->string('status', 20)->default('open');
            $table->string('priority', 10)->default('normal');
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });

        Schema::create('project_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            /* شرطٌ على بندٍ بعينه — أو على المشروع كلِّه */
            $table->foreignId('project_item_id')->nullable()->constrained('project_items')->nullOnDelete();
            $table->string('title');
            $table->text('details')->nullable();
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('project_id');
        });

        /*
         * الأعمدةُ الخمسة — الجسرُ من المستند إلى مشروعه. ومنها يُقرأ
         * الربحُ: `journal_entries.sourceable → المستند → project_id` بلا بُعدٍ
         * على سطر القيد (§8.3).
         */
        foreach (['customer_invoices', 'expenses', 'purchase_orders', 'supplier_invoices', 'stock_adjustments'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['customer_invoices', 'expenses', 'purchase_orders', 'supplier_invoices', 'stock_adjustments'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('project_id');
            });
        }

        Schema::dropIfExists('project_requirements');
        Schema::dropIfExists('project_tasks');
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('project_items');
        Schema::dropIfExists('projects');
    }
};
