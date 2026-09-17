<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\QuotationController;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\CompanyPolicy;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\PolicyException;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\User;
use App\Support\CompanyPolicies;
use App\Support\Permissions;
use App\Support\Policy\Registry;
use App\Support\Quotations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * الشركةُ تكتب سياساتها — والنصُّ يُختم في العرض، والفحصُ يرصد المخالفة.
 *
 * ═══ المطلب (§18) ═══
 *
 * «نصُّ السياسة أخطرُ من فحصها»: `text_ar` و`text_en` و`version` على
 * السياسة، وختمُها داخل العرض لحظةَ إرساله. والفحصُ الآليّ يبدأ بقاعدةٍ
 * واحدة: `lead_time_days`.
 *
 * ═══ وما يُفحص هنا ═══
 *
 * القائمةُ المغلقة · الشاشةُ وعزلُها · النسخةُ · النطاقُ · الختمُ والبصمة ·
 * الفحصُ والسببُ والاعتمادُ · الإرسالُ لا يمرّ على مخالفةٍ بلا استثناء ·
 * والتنقيحُ يحمل السببَ ويطلب الاعتمادَ ثانيةً.
 */
class TheCompanyWritesItsPoliciesDownTest extends TestCase
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
        $this->seller = User::create([
            'business_id' => $this->business->id, 'name' => 'البائع', 'email' => 'sales@hadath.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
        ]);

        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة']);

        $this->service = Product::create([
            'business_id' => $this->business->id, 'name' => 'تشغيل نظام تسجيل',
            'kind' => Product::SERVICE, 'price' => 500,
        ]);
    }

    private function leadTime(int $days = 7, float $surcharge = 10, array $over = []): CompanyPolicy
    {
        return CompanyPolicy::create($over + [
            'business_id' => $this->business->id, 'key' => 'lead_time_days',
            'params' => ['days' => $days, 'surcharge_percent' => $surcharge],
            'text_ar' => 'تُقبل الطلبات قبل الفعالية بسبعة أيام.',
            'text_en' => 'Orders are accepted seven days ahead.',
            'active' => true, 'version' => 1,
        ]);
    }

    /** @return array<string, mixed> */
    private function data(int $daysAhead, array $over = []): array
    {
        return $over + [
            'title' => 'مؤتمر', 'issued_at' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'event_starts_at' => now()->addDays($daysAhead)->toDateString(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function lines(): array
    {
        return [['product_id' => $this->service->id, 'quantity' => 2, 'unit_price' => 500]];
    }

    /* ─────────────────────────── القائمة المغلقة ─────────────────────────── */

    public function test_keys_are_a_closed_list_and_only_lead_time_has_a_check(): void
    {
        $this->assertTrue(Registry::known('lead_time_days'));
        $this->assertFalse(Registry::known('anything_the_user_types'));
        $this->assertNotNull(Registry::check('lead_time_days'));
        $this->assertNull(Registry::check('cancellation_window'));
        $this->assertArrayNotHasKey('discount_threshold', Registry::KEYS, 'حدُّ الخصم إعدادٌ من المرحلة 2 ولا يُكرَّر');
    }

    public function test_the_screen_refuses_an_unknown_key_and_unknown_params(): void
    {
        $this->actingAs($this->owner)->post(route('admin.settings.policies.store'), [
            'key' => 'made_up', 'text_ar' => 'نص',
        ])->assertSessionHasErrors('key');

        $this->actingAs($this->owner)->post(route('admin.settings.policies.store'), [
            'key' => 'lead_time_days', 'text_ar' => 'نص',
            'params' => ['days' => 7, 'surcharge_percent' => 10, 'evil' => 'DROP TABLE'],
        ])->assertSessionHasNoErrors();

        /* و`assertEquals`: JSON يردّ 10.0 عشرةً صحيحة — والقيمةُ هي ما يُقاس */
        $this->assertEquals(['days' => 7, 'surcharge_percent' => 10], CompanyPolicy::sole()->params);
    }

    /* ─────────────────────────── الشاشة والعزل ─────────────────────────── */

    public function test_the_policies_screen_opens_for_settings_holders_only(): void
    {
        $this->leadTime();

        $this->actingAs($this->owner)->get(route('admin.settings.policies.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Settings/Policies')
                ->has('policies', 1)
                ->where('policies.0.key', 'lead_time_days')
                ->has('catalog', count(Registry::KEYS))
                ->etc());

        $this->actingAs($this->seller)->get(route('admin.settings.policies.index'))->assertForbidden();
    }

    public function test_another_company_cannot_read_or_edit_these_policies(): void
    {
        $policy = $this->leadTime();

        $other = Business::create(['name' => 'أخرى', 'type' => 'عام', 'status' => 'نشط']);
        $stranger = User::create([
            'business_id' => $other->id, 'name' => 'غريب', 'email' => 's@x.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($stranger)->get(route('admin.settings.policies.index'))
            ->assertOk()->assertInertia(fn ($p) => $p->has('policies', 0)->etc());

        $this->actingAs($stranger)->put(route('admin.settings.policies.update', $policy->id), ['active' => false])
            ->assertNotFound();
        $this->actingAs($stranger)->delete(route('admin.settings.policies.destroy', $policy->id))->assertNotFound();

        $this->assertTrue($policy->fresh()->active);
    }

    public function test_a_scope_target_must_belong_to_the_company(): void
    {
        $other = Business::create(['name' => 'أخرى', 'type' => 'عام', 'status' => 'نشط']);
        $foreign = Customer::create(['business_id' => $other->id, 'name' => 'عميل الجار']);

        $this->actingAs($this->owner)->post(route('admin.settings.policies.store'), [
            'key' => 'lead_time_days', 'text_ar' => 'نص', 'scope_type' => 'customer', 'scope_id' => $foreign->id,
        ])->assertSessionHasErrors('scope_id');

        $this->actingAs($this->owner)->post(route('admin.settings.policies.store'), [
            'key' => 'lead_time_days', 'text_ar' => 'نص', 'scope_type' => 'customer',
        ])->assertSessionHasErrors('scope_id');
    }

    /* ─────────────────────────── النسخة ─────────────────────────── */

    public function test_editing_the_text_or_params_raises_the_version_and_toggling_does_not(): void
    {
        $policy = $this->leadTime();

        $this->actingAs($this->owner)->put(route('admin.settings.policies.update', $policy->id), ['active' => false]);
        $this->assertSame(1, $policy->fresh()->version);
        $this->assertFalse($policy->fresh()->active);

        $this->actingAs($this->owner)->put(route('admin.settings.policies.update', $policy->id), [
            'key' => 'lead_time_days', 'text_ar' => 'نصٌّ جديد', 'params' => ['days' => 7, 'surcharge_percent' => 10],
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, $policy->fresh()->version);

        $this->actingAs($this->owner)->put(route('admin.settings.policies.update', $policy->id), [
            'key' => 'lead_time_days', 'text_ar' => 'نصٌّ جديد', 'params' => ['days' => 10, 'surcharge_percent' => 10],
        ])->assertSessionHasNoErrors();
        $this->assertSame(3, $policy->fresh()->version);

        /* وتعديلُ تاريخ السريان وحدَه لا يغيّر ما يقرؤه العميل */
        $this->actingAs($this->owner)->put(route('admin.settings.policies.update', $policy->id), [
            'key' => 'lead_time_days', 'text_ar' => 'نصٌّ جديد', 'params' => ['days' => 10, 'surcharge_percent' => 10],
            'effective_from' => now()->addMonth()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(3, $policy->fresh()->version);
        $this->assertFalse($policy->fresh()->isInForce());
    }

    /* ─────────────────────────── النطاق ─────────────────────────── */

    public function test_the_most_specific_policy_wins_within_a_key(): void
    {
        $general = $this->leadTime(7);
        $forCustomer = $this->leadTime(3, 0, ['scope_type' => 'customer', 'scope_id' => $this->customer->id]);
        $other = Customer::create(['business_id' => $this->business->id, 'name' => 'آخر']);

        $draft = Quotations::draft((int) $this->business->id, $this->customer, $this->data(5), $this->lines(), $this->owner);
        $applicable = CompanyPolicies::applicable((int) $this->business->id, $draft);
        $this->assertCount(1, $applicable);
        $this->assertSame($forCustomer->id, $applicable->first()->id);
        /* وخمسةُ أيّامٍ تكفي لعميلٍ مهلتُه ثلاثة */
        $this->assertSame([], CompanyPolicies::findings((int) $this->business->id, $draft));

        $draft = Quotations::draft((int) $this->business->id, $other, $this->data(5), $this->lines(), $this->owner);
        $this->assertSame($general->id, CompanyPolicies::applicable((int) $this->business->id, $draft)->first()->id);
        $this->assertCount(1, CompanyPolicies::findings((int) $this->business->id, $draft));
    }

    public function test_a_category_scoped_policy_applies_only_when_an_item_is_in_it(): void
    {
        $category = Category::create(['business_id' => $this->business->id, 'name' => 'طباعة']);
        $printing = Product::create(['business_id' => $this->business->id, 'name' => 'طباعة بطاقات',
            'kind' => Product::ITEM, 'price' => 3, 'category_id' => $category->id]);
        $this->leadTime(14, 0, ['scope_type' => 'category', 'scope_id' => $category->id]);

        $draft = Quotations::draft((int) $this->business->id, $this->customer, $this->data(10), $this->lines(), $this->owner);
        $this->assertCount(0, CompanyPolicies::applicable((int) $this->business->id, $draft));

        $draft = Quotations::draft((int) $this->business->id, $this->customer, $this->data(10),
            [['product_id' => $printing->id, 'quantity' => 100, 'unit_price' => 3]], $this->owner);
        $this->assertCount(1, CompanyPolicies::applicable((int) $this->business->id, $draft));
        $this->assertCount(1, CompanyPolicies::findings((int) $this->business->id, $draft));
    }

    public function test_inactive_or_future_policies_do_not_apply(): void
    {
        $this->leadTime(7, 0, ['active' => false]);
        $this->leadTime(7, 0, ['effective_from' => now()->addWeek()->toDateString()]);

        $draft = Quotations::draft((int) $this->business->id, $this->customer, $this->data(2), $this->lines(), $this->owner);
        $this->assertCount(0, CompanyPolicies::applicable((int) $this->business->id, $draft));
    }

    /* ─────────────────────────── الفحص ─────────────────────────── */

    public function test_lead_time_is_detected_with_the_suggested_surcharge(): void
    {
        $this->leadTime(7, 10);

        $draft = Quotations::draft((int) $this->business->id, $this->customer, $this->data(3), $this->lines(), $this->owner);
        $findings = CompanyPolicies::findings((int) $this->business->id, $draft);

        $this->assertCount(1, $findings);
        $f = $findings[0]->toArray();
        $this->assertSame('lead_time_days', $f['key']);
        $this->assertSame(7, $f['detected']['required_days']);
        $this->assertSame(3, $f['detected']['available_days']);
        /* 10٪ من 1000 */
        $this->assertSame(100.0, $f['suggested_surcharge']);
        $this->assertTrue($f['requires_approval']);
    }

    public function test_no_event_date_or_enough_days_means_no_finding(): void
    {
        $this->leadTime(7);

        $noDate = Quotations::draft((int) $this->business->id, $this->customer,
            $this->data(3, ['event_starts_at' => null]), $this->lines(), $this->owner);
        $this->assertSame([], CompanyPolicies::findings((int) $this->business->id, $noDate));

        $enough = Quotations::draft((int) $this->business->id, $this->customer, $this->data(7), $this->lines(), $this->owner);
        $this->assertSame([], CompanyPolicies::findings((int) $this->business->id, $enough));
    }

    public function test_the_preview_reports_findings_before_saving(): void
    {
        $this->leadTime(7);

        $this->actingAs($this->owner)->postJson(route('admin.quotations.preview'),
            $this->data(3, ['customer_id' => $this->customer->id, 'items' => $this->lines()]))
            ->assertOk()
            ->assertJsonPath('findings.0.key', 'lead_time_days')
            ->assertJsonPath('findings.0.detected.available_days', 3);
    }

    /* ─────────────────────────── السبب والاعتماد ─────────────────────────── */

    public function test_a_violation_without_a_reason_is_not_saved(): void
    {
        $this->leadTime(7);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('اكتب سبب الاستثناء');

        Quotations::create((int) $this->business->id, $this->customer, $this->data(3), $this->lines(), $this->owner);
    }

    public function test_a_seller_records_the_exception_and_waits_for_approval(): void
    {
        $policy = $this->leadTime(7, 10);

        $q = Quotations::create((int) $this->business->id, $this->customer, $this->data(3), $this->lines(), $this->seller, [
            ['key' => 'lead_time_days', 'reason' => 'العميل يطلب الفعالية عاجلًا', 'surcharge_amount' => 80],
        ]);

        $this->assertSame(Quotation::PENDING_APPROVAL, $q->status);

        $e = $q->policyExceptions()->sole();
        $this->assertSame('lead_time_days', $e->policy_key);
        $this->assertSame($policy->id, (int) $e->company_policy_id);
        $this->assertSame(1, $e->policy_version);
        $this->assertSame(3, $e->detected['available_days']);
        $this->assertSame(100.0, (float) $e->detected['suggested_surcharge']);
        $this->assertSame(80.0, (float) $e->surcharge_amount, 'الرسومُ ما اختاره الموظّف لا ما اقتُرح');
        $this->assertSame((int) $this->seller->id, (int) $e->requested_by);
        $this->assertNull($e->approved_at);
        $this->assertSame((int) $this->business->id, (int) $e->business_id);

        /* والإرسالُ لا يمرّ */
        try {
            Quotations::send($q, $this->owner);
            $this->fail('أُرسل عرضٌ ينتظر اعتمادًا');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ينتظر اعتمادًا', $e->getMessage());
        }
    }

    public function test_whoever_holds_policy_override_approves_at_once(): void
    {
        $this->leadTime(7);
        $this->assertTrue($this->owner->may(Permissions::POLICY_OVERRIDE));
        $this->assertFalse($this->seller->may(Permissions::POLICY_OVERRIDE));

        $q = Quotations::create((int) $this->business->id, $this->customer, $this->data(3), $this->lines(), $this->owner, [
            ['key' => 'lead_time_days', 'reason' => 'أوافق'],
        ]);

        $this->assertSame(Quotation::DRAFT, $q->status);
        $this->assertNotNull($q->policyExceptions()->sole()->approved_at);
        $this->assertSame((int) $this->owner->id, (int) $q->policyExceptions()->sole()->approved_by);
    }

    public function test_the_manager_approves_the_exception_and_the_quotation_goes_out(): void
    {
        $this->leadTime(7);
        $q = Quotations::create((int) $this->business->id, $this->customer, $this->data(3), $this->lines(), $this->seller, [
            ['key' => 'lead_time_days', 'reason' => 'عاجل'],
        ]);

        $this->actingAs($this->owner)->post(route('admin.quotations.approve', $q->id))->assertSessionHasNoErrors();

        $q->refresh();
        $this->assertSame(Quotation::DRAFT, $q->status);
        $this->assertNotNull($q->policyExceptions()->sole()->approved_at);

        $sent = Quotations::send($q, $this->owner);
        $this->assertSame(Quotation::SENT, $sent->status);
    }

    public function test_a_seller_cannot_approve_the_exception(): void
    {
        $this->leadTime(7);
        $q = Quotations::create((int) $this->business->id, $this->customer, $this->data(3), $this->lines(), $this->seller, [
            ['key' => 'lead_time_days', 'reason' => 'عاجل'],
        ]);

        $this->actingAs($this->seller)->post(route('admin.quotations.approve', $q->id))->assertForbidden();
        $this->assertSame(Quotation::PENDING_APPROVAL, $q->fresh()->status);
    }

    public function test_editing_the_draft_rewrites_exceptions_and_asks_approval_again(): void
    {
        $this->leadTime(7);
        $q = Quotations::create((int) $this->business->id, $this->customer, $this->data(3), $this->lines(), $this->seller, [
            ['key' => 'lead_time_days', 'reason' => 'عاجل'],
        ]);
        Quotations::approve($q, $this->owner);
        $this->assertSame(Quotation::DRAFT, $q->fresh()->status);

        $q = Quotations::update($q->fresh(), $this->data(2), $this->lines(), $this->seller, [
            ['key' => 'lead_time_days', 'reason' => 'صار أعجل'],
        ]);

        $this->assertSame(Quotation::PENDING_APPROVAL, $q->status);
        $this->assertNull($q->policyExceptions()->sole()->approved_at);
        $this->assertSame('صار أعجل', $q->policyExceptions()->sole()->reason);

        /* وتعديلٌ يزيل المخالفة يزيل الاستثناء */
        $q = Quotations::update($q->fresh(), $this->data(20), $this->lines(), $this->seller);
        $this->assertSame(Quotation::DRAFT, $q->status);
        $this->assertSame(0, $q->policyExceptions()->count());
    }

    public function test_the_controller_accepts_exceptions_from_the_form(): void
    {
        $this->leadTime(7);

        $this->actingAs($this->owner)->post(route('admin.quotations.store'), $this->data(3, [
            'customer_id' => $this->customer->id, 'items' => $this->lines(),
            'exceptions' => [['key' => 'lead_time_days', 'reason' => 'عاجل', 'surcharge_amount' => '50']],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(50.0, (float) PolicyException::sole()->surcharge_amount);

        $this->actingAs($this->owner)->post(route('admin.quotations.store'), $this->data(3, [
            'customer_id' => $this->customer->id, 'items' => $this->lines(),
            'exceptions' => [['key' => 'not_a_policy', 'reason' => 'x']],
        ]))->assertSessionHasErrors('exceptions.0.key');
    }

    /* ─────────────────────────── الختم ─────────────────────────── */

    public function test_the_text_is_stamped_at_send_and_survives_later_edits(): void
    {
        $policy = $this->leadTime(7);
        CompanyPolicy::create(['business_id' => $this->business->id, 'key' => 'cancellation_window',
            'params' => ['days' => 14], 'text_ar' => 'الإلغاءُ قبل أسبوعين.', 'active' => true]);

        $q = Quotations::create((int) $this->business->id, $this->customer, $this->data(20), $this->lines(), $this->owner);
        $this->assertNull($q->policy_snapshot);

        $sent = Quotations::send($q, $this->owner);

        $this->assertCount(2, $sent->policy_snapshot);
        $this->assertSame('تُقبل الطلبات قبل الفعالية بسبعة أيام.', $sent->policy_snapshot[0]['text_ar']);
        $this->assertSame('Orders are accepted seven days ahead.', $sent->policy_snapshot[0]['text_en']);
        $this->assertSame(1, $sent->policy_snapshot[0]['version']);

        $hashBefore = $sent->content_hash;

        /* ثمّ تُعدَّل السياسة — والعرضُ يقرأ نصَّ يومه */
        $policy->update(['text_ar' => 'نصٌّ آخر تمامًا', 'version' => 2]);

        $texts = CompanyPolicies::texts($sent->fresh());
        $this->assertSame('تُقبل الطلبات قبل الفعالية بسبعة أيام.', $texts[0]['text']);
        $this->assertSame($hashBefore, Quotations::contentHash($sent->fresh()->load('items')));

        /* والورقةُ تطبع الختم */
        $html = QuotationController::paper((int) $this->business->id, $sent->fresh()->load('items'))->render();
        $this->assertStringContainsString('تُقبل الطلبات قبل الفعالية بسبعة أيام.', $html);
        $this->assertStringContainsString('الإلغاءُ قبل أسبوعين.', $html);
        $this->assertStringNotContainsString('نصٌّ آخر تمامًا', $html);
    }

    public function test_the_fingerprint_covers_the_policy_text(): void
    {
        $this->leadTime(7);
        $first = Quotations::send(Quotations::create((int) $this->business->id, $this->customer, $this->data(20), $this->lines(), $this->owner), $this->owner);

        CompanyPolicy::sole()->update(['text_ar' => 'نصٌّ مختلف', 'version' => 2]);
        $second = Quotations::send(Quotations::create((int) $this->business->id, $this->customer, $this->data(20), $this->lines(), $this->owner), $this->owner);

        $this->assertNotSame($first->content_hash, $second->content_hash, 'البنودُ واحدة والنصُّ مختلف — فالبصمةُ مختلفة');
    }

    public function test_a_draft_previews_the_live_text_and_a_sent_one_the_stamped(): void
    {
        $this->leadTime(7);
        $draft = Quotations::draft((int) $this->business->id, $this->customer, $this->data(20), $this->lines(), $this->owner);

        $this->assertSame('تُقبل الطلبات قبل الفعالية بسبعة أيام.', CompanyPolicies::texts($draft)[0]['text']);
        $this->assertSame('Orders are accepted seven days ahead.', CompanyPolicies::texts($draft, 'en')[0]['text']);
    }

    /* ─────────────────────────── الإرسال والتنقيح ─────────────────────────── */

    public function test_sending_refuses_a_violation_that_has_no_exception(): void
    {
        /* الحفظُ وقع قبل السياسة — ثمّ أُضيفت */
        $q = Quotations::create((int) $this->business->id, $this->customer, $this->data(3), $this->lines(), $this->owner);
        $this->leadTime(7);

        try {
            Quotations::send($q, $this->owner);
            $this->fail('أُرسل عرضٌ يخالف سياسةً بلا استثناء');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('يخالف سياسة', $e->getMessage());
        }

        $this->assertSame(Quotation::DRAFT, $q->fresh()->status);
        $this->assertNull($q->fresh()->number, 'ولا رقمَ يُقطع لما لم يخرج');

        /* والشاشةُ تقولها قبل الزرّ */
        $this->actingAs($this->owner)->get(route('admin.quotations.show', $q->id))
            ->assertInertia(fn ($p) => $p->has('quotation.unsettled', 1)->etc());
    }

    public function test_a_revision_carries_the_reason_and_asks_for_approval_again(): void
    {
        $this->leadTime(7);
        $q = Quotations::create((int) $this->business->id, $this->customer, $this->data(3), $this->lines(), $this->seller, [
            ['key' => 'lead_time_days', 'reason' => 'عاجل', 'surcharge_amount' => 40],
        ]);
        Quotations::approve($q, $this->owner);
        $sent = Quotations::send($q->fresh(), $this->owner);

        $copy = Quotations::revise($sent, $this->seller);

        $this->assertSame(Quotation::PENDING_APPROVAL, $copy->status);
        $e = $copy->policyExceptions()->sole();
        $this->assertSame('عاجل', $e->reason);
        $this->assertSame(40.0, (float) $e->surcharge_amount);
        $this->assertNull($e->approved_at);
        $this->assertNull($copy->policy_snapshot, 'والختمُ لا يُورَّث — يُؤخذ من جديد عند الإرسال');

        /* وبيد من يملك التجاوز يبدأ التنقيحُ معتمَدًا */
        $byOwner = Quotations::revise(Quotations::send(Quotations::approve($copy, $this->owner), $this->owner), $this->owner);
        $this->assertSame(Quotation::DRAFT, $byOwner->status);
    }

    /* ─────────────────────────── الأثر ─────────────────────────── */

    public function test_the_exception_and_its_approval_are_logged(): void
    {
        $this->leadTime(7);
        $this->actingAs($this->seller);
        $q = Quotations::create((int) $this->business->id, $this->customer, $this->data(3), $this->lines(), $this->seller, [
            ['key' => 'lead_time_days', 'reason' => 'العميل يصرّ'],
        ]);

        $this->assertTrue(ActivityLog::where('subject_type', 'quotation')->where('subject_id', $q->id)
            ->where('description', 'like', '%تجاوز سياسة «مهلة الطلب»%')
            ->where('description', 'like', '%العميل يصرّ%')
            ->where('description', 'like', '%ينتظر اعتمادًا%')->exists());

        $this->actingAs($this->owner);
        Quotations::approve($q, $this->owner);

        $this->assertTrue(ActivityLog::where('subject_type', 'quotation')->where('subject_id', $q->id)
            ->where('user_id', $this->owner->id)
            ->where('description', 'like', '%اعتمد استثناء السياسة%')->exists());
    }

    public function test_the_registry_labels_and_texts_are_translated(): void
    {
        foreach (Registry::KEYS as $spec) {
            $this->assertNotSame($spec['label'], trans($spec['label'], [], 'en'), $spec['label'].' بلا ترجمة');
        }
    }
}
