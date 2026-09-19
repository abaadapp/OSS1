<?php

namespace Tests\Feature;

use App\Mail\DocumentMail;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Communication;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\DeliveryPackage;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\User;
use App\Support\Communication\Timeline;
use App\Support\DeliveryPackages;
use App\Support\Quotations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * المشروعُ يروي قصّته بترتيبها — من سجلّاتٍ قائمة لا من تخمين.
 *
 * عرضٌ أُرسل بالبريد وقُبل، فصار مشروعًا، فخرجت حزمةُ تسليمٍ وأُرسلت،
 * وأبلغ المزوّد بوصول البريد، وقبل العميلُ التسليم — كلُّ ذلك أسطرٌ
 * بأوقاتها كما وقعت، والرسالةُ الواحدة سطران: أُرسلت ثمّ وصلت.
 */
class TheProjectTellsItsStoryInOrderTest extends TestCase
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
        Storage::fake('local');

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
        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة', 'email' => 'buyer@ministry.om']);
        $this->service = Product::create([
            'business_id' => $this->business->id, 'name' => 'تشغيل نظام تسجيل', 'kind' => Product::SERVICE, 'price' => 500,
        ]);

        config(['mail.default' => 'postmark', 'services.postmark.key' => 'pm-key', 'services.postmark.webhook_secret' => 'pm']);
        Mail::fake();
    }

    /** القصّةُ كما تقع — كلُّ خطوةٍ في دقيقتها */
    private function story(): Project
    {
        $this->actingAs($this->owner);

        Carbon::setTestNow('2026-09-19 10:30:00');
        $quotation = Quotations::create((int) $this->business->id, $this->customer, [
            'title' => 'مؤتمر الطاقة', 'issued_at' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(),
            'event_starts_at' => now()->addDays(40)->toDateString(),
        ], [['product_id' => $this->service->id, 'quantity' => 2, 'unit_price' => 500]], $this->owner);
        Quotations::send($quotation, $this->owner);

        Carbon::setTestNow('2026-09-19 10:34:00');
        $this->actingAs($this->owner)->post(route('admin.quotations.email', $quotation->id), ['to' => 'buyer@ministry.om'])->assertSessionHasNoErrors();
        $email = Communication::sole();
        $email->update(['provider_message_id' => 'pm-q1']);

        Carbon::setTestNow('2026-09-19 10:35:00');
        $this->postJson(route('webhooks.postmark'), ['RecordType' => 'Delivery', 'MessageID' => 'pm-q1', 'DeliveredAt' => '2026-09-19T06:35:00Z'],
            ['Authorization' => 'Basic '.base64_encode('x:pm')])->assertOk();

        Carbon::setTestNow('2026-09-19 11:00:00');
        Quotations::recordDecision($quotation->fresh(), Quotation::ACCEPTED, [], $this->owner);
        $project = Project::where('quotation_id', $quotation->id)->firstOrFail();
        $project->update(['status' => Project::IN_PROGRESS]);

        Carbon::setTestNow('2026-09-22 09:00:00');
        $package = DeliveryPackages::create((int) $this->business->id, null, ['project_id' => $project->id], $this->owner);
        foreach ($project->fresh('items')->items as $item) {
            DeliveryPackages::addItem($package, ['kind' => 'item', 'project_item_id' => $item->id, 'quantity' => $item->quantity], null, $this->owner);
        }
        DeliveryPackages::addItem($package, ['kind' => 'file', 'title' => 'تقرير'],
            UploadedFile::fake()->create('report.pdf', 5, 'application/pdf'), $this->owner);
        $package = DeliveryPackages::send(DeliveryPackages::ready($package->fresh(), $this->owner), $this->owner);

        Carbon::setTestNow('2026-09-22 09:05:00');
        $this->actingAs($this->owner)->post(route('admin.deliveries.email', $package->id), ['to' => 'buyer@ministry.om'])->assertSessionHasNoErrors();

        Carbon::setTestNow('2026-09-25 14:00:00');
        auth()->logout();
        $this->post(DeliveryPackages::url($package).'/decision', ['decision' => 'accepted', 'name' => 'سالم الهنائي', 'consent' => '1'])
            ->assertSessionHasNoErrors();

        Carbon::setTestNow();

        return $project->fresh();
    }

    public function test_the_timeline_lists_what_happened_in_the_order_it_happened(): void
    {
        $project = $this->story();
        $rows = Timeline::forProject($project);

        $titles = array_column($rows, 'title');
        $ats = array_column($rows, 'at');

        $sorted = $ats;
        sort($sorted);
        $this->assertSame($sorted, $ats, 'الأسطرُ بترتيب وقوعها');

        $find = fn (string $needle) => collect($titles)->search(fn ($t) => str_contains((string) $t, $needle));

        $emailSent = $find('أُرسل البريد: عرض سعر Q-000001');
        $emailDelivered = $find('وصل البريد');
        $accepted = $find('قبول العميل لعرض السعر');
        $packageSent = $find('أُرسل البريد: حزمة التسليم');
        $packageAccepted = $find('قبل العميل حزمة التسليم DP-000001 إلكترونيًّا');

        foreach (['emailSent', 'emailDelivered', 'accepted', 'packageSent', 'packageAccepted'] as $name) {
            $this->assertNotFalse($$name, "سطرُ «{$name}» غائب — الأسطر: ".implode(' | ', $titles));
        }

        $this->assertTrue($emailSent < $emailDelivered && $emailDelivered < $accepted && $accepted < $packageSent && $packageSent < $packageAccepted,
            'القصّةُ بترتيبها: أُرسل → وصل → قُبل → أُرسل التسليم → قُبل التسليم');

        $delivered = $rows[$emailDelivered];
        $this->assertSame('communication', $delivered['kind']);
        $this->assertSame('delivered', $delivered['status']);
        $this->assertSame('email', $delivered['channel']);
        $this->assertSame('buyer@ministry.om', $delivered['recipient']);

        /* ولا سطرَ يُخترع: كلُّ سطرٍ إمّا أثرٌ مكتوب وإمّا رسالةٌ مقيَّدة */
        $activities = collect($rows)->where('kind', 'activity')->count();
        $messages = collect($rows)->where('kind', 'communication')->count();
        $this->assertSame(count($rows), $activities + $messages);
        $this->assertSame(
            Communication::count() + Communication::whereNotNull('delivered_at')->count(),
            $messages,
            'كلُّ رسالةٍ سطرُ إرسال، وسطرُ وصولٍ لما وصل — لا أكثر'
        );
    }

    public function test_the_notice_to_the_company_is_on_the_timeline_and_marked_internal(): void
    {
        $this->story();

        $notice = Communication::where('template_key', 'delivery.accepted')->sole();
        $this->assertSame('hello@hadath.om', $notice->recipient);
        $this->assertTrue((bool) $notice->metadata['internal']);
        $this->assertSame(DeliveryPackage::class, $notice->subject_type);
        $this->assertNotNull($notice->project_id, 'خبرُ التسليم يتبع مشروعَه');
        $this->assertSame('النظام', $notice->initiated_by_name, 'لا موظّفَ خلف رابط العميل — الفاعلُ النظام');
    }

    public function test_the_tab_reads_the_timeline_through_its_own_door(): void
    {
        $project = $this->story();

        $json = $this->actingAs($this->seller)->getJson(route('admin.projects.timeline', $project->id))->assertOk()->json();
        $this->assertNotEmpty($json['timeline']);
        $this->assertFalse($json['may']['hub']);
        $this->assertTrue($json['may']['send']);

        $storekeeper = User::create([
            'business_id' => $this->business->id, 'name' => 'أمين المخزن', 'email' => 'store@hadath.om',
            'password' => bcrypt('password'), 'role' => 'inventory', 'status' => 'نشط',
        ]);
        $this->actingAs($storekeeper)->getJson(route('admin.projects.timeline', $project->id))->assertForbidden();

        $rival = Business::create(['name' => 'منافس', 'type' => 'عام', 'status' => 'نشط']);
        $stranger = User::create([
            'business_id' => $rival->id, 'name' => 'غريب', 'email' => 'x@rival.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($stranger)->getJson(route('admin.projects.timeline', $project->id))->assertNotFound();
    }

    public function test_a_project_message_is_filed_under_the_project_and_the_customer(): void
    {
        $project = $this->story();

        $this->actingAs($this->owner)->post(route('admin.projects.communications.send', $project->id), [
            'channel' => 'email', 'template' => 'project.update', 'to' => 'buyer@ministry.om', 'note' => 'تغيّر موقع الفعالية إلى القاعة الكبرى',
        ])->assertSessionHasNoErrors();

        $c = Communication::where('template_key', 'project.update')->sole();
        $this->assertSame($project->id, (int) $c->project_id);
        $this->assertSame($project->id, (int) $c->subject_id);
        $this->assertSame(Project::class, $c->subject_type);
        $this->assertSame($this->customer->id, (int) $c->customer_id);
        $this->assertStringContainsString('مؤتمر الطاقة', $c->title);

        Mail::assertSent(DocumentMail::class, fn ($m) => $m->intent->templateKey === 'project.update'
            && collect($m->intent->paragraphs)->contains(fn ($p) => str_contains($p, 'القاعة الكبرى'))
            && collect($m->intent->paragraphs)->contains(fn ($p) => str_contains($p, $project->code)));

        $this->actingAs($this->owner)->get(route('admin.communications.index', ['project_id' => $project->id]))
            ->assertInertia(fn ($p) => $p->where('communications.0.template_key', 'project.update')->etc());
    }
}
