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
    /* ------------------------------- الموعد ------------------------------- */
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
    /* --------------------- صفحة الطلب: قسمان لا كومة --------------------- */
}
