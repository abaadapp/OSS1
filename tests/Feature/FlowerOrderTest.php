<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\BackupService;
use App\Support\FlowerOrder;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * طلب الورد: مشترٍ، ومستلِمٌ آخر، وموعدٌ في المستقبل.
 *
 * محلّ الورد يبيع شيئين في بيعةٍ واحدة، والفاتورة كانت تعرف الأوّل وحده —
 * فبقي الباقي يُكتب في «ملاحظات» نصًّا حرًّا لا يُرشَّح ولا يُرتَّب عليه.
 *
 * وأهمّ ما يُحرَس هنا شيئان: أنّ بيعة المنضدة لم تُثقَل بحقولٍ لا تخصّها،
 * وأنّ التوصيل لا يمرّ بلا مستلِمٍ وعنوان.
 */
class FlowerOrderTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد', 'price' => 25, 'cost' => 10,
            'quantity' => 100, 'alert_qty' => 2,
        ]);

        $this->actingAs($this->owner);
        session(['current_branch' => Branch::where('business_id', $this->business->id)->value('id')]);
    }
    private function lastOrder(): Order
    {
        return Order::where('business_id', $this->business->id)->latest('id')->firstOrFail();
    }

    /* ------------------------- بيعة المنضدة تبقى سريعة ------------------------- */

    /**
     * بلا حقلٍ واحد من حقول الورد — وهي أكثر بيعات اليوم.
     *
     * لو صار أيٌّ منها إلزاميًّا لَملأه الكاشير بأيّ شيء ليتخلّص منه، فتصير
     * البيانات أسوأ من غيابها.
     */
    public function test_a_walk_in_sale_needs_none_of_the_new_fields(): void
    {
        $this->sell()->assertOk()->assertJson(['ok' => true]);

        $order = $this->lastOrder();
        $this->assertNull($order->scheduled_for);
        $this->assertNull($order->recipient_name);
        // بيعةٌ دُفعت وأُخذت في اللحظة نفسها: مكتملة، ولا شيء فيها يُجهَّز
        $this->assertSame(OrderStatus::COMPLETED, $order->status);
    }

    /* ------------------------------- الاستلام ------------------------------- */

    public function test_a_pickup_order_keeps_its_details(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::PICKUP,
            'recipient_name' => 'سارة',
            'recipient_phone' => '91234567',
            'scheduled_for' => now()->addDays(5)->format('Y-m-d H:i:s'),
            'occasion_type' => 'birthday',
        ])->assertOk();

        $order = $this->lastOrder();
        $this->assertSame(FlowerOrder::PICKUP, $order->fulfillment_type);
        $this->assertSame('سارة', $order->recipient_name);
        $this->assertSame('birthday', $order->occasion_type);
        $this->assertNotNull($order->scheduled_for);
    }

    /** والاستلام لا يُسأل عن عنوان */
    public function test_a_pickup_order_needs_no_address(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::PICKUP,
            'scheduled_for' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertOk();
    }

    /* ------------------------------- التوصيل ------------------------------- */

    public function test_a_delivery_order_is_refused_without_a_recipient(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::DELIVERY,
            'delivery_address' => 'الخوير، شارع ١٨',
        ])->assertStatus(422)->assertJsonValidationErrors(['recipient_name', 'recipient_phone']);

        $this->assertSame(0, Order::where('business_id', $this->business->id)->count());
    }

    public function test_a_delivery_order_is_refused_without_an_address(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::DELIVERY,
            'recipient_name' => 'سارة',
            'recipient_phone' => '91234567',
        ])->assertStatus(422)->assertJsonValidationErrors(['delivery_address']);
    }

    public function test_a_complete_delivery_order_is_accepted(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::DELIVERY,
            'recipient_name' => 'سارة',
            'recipient_phone' => '+968 9123 4567',
            'delivery_address' => 'الخوير، شارع ١٨',
            'delivery_notes' => 'الباب الأزرق',
            'delivery_fee' => 3,
            'scheduled_for' => now()->addDays(3)->format('Y-m-d H:i:s'),
        ])->assertOk();

        $order = $this->lastOrder();
        /*
         * ورقمُ الشارع يُحفظ إنجليزيًّا وحروفُه كما كُتبت.
         *
         * الأرقام تُوحَّد عند الباب لكلّ حقل (انظر `NormalizeNumbers`)، فيبقى
         * العنوان مقروءًا لسائق التوصيل ومطابقًا لما يُطبع على الفاتورة —
         * ويُبحث عنه برقمه أيًّا كانت لوحةُ من يبحث.
         */
        $this->assertSame('الخوير، شارع 18', $order->delivery_address);
        $this->assertSame('الباب الأزرق', $order->delivery_notes);
        $this->assertEquals(3, (float) $order->delivery_fee);
    }

    /** ورسوم توصيلٍ صفر ليست غيابًا: استلامٌ مجّانيّ يُسجَّل صفرًا */
    public function test_a_zero_delivery_fee_is_accepted(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::DELIVERY,
            'recipient_name' => 'سارة', 'recipient_phone' => '91234567',
            'delivery_address' => 'صحار', 'delivery_fee' => 0,
            'scheduled_for' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertOk();

        $this->assertEquals(0, (float) $this->lastOrder()->delivery_fee);
    }

    /* -------------------------- المشتري غير المستلِم -------------------------- */

    public function test_the_buyer_and_the_recipient_are_two_people(): void
    {
        $this->sell([
            'customer' => 'محمد',
            'fulfillment_type' => FlowerOrder::DELIVERY,
            'recipient_name' => 'سارة', 'recipient_phone' => '91234567',
            'delivery_address' => 'مسقط',
            'scheduled_for' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertOk();

        $order = $this->lastOrder();
        $this->assertSame('محمد', $order->customer_name);
        $this->assertSame('سارة', $order->recipient_name);
    }

    /** ولا يُنشأ للمستلِم عميل: من يُهدى إليه مرّةً ليس زبونًا */
    public function test_no_customer_row_is_created_for_the_recipient(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::DELIVERY,
            'recipient_name' => 'سارة المستلِمة', 'recipient_phone' => '91234567',
            'delivery_address' => 'مسقط',
            'scheduled_for' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertOk();

        $this->assertFalse(
            Customer::where('name', 'سارة المستلِمة')->exists(),
            'أُنشئ عميلٌ للمستلِم فتضخّمت قائمة العملاء بمن لم يشترِ'
        );
    }

    /* ------------------------------ بطاقة الإهداء ------------------------------ */

    public function test_the_card_and_its_sender_are_kept(): void
    {
        $this->sell([
            'card_message' => 'كل عام وأنتِ بخير',
            'sender_name' => 'محمد',
        ])->assertOk();

        $order = $this->lastOrder();
        $this->assertSame('كل عام وأنتِ بخير', $order->card_message);
        $this->assertSame('محمد', $order->sender_name);
        $this->assertFalse($order->hide_sender);
    }

    /**
     * وإخفاء المُهدي يُطاع في مخرَج المستلِم لا في الشاشة وحدها.
     *
     * من أخفى اسمه ثم قرأه المستلِمُ على البطاقة لم يُخدَع في ميزة — بل في
     * سرٍّ ائتمن النظامَ عليه.
     */
    public function test_a_hidden_sender_is_absent_from_what_the_recipient_reads(): void
    {
        $this->sell([
            'card_message' => 'من محبّك',
            'sender_name' => 'محمد',
            'hide_sender' => true,
        ])->assertOk();

        $order = $this->lastOrder();
        $this->assertTrue($order->hide_sender);
        $this->assertSame('محمد', $order->sender_name, 'الاسم يبقى محفوظًا للموظّف');

        $card = FlowerOrder::cardForRecipient($order);
        $this->assertNull($card['sender'], 'اسم المُهدي وصل إلى المستلِم رغم طلب إخفائه');
        $this->assertSame('من محبّك', $card['message']);
    }

    /* ------------------------------- الموعد ------------------------------- */

    /** طلبٌ لموعدٍ قادم يدخل مسار التجهيز لا يُغلَق فور دفعه */
    public function test_a_future_order_starts_as_pending_not_completed(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::PICKUP,
            'scheduled_for' => now()->addDays(5)->format('Y-m-d H:i:s'),
        ])->assertOk();

        $this->assertSame(OrderStatus::PENDING, $this->lastOrder()->status);
    }

    /** و`ordered_at` غير `scheduled_for`: أحدهما التسجيل والآخر التنفيذ */
    public function test_the_order_time_and_the_delivery_time_are_two_columns(): void
    {
        $when = now()->addDays(5)->startOfHour();
        $this->sell([
            'fulfillment_type' => FlowerOrder::PICKUP,
            'scheduled_for' => $when->format('Y-m-d H:i:s'),
        ])->assertOk();

        $order = $this->lastOrder();
        $this->assertTrue($order->ordered_at->isToday());
        $this->assertSame($when->format('Y-m-d H:i'), $order->scheduled_for->format('Y-m-d H:i'));
    }

    /* ------------------------------- التحقّق ------------------------------- */

    public function test_an_unknown_occasion_is_refused(): void
    {
        $this->sell(['occasion_type' => 'لا-مناسبة'])->assertStatus(422);
    }
    /** وتظهر في خيارات الشاشة بعد إضافتها */
    public function test_an_added_occasion_appears_in_the_options(): void
    {
        $before = collect(FlowerOrder::occasionOptions($this->business->id))->pluck('value');
        $this->assertNotContains('افتتاح فرع', $before);

        FlowerOrder::addOccasion('افتتاح فرع', $this->business->id);

        $after = collect(FlowerOrder::occasionOptions($this->business->id));
        $this->assertContains('افتتاح فرع', $after->pluck('value'));
        // التسمية هي القيمة: ما يكتبه التاجر لا مفتاح له يُترجَم
        $this->assertSame('افتتاح فرع', $after->firstWhere('value', 'افتتاح فرع')['label']);
    }

    /** المكرَّرة تُختار ولا تُضاف مرّةً ثانية */
    public function test_adding_the_same_occasion_twice_keeps_one(): void
    {
        FlowerOrder::addOccasion('عقيقة', $this->business->id);
        FlowerOrder::addOccasion('  عقيقة ', $this->business->id);

        $this->assertSame(['عقيقة'], FlowerOrder::customOccasions($this->business->id));
    }

    /** والمطابقة لثابتٍ تُردّ إلى مفتاحه — لا «زواج» مرّتين في القائمة */
    public function test_adding_a_builtin_occasion_returns_its_key(): void
    {
        $added = FlowerOrder::addOccasion('زواج', $this->business->id);

        $this->assertSame('wedding', $added['value']);
        $this->assertSame([], FlowerOrder::customOccasions($this->business->id));
    }
    /**
     * حقول التفاصيل مصدرها واحد.
     *
     * `attributes()` تمرّ على `FIELDS` و`rules()` تكتب مفاتيحها — فحقلٌ يُضاف
     * في إحداهما دون الأخرى إمّا يُتحقَّق منه ولا يُحفظ، وإمّا يُحفظ بلا
     * تحقّق. والثاني هو الخطر.
     */
    public function test_the_detail_fields_and_their_rules_stay_one_list(): void
    {
        $this->assertSame(FlowerOrder::FIELDS, array_keys(FlowerOrder::rules()));
    }

    public function test_an_invalid_schedule_is_refused(): void
    {
        $this->sell(['scheduled_for' => 'ليس تاريخًا'])->assertStatus(422);
    }

    /**
     * والهاتف الدوليّ يمرّ.
     *
     * الزبون يُهدي إلى دبي وإلى الرياض، ونمطٌ عمانيّ ضيّق يجعل الكاشير يكتب
     * الرقم في «ملاحظات» — فيخرج من كل ترشيحٍ وكل تقرير.
     */
    public function test_international_numbers_are_accepted(): void
    {
        foreach (['91234567', '+968 9123 4567', '+971-50-123-4567', '(966) 512345678'] as $phone) {
            $this->sell([
                'fulfillment_type' => FlowerOrder::DELIVERY,
                'recipient_name' => 'سارة', 'recipient_phone' => $phone,
                'delivery_address' => 'عنوان',
                'scheduled_for' => now()->addDay()->format('Y-m-d H:i:s'),
            ])->assertOk("رُفض رقمٌ صحيح: {$phone}");
        }
    }

    public function test_a_nonsense_phone_is_refused(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::DELIVERY,
            'recipient_name' => 'سارة', 'recipient_phone' => 'اتّصل بي',
            'delivery_address' => 'عنوان',
        ])->assertStatus(422)->assertJsonValidationErrors('recipient_phone');
    }

    public function test_an_overlong_card_message_is_refused(): void
    {
        $this->sell(['card_message' => str_repeat('ا', FlowerOrder::CARD_MAX + 1)])->assertStatus(422);
    }
    /** والمخزون ينقص كما كان */
    public function test_stock_still_moves(): void
    {
        $this->sell(['recipient_name' => 'سارة'], qty: 3)->assertOk();

        $this->assertEquals(97, (int) $this->product->fresh()->quantity);
    }

    /**
     * وتفاصيل الطلب تنجو من النسخة الاحتياطية واستعادتها.
     *
     * النسخة تُصدَّر بـ`toArray()` والاستعادة تُنشئ بـ`create()`، فالأعمدة
     * الجديدة تمرّ بلا تعديلٍ في المصدّر. لكنّ «تمرّ تلقائيًّا» فرضٌ لا يُوثق
     * به في شيءٍ يُستعاد يوم يُفقد كلّ شيء: يوم تُقرأ النسخة لا يبقى ما
     * يُقارَن به.
     */
    public function test_the_details_survive_a_backup_and_restore(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::DELIVERY,
            'recipient_name' => 'سارة', 'recipient_phone' => '91234567',
            'delivery_address' => 'الخوير', 'card_message' => 'كل عام وأنتِ بخير',
            'sender_name' => 'محمد', 'hide_sender' => true,
            'occasion_type' => 'birthday',
            'scheduled_for' => '2026-09-03 18:30:00',
        ])->assertOk();

        $dump = BackupService::payload($this->business->id);
        $row = collect($dump['orders'])->firstOrFail();

        foreach ([
            'recipient_name' => 'سارة',
            'delivery_address' => 'الخوير',
            'card_message' => 'كل عام وأنتِ بخير',
            'occasion_type' => 'birthday',
        ] as $field => $value) {
            $this->assertSame($value, $row[$field] ?? null,
                "«{$field}» لم يدخل النسخة الاحتياطية");
        }
        $this->assertTrue((bool) $row['hide_sender'], 'إخفاء المُهدي لم يدخل النسخة');
        $this->assertStringContainsString('2026-09-03', (string) $row['scheduled_for']);
    }

    /** والرفع المكرَّر لا يُنشئ طلبًا ثانيًا */
    public function test_the_same_upload_twice_makes_one_order(): void
    {
        $payload = ['client_uuid' => 'abc-123', 'recipient_name' => 'سارة'];
        $this->sell($payload)->assertOk();
        $this->sell($payload)->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, Order::where('business_id', $this->business->id)->count());
    }
    /** و«عميل نقدي» ليس اسمًا — هو ما يكتبه النظام حين لا يُكتب شيء */
    public function test_the_walk_in_placeholder_is_not_accepted_as_a_name(): void
    {
        $this->sell([
            'customer' => FlowerOrder::WALK_IN,
            'fulfillment_type' => FlowerOrder::PICKUP,
            'scheduled_for' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertStatus(422)->assertJsonValidationErrors('customer');
    }

    /** واللوحة تعرض «توصيل» أو «استلام»، فلا يُقبل طلبٌ لا يقول أيّهما */
    public function test_a_scheduled_order_is_refused_without_a_fulfillment_type(): void
    {
        $this->sell([
            'scheduled_for' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertStatus(422)->assertJsonValidationErrors('fulfillment_type');
    }
    /**
     * وطلبٌ قديم سابقٌ للقاعدة يبقى قابلًا للتصحيح.
     *
     * شاشة تعديل التفاصيل لا تعرض اسم العميل أصلًا، ففرضُ القاعدة فيها
     * قفلٌ بلا مفتاح: صاحبُ المحلّ يريد تصحيح عنوانٍ فيُمنع بسبب حقلٍ لا
     * يراه ولا يستطيع تغييره من هناك.
     */
    public function test_an_older_order_without_a_customer_can_still_be_edited(): void
    {
        $this->sell()->assertOk();
        $order = $this->lastOrder();
        $order->update([
            'scheduled_for' => now()->addDay(),
            'customer_name' => FlowerOrder::WALK_IN,
            'fulfillment_type' => null,
        ]);

        $this->actingAs($this->owner)
            ->put(route('admin.orders.details.update', $order->number), [
                'internal_notes' => 'يُغلَّف بورقٍ أسود',
            ])->assertSessionHasNoErrors();

        $this->assertSame('يُغلَّف بورقٍ أسود', $order->fresh()->internal_notes);
    }

    /* --------------------- صفحة الطلب: قسمان لا كومة --------------------- */

    /**
     * الصفحة تحمل القسمين معًا: مالَ الطلب وورقةَ تنفيذه.
     *
     * صارت الصفحة تبويبين — «بيانات الطلب» و«ورقة التفاصيل» — وكلاهما يُرسم
     * من الحمولة نفسها بلا نداءٍ ثانٍ. فلو سقط حقلٌ من الحمولة لَظهر التبويب
     * فارغًا بلا خطأ يُنبّه.
     */
    public function test_the_order_page_carries_both_the_money_and_the_sheet(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::DELIVERY,
            'scheduled_for' => now()->addDay()->format('Y-m-d H:i:s'),
            'recipient_name' => 'ليلى',
            'recipient_phone' => '+968 90000000',
            'delivery_address' => 'الخوير، شارع 33',
            'card_message' => 'كل عام وأنت بخير',
        ])->assertOk();

        $order = $this->lastOrder();

        $this->actingAs($this->owner)->get(route('admin.orders.show', $order->number))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Orders/Show')
                // قسم البيانات
                ->where('order.total', (float) $order->total)
                ->where('order.status', $order->status)
                ->has('order.items', 1)
                // قسم الورقة
                ->where('order.recipient_name', 'ليلى')
                ->where('order.delivery_address', 'الخوير، شارع 33')
                ->where('order.card_message', 'كل عام وأنت بخير')
                // وما تُبنى منه قوائم التعديل في المكان نفسه
                ->has('order.fulfillments')
                ->has('order.occasions'));
    }

    /**
     * وتعديل الورقة لا يمسّ ريالًا ولا يُقدّم الطلب في مساره.
     *
     * التعديل صار في الصفحة نفسها، فقُربُه من المبالغ يجعل الخلط ممكنًا:
     * حقلٌ زائد في الطلب، أو إعادةُ حسبةٍ «تصحيحًا»، فتقول الفاتورة غير ما
     * يقوله الدفتر. والمقياس هنا: الأرقام كما هي، والحالة كما هي، بعد تعديلٍ
     * يمسّ كل حقول الورقة.
     */
    public function test_editing_the_sheet_touches_neither_money_nor_status(): void
    {
        $this->sell([
            'fulfillment_type' => FlowerOrder::DELIVERY,
            'scheduled_for' => now()->addDay()->format('Y-m-d H:i:s'),
            'recipient_name' => 'ليلى',
            'recipient_phone' => '+968 90000000',
            'delivery_address' => 'الخوير',
        ])->assertOk();

        $order = $this->lastOrder();
        $before = $order->only([
            'subtotal', 'discount', 'tax', 'delivery_fee', 'total',
            'status', 'payment_method', 'payment_status',
        ]);

        $this->actingAs($this->owner)
            ->put(route('admin.orders.details.update', $order->number), [
                'fulfillment_type' => FlowerOrder::DELIVERY,
                'scheduled_for' => now()->addDays(2)->format('Y-m-d\TH:i'),
                'recipient_name' => 'ليلى الهنائي',
                'recipient_phone' => '+968 91111111',
                'delivery_address' => 'السيب، شارع 5',
                'delivery_notes' => 'اتّصل قبل الوصول',
                'card_message' => 'تهانينا',
                'sender_name' => 'خالد',
                'hide_sender' => true,
                'internal_notes' => 'يُغلَّف بورقٍ أسود',
            ])->assertSessionHasNoErrors();

        $order->refresh();

        $this->assertSame('ليلى الهنائي', $order->recipient_name);
        $this->assertSame('السيب، شارع 5', $order->delivery_address);
        $this->assertSame('يُغلَّف بورقٍ أسود', $order->internal_notes);

        foreach ($before as $field => $value) {
            $this->assertSame(
                (string) $value,
                (string) $order->$field,
                "تعديل الورقة غيّر «{$field}» — وهو ليس من شأنها"
            );
        }
    }
}
