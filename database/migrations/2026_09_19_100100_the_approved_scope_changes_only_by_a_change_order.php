<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * النطاقُ المعتمَد لا يتغيّر إلّا بأمر تغييرٍ يقبله العميل.
 *
 * ═══ ليس عرضًا تكميليًّا (§5.2) ═══
 *
 * العرضُ مستندٌ قائمٌ بذاته يُنشئ مشروعًا يومَ يُقبل. وأمرُ التغيير تعديلٌ
 * على مشروعٍ قائم يحمل المحاورَ الثلاثة: بنودٌ بفروقٍ موقَّعة (زيادةٌ أو
 * نقصٌ على قائم، أو بندٌ جديد)، ومتطلّباتٌ تُضاف أو تُزال، وموعدٌ جديد.
 * ولا عمودَ `kind`: يُشتقّ من المحتوى.
 *
 * ═══ ومرآةُ العرض في أعمدة الأثر ═══
 *
 * الدورةُ نفسُها — يُكتب، ويُعتمد إن لزم، ويُرسَل فيُختم ويُبصَم، ويُقبل من
 * الرابط أو يُسجَّل قبولُه — فالأعمدةُ نفسُها، والكودُ الذي يقرؤها واحد.
 *
 * ═══ والمتطلَّبُ يُوسَم ولا يُمحى ═══
 *
 * `change_order_id` من أضافه، و`removed_by_change_order_id` من أزاله. والصفُّ
 * يبقى: ما كان في النطاق يومًا يُقرأ بعد سنةٍ مع الأمر الذي أخرجه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            /* 1, 2, 3 داخل المشروع — والرقمُ «EP-2026-014/CO-02» يُقطع عند الإرسال */
            $table->unsignedSmallInteger('sequence');
            $table->string('number', 40)->nullable();
            $table->string('status', 20)->default('draft');

            $table->string('title');
            $table->text('reason')->nullable();
            /* من طلب التغيير: العميلُ أم الشركة — وباسم من */
            $table->string('requested_by_party', 10)->default('customer');
            $table->string('requested_by_name', 120)->nullable();

            /* ─ الموعدُ الجديد — NULL = بلا تغيير ─ */
            $table->date('new_starts_at')->nullable();
            $table->date('new_ends_at')->nullable();

            /* ─ الفروقُ الموقَّعة: نقصٌ سالب ─ */
            $table->decimal('subtotal_delta', 12, 3)->default(0);
            $table->decimal('discount_delta', 12, 3)->default(0);
            $table->decimal('tax_delta', 12, 3)->default(0);
            $table->decimal('total_delta', 12, 3)->default(0);
            $table->date('valid_until')->nullable();

            /* ─ التجميد — ما خرج يُختم ويُبصَم ─ */
            $table->json('document_snapshot')->nullable();
            $table->json('policy_snapshot')->nullable();
            $table->char('content_hash', 64)->nullable();

            /* ─ الأثر — أعمدةُ العرض نفسُها ─ */
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('sent_at')->nullable();
            $table->string('sent_to', 120)->nullable();
            $table->dateTime('first_viewed_at')->nullable();
            $table->dateTime('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->dateTime('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_name', 120)->nullable();
            $table->string('decline_reason', 500)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'sequence']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('change_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('change_order_id')->constrained()->cascadeOnDelete();
            /* NULL = بندٌ جديد · وإلّا فتعديلُ كميّةٍ على قائم */
            $table->foreignId('project_item_id')->nullable()->constrained('project_items')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $table->string('description');
            $table->string('unit', 40)->nullable();
            /* موقَّعة: سالبٌ = نقص — ويلزمه `project_item_id` */
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('list_price', 12, 3)->default(0);
            $table->decimal('unit_price', 12, 3)->default(0);
            $table->decimal('discount', 12, 3)->default(0);
            $table->string('discount_reason')->nullable();
            $table->foreignId('discount_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('discount_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('discount_approved_at')->nullable();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 3)->default(0);
            $table->decimal('line_total', 12, 3)->default(0);
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('change_order_id');
        });

        Schema::table('project_items', function (Blueprint $table) {
            $table->foreignId('change_order_id')->nullable()->constrained('change_orders')->nullOnDelete();
            /* والسطرُ الذي ولّده بعينه — به يُعاد حسابُ الحارس: الأصلُ + الفروق */
            $table->foreignId('change_order_item_id')->nullable()->constrained('change_order_items')->nullOnDelete();
        });

        Schema::table('project_requirements', function (Blueprint $table) {
            $table->foreignId('change_order_id')->nullable()->constrained('change_orders')->nullOnDelete();
            $table->foreignId('removed_by_change_order_id')->nullable()->constrained('change_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_requirements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('removed_by_change_order_id');
            $table->dropConstrainedForeignId('change_order_id');
        });

        Schema::table('project_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('change_order_item_id');
            $table->dropConstrainedForeignId('change_order_id');
        });

        Schema::dropIfExists('change_order_items');
        Schema::dropIfExists('change_orders');
    }
};
