<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحةُ الطلب — البياناتُ والورقةُ جنبًا إلى جنب، وأزرارُ الورقة تعمل.
 *
 * وكان تبويبان: من يراجع فاتورةً أمام زبونٍ يبدّل ذهابًا وإيابًا بين ما
 * يقوله النظام وما يقوله الورق.
 *
 * والمعروضُ هو الملفُّ نفسُه لا نسخةٌ منه مرسومةٌ في الشاشة — ونسخةٌ ثانيةٌ
 * تفترق عن أصلها يومًا، فيرى التاجرُ في اللوحة غيرَ ما يقرؤه الزبون في يده.
 * فهذه الحالاتُ تحرس أنّ الملفَّ يُفتح، وأنّه يُعرض داخل الصفحة لا يُنزَّل.
 */
class TheOrderPageShowsThePaperBesideItTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'phone' => '91234567',
        ]);
    }

    private function order(array $extra = []): Order
    {
        return Order::create($extra + [
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'customer_id' => $this->customer->id,
            'number' => 'INV-000900',
            'status' => OrderStatus::PENDING,
            'payment_method' => 'نقدي',
            'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25,
            'ordered_at' => now(),
        ]);
    }

    /* ----------------------------- الورقة ----------------------------- */

    /**
     * الملفُّ يُعرض داخل الصفحة لا يُنزَّل.
     *
     * والفرقُ رأسٌ واحد: `attachment` يجعل المتصفّح يحفظه، والإطارُ المدمج
     * يبقى أبيضَ فارغًا. فالمعاينةُ كلُّها معلّقةٌ على `inline`.
     */
    public function test_the_invoice_file_is_served_inline_so_the_frame_can_show_it(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->owner)->get(route('admin.orders.pdf', $order->number));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
    }

    /** وصفحةُ الطلب تُفتح ومعها ما يحتاجه الزرّان في رأس الورقة */
    public function test_the_order_page_opens(): void
    {
        $order = $this->order();

        $this->actingAs($this->owner)->get(route('admin.orders.show', $order->number))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Orders/Show')
                ->where('order.id', $order->number)
                ->has('taxInvoice'));
    }

    /* ---------------------------- «إرسال» ---------------------------- */
}
