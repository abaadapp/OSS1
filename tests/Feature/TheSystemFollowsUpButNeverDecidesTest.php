<?php

namespace Tests\Feature;

use App\Mail\DocumentMail;
use App\Models\ActivityLog;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Communication;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\InternalNotification;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Automation\Engine;
use App\Support\Automation\Rules;
use App\Support\Automation\Settings;
use App\Support\Automation\Triggers;
use App\Support\Communication\Templates;
use App\Support\CustomerInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * النظامُ يتابع بنفسه — ولا يقرّر بنفسه. المرحلة 8: المحرّك.
 *
 * ═══ ما يُفحص ═══
 *
 * الأوضاعُ الثلاثة ومفتاحُ الإيقاف · القاعدةُ الموقوفة · التكرارُ (مفتاحٌ
 * فريد، عاملان، مجدولٌ مرّتين) · الهدوء · ساعاتُ العمل بتوقيت الشركة ·
 * الخارجيُّ موقوفٌ افتراضًا · القالبُ والقناةُ والمستلم · سياقُ الأتمتة في
 * المراسلات · فشلُ المزوّد لا يمسّ الفاتورة · لا مالَ يُمسّ · العزلُ · الأذون
 * · الأثر · ولا سرّ.
 */
class TheSystemFollowsUpButNeverDecidesTest extends TestCase
{
    use RefreshDatabase;

    /** الأحد 2026-09-20 العاشرةَ صباحًا بتوقيت مسقط — داخل الدوام */
    private const NOW = '2026-09-20 10:00:00';

    private Business $business;

    private User $owner;

    private User $accountant;

    private Customer $customer;

    private Product $service;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow(Carbon::parse(self::NOW, 'Asia/Muscat'));

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط', 'email' => 'hello@hadath.om']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->owner = User::create(['business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@hadath.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط']);
        $this->accountant = User::create(['business_id' => $this->business->id, 'name' => 'المحاسب', 'email' => 'acc@hadath.om',
            'password' => bcrypt('password'), 'role' => 'accountant', 'status' => 'نشط']);
        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة الطاقة', 'email' => 'buyer@ministry.om', 'phone' => '91234567']);
        $this->service = Product::create(['business_id' => $this->business->id, 'name' => 'خدمة', 'kind' => Product::SERVICE, 'price' => 100]);

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.om']);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ─────────────────────────── الأدوات ─────────────────────────── */

    /** فاتورةٌ صادرة تستحقّ في يومٍ بعينه (نسبةً إلى اليوم) */
    private function invoice(int $dueInDays = 0, float $price = 100): CustomerInvoice
    {
        $invoice = CustomerInvoices::create((int) $this->business->id, $this->customer, [
            'issued_at' => now()->subDays(10)->toDateString(), 'due_at' => now()->addDays($dueInDays)->toDateString(),
        ], [['product_id' => $this->service->id, 'description' => 'خدمة', 'quantity' => 1, 'unit_price' => $price]], $this->owner->id);

        return CustomerInvoices::issue($invoice, $this->owner->id);
    }

    /** قاعدةُ تذكير الفاتورة — مفعَّلة على تشغيل بخطواتٍ معطاة */
    private function invoiceRule(array $steps = [['offset' => 0, 'audience' => 'customer']], array $over = []): AutomationRule
    {
        Settings::save((int) $this->business->id, ['mode' => Settings::LIVE], $this->owner);
        Rules::ensure((int) $this->business->id);
        /* والقواعدُ الأخرى تُطفأ: الاختبارُ يقيس قاعدةً واحدة */
        AutomationRule::where('business_id', $this->business->id)->where('key', '!=', 'invoice.due')->update(['enabled' => false]);
        $rule = AutomationRule::where('business_id', $this->business->id)->where('key', 'invoice.due')->firstOrFail();

        return Rules::save($rule, $over + ['enabled' => true, 'mode' => AutomationRule::LIVE, 'steps' => $steps, 'channel' => 'email',
            'template_key' => 'invoice.reminder', 'business_hours' => true, 'cooldown_hours' => 48], $this->owner);
    }

    /* ─────────────────────────── الأوضاع ─────────────────────────── */

