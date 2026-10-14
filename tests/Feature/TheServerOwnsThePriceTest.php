<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Quotations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * السعرُ يملكه الخادمُ لا المتصفّح.
 *
 * ═══ المطلب ═══
 *
 * «الموظف العادي يستطيع اختيار الخدمة والكمية. لكن لا يستطيع تغيير السعر
 * بحرية.» وهذا الملفُّ يحرسه على الطبقة التي تقرّر: `Quotations::price`.
 *
 * ═══ ولمَ عمودان للسعر لا واحد ═══
 *
 * بعمودٍ واحدٍ لا يُعرف **أوقع خصمٌ أصلًا**: من يرسل سعرًا أقلّ يُخفي الخصمَ
 * في الرقم، فلا سببَ يُطلب ولا اعتمادَ يُشترط ولا أثرَ يبقى. فالسعرُ
 * المعتمد يُقرأ من الكتالوج في كلّ حفظ، والمعروضُ يُقارَن به.
 *
 * ═══ وما يُفحص هنا ═══
 *
 * سلطةُ الكتالوج · تزويرُ السعر من المتصفّح · صلاحيّةُ الخصم · اعتمادُه ·
 * التجاوزُ فوق السعر · الضريبةُ · البندُ الحرّ · وعزلُ الشركات.
 */
