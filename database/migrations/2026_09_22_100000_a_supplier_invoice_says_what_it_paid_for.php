<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سندُ المورّد يقول ماذا اشترى — مخزونًا أم تكلفةً مباشرة (المرحلة 12 §18).
 *
 * ═══ ما كان ═══
 *
 * كلُّ سندٍ يُعتمد كان يُرحَّل `Dr مخزون` — دلالةٌ موروثة من متجر التجزئة الذي
 * كان يشتري بضاعةً للرفّ. وشركةُ الفعاليّات تشتري في أكثر أيّامها خدماتٍ:
 * مسرحٌ وصوتٌ وطباعةٌ ونقلٌ وضيافة — لا تدخل رفًّا ولا تخرج منه بيعًا، فبقيت
 * في المخزون بالدفتر أصلًا لا يُستهلك، ونقصت قائمةُ الدخل بمقدارها.
 *
 * ═══ ما صار ═══
 *
 * يُقرأ ما اشتراه السند من أمر الشراء: بنودٌ لأصنافٍ مخزونةٍ تُرحَّل مخزونًا،
 * وما سواها (خدمةٌ أو بندٌ حرٌّ أو سندٌ بلا أمر) يُرحَّل «مشتريات مباشرة»
 * (5150) — تكلفةَ خدمةٍ تظهر في الدخل يومَ اعتمادها. ويُتاح للمحاسب أن يقول
 * الطبيعةَ بيده قبل الاعتماد. وما رُحِّل قبل اليوم لا يُعاد كتابتُه: الأعمدةُ
 * تُضاف فارغةً، والقيدُ القديم يبقى كما وقع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            /* auto | inventory | direct — فارغٌ يعني «يُشتقّ من أمر الشراء عند الاعتماد» */
            $table->string('cost_kind', 12)->nullable()->after('is_opening_balance');
            /* ما رُحِّل فعلًا على كلّ حساب — يُكتب عند الاعتماد ولا يتغيّر */
            $table->decimal('inventory_cost', 14, 3)->nullable()->after('cost_kind');
            $table->decimal('direct_cost', 14, 3)->nullable()->after('inventory_cost');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropColumn(['cost_kind', 'inventory_cost', 'direct_cost']);
        });
    }
};
