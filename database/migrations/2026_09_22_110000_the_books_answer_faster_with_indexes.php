<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فهارسُ على ما يُسأل عنه كثيرًا — المرحلة 12 §43 · §58.
 *
 * قِيست الشاشاتُ على عشرة آلاف عميلٍ وخمسة آلاف فاتورةٍ وعشرين ألف مراسلة،
 * فظهرت جداولُ تُقرأ بالمشروع أو بالتاريخ بلا فهرس: المصروفاتُ (بلا فهرسٍ أصلًا)،
 * وسطورُ اليوميّة بقيدها، وسنداتُ الموردين وتسوياتُ المخزون بمشروعها.
 *
 * كلُّها إضافةٌ لا إسقاط، وعلى بياناتٍ صغيرة تُبنى في أجزاء ثانية. ومتى كبرت
 * الجداولُ إلى ملايين الصفوف فالإضافةُ اللاحقة تكون `CONCURRENTLY` خارج
 * المعاملة — انظر `docs/PRODUCTION_RUNBOOK.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->index(['business_id', 'spent_at']);
            $table->index(['business_id', 'project_id']);
            $table->index(['business_id', 'status']);
        });
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->index('journal_entry_id');
        });
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->index(['business_id', 'project_id']);
            $table->index(['business_id', 'issued_at']);
            $table->index('purchase_order_id');
        });
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->index(['business_id', 'project_id']);
        });
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->index(['business_id', 'project_id']);
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->index(['business_id', 'project_id']);
            $table->index(['business_id', 'status']);
        });
        Schema::table('communications', function (Blueprint $table) {
            $table->index(['business_id', 'customer_id']);
            $table->index(['business_id', 'project_id']);
        });
        Schema::table('customer_credit_notes', function (Blueprint $table) {
            $table->index(['business_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'spent_at']);
            $table->dropIndex(['business_id', 'project_id']);
            $table->dropIndex(['business_id', 'status']);
        });
        Schema::table('journal_lines', fn (Blueprint $table) => $table->dropIndex(['journal_entry_id']));
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'project_id']);
            $table->dropIndex(['business_id', 'issued_at']);
            $table->dropIndex(['purchase_order_id']);
        });
        Schema::table('customer_invoices', fn (Blueprint $table) => $table->dropIndex(['business_id', 'project_id']));
        Schema::table('stock_adjustments', fn (Blueprint $table) => $table->dropIndex(['business_id', 'project_id']));
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'project_id']);
            $table->dropIndex(['business_id', 'status']);
        });
        Schema::table('communications', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'customer_id']);
            $table->dropIndex(['business_id', 'project_id']);
        });
        Schema::table('customer_credit_notes', fn (Blueprint $table) => $table->dropIndex(['business_id', 'issued_at']));
    }
};
