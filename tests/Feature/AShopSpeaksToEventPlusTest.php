<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\SupportAttachment;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Support\Support;
use App\Support\TenantTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * مركزُ المحادثات — ما يُقال، ولمن، وما لا يُقال أبدًا.
 *
 * وأثقلُ ما هنا حارسٌ واحد: **الملاحظةُ الداخليّة لا تبلغ صاحبَ المتجر**.
 * ما دونه عطبٌ يُصلَح، وهو فضيحةٌ لا تُستدرك — كلامُ الفريق عن عميلٍ يصل
 * العميلَ نفسَه.
 */
class AShopSpeaksToEventPlusTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->shop('محل الورد');
        $this->owner = $this->person($this->shop->id, 'admin', 'صاحب المحل');
        $this->staff = $this->person(null, 'super_admin', 'دعم افنت بلس');
    }

    /** متجرٌ بأقلّ ما يقوم به صفُّه */
    private function shop(string $name): Business
    {
        return Business::create([
            'name' => $name, 'type' => 'محل ورود', 'status' => 'نشط',
            'city' => 'مسقط', 'phone' => '96871141624',
        ]);
    }

    /** ومستخدمٌ ببريدٍ لا يتكرّر — الفهرسُ عليه فريد */
    private function person(?int $businessId, string $role, string $name = 'مستخدم'): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'business_id' => $businessId, 'name' => $name, 'email' => "u{$n}@eventplus.om",
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
        ]);
    }
    /* ═══════════════ الرقم ═══════════════ */

    public function test_each_conversation_carries_a_reference_that_never_repeats(): void
    {
        $a = $this->conversation();
        $b = $this->conversation();

        $this->assertMatchesRegularExpression('/^SUP-\d{4}-\d{6}$/', $a->reference);
        $this->assertNotSame($a->reference, $b->reference);
        $this->assertSame(
            (int) substr($a->reference, -6) + 1,
            (int) substr($b->reference, -6),
            'والتسلسلُ يتقدّم واحدًا لا يقفز',
        );
    }
    /* ═══════════════ الترجمة ═══════════════ */

    public function test_every_status_priority_and_channel_has_an_english_word(): void
    {
        $en = json_decode(file_get_contents(base_path('lang/en.json')), true);

        $keys = array_merge(
            ['جديدة', 'مفتوحة', 'بانتظار العميل', 'بانتظار فريق افنت بلس', 'تم الحل', 'مغلقة'],
            ['منخفضة', 'عادية', 'عالية', 'عاجلة'],
            ['داخل افنت بلس'],
            Support::CATEGORIES,
            ['مركز المحادثات', 'المساعدة والدعم', 'رد للعميل', 'ملاحظة داخلية',
                'غير معيّن', 'فتح صفحة النشاط', 'إرفاق ملف', 'إرسال',
                'لا توجد محادثات حاليًا', 'لا توجد رسائل بعد', 'اختر محادثة لعرض الرسائل'],
        );

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $en, "لا ترجمة إنجليزية لـ«{$key}»");
            $this->assertNotSame('', trim((string) $en[$key]), "ترجمةٌ فارغة لـ«{$key}»");
        }
    }
    /** ولا سطرَ في مركز المحادثات يقرأ جدولَ رسائل المتاجر */
    public function test_the_platform_inbox_never_reads_the_tenant_message_table(): void
    {
        $files = [
            app_path('Http/Controllers/SuperAdmin/ConversationController.php'),
            app_path('Support/Support.php'),
        ];

        foreach ($files as $file) {
            /*
             * والتعليقاتُ تُنزَع قبل الفحص.
             *
             * هذا الملفُّ نفسُه يشرح في ترويسته أنّه **لا** يمسّ
             * `whatsapp_messages` — فحارسٌ يقرأ النصّ الخام يسقط على شرحِ
             * البراءة ويحسبه جُرمًا. والمقروءُ هو ما يُنفَّذ لا ما يُكتب
             * لقارئٍ بشريّ.
             */
            $code = '';
            foreach (token_get_all(file_get_contents($file)) as $token) {
                if (! is_array($token)) {
                    $code .= $token;

                    continue;
                }

                if (! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $code .= $token[1];
                }
            }

            foreach (['WhatsAppMessage', 'whatsapp_messages', 'Order::', 'Customer::'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $code,
                    basename($file).' يمسّ '.$forbidden.' — والحدُّ بين اللوحتين لا يُعبر',
                );
            }
        }
    }
    /**
     * وكلُّ ما يُبحث فيه أو يُرشَّح به مفهرَس.
     *
     * والمفتاحُ الأجنبيّ ليس فهرسًا: PostgreSQL تُنشئ قيدًا لا فهرسًا،
     * فجدولٌ يُقرأ بـ`where message_id in (…)` يُمسح كاملًا في كلّ فتحة.
     * ويعمل صامتًا حتّى يكبر.
     */
    public function test_the_columns_that_are_searched_are_indexed(): void
    {
        $expected = [
            'support_conversations' => ['status', 'business_id', 'assigned_to', 'channel', 'reference'],
            'support_messages' => ['conversation_id'],
            'support_attachments' => ['message_id'],
            'support_reads' => ['conversation_id'],
        ];

        foreach ($expected as $table => $columns) {
            $indexed = collect(\Illuminate\Support\Facades\Schema::getIndexes($table))
                ->pluck('columns')->flatten()->unique()->all();

            foreach ($columns as $column) {
                $this->assertContains(
                    $column,
                    $indexed,
                    "{$table}.{$column} يُرشَّح به ولا فهرسَ عليه",
                );
            }
        }
    }

    /* ═══════════════ النسخةُ الاحتياطيّة ═══════════════ */

    /**
     * ومحادثاتُ الدعم لا تخرج في ملفٍّ يُنزّله التاجر.
     *
     * فيها `is_internal`: ملاحظاتُ فريق افنت بلس عنه هو. وملفٌّ ينزل على جهازه
     * ويحملها ليس نسخةً احتياطيّة بل تسريب.
     */
    public function test_support_tables_are_excluded_from_a_shops_backup(): void
    {
        foreach (['support_conversations', 'support_messages', 'support_attachments', 'support_reads'] as $table) {
            $this->assertNotContains(
                $table,
                TenantTables::ORDER,
                "{$table} يخرج في نسخة التاجر",
            );
            $this->assertArrayHasKey(
                $table,
                TenantTables::NOT_MINE,
                "{$table} غيرُ مصنَّفٍ — لا يُنسخ ولا يُستثنى بسبب",
            );
        }
    }

    /* ═══════════════ ما لم يُبنَ ═══════════════ */

    /**
     * ولا زرَّ «تذكرة تقنية» يُعرض قبل أن يُبنى نظامُها.
     *
     * مقبضٌ لا يُدير شيئًا أسوأ من غياب المقبض: من يضغطه ينتظر تذكرةً لا
     * تُخلق، ويظنّ بلاغَه مسجَّلًا وقد ضاع.
     */
    public function test_no_ticket_button_is_shown_because_no_ticket_system_exists(): void
    {
        $screen = file_get_contents(resource_path('js/Pages/Platform/Conversations/Index.tsx'));

        $this->assertStringNotContainsString('تذكرة', $screen);
        $this->assertFalse(
            Schema::hasTable('support_tickets'),
            'إن بُني جدولُ التذاكر فليُوصَل الزرُّ — لا أن يُترك هذا الحارس',
        );
    }

    /** والخيطُ يبقى كاملًا: حذفُ المحادثة يحذف رسائلها ومرفقاتها ولا شيء غيرها */
    public function test_deleting_a_conversation_takes_its_messages_with_it(): void
    {
        Storage::fake('local');

        $c = $this->conversation();
        Support::attach($c->messages()->first(), UploadedFile::fake()->image('a.png'));

        $this->assertSame(1, SupportMessage::count());
        $this->assertSame(1, SupportAttachment::count());

        $c->delete();

        $this->assertSame(0, SupportMessage::count());
        $this->assertSame(0, SupportAttachment::count());
        $this->assertDatabaseHas('businesses', ['id' => $this->shop->id]);
    }
}
