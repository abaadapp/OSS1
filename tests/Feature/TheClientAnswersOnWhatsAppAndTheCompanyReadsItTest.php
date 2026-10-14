<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Communication;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Support\Messaging\MessageIntent;
use App\Support\Messaging\MessageResult;
use App\Support\WhatsApp\Inbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * العميلُ يردّ على واتساب — والشركةُ تقرأ وتجيب.
 *
 * ما يكتبه العميلُ يُكتب مرّةً في محادثة رقمه، ويُربط بعميله إن عُرف،
 * ويصل إلى شركة الرقم وحدَها. والردُّ نصٌّ حرٌّ داخل يوم آخر رسالةٍ منه،
 * ويُرفض بعده بسببه. وقالبُ المستند يظهر في المحادثة، وحالُه تتقدّم ولا
 * تتراجع.
 */
class TheClientAnswersOnWhatsAppAndTheCompanyReadsItTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@hadath.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة الطاقة', 'phone' => '+968 9123 4567']);

        config(['services.whatsapp.token' => 'SECRET_TOKEN_X', 'services.whatsapp.phone_number_id' => '1050000',
            'services.whatsapp.app_secret' => 'wa-app-secret', 'services.whatsapp.business_id' => null]);
    }

    /** نداءُ Meta موقَّعًا — `value` كما تكتبه */
    private function meta(array $value)
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => ['messaging_product' => 'whatsapp',
                'metadata' => ['phone_number_id' => '1050000']] + $value]],
        ]]]);

        return $this->call('POST', route('webhooks.whatsapp'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'wa-app-secret'),
        ], $body);
    }

    private function inbound(string $id, string $text, string $from = '96891234567', ?int $at = null): array
    {
        return [
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'سالم']]],
            'messages' => [['from' => $from, 'id' => $id, 'timestamp' => (string) ($at ?? now()->timestamp), 'type' => 'text', 'text' => ['body' => $text]]],
        ];
    }

    public function test_a_client_message_lands_once_in_the_conversation_of_their_number_linked_to_them(): void
    {
        $this->meta($this->inbound('wamid.1', 'السلام عليكم، متى يصل العرض؟'))->assertOk();
        /* Meta تعيد ما لم يُجَب — والثاني لا أثرَ له */
        $this->meta($this->inbound('wamid.1', 'السلام عليكم، متى يصل العرض؟'))->assertOk();

        $c = WhatsAppConversation::sole();
        $this->assertSame($this->business->id, $c->business_id);
        $this->assertSame('96891234567', $c->wa_id);
        $this->assertSame($this->customer->id, $c->customer_id, 'رقمُه رقمُ العميل — وإن كُتب بمسافاتٍ ومفتاح');
        $this->assertSame('سالم', $c->contact_name);
        $this->assertSame(1, $c->unread_count);
        $this->assertTrue($c->windowOpen());
        $this->assertSame('السلام عليكم، متى يصل العرض؟', $c->last_preview);

        $m = WhatsAppMessage::sole();
        $this->assertSame(Communication::INBOUND, $m->direction);
        $this->assertSame('text', $m->type);
    }

    public function test_media_is_kept_by_its_id_and_fetched_once_from_meta(): void
    {
        Storage::fake('local');
        Http::fake([
            'graph.facebook.com/v21.0/MEDIA1' => Http::response(['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => 'image/jpeg']),
            'lookaside.fbsbx.com/*' => Http::response('JPEGBYTES', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->meta(['messages' => [['from' => '96891234567', 'id' => 'wamid.img', 'timestamp' => (string) now()->timestamp,
            'type' => 'image', 'image' => ['id' => 'MEDIA1', 'mime_type' => 'image/jpeg', 'caption' => 'موقع المسرح']]]])->assertOk();

        $m = WhatsAppMessage::sole();
        $this->assertSame('موقع المسرح', $m->body);
        $this->assertSame('📷 صورة موقع المسرح', WhatsAppConversation::sole()->last_preview);

        $this->actingAs($this->owner)->get(route('admin.whatsapp.media', $m->id))->assertOk();
        $this->actingAs($this->owner)->get(route('admin.whatsapp.media', $m->id))->assertOk();

        Http::assertSentCount(2);
        $this->assertNotNull($m->fresh()->media_path);
    }

    public function test_the_company_replies_within_a_day_and_is_refused_after_it(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out1']]])]);

        $this->meta($this->inbound('wamid.1', 'مرحبا'))->assertOk();
        $c = WhatsAppConversation::sole();

        $this->actingAs($this->owner)->post(route('admin.whatsapp.reply', $c->id), ['body' => 'أهلًا، يصلكم اليوم'])->assertRedirect();

        Http::assertSent(fn (ClientRequest $r) => $r['type'] === 'text' && $r['text']['body'] === 'أهلًا، يصلكم اليوم' && $r['to'] === '96891234567');

        $out = WhatsAppMessage::where('direction', Communication::OUTBOUND)->sole();
        $this->assertSame('wamid.out1', $out->provider_message_id);
        $this->assertSame(Communication::SENT, $out->status);
        $this->assertSame('المالك', $out->sent_by_name);
        $this->assertSame(0, Communication::count(), 'الردُّ الحرّ سجلُّه المحادثة — لا قيدَ قالبٍ في المراسلات');

        /* بعد يوم: Meta لا تقبل نصًّا حرًّا — فلا يُطلب منها */
        $this->travel(25)->hours();
        $this->actingAs($this->owner)->post(route('admin.whatsapp.reply', $c->id), ['body' => 'ثانية'])
            ->assertSessionHas('toast', fn ($t) => $t['type'] === 'danger');

        Http::assertSentCount(1);
    }

    public function test_statuses_move_the_bubble_forward_and_never_back(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out1']]])]);
        $this->meta($this->inbound('wamid.1', 'مرحبا'))->assertOk();
        $this->actingAs($this->owner)->post(route('admin.whatsapp.reply', WhatsAppConversation::sole()->id), ['body' => 'أهلًا']);

        $status = fn (string $s, int $ts) => ['statuses' => [['id' => 'wamid.out1', 'status' => $s, 'timestamp' => (string) $ts, 'recipient_id' => '96891234567']]];

        $this->meta($status('read', now()->timestamp))->assertOk();
        $this->meta($status('delivered', now()->timestamp))->assertOk();

        $out = WhatsAppMessage::where('provider_message_id', 'wamid.out1')->sole();
        $this->assertSame(Communication::READ, $out->status);
        $this->assertNotNull($out->read_at);
        $this->assertNotNull($out->delivered_at);
        $this->assertSame(Communication::READ, WhatsAppConversation::sole()->last_status);
    }

    public function test_a_template_sent_from_a_document_appears_in_the_clients_conversation(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.tpl']]])]);

        $communication = Communication::create([
            'business_id' => $this->business->id, 'customer_id' => $this->customer->id, 'channel' => 'whatsapp',
            'direction' => 'outbound', 'recipient' => '96891234567', 'template_key' => 'quotation.link',
            'title' => 'عرض سعر Q-000001', 'provider' => 'meta', 'provider_message_id' => 'wamid.tpl',
            'status' => Communication::SENT, 'queued_at' => now(), 'sent_at' => now(), 'initiated_by_name' => 'المالك',
            'metadata' => ['url' => 'https://hadath.om/q/abc'],
        ]);

        Inbox::mirror($communication);

        $c = WhatsAppConversation::sole();
        $this->assertSame($this->customer->id, $c->customer_id);
        $this->assertSame(0, $c->unread_count);
        $this->assertFalse($c->windowOpen(), 'رسالتُنا لا تفتح النافذة — العميلُ وحدَه يفتحها');

        $m = WhatsAppMessage::sole();
        $this->assertSame('template', $m->type);
        $this->assertSame($communication->id, $m->communication_id);
        $this->assertSame('https://hadath.om/q/abc', $m->metadata['url']);

        /* والخبرُ يمسّ القيدَ والفقاعةَ معًا */
        $this->meta(['statuses' => [['id' => 'wamid.tpl', 'status' => 'delivered', 'timestamp' => (string) now()->timestamp]]])->assertOk();
        $this->assertSame(Communication::DELIVERED, $communication->fresh()->status);
        $this->assertSame(Communication::DELIVERED, $m->fresh()->status);
    }

    public function test_with_several_companies_and_none_named_the_message_is_dropped_not_guessed(): void
    {
        Business::create(['name' => 'شركة أخرى', 'type' => 'عام', 'status' => 'نشط']);

        $this->meta($this->inbound('wamid.1', 'مرحبا'))->assertOk();
        $this->assertSame(0, WhatsAppConversation::count());

        config(['services.whatsapp.business_id' => $this->business->id]);
        $this->meta($this->inbound('wamid.2', 'مرحبا'))->assertOk();
        $this->assertSame($this->business->id, WhatsAppConversation::sole()->business_id);
    }

    public function test_one_company_never_opens_another_companys_conversation(): void
    {
        $this->meta($this->inbound('wamid.1', 'مرحبا'))->assertOk();
        $c = WhatsAppConversation::sole();

        $other = Business::create(['name' => 'شركة أخرى', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $other->id, 'name' => 'الرئيسي']);
        $stranger = User::create(['business_id' => $other->id, 'name' => 'غريب', 'email' => 'x@other.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط']);

        $this->actingAs($stranger)->get(route('admin.whatsapp.index', ['c' => $c->id]))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('thread', null)->where('conversations', []));
        $this->actingAs($stranger)->post(route('admin.whatsapp.reply', $c->id), ['body' => 'x'])->assertNotFound();
        $this->actingAs($stranger)->get(route('admin.whatsapp.media', WhatsAppMessage::sole()->id))->assertNotFound();
    }

    public function test_opening_the_conversation_clears_its_unread_count(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $this->meta($this->inbound('wamid.1', 'مرحبا'))->assertOk();
        $c = WhatsAppConversation::sole();

        $this->actingAs($this->owner)->get(route('admin.whatsapp.index', ['c' => $c->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Admin/WhatsApp/Index')
                ->where('thread.conversation.id', $c->id)
                ->has('thread.messages', 1)
                ->where('conversations.0.unread_count', 0));

        $this->assertSame(0, $c->fresh()->unread_count);
        Http::assertSent(fn (ClientRequest $r) => ($r->data()['status'] ?? null) === 'read' && ($r->data()['message_id'] ?? null) === 'wamid.1');
    }

    public function test_a_seller_without_the_communications_section_is_kept_out(): void
    {
        $seller = User::create(['business_id' => $this->business->id, 'name' => 'البائع', 'email' => 's@hadath.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط']);

        $this->actingAs($seller)->get(route('admin.whatsapp.index'))->assertForbidden();
    }
}
