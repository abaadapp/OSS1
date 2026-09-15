<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ما يدخل الفاتورة يدخل وعاء الضريبة معه.
 *
 * الإضافةُ «جزءٌ من ثمن البند لا سطرٌ منفصل» — هكذا يقول الحساب في موضعين:
 * المجموعُ الفرعيّ يضمّها، والفاتورةُ تعرضها في البند. وحدَه وعاءُ الضريبة
 * كان يقرأ سعر الصنف وحده.
 *
 * وهذا ليس خطأً في شاشة: هو ضريبةٌ لم تُجبَ من الزبون ولم تُقرّ للدولة، عن
 * كلّ إضافةٍ باعها المحلّ منذ رُبطت الإضافات بالبنود.
 */
class PosSecondPassAuditTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $cashier;

    private Product $product;

    private Addon $addon;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة',
            'price' => 10, 'cost' => 4, 'quantity' => 100, 'alert_qty' => 1, 'active' => true,
        ]);

        $this->addon = Addon::create([
            'business_id' => $this->business->id, 'name' => 'شوكولاتة',
            'price' => 5, 'icon' => '🍫', 'active' => true,
        ]);

        $this->set('vat_enabled', '1');
        $this->set('vat_rate', '5');
    }

    private function set(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => $key], ['value' => $value]);
    }
    /* ==================== وعاء الضريبة ==================== */

    public function test_an_addon_sold_on_a_line_is_taxed_like_the_line(): void
    {
        $this->sellWithAddon()->assertOk();
        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(15.0, round((float) $order->subtotal, 3), 'الإضافة داخل المجموع الفرعي');
        $this->assertSame(
            0.75,
            round((float) $order->tax, 3),
            'خمسةٌ بالمئة من خمسة عشر = ٧٥٠ بيسة — لا ٥٠٠: الإضافة خرجت من وعاء الضريبة',
        );
        $this->assertSame(15.75, round((float) $order->total, 3));
    }

    public function test_the_stored_total_still_equals_its_own_parts(): void
    {
        $this->sellWithAddon()->assertOk();
        $o = Order::latest('id')->firstOrFail();

        $this->assertSame(
            round((float) $o->subtotal - (float) $o->discount + (float) $o->tax + (float) $o->delivery_fee, 3),
            round((float) $o->total, 3),
        );
    }

    public function test_an_addon_follows_the_rate_of_the_product_it_rides_on(): void
    {
        // صنفٌ بنسبةٍ خاصّة: إضافتُه تتبعه لا تتبع نسبة المتجر
        $this->product->update(['tax' => 10]);

        $this->sellWithAddon()->assertOk();

        $this->assertSame(1.5, round((float) Order::latest('id')->firstOrFail()->tax, 3));
    }

    public function test_turning_the_tax_off_frees_the_addon_too(): void
    {
        $this->set('vat_enabled', '0');

        $this->sellWithAddon()->assertOk();
        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(0.0, round((float) $order->tax, 3));
        $this->assertSame(15.0, round((float) $order->total, 3));
    }

    public function test_an_inclusive_price_pulls_the_tax_out_of_the_addon_as_well(): void
    {
        /*
         * «مشمولة» تُستخرَج ولا تُضاف: خمسةَ عشر على الرفّ هي خمسةَ عشر عند
         * الصندوق، وضريبتُها منها — لا فوقها.
         */
        $this->set('tax_mode', 'inclusive');

        $this->sellWithAddon()->assertOk();
        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(0.714, round((float) $order->tax, 3), '١٥ × ٥ ÷ ١٠٥');
        $this->assertSame(15.0, round((float) $order->total, 3), 'المعروض هو المدفوع');
    }
    public function test_the_screen_and_the_server_weigh_the_same_line(): void
    {
        /*
         * الشاشة كانت الصادقة والخادم هو الذي انحرف: `usePosCart` تحسب
         * وعاءها بـ`lineTotal` — سعرٌ في كميّة زائدًا الإضافات — والخادم كان
         * يقرأ السعر وحده. فيقرأ الزبون على الشاشة ١٥٫٧٥٠ ويُطبع له ١٥٫٥٠٠.
         *
         * والمعادلتان في لغتين لا تُشغَّلان في عمليّةٍ واحدة، فتُقرأ الصيغتان
         * من مصدريهما: كلتاهما تضمّ الإضافة إلى الوعاء. وهذا إثباتُ شكلٍ لا
         * إثباتُ تساوٍ — والتساوي يُثبته الرقم في الاختبار الأوّل أعلاه.
         */
        $screen = file_get_contents(resource_path('js/hooks/usePosCart.ts'));
        $server = file_get_contents(app_path('Http/Controllers/Pos/PosController.php'));

        $this->assertStringContainsString(
            'const net = lineTotal(i);',
            $screen,
            'الشاشة لم تعد تحسب وعاءها من ثمن البند كاملًا',
        );

        $this->assertStringContainsString(
            "\$net = \$l['price'] * \$l['qty'] + (\$l['addons_total'] ?? 0);",
            $server,
            'الخادم يقرأ سعر الصنف وحده — والإضافة في المجموع وليست في الوعاء',
        );
    }

    /* ==================== العميل ==================== */

    public function test_a_phone_finds_its_owner_even_when_the_typed_name_differs(): void
    {
        $customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'أحمد', 'phone' => '90000001', 'points' => 0,
        ]);

        $this->sellWithAddon(['customer' => 'مجهول', 'customer_phone' => '90000001'])->assertOk();

        $this->assertSame($customer->id, (int) Order::latest('id')->firstOrFail()->customer_id);
    }

    public function test_a_neighbours_phone_never_reaches_across_the_wall(): void
    {
        $neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Customer::create([
            'business_id' => $neighbour->id, 'name' => 'جارٌ', 'phone' => '90000002', 'points' => 500,
        ]);

        $this->sellWithAddon(['customer' => 'جارٌ', 'customer_phone' => '90000002'])->assertOk();

        $this->assertNull(Order::latest('id')->firstOrFail()->customer_id);
    }
}
