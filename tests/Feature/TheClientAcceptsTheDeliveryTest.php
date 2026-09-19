<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\DeliveryPackage;
use App\Models\DeliveryPackageItem;
use App\Models\DocumentApproval;
use App\Models\DocumentLink;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\DeliveryPackages;
use App\Support\Fingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * العميلُ يقبل التسليم من رابطه — والقبولُ دليلٌ لا زرّ.
 *
 * ما يُفحص: الرابطُ لما خرج وحدَه · الملفّاتُ من الرابط لحزمته وحدَها ·
 * القبولُ دليلًا مبصومًا يُكتب مرّةً · الرفضُ بسببٍ يبقى · ردُّ ما مُسّ
 * بعد الإرسال · العزلُ بين الشركات · والخبرُ إلى الشركة.
 */
class TheClientAcceptsTheDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('local');

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط', 'email' => 'hello@hadath.om']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@hadath.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة', 'legal_name' => 'وزارة الطاقة']);
    }

    protected function sent(): DeliveryPackage
    {
        $this->actingAs($this->owner);

        $p = DeliveryPackages::create((int) $this->business->id, $this->customer,
            ['title' => 'تسليم مؤتمر الطاقة', 'client_notes' => 'التقارير مرفقة', 'contact_email' => 'buyer@ministry.om'], $this->owner);
        DeliveryPackages::addItem($p, ['kind' => 'item', 'title' => 'تشغيل نظام التسجيل', 'quantity' => 2, 'unit' => 'يوم'], null, $this->owner);
        DeliveryPackages::addItem($p, ['kind' => 'file', 'title' => 'تقرير الحضور'],
            UploadedFile::fake()->create('attendance.pdf', 50, 'application/pdf'), $this->owner);

        $sent = DeliveryPackages::send(DeliveryPackages::ready($p->fresh(), $this->owner), $this->owner);
        auth()->logout();

        return $sent;
    }

    protected function url(DeliveryPackage $p): string
    {
        return (string) DeliveryPackages::url($p);
    }

    /** @return array<string, mixed> */
    private function acceptance(array $over = []): array
    {
        return $over + [
            'decision' => 'accepted', 'name' => 'سالم الهنائي', 'title' => 'مدير المشروع',
            'email' => 'salim@ministry.om', 'phone' => '+96891234567', 'consent' => '1',
        ];
    }

    /* ─────────────────────────── الرابط ─────────────────────────── */

    public function test_the_link_opens_only_what_left_the_company(): void
    {
        $this->actingAs($this->owner);
        $draft = DeliveryPackages::create((int) $this->business->id, $this->customer, ['title' => 'مسودّة'], $this->owner);
        auth()->logout();

        /* رمزٌ يُبنى لمسودّة — ولا يفتحها */
        $this->get($this->url($draft))->assertNotFound();

        $sent = $this->sent();
        $this->assertStringContainsString('/d/', $this->url($sent));

        $this->get($this->url($sent))->assertOk()
            ->assertSee('تسليم مؤتمر الطاقة')
            ->assertSee('تشغيل نظام التسجيل')
            ->assertSee('تقرير الحضور')
            ->assertSee('التقارير مرفقة')
            ->assertSee('أقبل التسليم')
            ->assertSee($sent->content_hash)
            ->assertDontSee('ملاحظات داخلية');

        /* والفتحُ يُختم */
        $this->assertSame(1, $sent->fresh()->view_count);
        $this->assertNotNull($sent->fresh()->first_viewed_at);

        /* ورمزُ التسليم لا يفتح على باب الأوراق، ورمزُ عرضٍ لا يفتح على باب التسليم */
        $token = DocumentLink::where('linkable_type', $sent->getMorphClass())->value('token');
        $this->get(route('paper.show', $token))->assertNotFound();
        $this->get(route('delivery.show', str_repeat('x', 22)))->assertNotFound();

        /* وملغاةٌ لا تُفتح ولو كان رمزُها في يد العميل */
        $this->actingAs($this->owner);
        DeliveryPackages::cancel($sent, 'خطأ', $this->owner);
        auth()->logout();
        $this->get($this->url($sent))->assertNotFound();
    }

    public function test_files_stream_from_the_link_for_their_own_package_only(): void
    {
        $sent = $this->sent();
        $file = $sent->items->firstWhere('kind', DeliveryPackageItem::FILE);
        $line = $sent->items->firstWhere('kind', DeliveryPackageItem::ITEM);

        $this->get($this->url($sent).'/files/'.$file->id)->assertOk()->assertHeader('content-disposition', 'inline');
        $this->get($this->url($sent).'/files/'.$line->id)->assertNotFound();
        $this->get($this->url($sent).'/files/999')->assertNotFound();

        /* وبندُ حزمةٍ أخرى من رابط هذه: ٤٠٤ */
        $other = $this->sent();
        $otherFile = $other->items->firstWhere('kind', DeliveryPackageItem::FILE);
        $this->get($this->url($sent).'/files/'.$otherFile->id)->assertNotFound();

        /* والبابُ العامّ لا يفتح ملفَّ مسودّة */
        $this->actingAs($this->owner);
        $draft = DeliveryPackages::create((int) $this->business->id, $this->customer, ['title' => 'مسودّة'], $this->owner);
        $draftFile = DeliveryPackages::addItem($draft, ['kind' => 'file', 'title' => 'سرّي'],
            UploadedFile::fake()->create('secret.pdf', 5, 'application/pdf'), $this->owner);
        auth()->logout();
        $this->get($this->url($draft).'/files/'.$draftFile->id)->assertNotFound();
    }

    /* ─────────────────────────── القبول ─────────────────────────── */

    public function test_the_client_accepts_and_the_evidence_is_written_once(): void
    {
        $p = $this->sent();

        $this->post($this->url($p).'/decision', $this->acceptance())
            ->assertRedirect($this->url($p))
            ->assertSessionHas('decided', 'accepted');

        $p = $p->fresh('approval');
        $this->assertSame(DeliveryPackage::ACCEPTED, $p->status);
        $this->assertNotNull($p->accepted_at);
        $this->assertSame('سالم الهنائي', $p->decided_by_name);
        $this->assertNull($p->decided_by);

        $a = $p->approval;
        $this->assertInstanceOf(DocumentApproval::class, $a);
        $this->assertSame(DocumentApproval::ACCEPTED, $a->decision);
        $this->assertSame($p->content_hash, $a->content_hash);
        $this->assertTrue($a->verify(), 'البصمةُ تُعاد من اللقطة وتطابق');
        $this->assertTrue(Fingerprint::matches($a->accepted_snapshot, $p->content_hash));
        $this->assertSame('salim@ministry.om', $a->signer_email);
        $this->assertStringContainsString('افنت بلس', $a->consent_text);
        $this->assertSame('127.0.0.1', $a->ip);
        $this->assertCount(2, $a->accepted_snapshot['items']);
        $this->assertSame($p->items->firstWhere('kind', 'file')->checksum, $a->accepted_snapshot['items'][1]['checksum']);

        /* والصفحةُ بعدها تقول «قُبل» ولا زرَّ */
        $this->get($this->url($p))->assertOk()->assertSee('تمّ قبول التسليم')->assertDontSee('أقبل التسليم');

        /* والقرارُ الثاني يُردّ — ولا صفَّ ثانيًا */
        $this->post($this->url($p).'/decision', $this->acceptance(['decision' => 'declined', 'decline_reason' => 'x']))
            ->assertSessionHasErrors('decision');
        $this->assertSame(1, DocumentApproval::count());
        $this->assertSame(DeliveryPackage::ACCEPTED, $p->fresh()->status);

        $this->assertTrue(ActivityLog::where('subject_type', 'delivery_package')
            ->where('description', 'like', 'قبل العميل حزمة التسليم%')->exists());
    }

    public function test_acceptance_needs_a_name_and_the_consent(): void
    {
        $p = $this->sent();

        $this->post($this->url($p).'/decision', $this->acceptance(['name' => '']))->assertSessionHasErrors('name');
        $this->post($this->url($p).'/decision', $this->acceptance(['consent' => '0']))->assertSessionHasErrors('consent');

        $this->assertSame(DeliveryPackage::SENT, $p->fresh()->status);
        $this->assertSame(0, DocumentApproval::count());
    }

    public function test_the_client_declines_with_a_reason_that_stays(): void
    {
        $p = $this->sent();

        $this->post($this->url($p).'/decision', $this->acceptance(['decision' => 'declined', 'consent' => '0']))
            ->assertSessionHasErrors('decision');

        $this->post($this->url($p).'/decision', $this->acceptance([
            'decision' => 'declined', 'consent' => '0', 'decline_reason' => 'تقرير الحضور ينقصه اليوم الثاني',
        ]))->assertRedirect($this->url($p));

        $p = $p->fresh('approval');
        $this->assertSame(DeliveryPackage::DECLINED, $p->status);
        $this->assertNotNull($p->declined_at);
        $this->assertSame('تقرير الحضور ينقصه اليوم الثاني', $p->decline_reason);
        $this->assertSame(DocumentApproval::DECLINED, $p->approval->decision);
        $this->assertSame('تقرير الحضور ينقصه اليوم الثاني', $p->approval->decline_reason);
        $this->assertNull($p->approval->consent_text);

        /* والمرفوضةُ تبقى تُفتح — دليلًا — ولا زرَّ عليها */
        $this->get($this->url($p))->assertOk()->assertSee('سُجّل ردّكم')->assertDontSee('أقبل التسليم');

        /* وإعادتُها لا تمسّ دليلَها */
        $this->actingAs($this->owner);
        $copy = DeliveryPackages::revise($p, $this->owner);
        $this->assertSame(DocumentApproval::DECLINED, $p->fresh('approval')->approval->decision);
        $this->assertSame($p->id, $copy->supersedes_id);
    }

    public function test_a_package_touched_after_sending_is_not_accepted(): void
    {
        $p = $this->sent();

        /* عبثٌ مباشرٌ في القاعدة بعد الخروج — لا من الدورة */
        DeliveryPackageItem::whereKey($p->items->first()->id)->update(['title' => 'بندٌ غُيّر']);

        $this->post($this->url($p).'/decision', $this->acceptance())->assertSessionHasErrors('decision');

        $this->assertSame(DeliveryPackage::SENT, $p->fresh()->status);
        $this->assertSame(0, DocumentApproval::count());
    }

    public function test_the_link_is_throttled_by_address(): void
    {
        $p = $this->sent();

        for ($i = 0; $i < 10; $i++) {
            $this->post($this->url($p).'/decision', ['decision' => 'accepted', 'name' => '']);
        }

        $this->post($this->url($p).'/decision', $this->acceptance())->assertSessionHasErrors('decision');
        $this->assertSame(DeliveryPackage::SENT, $p->fresh()->status);
    }

    public function test_another_company_cannot_read_the_package_or_its_approval(): void
    {
        $p = $this->sent();
        $this->post($this->url($p).'/decision', $this->acceptance());

        $other = Business::create(['name' => 'أخرى', 'type' => 'عام', 'status' => 'نشط']);
        $stranger = User::create([
            'business_id' => $other->id, 'name' => 'غريب', 'email' => 'x@other.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($stranger);
        $this->get(route('admin.deliveries.show', $p->id))->assertNotFound();
        $this->get(route('admin.deliveries.index'))->assertOk()->assertDontSee('تسليم مؤتمر الطاقة');
    }

    public function test_the_company_screen_shows_the_verified_evidence(): void
    {
        $p = $this->sent();
        $this->post($this->url($p).'/decision', $this->acceptance());

        $this->actingAs($this->owner);
        $this->get(route('admin.deliveries.show', $p->id))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/DeliveryPackages/Show')
                ->where('package.status', 'accepted')
                ->where('package.approval.verified', true)
                ->where('package.approval.signer_name', 'سالم الهنائي')
                ->where('package.readiness', null));
    }
}