    public function test_external_presets_are_written_disabled_and_observing(): void
    {
        Rules::ensure((int) $this->business->id);

        $rules = AutomationRule::where('business_id', $this->business->id)->get()->keyBy('key');
        $this->assertCount(count(Rules::PRESETS), $rules);

        foreach (['quotation.followup', 'invoice.due', 'invoice.overdue', 'delivery.followup'] as $key) {
            $this->assertFalse($rules[$key]->enabled, "$key يجب أن تكون موقوفة");
            $this->assertSame(AutomationRule::OBSERVE, $rules[$key]->mode);
            $this->assertSame(AutomationRule::EXTERNAL, $rules[$key]->kind);
        }
        foreach (['project.start', 'task.due', 'communication.failed', 'brief.morning'] as $key) {
            $this->assertTrue($rules[$key]->enabled);
            $this->assertSame(AutomationRule::INTERNAL, $rules[$key]->kind);
        }

        /* والشركةُ تبدأ مطفأة: لا تقييم حتى تختار */
        $this->assertSame(Settings::OFF, Settings::for((int) $this->business->id)->mode);
        $this->invoice();
        $this->assertSame(0, Engine::tick((int) $this->business->id)['businesses']);
        $this->assertSame(0, AutomationRun::count());
    }

    public function test_observe_records_what_would_happen_and_sends_nothing(): void
    {
        $invoice = $this->invoice();
        $this->invoiceRule();
        Settings::save((int) $this->business->id, ['mode' => Settings::OBSERVE], $this->owner);

        $stats = Engine::tick((int) $this->business->id);

        $this->assertSame(1, $stats['observed']);
        $run = AutomationRun::sole();
        $this->assertSame(AutomationRun::OBSERVED, $run->status);
        $this->assertSame('buyer@ministry.om', $run->metadata['would']['to']);
        $this->assertSame('invoice.reminder', $run->metadata['would']['template']);
        $this->assertStringContainsString($invoice->number, $run->metadata['would']['reason']);
        $this->assertStringContainsString('كان سيُرسل', $run->result);

        Mail::assertNothingSent();
        $this->assertSame(0, Communication::count());
        $this->assertSame(0, InternalNotification::count());
    }

    public function test_a_rule_in_observe_stays_observing_even_when_the_company_is_live(): void
    {
        $this->invoice();
        $this->invoiceRule(over: ['mode' => AutomationRule::OBSERVE]);

        Engine::tick((int) $this->business->id);

        $this->assertSame(AutomationRun::OBSERVED, AutomationRun::sole()->status);
        Mail::assertNothingSent();
    }