class TheServerOwnsThePriceTest extends TestCase
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

        /* والبائعُ يكتب ويُرسل ولا يمسّ السعر — انظر `Permissions::ACTION_ROLES` */
        $this->seller = User::create([
            'business_id' => $this->business->id, 'name' => 'بائع', 'email' => 'sales@hadath.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'وزارة',
            'legal_name' => 'وزارة الطاقة', 'tax_number' => 'OM1100999999',
        ]);

        $this->service = Product::create([
            'business_id' => $this->business->id, 'name' => 'تشغيل نظام تسجيل',
            'kind' => Product::SERVICE, 'price' => 500, 'cost' => 0, 'quantity' => 0,
        ]);
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function create(array $lines, ?User $actor = null, array $data = []): Quotation
    {
        return Quotations::create(
            (int) $this->business->id,
            $this->customer,
            $data + ['title' => 'مؤتمر الطاقة', 'issued_at' => now()->toDateString()],
            $lines,
            $actor ?? $this->owner,
        );
    }

    /* ─────────────────── سلطةُ الكتالوج ─────────────────── */

    public function test_the_list_price_comes_from_the_catalogue(): void
    {
        $quotation = $this->create([[
            'product_id' => $this->service->id, 'quantity' => 2, 'unit_price' => 500,
        ]]);

        $item = $quotation->items->first();

        $this->assertSame('500.000', $item->list_price);
        $this->assertSame('500.000', $item->unit_price);
        $this->assertSame('0.000', $item->discount);
        $this->assertSame('تشغيل نظام تسجيل', $item->description, 'الوصفُ يُملأ من الكتالوج حين لا يُكتب');
    }

    /**
     * وخصمُ الصنف المحفوظ في الكتالوج هو السعرُ المعتمد.
     *
     * `Product::sellingPrice()` تطبّقه أصلًا، ولا يُحسب سعرٌ ثانٍ هنا: لو
     * حُسب لقال الكتالوجُ شيئًا وقال العرضُ غيرَه.
     */
    public function test_the_list_price_is_the_selling_price_not_the_raw_one(): void
    {
        $this->service->update(['discount' => 10]);

        $quotation = $this->create([[
            'product_id' => $this->service->id, 'quantity' => 1, 'unit_price' => 450,
        ]]);

        $item = $quotation->items->first();

        $this->assertSame('450.000', $item->list_price, '٥٠٠ ناقص ١٠٪');
        $this->assertSame('0.000', $item->discount, 'وبيعٌ بالسعر المعتمد ليس خصمًا');
    }

    /**
     * ═══ والمتصفّحُ لا يزوّر سعرًا معتمدًا ═══
     *
     * `list_price` لا يُقبل من الطلب إطلاقًا — لا في قواعد التحقّق ولا في
     * طبقة التسعير. ومن أرسله لم يرسل شيئًا.
     */
    public function test_a_forged_list_price_in_the_request_is_ignored(): void
    {
        $quotation = $this->create([[
            'product_id' => $this->service->id,
            'quantity' => 1,
            'unit_price' => 500,
            /* ما يرسله من يفتح أدوات المتصفّح ليُخفي خصمَه */
            'list_price' => 500000,
            'discount' => 0,
            'tax_amount' => 0,
            'line_total' => 1,
        ]]);

        $item = $quotation->items->first();

        $this->assertSame('500.000', $item->list_price);
        $this->assertSame('525.000', $item->line_total, 'الإجماليُّ محسوبٌ لا مقروء');
        $this->assertSame(500.0, (float) $quotation->subtotal);
        $this->assertSame(525.0, (float) $quotation->total);
    }

    /** والمسارُ نفسُه يردّه: التحقّقُ لا يسمّي `list_price` أصلًا */
    public function test_the_route_does_not_accept_a_list_price(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.quotations.store'), [
                'customer_id' => $this->customer->id,
                'title' => 'مؤتمر',
                'items' => [[
                    'product_id' => $this->service->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                    'list_price' => 999999,
                ]],
            ])->assertRedirect();

        $this->assertSame('500.000', Quotation::first()->items->first()->list_price);
    }

    /** ومعرّفُ صنفٍ من شركةٍ أخرى يُردّ في الخادم لا في الشاشة */
    public function test_an_item_from_another_company_is_refused(): void
    {
        $other = Business::create(['name' => 'أخرى', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Product::create(['business_id' => $other->id, 'name' => 'خدمتهم', 'price' => 10]);

        $this->expectException(RuntimeException::class);

        $this->create([['product_id' => $theirs->id, 'quantity' => 1, 'unit_price' => 10]]);
    }

    /* ─────────────────── الخصمُ وصلاحيّتُه ─────────────────── */

    public function test_a_seller_cannot_discount(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('منح الخصم يحتاج صلاحيةً لا تملكها.');

        $this->create([[
            'product_id' => $this->service->id, 'quantity' => 1, 'unit_price' => 400,
            'discount_reason' => 'تفاوض',
        ]], $this->seller);
    }

    public function test_a_seller_cannot_sell_above_the_list_price(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('البيع فوق سعر الكتالوج يحتاج صلاحيةً لا تملكها.');

        $this->create([[
            'product_id' => $this->service->id, 'quantity' => 1, 'unit_price' => 600,
            'discount_reason' => 'موقع بعيد',
        ]], $this->seller);
    }

    /**
     * والتجاوزُ فوق السعر للمالك وحده — لا لمدير الفرع.
     *
     * قرارٌ يمسّ سمعةَ التسعير كلَّه لا صفقةً واحدة: عميلان يقارنان عرضيهما
     * فيجدان الخدمةَ نفسَها بسعرين.
     */
    public function test_only_the_owner_may_sell_above_the_list_price(): void
    {
        $manager = User::create([
            'business_id' => $this->business->id, 'name' => 'مدير فرع', 'email' => 'm@hadath.om',
            'password' => bcrypt('password'), 'role' => 'manager', 'status' => 'نشط',
        ]);

        $this->assertFalse($manager->may(Permissions::QUOTATION_OVERRIDE_PRICE));
        $this->assertTrue($this->owner->may(Permissions::QUOTATION_OVERRIDE_PRICE));
    }

    /** وخصمٌ بلا سببٍ يُردّ — الأثرُ بلا سببٍ لا يُقرأ بعد سنة */
    public function test_a_discount_without_a_reason_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('اكتب سبب تغيير السعر');

        $this->create([[
            'product_id' => $this->service->id, 'quantity' => 1, 'unit_price' => 450,
        ]]);
    }

    /**
     * والخصمُ يُسجَّل كاملًا: القيمةُ والنسبةُ والسببُ ومن طلبه ومن اعتمده.
     *
     * والنسبةُ **تُشتقّ ولا تُخزَّن**: عمودٌ بالقيمة وعمودٌ بالنسبة يقولان
     * الشيء نفسه ويفترقان عند أوّل تعديل.
     */
    public function test_a_discount_records_its_whole_trail(): void
    {
        $quotation = $this->create([[
            'product_id' => $this->service->id, 'quantity' => 2, 'unit_price' => 450,
            'discount_reason' => 'عميل متكرّر',
        ]]);

        $item = $quotation->items->first();

        $this->assertSame('500.000', $item->list_price, 'الأصل');
        $this->assertSame('450.000', $item->unit_price, 'المعروض');
        $this->assertSame('100.000', $item->discount, '٥٠ × ٢');
        $this->assertSame(10.0, $item->discountPercent());
        $this->assertSame('عميل متكرّر', $item->discount_reason);
        $this->assertSame($this->owner->id, $item->discount_requested_by);
        /* والمالكُ يملك الاعتماد — فلا ينتظر نفسَه */
        $this->assertSame($this->owner->id, $item->discount_approved_by);
        $this->assertNotNull($item->discount_approved_at);
        $this->assertSame(Quotation::DRAFT, $quotation->status);
    }

    /* ─────────────────── الحدُّ والاعتماد ─────────────────── */

    /**
     * والحدُّ صفرٌ افتراضًا — فيُغلق الباب لا يُفتح.
     *
     * مفتاحٌ لم يُضبط بعد يعني «لم يقرّر أحدٌ شيئًا»، وقراءتُه «بلا حدّ»
     * تمنح الموظّفين خصمًا مفتوحًا حتى ينتبه صاحبُ العمل.
     */
    public function test_the_threshold_closes_the_door_by_default(): void
    {
        $this->assertSame(0.0, Quotations::threshold((int) $this->business->id));

        Setting::create(['business_id' => $this->business->id,
            'key' => Quotations::THRESHOLD_KEY, 'value' => '15']);

        $this->assertSame(15.0, Quotations::threshold((int) $this->business->id));
    }

    public function test_a_discount_beyond_the_limit_waits_for_approval(): void
    {
        Setting::create(['business_id' => $this->business->id,
            'key' => Quotations::THRESHOLD_KEY, 'value' => '5']);

        /* ومن يملك الخصمَ ولا يملك الاعتماد */
        $closer = User::create([
            'business_id' => $this->business->id, 'name' => 'مسؤول مبيعات', 'email' => 'c@hadath.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
            'permissions' => ['quotations', Permissions::QUOTATION_CREATE, Permissions::QUOTATION_DISCOUNT],
        ]);

        $quotation = $this->create([[
            'product_id' => $this->service->id, 'quantity' => 1, 'unit_price' => 400,
            'discount_reason' => 'مناقصة',
        ]], $closer);

        $this->assertSame(Quotation::PENDING_APPROVAL, $quotation->status, '٢٠٪ فوق حدّ ٥٪');
        $this->assertNull($quotation->items->first()->discount_approved_by);
        $this->assertSame($closer->id, $quotation->items->first()->discount_requested_by);

        /* ولا يخرج قبل الاعتماد */
        try {
            Quotations::send($quotation, $closer);
            $this->fail('خرج عرضٌ ينتظر اعتمادًا');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ينتظر اعتمادًا', $e->getMessage());
        }

        $approved = Quotations::approve($quotation->fresh('items'), $this->owner);

        $this->assertSame(Quotation::DRAFT, $approved->status);
        $this->assertSame($this->owner->id, $approved->approved_by);
        $this->assertSame($this->owner->id, $approved->items->first()->discount_approved_by,
            'والاعتمادُ يُنسب على البند أيضًا — فيُقرأ بلا فتح الرأس');
    }

    public function test_a_discount_within_the_limit_passes(): void
    {
        Setting::create(['business_id' => $this->business->id,
            'key' => Quotations::THRESHOLD_KEY, 'value' => '20']);

        $closer = User::create([
            'business_id' => $this->business->id, 'name' => 'مسؤول', 'email' => 'c2@hadath.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
            'permissions' => ['quotations', Permissions::QUOTATION_CREATE, Permissions::QUOTATION_DISCOUNT],
        ]);

        $quotation = $this->create([[
            'product_id' => $this->service->id, 'quantity' => 1, 'unit_price' => 450,
            'discount_reason' => 'تفاوض',
        ]], $closer);

        $this->assertSame(Quotation::DRAFT, $quotation->status, '١٠٪ تحت حدّ ٢٠٪');
    }

    /**
     * وتعديلُ المسودّة بعد اعتمادها يمحو الاعتماد.
     *
     * اعتُمد خصمٌ بعشرين ثمّ غُيّرت البنود — فالاعتمادُ وقع على ورقةٍ لم
     * تعد قائمة، وإبقاؤه يجعل من اعتمد مسؤولًا عمّا لم يره.
     */
    public function test_editing_after_approval_clears_it(): void
    {
        Setting::create(['business_id' => $this->business->id,
            'key' => Quotations::THRESHOLD_KEY, 'value' => '5']);

        $closer = User::create([
            'business_id' => $this->business->id, 'name' => 'مسؤول', 'email' => 'c3@hadath.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
            'permissions' => ['quotations', Permissions::QUOTATION_CREATE, Permissions::QUOTATION_DISCOUNT],
        ]);

        $quotation = $this->create([[
            'product_id' => $this->service->id, 'quantity' => 1, 'unit_price' => 400,
            'discount_reason' => 'مناقصة',
        ]], $closer);

        $quotation = Quotations::approve($quotation, $this->owner);
        $this->assertNotNull($quotation->approved_at);

        $quotation = Quotations::update($quotation, ['title' => 'مؤتمر الطاقة'], [[
            'product_id' => $this->service->id, 'quantity' => 1, 'unit_price' => 380,
            'discount_reason' => 'مناقصة أوسع',
        ]], $closer);

        $this->assertNull($quotation->approved_at);
        $this->assertSame(Quotation::PENDING_APPROVAL, $quotation->status);
    }

    /* ─────────────────── الضريبةُ والبندُ الحرّ ─────────────────── */

    /**
     * والضريبةُ تُحسب على الصافي بعد الخصم — لا على سعر الكتالوج.
     *
     * وهي القاعدةُ نفسُها في `CustomerInvoices::compute` لأنّها المحرّكُ
     * نفسُه: `LineTotals`. فما يُعرض وما يُفوتَر لا يفترقان.
     */
    public function test_vat_is_computed_on_the_net_by_the_same_engine(): void
    {
        $quotation = $this->create([[
            'product_id' => $this->service->id, 'quantity' => 2, 'unit_price' => 450,
            'discount_reason' => 'تفاوض',
        ]]);

        $this->assertSame(1000.0, (float) $quotation->subtotal, '٥٠٠ × ٢ بسعر الكتالوج');
        $this->assertSame(100.0, (float) $quotation->discount_total);
        $this->assertSame(45.0, (float) $quotation->tax_total, '٥٪ على ٩٠٠');
        $this->assertSame(945.0, (float) $quotation->total);

        /* والخاصّيّةُ التي تُراجَع بها الورقة: البنودُ تجمع إلى مجموعها */
        $this->assertEqualsWithDelta(
            (float) $quotation->subtotal - (float) $quotation->discount_total + (float) $quotation->tax_total,
            (float) $quotation->items->sum('line_total'),
            0.0005,
        );
    }

    /** وبندٌ معفًى يُكتب بنسبة صفرٍ — والإعفاءُ لقطةٌ في السطر */
    public function test_an_exempt_item_carries_its_own_rate(): void
    {
        $exempt = Product::create([
            'business_id' => $this->business->id, 'name' => 'رسوم حكومية',
            'kind' => Product::SERVICE, 'price' => 100, 'tax' => 0,
        ]);

        $quotation = $this->create([[
            'product_id' => $exempt->id, 'quantity' => 1, 'unit_price' => 100,
        ]]);

        $this->assertSame('0.00', $quotation->items->first()->tax_rate);
        $this->assertSame(0.0, (float) $quotation->tax_total);
    }

    /**
     * والبندُ الحرُّ لا سعرَ كتالوجٍ له — فلا خصمَ عليه ولا اعتماد.
     *
     * وهو بابٌ مفتوحٌ عن قصد: البندُ الحرّ هو الطريقةُ الطبيعيّة لعرض عملٍ
     * مفصّل لا نظيرَ له في الكتالوج، ومنعُه يُعطّل أكثرَ ما تبيعه الشركة.
     * انظر التعليق في `Quotations::classify`.
     */
    public function test_a_free_text_line_has_no_list_price_to_discount_from(): void
    {
        $quotation = $this->create([[
            'product_id' => null,
            'description' => 'تصميم هويّة بصرية للفعالية',
            'unit' => 'مشروع',
            'quantity' => 1,
            'unit_price' => 750,
        ]], $this->seller);

        $item = $quotation->items->first();

        $this->assertTrue($item->isFreeText());
        $this->assertSame('750.000', $item->list_price, 'المعروضُ هو المعتمد');
        $this->assertSame('0.000', $item->discount);
        $this->assertNull($item->discount_requested_by);
        $this->assertSame(Quotation::DRAFT, $quotation->status, 'ولا اعتمادَ ينتظره');
    }

    /* ─────────────────── المعاينة ─────────────────── */

    /**
     * والمعاينةُ تُرسم ولا تُكتب.
     *
     * من فتح شاشة الإنشاء وكتب بندًا ثمّ تركها لا يجب أن يترك خلفه مسودّةً
     * في دفتره ولا فجوةً في تسلسل أرقامه. وهي القاعدةُ نفسُها في
     * `CustomerInvoices::draft`.
     */
    public function test_the_preview_draws_without_writing(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson(route('admin.quotations.preview'), [
                'customer_id' => $this->customer->id,
                'title' => 'مؤتمر الطاقة',
                'items' => [[
                    'product_id' => $this->service->id,
                    'quantity' => 2,
                    'unit_price' => 500,
                ]],
            ])->assertOk();

        $html = $response->json('html');

        $this->assertStringContainsString('وزارة الطاقة', $html);
        $this->assertStringContainsString('تشغيل نظام تسجيل', $html);
        /* ولا رقمَ لها: لم تُرسَل، ولم يُقطع لها شيء */
        $this->assertStringContainsString('مسودة', $html);

        $this->assertSame(0, Quotation::count(), 'المعاينةُ لا تكتب صفًّا');
        $this->assertSame('Q-000001', Quotations::nextNumber((int) $this->business->id));
    }

    /** والمعاينةُ تخضع للضبط نفسِه: بائعٌ لا يعاين خصمًا لا يملكه */
    /**
     * والورقةُ تُرسم من أوّل بند — قبل العنوان وقبل العميل.
     *
     * كانت المعاينةُ تشترط العنوانَ كالحفظ، فتُردّ بـ٤٢٢ صامتة على شاشةٍ
     * فُتحت للتوّ، ولا تظهر الورقةُ بجانب النموذج حتّى يُكتب. والحفظُ يبقى
     * يشترطه.
     */
    public function test_the_paper_shows_beside_the_form_before_the_title_is_written(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('admin.quotations.preview'), [
                'customer_id' => '', 'title' => '',
                'items' => [['product_id' => null, 'description' => '', 'quantity' => 1, 'unit_price' => 0]],
            ])->assertOk()->assertJsonStructure(['html']);

        $this->actingAs($this->owner)
            ->post(route('admin.quotations.store'), [
                'customer_id' => $this->customer->id, 'title' => '',
                'items' => [['description' => 'تنظيم', 'quantity' => 1, 'unit_price' => 100]],
            ])->assertSessionHasErrors('title');
    }

    public function test_the_preview_obeys_the_same_price_rules(): void
    {
        $this->actingAs($this->seller)
            ->postJson(route('admin.quotations.preview'), [
                'customer_id' => $this->customer->id,
                'title' => 'مؤتمر',
                'items' => [[
                    'product_id' => $this->service->id,
                    'quantity' => 1,
                    'unit_price' => 400,
                    'discount_reason' => 'تفاوض',
                ]],
            ])->assertStatus(422);
    }

    /* ─────────────────── العلاقاتُ والعزل ─────────────────── */

    public function test_a_quotation_belongs_to_the_existing_customer(): void
    {
        $quotation = $this->create([[
            'product_id' => $this->service->id, 'quantity' => 1, 'unit_price' => 500,
        ]]);

        $this->assertSame($this->customer->id, $quotation->customer_id);
        /* ولقطةُ العميل تُكتب: ورقةٌ خرجت لا يغيّرها تبديلُ اسمٍ بعد سنة */
        $this->assertSame('وزارة الطاقة', $quotation->customer_name);
        $this->assertSame('OM1100999999', $quotation->customer_tax_number);

        $this->customer->update(['legal_name' => 'وزارة الطاقة والمعادن']);

        $this->assertSame('وزارة الطاقة', $quotation->fresh()->customer_name);
        $this->assertSame(1, $this->customer->quotations()->count());
    }

    public function test_a_customer_from_another_company_is_refused(): void
    {
        $other = Business::create(['name' => 'أخرى', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Customer::create(['business_id' => $other->id, 'name' => 'عميلهم']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('هذا العميل ليس من عملاء شركتك.');

        Quotations::create((int) $this->business->id, $theirs, ['title' => 'x'], [[
            'product_id' => null, 'description' => 'بند', 'quantity' => 1, 'unit_price' => 1,
        ]], $this->owner);
    }

    /** وعرضُ شركةٍ لا يُفتح من شركةٍ أخرى — ولو عُرف معرّفُه */
    public function test_a_quotation_is_not_read_across_companies(): void
    {
        $quotation = $this->create([[
            'product_id' => $this->service->id, 'quantity' => 1, 'unit_price' => 500,
        ]]);

        $other = Business::create(['name' => 'أخرى', 'type' => 'عام', 'status' => 'نشط']);
        JobTitle::create(['business_id' => $other->id, 'name' => 'مدير', 'role' => 'admin']);
        $intruder = User::create([
            'business_id' => $other->id, 'name' => 'غريب', 'email' => 'x@other.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($intruder)->get(route('admin.quotations.show', $quotation->id))->assertNotFound();
        $this->actingAs($intruder)->post(route('admin.quotations.send', $quotation->id))->assertNotFound();
        $this->actingAs($intruder)->get(route('admin.quotations.pdf', $quotation->id))->assertNotFound();

        /* وقائمتُه لا تحمل عرضَ غيره */
        $this->actingAs($intruder)->get(route('admin.quotations.index'))
            ->assertOk()
            /* و`etc()` كما في سائر حرّاس هذا المستودع — الفحصُ على ما يُسأل عنه */
            ->assertInertia(fn ($page) => $page->where('quotations', [])->etc());
    }
}
