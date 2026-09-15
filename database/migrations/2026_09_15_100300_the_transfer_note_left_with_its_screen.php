<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سندُ النقل بين الفروع ذهب مع شاشته.
 *
 * الشاشةُ والمسارُ والمتحكّم حُذفت في جولةٍ سابقة، وبقي الجدولُ ونموذجُه:
 * صفرُ صفوف، وصفرُ مراجع في الكود كلّه — لا نموذجٌ يقرؤه، ولا تقريرٌ
 * يجمعه، ولا نسخةٌ احتياطية تعرفه.
 *
 * وليس هذا قرارًا على «النقل بين الفروع» نفسِه: إن عاد يومًا فسيأتي بجدولٍ
 * يُكتب لما سيفعله حينَها، لا بهيكلٍ فارغٍ كُتب لشاشةٍ زالت.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('stock_transfers');
    }

    public function down(): void
    {
        /*
         * الجدول لا يُعاد: هيكلٌ فارغٌ لا يردّ ما لم يكن فيه شيء، وإعادتُه
         * تُحيي اسمًا لا يقرؤه شيء في هذا النظام.
         */
    }
};
