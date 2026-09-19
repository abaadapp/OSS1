<?php

namespace Tests\Feature;

use App\Mail\DocumentMail;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Communication;
use App\Models\CommunicationTemplate;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Communication\Templates;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * الشركةُ تقرّر ما تقوله قوالبُها — والكتالوجُ يقول ما يُمكن أن يُقال.
 *
 * الكتالوجُ في الكود بمفاتيحه ومتغيّراته؛ والشركةُ تكتب فوقه: اسمَ قالب
 * Meta المعتمَد ولغتَه وترتيبَ خاناته، وتوقف قالبًا فلا يُرسَل به، وتعاين
 * قبل أن تحفظ. والعربيّةُ والإنجليزيّةُ معًا.
 */
class TheCompanyDecidesWhatItsTemplatesSayTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $customer;

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
        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة', 'email' => 'buyer@ministry.om', 'phone' => '91234567']);

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.om']);
        config(['services.whatsapp.token' => 'tok', 'services.whatsapp.phone_number_id' => '1050000']);
        Mail::fake();
    }

    /** فاتورةٌ صادرةٌ عليها رصيد */
    private function invoice(): CustomerInvoice
    {
        $this->actingAs($this->owner);
        $service = Product::create(['business_id' => $this->business->id, 'name' => 'خدمة', 'kind' => Product::SERVICE, 'price' => 100]);

        $invoice = CustomerInvoices::create((int) $this->business->id, $this->customer, [
            'issued_at' => now()->toDateString(), 'due_at' => now()->addDays(30)->toDateString(),
        ], [['product_id' => $service->id, 'description' => 'خدمة', 'quantity' => 2, 'unit_price' => 100]], $this->owner->id);

        return CustomerInvoices::issue($invoice, $this->owner->id);
    }

    public function test_the_catalog_is_listed_for_both_channels_and_both_languages(): void
    {
        $this->actingAs($this->owner)->get(route('admin.communications.templates'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Communications/Templates')
                ->has('templates', count(collect(Templates::CATALOG)->flatMap(fn ($t) => $t['channels'])) * 2)
                ->where('may.manage', true)->etc());

        $all = collect(Templates::all((int) $this->business->id));
        $link = $all->firstWhere(fn ($t) => $t['key'] === 'quotation.link' && $t['channel'] === 'whatsapp' && $t['language'] === 'ar');

        $this->assertSame('رابط عرض السعر', $link['name']);
        $this->assertFalse($link['sendable'], 'واتساب بلا اسمٍ معتمَد لا يُرسَل به');
        $this->assertFalse($link['customized']);
        $this->assertContains('url', $link['allowed_variables']);

        $email = $all->firstWhere(fn ($t) => $t['key'] === 'quotation.link' && $t['channel'] === 'email' && $t['language'] === 'en');
        $this->assertTrue($email['sendable'], 'البريدُ فعّالٌ بقالبه في الكود');
    }

    public function test_saving_a_whatsapp_template_keeps_only_known_variables_in_the_chosen_order_and_is_audited(): void
    {
        $this->actingAs($this->owner)->post(route('admin.communications.templates.update'), [
            'key' => 'invoice.reminder', 'channel' => 'whatsapp', 'language' => 'en', 'status' => 'active',
            'name' => 'Payment reminder', 'provider_template' => 'payment_reminder_v2', 'provider_language' => 'en_US',
            'variables' => ['number', 'outstanding', 'not_a_variable', 'url'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $t = CommunicationTemplate::sole();
        $this->assertSame('payment_reminder_v2', $t->provider_template);
        $this->assertSame('en_US', $t->provider_language);
        $this->assertSame(['number', 'outstanding', 'url'], $t->variables, 'المتغيّرُ الغريب يُسقط والترتيبُ يبقى');
        $this->assertSame('Payment reminder', $t->name);
        $this->assertSame($this->owner->id, (int) $t->updated_by);
        $this->assertTrue($t->isSendable());

        $this->assertTrue(ActivityLog::where('subject_type', 'communication_template')->where('subject_id', $t->id)->exists());

        /* واسمُ قالب Meta بحروفٍ لاتينيّة صغيرة — كما تقبله Meta */
        $this->actingAs($this->owner)->post(route('admin.communications.templates.update'), [
            'key' => 'invoice.reminder', 'channel' => 'whatsapp', 'language' => 'en', 'status' => 'active', 'provider_template' => 'Bad Name!',
        ])->assertSessionHasErrors('provider_template');

        /* وقالبٌ لا يعرفه الكتالوج يُردّ */
        $this->actingAs($this->owner)->post(route('admin.communications.templates.update'), [
            'key' => 'nonsense.key', 'channel' => 'email', 'language' => 'ar', 'status' => 'active',
        ])->assertSessionHasErrors('template');
    }

    public function test_the_preview_fills_the_placeholders_in_the_templates_order(): void
    {
        $json = $this->actingAs($this->owner)->postJson(route('admin.communications.templates.preview'), [
            'key' => 'invoice.reminder', 'channel' => 'whatsapp', 'language' => 'ar',
            'variables' => ['number', 'outstanding', 'url'],
            'body' => 'الفاتورة {{1}} عليها {{2}} — {{3}}',
        ])->assertOk()->json();

        $this->assertSame('الفاتورة INV-000034 عليها 500.000 — https://hadath.om/i/xxxxxxxxxxxxxxxxxxxxxx', $json['body']);
        $this->assertSame(['INV-000034', '500.000', 'https://hadath.om/i/xxxxxxxxxxxxxxxxxxxxxx'], $json['parameters']);
        $this->assertStringContainsString('INV-000034', $json['title']);

        $named = $this->actingAs($this->owner)->postJson(route('admin.communications.templates.preview'), [
            'key' => 'project.update', 'channel' => 'email', 'language' => 'en',
        ])->assertOk()->json();

        $this->assertStringContainsString('PRJ-000007', $named['body']);
        $this->assertStringNotContainsString('{{', $named['body'], 'لا خانةَ تبقى فارغة');
    }

    public function test_a_stopped_template_blocks_sending_and_an_active_one_speaks_meta(): void
    {
        $invoice = $this->invoice();

        Templates::save((int) $this->business->id, 'invoice.reminder', 'whatsapp', 'ar', [
            'provider_template' => 'payment_reminder', 'status' => 'inactive', 'variables' => ['customer_name', 'number', 'outstanding'],
        ], $this->owner);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.INV']]], 200)]);

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.communications.send', $invoice->id), [
            'channel' => 'whatsapp', 'template' => 'invoice.reminder', 'to' => '91234567',
        ])->assertSessionHasErrors('communication');
        Http::assertNothingSent();

        Templates::save((int) $this->business->id, 'invoice.reminder', 'whatsapp', 'ar', [
            'provider_template' => 'payment_reminder', 'status' => 'active', 'variables' => ['customer_name', 'number', 'outstanding'],
        ], $this->owner);

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.communications.send', $invoice->id), [
            'channel' => 'whatsapp', 'template' => 'invoice.reminder', 'to' => '91234567',
        ])->assertSessionHasNoErrors();

        Http::assertSent(function (ClientRequest $r) use ($invoice) {
            $params = collect($r->data()['template']['components'][0]['parameters'])->pluck('text')->all();

            return $r->data()['template']['name'] === 'payment_reminder'
                && $params[0] === 'وزارة'
                && $params[1] === $invoice->number
                && str_contains($params[2], '210');
        });

        $c = Communication::sole();
        $this->assertSame(CustomerInvoice::class, $c->subject_type);
        $this->assertSame($invoice->id, (int) $c->subject_id);
        $this->assertSame('invoice.reminder', $c->template_key);
    }

    public function test_an_english_email_template_speaks_english(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.communications.send', $invoice->id), [
            'channel' => 'email', 'template' => 'invoice.link', 'to' => 'buyer@ministry.om', 'language' => 'en',
        ])->assertSessionHasNoErrors();

        Mail::assertSent(DocumentMail::class, function ($m) use ($invoice) {
            $i = $m->intent;

            return $i->templateKey === 'invoice.link'
                && $i->language === 'en'
                && str_starts_with($i->subject, 'Invoice '.$invoice->number)
                && collect($i->paragraphs)->contains(fn ($p) => str_starts_with($p, 'Greetings'))
                && $i->url !== null && str_contains($i->url, '/i/');
        });

        $this->assertSame('en', Communication::sole()->language);
        $this->assertSame('ar', app()->getLocale(), 'لغةُ الجلسة تعود كما كانت');
    }

    public function test_a_paid_invoice_is_not_reminded_and_a_draft_is_not_sent(): void
    {
        $invoice = $this->invoice();
        $service = Product::create(['business_id' => $this->business->id, 'name' => 'خدمة ٢', 'kind' => Product::SERVICE, 'price' => 100]);
        $draft = CustomerInvoices::create((int) $this->business->id, $this->customer, ['issued_at' => now()->toDateString()],
            [['product_id' => $service->id, 'description' => 'خدمة', 'quantity' => 1, 'unit_price' => 100]], $this->owner->id);

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.communications.send', $draft->id), [
            'channel' => 'email', 'template' => 'invoice.link', 'to' => 'buyer@ministry.om',
        ])->assertSessionHasErrors('communication');

        CustomerPayments::record((int) $this->business->id, $this->customer, (float) $invoice->total,
            ['method' => 'نقدي', 'occurred_at' => now()->toDateString()], [$invoice->id => (float) $invoice->total], $this->owner->id);

        $this->actingAs($this->owner)->post(route('admin.customerInvoices.communications.send', $invoice->id), [
            'channel' => 'email', 'template' => 'invoice.reminder', 'to' => 'buyer@ministry.om',
        ])->assertSessionHasErrors('communication');

        Mail::assertNothingSent();
        $this->assertSame(0, Communication::count());
    }
}
