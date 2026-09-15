<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الشركة لا اشتراكَ لها ينتهي.
 *
 * `starts_at` و`ends_at` كانا مدّةَ اشتراكِ تاجرٍ على منصّةٍ تبيع النظام:
 * تقرؤهما طبقةُ المستأجر فتقفل اللوحةَ بعد المهلة، ويقرؤهما شريطٌ أحمر
 * فوق كلّ شاشةٍ يعدّ ما بقي. والمنصّةُ ذهبت — صار النظامُ داخليًّا لشركةٍ
 * واحدة — فبقي تاريخُ انتهاءٍ يقفل على أصحابه بابَهم بلا أن يكون لأحدٍ
 * سبيلٌ إلى تجديده: لا شاشةَ تُمدّده، ولا أمرَ يُنشئ فاتورتَه.
 *
 * و`branches_count` عدّادٌ كانت تعرضه قائمةُ المنصّة وحدها، ولا يُصحَّح
 * حين يُضاف فرعٌ أو يُحذف — فهو عددٌ يكذب. والفروع تُعدّ من جدولها.
 *
 * وما يبقى هو ما يمنع فعلًا: `status` — «معطل» يُخرج صاحبَه عند الباب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['starts_at', 'ends_at', 'branches_count']);
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->unsignedInteger('branches_count')->default(1);
        });
    }
};
