<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الإدارةُ تخطّط والدفترُ يجيب — المرحلة 11.
 *
 * ═══ لا حقيقةَ ماليّةً ثانية ═══
 *
 * لا جدولَ هنا يحمل إيرادًا أو مصروفًا أو تحصيلًا: كلُّ رقمٍ إداريّ يُقرأ من
 * الفواتير والدفعات والسندات والمصروفات ودفتر الأستاذ لحظةَ السؤال. وما
 * يُكتب هنا **خططٌ ولقطات**: موازنةٌ بنسخها، وتوقّعٌ يُجمَّد حين يُعتمد
 * ليُقارَن بالواقع لاحقًا، وسيناريوهاتٌ هي محاكاةٌ لا تمسّ قيدًا، ومراجعةٌ
 * إداريّة بقراراتها.
 *
 * ═══ والمستهلَكاتُ تخرج من الرفّ من بابها ═══
 *
 * صرفُ المستهلَكات لفعاليّةٍ يمرّ بـ`StockLedger` و`stock_adjustments`
 * (بمشروعها) كما يمرّ كلُّ نقصٍ — وهذا الجدولُ يذكر ما صُرف وما عاد لكلّ
 * صنفٍ وفعاليّة، بمفتاحِ تكرارٍ يردّ الضغطةَ الثانية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('name', 120);
            /* draft · approved · superseded · archived */
            $table->string('status', 20)->default('draft');
            $table->string('currency', 8)->nullable();
            $table->text('notes')->nullable();
            $table->string('revision_reason')->nullable();
            $table->foreignId('revised_from_id')->nullable()->constrained('budgets')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('superseded_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'fiscal_year', 'version']);
            $table->index(['business_id', 'fiscal_year', 'status']);
        });

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('budgets')->cascadeOnDelete();
            /* حسابٌ من الشجرة — إيرادٌ أو مصروف؛ والفعليُّ يُقرأ من سطوره */
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            /* revenue · expense — يُشتقّ من نوع الحساب ويُحفظ لقراءة الإشارة */
            $table->string('kind', 10);
            $table->unsignedTinyInteger('month');
            $table->decimal('planned_amount', 14, 3)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['budget_id', 'account_id', 'month']);
        });

        Schema::create('financial_scenarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            /* base · conservative · growth · custom */
            $table->string('kind', 20)->default('custom');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'active']);
        });

        Schema::create('financial_scenario_assumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenario_id')->constrained('financial_scenarios')->cascadeOnDelete();
            /* revenue_change_pct · collection_delay_days · expense_change_pct · one_time_outflow · monthly_outflow · monthly_inflow · expected_contract */
            $table->string('key', 40);
            $table->string('label', 160);
            $table->decimal('amount', 14, 3)->nullable();
            $table->decimal('percent', 8, 3)->nullable();
            $table->date('starts_on')->nullable();
            $table->unsignedSmallInteger('months')->nullable();
            $table->string('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('financial_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('horizon_days');
            $table->date('base_date');
            /* draft · approved · superseded */
            $table->string('status', 20)->default('draft');
            $table->foreignId('scenario_id')->nullable()->constrained('financial_scenarios')->nullOnDelete();
            $table->json('assumptions');
            $table->decimal('opening_cash', 14, 3)->default(0);
            /* اللقطةُ كاملةً: الأشهرُ ببنودها ومصدرِ كلّ بند */
            $table->json('projection');
            $table->json('totals');
            $table->string('currency', 8)->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('superseded_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status', 'base_date']);
        });

        Schema::create('management_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->date('period_start');
            $table->date('period_end');
            /* draft · reviewed · closed */
            $table->string('status', 20)->default('draft');
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            /* لقطةُ الأرقام كما كانت يومَ المراجعة — لا تُعاد قراءتُها من الحاضر */
            $table->json('snapshot')->nullable();
            $table->json('sections')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'period_start']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('management_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('management_review_id')->constrained('management_reviews')->cascadeOnDelete();
            $table->string('decision', 255);
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_at')->nullable();
            /* open · done · dropped */
            $table->string('status', 20)->default('open');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });

        Schema::create('operation_consumable_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_operation_id')->constrained('event_operations')->cascadeOnDelete();
            $table->foreignId('requirement_id')->nullable()->constrained('operation_equipment_requirements')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->unsignedInteger('issued_quantity');
            $table->unsignedInteger('returned_quantity')->default(0);
            /* تكلفةُ الوحدة لحظةَ الصرف — المتوسّطُ المتحرّك من `products.cost` */
            $table->decimal('unit_cost', 12, 3)->default(0);
            /* issued · closed */
            $table->string('status', 20)->default('issued');
            $table->string('idempotency_key', 120);
            $table->foreignId('issue_adjustment_id')->nullable()->constrained('stock_adjustments')->nullOnDelete();
            $table->foreignId('return_adjustment_id')->nullable()->constrained('stock_adjustments')->nullOnDelete();
            $table->dateTime('issued_at');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('returned_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'idempotency_key']);
            $table->index(['event_operation_id', 'product_id']);
        });

        Schema::table('operation_equipment_requirements', function (Blueprint $table) {
            $table->unsignedInteger('issued_quantity')->default(0)->after('assigned_quantity');
            $table->unsignedInteger('returned_quantity')->default(0)->after('issued_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('operation_equipment_requirements', function (Blueprint $table) {
            $table->dropColumn(['issued_quantity', 'returned_quantity']);
        });
        Schema::dropIfExists('operation_consumable_issues');
        Schema::dropIfExists('management_decisions');
        Schema::dropIfExists('management_reviews');
        Schema::dropIfExists('financial_forecasts');
        Schema::dropIfExists('financial_scenario_assumptions');
        Schema::dropIfExists('financial_scenarios');
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
    }
};
