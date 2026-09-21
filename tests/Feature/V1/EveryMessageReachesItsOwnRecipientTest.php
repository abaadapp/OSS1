<?php

namespace Tests\Feature\V1;

use App\Mail\DocumentMail;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Communication;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\User;
use App\Support\Communication\Communications;
use App\Support\Communication\Templates;
use App\Support\Ledger;
use App\Support\Quotations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * البصمةُ الذهبيّة للمراسلات — المرحلة 12 (§26 · §92).
 *
 * ثلاثةُ عملاءَ بثلاثِ لغاتٍ وثلاثِ عروض، تُرسَل في دفعةٍ واحدة بالبريد
 * وبواتساب: كلُّ رسالةٍ تصل إلى صاحبها وحدَه، بورقته وحدَها، وبلغته، ورابطُها
 * رابطُ ورقته — ولا مزوّدَ يُنادى فعلًا (بريدٌ مزيَّف وMeta مزيَّفة).
 *
 * والإعادةُ لا تكرّر ناجحًا، والتكرارُ في لحظاتٍ لا يخرج مرّتين.
 */
class EveryMessageReachesItsOwnRecipientTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    /** @var array<int, array{customer: Customer, quotation: Quotation, email: string, phone: string, language: string}> */
    private array $set = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.om']);
        config(['services.whatsapp.token' => 'tok', 'services.whatsapp.phone_number_id' => '1', 'services.whatsapp.app_secret' => 's', 'services.whatsapp.verify_token' => 'v']);

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط', 'email' => 'hello@eventplus.om']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);
        Ledger::seedChart((int) $this->business->id);
        $this->owner = User::create(['business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@eventplus.om', 'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط']);
        $this->actingAs($this->owner);

        Templates::save((int) $this->business->id, 'quotation.link', 'whatsapp', 'ar', ['provider_template' => 'quotation_link', 'provider_language' => 'ar', 'status' => 'active', 'variables' => ['contact_name', 'label', 'url']], $this->owner);
        Templates::save((int) $this->business->id, 'quotation.link', 'whatsapp', 'en', ['provider_template' => 'quotation_link_en', 'provider_language' => 'en', 'status' => 'active', 'variables' => ['contact_name', 'label', 'url']], $this->owner);

        foreach ([['وزارة الطاقة', 'energy@ministry.om', '+96891111111', 'ar'], ['Oman Airports', 'airports@oman.om', '+96892222222', 'en'], ['بنك مسقط', 'bank@muscat.om', '+96893333333', 'ar']] as $i => [$name, $email, $phone, $lang]) {
            $customer = Customer::create(['business_id' => $this->business->id, 'name' => $name, 'email' => $email, 'phone' => $phone]);
            $q = Quotations::create((int) $this->business->id, $customer, ['title' => 'مؤتمر '.($i + 1), 'issued_at' => now()->toDateString(), 'contact_email' => $email, 'contact_phone' => $phone],
                [['description' => 'تنظيم', 'quantity' => 1, 'unit_price' => 1000 * ($i + 1)]], $this->owner);
            $q = Quotations::send($q, $this->owner);
            $this->set[] = ['customer' => $customer, 'quotation' => $q->fresh(), 'email' => $email, 'phone' => $phone, 'language' => $lang];
        }
    }

    public function test_a_burst_of_emails_pairs_each_recipient_with_its_own_document_and_language(): void
    {
        foreach ($this->set as $row) {
            Communications::send($row['quotation'], 'email', 'quotation.link', $row['email'], $this->owner, ['language' => $row['language']]);
        }

        $this->assertSame(3, Communication::where('business_id', $this->business->id)->count());
        Mail::assertSent(DocumentMail::class, 3);

        foreach ($this->set as $row) {
            $c = Communication::where('recipient', $row['email'])->sole();
            $this->assertSame($row['quotation']->id, (int) $c->subject_id, 'الرسالةُ لصاحب الورقة');
            $this->assertSame($row['customer']->id, (int) $c->customer_id);
            $this->assertSame($row['language'], $c->language ?? $row['language']);

            Mail::assertSent(DocumentMail::class, function (DocumentMail $m) use ($row) {
                if (! $m->hasTo($row['email'])) {
                    return false;
                }
                /* وورقتُها هي: الرابطُ رابطُ ورقته وحدَه — البريدُ القديم لا يحمل `about` في نيّته */
                $this->assertStringContainsString((string) \App\Support\PublicDocument::token($row['quotation']), (string) $m->intent->url, 'رسالةٌ إلى '.$row['email'].' تحمل رابطَ غيره');

                return true;
            });
        }

        /* ولا رسالةٌ إلى عنوانٍ تحمل ورقةً لا يملكها */
        $tokens = collect($this->set)->mapWithKeys(fn ($r) => [$r['email'] => \App\Support\PublicDocument::token($r['quotation'])]);
        Mail::assertSent(DocumentMail::class, function (DocumentMail $m) use ($tokens) {
            foreach ($tokens as $email => $token) {
                if (! $m->hasTo($email) && str_contains((string) $m->intent->url, (string) $token)) {
                    $this->fail("رابطُ $email وصل إلى غيره");
                }
            }

            return true;
        });
    }

    public function test_a_burst_of_whatsapp_messages_calls_meta_once_per_recipient_with_its_own_link(): void
    {
        $calls = [];
        Http::fake(function ($request) use (&$calls) {
            $calls[] = $request->data();

            return Http::response(['messages' => [['id' => 'wamid.'.count($calls)]]], 200);
        });

        foreach ($this->set as $row) {
            Communications::send($row['quotation'], 'whatsapp', 'quotation.link', $row['phone'], $this->owner, ['language' => $row['language']]);
        }

        $this->assertCount(3, $calls);
        foreach ($this->set as $i => $row) {
            $call = collect($calls)->first(fn ($c) => ($c['to'] ?? '') === ltrim($row['phone'], '+'));
            $this->assertNotNull($call, 'لا نداءَ لـ'.$row['phone']);
            $this->assertSame($row['language'] === 'en' ? 'quotation_link_en' : 'quotation_link', $call['template']['name'] ?? null, 'القالبُ بلغة المستلم');
            $params = json_encode($call['template']['components'] ?? []);
            $this->assertStringContainsString((string) \App\Support\PublicDocument::token($row['quotation']), $params, 'رابطُ ورقته هو');
            foreach ($this->set as $j => $other) {
                if ($j !== $i) {
                    $this->assertStringNotContainsString((string) \App\Support\PublicDocument::token($other['quotation']), $params, 'رابطُ غيره في رسالته');
                }
            }
        }
        $this->assertSame(3, Communication::where('channel', 'whatsapp')->where('status', Communication::SENT)->count());
        $this->assertCount(3, Communication::pluck('provider_message_id')->unique());
    }

    public function test_the_same_message_twice_in_a_moment_goes_out_once_and_a_sent_message_is_never_retried(): void
    {
        $row = $this->set[0];
        $first = Communications::send($row['quotation'], 'email', 'quotation.link', $row['email'], $this->owner);

        try {
            Communications::send($row['quotation'], 'email', 'quotation.link', $row['email'], $this->owner);
            $this->fail('أُرسلت مرّتين في لحظة');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('قبل لحظات', $e->getMessage());
        }
        Mail::assertSent(DocumentMail::class, 1);

        try {
            Communications::retry($first, $this->owner);
            $this->fail('أُعيد إرسالُ ناجح');
        } catch (\RuntimeException) {
            $this->assertTrue(true);
        }
        Mail::assertSent(DocumentMail::class, 1);
    }
}
