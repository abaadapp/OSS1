<?php

namespace Tests\Feature;

use App\Mail\DocumentMail;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Communication;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\User;
use App\Support\Communication\Communications;
use App\Support\Communication\Templates;
use App\Support\Quotations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * كلُّ رسالةٍ إلى عميلٍ في السجلّ — المرحلة 6.
 *
 * ═══ ما يُفحص ═══
 *
 * البريدُ القديم (رابطُ العرض) يُقيَّد بلا أن يتغيّر · الإرسالُ من نافذة
 * المستند بريدًا وواتساب · معرّفُ المزوّد · الرفضُ قبل المزوّد: مُرسَلٌ
 * إليه لا يصلح، ومستندٌ في غير حاله، وقالبٌ غيرُ معتمَد · العزلُ بين
 * الشركات · الأذونُ بابًا بابًا · ولا سرَّ يبلغ الشاشةَ أو القيد.
 */
class EveryMessageToAClientIsOnTheRecordTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $seller;

    private Customer $customer;

    private Product $service;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط', 'email' => 'hello@hadath.om']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@hadath.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->seller = User::create([
            'business_id' => $this->business->id, 'name' => 'البائع', 'email' => 'sales@hadath.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'وزارة الطاقة', 'email' => 'Info@Ministry.om',
            'phone' => '+968 9123 4567', 'contact_person' => 'سالم', 'contact_phone' => '٩٩٨٨٧٧٦٦',
        ]);
        $this->service = Product::create([
            'business_id' => $this->business->id, 'name' => 'تشغيل نظام تسجيل', 'kind' => Product::SERVICE, 'price' => 500,
        ]);

        /* البريدُ مضبوطٌ افتراضًا، وواتساب موصولٌ — وما عداه يُقال صراحةً */
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.om']);
        config(['services.whatsapp.token' => 'SECRET_TOKEN_X', 'services.whatsapp.phone_number_id' => '1050000']);
        Mail::fake();
    }

    private function sent(array $over = []): Quotation
    {
        $this->actingAs($this->owner);

        $q = Quotations::create((int) $this->business->id, $this->customer,
            $over + ['title' => 'مؤتمر الطاقة', 'attention_to' => 'سالم', 'issued_at' => now()->toDateString(),
                'valid_until' => now()->addDays(30)->toDateString(), 'contact_email' => 'buyer@ministry.om'],
            [['product_id' => $this->service->id, 'quantity' => 2, 'unit_price' => 500]], $this->owner);

        return Quotations::send($q, $this->owner);
    }

    /** قالبُ واتساب معتمَد لرابط العرض — كما تضبطه الشركة */
    private function approveWhatsAppTemplate(string $key = 'quotation.link', string $name = 'quotation_link'): void
    {
        Templates::save((int) $this->business->id, $key, 'whatsapp', 'ar', [
            'provider_template' => $name, 'provider_language' => 'ar', 'status' => 'active',
            'variables' => ['contact_name', 'label', 'url'],
        ], $this->owner);
    }

    private function fakeMeta(string $id = 'wamid.ABC123'): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => $id]]], 200)]);
    }

    /* ─────────────────────────── البريدُ القديم يُقيَّد ─────────────────────────── */

    public function test_the_emailed_quotation_link_lands_in_the_record_without_changing_the_old_path(): void
    {
        $q = $this->sent();

        $this->actingAs($this->owner)->post(route('admin.quotations.email', $q->id), ['to' => 'Buyer@Ministry.om'])
            ->assertRedirect()->assertSessionHasNoErrors();

        Mail::assertSent(DocumentMail::class);

        $c = Communication::sole();
        $this->assertSame((int) $this->business->id, (int) $c->business_id);
        $this->assertSame('email', $c->channel);
        $this->assertSame('outbound', $c->direction);
        $this->assertSame('buyer@ministry.om', $c->recipient, 'العنوانُ يُطبَّع بحروفٍ صغيرة');
        $this->assertSame(Quotation::class, $c->subject_type);
        $this->assertSame($q->id, (int) $c->subject_id);
        $this->assertSame($this->customer->id, (int) $c->customer_id);
        $this->assertSame('quotation.link', $c->template_key);
        $this->assertStringContainsString('Q-000001', $c->title);
        $this->assertSame(Communication::SENT, $c->status);
        $this->assertNotNull($c->sent_at);
        $this->assertSame('smtp', $c->provider);
        $this->assertSame($this->owner->id, (int) $c->initiated_by);
        $this->assertSame('المالك', $c->initiated_by_name);
        $this->assertSame('Buyer@Ministry.om', $q->fresh()->sent_to, 'المسارُ القديم لم يتغيّر — يكتب العنوانَ كما كُتب');
    }

    /* ─────────────────────────── الإرسالُ من نافذة المستند ─────────────────────────── */

    public function test_the_compose_window_offers_only_this_documents_contacts_and_templates(): void
    {
        $q = $this->sent();
        Customer::create(['business_id' => $this->business->id, 'name' => 'شركة أخرى', 'email' => 'other@corp.om', 'phone' => '92000000']);

        $json = $this->actingAs($this->owner)->getJson(route('admin.quotations.communications.compose', $q->id))
            ->assertOk()->json();

        $emails = collect($json['recipients']['email'])->pluck('value')->all();
        $phones = collect($json['recipients']['phone'])->pluck('value')->all();

        $this->assertSame(['buyer@ministry.om', 'info@ministry.om'], $emails, 'بريدُ العرض ثمّ بريدُ العميل — ولا غريب');
        $this->assertSame(['96899887766', '96891234567'], $phones, 'الأرقامُ مطبَّعةٌ دوليًّا — والعربيّةُ تُقرأ');
        $this->assertNotContains('other@corp.om', $emails);

        $keys = collect($json['templates'])->pluck('key')->all();
        $this->assertContains('quotation.link', $keys);
        $this->assertContains('quotation.reminder', $keys);
        $this->assertNotContains('quotation.accepted', $keys, 'الأخبارُ الداخليّة لا تُعرض للإرسال');
        $this->assertNotContains('invoice.link', $keys);
        $this->assertTrue($json['channels']['email']);
        $this->assertTrue($json['channels']['whatsapp']);
        $this->assertNull($json['blocked']);

        /* وقالبُ واتساب بلا اسمٍ معتمَد لا يُعرض متاحًا */
        $link = collect($json['templates'])->firstWhere('key', 'quotation.link');
        $this->assertTrue($link['available']['email']['ar']);
        $this->assertFalse($link['available']['whatsapp']['ar']);
    }

    public function test_sending_email_from_the_window_goes_through_the_quotations_service(): void
    {
        $q = $this->sent();

        $this->actingAs($this->seller)->post(route('admin.quotations.communications.send', $q->id), [
            'channel' => 'email', 'template' => 'quotation.link', 'to' => 'buyer@ministry.om', 'note' => 'نرجو الردّ',
        ])->assertRedirect()->assertSessionHasNoErrors();

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $m) => $m->hasTo('buyer@ministry.om')
            && $m->intent->templateKey === 'quotation.link'
            && collect($m->intent->paragraphs)->contains('نرجو الردّ'));

        $c = Communication::sole();
        $this->assertSame('quotation.link', $c->template_key);
        $this->assertSame($this->seller->id, (int) $c->initiated_by);
        $this->assertSame('buyer@ministry.om', $q->fresh()->sent_to, 'الخدمةُ القديمة هي التي أرسلت — فكتبت أثرَها');
        $this->assertTrue(ActivityLog::where('subject_type', 'communication')->where('subject_id', $c->id)->exists());
    }

    public function test_a_whatsapp_message_is_sent_by_approved_template_and_recorded_with_the_providers_id(): void
    {
        $q = $this->sent();
        $this->approveWhatsAppTemplate();
        $this->fakeMeta('wamid.HBgL123');

        $this->actingAs($this->owner)->post(route('admin.quotations.communications.send', $q->id), [
            'channel' => 'whatsapp', 'template' => 'quotation.link', 'to' => '+968 9123 4567',
        ])->assertRedirect()->assertSessionHasNoErrors();

        Http::assertSent(function (ClientRequest $r) {
            $body = $r->data();

            return str_contains($r->url(), 'graph.facebook.com') && str_contains($r->url(), '/1050000/messages')
                && $r->hasHeader('Authorization', 'Bearer SECRET_TOKEN_X')
                && $body['to'] === '96891234567'
                && $body['type'] === 'template'
                && $body['template']['name'] === 'quotation_link'
                && $body['template']['language']['code'] === 'ar'
                && count($body['template']['components'][0]['parameters']) === 3
                && $body['template']['components'][0]['parameters'][0]['text'] === 'سالم'
                && $body['template']['components'][0]['parameters'][1]['text'] === 'Q-000001'
                && str_contains($body['template']['components'][0]['parameters'][2]['text'], '/i/');
        });

        $c = Communication::sole();
        $this->assertSame('whatsapp', $c->channel);
        $this->assertSame('meta', $c->provider);
        $this->assertSame('wamid.HBgL123', $c->provider_message_id);
        $this->assertSame('96891234567', $c->recipient);
        $this->assertSame(Communication::SENT, $c->status);
        $this->assertSame($q->id, (int) $c->subject_id);
        $this->assertSame('ar', $c->language);
        Mail::assertNothingSent();
    }

    public function test_whatsapp_refuses_without_an_approved_template_and_never_calls_the_provider(): void
    {
        $q = $this->sent();
        $this->fakeMeta();

        $this->actingAs($this->owner)->post(route('admin.quotations.communications.send', $q->id), [
            'channel' => 'whatsapp', 'template' => 'quotation.link', 'to' => '91234567',
        ])->assertSessionHasErrors('communication');

        Http::assertNothingSent();
        $this->assertSame(0, Communication::count());
    }

    public function test_a_provider_refusal_is_recorded_as_a_failure_with_its_reason(): void
    {
        $q = $this->sent();
        $this->approveWhatsAppTemplate();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131026, 'message' => 'Message Undeliverable']], 400)]);

        $this->actingAs($this->owner)->post(route('admin.quotations.communications.send', $q->id), [
            'channel' => 'whatsapp', 'template' => 'quotation.link', 'to' => '91234567',
        ])->assertSessionHasErrors('communication');

        $c = Communication::sole();
        $this->assertSame(Communication::FAILED, $c->status);
        $this->assertNotNull($c->failed_at);
        $this->assertNull($c->sent_at);
        $this->assertStringContainsString('131026', $c->failure_reason);
        $this->assertStringContainsString('Message Undeliverable', $c->failure_reason);
        $this->assertTrue(ActivityLog::where('subject_type', 'communication')->where('subject_id', $c->id)
            ->where('color', 'danger')->exists(), 'الفشلُ يدخل الأثر');
    }

    /* ─────────────────────────── الرفضُ قبل المزوّد ─────────────────────────── */

    public function test_an_invalid_recipient_is_refused_before_any_provider_is_called(): void
    {
        $q = $this->sent();
        $this->approveWhatsAppTemplate();
        $this->fakeMeta();

        $this->actingAs($this->owner)->post(route('admin.quotations.communications.send', $q->id), [
            'channel' => 'email', 'template' => 'quotation.link', 'to' => 'not-an-email',
        ])->assertSessionHasErrors('communication');

        $this->actingAs($this->owner)->post(route('admin.quotations.communications.send', $q->id), [
            'channel' => 'whatsapp', 'template' => 'quotation.link', 'to' => 'abc',
        ])->assertSessionHasErrors('communication');

        Mail::assertNothingSent();
        Http::assertNothingSent();
        $this->assertSame(0, Communication::count());
    }

    public function test_a_document_outside_its_sendable_state_is_refused(): void
    {
        $this->actingAs($this->owner);
        $draft = Quotations::create((int) $this->business->id, $this->customer,
            ['title' => 'مسودة', 'issued_at' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString()],
            [['product_id' => $this->service->id, 'quantity' => 1, 'unit_price' => 500]], $this->owner);

        $this->actingAs($this->owner)->post(route('admin.quotations.communications.send', $draft->id), [
            'channel' => 'email', 'template' => 'quotation.link', 'to' => 'buyer@ministry.om',
        ])->assertSessionHasErrors('communication');

        $this->assertNotNull($this->actingAs($this->owner)
            ->getJson(route('admin.quotations.communications.compose', $draft->id))->json('blocked'));

        Mail::assertNothingSent();
        $this->assertSame(0, Communication::count());
    }

    public function test_a_template_that_does_not_belong_to_the_document_is_refused(): void
    {
        $q = $this->sent();

        $this->actingAs($this->owner)->post(route('admin.quotations.communications.send', $q->id), [
            'channel' => 'email', 'template' => 'invoice.reminder', 'to' => 'buyer@ministry.om',
        ])->assertSessionHasErrors('communication');

        $this->actingAs($this->owner)->post(route('admin.quotations.communications.send', $q->id), [
            'channel' => 'email', 'template' => 'quotation.accepted', 'to' => 'buyer@ministry.om',
        ])->assertSessionHasErrors('communication');

        Mail::assertNothingSent();
    }

    public function test_two_clicks_send_one_message(): void
    {
        $q = $this->sent();
        $this->approveWhatsAppTemplate();
        $this->fakeMeta();

        $payload = ['channel' => 'whatsapp', 'template' => 'quotation.link', 'to' => '91234567'];
        $this->actingAs($this->owner)->post(route('admin.quotations.communications.send', $q->id), $payload)->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('admin.quotations.communications.send', $q->id), $payload)->assertSessionHasErrors('communication');

        $this->assertSame(1, Communication::count());
        Http::assertSentCount(1);
    }

    /* ─────────────────────────── السجلّ ─────────────────────────── */

    public function test_the_hub_lists_counts_and_filters_and_the_detail_names_everything(): void
    {
        $q = $this->sent();
        $this->approveWhatsAppTemplate();
        $this->fakeMeta();

        $this->actingAs($this->owner)->post(route('admin.quotations.email', $q->id), ['to' => 'buyer@ministry.om']);
        $this->actingAs($this->owner)->post(route('admin.quotations.communications.send', $q->id), [
            'channel' => 'whatsapp', 'template' => 'quotation.link', 'to' => '91234567',
        ]);

        $this->actingAs($this->owner)->get(route('admin.communications.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Communications/Index')
                ->has('communications', 2)
                ->where('totals.sent', 2)->where('totals.delivered', 0)->where('totals.failed', 0)
                ->where('channels.whatsapp', true)->etc());

        $this->actingAs($this->owner)->get(route('admin.communications.index', ['channel' => 'whatsapp']))
            ->assertInertia(fn ($p) => $p->has('communications', 1)
                ->where('communications.0.channel', 'whatsapp')
                ->where('communications.0.subject_type', 'quotation')
                ->where('communications.0.customer', 'وزارة الطاقة')->etc());

        $this->actingAs($this->owner)->get(route('admin.communications.index', ['customer_id' => $this->customer->id, 'from' => now()->toDateString()]))
            ->assertInertia(fn ($p) => $p->has('communications', 2)->etc());

        $this->actingAs($this->owner)->get(route('admin.communications.index', ['to' => now()->subDay()->toDateString()]))
            ->assertInertia(fn ($p) => $p->has('communications', 0)->etc());

        $wa = Communication::where('channel', 'whatsapp')->sole();

        $this->actingAs($this->owner)->get(route('admin.communications.show', $wa->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Communications/Show')
                ->where('communication.recipient', '96891234567')
                ->where('communication.provider', 'meta')
                ->where('communication.provider_message_id', 'wamid.ABC123')
                ->where('communication.template_key', 'quotation.link')
                ->where('communication.subject_label', 'عرض سعر Q-000001')
                ->where('communication.subject_route', route('admin.quotations.show', $q->id))
                ->where('communication.initiated_by', 'المالك')
                ->where('communication.retryable', false)->etc());
    }

    public function test_the_documents_history_window_lists_its_own_messages_only(): void
    {
        $q = $this->sent();
        $other = $this->sent(['title' => 'عرضٌ آخر']);

        $this->actingAs($this->owner)->post(route('admin.quotations.email', $q->id), ['to' => 'buyer@ministry.om']);
        $this->actingAs($this->owner)->post(route('admin.quotations.email', $other->id), ['to' => 'boss@ministry.om']);

        $json = $this->actingAs($this->seller)->getJson(route('admin.quotations.communications', $q->id))->assertOk()->json();

        $this->assertCount(1, $json['communications']);
        $this->assertSame('buyer@ministry.om', $json['communications'][0]['recipient']);
        $this->assertSame('رابط عرض السعر', $json['communications'][0]['template_name']);
        $this->assertFalse($json['may']['hub'], 'البائعُ يرى مراسلاتِ عرضه ولا يملك السجلَّ كلَّه');
    }

    /* ─────────────────────────── العزل والأذون ─────────────────────────── */

    public function test_nothing_crosses_the_tenant_line(): void
    {
        $q = $this->sent();
        $this->actingAs($this->owner)->post(route('admin.quotations.email', $q->id), ['to' => 'buyer@ministry.om']);
        $mine = Communication::sole();

        $rival = Business::create(['name' => 'منافس', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $rival->id, 'name' => 'الرئيسي']);
        $stranger = User::create([
            'business_id' => $rival->id, 'name' => 'غريب', 'email' => 'x@rival.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($stranger)->get(route('admin.communications.show', $mine->id))->assertNotFound();
        $this->actingAs($stranger)->post(route('admin.communications.retry', $mine->id))->assertNotFound();
        $this->actingAs($stranger)->getJson(route('admin.quotations.communications', $q->id))->assertNotFound();
        $this->actingAs($stranger)->getJson(route('admin.quotations.communications.compose', $q->id))->assertNotFound();
        $this->actingAs($stranger)->post(route('admin.quotations.communications.send', $q->id), [
            'channel' => 'email', 'template' => 'quotation.link', 'to' => 'buyer@ministry.om',
        ])->assertNotFound();

        $this->actingAs($stranger)->get(route('admin.communications.index'))
            ->assertInertia(fn ($p) => $p->has('communications', 0)->where('totals.sent', 0)->etc());

        $this->assertSame(1, Communication::count(), 'لم يُكتب شيءٌ باسم الغريب');
    }

    public function test_every_door_asks_for_its_own_permission(): void
    {
        $q = $this->sent();

        $noSend = User::create([
            'business_id' => $this->business->id, 'name' => 'بائع بلا إرسال', 'email' => 'nosend@hadath.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
            'permissions' => ['dashboard', 'quotations', 'communication.view'],
        ]);
        $storekeeper = User::create([
            'business_id' => $this->business->id, 'name' => 'أمين المخزن', 'email' => 'store@hadath.om',
            'password' => bcrypt('password'), 'role' => 'inventory', 'status' => 'نشط',
        ]);
        $accountant = User::create([
            'business_id' => $this->business->id, 'name' => 'المحاسب', 'email' => 'acc@hadath.om',
            'password' => bcrypt('password'), 'role' => 'accountant', 'status' => 'نشط',
        ]);

        /* الإرسالُ فعلٌ باسمه */
        $this->actingAs($noSend)->post(route('admin.quotations.communications.send', $q->id), [
            'channel' => 'email', 'template' => 'quotation.link', 'to' => 'buyer@ministry.om',
        ])->assertForbidden();
        $this->actingAs($noSend)->getJson(route('admin.quotations.communications.compose', $q->id))->assertForbidden();
        /* والقراءةُ داخل المستند لمن مُنحها */
        $this->actingAs($noSend)->getJson(route('admin.quotations.communications', $q->id))->assertOk();
        $this->actingAs($this->seller)->getJson(route('admin.quotations.communications', $q->id))->assertOk();

        /* وأمينُ المخزن لا يرى القسمَ ولا الفعل */
        $this->assertContains($this->actingAs($storekeeper)->getJson(route('admin.quotations.communications', $q->id))->getStatusCode(), [403, 302]);
        $this->assertContains($this->actingAs($storekeeper)->get(route('admin.communications.index'))->getStatusCode(), [403, 302]);

        /* والسجلُّ كلُّه قسمٌ لا يملكه المحاسب والبائع افتراضًا */
        $this->assertContains($this->actingAs($accountant)->get(route('admin.communications.index'))->getStatusCode(), [403, 302]);
        $this->assertContains($this->actingAs($this->seller)->get(route('admin.communications.index'))->getStatusCode(), [403, 302]);
        $this->assertContains($this->actingAs($this->seller)->get(route('admin.communications.templates'))->getStatusCode(), [403, 302]);

        /* والإعادةُ والقوالبُ أفعالٌ فوق القسم */
        $this->actingAs($this->owner)->post(route('admin.quotations.email', $q->id), ['to' => 'buyer@ministry.om']);
        $c = Communication::sole();
        $granted = User::create([
            'business_id' => $this->business->id, 'name' => 'مُنح القسم', 'email' => 'hub@hadath.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
            'permissions' => ['dashboard', 'communications'],
        ]);
        $this->actingAs($granted)->get(route('admin.communications.show', $c->id))->assertOk();
        $this->actingAs($granted)->post(route('admin.communications.retry', $c->id))->assertForbidden();
        $this->actingAs($granted)->post(route('admin.communications.templates.update'), [
            'key' => 'quotation.link', 'channel' => 'whatsapp', 'language' => 'ar', 'status' => 'active', 'provider_template' => 'x',
        ])->assertForbidden();
        $this->actingAs($this->owner)->post(route('admin.communications.templates.update'), [
            'key' => 'quotation.link', 'channel' => 'whatsapp', 'language' => 'ar', 'status' => 'active', 'provider_template' => 'quotation_link',
        ])->assertSessionHasNoErrors();
    }

    /* ─────────────────────────── لا سرَّ يخرج ─────────────────────────── */

    public function test_no_secret_reaches_the_screen_or_the_record(): void
    {
        $q = $this->sent();
        $this->approveWhatsAppTemplate();
        Http::fake(['graph.facebook.com/*' => Http::response([
            'messages' => [['id' => 'wamid.S']], 'access_token' => 'LEAKED_IN_RESPONSE',
        ], 200)]);

        $this->actingAs($this->owner)->post(route('admin.quotations.communications.send', $q->id), [
            'channel' => 'whatsapp', 'template' => 'quotation.link', 'to' => '91234567',
        ])->assertSessionHasNoErrors();

        $c = Communication::sole();
        $this->assertStringNotContainsString('SECRET_TOKEN_X', json_encode($c->toArray()));
        $this->assertStringNotContainsString('LEAKED', json_encode($c->toArray()));

        $show = $this->actingAs($this->owner)->get(route('admin.communications.show', $c->id))->assertOk();
        $this->assertStringNotContainsString('SECRET_TOKEN_X', json_encode($show->viewData('page')['props']));

        $index = $this->actingAs($this->owner)->get(route('admin.communications.index'))->assertOk();
        $this->assertStringNotContainsString('SECRET_TOKEN_X', json_encode($index->viewData('page')['props']));

        $templates = $this->actingAs($this->owner)->get(route('admin.communications.templates'))->assertOk();
        $this->assertStringNotContainsString('SECRET_TOKEN_X', json_encode($templates->viewData('page')['props']));

        /* وحمولةُ المزوّد تُنقّى من كلّ ما يشبه سرًّا */
        $clean = Communications::sanitize(['id' => 'x', 'access_token' => 's', 'nested' => ['api_key' => 'k', 'ok' => 1], 'X-Hub-Signature' => 'sig']);
        $this->assertSame(['id' => 'x', 'nested' => ['ok' => 1]], $clean);
    }

    public function test_a_failed_provider_message_never_echoes_the_token(): void
    {
        $q = $this->sent();
        $this->approveWhatsAppTemplate();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token SECRET_TOKEN_X']], 401)]);

        $this->actingAs($this->owner)->post(route('admin.quotations.communications.send', $q->id), [
            'channel' => 'whatsapp', 'template' => 'quotation.link', 'to' => '91234567',
        ])->assertSessionHasErrors('communication');

        $c = Communication::sole();
        $this->assertStringNotContainsString('SECRET_TOKEN_X', (string) $c->failure_reason);
        $this->assertStringContainsString('190', (string) $c->failure_reason);
        $this->assertStringNotContainsString('SECRET_TOKEN_X', (string) session('errors')?->first('communication'));
    }
}
