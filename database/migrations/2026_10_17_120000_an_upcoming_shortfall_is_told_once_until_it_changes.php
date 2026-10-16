<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نقصُ المخزون في مشروعٍ قادم يُقال مرّةً — حتى يتغيّر.
 *
 * التوقّعُ نفسُه لا جدولَ له (قراءةٌ تُحسب، لا حركةٌ ولا حجز). وإنّما يُحفظ هنا
 * ما قيل: بصمةُ آخر نقصٍ نُبّه عنه لكلّ مشروع. فالأتمتةُ لا تعيد التنبيه كلَّ دورةٍ
 * عن النقص نفسِه، وإن تحسّن المخزونُ وزال النقص مُحيت البصمة — فنقصٌ جديدٌ
 * لاحقًا يُقال من جديد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_shortfall_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->char('notified_signature', 64)->nullable();
            /* والجمهورُ الذي بلغه نقصُ هذه البصمة — لكلّ خطوةٍ جمهورُها */
            $table->json('notified_audiences')->nullable();
            $table->json('items')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique('project_id');
            $table->index(['business_id', 'notified_signature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_shortfall_states');
    }
};
