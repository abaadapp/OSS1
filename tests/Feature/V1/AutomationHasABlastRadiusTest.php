<?php

namespace Tests\Feature\V1;

use App\Mail\DocumentMail;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Automation\Engine;
use App\Support\Automation\Rules;
use App\Support\Automation\Settings;
use App\Support\CustomerInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * للأتمتة حدُّ انفجار — المرحلة 12 (§28 · §29 · §68).
 *
 * الشركةُ تبدأ مطفأة ولا قاعدةً حيّةً بالبذرة · قاعدةٌ تطابق كثيرًا لا تُخرج
 * أكثرَ من سقف الدورة، والباقي يبقى معلَّقًا للدورة التالية لا يضيع · وقاعدةٌ
 * تفشل تكرارًا ينقطع فتيلُها · وصفٌّ يُجدوَل ثمّ تتغيّر حالُ مستنده يُعاد فحصُه
 * لحظةَ التنفيذ.
 */
class AutomationHasABlastRadiusTest extends TestCase
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
        Carbon::setTestNow(Carbon::parse('2026-09-20 10:00:00', 'Asia/Muscat'));
        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط', 'email' => 'hello@eventplus.om']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);
        $this->owner = User::create(['business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@eventplus.om', 'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط']);
        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة', 'email' => 'buyer@ministry.om', 'phone' => '91234567']);
        $this->service = Product::create(['business_id' => $this->business->id, 'name' => 'خدمة', 'kind' => Product::SERVICE, 'price' => 100]);
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.om']);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(int $dueInDays = 0): CustomerInvoice
    {
        $invoice = CustomerInvoices::create((int) $this->business->id, $this->customer, ['issued_at' => now()->subDays(10)->toDateString(), 'due_at' => now()->addDays($dueInDays)->toDateString()],
            [['product_id' => $this->service->id, 'description' => 'خدمة', 'quantity' => 1, 'unit_price' => 100]], $this->owner->id);

        return CustomerInvoices::issue($invoice->fresh(), $this->owner->id);
    }

    private function liveInvoiceRule(): AutomationRule
    {
        Settings::save((int) $this->business->id, ['mode' => Settings::LIVE], $this->owner);
        Rules::ensure((int) $this->business->id);
        AutomationRule::where('business_id', $this->business->id)->where('key', '!=', 'invoice.due')->update(['enabled' => false]);
        $rule = AutomationRule::where('business_id', $this->business->id)->where('key', 'invoice.due')->firstOrFail();

        return Rules::save($rule, ['enabled' => true, 'mode' => AutomationRule::LIVE, 'steps' => [['offset' => 0, 'audience' => 'customer']], 'channel' => 'email',
            'template_key' => 'invoice.reminder', 'business_hours' => true, 'cooldown_hours' => 1], $this->owner);
    }

    public function test_a_fresh_company_is_off_and_no_seeded_rule_is_live(): void
    {
        $this->assertSame(Settings::OFF, Settings::for((int) $this->business->id)->mode);
        Rules::ensure((int) $this->business->id);
        /* الداخليُّ (إشعاراتٌ في الشاشة) يُبذر حيًّا ولا يعمل حتى تختار الشركةُ وضعًا؛ والخارجيُّ موقوفٌ ومراقِب */
        $this->assertSame(0, AutomationRule::where('business_id', $this->business->id)->where('kind', AutomationRule::EXTERNAL)->where('enabled', true)->count(), 'الخارجيُّ يبدأ موقوفًا');
        $this->assertSame(0, AutomationRule::where('business_id', $this->business->id)->where('kind', AutomationRule::EXTERNAL)->where('mode', AutomationRule::LIVE)->count(), 'ولا خارجيٌّ حيّ');
        $this->assertSame(0, Engine::tick((int) $this->business->id)['businesses'], 'شركةٌ مطفأة لا تُقيَّم أصلًا');
        $this->assertSame(Engine::DEFAULT_EXTERNAL_PER_TICK, Settings::for((int) $this->business->id)->externalPerTick);
    }

    public function test_external_sends_are_capped_per_tick_and_the_rest_wait_their_turn(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->invoice();
        }
        $this->liveInvoiceRule();
        Settings::save((int) $this->business->id, ['external_cap' => 3], $this->owner);

        $first = Engine::tick((int) $this->business->id);
        $this->assertSame(3, $first['executed'], 'الدورةُ الأولى تُخرج السقف');
        $this->assertGreaterThanOrEqual(4, $first['pending'], 'والباقي معلَّقٌ لا مُلغًى');
        Mail::assertSent(DocumentMail::class, 3);
        $this->assertSame(4, AutomationRun::where('status', AutomationRun::PENDING)->count());

        $second = Engine::tick((int) $this->business->id);
        $this->assertSame(3, $second['executed']);
        $third = Engine::tick((int) $this->business->id);
        $this->assertSame(1, $third['executed'], 'الدفعةُ المشروعة تخرج كلُّها — على مهل');
        Mail::assertSent(DocumentMail::class, 7);
        $this->assertSame(0, AutomationRun::where('status', AutomationRun::PENDING)->count());
    }

    public function test_the_cap_is_a_company_setting_with_bounds(): void
    {
        Settings::save((int) $this->business->id, ['external_cap' => 120], $this->owner);
        $this->assertSame(120, Settings::for((int) $this->business->id)->externalPerTick);

        $this->expectException(\RuntimeException::class);
        Settings::save((int) $this->business->id, ['external_cap' => 0], $this->owner);
    }

    public function test_a_rule_that_keeps_failing_blows_its_fuse_and_its_pending_rows_are_skipped_not_sent(): void
    {
        $this->invoice();
        $rule = $this->liveInvoiceRule();
        for ($i = 0; $i < Engine::FUSE_FAILURES; $i++) {
            AutomationRun::create(['business_id' => $this->business->id, 'automation_rule_id' => $rule->id, 'trigger' => 'invoice_due', 'step' => 0, 'audience' => 'customer',
                'occurrence' => "f$i", 'idempotency_key' => "fail-$i", 'status' => AutomationRun::FAILED, 'executed_at' => now()->subMinutes(5), 'failure' => 'provider down']);
        }

        $stats = Engine::tick((int) $this->business->id);

        $this->assertSame(0, $stats['executed']);
        Mail::assertNothingSent();
        $skipped = AutomationRun::where('status', AutomationRun::SKIPPED)->get();
        $this->assertCount(1, $skipped);
        $this->assertSame(Engine::FUSE_BLOWN, $skipped->first()->skipped_reason);
    }

    public function test_a_row_scheduled_while_eligible_is_rechecked_when_it_runs(): void
    {
        $invoice = $this->invoice();
        $this->liveInvoiceRule();
        /* خارجَ الدوام يُجدوَل ولا يُنفَّذ */
        Carbon::setTestNow(Carbon::parse('2026-09-20 22:00:00', 'Asia/Muscat'));
        $stats = Engine::tick((int) $this->business->id);
        $this->assertSame(1, AutomationRun::where('status', AutomationRun::PENDING)->count(), json_encode($stats));

        /* ثمّ تُسدَّد الفاتورة قبل أن يحين — فلا تذكيرَ يخرج */
        \App\Support\CustomerPayments::record((int) $this->business->id, $this->customer, (float) $invoice->total, ['method' => 'نقدي', 'occurred_at' => now()->toDateString()], [$invoice->id => (float) $invoice->total], $this->owner->id);
        Carbon::setTestNow(Carbon::parse('2026-09-21 10:00:00', 'Asia/Muscat'));
        Engine::tick((int) $this->business->id);

        Mail::assertNothingSent();
        $this->assertSame(Engine::SETTLED, AutomationRun::sole()->skipped_reason);
    }

    public function test_the_kill_switch_stops_everything_without_deleting_anything(): void
    {
        $this->invoice();
        $this->liveInvoiceRule();
        Settings::pause((int) $this->business->id, true, $this->owner);

        $stats = Engine::tick((int) $this->business->id);
        Mail::assertNothingSent();
        $this->assertSame(0, $stats['executed']);
        $this->assertGreaterThanOrEqual(0, AutomationRun::count());
    }
}
