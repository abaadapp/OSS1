<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Communication;
use App\Models\CommunicationEvent;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المزوّدُ يقول ما صار إلى الرسالة — ونحن نكتبه مرّةً.
 *
 * ═══ Postmark وMeta من بابٍ واحد ═══
 *
 * كلٌّ بلغته وبقفله: Postmark بسرٍّ في هويّة العنوان، وMeta بتوقيع HMAC
 * على الجسد. وما يمرّ يُترجَم إلى الحالات الستّ ويُكتب خبرًا بمعرّفه —
 * فالنداءُ المكرَّر يُردّ بلا أثر، والحالُ لا تتراجع، وما يخصّ متجرًا لا
 * يمسّ متجرًا.
 */
class TheProviderTellsUsWhatBecameOfTheMessageTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة']);

        config(['services.postmark.webhook_secret' => 'pm-secret', 'services.whatsapp.app_secret' => 'wa-app-secret',
            'services.whatsapp.verify_token' => 'verify-me']);
    }

    /** رسالةٌ أُرسلت — كما يكتبها القيد بعد نجاح المزوّد */
    private function message(string $provider, string $id, string $channel = 'email', ?int $businessId = null): Communication
    {
        return Communication::create([
            'business_id' => $businessId ?? $this->business->id,
            'customer_id' => $this->customer->id,
            'channel' => $channel,
            'direction' => 'outbound',
            'recipient' => $channel === 'email' ? 'buyer@ministry.om' : '96891234567',
            'template_key' => 'quotation.link',
            'title' => 'عرض سعر Q-000001',
            'provider' => $provider,
            'provider_message_id' => $id,
            'status' => Communication::SENT,
            'queued_at' => now()->subMinute(),
            'sent_at' => now()->subMinute(),
            'initiated_by_name' => 'المالك',
        ]);
    }

    private function postmark(array $payload, ?string $secret = 'pm-secret')
    {
        $headers = [];
        if ($secret !== null) {
            $headers['Authorization'] = 'Basic '.base64_encode('postmark:'.$secret);
        }

        return $this->postJson(route('webhooks.postmark'), $payload, $headers);
    }

    private function meta(array $statuses, ?string $secret = 'wa-app-secret')
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '1050000'], 'statuses' => $statuses,
            ]]],
        ]]]);

        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($secret !== null) {
            $headers['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', route('webhooks.whatsapp'), [], [], [], $headers, $body);
    }

    /* ─────────────────────────── Postmark ─────────────────────────── */

    public function test_postmark_is_refused_without_the_secret_and_closed_when_none_is_configured(): void
    {
        $this->message('postmark', 'pm-1');

        $this->postmark(['RecordType' => 'Delivery', 'MessageID' => 'pm-1'], null)->assertForbidden();
        $this->postmark(['RecordType' => 'Delivery', 'MessageID' => 'pm-1'], 'wrong')->assertForbidden();

        config(['services.postmark.webhook_secret' => null]);
        $this->postmark(['RecordType' => 'Delivery', 'MessageID' => 'pm-1'])->assertNotFound();

        $this->assertSame(Communication::SENT, Communication::sole()->status);
        $this->assertSame(0, CommunicationEvent::count());
    }

    public function test_a_delivery_then_an_open_move_the_message_forward_and_never_back(): void
    {
        $c = $this->message('postmark', 'pm-1');

        $this->postmark(['RecordType' => 'Delivery', 'MessageID' => 'pm-1', 'Recipient' => 'buyer@ministry.om',
            'DeliveredAt' => '2026-09-19T10:35:00Z', 'Details' => 'smtp;250 ok'])->assertOk();

        $c->refresh();
        $this->assertSame(Communication::DELIVERED, $c->status);
        $this->assertSame('2026-09-19T10:35:00+00:00', $c->delivered_at->utc()->toIso8601String(), 'وقتُ المزوّد كما هو — بمنطقته');
        $this->assertNull($c->read_at);

        $this->postmark(['RecordType' => 'Open', 'MessageID' => 'pm-1', 'ReceivedAt' => '2026-09-19T11:00:00Z', 'FirstOpen' => true])->assertOk();
        $this->postmark(['RecordType' => 'Open', 'MessageID' => 'pm-1', 'ReceivedAt' => '2026-09-19T12:00:00Z', 'FirstOpen' => false])->assertOk();

        $c->refresh();
        $this->assertSame(Communication::READ, $c->status);
        $this->assertSame('2026-09-19T11:00:00+00:00', $c->read_at->utc()->toIso8601String(), 'أوّلُ قراءةٍ هي ما يُقاس');

        /* وخبرُ وصولٍ متأخّر لا يمحو القراءة */
        $this->postmark(['RecordType' => 'Delivery', 'MessageID' => 'pm-1', 'DeliveredAt' => '2026-09-19T10:35:00Z'])->assertOk();
        $this->assertSame(Communication::READ, $c->fresh()->status);

        $this->assertSame(['delivered', 'opened', 'opened'], $c->events()->pluck('kind')->all(), 'الوصولُ المكرَّر لم يُكتب ثانيةً');
    }

    public function test_a_duplicate_callback_leaves_no_second_trace(): void
    {
        $c = $this->message('postmark', 'pm-2');
        $bounce = ['RecordType' => 'Bounce', 'ID' => 4323372036854775807, 'Type' => 'HardBounce', 'MessageID' => 'pm-2',
            'Description' => 'The server was unable to deliver your message', 'Email' => 'buyer@ministry.om',
            'BouncedAt' => '2026-09-19T10:36:00Z', 'Content' => '<full dump>'];

        $this->postmark($bounce)->assertOk();
        $this->postmark($bounce)->assertOk();
        $this->postmark($bounce)->assertOk();

        $c->refresh();
        $this->assertSame(Communication::BOUNCED, $c->status);
        $this->assertSame('HardBounce', $c->failure_code);
        $this->assertStringContainsString('unable to deliver', $c->failure_reason);
        $this->assertSame('2026-09-19T10:36:00+00:00', $c->failed_at->utc()->toIso8601String());
        $this->assertSame(1, $c->events()->count());
        $this->assertArrayNotHasKey('Content', $c->events()->first()->payload, 'نصُّ الرسالة الكامل لا يُخزَّن');
        $this->assertSame(1, ActivityLog::where('subject_type', 'communication')->where('subject_id', $c->id)->count(), 'الفشلُ يدخل الأثرَ مرّةً');
        $this->assertTrue($c->isRetryable());
    }

    public function test_a_spam_complaint_is_a_bounce_and_a_click_is_only_a_note(): void
    {
        $c = $this->message('postmark', 'pm-3');

        $this->postmark(['RecordType' => 'Click', 'MessageID' => 'pm-3', 'ReceivedAt' => '2026-09-19T11:00:00Z', 'OriginalLink' => 'https://hadath.om/i/x'])->assertOk();
        $this->assertSame(Communication::SENT, $c->fresh()->status, 'الضغطُ على رابطٍ لا يغيّر الحال');
        $this->assertSame('clicked', $c->events()->first()->kind);

        $this->postmark(['RecordType' => 'SpamComplaint', 'ID' => 99, 'MessageID' => 'pm-3', 'BouncedAt' => '2026-09-19T12:00:00Z'])->assertOk();
        $this->assertSame(Communication::BOUNCED, $c->fresh()->status);
        $this->assertSame('SpamComplaint', $c->fresh()->failure_code);
    }

    public function test_an_unknown_message_is_thanked_and_ignored(): void
    {
        $this->postmark(['RecordType' => 'Delivery', 'MessageID' => 'nobody-knows'])->assertOk();
        $this->postmark(['RecordType' => 'SubscriptionChange', 'MessageID' => 'pm-x'])->assertOk();
        $this->postmark(['RecordType' => 'Delivery'])->assertOk();

        $this->assertSame(0, CommunicationEvent::count());
        $this->assertSame(0, Communication::count());
    }

    /* ─────────────────────────── Meta ─────────────────────────── */

    public function test_meta_verifies_the_subscription_with_our_token_only(): void
    {
        $this->get(route('webhooks.whatsapp.verify', ['hub.mode' => 'subscribe', 'hub.verify_token' => 'verify-me', 'hub.challenge' => '12345']))
            ->assertOk()->assertSee('12345', false);

        $this->get(route('webhooks.whatsapp.verify', ['hub.mode' => 'subscribe', 'hub.verify_token' => 'guess', 'hub.challenge' => '12345']))
            ->assertForbidden();

        config(['services.whatsapp.verify_token' => null]);
        $this->get(route('webhooks.whatsapp.verify', ['hub.mode' => 'subscribe', 'hub.verify_token' => 'verify-me', 'hub.challenge' => '1']))
            ->assertNotFound();
    }

    public function test_meta_is_refused_without_a_valid_signature(): void
    {
        $this->message('meta', 'wamid.1', 'whatsapp');

        $this->meta([['id' => 'wamid.1', 'status' => 'delivered', 'timestamp' => '1790000000']], null)->assertForbidden();
        $this->meta([['id' => 'wamid.1', 'status' => 'delivered', 'timestamp' => '1790000000']], 'forged')->assertForbidden();

        config(['services.whatsapp.app_secret' => null]);
        $this->meta([['id' => 'wamid.1', 'status' => 'delivered', 'timestamp' => '1790000000']], 'wa-app-secret')->assertNotFound();

        $this->assertSame(Communication::SENT, Communication::sole()->status);
        $this->assertSame(0, CommunicationEvent::count());
    }

    public function test_meta_statuses_walk_the_message_from_sent_to_read_with_their_own_clock(): void
    {
        $c = $this->message('meta', 'wamid.1', 'whatsapp');

        $this->meta([
            ['id' => 'wamid.1', 'status' => 'sent', 'timestamp' => '1790000000', 'recipient_id' => '96891234567'],
            ['id' => 'wamid.1', 'status' => 'delivered', 'timestamp' => '1790000060', 'recipient_id' => '96891234567'],
        ])->assertOk();

        $c->refresh();
        $this->assertSame(Communication::DELIVERED, $c->status);
        $this->assertSame(1790000060, $c->delivered_at->getTimestamp());

        $this->meta([['id' => 'wamid.1', 'status' => 'read', 'timestamp' => '1790000120']])->assertOk();
        /* وMeta تعيد الخبرَ نفسَه إن تأخّرنا في الردّ */
        $this->meta([['id' => 'wamid.1', 'status' => 'read', 'timestamp' => '1790000120']])->assertOk();
        $this->meta([['id' => 'wamid.1', 'status' => 'delivered', 'timestamp' => '1790000060']])->assertOk();

        $c->refresh();
        $this->assertSame(Communication::READ, $c->status);
        $this->assertSame(1790000120, $c->read_at->getTimestamp());
        $this->assertSame(['sent', 'delivered', 'read'], $c->events()->pluck('kind')->all());
    }

    public function test_a_meta_failure_carries_its_code_and_reason(): void
    {
        $c = $this->message('meta', 'wamid.2', 'whatsapp');

        $this->meta([[
            'id' => 'wamid.2', 'status' => 'failed', 'timestamp' => '1790000000',
            'errors' => [['code' => 131026, 'title' => 'Message Undeliverable', 'message' => 'Message Undeliverable.',
                'error_data' => ['details' => 'Receiver is incapable of receiving this message']]],
        ]])->assertOk();

        $c->refresh();
        $this->assertSame(Communication::FAILED, $c->status);
        $this->assertSame('131026', $c->failure_code);
        $this->assertStringContainsString('Message Undeliverable', $c->failure_reason);
        $this->assertStringContainsString('incapable', $c->failure_reason);
        $this->assertNotNull($c->failed_at);
        $this->assertTrue($c->isRetryable());
    }

    public function test_inbound_messages_are_accepted_and_left_alone(): void
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [[
            'field' => 'messages', 'value' => ['messages' => [['from' => '96891234567', 'id' => 'wamid.in', 'type' => 'text', 'text' => ['body' => 'شكرًا']]]],
        ]]]]]);

        $this->call('POST', route('webhooks.whatsapp'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'wa-app-secret'),
        ], $body)->assertOk();

        $this->assertSame(0, Communication::count());
        $this->assertSame(0, CommunicationEvent::count());
    }

    /* ─────────────────────────── العزل ─────────────────────────── */

    public function test_a_callback_touches_its_own_message_and_no_other_tenants(): void
    {
        $rival = Business::create(['name' => 'منافس', 'type' => 'عام', 'status' => 'نشط']);
        $mine = $this->message('postmark', 'pm-mine');
        $theirs = $this->message('postmark', 'pm-theirs', 'email', $rival->id);
        /* ومعرّفٌ واحدٌ عند مزوّدٍ آخر رسالةٌ أخرى */
        $sameIdOtherProvider = $this->message('meta', 'pm-mine', 'whatsapp');

        $this->postmark(['RecordType' => 'Delivery', 'MessageID' => 'pm-mine', 'DeliveredAt' => '2026-09-19T10:35:00Z'])->assertOk();

        $this->assertSame(Communication::DELIVERED, $mine->fresh()->status);
        $this->assertSame(Communication::SENT, $theirs->fresh()->status);
        $this->assertSame(Communication::SENT, $sameIdOtherProvider->fresh()->status);

        $event = CommunicationEvent::sole();
        $this->assertSame($mine->id, (int) $event->communication_id);
        $this->assertSame($this->business->id, (int) $event->business_id);
    }

    public function test_a_provider_payload_is_stored_without_anything_that_looks_like_a_secret(): void
    {
        $c = $this->message('postmark', 'pm-5');

        $this->postmark(['RecordType' => 'Delivery', 'MessageID' => 'pm-5', 'DeliveredAt' => '2026-09-19T10:35:00Z',
            'ServerToken' => 'should-not-be-stored', 'Metadata' => ['api_key' => 'x'], 'Tag' => 'ok'])->assertOk();

        $payload = $c->events()->first()->payload;
        $this->assertArrayNotHasKey('ServerToken', $payload);
        $this->assertArrayNotHasKey('Metadata', $payload);
        $this->assertSame('ok', $payload['Tag']);
    }
}
