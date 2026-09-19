<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * ما فشل يُعاد بيدٍ — لا يُعاد وحده، ولا يُعاد مرّتين.
 *
 * الإعادةُ صفٌّ جديد يشير إلى أصله بمعرّفٍ جديدٍ عند المزوّد، وتحتاج إذنًا
 * باسمها. وما نجح لا يُعاد، وما نجحت إعادتُه لا يُعاد ثانيةً — رسالةٌ
 * ماليّةٌ تصل مرّتين أسوأ من رسالةٍ لا تصل.
 */
class AFailedMessageIsRetriedByHandNotByAccidentTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $manager;

    private Customer $customer;

    private Quotation $quotation;

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
        /* مديرٌ مُنح السجلَّ ولم يُمنح الإعادة */
        $this->manager = User::create([
            'business_id' => $this->business->id, 'name' => 'مدير', 'email' => 'm@hadath.om',
            'password' => bcrypt('password'), 'role' => 'manager', 'status' => 'نشط',
            'permissions' => ['dashboard', 'communications', 'quotations', 'messaging.send'],
        ]);
        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة', 'phone' => '91234567']);
        $service = Product::create(['business_id' => $this->business->id, 'name' => 'خدمة', 'kind' => Product::SERVICE, 'price' => 500]);

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.om']);
        config(['services.whatsapp.token' => 'tok', 'services.whatsapp.phone_number_id' => '1050000']);
        Mail::fake();

        $this->actingAs($this->owner);
        $q = Quotations::create((int) $this->business->id, $this->customer,
            ['title' => 'مؤتمر', 'attention_to' => 'سالم', 'issued_at' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString()],
            [['product_id' => $service->id, 'quantity' => 1, 'unit_price' => 500]], $this->owner);
        $this->quotation = Quotations::send($q, $this->owner);

        Templates::save((int) $this->business->id, 'quotation.link', 'whatsapp', 'ar', [
            'provider_template' => 'quotation_link', 'status' => 'active',
        ], $this->owner);
    }

    private const REFUSED = ['error' => ['code' => 131047, 'message' => 'Re-engagement message']];

    /**
     * ردودُ المزوّد بترتيبها — الأوّل للإرسال الأصليّ وما بعده للإعادات.
     *
     * و`Http::fake` يراكم ما يُسجَّل ويُقدّم الأقدم، فيُكتب التسلسلُ مرّةً.
     *
     * @param  list<array{0: array, 1: int}>  $responses
     */
    private function provider(array $responses): void
    {
        $sequence = Http::fakeSequence('graph.facebook.com/*');
        foreach ($responses as [$body, $status]) {
            $sequence->push($body, $status);
        }
    }

    /** إرسالٌ يفشل عند المزوّد — ويبقى في السجلّ فاشلًا */
    private function failed(array $then = []): Communication
    {
        $this->provider([[self::REFUSED, 400], ...$then]);

        $this->actingAs($this->owner)->post(route('admin.quotations.communications.send', $this->quotation->id), [
            'channel' => 'whatsapp', 'template' => 'quotation.link', 'to' => '91234567',
        ])->assertSessionHasErrors('communication');

        return Communication::sole();
    }

    public function test_a_failed_message_is_retried_into_a_new_row_that_points_at_its_origin(): void
    {
        $original = $this->failed([[['messages' => [['id' => 'wamid.RETRY']]], 200]]);
        $this->assertSame(Communication::FAILED, $original->status);
        $this->assertTrue($original->isRetryable());

        $this->actingAs($this->owner)->post(route('admin.communications.retry', $original->id))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, Communication::count());
        $retry = Communication::where('retry_of_id', $original->id)->sole();

        $this->assertSame(Communication::SENT, $retry->status);
        $this->assertSame('wamid.RETRY', $retry->provider_message_id);
        $this->assertSame($original->recipient, $retry->recipient);
        $this->assertSame($original->template_key, $retry->template_key);
        $this->assertSame($original->subject_id, $retry->subject_id);
        $this->assertSame(Communication::FAILED, $original->fresh()->status, 'الأصلُ يبقى كما كان — لا يُعاد كتابتُه');
        $this->assertNull($original->fresh()->provider_message_id);
        $this->assertFalse($original->fresh()->isRetryable(), 'نجحت إعادتُه — فلا يُعاد ثانيةً');

        $this->assertTrue(ActivityLog::where('subject_type', 'communication')->where('subject_id', $retry->id)
            ->where('description', 'like', 'أعاد إرسال%')->exists());

        $this->actingAs($this->owner)->get(route('admin.communications.show', $original->id))
            ->assertInertia(fn ($p) => $p->where('communication.retryable', false)
                ->where('communication.retries.0.id', $retry->id)
                ->where('communication.retries.0.status', 'sent')->etc());
        $this->actingAs($this->owner)->get(route('admin.communications.show', $retry->id))
            ->assertInertia(fn ($p) => $p->where('communication.retry_of.id', $original->id)->etc());
    }

    public function test_a_second_retry_after_a_successful_one_is_refused(): void
    {
        $original = $this->failed([[['messages' => [['id' => 'wamid.R1']]], 200]]);

        $this->actingAs($this->owner)->post(route('admin.communications.retry', $original->id))->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('admin.communications.retry', $original->id))->assertSessionHasErrors('retry');

        $this->assertSame(2, Communication::count());
        /* الأصلُ والإعادةُ الأولى — ولا ثالث */
        Http::assertSentCount(2);
    }

    public function test_a_failed_retry_may_be_retried_again_and_the_chain_stays_readable(): void
    {
        $original = $this->failed([
            [['error' => ['code' => 500, 'message' => 'down']], 500],
            [['messages' => [['id' => 'wamid.R2']]], 200],
        ]);

        $this->actingAs($this->owner)->post(route('admin.communications.retry', $original->id))->assertSessionHasErrors('retry');

        $first = Communication::where('retry_of_id', $original->id)->sole();
        $this->assertSame(Communication::FAILED, $first->status);
        $this->assertTrue($original->fresh()->isRetryable(), 'إعادةٌ فشلت لا تُغلق الباب');

        $this->actingAs($this->owner)->post(route('admin.communications.retry', $original->id))->assertSessionHasNoErrors();

        $this->assertSame(3, Communication::count());
        $this->assertSame(2, Communication::where('retry_of_id', $original->id)->count());
        $this->assertFalse($original->fresh()->isRetryable());
    }

    public function test_a_successful_message_is_never_resent_through_retry(): void
    {
        $this->provider([[['messages' => [['id' => 'wamid.OK']]], 200]]);
        $this->actingAs($this->owner)->post(route('admin.quotations.communications.send', $this->quotation->id), [
            'channel' => 'whatsapp', 'template' => 'quotation.link', 'to' => '91234567',
        ])->assertSessionHasNoErrors();
        $sent = Communication::sole();

        $this->actingAs($this->owner)->post(route('admin.communications.retry', $sent->id))->assertSessionHasErrors('retry');

        $this->assertSame(1, Communication::count());
        Http::assertSentCount(1);

        $this->expectException(RuntimeException::class);
        Communications::retry($sent, $this->owner);
    }

    public function test_retry_needs_its_own_permission_and_may_correct_the_recipient(): void
    {
        $original = $this->failed([[['messages' => [['id' => 'wamid.FIX']]], 200]]);

        $this->actingAs($this->manager)->post(route('admin.communications.retry', $original->id))->assertForbidden();
        $this->assertSame(1, Communication::count());

        $this->actingAs($this->owner)->post(route('admin.communications.retry', $original->id), ['to' => '+968 9999 0000'])
            ->assertSessionHasNoErrors();

        $retry = Communication::where('retry_of_id', $original->id)->sole();
        $this->assertSame('96899990000', $retry->recipient, 'العنوانُ المصحَّح يُطبَّع كغيره');
        $this->assertSame($this->owner->id, (int) $retry->initiated_by);
    }

    public function test_a_bounced_email_is_retryable_and_the_retry_goes_out_as_a_fresh_email(): void
    {
        config(['services.postmark.webhook_secret' => 'pm']);

        $this->actingAs($this->owner)->post(route('admin.quotations.email', $this->quotation->id), ['to' => 'buyer@ministry.om'])
            ->assertSessionHasNoErrors();
        $c = Communication::sole();
        /* المزوّدُ الوهميّ لا يعيد معرّفًا — فيُكتب كما لو أعاده */
        $c->update(['provider' => 'postmark', 'provider_message_id' => 'pm-b']);

        $this->postJson(route('webhooks.postmark'), ['RecordType' => 'Bounce', 'ID' => 1, 'Type' => 'HardBounce', 'MessageID' => 'pm-b',
            'Description' => 'mailbox not found', 'BouncedAt' => '2026-09-19T10:36:00Z'],
            ['Authorization' => 'Basic '.base64_encode('x:pm')])->assertOk();

        $this->assertSame(Communication::BOUNCED, $c->fresh()->status);

        $this->actingAs($this->owner)->post(route('admin.communications.retry', $c->id), ['to' => 'correct@ministry.om'])
            ->assertSessionHasNoErrors();

        $retry = Communication::where('retry_of_id', $c->id)->sole();
        $this->assertSame('email', $retry->channel);
        $this->assertSame('correct@ministry.om', $retry->recipient);
        $this->assertSame(Communication::SENT, $retry->status);
        Mail::assertSentCount(2);
    }
}
