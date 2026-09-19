<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * البياناتُ التاريخيّة تدخل من بابٍ واحد — المرحلة 9.
 *
 * ═══ سجلُّ المجموعات ═══
 *
 * كلُّ ملفٍّ يصل يصير «مجموعةً» برقمٍ لا يتغيّر (`DATA-0001`) وبصمةٍ
 * (`checksum`) وحالٍ تمشي في مسارٍ واحد: مسجَّلة → مقروءة → مربوطة →
 * مفحوصة → مجرَّبة → معتمَدة → مستورَدة → مطابَقة. ولا تقفز مجموعةٌ من
 * الرفع إلى الاستيراد.
 *
 * ═══ والتنفيذاتُ والصفوف ═══
 *
 * التجربةُ الجافّة والاستيرادُ الحقيقيُّ كلاهما «تنفيذ» له سجلُّه وصفوفُه:
 * لكلّ صفٍّ في المصدر نتيجةٌ — أُنشئ · رُبط · تُخطّي · تعارض · خطأ.
 *
 * ═══ والأثر ═══
 *
 * `import_links` يقول لكلّ كيانٍ أُنشئ أو رُبط: من أيّ مجموعةٍ وأيّ صفّ.
 * وقيدُه الفريد هو ما يجعل تكرارَ الاستيراد لا يكرّر الكيان.
 *
 * ═══ والمنشأ ═══
 *
 * عمودُ `origin` على ما يبلغ العميلَ أو الدفتر: «native» لما وُلد في النظام،
 * و«historical_import» لما جاء من الخزنة — فتعرفه الأتمتةُ فلا تُذكّر به،
 * وتعرفه الشاشةُ فتقول «مستورَد». و`is_opening_balance` للفاتورة والسند
 * اللذين لا يحملان مستندًا بل رصيدًا يومَ التحويل.
 */
return new class extends Migration
{
    private const ORIGIN = [
        'customers', 'suppliers', 'products', 'projects', 'quotations',
        'customer_invoices', 'customer_payments', 'expenses', 'supplier_invoices', 'purchase_orders',
    ];

    public function up(): void
    {
        Schema::create('import_mapping_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('category', 32);
            $table->json('mapping');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'name']);
        });

        Schema::create('import_datasets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('code', 12);
            $table->string('name', 160);
            $table->string('category', 32);
            $table->string('source', 160)->nullable();
            $table->string('original_filename', 255);
            $table->string('extension', 8);
            $table->string('mime', 120)->nullable();
            /* مسارٌ داخل القرص الخاصّ — لا يخرج إلى الشاشة */
            $table->string('path', 255);
            $table->string('checksum', 64);
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('row_count')->nullable();
            $table->unsignedInteger('column_count')->nullable();
            $table->string('status', 24)->default('registered');
            $table->json('columns')->nullable();
            $table->json('profile')->nullable();
            $table->json('sample')->nullable();
            $table->json('mapping')->nullable();
            $table->unsignedInteger('mapping_version')->default(0);
            $table->foreignId('template_id')->nullable()->constrained('import_mapping_templates')->nullOnDelete();
            $table->string('strategy', 24)->nullable();
            $table->json('validation')->nullable();
            $table->json('resolutions')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            /* بصمةُ ما اعتُمد: الملفُّ والربطُ والاستراتيجيّةُ والقراراتُ والتجربة — تغيّرَ أحدُها سقط الاعتماد */
            $table->string('approved_fingerprint', 64)->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->json('reconciliation')->nullable();
            $table->json('rollback')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'checksum']);
        });

        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dataset_id')->constrained('import_datasets')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('status', 16)->default('running');
            $table->string('checksum', 64);
            $table->unsignedInteger('mapping_version')->default(0);
            $table->string('strategy', 24)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('totals')->nullable();
            $table->json('financial')->nullable();
            $table->json('findings')->nullable();
            $table->json('reconciliation')->nullable();
            $table->json('report')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['dataset_id', 'kind']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dataset_id')->constrained('import_datasets')->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('import_runs')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('source_ref', 64);
            $table->string('outcome', 16);
            $table->string('level', 8)->nullable();
            $table->json('messages')->nullable();
            $table->string('match', 16)->nullable();
            $table->string('entity_type', 64)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->timestamps();

            $table->index(['run_id', 'row_number']);
            $table->index(['dataset_id', 'outcome']);
        });

        Schema::create('import_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dataset_id')->constrained('import_datasets')->cascadeOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('import_runs')->nullOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('source_ref', 64);
            $table->string('linkable_type', 64);
            $table->unsignedBigInteger('linkable_id');
            $table->string('action', 8);
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();

            /* الصفُّ الواحد يلد كيانًا واحدًا من كلّ نوع — ولو أُعيد الاستيراد */
            $table->unique(['dataset_id', 'row_number', 'linkable_type'], 'import_links_once');
            $table->index(['linkable_type', 'linkable_id']);
            $table->index(['business_id', 'source_ref']);
        });

        /* والحلقةُ الوحيدة: المجموعةُ تشير إلى آخر تجربةٍ وإلى استيرادها، والتنفيذُ يشير إلى مجموعته — فتُكتب بعده */
        Schema::table('import_datasets', function (Blueprint $table) {
            $table->foreignId('dry_run_id')->nullable()->after('resolutions')->constrained('import_runs')->nullOnDelete();
            $table->foreignId('import_run_id')->nullable()->after('approved_fingerprint')->constrained('import_runs')->nullOnDelete();
        });

        foreach (self::ORIGIN as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('origin', 24)->default('native');
            });
        }

        foreach (['customer_invoices', 'supplier_invoices'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->boolean('is_opening_balance')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (['customer_invoices', 'supplier_invoices'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('is_opening_balance'));
        }
        foreach (self::ORIGIN as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('origin'));
        }

        Schema::table('import_datasets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dry_run_id');
            $table->dropConstrainedForeignId('import_run_id');
            $table->dropConstrainedForeignId('template_id');
        });
        Schema::dropIfExists('import_mapping_templates');
        Schema::dropIfExists('import_links');
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_runs');
        Schema::dropIfExists('import_datasets');
    }
};
