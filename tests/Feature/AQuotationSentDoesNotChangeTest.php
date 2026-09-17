<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\DocumentLink;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\User;
use App\Support\Document\Snapshot;
use App\Support\Permissions;
use App\Support\PublicDocument;
use App\Support\Quotations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * ما خرج إلى العميل لا يتغيّر بصمت.
 *
 * ═══ المطلب ═══
 *
 * «بعد إرسال Quotation: لا يسمح بتغيير محتواه التجاري بصمت… ويظل Revision 1
 * محفوظًا. واحدة فقط تكون Current Revision. لا تمسح التاريخ.»
 *
 * ═══ ولمَ ذلك أهمُّ ممّا يبدو ═══
 *
 * عرضٌ أُرسل إلى وزارةٍ ثمّ غُيّرت بنودُه في القاعدة يعني ورقتين مختلفتين
 * تحملان الرقمَ نفسَه: واحدةٌ في يد العميل وواحدةٌ في النظام. ولا سطرَ يقول
 * أيّهما الأصل. وهو بعينه ما يجعل النزاعَ بعد التنفيذ بلا فاصل.
 *
 * ═══ وما يُفحص هنا ═══
 *
 * الترقيم · التجميد · التنقيح · السلسلة · البصمة · اللقطة · الرابط العامّ ·
 * رسمُ الورقة · الصلاحيّات · وأثرُ التدقيق.
 */
