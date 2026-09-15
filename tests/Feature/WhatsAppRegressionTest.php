<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Support\OrderStatus;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppQuota;
use App\Support\WhatsAppTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ما كان يعمل قبل واتساب يعمل بعده.
 *
 * وهذا أهمّ ملفّ في المجموعة: ميزةُ إشعاراتٍ تُسقط بيعةً واحدة عند الصندوق
 * تُطفَأ في اليوم الأوّل — والثمن حينها ليس الميزة وحدها بل الثقة بالنظام.
 */
class WhatsAppRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $cashier;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // واتساب مفعَّلٌ بالكامل — الاختبار أن يعمل البيع رغم ذلك، لا بغيابه
        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['business_id' => null, 'key' => WhatsAppQuota::DEFAULT_KEY], ['value' => '100']);

        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'EVENTPLUS-PN',
            'access_token' => 'platform-token-value-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
        ]);
        WhatsAppTemplates::seedPlatformDefaults('ar');

        $this->business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        $branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد', 'price' => 25, 'cost' => 10,
            'quantity' => 50, 'alert_qty' => 2,
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'زبون', 'phone' => '91234567',
        ]);

        foreach (WhatsAppEvent::SETTING_KEYS as $key) {
            Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => $key], ['value' => '1']);
        }

        $this->actingAs($this->cashier);
        session(['current_branch' => $branch->id]);
    }
    /* ------------------------------ البيع سليم ------------------------------ */

    public function test_a_sale_still_completes_with_whatsapp_on(): void
    {
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200)]);

        $this->sell()->assertOk()->assertJson(['ok' => true]);

        $order = Order::where('business_id', $this->business->id)->latest('id')->firstOrFail();
        $this->assertSame(OrderStatus::COMPLETED, $order->status);
        $this->assertEqualsWithDelta(50.0, (float) $order->subtotal, 0.0005);
        $this->assertSame(48, (int) $this->product->fresh()->quantity, 'المخزون خُصم كما كان');
    }

    /**
     * وميتا ساقطة لا تُسقط البيعة.
     *
     * وهذا الفحص لا يُغني عنه غيره: الطابور والمعاملة والاستثناءات كلّها
     * تُقاس هنا في سطرٍ واحد — أيبيع الكاشير حين ينقطع مزوّد الرسائل؟
     */
    public function test_a_dead_provider_does_not_break_the_sale(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('meta is down'));

        $this->sell()->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(1, Order::where('is_held', false)->count());
        $this->assertSame(48, (int) $this->product->fresh()->quantity);
    }

    /** ووصلةٌ مفقودة كذلك */
    public function test_a_missing_connection_does_not_break_the_sale(): void
    {
        WhatsAppConnection::query()->platform()->update(['status' => WhatsAppConnection::INACTIVE]);

        $this->sell()->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(1, Order::where('is_held', false)->count());
    }

    /** ونفادُ الحصّة كذلك — والفاتورة تُصدر بترقيمها */
    public function test_an_exhausted_quota_does_not_break_the_sale(): void
    {
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200)]);
        $this->business->update(['whatsapp_monthly_limit' => 0]);

        $response = $this->sell()->assertOk()->assertJson(['ok' => true]);

        $this->assertNotEmpty($response->json('invoice'));
    }

    /* ---------------------------- الأسعار والمخزون ---------------------------- */

    /** السعر ما زال من القاعدة لا من الطلب */
    public function test_pricing_is_unaffected(): void
    {
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'x']]], 200)]);

        $this->sell(['items' => [[
            'id' => $this->product->id, 'name' => 'باقة ورد', 'qty' => 1, 'price' => 1,
        ]]])->assertOk();

        $order = Order::latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(25.0, (float) $order->subtotal, 0.0005);
    }
}
