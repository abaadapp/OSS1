<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\OrderCorrection;
use App\Support\OrderStatus;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * الفاتورة الصادرة تُقفل بانتهاء يومها — ولا يفتحها إلا من مُنح ذلك.
 *
 * كان `OrderCorrection` يعدّل فاتورةً مكتملة **بلا حدٍّ زمنيّ ولا بوّابة
 * صلاحية**، ويُفتح من نقطة البيع لأيّ واقفٍ عليها. فالفاتورة التي في يد
 * الزبون والإقرارُ الضريبيّ الذي قُدّم عن شهرٍ أُغلق يُعاد كتابتهما بعد
 * ثلاثة أسابيع، بلا مستندٍ ثالثٍ يقول إنّ شيئًا تغيّر.
 *
 * والفرقُ بين حالتين كان ضائعًا: «الكاشير كتب ٣ بدل ٢ قبل ثلاثين ثانية»
 * و«الزبون أعاد البضاعة بعد ثلاثة أسابيع». الأولى تصحيحٌ، والثانية إرجاعٌ
 * له مستندُه — ولا يُعالجان بالباب نفسه.
 *
 * فحدَّان: يومُ البيع، وصلاحيةٌ باسمها. وما بعد اليوم يبقى **الإلغاء
 * الكامل** — وهو يعكس القيد ولا يعيد كتابة المستند.
 */
class AnIssuedInvoiceClosesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private Product $product;

    private User $cashier;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'ورود مسقط', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'نورة', 'email' => 'n@eventplusapp.om',
            'password' => bcrypt('secret12345'), 'role' => 'cashier', 'status' => 'نشط',
        ]);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplusapp.om',
            'password' => bcrypt('secret12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $cat = Category::create(['business_id' => $this->business->id, 'name' => 'عام']);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'category_id' => $cat->id,
            'name' => 'قميص', 'sku' => 'SH-1', 'price' => 10, 'cost' => 6,
            'quantity' => 20, 'alert_qty' => 2, 'active' => true, 'tax' => 5,
        ]);
        BranchStock::ensureAllocated($this->business->id, $this->product->id, 20);
    }

    /** فاتورةٌ بيعت في وقتٍ بعينه */
    private function sale(?string $when = null): Order
    {
        $at = $when ? now()->parse($when) : now();

        $order = Order::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'number' => 'INV-'.uniqid(), 'customer_name' => 'عميل نقدي',
            'employee_name' => 'نورة', 'user_id' => $this->cashier->id,
            'subtotal' => 30, 'discount' => 0, 'tax' => 1.5, 'delivery_fee' => 0,
            'total' => 31.5, 'payment_method' => 'نقدي',
            'status' => 'مكتمل', 'payment_status' => 'مدفوع', 'is_held' => false,
            'ordered_at' => $at,
        ]);
        $order->items()->create([
            'product_id' => $this->product->id, 'name' => 'قميص',
            'price' => 10, 'cost' => 6, 'quantity' => 3, 'total' => 30,
        ]);
        $order->items()->create([
            'product_id' => $this->product->id, 'name' => 'وردة',
            'price' => 5, 'cost' => 2, 'quantity' => 1, 'total' => 5,
        ]);

        $this->product->decrement('quantity', 3);
        BranchStock::adjust($this->business->id, $this->branch->id, $this->product->id, -3);

        return $order->fresh('items');
    }
    /* ----------------------------- حدُّ اليوم ----------------------------- */

    public function test_an_invoice_from_today_is_still_correctable(): void
    {
        $order = $this->sale();

        $this->actingAs($this->owner)->correct($order)->assertSessionHasNoErrors();

        $this->assertSame(1, (int) $order->fresh('items')->items->first()->quantity);
    }

    public function test_an_invoice_from_yesterday_is_closed(): void
    {
        $order = $this->sale(now()->subDay()->setTime(16, 0)->toDateTimeString());

        $this->actingAs($this->owner)->correct($order)->assertSessionHasErrors('quantity');

        $this->assertSame(3, (int) $order->fresh('items')->items->first()->quantity, 'فاتورةُ أمسِ أُعيدت كتابتها');
    }

    public function test_the_boundary_is_the_day_not_a_span_of_hours(): void
    {
        /*
         * فاتورةٌ بيعت ١١:٥٩ مساءً تُقفل بعد دقيقة — وأخرى بيعت الفجرَ تبقى
         * مفتوحةً إلى منتصف الليل. الحدُّ يومُ عملٍ لا عدّادُ ساعات.
         */
        $order = $this->sale(now()->startOfDay()->addMinutes(5)->toDateTimeString());

        $this->actingAs($this->owner)->correct($order)->assertSessionHasNoErrors();
    }

    public function test_the_addon_door_is_closed_too(): void
    {
        // بابٌ ثالثٌ يبقى مفتوحًا يُبطل الحدَّ كلَّه
        $order = $this->sale(now()->subDays(3)->toDateTimeString());

        $this->expectException(RuntimeException::class);

        OrderCorrection::setPaymentMethod($order, 'بطاقة', 'تصحيح متأخّر');
    }
    public function test_cancelling_an_old_invoice_is_still_open(): void
    {
        /*
         * وإلّا تُرك التاجر بلا أيّ وسيلة إصلاح. والإلغاء لا يعيد كتابة
         * المستند: يعكس قيدَه ويترك الاثنين مقروءين.
         */
        $order = $this->sale(now()->subMonth()->toDateTimeString());

        OrderCorrection::cancel($order, 'إرجاعٌ كامل');

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    private function correct(Order $order, int $qty = 1)
    {
        return $this->put(route('admin.orders.items.update', [$order->number, $order->items->first()->id]), [
            'quantity' => $qty, 'reason' => 'الزبون غيّر رأيه في قطعة',
        ]);
    }
}