class AQuotationSentDoesNotChangeTest extends TestCase
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

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط']);
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

    private function quote(array $over = []): Quotation
    {
        return Quotations::create(
            (int) $this->business->id,
            $this->customer,
            $over + ['title' => 'مؤتمر الطاقة', 'issued_at' => now()->toDateString(),
                'valid_until' => now()->addDays(30)->toDateString()],
            [['product_id' => $this->service->id, 'quantity' => 1, 'unit_price' => 500]],
            $this->owner,
        );
    }

    /* ─────────────────────────── الترقيم ─────────────────────────── */

    /**
     * ولا رقمَ للمسودّة — كما في فاتورة العميل حرفًا بحرف.
     *
     * مسودّةٌ تُهجر تأخذ رقمَها معها ويقفز التسلسل. والرقمُ يُقطع عند
     * **الإرسال**: هو اللحظةُ التي يصير فيها للورقة وجودٌ خارج الشركة.
     */
    public function test_a_draft_does_not_spend_a_number(): void
    {
        $quotation = $this->quote();

        $this->assertNull($quotation->number);
        $this->assertSame(Quotation::DRAFT, $quotation->status);

        $sent = Quotations::send($quotation, $this->owner);

        $this->assertSame('Q-000001', $sent->number);
        $this->assertSame(1, $sent->revision);
    }

    public function test_numbers_run_in_sequence_within_the_company(): void
    {
        $first = Quotations::send($this->quote(), $this->owner);
        $second = Quotations::send($this->quote(), $this->owner);

        $this->assertSame('Q-000001', $first->number);
        $this->assertSame('Q-000002', $second->number);

        /* وشركةٌ أخرى تبدأ من واحد: التسلسلُ داخل الشركة لا في المنصّة */
        $other = Business::create(['name' => 'أخرى', 'type' => 'عام', 'status' => 'نشط']);

        $this->assertSame('Q-000001', Quotations::nextNumber((int) $other->id));
    }

    /** ونداءان على «إرسال» لا يقطعان رقمين — الحالةُ تُقرأ تحت قفل */
    public function test_sending_twice_does_not_spend_two_numbers(): void
    {
        $quotation = $this->quote();

        $first = Quotations::send($quotation, $this->owner);
        $again = Quotations::send($first, $this->owner);

        $this->assertSame($first->number, $again->number);
        $this->assertSame(1, Quotation::where('business_id', $this->business->id)->count());
    }

    /** وعرضٌ بلا بنودٍ لا يُرسَل — والحارسُ على الفراغ لا على الصفر */
    public function test_an_empty_quotation_is_not_sent(): void
    {
        $quotation = $this->quote();
        $quotation->items()->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('لا يُرسَل عرضٌ بلا بنود.');

        Quotations::send($quotation->fresh('items'), $this->owner);
    }

    /* ─────────────────────────── التجميد ─────────────────────────── */

    public function test_a_sent_quotation_cannot_be_edited(): void
    {
        $quotation = Quotations::send($this->quote(), $this->owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('هذا العرض خرج إلى العميل — لا يُعدَّل.');

        Quotations::update($quotation, ['title' => 'عنوان آخر'], [[
            'product_id' => $this->service->id, 'quantity' => 1, 'unit_price' => 500,
        ]], $this->owner);
    }

    /** والردُّ في الخادم لا في الشاشة: المسارُ نفسُه يُغلق */
    public function test_the_edit_route_refuses_a_sent_quotation(): void
    {
        $quotation = Quotations::send($this->quote(), $this->owner);

        $this->actingAs($this->owner)
            ->get(route('admin.quotations.create', ['id' => $quotation->id]))
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->put(route('admin.quotations.update', $quotation->id), [
                'customer_id' => $this->customer->id,
                'title' => 'عنوان آخر',
                'items' => [['product_id' => $this->service->id, 'quantity' => 1, 'unit_price' => 1]],
            ])->assertSessionHasErrors('items');

        $this->assertSame('500.000', $quotation->fresh()->items->first()->unit_price);
    }

    /**
     * وتُختم بحال الشركة لحظةَ الخروج — لا لحظةَ الكتابة.
     *
     * المسودّةُ تُكتب اليوم وتُرسَل بعد أسبوع، وما بينهما قد يتغيّر اسمُ
     * الشركة أو رقمُها الضريبيّ. والورقةُ الرسميّة هي التي خرجت.
     */
    public function test_sending_stamps_the_company_as_it_was(): void
    {
        $quotation = $this->quote();

        $this->assertFalse(Snapshot::stamped($quotation));

        $sent = Quotations::send($quotation, $this->owner);

        $this->assertTrue(Snapshot::stamped($sent));
        $this->assertSame('افنت بلس', Snapshot::seller($sent->document_snapshot)['name']);

        $this->business->update(['name' => 'افنت بلس للفعاليات ش.م.م']);

        $this->assertSame('افنت بلس', Snapshot::seller($sent->fresh()->document_snapshot)['name']);
    }

    /**
     * والبصمةُ تُحسب عند الخروج — وهي ما يجعل القبولَ دليلًا.
     *
     * سجلُّ القبول يحمل البصمة حين يُبنى، فيُثبَت بعد سنةٍ أنّ ما قُبل هو
     * هذا بعينه لا نسخةٌ عُدّلت بعده.
     */
    public function test_sending_seals_a_content_hash(): void
    {
        $quotation = $this->quote();

        $this->assertNull($quotation->content_hash);

        $sent = Quotations::send($quotation, $this->owner);

        $this->assertSame(64, strlen((string) $sent->content_hash));
        /* وهي ثابتةٌ: تُحسب مرّتين فتردّ الشيء نفسَه */
        $this->assertSame($sent->content_hash, Quotations::contentHash($sent->fresh('items')));

        /* وتختلف حين يختلف المحتوى */
        $other = Quotations::send(Quotations::create(
            (int) $this->business->id, $this->customer,
            ['title' => 'مؤتمر آخر'],
            [['product_id' => $this->service->id, 'quantity' => 3, 'unit_price' => 500]],
            $this->owner,
        ), $this->owner);

        $this->assertNotSame($sent->content_hash, $other->content_hash);
    }

    /* ─────────────────────────── التنقيح ─────────────────────────── */

    public function test_a_revision_keeps_its_ancestor_whole(): void
    {
        $first = Quotations::send($this->quote(), $this->owner);

        $second = Quotations::revise($first, $this->owner);

        /* الأصلُ يبقى كما خرج */
        $first->refresh();
        $this->assertSame(Quotation::SUPERSEDED, $first->status);
        $this->assertSame('Q-000001', $first->number);
        $this->assertSame(1, $first->revision);
        $this->assertSame('500.000', $first->items->first()->unit_price);
        $this->assertNotNull($first->sent_at, 'وتاريخُ خروجه لا يُمحى');

        /* والتنقيحُ يبدأ مسودّةً برقم سلسلته */
        $this->assertSame(Quotation::DRAFT, $second->status);
        $this->assertSame(2, $second->revision);
        $this->assertSame('Q-000001', $second->number, 'رقمُ السلسلة لا رقمٌ ثانٍ');
        $this->assertSame((int) $first->id, (int) $second->root_id);
        $this->assertNull($second->sent_at);
        $this->assertSame(0, (int) $second->view_count);
        $this->assertNull($second->content_hash);
        $this->assertCount(1, $second->items, 'والبنودُ تُنسخ');
    }

    /** والتنقيحُ يُعدَّل ويُرسَل — فتعود السلسلةُ إلى «واحدٌ حاليّ» */
    public function test_only_one_revision_is_current(): void
    {
        $first = Quotations::send($this->quote(), $this->owner);
        $second = Quotations::send(Quotations::revise($first, $this->owner), $this->owner);
        $third = Quotations::revise($second, $this->owner);

        $this->assertSame(3, $third->revision);
        $this->assertSame((int) $first->id, (int) $third->root_id, 'والجذرُ واحدٌ للسلسلة كلّها');

        $chain = Quotation::where('business_id', $this->business->id)
            ->where(fn ($q) => $q->whereKey($first->id)->orWhere('root_id', $first->id))
            ->orderBy('revision')->pluck('status')->all();

        $this->assertSame([
            Quotation::SUPERSEDED, Quotation::SUPERSEDED, Quotation::DRAFT,
        ], $chain);
    }

    /**
     * وأعلى تنقيحٍ في السلسلة لا «تنقيحي + ١».
     *
     * من نقّح النسخةَ الأولى بعد أن صارت الثانية موجودةً يكتب ثالثةً لا
     * ثانيةً — وإلّا اصطدم بفهرس التفرّد (الرقم + التنقيح).
     */
    public function test_revising_an_older_link_does_not_collide(): void
    {
        $first = Quotations::send($this->quote(), $this->owner);
        $second = Quotations::send(Quotations::revise($first, $this->owner), $this->owner);

        /* وتُعاد الأولى إلى «أُرسل» بيدٍ لنُنقّحها ثانيةً — حالةٌ نادرةٌ لكنّها ممكنة */
        $first->refresh()->update(['status' => Quotation::SENT]);

        $third = Quotations::revise($first->fresh('items'), $this->owner);

        $this->assertSame(3, $third->revision);
        $this->assertSame(2, $second->revision);
    }

    public function test_only_a_sent_or_rejected_quotation_is_revised(): void
    {
        $draft = $this->quote();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('لا يُنقَّح إلا عرضٌ أُرسل أو رُفض.');

        Quotations::revise($draft, $this->owner);
    }

    /**
     * والنسخةُ غيرُ التنقيح: عرضٌ آخر يبدأ من صفر.
     *
     * التنقيحُ نسخةٌ ثانية من **العرض نفسِه** لعميله نفسِه، وهذه عرضٌ لفعاليّةٍ
     * تشبه سابقتَها — فلها رقمُها وسلسلتُها.
     */
    public function test_a_duplicate_starts_a_new_chain(): void
    {
        $first = Quotations::send($this->quote(), $this->owner);

        $copy = Quotations::duplicate($first, $this->owner);

        $this->assertNull($copy->number);
        $this->assertNull($copy->root_id);
        $this->assertSame(1, $copy->revision);
        $this->assertSame(Quotation::DRAFT, $copy->status);
        $this->assertSame(Quotation::SENT, $first->fresh()->status, 'والأصلُ لا يُمسّ');

        $sent = Quotations::send($copy, $this->owner);
        $this->assertSame('Q-000002', $sent->number);
    }

    /* ─────────────────────────── الدورة ─────────────────────────── */

    public function test_the_presented_state_is_derived_not_stored(): void
    {
        $sent = Quotations::send($this->quote(), $this->owner);

        $this->assertSame('unopened', $sent->presentedState());
        /*
            وعدَّادُ الأيّام موجبٌ لما بقي وسالبٌ لما مضى.
            وانقلابُ إشارته يجعل الشاشة تقول «بقي ٣٠ يومًا» عن عرضٍ انتهى
            منذ ثلاثين — وهو خطأٌ لا يكشفه إلّا من يقرأ التاريخ بنفسه.
        */
        $this->assertSame(30, $sent->daysLeft());

        Quotations::markViewed($sent);
        $this->assertSame('awaiting_client', $sent->fresh()->presentedState());

        /* ثمّ يمضي موعدُ الصلاحية — ولا مهمّةَ ليليّة تُحدّث شيئًا */
        $sent->fresh()->update(['valid_until' => now()->subDay()->toDateString()]);

        $expired = $sent->fresh();
        $this->assertSame('expired', $expired->presentedState());
        $this->assertSame(Quotation::SENT, $expired->status, 'والحالُ المخزَّنة لم تتغيّر');

        /* والترشيحُ بالقاعدة يوافق ما تقوله الدالّة */
        $this->assertSame(1, Quotation::where('business_id', $this->business->id)->expired()->count());
        $this->assertSame(0, Quotation::where('business_id', $this->business->id)->awaitingClient()->count());
    }

    public function test_a_decision_needs_a_sent_quotation_and_a_reason_when_rejected(): void
    {
        $draft = $this->quote();

        try {
            Quotations::recordDecision($draft, Quotation::ACCEPTED, [], $this->owner);
            $this->fail('سُجّل قرارٌ على مسودّة');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('إلا على عرضٍ أُرسل', $e->getMessage());
        }

        $sent = Quotations::send($draft, $this->owner);

        try {
            Quotations::recordDecision($sent, Quotation::REJECTED, [], $this->owner);
            $this->fail('رُفض بلا سبب');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('اكتب سبب الرفض', $e->getMessage());
        }

        $decided = Quotations::recordDecision($sent, Quotation::ACCEPTED, [
            'decided_by_name' => 'م. سالم',
        ], $this->owner);

        $this->assertSame(Quotation::ACCEPTED, $decided->status);
        $this->assertSame('م. سالم', $decided->decided_by_name);
        $this->assertSame($this->owner->id, $decided->decided_by);
        $this->assertNotNull($decided->decided_at);
    }

    /** وعرضٌ قُبل لا يُلغى: القرارُ وقع، والإلغاءُ بعده يمحو ما جرى */
    public function test_an_accepted_quotation_is_not_cancelled(): void
    {
        $sent = Quotations::send($this->quote(), $this->owner);
        $accepted = Quotations::recordDecision($sent, Quotation::ACCEPTED, [], $this->owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('لا يُلغى عرضٌ قُبل');

        Quotations::cancel($accepted, 'تراجعنا', $this->owner);
    }

    /* ─────────────────────── الرابط العامّ ─────────────────────── */

    /**
     * ورابطُ العميل يُبنى لما خرج وحدَه — ومسودّةٌ لا تُفتح ولو عُرف رمزُها.
     *
     * عرضٌ أُرسل ثمّ نُقّح يبقى رمزُه، وتنقيحُه الجديد يبدأ مسودّةً. وفتحُ
     * المسودّة للعميل يُريه سعرًا لم يُقرَّر.
     */
    public function test_the_public_link_opens_only_what_went_out(): void
    {
        $sent = Quotations::send($this->quote(), $this->owner);

        $url = PublicDocument::url($sent);
        $this->assertNotNull($url);

        $token = DocumentLink::where('linkable_type', $sent->getMorphClass())
            ->where('linkable_id', $sent->id)->value('token');

        /* ويُفتح بلا تسجيل دخول: الواقفُ أمامه عميلٌ لا موظّف */
        $this->get(route('paper.show', $token))
            ->assertOk()
            ->assertSee('Q-000001')
            ->assertSee('تشغيل نظام تسجيل');

        /* والفتحُ يُختم */
        $sent->refresh();
        $this->assertNotNull($sent->first_viewed_at);
        $this->assertSame(1, (int) $sent->view_count);

        $this->get(route('paper.show', $token))->assertOk();
        $this->assertSame(2, (int) $sent->fresh()->view_count);
        $this->assertSame(
            $sent->first_viewed_at->toDateTimeString(),
            $sent->fresh()->first_viewed_at->toDateTimeString(),
            'و«أوّلُ فتح» يُكتب مرّةً — هو ما يُقاس به «أُرسل ولم يُفتح»',
        );

        /* وتنقيحُه مسودّةٌ: رمزُها — إن طُلب — لا يفتح شيئًا */
        $revision = Quotations::revise($sent->fresh('items'), $this->owner);
        $draftToken = PublicDocument::token($revision);

        $this->get(route('paper.show', $draftToken))->assertNotFound();
    }

    /**
     * ولا يُبنى رابطٌ لما لم يخرج بعد.
     *
     * `PublicDocument::url` تكتب صفًّا في جدول الروابط عند أوّل نداء. فلو
     * نُوديت لمسودّةٍ لَرُسم لها زرُّ نسخٍ يُرسله صاحبُه فيفتح العميلُ صفحةَ
     * «غير موجود» — وهو أسوأ من غياب الزرّ.
     */
    public function test_no_link_is_minted_before_the_quotation_goes_out(): void
    {
        $draft = $this->quote();

        $this->actingAs($this->owner)
            ->get(route('admin.quotations.show', $draft->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('quotation.public_url', null)->etc());

        $this->assertSame(0, DocumentLink::count(), 'ولا صفَّ يُكتب في جدول الروابط');

        Quotations::send($draft, $this->owner);

        $this->actingAs($this->owner)
            ->get(route('admin.quotations.show', $draft->id))
            ->assertOk();

        $this->assertSame(1, DocumentLink::count());
    }

    /** ورمزٌ لا يُخمَّن: اثنان وعشرون حرفًا، وواحدٌ لكلّ ورقة */
    public function test_one_paper_has_one_token(): void
    {
        $sent = Quotations::send($this->quote(), $this->owner);

        $first = PublicDocument::token($sent);
        $second = PublicDocument::token($sent);

        $this->assertSame($first, $second);
        $this->assertSame(22, strlen((string) $first));
        $this->assertSame(1, DocumentLink::where('linkable_type', $sent->getMorphClass())->count());
    }

    /* ─────────────────────── رسمُ الورقة ─────────────────────── */

    /**
     * نصُّ الورقة كما يُقرأ — بلا أنماطٍ ولا تعليقات.
     *
     * وأنماطُ `documents/v1` مضمَّنةٌ في الورقة وفيها شروحٌ عربيّةٌ مطوَّلة
     * («في المرجع: الرصيد المستحق…»). فبحثٌ في الرسم الخام يجد كلماتٍ لا
     * يراها أحد، ويمرّ حارسٌ يسأل «أليست هذه فاتورة؟» وهو يقرأ تعليقًا.
     */
    private function visible(string $html): string
    {
        $text = (string) preg_replace('/<style.*?<\/style>/s', '', $html);

        return (string) preg_replace('/<!--.*?-->/s', '', $text);
    }

    public function test_the_paper_is_drawn_by_the_document_system(): void
    {
        $quotation = Quotations::send($this->quote([
            'scope' => 'يشمل التركيب والتشغيل والفكّ.',
            'terms' => 'الإلغاء قبل أسبوع بلا رسوم.',
            'event_location' => 'مركز عُمان للمؤتمرات',
        ]), $this->owner);

        $html = $this->visible($this->actingAs($this->owner)
            ->get(route('admin.quotations.show', $quotation->id))
            ->assertOk()
            ->viewData('page')['props']['paper']);

        $this->assertStringContainsString('Q-000001', $html);
        $this->assertStringContainsString('وزارة الطاقة', $html);
        $this->assertStringContainsString('يشمل التركيب والتشغيل والفكّ.', $html);
        $this->assertStringContainsString('الإلغاء قبل أسبوع بلا رسوم.', $html);
        $this->assertStringContainsString('مركز عُمان للمؤتمرات', $html);
        $this->assertStringContainsString('صالح حتى', $html);

        /*
         * ولا ذمّةَ على الورقة: الرقمُ الأهمّ «الإجماليُّ المعروض» لا
         * «الرصيد المستحقّ»، ولا مسدَّدَ ولا باقيَ ولا تعليماتِ سداد.
         * وعرضُ سعرٍ يقول «الباقي عليك» يُقرأ مطالبةً بمالٍ لم يُتَّفق عليه.
         */
        $this->assertStringContainsString('الإجمالي المعروض', $html);
        $this->assertStringNotContainsString('الرصيد المستحق', $html);
        $this->assertStringNotContainsString('المسدَّد', $html);
        $this->assertStringNotContainsString('تعليمات السداد', $html);
    }

    /** والملاحظةُ الداخليّة لا تبلغ ورقةَ العميل بحال */
    public function test_internal_notes_never_reach_the_paper(): void
    {
        $quotation = Quotations::send($this->quote([
            'internal_notes' => 'هامشنا ٤٠٪ — لا يُطبع',
            'notes' => 'نتشرف بخدمتكم',
        ]), $this->owner);

        $html = $this->visible($this->actingAs($this->owner)
            ->get(route('admin.quotations.show', $quotation->id))
            ->assertOk()
            ->viewData('page')['props']['paper']);

        $this->assertStringNotContainsString('هامشنا', $html);
        $this->assertStringContainsString('نتشرف بخدمتكم', $html);
    }

    public function test_the_pdf_route_answers(): void
    {
        $quotation = Quotations::send($this->quote(), $this->owner);

        $this->actingAs($this->owner)
            ->get(route('admin.quotations.pdf', $quotation->id))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    /* ─────────────────────── الصلاحيّات ─────────────────────── */

    public function test_each_action_is_guarded_by_its_own_permission(): void
    {
        $quotation = $this->quote();

        /* موظّفٌ يفتح القسم ولا يملك فعلًا واحدًا من أفعاله */
        $reader = User::create([
            'business_id' => $this->business->id, 'name' => 'قارئ', 'email' => 'r@hadath.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
            'permissions' => ['quotations'],
        ]);

        $this->actingAs($reader)->get(route('admin.quotations.index'))->assertOk();
        $this->actingAs($reader)->get(route('admin.quotations.create'))->assertForbidden();
        $this->actingAs($reader)->post(route('admin.quotations.send', $quotation->id))->assertForbidden();
        $this->actingAs($reader)->post(route('admin.quotations.cancel', $quotation->id), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($reader)->post(route('admin.quotations.approve', $quotation->id))->assertForbidden();
        $this->actingAs($reader)->post(route('admin.quotations.decision', $quotation->id), [
            'decision' => Quotation::ACCEPTED,
        ])->assertForbidden();

        /* ومن لا يملك القسمَ لا يبلغ الشاشةَ أصلًا */
        $stranger = User::create([
            'business_id' => $this->business->id, 'name' => 'أمين مخزن', 'email' => 'i@hadath.om',
            'password' => bcrypt('password'), 'role' => 'inventory', 'status' => 'نشط',
        ]);

        $this->actingAs($stranger)->get(route('admin.quotations.index'))->assertForbidden();
    }

    /** والبائعُ يكتب ويُرسل ويسجّل القرار — ولا يمسّ السعر */
    public function test_the_seller_owns_the_paper_not_the_price(): void
    {
        $seller = User::create([
            'business_id' => $this->business->id, 'name' => 'بائع', 'email' => 's@hadath.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
        ]);

        $this->assertTrue($seller->may(Permissions::QUOTATION_CREATE));
        $this->assertTrue($seller->may(Permissions::QUOTATION_SEND));
        $this->assertTrue($seller->may(Permissions::QUOTATION_RECORD_DECISION));
        $this->assertFalse($seller->may(Permissions::QUOTATION_DISCOUNT));
        $this->assertFalse($seller->may(Permissions::QUOTATION_APPROVE));
        $this->assertFalse($seller->may(Permissions::QUOTATION_OVERRIDE_PRICE));
        $this->assertFalse($seller->may(Permissions::QUOTATION_CANCEL));
    }

    /** والمحاسبُ لا يفتح عروضَ الأسعار: مستندٌ تجاريٌّ قبل البيع */
    public function test_the_accountant_does_not_open_quotations(): void
    {
        $accountant = User::create([
            'business_id' => $this->business->id, 'name' => 'محاسب', 'email' => 'a@hadath.om',
            'password' => bcrypt('password'), 'role' => 'accountant', 'status' => 'نشط',
        ]);

        $this->assertFalse($accountant->allows('quotations'));
        $this->actingAs($accountant)->get(route('admin.quotations.index'))->assertForbidden();
    }

    /* ─────────────────────── أثرُ التدقيق ─────────────────────── */

    /**
     * والأفعالُ المهمّة تُقيَّد على أصحابها.
     *
     * وبـ`subject_type = 'quotation'` — الجدولُ متعدّدُ الأشكال أصلًا، فلا
     * جدولَ ثانٍ يُنشأ لسجلّ عروض الأسعار.
     */
    public function test_every_meaningful_act_is_logged(): void
    {
        $this->actingAs($this->owner);

        $quotation = $this->quote();
        Quotations::update($quotation, ['title' => 'مؤتمر الطاقة ٢٠٢٦'], [[
            'product_id' => $this->service->id, 'quantity' => 2, 'unit_price' => 500,
        ]], $this->owner);

        $sent = Quotations::send($quotation->fresh('items'), $this->owner);
        Quotations::revise($sent, $this->owner);

        $logged = ActivityLog::where('business_id', $this->business->id)
            ->where('subject_type', 'quotation')
            ->pluck('description');

        $this->assertTrue($logged->contains(fn ($d) => str_contains($d, 'أنشأ عرض سعر')));
        $this->assertTrue($logged->contains(fn ($d) => str_contains($d, 'عدّل عرض السعر')));
        $this->assertTrue($logged->contains(fn ($d) => str_contains($d, 'أرسل عرض السعر Q-000001')));
        $this->assertTrue($logged->contains(fn ($d) => str_contains($d, 'أنشأ تنقيحًا')));

        /* وكلُّها منسوبةٌ إلى فاعلها */
        $this->assertSame(
            [$this->owner->id],
            ActivityLog::where('subject_type', 'quotation')->pluck('user_id')->unique()->values()->all(),
        );
    }

    /**
     * والخصمُ يُقيَّد اعتمادُه على حدة.
     *
     * «من طلبه» و«من اعتمده» سؤالان مختلفان، وسجلٌّ يخلطهما لا يجيب عن
     * أيّهما.
     */
    public function test_an_approval_is_logged_on_its_own(): void
    {
        Setting::create(['business_id' => $this->business->id,
            'key' => Quotations::THRESHOLD_KEY, 'value' => '5']);

        $closer = User::create([
            'business_id' => $this->business->id, 'name' => 'مسؤول', 'email' => 'c@hadath.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
            'permissions' => ['quotations', Permissions::QUOTATION_CREATE, Permissions::QUOTATION_DISCOUNT],
        ]);

        $this->actingAs($closer);

        $quotation = Quotations::create((int) $this->business->id, $this->customer,
            ['title' => 'مؤتمر'],
            [['product_id' => $this->service->id, 'quantity' => 1, 'unit_price' => 400,
                'discount_reason' => 'مناقصة']],
            $closer);

        $this->actingAs($this->owner);
        Quotations::approve($quotation, $this->owner);

        $approval = ActivityLog::where('subject_type', 'quotation')
            ->where('description', 'like', '%اعتمد خصم%')->first();

        $this->assertNotNull($approval);
        $this->assertSame($this->owner->id, $approval->user_id);
    }

    /**
     * وفتحُ العميل لا يُقيَّد في سجلّ النشاط.
     *
     * من يفتح رابطًا عامًّا ليس مستخدمًا في النظام، و`Activity::log` تنسب
     * الفعلَ إلى فاعل — فكانت تكتب «زائر» صفًّا في كلّ تحديثِ صفحة.
     * والواقعةُ مختومةٌ في الصفّ نفسِه: `first_viewed_at` و`view_count`.
     */
    public function test_a_customer_view_is_stamped_not_logged(): void
    {
        $sent = Quotations::send($this->quote(), $this->owner);
        $before = ActivityLog::count();

        $this->get(route('paper.show', PublicDocument::token($sent)))->assertOk();

        $this->assertSame($before, ActivityLog::count());
        $this->assertSame(1, (int) $sent->fresh()->view_count);
    }
}
