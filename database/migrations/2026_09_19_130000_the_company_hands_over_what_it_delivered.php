<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حزمةُ التسليم — ما تسلّمه الشركةُ رسميًّا للعميل في ختام العمل.
 *
 * ═══ حاويةُ دليلٍ لا مستندٌ ماليّ ═══
 *
 * لا قيدَ يقع منها ولا ذمّةَ تنشأ بها. هي ما يُجمَع ويُرسَل ويُقبَل:
 * تقاريرُ وملفّاتٌ ومخرجاتٌ نهائيّة، وبنودٌ مسلَّمة بكميّاتها. والفاتورةُ
 * تبقى بابَ الإيراد الوحيد — انظر `App\Support\Revenue`.
 *
 * ═══ والبندُ دليلٌ من نوعين ═══
 *
 * `file` ملفٌّ يُرفع على القرص الخاصّ ويُحمل بصمتُه (`checksum`) في
 * الصفّ: ما أُرسل يُبصَم بمحتواه لا باسمه. و`item` سطرُ خدمةٍ أو نطاقٍ
 * سُلِّم بكميّة. ولا نموذجَ ثالثًا اسمُه «Deliverable»: هو البندُ نفسُه
 * منظورًا من جهة التسليم.
 *
 * ═══ ووصلةُ المشروع بلا قيدٍ مرجعيّ — عمدًا ═══
 *
 * `project_id` و`project_item_id` عمودان يشيران إلى جداول المرحلة 4 التي
 * تُبنى بالتوازي في شجرةٍ واحدة ولم تُلتزَم بعد. فيُتركان بلا `FOREIGN KEY`
 * كي لا تعتمد هذه الهجرةُ على هجرةٍ لم تستقرّ، ويُقيَّدان في هجرةٍ لاحقة
 * بعد الدمج. وnull فيهما مشروع: حزمةٌ اليوم تتبع عرضًا مقبولًا وعميلًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            /* العرضُ المقبول الذي تُسلَّم مخرجاتُه — أصلُ النطاق حتى يُوصَل المشروع */
            $table->foreignId('quotation_id')->nullable()->constrained()->nullOnDelete();
            /* المرحلة 4 — بلا قيدٍ مرجعيّ حتى الدمج (انظر رأس الملفّ) */
            $table->unsignedBigInteger('project_id')->nullable()->index();

            /* «DP-000001» — يُقطع عند الإرسال لا عند الإنشاء: المسودّةُ بلا رقم */
            $table->string('number', 30)->nullable();
            $table->string('title');
            $table->text('description')->nullable();

            /* draft | ready | sent | accepted | declined | cancelled */
            $table->string('status', 20)->default('draft');

            /* ما يقرؤه العميل على صفحته — وما لا يقرؤه */
            $table->text('client_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('contact_email', 120)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            /* ─ الجاهزيّة: من فحصها ومتى ─ */
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('prepared_at')->nullable();

            /* ─ الخروج: مختومٌ ومبصوم ─ */
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('sent_at')->nullable();
            $table->string('sent_to', 120)->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->json('document_snapshot')->nullable();

            /* ─ الفتح: هل قرأها العميل ─ */
            $table->dateTime('first_viewed_at')->nullable();
            $table->dateTime('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);

            /* ─ القرار: من الرابط دليلٌ في `document_approvals`، ومن موظّفٍ خبرٌ هنا ─ */
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('declined_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_name', 120)->nullable();
            $table->text('decline_reason')->nullable();

            /* ─ الإلغاء الداخليّ ─ */
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancellation_reason', 200)->nullable();

            /* حزمةٌ أُعدّت بعد رفضٍ تشير إلى ما حلّت محلَّه — والمرفوضةُ تبقى دليلًا */
            $table->foreignId('supersedes_id')->nullable()->constrained('delivery_packages')->nullOnDelete();

            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'customer_id']);
        });

        Schema::create('delivery_package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_package_id')->constrained()->cascadeOnDelete();
            /* المرحلة 4 — بلا قيدٍ مرجعيّ حتى الدمج (انظر رأس الملفّ) */
            $table->unsignedBigInteger('project_item_id')->nullable()->index();

            /* file | item */
            $table->string('kind', 20)->default('file');
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('quantity', 12, 3)->nullable();
            $table->string('unit', 40)->nullable();

            /* الملفُّ — على القرص الخاصّ، ويُقرأ من بابٍ يسأل عن صاحبه */
            $table->string('path')->nullable();
            $table->string('name')->nullable();
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->char('checksum', 64)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('delivery_package_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_package_items');
        Schema::dropIfExists('delivery_packages');
    }
};
