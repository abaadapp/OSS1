<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderEdit;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «تعديل» فوق فاتورةٍ يعني الفاتورة.
 *
 * كان في شاشة الطلب زرٌّ واحد اسمه «تعديل»، ويفتح **ورقةَ التفاصيل**: اسمُ
 * المستلم، وموعدُ التسليم، ونصُّ البطاقة، والعنوان. ومن ضغطه باحثًا عن كميّةٍ
 * أدخلها خطأً — وهو أكثرُ من يضغط زرًّا بهذا الاسم فوق جدولِ أصناف — لم يجد
 * في النموذج حرفًا واحدًا يمسّ الرقم الذي فتح الصفحة من أجله. ولم يكن لمتن
 * الفاتورة بابٌ من هذه الشاشة أصلًا: التصحيح كلُّه خلف شاشة الكاشير، فصاحبُ
 * النشاط يقرأ فاتورته ولا يملك تصحيحها إلّا أن يقف على الصندوق.
 *
 * فصارا بابين باسمين: **تعديل الفاتورة** يمسّ ما بيع وبكم وبأيّ وسيلة دُفع،
 * و**ورقة التفاصيل** تمسّ التنفيذ وحده. وما يُحرَس هنا ثلاثة:
 *
 * ١ — أنّ البابَ الجديد يُدير شيئًا: يُصحَّح البند من شاشة المبيعات فيتحرّك
 *     الرفُّ والدفترُ ويبقى الأثر — بالكتابة نفسِها التي يُصحّح بها الكاشير.
 * ٢ — أنّ حُكمَه هو حكمُ الكاشير لا أوسع: `order.edit` ويومُ البيع، يُقاسان
 *     في الخادم لا في الشاشة.
 * ٣ — أنّ البابين لم يختلطا: تعديلُ الورقة لا يمسّ ريالًا، وتصحيحُ الفاتورة
 *     لا يمسّ اسمَ المستلم.
 */
class TheInvoiceAndItsSheetAreTwoDoorsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد', 'price' => 10, 'cost' => 4,
            'quantity' => 50, 'alert_qty' => 2,
        ]);

        $this->actingAs($this->owner);
        session(['current_branch' => $this->branch->id]);
    }
    /** موظّفٌ بدورٍ بعينه وصلاحياتٍ مكتوبةٍ بيدها */
    private function staff(string $role, ?array $permissions = null): User
    {
        return User::create([
            'business_id' => $this->business->id, 'name' => 'موظف', 'email' => $role.'@eventplus.om',
            'password' => bcrypt('password12345'), 'role' => $role, 'status' => 'نشط',
            'permissions' => $permissions,
        ]);
    }
    /** ولا يراه في شاشته أصلًا: بابٌ لا يُعرض خيرٌ من بابٍ يُقال لصاحبه «ليست لك» */
    public function test_the_door_is_not_drawn_for_who_may_not_open_it(): void
    {
        $order = $this->sell();

        $this->actingAs($this->staff('cashier', ['orders']))
            ->get(route('admin.orders.show', $order->number))
            ->assertInertia(fn ($page) => $page
                ->where('invoiceEdit.can', false)
                ->where('invoiceEdit.reason', null));
    }

    /** ومن يملكها ويومُ فاتورته قائم يراه مفتوحًا */
    public function test_the_door_is_open_on_the_day_of_the_sale(): void
    {
        $order = $this->sell();

        $this->get(route('admin.orders.show', $order->number))
            ->assertInertia(fn ($page) => $page->where('invoiceEdit.can', true));
    }
    /* ==================== والبابان لم يختلطا ==================== */

    /**
     * ورقةُ التفاصيل لا تمسّ ريالًا.
     *
     * وهذا ما كان الزرُّ الواحد يَعِد به ولا يقوله: من فتحه باحثًا عن المبلغ
     * لم يكن ليجده مهما ملأ من حقول.
     */
    public function test_the_detail_sheet_moves_no_money(): void
    {
        $order = $this->sell(3);
        $before = (float) $order->total;

        $this->put(route('admin.orders.details.update', $order->number), [
            'fulfillment_type' => 'delivery',
            'recipient_name' => 'سارة',
            'recipient_phone' => '99887766',
            'delivery_address' => 'الخوض',
            // وطلبٌ يُجهَّز له موعد — وإلّا سقط من لوحة التجهيز صامتًا
            'scheduled_for' => now()->addDay()->format('Y-m-d\TH:i'),
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('سارة', $order->recipient_name);
        $this->assertSame($before, (float) $order->total);
    }
    /* ==================== وما تحتاجه الشاشة ليُنادى الباب ==================== */

    /**
     * الحمولةُ تحمل معرّفَ كلّ بند ووسائلَ الدفع المأذونة.
     *
     * وبلا المعرّف لا يُبنى المسار، فيبقى القلمُ مرسومًا لا يُدير شيئًا.
     */
    public function test_the_payload_carries_what_the_pencil_needs(): void
    {
        $order = $this->sell();

        $this->get(route('admin.orders.show', $order->number))
            ->assertInertia(fn ($page) => $page
                ->has('order.items.0.id')
                ->has('order.payment_methods'));
    }

    /**
     * وزرّان في الترويسة لا زرّ.
     *
     * وهذا حارسٌ يقرأ مصدرًا — ويُقال إنّه ضعيف: وجودُ زرٍّ بعينه في ترويسة
     * صفحةٍ تركيبُ صفحةٍ لا سلوكُ مكوّن، ولا يُثبَت في jsdom إلّا بتركيب
     * `AdminLayout` كلِّه. والوجهتان تحته بابان قائمان لا اسمان مخترَعان —
     * وذلك ما تحرسه بقيّةُ هذا الملفّ.
     */
    public function test_the_header_offers_two_doors_not_one(): void
    {
        $screen = file_get_contents(base_path('resources/js/Pages/Admin/Orders/Show.tsx'));

        $this->assertStringContainsString("{t('تعديل الفاتورة')}", $screen);
        $this->assertStringContainsString("{t('ورقة التفاصيل')}", $screen);
        // والقديمُ المبهم ذهب: «تعديل» وحدها فوق فاتورةٍ تَعِد بما لا تفتح
        $this->assertStringNotContainsString("{t('تعديل')}", $screen);
    }
}
