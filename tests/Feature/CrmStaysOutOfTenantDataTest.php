<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\SupportConversation;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Support\CrmLeads;
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
     * ولا سطرَ في مصدر CRM يمسّ جدولًا تحت `business_id`.
     *
     * ═══ ولمَ يُفحص المصدر ═══
     *
     * فحصُ الحمولة يشهد على ما يُرسل اليوم. وهذا يشهد على الغد: من يكتب بعد
     * سنةٍ `Customer::where(...)` في متحكّم CRM ليعرض «عملاء المتجر» يسقط
     * هنا — لا يوم يشكو تاجر.
     */
    /** @return list<string> ما يُفحص — ومصدرٌ واحد يقرؤه الحارسان */
    private function guardedFiles(): array
    {
        return [
            base_path('app/Http/Controllers/SuperAdmin/CrmController.php'),
            base_path('app/Support/CrmLeads.php'),
            base_path('app/Support/Crm.php'),
            base_path('app/Models/CrmLead.php'),
            base_path('app/Models/CrmNote.php'),
            base_path('app/Models/CrmTask.php'),
            base_path('app/Models/CrmStageEvent.php'),
            /*
             * وقناةُ واتساب معها — وهي أخطرُها.
             *
             * هنا يُقرأ واردٌ من رقمٍ **مجهول**، فسطرٌ يقرأ `Customer::`
             * ليخمّن «من هذا؟» يفتح جدولَ زبائن التجّار كلِّهم على دفتر
             * مبيعاتنا. والقائمةُ تُوسَّع مع كلّ ملفٍّ يُضاف إلى القسم —
             * وإلّا حرست ما كان ولا تحرس ما يُكتب.
             */
            base_path('app/Support/CrmWhatsApp.php'),
            base_path('app/Http/Controllers/SuperAdmin/CrmConversationController.php'),
            base_path('app/Models/CrmMessage.php'),
            base_path('app/Models/CrmRead.php'),
            base_path('app/Models/CrmAttachment.php'),
            base_path('app/Http/Controllers/SuperAdmin/CrmAttachmentController.php'),
            /*
             * والمساعدُ الذكيّ ومعرفتُه وإشاراتُه.
             *
             * وأخطرُها `CrmKnowledge`: ما فيها يُرسَل إلى مزوّدٍ خارجيّ. فسطرٌ
             * يقرأ `Customer::` ليُخبر النموذجَ «عن عملائنا» يُخرج أسماءَ
             * زبائنِ التجّار من الخادم إلى شركةٍ أخرى.
             */
            base_path('app/Support/CrmAssistant.php'),
            base_path('app/Support/CrmKnowledge.php'),
            base_path('app/Support/CrmSignals.php'),
            base_path('app/Models/CrmAiFeedback.php'),
        ];
    }

    public function test_no_line_of_crm_source_touches_a_tenant_table(): void
    {
        $files = $this->guardedFiles();

        /* نماذجُ التاجر وزبائنه — ما لا يُذكر اسمُه في هذا القسم */
        $forbidden = [
            'Customer::', 'Order::', 'OrderItem::', 'Product::', 'Invoice::',
            'CustomerInvoice::', 'WhatsAppMessage::', 'SupportMessage::',
            'SupportConversation::', 'PointTransaction::', 'CustomerAddress::',
        ];

        foreach ($files as $path) {
            $source = file_get_contents($path);

            /*
             * ═══ واستثناءٌ واحدٌ مكتوبٌ بنصّه — لا فئةٌ مفتوحة ═══
             *
             * حين صار رقمُ الإشعارات يحمل دفترَ المبيعات معه (بإذن)، احتاج
             * `CrmWhatsApp` أن يعرف: **أأرسلنا إلى هذا الرقم إشعارَ طلبٍ
             * يومًا؟** فمن أرسلنا إليه زبونُ محلٍّ يردّ على إشعاره، ولا يُكتب
             * في دفترنا.
             *
             * وهذه القراءةُ عكسُ ما يحرسه هذا الاختبار: لا تُدخل بيانَ تاجرٍ
             * إلى CRM، بل تمنع دخولَ زبونه. ولا تقرأ نصًّا ولا طلبًا ولا
             * اسمًا — عمودَ هاتفٍ واحدًا، وتردُّ `true`/`false`.
             *
             * فيُسمح بهذا السطر **بحرفه** لا بالنموذج كلِّه: أيُّ استعمالٍ
             * آخر لـ`WhatsAppMessage` في ملفّات CRM يسقط هنا كما كان. ويحرس
             * `test_the_send_log_exception_stays_a_single_read` أن يبقى
             * واحدًا.
             */
            $source = str_replace(self::SEND_LOG_READ, '', $source);

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $source,
                    'مصدرُ CRM يمسّ جدولَ تاجر — '.basename($path).' يذكر '.$needle
                );
            }
        }
    }

    /**
     * القراءةُ الوحيدةُ المسموحة في دفتر الإرسال — بحرفها.
     *
     * وتُكتب هنا لا في الملفّ: نسختان تفترقان يومًا، فتتّسع إحداهما ويبقى
     * الحارسُ يحرس الأخرى.
     */
    private const SEND_LOG_READ = "WhatsAppMessage::where('recipient_phone', \$phone)->exists()";

    /**
     * والاستثناءُ يبقى قراءةً واحدةً — لا بابًا يتّسع.
     *
     * «بابٌ يُفتح شبرًا يُفتح ذراعًا»: من يكتب بعد سنةٍ قراءةً ثانيةً في
     * `whatsapp_messages` — «آخرُ طلبٍ له»، «كم اشترى» — يُدخل دفترَ تاجرٍ
     * إلى دفتر مبيعاتنا. فيُعدّ العددُ ويُقارن.
     */
    public function test_the_send_log_exception_stays_a_single_read(): void
    {
        $path = base_path('app/Support/CrmWhatsApp.php');
        $source = file_get_contents($path);

        $this->assertSame(
            1,
            substr_count($source, self::SEND_LOG_READ),
            'القراءةُ المسموحةُ في دفتر الإرسال تغيّرت أو تكرّرت — راجع الاستثناء'
        );

        /* ولا ذكرَ آخرَ للنموذج: الاستيرادُ وهذه القراءةُ وحدَهما */
        $this->assertSame(
            1,
            substr_count(str_replace('use App\Models\WhatsAppMessage;', 'IMPORT', $source), 'WhatsAppMessage'),
            'ذُكر `WhatsAppMessage` في CRM أكثرَ من القراءةِ المأذونة'
        );
    }

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