    public function test_live_sends_through_phase_six_with_automation_context(): void
    {
        $invoice = $this->invoice();
        $rule = $this->invoiceRule();

        $stats = Engine::tick((int) $this->business->id);

        $this->assertSame(1, $stats['executed']);
        $run = AutomationRun::sole();
        $this->assertSame(AutomationRun::EXECUTED, $run->status);
        $this->assertNotNull($run->communication_id);

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $m) => $m->hasTo('buyer@ministry.om') && $m->intent->templateKey === 'invoice.reminder');

        $c = Communication::sole();
        $this->assertSame($run->communication_id, $c->id);
        $this->assertSame(CustomerInvoice::class, $c->subject_type);
        $this->assertSame($invoice->id, (int) $c->subject_id);
        $this->assertSame('الأتمتة', $c->initiated_by_name);
        $this->assertNull($c->initiated_by);
        $this->assertSame($rule->id, (int) $c->metadata['automation_rule_id']);
        $this->assertSame($run->id, (int) $c->metadata['automation_run_id']);
        $this->assertSame('invoice_due', $c->metadata['trigger']);
        $this->assertStringContainsString($invoice->number, $c->metadata['reason']);

        $this->assertTrue(ActivityLog::where('subject_type', 'automation_run')->where('subject_id', $run->id)
            ->where('user_name', 'الأتمتة')->exists(), 'الإرسالُ الخارجيّ في الأثر باسم الأتمتة');
    }

    public function test_a_disabled_rule_does_nothing(): void
    {
        $this->invoice();
        $this->invoiceRule(over: ['enabled' => false]);

        Engine::tick((int) $this->business->id);

        $this->assertSame(0, AutomationRun::count());
        Mail::assertNothingSent();
    }

    public function test_the_kill_switch_stops_everything_but_keeps_the_record(): void
    {
        $this->invoice();
        $this->invoiceRule();
        Settings::pause((int) $this->business->id, true, $this->owner);

        $stats = Engine::tick((int) $this->business->id);

        $this->assertSame(1, $stats['skipped']);
        $run = AutomationRun::sole();
        $this->assertSame(AutomationRun::SKIPPED, $run->status);
        $this->assertSame(Engine::PAUSED, $run->skipped_reason);
        Mail::assertNothingSent();
        $this->assertSame(0, Communication::count());

        /* ولا تنبيهَ داخليًّا كذلك — الإيقافُ إيقاف */
        $this->assertSame(0, InternalNotification::count());
        $this->assertTrue(ActivityLog::where('description', 'أوقف الأتمتة كلَّها')->exists());

        /* ويُستأنف: الخطوةُ نفسُها لا تُعاد — كُتبت متخطّاةً — لكنّ خطوةً جديدة تمرّ */
        Settings::pause((int) $this->business->id, false, $this->owner);
        Engine::tick((int) $this->business->id);
        $this->assertSame(1, AutomationRun::count());
        $this->assertTrue(ActivityLog::where('description', 'استأنف الأتمتة')->exists());
    }

    /* ─────────────────────────── التكرار ─────────────────────────── */

    public function test_the_same_step_never_runs_twice_no_matter_how_often_the_scheduler_fires(): void
    {
        $this->invoice();
        $this->invoiceRule();

        Engine::tick((int) $this->business->id);
        Engine::tick((int) $this->business->id);
        $this->artisan('automation:run', ['--business' => $this->business->id])->assertSuccessful();
        Carbon::setTestNow(Carbon::parse('2026-09-20 15:00:00', 'Asia/Muscat'));
        $this->artisan('automation:run')->assertSuccessful();

        $this->assertSame(1, AutomationRun::count());
        $this->assertSame(1, Communication::count());
        Mail::assertSentCount(1);
    }

    public function test_two_workers_on_the_same_run_send_once(): void
    {
        $invoice = $this->invoice();
        $rule = $this->invoiceRule();
        $trigger = Triggers::for('invoice_due');
        $step = ['offset' => 0, 'audience' => 'customer'];

        /* العاملُ الأوّل كتب الصفَّ وحجزه ونفّذ */
        $run = AutomationRun::create([
            'business_id' => $this->business->id, 'automation_rule_id' => $rule->id, 'trigger' => 'invoice_due',
            'target_type' => CustomerInvoice::class, 'target_id' => $invoice->id, 'step' => 0, 'audience' => 'customer',
            'occurrence' => now()->toDateString(), 'idempotency_key' => Engine::idempotencyKey($rule, $invoice, now()->toDateString(), $step),
            'status' => AutomationRun::PENDING, 'evaluated_at' => now(), 'scheduled_for' => now(),
        ]);
        $settings = Settings::for((int) $this->business->id);

        $first = Engine::execute($run, $rule, $trigger, $invoice, $step, $settings);
        /* والثاني وصل إلى الصفّ نفسِه — الحجزُ الذرّيّ يردّه */
        $second = Engine::execute($run->fresh(), $rule, $trigger, $invoice, $step, $settings);

        $this->assertSame('executed', $first);
        $this->assertSame('skipped', $second);
        $this->assertSame(1, Communication::count());
        Mail::assertSentCount(1);

        /* وعاملٌ ثالث يحاول فتحَ الصفّ نفسِه بالمفتاح نفسِه — القيدُ الفريد يردّه قبل التنفيذ */
        Engine::tick((int) $this->business->id);
        $this->assertSame(1, AutomationRun::count());
    }

    public function test_cooldown_holds_a_second_step_to_the_same_audience(): void
    {
        $this->invoice(-1);
        /* خطوتان حانتا معًا: «في اليوم» أمس، و«بعد يوم» اليوم — والهدوءُ ٤٨ ساعة */
        $this->invoiceRule([['offset' => 0, 'audience' => 'customer'], ['offset' => 1, 'audience' => 'customer']]);

        Engine::tick((int) $this->business->id);

        $runs = AutomationRun::orderBy('step')->get();
        $this->assertCount(2, $runs);
        $this->assertSame(AutomationRun::EXECUTED, $runs[0]->status);
        $this->assertSame(AutomationRun::SKIPPED, $runs[1]->status);
        $this->assertSame(Engine::COOLDOWN, $runs[1]->skipped_reason);
        Mail::assertSentCount(1);
    }

    public function test_client_messages_wait_for_business_hours_in_the_companys_timezone(): void
    {
        $this->invoice();
        $this->invoiceRule();
        /* الأحدُ الثامنةَ مساءً بتوقيت مسقط — بعد الدوام */
        Carbon::setTestNow(Carbon::parse('2026-09-20 20:00:00', 'Asia/Muscat'));

        $stats = Engine::tick((int) $this->business->id);

        $this->assertSame(1, $stats['pending']);
        $run = AutomationRun::sole();
        $this->assertSame(AutomationRun::PENDING, $run->status);
        $this->assertSame('2026-09-21 09:00', $run->scheduled_for->setTimezone('Asia/Muscat')->format('Y-m-d H:i'), 'أوّلُ ساعةٍ مفتوحة: الاثنين التاسعة');
        Mail::assertNothingSent();

        /* الاثنين ٩:٣٠ — تُنفَّذ من المعلَّق */
        Carbon::setTestNow(Carbon::parse('2026-09-21 09:30:00', 'Asia/Muscat'));
        $stats = Engine::tick((int) $this->business->id);
        $this->assertSame(1, $stats['executed']);
        $this->assertSame(AutomationRun::EXECUTED, $run->fresh()->status);
        Mail::assertSentCount(1);
        $this->assertSame(1, AutomationRun::count(), 'لم يُفتح صفٌّ ثانٍ للخطوة نفسِها');
    }

    public function test_the_weekend_is_skipped_and_internal_alerts_do_not_wait(): void
    {
        $settings = Settings::for((int) $this->business->id);
        /* الجمعة ٢٠٢٦-٠٩-٢٥ ليست يومَ عمل: التالي الأحد ٢٧ التاسعة */
        $friday = Carbon::parse('2026-09-25 11:00:00', 'Asia/Muscat');
        $this->assertFalse($settings->isOpen($friday));
        $this->assertSame('2026-09-27 09:00', $settings->nextOpen($friday)->format('Y-m-d H:i'));
        $this->assertTrue($settings->isOpen(Carbon::parse('2026-09-20 16:59:00', 'Asia/Muscat')));
        $this->assertFalse($settings->isOpen(Carbon::parse('2026-09-20 17:00:00', 'Asia/Muscat')));

        /* ومنطقةٌ أخرى تُحفظ وتُقرأ بها الساعة */
        Settings::save((int) $this->business->id, ['timezone' => 'Asia/Dubai', 'start' => '08:00', 'end' => '16:00', 'days' => [1, 2, 3]], $this->owner);
        $s = Settings::for((int) $this->business->id);
        $this->assertSame('Asia/Dubai', $s->timezone);
        $this->assertSame([1, 2, 3], $s->days);
        $this->assertSame('2026-09-21 08:00', $s->at('2026-09-18', 3)->format('Y-m-d H:i'));

        $this->expectException(RuntimeException::class);
        Settings::save((int) $this->business->id, ['start' => '18:00', 'end' => '09:00'], $this->owner);
    }

    /* ─────────────────────────── الحرّاس ─────────────────────────── */

    public function test_an_invalid_recipient_is_skipped_safely(): void
    {
        $this->customer->update(['email' => null, 'contact_email' => null]);
        $this->invoice();
        $this->invoiceRule();

        Engine::tick((int) $this->business->id);

        $run = AutomationRun::sole();
        $this->assertSame(AutomationRun::SKIPPED, $run->status);
        $this->assertSame(Engine::NO_RECIPIENT, $run->skipped_reason);
        Mail::assertNothingSent();
    }

    public function test_a_template_that_does_not_fit_the_trigger_or_channel_is_rejected(): void
    {
        $rule = $this->invoiceRule();

        try {
            Rules::save($rule, ['template_key' => 'quotation.reminder'], $this->owner);
            $this->fail('قالبُ عرضٍ على فاتورة');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('لا يخصّ', $e->getMessage());
        }

        try {
            Rules::save($rule, ['template_key' => 'quotation.accepted'], $this->owner);
            $this->fail('قالبٌ داخليّ للعميل');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('لا يخصّ', $e->getMessage());
        }

        try {
            Rules::save($rule, ['channel' => 'internal'], $this->owner);
            $this->fail('خطوةُ عميلٍ بلا قناة');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('القناة', $e->getMessage());
        }

        try {
            Rules::save($rule, ['steps' => [['offset' => 0, 'audience' => 'assignee']]], $this->owner);
            $this->fail('جمهورٌ لا يعرفه المُطلِق');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('الجمهور', $e->getMessage());
        }

        /* وواتساب بلا قالبٍ معتمَد: المحرّكُ يتخطّى بسببٍ يُقرأ — ولا يرمي إلى Meta */
        config(['services.whatsapp.token' => 'SECRET_WA', 'services.whatsapp.phone_number_id' => '1050']);
        Http::fake();
        $this->invoice();
        Rules::save($rule, ['channel' => 'whatsapp'], $this->owner);
        Engine::tick((int) $this->business->id);
        $this->assertSame(Engine::NO_TEMPLATE, AutomationRun::sole()->skipped_reason);
        Http::assertNothingSent();
    }

    public function test_a_provider_failure_marks_the_run_failed_and_leaves_the_invoice_untouched(): void
    {
        config(['services.whatsapp.token' => 'SECRET_WA', 'services.whatsapp.phone_number_id' => '1050']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131026, 'message' => 'Message Undeliverable']], 400)]);
        Templates::save((int) $this->business->id, 'invoice.reminder', 'whatsapp', 'ar', ['provider_template' => 'invoice_reminder', 'status' => 'active'], $this->owner);

        $invoice = $this->invoice();
        $before = [$invoice->status, $invoice->outstanding(), $invoice->total];
        $this->invoiceRule(over: ['channel' => 'whatsapp']);

        $stats = Engine::tick((int) $this->business->id);

        $this->assertSame(1, $stats['failed']);
        $run = AutomationRun::sole();
        $this->assertSame(AutomationRun::FAILED, $run->status);
        $this->assertStringContainsString('131026', $run->failure);
        $this->assertNotNull($run->communication_id, 'الرسالةُ الفاشلة مقيَّدة في المراسلات ومربوطة');
        $this->assertSame(Communication::FAILED, Communication::sole()->status);

        $fresh = $invoice->fresh();
        $this->assertSame($before, [$fresh->status, $fresh->outstanding(), $fresh->total], 'فشلُ الإرسال لا يمسّ الفاتورة');
        $this->assertStringNotContainsString('SECRET_WA', json_encode(AutomationRun::sole()->toArray()));
    }

    public function test_automation_never_touches_money_or_state_only_reminds(): void
    {
        $invoice = $this->invoice();
        $this->invoiceRule();
        Rules::ensure((int) $this->business->id);
        AutomationRule::where('business_id', $this->business->id)->update(['enabled' => true, 'mode' => AutomationRule::LIVE]);

        Engine::tick((int) $this->business->id);
        Carbon::setTestNow(Carbon::parse('2026-09-21 10:00:00', 'Asia/Muscat'));
        Engine::tick((int) $this->business->id);

        $fresh = $invoice->fresh();
        $this->assertSame(CustomerInvoice::ISSUED, $fresh->status);
        $this->assertSame(105.0, $fresh->outstanding());
        $this->assertSame(0, CustomerPayment::count());
        $this->assertSame(0, CustomerCreditNote::count());
        $this->assertGreaterThan(0, AutomationRun::count());
    }

    /* ─────────────────────────── العزل والأذون ─────────────────────────── */

    public function test_a_run_pointing_at_another_companys_document_is_refused(): void
    {
        $rule = $this->invoiceRule();

        $rival = Business::create(['name' => 'منافس', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $rival->id, 'name' => 'الرئيسي']);
        Currency::create(['business_id' => $rival->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true]);
        $stranger = User::create(['business_id' => $rival->id, 'name' => 'غريب', 'email' => 'x@rival.om', 'password' => bcrypt('p'), 'role' => 'admin', 'status' => 'نشط']);
        $theirCustomer = Customer::create(['business_id' => $rival->id, 'name' => 'عميلهم', 'email' => 'them@rival.om']);
        $theirProduct = Product::create(['business_id' => $rival->id, 'name' => 'خدمة', 'kind' => Product::SERVICE, 'price' => 100]);
        $theirs = CustomerInvoices::issue(CustomerInvoices::create((int) $rival->id, $theirCustomer, ['issued_at' => now()->toDateString(), 'due_at' => now()->toDateString()],
            [['product_id' => $theirProduct->id, 'description' => 'خدمة', 'quantity' => 1, 'unit_price' => 100]], $stranger->id), $stranger->id);

        /* دورةُ شركتنا لا ترى فاتورتَهم أصلًا */
        Engine::tick((int) $this->business->id);
        $this->assertSame(0, AutomationRun::count());

        /* وصفٌّ معلَّق دُسّ فيه معرّفُ فاتورتهم يُرفض عند التنفيذ */
        AutomationRun::create([
            'business_id' => $this->business->id, 'automation_rule_id' => $rule->id, 'trigger' => 'invoice_due',
            'target_type' => CustomerInvoice::class, 'target_id' => $theirs->id, 'step' => 0, 'audience' => 'customer',
            'occurrence' => now()->toDateString(), 'idempotency_key' => 'forged', 'status' => AutomationRun::PENDING,
            'evaluated_at' => now(), 'scheduled_for' => now()->subMinute(),
        ]);
        Engine::tick((int) $this->business->id);
        $this->assertSame(Engine::TARGET_GONE, AutomationRun::where('idempotency_key', 'forged')->sole()->skipped_reason);
        Mail::assertNothingSent();

        /* والشاشةُ لا تُري الغريبَ قواعدَنا ولا سجلَّنا */
        $this->actingAs($stranger)->get(route('admin.automation.history'))
            ->assertInertia(fn ($p) => $p->has('runs', 0)->etc());
        $this->actingAs($stranger)->put(route('admin.automation.rules.update', $rule->id), ['enabled' => false])->assertNotFound();
        $this->assertTrue($rule->fresh()->enabled);
    }

    public function test_only_the_authorized_touch_rules_and_the_switch(): void
    {
        $rule = $this->invoiceRule();

        $this->assertContains($this->actingAs($this->accountant)->get(route('admin.automation.index'))->getStatusCode(), [403, 302]);

        $viewer = User::create(['business_id' => $this->business->id, 'name' => 'قارئ', 'email' => 'v@hadath.om', 'password' => bcrypt('p'),
            'role' => 'sales', 'status' => 'نشط', 'permissions' => ['dashboard', 'automation']]);
        $this->actingAs($viewer)->get(route('admin.automation.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Automation/Rules')->where('may.manage', false)->where('may.pause', false)->etc());
        $this->actingAs($viewer)->get(route('admin.automation.history'))->assertOk();
        $this->actingAs($viewer)->put(route('admin.automation.rules.update', $rule->id), ['enabled' => false])->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.automation.settings'), ['mode' => 'off'])->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.automation.pause'), ['paused' => true])->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.automation.evaluate'))->assertForbidden();
        $this->assertTrue($rule->fresh()->enabled);
        $this->assertFalse(Settings::for((int) $this->business->id)->paused);

        $this->actingAs($this->owner)->post(route('admin.automation.pause'), ['paused' => true])->assertSessionHasNoErrors();
        $this->assertTrue(Settings::for((int) $this->business->id)->paused);
        $this->actingAs($this->owner)->put(route('admin.automation.rules.update', $rule->id), ['enabled' => false, 'cooldown_hours' => 12])->assertSessionHasNoErrors();
        $this->assertFalse($rule->fresh()->enabled);
        $this->assertSame(12, $rule->fresh()->cooldown_hours);
    }

    public function test_configuration_changes_are_audited(): void
    {
        $rule = $this->invoiceRule();
        Settings::save((int) $this->business->id, ['mode' => Settings::OBSERVE], $this->owner);
        Rules::save($rule, ['enabled' => false], $this->owner);

        $log = ActivityLog::where('business_id', $this->business->id)->pluck('description')->all();

        $this->assertTrue(collect($log)->contains(fn ($d) => str_contains($d, 'غيّر وضع الأتمتة من «off» إلى «live»')));
        $this->assertTrue(collect($log)->contains(fn ($d) => str_contains($d, 'غيّر وضع الأتمتة من «live» إلى «observe»')));
        $this->assertTrue(collect($log)->contains(fn ($d) => str_starts_with($d, 'فعّل قاعدة الأتمتة') && str_contains($d, 'تبلغ العميل')));
        $this->assertTrue(collect($log)->contains(fn ($d) => str_starts_with($d, 'أوقف قاعدة الأتمتة')));
    }

    public function test_no_secret_reaches_the_run_history_or_the_screens(): void
    {
        config(['services.whatsapp.token' => 'SECRET_WA_TOKEN', 'services.whatsapp.app_secret' => 'SECRET_APP', 'services.postmark.key' => 'SECRET_PM',
            'services.postmark.webhook_secret' => 'SECRET_HOOK', 'mail.mailers.smtp.password' => 'SECRET_SMTP', 'services.whatsapp.phone_number_id' => '1050']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token SECRET_WA_TOKEN']], 401)]);
        Templates::save((int) $this->business->id, 'invoice.reminder', 'whatsapp', 'ar', ['provider_template' => 'invoice_reminder', 'status' => 'active'], $this->owner);
        $this->invoice();
        $this->invoiceRule(over: ['channel' => 'whatsapp']);

        Engine::tick((int) $this->business->id);

        $dump = json_encode(AutomationRun::all()->toArray()).json_encode(Communication::all()->toArray());
        foreach (['SECRET_WA_TOKEN', 'SECRET_APP', 'SECRET_PM', 'SECRET_HOOK', 'SECRET_SMTP'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump, $secret);
        }

        foreach (['admin.automation.index', 'admin.automation.history'] as $name) {
            $props = json_encode($this->actingAs($this->owner)->get(route($name))->assertOk()->viewData('page')['props']);
            foreach (['SECRET_WA_TOKEN', 'SECRET_APP', 'SECRET_PM', 'SECRET_HOOK', 'SECRET_SMTP'] as $secret) {
                $this->assertStringNotContainsString($secret, $props, $name.' '.$secret);
            }
        }
        $run = AutomationRun::sole();
        $detail = json_encode($this->actingAs($this->owner)->getJson(route('admin.automation.history.show', $run->id))->assertOk()->json());
        $this->assertStringNotContainsString('SECRET', $detail);
    }

    public function test_a_pending_run_may_be_cancelled_by_hand_but_not_after_it_ran(): void
    {
        $this->invoice();
        $this->invoiceRule();
        Carbon::setTestNow(Carbon::parse('2026-09-20 20:00:00', 'Asia/Muscat'));
        Engine::tick((int) $this->business->id);
        $run = AutomationRun::sole();
        $this->assertSame(AutomationRun::PENDING, $run->status);

        $this->actingAs($this->owner)->post(route('admin.automation.runs.cancel', $run->id))->assertSessionHasNoErrors();
        $this->assertSame(AutomationRun::CANCELLED, $run->fresh()->status);

        Carbon::setTestNow(Carbon::parse('2026-09-21 09:30:00', 'Asia/Muscat'));
        Engine::tick((int) $this->business->id);
        Mail::assertNothingSent();
        $this->assertSame(1, AutomationRun::count(), 'الملغى لا يُعاد فتحُه: مفتاحُه محجوز');

        $this->actingAs($this->owner)->post(route('admin.automation.runs.cancel', $run->id))->assertSessionHasErrors('run');
    }
}
