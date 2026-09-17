<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سياساتُ الشركة — نصٌّ يراه العميل، ومعاملاتٌ يفحصها النظام.
 *
 * ═══ لا Hard-code، ولا محرّكُ قواعد ═══
 *
 * المفتاحُ من قائمةٍ مغلقةٍ في PHP (`Policy\Registry::KEYS`) يقابله صنفُ
 * فحصٍ مكتوبٌ في الكود — على نمط `CustomAlert::METRICS` حرفًا: «لا يصل
 * شيءٌ من إدخال المستخدم إلى قاعدة البيانات كشرط». والمديرُ يضبط
 * **المعاملاتِ والنصَّ**، لا المنطق.
 *
 * ═══ ولمَ النصُّ أخطرُ من الفحص ═══
 *
 * فحصٌ آليٌّ مفقودٌ يعني موظّفًا ينتبه أو لا ينتبه. أمّا **نصُّ الشروط**
 * فهو ما يُثبَت به الحقُّ إن اختلفتم مع عميل. ولو عاش النصُّ في ملفّ PDF
 * خارج النظام لما أثبت «القبولُ الإلكترونيّ» شيئًا: لا يُعرف **أيَّ نصٍّ**
 * قَبِل العميل. فالنصُّ هنا، و`version` يُرفع مع كلّ تعديل، ويُختم داخل
 * العرض لحظةَ إرساله (`quotations.policy_snapshot`) — انظر §18.
 *
 * ═══ والنطاق ═══
 *
 * `scope_type` فارغٌ لسياسةٍ عامّة، أو `customer` لعميلٍ بعقدٍ إطاريّ له
 * مهلتُه، أو `product` / `category` لما يخصّ «طباعة البطاقات» وحدها.
 * و`scope_id` رقمٌ بلا مفتاحٍ خارجيّ: يشير إلى ثلاثة جداول، وسياسةٌ فقدت
 * هدفَها تُقرأ ولا تنطبق — أهونُ من استعادةٍ تسقط.
 *
 * ═══ والاستثناء ═══
 *
 * `policy_exceptions` ما وقع حين خالف عرضٌ سياسةً: **ما رصده النظام**
 * (`detected`) و**سببُ الموظّف** و**رسومٌ** إن فُرضت و**من اعتمد**. وبلا
 * `status`: «ينتظر» هي `approved_at IS NULL`، وعمودٌ ثانٍ للحقيقة نفسِها
 * يفترق عنها يومًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            /* من `Policy\Registry::KEYS` — ويُحرس هناك لا هنا */
            $table->string('key', 40);
            $table->string('scope_type', 20)->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();

            /* معاملاتُ الفحص — «الأيّام: 7، رسومُ الاستعجال: 15٪» */
            $table->json('params')->nullable();

            /* ما يراه العميل على الورقة — بلغتين */
            $table->text('text_ar');
            $table->text('text_en')->nullable();

            $table->boolean('active')->default(true);
            /* سياسةٌ تُكتب اليوم لتسري من أوّل الشهر */
            $table->date('effective_from')->nullable();
            /* يُرفع مع كلّ تعديلٍ في النصّ أو المعاملات — الختمُ يحمله */
            $table->unsignedInteger('version')->default(1);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'key']);
            $table->index(['business_id', 'active']);
        });

        Schema::create('policy_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            /* Quotation اليوم — وProject في المرحلة 4 */
            $table->morphs('exceptionable');

            $table->string('policy_key', 40);
            /*
             * والسياسةُ التي خُولفت بمعرّفها ونسختها معًا: المعرّفُ يقود
             * إليها، والنسخةُ تقول أيَّ نصٍّ كان ساريًا — فإن عُدّلت بعده
             * بقي الاستثناءُ يشير إلى ما خُولف فعلًا.
             */
            $table->foreignId('company_policy_id')->nullable()->constrained('company_policies')->nullOnDelete();
            $table->unsignedInteger('policy_version')->default(1);

            /* ما رصده النظام: «طُلب قبل 3 أيّام والحدّ 7» — أرقامًا لا جملة */
            $table->json('detected')->nullable();
            $table->text('reason');
            $table->decimal('surcharge_amount', 12, 3)->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'approved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_exceptions');
        Schema::dropIfExists('company_policies');
    }
};
