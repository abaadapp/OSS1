<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * لم يعد لأحدٍ أن يفعل باسم أحد.
 *
 * العمودان كُتبا حين كانت لوحةُ منصّةٍ فوق النظام، وفيها بابُ «الدخول
 * كتاجر»: يدخل الدعمُ حسابَ متجرٍ فيُقيَّد فعلُه باسم صاحب المتجر، فوُضع
 * العمودان ليقولا مَن فعلها حقًّا.
 *
 * ولوحةُ المنصّة ذهبت، وذهب معها البابُ الوحيد الذي يكتب `impersonator_id`
 * في الجلسة. فبقي العمودان يُكتبان فارغين في كلّ سطرٍ من السجلّ، ويُقرآن
 * في شرطٍ لا يُسقط صفًّا أبدًا، ويُعرضان في شارةٍ لا تظهر.
 *
 * ولا بيانات تضيع: لم يُكتب فيهما شيءٌ غيرُ الفراغ منذ حُذف الباب. ولو
 * عاد انتحالٌ يومًا فسيأتي بشكله الخاصّ وبأعمدته.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'impersonator_id']);
            $table->dropColumn(['impersonator_id', 'impersonator_name']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('impersonator_id')->nullable()->after('user_name');
            $table->string('impersonator_name')->nullable()->after('impersonator_id');
            $table->index(['business_id', 'impersonator_id']);
        });
    }
};
