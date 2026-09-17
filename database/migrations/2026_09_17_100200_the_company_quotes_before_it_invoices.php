<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الشركةُ تعرض قبل أن تُفوتر.
 *
 * ═══ ولمَ مستندٌ مستقلّ لا مسودّةُ فاتورة ═══
 *
 * عرضُ السعر **ليس مستندًا محاسبيًّا**: لا يُنشئ ذمّةً، ولا يكتب قيدًا، ولا
 * يدخل إقرارًا ضريبيًّا. وفاتورةٌ في حال «معروضة» كانت ستعني أنّ كلّ
 * استعلامٍ يقرأ الإيرادَ يجب أن يستثنيها — وأوّلُ من ينسى يُدخل في الدفتر
 * مبلغًا لم يُبَع بعد. والفصلُ يجعل `Revenue` كما هي بلا حرفٍ يتغيّر.
 *
 * ═══ وهو مرآةُ `customer_invoices` عمدًا ═══
 *
 * الأعمدةُ نفسُها بالأسماء نفسِها حيث المعنى واحد — فالتحويلُ إلى فاتورةٍ
 * **نسخُ بنودٍ مباشر** لا تحويلَ شكل. وما زاد عليها فلعرضِ السعر وحده:
 * الفعاليّة، والصلاحية، والتنقيح، والبصمة.
 *
 * ═══ الحالاتُ السبع — وثلاثٌ لا تُخزَّن ═══
 *
 *   draft · pending_approval · sent · accepted · rejected · cancelled · superseded
 *
 * و«منتهية» و«لم تُفتح» و«تحتاج متابعة» **تُشتقّ ولا تُخزَّن**: حالةٌ في
 * عمودٍ تحتاج مهمّةً ليليّة تُحدّثها، وأوّلُ ليلةٍ تسقط فيها تجعل الشاشة
 * تكذب. وهي القاعدةُ نفسُها في `CustomerInvoice::paymentState()`.
 *
 * ═══ والتنقيحُ علاقةٌ لا حالة ═══
 *
 * `root_id` يجمع سلسلةً، و`revision` يرقّمها. والأصلُ يصير `superseded`
 * والجديدُ `draft`. ولا يُمحى تاريخ: ما خرج إلى عميلٍ يبقى كما خرج.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            /*
             * ولا رقمَ للمسودّة — كما في فاتورة العميل حرفًا بحرف.
             *
             * مسودّةٌ تُهجر تأخذ رقمَها معها ويقفز التسلسل. والرقمُ يُقطع عند
             * **الإرسال**: هو اللحظةُ التي يصير فيها للورقة وجودٌ خارج الشركة.
             * وتنقيحٌ يرث رقمَ جذره ولا يقطع رقمًا ثانيًا.
             */
            $table->string('number', 40)->nullable();
            $table->string('status', 20)->default('draft');

            /*
             * جذرُ سلسلة التنقيحات — و`null` للأصل نفسِه.
             *
             * ولا `superseded_by_id`: «أيُّ تنقيحٍ حلّ محلّي» يُشتقّ من
             * السلسلة نفسِها (الأعلى رقمًا في الجذر نفسه). وعمودٌ وجدولٌ
             * يقولان الشيء نفسه يفترقان يومًا.
             */
            $table->foreignId('root_id')->nullable()->constrained('quotations')->nullOnDelete();
            $table->unsignedInteger('revision')->default(1);

            /* ─────────────── الفعاليّة ─────────────── */

            /* عنوانُ الفعاليّة أو المشروع — «مؤتمر عُمان للطاقة ٢٠٢٦» */
            $table->string('title');
            $table->string('event_location')->nullable();
            /*
             * وتاريخان لا واحد: فعاليّةٌ تمتدّ ثلاثةَ أيّامٍ تُسعَّر بأيّامها.
             * وتاريخٌ لا وقت: «١٢ مارس» لا تحتمل قراءتين، و«١٢ مارس ٩:٠٠»
             * تجعل من يكتبها يخترع ساعةً لا يعرفها بعد.
             */
            $table->date('event_starts_at')->nullable();
            $table->date('event_ends_at')->nullable();
            $table->unsignedInteger('expected_attendees')->nullable();

            /* ─────────────── التجارة ─────────────── */

            $table->date('issued_at')->nullable();
            /*
             * وصلاحيّةُ العرض تُكتب ولا تُشتقّ من مدّةٍ في الإعدادات.
             *
             * «صالحٌ ٣٠ يومًا» قاعدةٌ عامّة، وهذا العرضُ بعينه قد يُمدَّد
             * لجهةٍ تأخذ وقتَها. والورقةُ تحمل ما وُعد به لا ما كان معتادًا.
             */
            $table->date('valid_until')->nullable();
            /* لقطةُ العملة: ورقةٌ خرجت بالريال تبقى بالريال وإن غُيّرت العملة */
            $table->string('currency', 8)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();

            $table->decimal('subtotal', 12, 3)->default(0);
            $table->decimal('discount_total', 12, 3)->default(0);
            $table->decimal('tax_total', 12, 3)->default(0);
            $table->decimal('total', 12, 3)->default(0);

            /* ─────────────── الجهة ─────────────── */

            /* لقطةُ العميل — عرضٌ خرج لا يغيّره تبديلُ اسمٍ بعد سنة */
            $table->string('customer_name')->nullable();
            $table->string('customer_tax_number', 50)->nullable();
            $table->string('customer_cr', 50)->nullable();
            $table->text('customer_address')->nullable();

            /*
             * ومرجعُ العميل لا «رقم أمر الشراء».
             *
             * أمرُ الشراء يُصدَر **بعد** قبول العرض لا قبله؛ ووضعُ حقلٍ له
             * هنا يجعل من يملأ النموذج يبحث عن رقمٍ لا وجود له بعد. والذي
             * يُوجد قبل العرض رقمُ مناقصةٍ أو طلبِ تسعيرٍ من الجهة.
             */
            $table->string('customer_reference', 60)->nullable();
            $table->string('department', 120)->nullable();
            $table->string('attention_to', 120)->nullable();
            /* وبريدُ المُرسَل إليه وهاتفُه — العرضُ يمضي إلى شخصٍ لا إلى جهةٍ مجرّدة */
            $table->string('contact_email', 120)->nullable();
            $table->string('contact_phone', 40)->nullable();

            /* ─────────────── النصوص ─────────────── */

            /*
             * وأربعةُ حقولٍ نصّيّةٍ لا واحد — ولكلٍّ موضعُه على الورقة.
             *
             *  • `scope`  نطاقُ العمل: ما سيُنفَّذ وما لا يُنفَّذ. وهو أكثرُ ما
             *    يُختلف عليه بعد القبول، فيُكتب قبله.
             *  • `terms`  الشروطُ التجاريّة: الإلغاءُ والتأجيلُ والمسؤوليّة.
             *  • `notes`  ملاحظةٌ للعميل — تُطبع.
             *  • `internal_notes` ما لا يبلغ ورقتَه بحال.
             */
            $table->text('scope')->nullable();
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();

            /* ─────────────── التجميد ─────────────── */

            /* لقطةُ الهويّة والعملة والقالب — انظر `Document\Snapshot` */
            $table->json('document_snapshot')->nullable();
            /*
             * وبصمةُ ما خرج: `sha256` على البنود والإجماليّات والشروط.
             *
             * وهي ما يجعل القبولَ **دليلًا** حين يُبنى في مرحلةٍ تالية: سجلُّ
             * القبول يحمل البصمة، فيُثبَت بعد سنةٍ أنّ ما قُبل هو هذا بعينه.
             *
             * ولا `terms_snapshot`: الشروطُ في أعمدةِ هذا الصفّ نفسِه، وهي
             * مجمَّدةٌ بمنع التعديل بعد الإرسال. ونسخةٌ ثانيةٌ منها في JSON
             * مصدرٌ ثانٍ للحقيقة نفسِها.
             */
            $table->char('content_hash', 64)->nullable();

            /* ─────────────── الأثر ─────────────── */

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            /* الاعتمادُ الداخليّ — حين يتجاوز الخصمُ حدَّ من كتبه */
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();

            /*
             * و«شُوهد» **واقعةٌ لها ختمُ وقت** لا حالةٌ في المسار.
             *
             * جعلُها حالةً يُفقدك الفرقَ بين «أُرسل ولم يُفتح قطّ» و«فُتح
             * خمسَ مرّات»، ويجعل عرضًا قُبل بمكالمةٍ هاتفيّة عالقًا.
             */
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);

            /*
             * والقرارُ ينسبه من سجّله.
             *
             * `decided_by` موظّفٌ سجّله نيابةً، و`decided_by_name` اسمُ من
             * قرّر لدى العميل حين يأتي القرارُ من الرابط العامّ — ولا حساب
             * له في النظام ولا يجب أن يكون.
             */
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_name', 120)->nullable();
            $table->string('rejection_reason')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable();

            $table->timestamps();

            /*
             * والرقمُ فريدٌ **مع تنقيحه**: سلسلةٌ واحدة تحمل الرقمَ نفسَه
             * بتنقيحاتٍ مختلفة — وهو معنى «العرضُ نفسُه، النسخةُ الثانية».
             */
            $table->unique(['business_id', 'number', 'revision']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'customer_id']);
            $table->index(['business_id', 'valid_until']);
            $table->index(['business_id', 'issued_at']);
            $table->index('root_id');
        });

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            /* والمنتجُ يُقيَّد لا يُحذف معه — واللقطةُ تحمل اسمَه وثمنَه أصلًا */
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            /*
             * والوصفُ لقطةٌ لا إحالة.
             *
             * «تشغيل نظام تسجيل لمؤتمرٍ من يومين» ليس بندًا في الكتالوج.
             * ومنتجٌ كان اسمُه «تسجيل» فصار «تسجيل متقدّم» لا يعيد كتابة
             * عرضٍ خرج.
             */
            $table->string('description');
            /* «يوم» · «شخص» · «جهاز» · «م²» — نصٌّ يكتبه من يعرف عملَه */
            $table->string('unit', 40)->nullable();
            $table->decimal('quantity', 12, 3)->default(1);

            /*
             * ═══ عمودان للسعر لا واحد ═══
             *
             * `list_price` سعرُ الكتالوج المعتمد — **يُقرأ من الخادم لحظةَ
             * إضافة البند ولا يُقبل من الطلب إطلاقًا**. و`unit_price` ما
             * سيُفوتَر به فعلًا.
             *
             * وبعمودٍ واحد لا يُعرف أوقع خصمٌ أصلًا: من يرسل سعرًا أقلّ
             * يُخفي الخصمَ في الرقم، فلا سببَ يُطلب ولا اعتمادَ يُشترط ولا
             * أثرَ يبقى. وهو بعينه ما طُلب منعُه.
             */
            $table->decimal('list_price', 12, 3)->default(0);
            $table->decimal('unit_price', 12, 3)->default(0);
            /*
             * والخصمُ **محسوبٌ لا مُرسَل**: `(list_price − unit_price) × quantity`.
             * فلا يفترق الرقمُ عن السعرين اللذين يشرحانه.
             */
            $table->decimal('discount', 12, 3)->default(0);

            /* ─── أثرُ الخصم — ثلاثةُ أسئلةٍ لكلّ خصمٍ يقع ─── */
            $table->string('discount_reason')->nullable();
            $table->foreignId('discount_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('discount_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('discount_approved_at')->nullable();

            /* لقطةُ الضريبة: تغييرُ النسبة الشهرَ القادم لا يمسّ ورقةً خرجت */
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 3)->default(0);
            $table->decimal('line_total', 12, 3)->default(0);

            /* نطاقُ هذا البند وحدَه — «يشمل التركيب والفكّ» */
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('quotation_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
    }
};
