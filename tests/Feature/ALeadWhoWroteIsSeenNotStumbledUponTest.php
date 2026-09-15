<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\CrmRead;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Support\Crm;
use App\Support\CrmWhatsApp;
use App\Support\WhatsAppMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * من كتب إلينا يُرى — لا يُعثر عليه بالصدفة.
 *
 * ═══ العطب ═══
 *
 * تاجرٌ يكتب إلى رقمنا يُضيء شارةً في الشريط الجانبيّ. وعميلٌ محتمَلٌ يكتب
 * إلى **الرقم نفسِه** لا يُضيء شيئًا: تُكتب رسالتُه في `crm_messages`
 * وتنتظر، ولا يعرف بها أحدٌ حتّى يفتح «CRM ‹ المحادثات» بمحض الصدفة.
 *
 * ومن جاء ليشتري وانتظر يومًا بلا ردّ يذهب إلى غيرنا. وهذا أغلى صمتٍ في
 * النظام: صمتٌ يُكلّف مشتركًا.
 *
 * ═══ وما تحرسه هذه الحرّاس ═══
 *
 * أنّ الشارة **تُضيء** حين يكتب، وأنّها **تنطفئ** حين يُقرأ — والثانيةُ
 * أهمّ: شارةٌ لا تُطفأ تصير جزءًا من الأثاث فلا تقع عليها عين، ثمّ لا
 * تُقرأ حين تعني شيئًا.
 */
class ALeadWhoWroteIsSeenNotStumbledUponTest extends TestCase
{
    use RefreshDatabase;

    private User $sales;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Http::preventStrayRequests();

        $this->sales = User::create([
            'name' => 'موظّف مبيعات', 'email' => 's@eventplus.om', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'status' => 'active', 'business_id' => null,
        ]);

        $this->colleague = User::create([
            'name' => 'زميل', 'email' => 'c@eventplus.om', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'status' => 'active', 'business_id' => null,
        ]);

        Setting::updateOrCreate(['business_id' => null, 'key' => 'crm_whatsapp_shared'], ['value' => '1']);

        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'phone_number_id' => 'PN', 'display_phone_number' => '+968 7114 1624',
            'access_token' => 'tok-0123456789abcdef',
            'status' => WhatsAppConnection::ACTIVE, 'supports_inbox' => true,
        ]);
    }

    private function lead(string $phone = '96877778888', array $attrs = []): CrmLead
    {
        return CrmLead::create(array_merge([
            'phone' => $phone, 'phone_raw' => $phone, 'name' => 'عميل',
            'source' => Crm::SOURCE_WHATSAPP, 'status' => Crm::ACTIVE, 'stage' => Crm::NEW,
            'last_contact_at' => now(),
        ], $attrs));
    }

    private function wrote(CrmLead $lead, string $body = 'كم سعر افنت بلس؟'): CrmMessage
    {
        return CrmMessage::create([
            'lead_id' => $lead->id, 'direction' => CrmMessage::IN, 'body' => $body,
            'external_message_id' => 'wamid.'.uniqid('', true),
        ]);
    }

    /* ═════════════ تُضيء حين يكتب ═════════════ */

    /** رسالةٌ من عميلٍ تُضيء الشارة */
    public function test_a_lead_who_writes_lights_the_badge(): void
    {
        $this->assertSame(0, CrmWhatsApp::badge($this->sales), 'أضاءت الشارةُ ولا رسالة');

        $this->wrote($this->lead());

        $this->assertSame(1, CrmWhatsApp::badge($this->sales));
    }

    /**
     * وثلاثةُ أسطرٍ من عميلٍ واحد شيءٌ واحد ينتظر.
     *
     * ورقمٌ يقفز إلى «٢٣» لأنّ ثلاثةً أسهبوا يُقرأ ضجيجًا فيُهمَل.
     */
    public function test_three_lines_from_one_lead_are_one_thing_waiting(): void
    {
        $lead = $this->lead();
        $this->wrote($lead, 'مرحبا');
        $this->wrote($lead, 'عندي محل');
        $this->wrote($lead, 'كم السعر؟');

        $this->assertSame(1, CrmWhatsApp::badge($this->sales));
    }

    /** وعميلان يكتبان شيئان */
    public function test_two_leads_are_two(): void
    {
        $this->wrote($this->lead('96877778888'));
        $this->wrote($this->lead('96877779999'));

        $this->assertSame(2, CrmWhatsApp::badge($this->sales));
    }

    /**
     * وردُّنا نحن لا يُضيء شيئًا.
     *
     * ولو عُدّ لَبقيت الشارةُ مضيئةً بعد أن يردّ الموظّف بنفسه.
     */
    public function test_our_own_reply_lights_nothing(): void
    {
        $lead = $this->lead();

        CrmMessage::create([
            'lead_id' => $lead->id, 'direction' => CrmMessage::OUT, 'body' => 'أهلًا',
        ]);

        $this->assertSame(0, CrmWhatsApp::badge($this->sales));
    }

    /**
     * ومن انتهى أمرُه لا يُضيء.
     *
     * من رُبح أو خُسر أُغلق بابُه، ورسالةٌ متأخّرةٌ منه لا تُعيد فتحَ
     * الشارة على من لا يُنتظر منه قرار.
     */
    public function test_a_finished_lead_lights_nothing(): void
    {
        $this->wrote($this->lead('96877778888', ['status' => Crm::CONVERTED]));

        $this->assertSame(0, CrmWhatsApp::badge($this->sales));
    }
    /* ═════════════ ولا تاجرَ يقرأ دفترَ مبيعاتنا ═════════════ */

    /** وشارةُ المبيعات صفرٌ لمن ليس من المنصّة */
    public function test_a_merchant_never_carries_our_sales_badge(): void
    {
        $business = Business::create([
            'name' => 'محل', 'type' => 'عام', 'status' => 'active',
        ]);

        $merchant = User::create([
            'name' => 'تاجر', 'email' => 'm@shop.om', 'password' => bcrypt('x'),
            'role' => 'admin', 'status' => 'active', 'business_id' => $business->id,
        ]);

        $this->wrote($this->lead());

        $this->assertSame(0, CrmWhatsApp::badge($merchant));
    }
}
