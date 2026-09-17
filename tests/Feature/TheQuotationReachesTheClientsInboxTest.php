<?php

namespace Tests\Feature;

use App\Mail\DocumentMail;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\User;
use App\Support\Messaging\EmailNotifier;
use App\Support\Messaging\MessageIntent;
use App\Support\Messaging\Notifiers;
use App\Support\Permissions;
use App\Support\PublicDocument;
use App\Support\Quotations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * العرضُ يصل صندوقَ العميل — والقناةُ تقول عن نفسها هل تُوصِل.
 *
 * ═══ الحدُّ (§21) ═══
 *
 * `Notifier` واجهةٌ تُنفَّذ بريدًا اليوم، و`MessageIntent` نيّةٌ بلا لغةِ
 * قناة. و`configured()` ترث درسَ `Mailer`: بريدٌ إلى `log` «ينجح» في نظر
 * لارافيل والرسالةُ في ملفٍّ على الخادم — فتُرفض هنا لا في الشاشة وحدها.
 *
 * ═══ وما يُفحص ═══
 *
 * الإرسالُ والتذكيرُ ومحتواهما · `sent_to` · الأثرُ · الرفضُ لغير المُرسَل
 * ولمنتهي الصلاحيّة · الصلاحيّةُ · والقناةُ الساقطة.
 */
