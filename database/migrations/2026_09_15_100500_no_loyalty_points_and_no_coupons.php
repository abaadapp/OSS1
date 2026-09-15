<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * لا نقاطَ ولاءٍ ولا كوبونات.
 *
 * برنامجُ الولاء كان يُدار من «أدوات التسويق»، والكوبوناتُ من شاشتها —
 * وكلاهما حُذف. فبقيت أربعةُ أعمدةٍ على الطلب لا يكتبها شيء
 * (`coupon_code` و`coupon_discount` و`points_earned` و`redeemed_points`)،
 * وعمودٌ على العميل (`points`) يُقرأ ويُعرض ويُصدَّر ويُستورد ولا يزيده
 * أحد: لا بيعةَ تكسبه نقطةً، ولا شاشةَ تصرفها.
 *
 * وأسوأُ ما فيه أنّه يُعرض: عمودٌ اسمه «نقاط الولاء» في جدول العملاء يقول
 * رقمًا ثابتًا أبدًا، وتلميحٌ تحت رقم الهاتف يَعِد بأن «النقاط تتبع الرقم».
 *
 * و`coupon_discount` كان أسوأ: تقرؤه `OrderCorrection::recompute` وتطرحه من
 * الفاتورة عند كلّ تصحيح — حسابٌ يجري على قيمةٍ لا تُكتب.
 *
 * والخصمُ العامّ يبقى: `orders.discount` يُكتب بيد من يبيع، ولا علاقة له
 * بكوبون.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['coupon_code', 'coupon_discount', 'points_earned', 'redeemed_points']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('points');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('coupon_code')->nullable();
            $table->decimal('coupon_discount', 12, 3)->default(0);
            $table->unsignedInteger('points_earned')->default(0);
            $table->unsignedInteger('redeemed_points')->default(0);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedInteger('points')->default(0);
        });
    }
};
