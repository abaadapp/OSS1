<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
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
}
