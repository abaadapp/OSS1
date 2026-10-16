<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * كلُّ دفعةٍ على سند المورّد تحفظ إيصالَها.
 *
 * ═══ ولمَ ليس مرفقَ السند ═══
 *
 * `supplier_invoices.attachment` فاتورةُ المورّد نفسُها كما وصلت — تقول «عليك».
 * وإيصالُ السداد يقول «دفعتُ»، والسندُ يُسدَّد على دفعات: ثلاثُمئةٍ تحويلًا،
 * ثمّ أربعُمئةٍ تحويلًا، ثمّ ثلاثُمئةٍ نقدًا بلا إيصال. فلكلّ دفعةٍ صفُّها وإيصالُها.
 *
 * ═══ والدفترُ يبقى مصدرَ الحقيقة ═══
 *
 * المبلغُ والتاريخُ يقولهما قيدُ السداد (`journal_entry_id` — فريد: قيدٌ واحد لدفعةٍ
 * واحدة، ولا دفعةَ بلا قيد). والصفُّ مستندُ الدفعة: من أين خرجت (`paid_from` —
 * لا `from` المحجوزة في SQL)، ومن سجّلها، وإيصالُها على القرص الخاصّ باسمه الأصليّ
 * للعرض وحده. ويُكتبان معًا في معاملة `SupplierInvoices::settle`.
 *
 * و`idempotency_key` مفتاحُ نموذج السداد: ضغطةٌ ثانية أو إعادةُ إرسالٍ بالمفتاح
 * نفسِه لا تُنشئ دفعةً ثانية (فريدٌ في الشركة، والفارغُ لا يُقيَّد).
 *
 * والسداداتُ السابقة لهذا الجدول تبقى قيودًا تُقرأ كما كانت — لا تُملأ بما لا يُعرف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_invoice_id')->constrained('supplier_invoices');
            $table->foreignId('journal_entry_id')->constrained('journal_entries');
            $table->decimal('amount', 14, 3);
            $table->date('paid_at');
            $table->string('paid_from', 10);
            $table->string('receipt', 500)->nullable();
            $table->string('receipt_name', 255)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('journal_entry_id');
            $table->unique(['business_id', 'idempotency_key']);
            $table->index(['business_id', 'supplier_invoice_id']);
            $table->index(['business_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_payments');
    }
};
