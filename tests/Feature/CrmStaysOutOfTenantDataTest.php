<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CRM لا يبلغ ما بين التاجر وزبائنه — لا في الشاشة ولا في المصدر.
 *
 * ═══ لمَ هذا أخطرُ حارسٍ في القسم ═══
 *
 * رقمُ افنت بلسٍ المشترك يُرسل إشعاراتِ الطلبات **نيابةً عن المحلّات**، فيردّ
 * عليه زبائنُهم: «وصل؟»، «غيّر العنوان». فلو صار كلُّ رقمٍ مجهولٍ يكتب إلينا
 * «عميلًا محتملًا»، لَصارت زبونةُ محلِّ ورودٍ سألت عن هديّةٍ لزوجها صفًّا في
 * دفتر مبيعاتنا — ونصُّ رسالتها مقروءًا في لوحة المنصّة.
 *
 * والحارسُ هنا على طبقتين: الحمولةُ التي تصل المتصفّح فعلًا، والمصدرُ نفسُه.
 * الأولى تشهد على اليوم، والثانية تمنع سطرًا يُكتب بعد سنة.
 */
class CrmStaysOutOfTenantDataTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Business $shop;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'سالم', 'email' => 'salem@eventplus.om',
            'password' => bcrypt('secret'), 'role' => 'super_admin', 'status' => 'active',
        ]);

        $this->shop = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'active']);

        /* زبونةٌ من زبائن المحلّ — ورقمُها هو ما لا يجوز أن يصير عميلًا محتملًا */
        $this->customer = Customer::create([
            'business_id' => $this->shop->id,
            'name' => 'مريم',
            'phone' => '97778888',
        ]);
    }
    /**
     * القراءةُ الوحيدةُ المسموحة في دفتر الإرسال — بحرفها.
     *
     * وتُكتب هنا لا في الملفّ: نسختان تفترقان يومًا، فتتّسع إحداهما ويبقى
     * الحارسُ يحرس الأخرى.
     */
    private const SEND_LOG_READ = "WhatsAppMessage::where('recipient_phone', \$phone)->exists()";
    /**
     * ولا ملفَّ CRM يبقى خارج القائمة أعلاه.
     *
     * «قائمةٌ تُكتب باليد تنسى التاليَ دائمًا»: يُضاف ملفٌّ إلى القسم بعد
     * شهرٍ فلا يُدرَج، فيمرّ فيه `Customer::` صامتًا. فيُقرأ ما في المجلّدات
     * ويُقارَن بما يُفحص.
     */
    public function test_no_crm_file_escapes_the_source_guard(): void
    {
        $guarded = array_map('basename', $this->guardedFiles());
        $found = [];

        foreach ([app_path('Support'), app_path('Models'), app_path('Http/Controllers/SuperAdmin')] as $dir) {
            foreach (scandir($dir) ?: [] as $name) {
                if (str_starts_with($name, 'Crm') && str_ends_with($name, '.php')) {
                    $found[] = $name;
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_diff($found, $guarded)),
            'ملفُّ CRM خارج حارس المصدر — أضِفه إلى `guardedFiles`'
        );
    }
    /**
     * حمولةُ Inertia — من **جسم** الوسم لا من خاصيّته.
     *
     * والعربيّةُ داخلها مهروبةٌ `\uXXXX`، فالبحثُ في النصّ الخام لا يجد شيئًا
     * ويُظنّ الحارسُ قد شهد. فتُفكّ أوّلًا.
     */
    private function inertiaPayload(string $html): string
    {
        preg_match('/data-page="app" type="application\/json">(.*?)<\/script>/s', $html, $m);

        $this->assertNotEmpty($m[1] ?? '', 'لم تُقرأ حمولةُ الصفحة — تغيّر شكلُ الوسم');

        $decoded = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

        return json_encode($decoded, JSON_UNESCAPED_UNICODE) ?: '';
    }
}
