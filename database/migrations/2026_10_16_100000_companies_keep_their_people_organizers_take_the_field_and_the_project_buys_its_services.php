<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الشركاتُ تحفظ أشخاصَها، والمنظّمون يأخذون الميدان، والمشروعُ يشتري خدماتِه.
 *
 * ═══ أشخاصُ الاتصال صفوفٌ لا أعمدةٌ مرقّمة ═══
 *
 * الجهةُ كانت تحمل شخصًا واحدًا في ثلاثة أعمدة على العميل (`contact_person`
 * و`contact_phone` و`contact_email`) — ولا شاشةَ تكتبها. والشركةُ فيها مديرُ
 * تسويقٍ ومحاسبٌ ومنسّقُ فعاليّة. فلهم جدولُهم: صفٌّ لكلّ شخص، وواحدٌ أساسيٌّ
 * **بقيدٍ في القاعدة** لا بشرطٍ في الكود. والأعمدةُ القديمة تبقى سندًا لمن لم
 * يُنقل، ومن له اسمٌ فيها يصير شخصَه الأساسيّ هنا مرّةً واحدة.
 *
 * والعرضُ والمشروعُ يحفظان من اختير (`customer_contact_id`) مرجعًا، وتبقى
 * أسماؤه وأرقامُه منسوخةً فيهما لقطةً كما كانت — الورقةُ تقول ما قالت يومَها.
 *
 * ═══ المنظّمون ليسوا عملاءَ ولا موظّفين ═══
 *
 * يعملون في الميدان من فعاليّةٍ إلى فعاليّة: لهم سجلٌّ وتصنيفاتٌ يديرها
 * أصحابُ الشركة (استقبال · تسجيل · بروتوكول …) — كثيرٌ لكثير. ويُعيَّنون على
 * **الفعاليّة** لا على المشروع: الفعاليّةُ هي التي لها وقتٌ ومكان. ولكلّ
 * فعاليّةٍ مسؤولُ منظّمين من موظّفي الشركة (`organizers_lead_user_id`).
 *
 * ═══ المورّدُ يقدّم خدماتٍ أيضًا ═══
 *
 * لا عالمَ ماليًّا ثانيًا: المورّدُ نفسُه منتجاتٍ أو خدماتٍ أو كليهما، وله
 * خدماتٌ من كتالوجٍ يُبحث به (صوتيات · تصوير · أمن …). وبندُ أمر الشراء يقول
 * نوعَه (`line_kind`): منتجٌ يدخل المخزون، أو خدمةٌ، أو بندٌ مخصّص — وما ليس
 * منتجًا مخزونًا لا يبلغ الرفّ.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ─── أشخاصُ الاتصال ─── */
        Schema::create('customer_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('job_title', 120)->nullable();
            $table->string('department', 120)->nullable();
            $table->string('phone', 40)->nullable();
            /* رقمُ واتساب حين يختلف عن الهاتف — والفراغُ يعني الهاتفَ نفسَه */
            $table->string('whatsapp', 40)->nullable();
            $table->string('email', 120)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'customer_id']);
        });

        /* أساسيٌّ واحدٌ لكلّ جهة — فهرسٌ جزئيٌّ فريد يقوله المحرّك لا الكود */
        $true = DB::connection()->getDriverName() === 'pgsql' ? 'true' : '1';
        DB::statement("CREATE UNIQUE INDEX customer_contacts_one_primary ON customer_contacts (customer_id) WHERE is_primary = {$true}");

        foreach (['quotations', 'projects'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('customer_contact_id')->nullable()->constrained('customer_contacts')->nullOnDelete();
            });
        }

        $this->backfill();

        /* ─── المنظّمون ─── */
        Schema::create('organizer_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name', 80);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'name']);
        });

        Schema::create('organizers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('phone', 40)->nullable();
            $table->string('whatsapp', 40)->nullable();
            $table->string('email', 120)->nullable();
            /* male · female — قائمةٌ مغلقة لا نصٌّ حرّ */
            $table->string('gender', 10);
            /* available · unavailable · suspended */
            $table->string('status', 20)->default('available');
            /* internal · freelance · external */
            $table->string('source', 20)->nullable();
            $table->date('joined_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'name']);
        });

        Schema::create('organizer_category_organizer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->foreignId('organizer_category_id')->constrained('organizer_categories')->cascadeOnDelete();

            $table->unique(['organizer_id', 'organizer_category_id']);
        });

        Schema::table('event_operations', function (Blueprint $table) {
            /* مسؤولُ المنظّمين — موظّفٌ من الشركة، غيرُ قائد العمليّات وقد يكونه */
            $table->foreignId('organizers_lead_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('operation_organizers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('event_operation_id')->constrained('event_operations')->cascadeOnDelete();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            /* تصنيفُه في هذه الفعاليّة — قد يكون استقبالًا هنا وتسجيلًا هناك */
            $table->foreignId('organizer_category_id')->nullable()->constrained('organizer_categories')->nullOnDelete();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            /* assigned · confirmed · attended · absent · cancelled */
            $table->string('status', 20)->default('assigned');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['event_operation_id', 'organizer_id']);
            $table->index(['organizer_id', 'starts_at']);
        });

        /* ─── مقدّمو الخدمات ─── */
        Schema::table('suppliers', function (Blueprint $table) {
            /* products · services · both — ومن سبق كان مورّدَ منتجات */
            $table->string('supplier_type', 20)->default('products');
            $table->string('whatsapp', 40)->nullable();
            /* active · inactive */
            $table->string('status', 20)->default('active');

            $table->index(['business_id', 'supplier_type']);
        });

        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name', 80);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'name']);
        });

        Schema::create('service_category_supplier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained('service_categories')->cascadeOnDelete();

            $table->unique(['supplier_id', 'service_category_id']);
        });

        /* ─── نوعُ بند الشراء ─── */
        Schema::table('purchase_order_items', function (Blueprint $table) {
            /* product · service · custom */
            $table->string('line_kind', 12)->default('product');
        });

        /* وما سبق يُقرأ من حاله: بلا صنفٍ بندٌ مخصّص، وصنفٌ غيرُ مخزونٍ خدمة */
        DB::table('purchase_order_items')->whereNull('product_id')->update(['line_kind' => 'custom']);
        DB::table('purchase_order_items')->whereNotNull('product_id')
            ->whereIn('product_id', DB::table('products')->whereIn('kind', ['service', 'package'])->select('id'))
            ->update(['line_kind' => 'service']);
    }

    /**
     * من له اسمٌ في الأعمدة القديمة يصير شخصَه الأساسيّ — للشركات والجهات وحدَها.
     *
     * الفردُ هو نفسُه جهةُ الاتصال، وهاتفُ الشركة العامّ ليس هاتفَ شخص. ومن لا
     * اسمَ له يبقى على أعمدته سندًا (`CustomerContacts::primary`). ومرّةً: من
     * له أشخاصُ اتصالٍ لا يُضاف إليه — فالنقلُ يُعاد بلا تكرارٍ ولا أساسيَّين.
     */
    public function backfill(): void
    {
        $now = now();
        DB::table('customers')
            ->whereIn('customer_type', ['شركة', 'جهة حكومية'])
            ->whereNotNull('contact_person')->where('contact_person', '<>', '')
            ->whereNotExists(fn ($q) => $q->from('customer_contacts')->whereColumn('customer_contacts.customer_id', 'customers.id'))
            ->chunkById(500, function ($customers) use ($now) {
                $rows = [];
                foreach ($customers as $c) {
                    $rows[] = [
                        'business_id' => $c->business_id, 'customer_id' => $c->id,
                        'name' => mb_substr(trim((string) $c->contact_person), 0, 120),
                        'phone' => $c->contact_phone ?: null, 'email' => $c->contact_email ?: null,
                        'is_primary' => true, 'active' => true, 'created_at' => $now, 'updated_at' => $now,
                    ];
                }
                if ($rows !== []) {
                    DB::table('customer_contacts')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('line_kind');
        });

        Schema::dropIfExists('service_category_supplier');
        Schema::dropIfExists('service_categories');

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'supplier_type']);
            $table->dropColumn(['supplier_type', 'whatsapp', 'status']);
        });

        Schema::dropIfExists('operation_organizers');

        Schema::table('event_operations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organizers_lead_user_id');
        });

        Schema::dropIfExists('organizer_category_organizer');
        Schema::dropIfExists('organizers');
        Schema::dropIfExists('organizer_categories');

        foreach (['projects', 'quotations'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('customer_contact_id');
            });
        }

        Schema::dropIfExists('customer_contacts');
    }
};
