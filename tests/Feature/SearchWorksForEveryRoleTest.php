<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * زرُّ البحث كما يراه كلُّ من يفتحه: مدير المنصّة، وصاحب النشاط، والموظّف.
 *
 * الصندوق واحدٌ في الترويسة، ومساره ليس واحدًا: مدير المنصّة لا يملك
 * `business_id`، فبحثُه على `super-admin.search` — شركاتٌ ومستخدمون. وصاحبُ
 * النشاط وموظّفوه على `admin.search` — منتجاتٌ وطلباتٌ وعملاءُ وموردون.
 *
 * وحدودُ الموظّف حدودُ صلاحيته: من لا يملك «العملاء» لا يجدهم في الصندوق.
 * وبابٌ يعرض ما لا يُفتح أسوأ من بابٍ لا يُعرض — والأسوأ منهما أن يعرض
 * البحثُ اسمَ عميلٍ لمن مُنع من شاشة العملاء.
 */
class SearchWorksForEveryRoleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->shop->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@eventplusapp.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        /*
         * كاشيرٌ بدوره لا بصلاحياتٍ مكتوبةٍ باليد.
         *
         * `Permissions::MAP['cashier']` هو ما يملكه كاشيرُ الإنتاج فعلًا —
         * لوحةٌ ونقطةُ بيع، لا أكثر. ومُعطًى يمنحه `orders` يدويًّا يختبر
         * موظّفًا لا وجود له، ويمرّ بينما ينكسر الحقيقيّ.
         */
        $this->cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'cashier@eventplusapp.om',
            'password' => bcrypt('x'), 'role' => 'sales', 'status' => 'نشط',
        ]);

        /*
         * وموظّفٌ يدخل اللوحة بصلاحياتٍ ممنوحةٍ يدويًّا: نقطةُ البيع والمنتجات.
         *
         * وهو الحال الذي يقع فعلًا حين يُمنح كاشيرٌ شاشةَ المنتجات: يدخل
         * اللوحة فيرى ترويستها وصندوقَها — ولا يملك «الطلبات».
         */
        $this->onFloor = User::create([
            'business_id' => $this->shop->id, 'name' => 'بائع', 'email' => 'floor@eventplusapp.om',
            'password' => bcrypt('x'), 'role' => 'sales', 'status' => 'نشط',
            'permissions' => ['pos', 'products'],
        ]);

        $this->platform = User::create([
            'business_id' => null, 'name' => 'حسابٌ بلا شركة', 'email' => 'nobody@eventplusapp.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        Product::create([
            'business_id' => $this->shop->id, 'name' => 'وردة حمراء',
            'price' => 5, 'cost' => 2, 'quantity' => 9, 'alert_qty' => 1, 'active' => true,
        ]);
        Customer::create(['business_id' => $this->shop->id, 'name' => 'زبونة الورد', 'phone' => '99001122']);
        Supplier::create(['business_id' => $this->shop->id, 'name' => 'مشتل الورد']);
        }

    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private User $cashier;

    private User $onFloor;

    private User $platform;
    /** مجموعاتُ النتائج كما يردّها المسار لهذا المستخدم */
    private function titles(User $as, string $q, string $route): array
    {
        $res = $this->actingAs($as)->getJson(route($route, ['q' => $q]));
        $res->assertOk();

        return array_column($res->json('groups') ?? [], 'title');
    }

    private function labels(User $as, string $q, string $route): array
    {
        $res = $this->actingAs($as)->getJson(route($route, ['q' => $q]));
        $res->assertOk();

        $out = [];
        foreach ($res->json('groups') ?? [] as $g) {
            foreach ($g['items'] as $i) {
                $out[] = $i['label'];
            }
        }

        return $out;
    }

    /* --------------------------- صاحب النشاط --------------------------- */

    public function test_the_owner_finds_everything_in_his_shop(): void
    {
        $found = $this->labels($this->owner, 'ورد', 'admin.search');

        $this->assertContains('وردة حمراء', $found, 'المنتج لا يُوجد');
        $this->assertContains('زبونة الورد', $found, 'العميل لا يُوجد');
        $this->assertContains('مشتل الورد', $found, 'المورّد لا يُوجد');
    }

    public function test_the_owner_finds_a_customer_by_phone(): void
    {
        $this->assertContains('زبونة الورد', $this->labels($this->owner, '990011', 'admin.search'));
    }

    /* ----------------------------- الموظّف ----------------------------- */

    /**
     * الكاشير يبحث — لكن في حدود ما يملك.
     *
     * له «نقطة البيع» و«الطلبات» ولا شيء غيرهما، فاسمُ العميل واسمُ المورّد
     * لا يصلانه من الصندوق: لو وصلاه لكان الصندوق بابًا خلفيًّا إلى شاشةٍ
     * مُنع منها بالباب الأمامي.
     */
    public function test_an_employee_sees_only_what_his_permissions_open(): void
    {
        $found = $this->labels($this->onFloor, 'ورد', 'admin.search');

        $this->assertNotContains('زبونة الورد', $found, 'يرى عميلًا لا يملك شاشته');
        $this->assertNotContains('مشتل الورد', $found, 'يرى مورّدًا لا يملك شاشته');
        $this->assertContains('وردة حمراء', $found, 'مُنح المنتجات ولا يجدها');
    }

    private function sale(string $number): Order
    {
        return Order::create([
            'business_id' => $this->shop->id, 'number' => $number, 'status' => 'مكتمل',
            'payment_status' => 'مدفوع', 'payment_method' => 'نقدي',
            'subtotal' => 5, 'discount' => 0, 'tax' => 0, 'delivery_fee' => 0, 'total' => 5,
        ]);
    }

    /**
     * والطلبُ القديم لا يُبحث: خرجت «المبيعات» ولا صفحةَ له، فنتيجتُه رابطٌ ميّت.
     * والفاتورةُ برقمها تقود إلى «فواتير العملاء».
     */
    public function test_an_old_order_is_not_found_and_an_invoice_leads_to_its_page(): void
    {
        $this->sale('INV-999999');
        $customer = Customer::where('business_id', $this->shop->id)->firstOrFail();
        $invoice = CustomerInvoice::create([
            'business_id' => $this->shop->id, 'customer_id' => $customer->id, 'number' => 'CI-777777',
            'status' => CustomerInvoice::ISSUED, 'issued_at' => now()->toDateString(),
            'subtotal' => 5, 'tax_total' => 0, 'total' => 5,
        ]);

        $old = $this->actingAs($this->owner)->getJson(route('admin.search', ['q' => '999999']));
        $this->assertSame([], $old->json('groups'), 'رقمُ طلبٍ قديم يُعرض نتيجةً');
        $this->assertStringNotContainsString('/admin/orders', $old->getContent());

        $url = $this->actingAs($this->owner)->getJson(route('admin.search', ['q' => '777777']))
            ->json('groups.0.items.0.url');
        $this->assertSame(route('admin.customerInvoices.show', $invoice->id), $url);
    }

    /**
     * ولا يدخل بحثَ التجّار: ليس له متجرٌ يبحث فيه.
     *
     * والردّ تحويلٌ لا منع: الحارس يردّه إلى لوحته بدل أن يقول «ممنوع» —
     * فمن قصد بابًا ليس بابَه يجد نفسه عند بابه لا أمام جدار.
     */
    public function test_the_platform_admin_is_not_a_merchant(): void
    {
        $res = $this->actingAs($this->platform)->get(route('admin.search', ['q' => 'ورد']));

        $this->assertTrue(
            in_array($res->status(), [302, 403], true),
            'بحثُ التجّار مفتوحٌ لمن لا متجرَ له — الردّ: '.$res->status(),
        );

        if ($res->status() === 302) {
            $this->assertStringNotContainsString('/admin/', (string) $res->headers->get('Location'));
        }
    }
    /* ---------------------------- والحدود ---------------------------- */

    /** ولا يعبر البحث بين متجرين */
    public function test_the_box_stops_at_the_shop_wall(): void
    {
        $other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        Product::create([
            'business_id' => $other->id, 'name' => 'وردة الجار',
            'price' => 9, 'cost' => 3, 'quantity' => 9, 'alert_qty' => 1, 'active' => true,
        ]);

        $this->assertNotContains('وردة الجار', $this->labels($this->owner, 'ورد', 'admin.search'));
    }

    /** والزائر لا يبحث */
    public function test_a_guest_gets_no_search(): void
    {
        $this->getJson(route('admin.search', ['q' => 'ورد']))->assertUnauthorized();
    }

    /** وحرفٌ واحد لا يُتعب الخادم */
    public function test_one_letter_asks_the_server_for_nothing(): void
    {
        $res = $this->actingAs($this->owner)->getJson(route('admin.search', ['q' => 'و']));

        $res->assertOk();
        $this->assertSame([], $res->json('groups'));
    }
}
