<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Support\Support;
use App\Support\SupportWhatsApp;
use App\Support\WhatsAppMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * واتسابُ الدعم — ما يُقرأ منه، وما لا يُقرأ أبدًا، وما لا يخرج.
 *
 * ═══ أثقلُ ما هنا حارسان ═══
 *
 * **الأوّل**: الرقمُ المشترك يُرسل إشعاراتِ الطلبات نيابةً عن المحلّات،
 * فيردّ عليه زبائنُهم. ولو خُزّن ردُّ زبونٍ لَقرأه مديرُ المنصّة في لوحته:
 * ما كتبته زبونةٌ لمحلّ ورودٍ عن هديّةٍ لزوجها. فلا يُخزَّن واردٌ إلّا من
 * رقمٍ يُطابق مستخدمًا **واحدًا** له متجر.
 *
 * **والثاني**: الملاحظةُ الداخليّة لا تخرج إلى هاتف التاجر. وهي قناةٌ
 * جديدة، والشرطُ الذي يحرسها في الشاشة لا يحرسها هنا.
 *
 * ═══ وحالُ التسليم تُقاس ولا تُفترض ═══
 *
 * ميتا تمنع النصّ الحرّ خارج نافذة الأربعِ والعشرين ساعة. فردٌّ يُكتب في
 * المركز قد لا يخرج — ويُقال إنّه لم يخرج، لا يُصمَت عنه فيُظنّ واصلًا.
 */
class EventPlusAnswersOnWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private User $staff;

    private WhatsAppConnection $line;

    protected function setUp(): void
    {
        parent::setUp();

        config(['whatsapp.app_secret' => 'test-app-secret']);
        Http::preventStrayRequests();

        $this->shop = $this->shop('محل الورد');
        $this->owner = $this->person($this->shop->id, 'admin', 'صاحب المحل', '96891112222');
        $this->staff = $this->person(null, 'super_admin', 'دعم افنت بلس');

        $this->line = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'EVENTPLUS-PN',
            'display_phone_number' => '+968 7114 1624',
            'access_token' => 'platform-token-value-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
            'supports_inbox' => true,
        ]);
    }

    /* ═══════════════ أدوات ═══════════════ */

    private function shop(string $name): Business
    {
        return Business::create([
            'name' => $name, 'type' => 'محل ورود', 'status' => 'نشط', 'city' => 'مسقط',
        ]);
    }

    private function person(?int $businessId, string $role, string $name = 'مستخدم', ?string $phone = null): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'business_id' => $businessId, 'name' => $name, 'email' => "wa{$n}@eventplus.om",
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط', 'phone' => $phone,
        ]);
    }
    /** محادثةُ واتسابٍ نافذتُها مفتوحة */
    private function live(): SupportConversation
    {
        $this->inbound()->assertOk();

        return SupportConversation::firstOrFail();
    }

    /* ═══════════════ الحدُّ الذي لا يُعبر ═══════════════ */

    /**
     * ردُّ زبونٍ على إشعارِ طلبه لا يصير محادثةَ دعم.
     *
     * وهو أثقلُ حارسٍ في هذا الملفّ: الرقمُ يُرسل نيابةً عن المحلّات، وأكثرُ
     * ما يصله ردودُ زبائنِهم. وخزنُ واحدةٍ منها يعني أن يقرأ مديرُ المنصّة
     * ما كتبته زبونةٌ لمحلِّها.
     */
    public function test_a_customer_reply_is_not_stored_at_all(): void
    {
        Customer::create([
            'business_id' => $this->shop->id, 'name' => 'زبونة', 'phone' => '96895556666',
        ]);

        $this->inbound(from: '96895556666', text: 'وين وصل الطلب؟')->assertOk();

        $this->assertSame(0, SupportConversation::count(), 'رسالةُ زبونٍ صارت محادثةَ دعم');
        $this->assertSame(0, SupportMessage::count(), 'نصُّ زبونٍ خُزّن في جداول الدعم');
    }

    /** ورقمٌ لا يعرفه النظامُ أصلًا يُسقَط */
    public function test_an_unknown_number_is_dropped(): void
    {
        $this->inbound(from: '96899998888')->assertOk();

        $this->assertSame(0, SupportConversation::count());
    }

    /**
     * ورقمٌ يُطابق اثنين لا يُنسب لأحدهما.
     *
     * نسبتُه بالحدس تعني محادثةً تُفتح باسم متجرٍ لم يكتبها أحدٌ فيه.
     */
    public function test_an_ambiguous_number_is_dropped(): void
    {
        $other = $this->shop('محل آخر');
        $this->person($other->id, 'admin', 'صاحب الآخر', '+968 9111 2222');

        $this->inbound()->assertOk();

        $this->assertSame(0, SupportConversation::count(), 'رقمٌ مكرَّرٌ نُسب لمتجرٍ بالحدس');
    }

    /** والبابُ مغلقٌ ما لم يُؤذن له صراحةً */
    public function test_nothing_is_read_while_the_inbox_is_closed(): void
    {
        $this->line->update(['supports_inbox' => false]);

        $this->inbound()->assertOk();

        $this->assertSame(0, SupportConversation::count(), 'قُرئ الواردُ على رقمٍ لم يُفتح بابُه');
    }

    /** وهو مغلقٌ في الهجرة — ترقيةٌ لا تفتح بابًا لم يطلبه أحد */
    public function test_the_inbox_is_closed_by_default(): void
    {
        $fresh = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'OTHER-PN',
            'access_token' => 'token-value-0123456789012',
            'status' => WhatsAppConnection::ACTIVE,
        ]);

        $this->assertFalse((bool) $fresh->fresh()->supports_inbox);
    }
    /* ═══════════════ الوارد يُقرأ ═══════════════ */

    public function test_a_shop_owner_message_opens_a_conversation(): void
    {
        $this->inbound()->assertOk();

        $c = SupportConversation::firstOrFail();

        $this->assertSame($this->shop->id, $c->business_id);
        $this->assertSame($this->owner->id, $c->opened_by);
        $this->assertSame('whatsapp', $c->channel);
        $this->assertSame('96891112222', $c->contact_phone);
        $this->assertSame('new', $c->status);
        $this->assertStringStartsWith('SUP-', $c->reference);
        $this->assertSame('الفاتورة لا تُطبع عندي', $c->messages()->first()->body);
        $this->assertSame('business', $c->messages()->first()->sender_scope);
    }

    /** ورسالةٌ ثانيةٌ تُكتب في الخيط نفسِه لا في خيطٍ جديد */
    public function test_a_second_message_joins_the_same_thread(): void
    {
        $this->inbound()->assertOk();
        $this->inbound(text: 'وأيضًا الطباعة بطيئة', wamid: 'wamid.IN-2')->assertOk();

        $this->assertSame(1, SupportConversation::count(), 'كلُّ رسالةٍ فتحت خيطًا');
        $this->assertSame(2, SupportMessage::whereNull('event')->count());
        $this->assertSame('waiting_eventplus', SupportConversation::first()->status);
    }

    /**
     * وإشعارٌ وصل مرّتين يُكتب مرّة.
     *
     * ميتا تُعيد الإشعارَ حين لا تصلها ٢٠٠ في الوقت. وبلا هذا يقرأ التاجرُ
     * رسالتَه مكرَّرةً في خيطه ويظنّ العطبَ في يده.
     */
    public function test_a_repeated_webhook_writes_one_message(): void
    {
        $this->inbound()->assertOk();
        $this->inbound()->assertOk();

        $this->assertSame(1, SupportMessage::whereNull('event')->count());
        $this->assertSame(1, SupportConversation::count());
    }

    /** وخيطٌ حُلّ قبل شهرٍ لا يُبعث برسالةٍ عن شيءٍ آخر */
    public function test_an_old_resolved_thread_is_not_revived(): void
    {
        $c = $this->live();
        $c->forceFill([
            'status' => 'resolved',
            'resolved_at' => now()->subMonth(),
            'last_message_at' => now()->subMonth(),
        ])->save();

        $this->inbound(text: 'سؤالٌ آخر تمامًا', wamid: 'wamid.IN-9')->assertOk();

        $this->assertSame(2, SupportConversation::count(), 'خيطٌ قديمٌ بُعث بدل أن يُفتح جديد');
        $this->assertSame('resolved', $c->fresh()->status, 'تبدّلت حالُ خيطٍ محلولٍ من رسالةٍ لا تخصّه');
    }

    /** وخيطٌ حُلّ للتوّ يُعاد فتحُه — من ردّ في دقيقته يتكلّم عن الشيء نفسِه */
    public function test_a_just_resolved_thread_reopens(): void
    {
        $c = $this->live();
        $c->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();

        $this->inbound(text: 'ما زالت المشكلة', wamid: 'wamid.IN-3')->assertOk();

        $this->assertSame(1, SupportConversation::count());
        $this->assertSame('waiting_eventplus', $c->fresh()->status);
    }

    /**
     * وواردٌ غيرُ نصّيٍّ يُكتب بما هو — لا يُسقَط ولا يُكتب فارغًا.
     *
     * تاجرٌ أرسل صورةً ولم يردّ عليه أحد يظنّ الدعمَ لا يقرأ؛ وسطرٌ أبيضُ في
     * الخيط لا يقول شيئًا لمن يفتحه.
     */
    public function test_a_non_text_message_is_recorded_as_what_it_is(): void
    {
        $this->inbound(message: [
            'id' => 'wamid.IMG', 'from' => '96891112222', 'type' => 'image',
            'image' => ['id' => 'media-1', 'mime_type' => 'image/jpeg'],
        ])->assertOk();

        $body = (string) SupportMessage::whereNull('event')->first()->body;

        $this->assertNotSame('', trim($body), 'وارِدٌ غيرُ نصّيٍّ كُتب سطرًا فارغًا');
        $this->assertStringContainsString('صورة', $body);
    }
    /**
     * والحارسُ في `deliver` نفسِها — لا في المتحكّم وحده.
     *
     * المتحكّمُ يخرج مبكّرًا عند الملاحظة، فلو كان ذلك حارسَها الوحيد لَبقي
     * فحصُ `is_internal` ميتًا: يومَ يُنادى `deliver` من طابورٍ أو مهمّةٍ
     * أو قناةٍ ثانية يخرج كلامُ الفريق إلى هاتف التاجر ولا شيء يمنعه.
     *
     * فتُنادى الدالّةُ هنا مباشرةً بملاحظةٍ داخليّة، وكلُّ شرطٍ آخرَ يقول
     * «أرسِل»: الخطُّ موصولٌ والنافذةُ مفتوحةٌ والرقمُ محفوظ.
     */
    public function test_deliver_itself_refuses_an_internal_note(): void
    {
        $c = $this->live();

        $this->assertTrue(SupportWhatsApp::windowOpen($c), 'شرطُ النافذة لم يتحقّق فالطفرةُ لا تُقاس');
        $this->assertTrue(SupportWhatsApp::connected(), 'الخطُّ غيرُ موصولٍ فالطفرةُ لا تُقاس');

        $note = Support::say($c, $this->staff, 'platform', 'لا تَعِده بشيء', internal: true);

        SupportWhatsApp::deliver($c->fresh(), $note);

        Http::assertNothingSent();
        $this->assertNull($note->fresh()->delivery);
    }

    /**
     * والنافذةُ تُقرأ من ختم الوارد لا من آخر حركةٍ في الخيط.
     *
     * `last_message_at` يتحرّك بردّ افنت بلسٍ أيضًا. ولو حُسبت النافذةُ منه
     * لَقالت الشاشةُ «مفتوحة» لأنّ الدعمَ كتب — ثمّ تردّ ميتا الرسالة.
     */
    public function test_the_window_is_read_from_the_inbound_stamp_only(): void
    {
        $c = $this->live();

        $c->forceFill([
            'whatsapp_window_at' => now()->subHours(30),
            'last_message_at' => now(),
        ])->save();

        $this->assertFalse(
            SupportWhatsApp::windowOpen($c->fresh()),
            'حُسبت النافذةُ من آخر حركةٍ في الخيط لا من ختم الوارد',
        );
    }
    /** والنافذةُ تُقاس من الوارد وحده — لا من آخر رسالةٍ في الخيط */
    public function test_an_eventplus_reply_does_not_reopen_metas_window(): void
    {
        $c = $this->live();
        $c->forceFill(['whatsapp_window_at' => now()->subHours(30)])->save();

        Support::say($c->fresh(), $this->staff, 'platform', 'ردٌّ متأخّر');

        $this->assertFalse(SupportWhatsApp::windowOpen($c->fresh()), 'ردُّ افنت بلسٍ فتح نافذةَ ميتا');
    }
    /**
     * وعددُ من يستطيع أن يصل — محسوبٌ لا موعود.
     *
     * القناةُ تُشعَل ثمّ لا يصل شيء فيُظنّ العطبُ في الربط، والسببُ أنّ
     * أصحابَ المتاجر لم يكتبوا أرقامهم.
     */
    public function test_the_reach_is_counted_honestly(): void
    {
        $other = $this->shop('محل ثانٍ');
        $this->person($other->id, 'admin', 'بلا رقم');
        $twinShop = $this->shop('محل ثالث');
        $this->person($twinShop->id, 'admin', 'توأم', '96897777777');
        $this->person($this->shop->id, 'sales', 'توأم آخر', '+968 9777 7777');

        $reach = SupportWhatsApp::reach();

        $this->assertSame(4, $reach['total']);
        $this->assertSame(1, $reach['reachable'], 'عُدّ من لا يُطابَق قابلًا للوصول');
        $this->assertSame(1, $reach['ambiguous']);
    }
}