class TheQuotationReachesTheClientsInboxTest extends TestCase
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

        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة']);
        $this->service = Product::create([
            'business_id' => $this->business->id, 'name' => 'تشغيل نظام تسجيل', 'kind' => Product::SERVICE, 'price' => 500,
        ]);

        /* والبريدُ مضبوطٌ افتراضًا في هذا الصنف — وما عداه يُقال صراحةً */
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.om']);
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

    /* ─────────────────────────── الإرسال ─────────────────────────── */

    public function test_the_link_goes_out_by_email_with_the_paper_facts(): void
    {
        $q = $this->sent();

        $this->actingAs($this->owner)->post(route('admin.quotations.email', $q->id), ['to' => 'buyer@ministry.om'])
            ->assertRedirect()->assertSessionHasNoErrors();

        Mail::assertSent(DocumentMail::class, function (DocumentMail $m) use ($q) {
            $i = $m->intent;

            return $m->hasTo('buyer@ministry.om')
                && str_contains($i->subject, 'Q-000001')
                && str_contains($i->subject, 'افنت بلس')
                && ! str_contains($i->subject, 'تذكير')
                && $i->url === PublicDocument::url($q)
                && $i->templateKey === 'quotation.link'
                && $i->senderName === 'افنت بلس'
                && $i->replyTo === 'owner@hadath.om'
                && collect($i->paragraphs)->contains(fn ($p) => str_contains($p, 'سالم'))
                && collect($i->paragraphs)->contains(fn ($p) => str_contains($p, '1,050'));
        });

        $this->assertSame('buyer@ministry.om', $q->fresh()->sent_to);
    }

    public function test_the_second_send_is_a_reminder_and_the_first_address_stays(): void
    {
        $q = $this->sent();

        $this->actingAs($this->owner)->post(route('admin.quotations.email', $q->id), ['to' => 'buyer@ministry.om']);
        $this->actingAs($this->owner)->post(route('admin.quotations.email', $q->id), [
            'to' => 'boss@ministry.om', 'note' => 'نرجو الردّ قبل الخميس',
        ])->assertSessionHasNoErrors();

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $m) => $m->hasTo('boss@ministry.om')
            && str_starts_with($m->intent->subject, 'تذكير')
            && $m->intent->templateKey === 'quotation.reminder'
            && collect($m->intent->paragraphs)->contains('نرجو الردّ قبل الخميس'));

        $this->assertSame('buyer@ministry.om', $q->fresh()->sent_to, 'أوّلُ عنوانٍ يبقى — الأثرُ يحمل الباقي');
    }

    public function test_the_mail_renders(): void
    {
        $q = $this->sent();

        $html = (new DocumentMail(new MessageIntent(
            to: 'x@y.om', subject: 'عرض', paragraphs: ['فقرةٌ أولى', 'فقرةٌ ثانية'],
            url: PublicDocument::url($q), action: 'فتح', senderName: 'افنت بلس',
        )))->render();

        $this->assertStringContainsString('فقرةٌ أولى', $html);
        $this->assertStringContainsString('فقرةٌ ثانية', $html);
        $this->assertStringContainsString(PublicDocument::url($q), $html);
        $this->assertStringContainsString('افنت بلس', $html);
    }

    /* ─────────────────────────── الأثر ─────────────────────────── */

    public function test_sending_is_logged_with_the_address(): void
    {
        $q = $this->sent();
        $this->actingAs($this->owner)->post(route('admin.quotations.email', $q->id), ['to' => 'buyer@ministry.om']);
        $this->actingAs($this->owner)->post(route('admin.quotations.email', $q->id), ['to' => 'buyer@ministry.om']);

        $rows = ActivityLog::where('subject_type', 'quotation')->where('subject_id', $q->id)
            ->where('description', 'like', '%بالبريد إلى buyer@ministry.om%')->get();

        $this->assertCount(2, $rows);
        $this->assertStringContainsString('أرسل عرض السعر', $rows[0]->description);
        $this->assertStringContainsString('تذكيرًا', $rows[1]->description);
        $this->assertSame('mail', $rows[0]->icon);
    }

    /* ─────────────────────────── الرفض ─────────────────────────── */

    public function test_a_draft_is_not_emailed(): void
    {
        $this->actingAs($this->owner);
        $draft = Quotations::create((int) $this->business->id, $this->customer, ['title' => 'م'],
            [['product_id' => $this->service->id, 'quantity' => 1, 'unit_price' => 500]], $this->owner);

        $this->post(route('admin.quotations.email', $draft->id), ['to' => 'buyer@ministry.om'])
            ->assertSessionHasErrors('email');

        Mail::assertNothingSent();
        $this->assertNull($draft->fresh()->sent_to);
    }

    public function test_an_expired_quotation_is_not_reminded(): void
    {
        $q = $this->sent(['valid_until' => now()->subDay()->toDateString()]);

        $this->actingAs($this->owner)->post(route('admin.quotations.email', $q->id), ['to' => 'buyer@ministry.om'])
            ->assertSessionHasErrors('email');

        Mail::assertNothingSent();
    }

    public function test_the_address_is_validated(): void
    {
        $q = $this->sent();

        $this->actingAs($this->owner)->post(route('admin.quotations.email', $q->id), ['to' => 'not-an-email'])
            ->assertSessionHasErrors('to');

        Mail::assertNothingSent();
    }

    public function test_whoever_lacks_messaging_send_is_refused_and_another_company_finds_nothing(): void
    {
        $q = $this->sent();

        $this->assertTrue($this->seller->may(Permissions::MESSAGING_SEND));

        $this->seller->forceFill(['permissions' => ['quotations', 'quotation.create']])->save();
        $this->assertFalse($this->seller->fresh()->may(Permissions::MESSAGING_SEND));

        $this->actingAs($this->seller->fresh())->post(route('admin.quotations.email', $q->id), ['to' => 'buyer@ministry.om'])
            ->assertForbidden();

        $other = Business::create(['name' => 'أخرى', 'type' => 'عام', 'status' => 'نشط']);
        $stranger = User::create([
            'business_id' => $other->id, 'name' => 'غريب', 'email' => 's@x.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($stranger)->post(route('admin.quotations.email', $q->id), ['to' => 'buyer@ministry.om'])
            ->assertNotFound();

        Mail::assertNothingSent();
    }

    /* ─────────────────────────── القناة ─────────────────────────── */

    public function test_a_sink_mailer_is_refused_and_the_screen_hides_the_button(): void
    {
        config(['mail.default' => 'log']);
        $q = $this->sent();

        $this->assertFalse(Notifiers::email()->configured());

        $this->actingAs($this->owner)->post(route('admin.quotations.email', $q->id), ['to' => 'buyer@ministry.om'])
            ->assertSessionHasErrors('email');

        Mail::assertNothingSent();
        $this->assertNull($q->fresh()->sent_to);

        $this->actingAs($this->owner)->get(route('admin.quotations.show', $q->id))
            ->assertInertia(fn ($p) => $p->where('may.email', false)->whereNot('may.mail_reason', null)->etc());
    }

    public function test_the_screen_offers_the_button_when_mail_delivers(): void
    {
        $q = $this->sent();

        $this->actingAs($this->owner)->get(route('admin.quotations.show', $q->id))
            ->assertInertia(fn ($p) => $p->where('may.email', true)->where('may.mail_reason', null)
                ->where('quotation.sent_to', null)->etc());
    }

    public function test_the_notifier_reports_a_failed_delivery_instead_of_throwing(): void
    {
        $notifier = new EmailNotifier;
        $this->assertSame('email', $notifier->channel());

        config(['mail.default' => 'array']);
        $result = $notifier->send(new MessageIntent(to: 'x@y.om', subject: 'س', paragraphs: ['ن']));

        $this->assertFalse($result->sent);
        $this->assertNotNull($result->reason);
    }

    public function test_the_service_refuses_before_touching_the_mailer(): void
    {
        config(['mail.default' => 'log']);
        $q = $this->sent();

        $this->expectException(RuntimeException::class);
        Quotations::email($q, 'buyer@ministry.om', null, $this->owner);
    }
}
