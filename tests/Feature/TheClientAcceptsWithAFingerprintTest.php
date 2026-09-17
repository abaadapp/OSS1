<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\QuotationController;
use App\Mail\DocumentMail;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\DocumentApproval;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\User;
use App\Support\Fingerprint;
use App\Support\PublicDocument;
use App\Support\Quotations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * العميلُ يقبل من رابطه — والقبولُ دليلٌ لا زرّ.
 *
 * ═══ ما يجعله دليلًا (§19) ═══
 *
 * البصمةُ تُقارَن بما خُتم عند الإرسال، واللقطةُ هي الحمولةُ التي بُصمت،
 * والموقّعُ ونصُّ إقراره والعنوانُ والمتصفّحُ تُكتب مرّةً. ومن شكّ بعد
 * سنةٍ أعاد الحسابَ وقارن.
 *
 * ═══ وما يُفحص هنا ═══
 *
 * القبولُ والاعتذارُ من الرابط · الدليلُ وإعادةُ فحصه · الرفضُ لما لا
 * يقبل قرارًا (مسودّة · منتهٍ · مقرَّر · مُسّ بعد الإرسال) · القرارُ الواحد ·
 * العزلُ · الخنق · والخبرُ إلى الشركة.
 */
class TheClientAcceptsWithAFingerprintTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

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

        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'وزارة', 'legal_name' => 'وزارة الطاقة',
        ]);

        $this->service = Product::create([
            'business_id' => $this->business->id, 'name' => 'تشغيل نظام تسجيل',
            'kind' => Product::SERVICE, 'price' => 500,
        ]);
    }

    private function sent(array $over = []): Quotation
    {
        $this->actingAs($this->owner);

        $quotation = Quotations::create(
            (int) $this->business->id,
            $this->customer,
            $over + ['title' => 'مؤتمر الطاقة', 'issued_at' => now()->toDateString(),
                'valid_until' => now()->addDays(30)->toDateString(), 'contact_email' => 'buyer@ministry.om'],
            [['product_id' => $this->service->id, 'quantity' => 2, 'unit_price' => 500]],
            $this->owner,
        );

        $sent = Quotations::send($quotation, $this->owner);
        auth()->logout();

        return $sent;
    }

    private function url(Quotation $q): string
    {
        return (string) PublicDocument::url($q);
    }

    /** @return array<string, mixed> */
    private function acceptance(array $over = []): array
    {
        return $over + [
            'decision' => 'accepted', 'name' => 'سالم الهنائي', 'title' => 'مدير المشتريات',
            'email' => 'salim@ministry.om', 'phone' => '+96891234567', 'consent' => '1',
        ];
    }

    /* ─────────────────────────── القبول ─────────────────────────── */

    public function test_the_client_accepts_from_the_link_and_evidence_is_written_once(): void
    {
        $q = $this->sent();

        $this->post($this->url($q).'/decision', $this->acceptance())
            ->assertRedirect($this->url($q))
            ->assertSessionHasNoErrors();

        $q->refresh();
        $this->assertSame(Quotation::ACCEPTED, $q->status);
        $this->assertNotNull($q->decided_at);
        $this->assertNull($q->decided_by, 'قرارُ العميل لا يُنسب إلى موظّف');
        $this->assertSame('سالم الهنائي', $q->decided_by_name);

        $approval = $q->approval;
        $this->assertNotNull($approval);
        $this->assertSame(DocumentApproval::ACCEPTED, $approval->decision);
        $this->assertSame(DocumentApproval::INTERNAL, $approval->provider);
        $this->assertSame((int) $this->business->id, (int) $approval->business_id);
        $this->assertSame('مدير المشتريات', $approval->signer_title);
        $this->assertSame('salim@ministry.om', $approval->signer_email);
        $this->assertNotEmpty($approval->ip);
        $this->assertNotNull($approval->decided_at);
        /* ونصُّ الإقرار بحرفه — لا مفتاحُه */
        $this->assertStringContainsString('أقرّ بأنني مخوّل', (string) $approval->consent_text);
        $this->assertStringContainsString('افنت بلس', (string) $approval->consent_text);
    }

    /**
     * البصمةُ في الدليل هي بصمةُ الإرسال — واللقطةُ تعيد إنتاجَها.
     *
     * دليلٌ لا يُعاد فحصُه شهادة: من شكّ بعد سنةٍ حسب `sha256` على
     * اللقطة وقارن بالبصمة، فإن تطابقا فما قُبل هو هذا بعينه.
     */
    public function test_the_evidence_carries_the_fingerprint_and_the_snapshot_reproduces_it(): void
    {
        $q = $this->sent();
        $this->post($this->url($q).'/decision', $this->acceptance())->assertSessionHasNoErrors();

        $approval = $q->fresh()->approval;

        $this->assertSame($q->content_hash, $approval->content_hash);
        $this->assertTrue($approval->verify());
        $this->assertSame($approval->content_hash, Fingerprint::of($approval->accepted_snapshot));
        /* واللقطةُ تحمل البنودَ التي قُبلت — بأسعارها */
        $this->assertSame('500.000', $approval->accepted_snapshot['items'][0]['n']);
        $this->assertSame('1050.000', $approval->accepted_snapshot['totals'][3]);
    }

    public function test_a_tampered_snapshot_fails_verification(): void
    {
        $q = $this->sent();
        $this->post($this->url($q).'/decision', $this->acceptance())->assertSessionHasNoErrors();

        $approval = $q->fresh()->approval;
        $snapshot = $approval->accepted_snapshot;
        $snapshot['totals'][3] = '1.000';
        $approval->forceFill(['accepted_snapshot' => $snapshot])->save();

        $this->assertFalse($approval->fresh()->verify());
    }

    /**
     * وعرضٌ مُسّ في القاعدة بعد إرساله لا يُقبل.
     *
     * العميلُ يقبل ما رآه لا ما صار: بصمةُ الصفّ الآن تُقارَن بما خُتم
     * عند الإرسال، فإن افترقا رُدّ القرارُ — لا يُكتب دليلٌ على ورقةٍ
     * تغيّرت تحت يد قارئها.
     */
    public function test_a_quotation_altered_after_sending_refuses_the_decision(): void
    {
        $q = $this->sent();
        $q->items()->first()->forceFill(['unit_price' => 1, 'line_total' => 1])->saveQuietly();

        $this->post($this->url($q).'/decision', $this->acceptance())
            ->assertSessionHasErrors('decision');

        $this->assertSame(Quotation::SENT, $q->fresh()->status);
        $this->assertSame(0, DocumentApproval::count());
    }

    /* ─────────────────────────── الاعتذار ─────────────────────────── */

    public function test_the_client_declines_with_a_reason(): void
    {
        $q = $this->sent();

        $this->post($this->url($q).'/decision', [
            'decision' => 'declined', 'name' => 'سالم', 'decline_reason' => 'اخترنا عرضًا آخر',
        ])->assertSessionHasNoErrors();

        $q->refresh();
        $this->assertSame(Quotation::REJECTED, $q->status);
        $this->assertSame('اخترنا عرضًا آخر', $q->rejection_reason);
        $this->assertSame(DocumentApproval::DECLINED, $q->approval->decision);
        $this->assertNull($q->approval->consent_text, 'من يعتذر لا يُقرّ بشيء');
    }

    public function test_declining_needs_a_reason_and_accepting_needs_consent(): void
    {
        $q = $this->sent();

        $this->post($this->url($q).'/decision', ['decision' => 'declined', 'name' => 'سالم'])
            ->assertSessionHasErrors('decision');

        $this->post($this->url($q).'/decision', $this->acceptance(['consent' => null]))
            ->assertSessionHasErrors('consent');

        $this->post($this->url($q).'/decision', $this->acceptance(['name' => '']))
            ->assertSessionHasErrors('name');

        $this->assertSame(Quotation::SENT, $q->fresh()->status);
        $this->assertSame(0, DocumentApproval::count());
    }

    /* ─────────────────────────── ما لا يقبل قرارًا ─────────────────────────── */

    public function test_a_decision_is_written_once(): void
    {
        $q = $this->sent();
        $this->post($this->url($q).'/decision', $this->acceptance())->assertSessionHasNoErrors();

        $this->post($this->url($q).'/decision', [
            'decision' => 'declined', 'name' => 'آخر', 'decline_reason' => 'غيّرنا رأينا',
        ])->assertSessionHasErrors('decision');

        $this->assertSame(Quotation::ACCEPTED, $q->fresh()->status);
        $this->assertSame(1, DocumentApproval::count());
    }

    public function test_an_expired_quotation_does_not_accept(): void
    {
        $q = $this->sent(['valid_until' => now()->subDay()->toDateString()]);

        $this->post($this->url($q).'/decision', $this->acceptance())->assertSessionHasErrors('decision');

        $this->assertSame(Quotation::SENT, $q->fresh()->status);
        $this->assertSame('expired', $q->fresh()->presentedState());

        /* والصفحةُ تقول ذلك ولا ترسم الزرّين */
        $this->get($this->url($q))->assertOk()
            ->assertSee('انتهت صلاحية هذا العرض')
            ->assertDontSee('أقبل العرض');
    }

    public function test_a_draft_cannot_be_decided_even_with_its_token(): void
    {
        $this->actingAs($this->owner);
        $draft = Quotations::create((int) $this->business->id, $this->customer, ['title' => 'م'],
            [['product_id' => $this->service->id, 'quantity' => 1, 'unit_price' => 500]], $this->owner);
        $token = PublicDocument::token($draft);
        auth()->logout();

        $this->post('/i/'.$token.'/decision', $this->acceptance())->assertNotFound();
        $this->assertSame(Quotation::DRAFT, $draft->fresh()->status);
    }

    public function test_a_cancelled_quotation_does_not_accept(): void
    {
        $q = $this->sent();
        $this->actingAs($this->owner);
        Quotations::cancel($q, 'أُلغيت الفعاليّة', $this->owner);
        auth()->logout();

        $this->post($this->url($q).'/decision', $this->acceptance())->assertSessionHasErrors('decision');
        $this->assertSame(Quotation::CANCELLED, $q->fresh()->status);
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->post('/i/AAAAAAAAAAAAAAAAAAAAAA/decision', $this->acceptance())->assertNotFound();
    }

    /* ─────────────────────────── الصفحة ─────────────────────────── */

    public function test_the_page_shows_the_decision_panel_then_the_confirmation(): void
    {
        $q = $this->sent();

        $this->get($this->url($q))->assertOk()
            ->assertSee('قراركم على هذا العرض')
            ->assertSee('أقبل العرض')
            ->assertSee('أعتذر عن العرض')
            ->assertSee('buyer@ministry.om');

        $this->post($this->url($q).'/decision', $this->acceptance())->assertSessionHasNoErrors();

        $this->get($this->url($q))->assertOk()
            ->assertSee('تمّ قبول العرض')
            ->assertSee('سالم الهنائي')
            ->assertSee($q->fresh()->content_hash)
            ->assertDontSee('أقبل العرض');
    }

    /** والورقةُ نفسُها تحمل قبولَها بعده — فالـPDF المطبوع بعد القبول يشهد */
    public function test_the_paper_prints_the_acceptance(): void
    {
        $q = $this->sent();
        $this->post($this->url($q).'/decision', $this->acceptance())->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get(route('admin.quotations.show', $q->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('quotation.approval.signer_name', 'سالم الهنائي')
                ->where('quotation.approval.verified', true)
                ->where('quotation.status', Quotation::ACCEPTED)
                ->etc());

        $html = QuotationController::paper((int) $this->business->id, $q->fresh()->load('items'))->render();
        $this->assertStringContainsString('سالم الهنائي', $html);
        $this->assertStringContainsString(substr($q->fresh()->content_hash, 0, 16), $html);
    }

    /* ─────────────────────────── الأثر والخبر ─────────────────────────── */

    public function test_the_decision_is_logged_with_the_signer_and_not_as_a_user(): void
    {
        $q = $this->sent();
        $this->post($this->url($q).'/decision', $this->acceptance())->assertSessionHasNoErrors();

        $row = ActivityLog::where('subject_type', 'quotation')->where('subject_id', $q->id)
            ->where('description', 'like', '%قبل العميل%')->first();

        $this->assertNotNull($row);
        $this->assertSame((int) $this->business->id, (int) $row->business_id);
        $this->assertNull($row->user_id);
        $this->assertStringContainsString('سالم الهنائي', $row->description);
        $this->assertStringContainsString('salim@ministry.om', $row->description);
    }

    public function test_the_company_and_the_signer_are_emailed_when_mail_is_configured(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.om']);
        Mail::fake();

        $q = $this->sent();
        $this->post($this->url($q).'/decision', $this->acceptance())->assertSessionHasNoErrors();

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $m) => $m->hasTo('hello@hadath.om')
            && str_contains($m->intent->subject, 'قبل العميل'));
        Mail::assertSent(DocumentMail::class, fn (DocumentMail $m) => $m->hasTo('salim@ministry.om')
            && str_contains($m->intent->subject, 'تأكيد قبولكم'));
    }

    public function test_no_mail_is_attempted_when_the_mailer_is_a_sink(): void
    {
        config(['mail.default' => 'log']);
        Mail::fake();

        $q = $this->sent();
        $this->post($this->url($q).'/decision', $this->acceptance())->assertSessionHasNoErrors();

        Mail::assertNothingSent();
        $this->assertSame(Quotation::ACCEPTED, $q->fresh()->status, 'والقرارُ ثبت وإن لم يُرسَل بريد');
    }

    /* ─────────────────────────── العزل والخنق ─────────────────────────── */

    public function test_the_evidence_belongs_to_the_company_and_another_cannot_read_it(): void
    {
        $q = $this->sent();
        $this->post($this->url($q).'/decision', $this->acceptance())->assertSessionHasNoErrors();

        $other = Business::create(['name' => 'أخرى', 'type' => 'عام', 'status' => 'نشط']);
        $stranger = User::create([
            'business_id' => $other->id, 'name' => 'غريب', 'email' => 's@x.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($stranger)->get(route('admin.quotations.show', $q->id))->assertNotFound();
        $this->assertSame((int) $this->business->id, (int) DocumentApproval::sole()->business_id);
    }

    public function test_the_door_is_throttled_by_address(): void
    {
        $q = $this->sent();

        foreach (range(1, 10) as $i) {
            $this->post($this->url($q).'/decision', ['decision' => 'declined', 'name' => 'x']);
        }

        $this->post($this->url($q).'/decision', $this->acceptance())->assertSessionHasErrors('decision');
        $this->assertSame(Quotation::SENT, $q->fresh()->status);
    }

    /* ─────────────────────────── الخدمة مباشرةً ─────────────────────────── */

    public function test_the_service_refuses_an_unknown_decision(): void
    {
        $q = $this->sent();

        $this->expectException(RuntimeException::class);
        Quotations::decide($q, 'maybe', ['name' => 'س']);
    }

    /** والقرارُ من داخل النظام يبقى بلا دليلٍ إلكترونيّ — خبرٌ لا قبول */
    public function test_a_staff_recorded_decision_writes_no_evidence(): void
    {
        $q = $this->sent();
        $this->actingAs($this->owner);
        Quotations::recordDecision($q, Quotation::ACCEPTED, ['decided_by_name' => 'هاتفيًّا'], $this->owner);

        $this->assertSame(0, DocumentApproval::count());
        $this->assertSame((int) $this->owner->id, (int) $q->fresh()->decided_by);
    }
}
