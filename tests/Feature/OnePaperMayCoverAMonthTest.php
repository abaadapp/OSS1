<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\OrderCorrection;
use App\Support\Receivables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * ورقةٌ واحدة تغطّي شهرًا · وإلغاءُ الطلب يصل إليها · والتذكيرُ يقع وحدَه.
 */
class OnePaperMayCoverAMonthTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Customer $company;

    private Product $product;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 10, 'cost' => 4, 'quantity' => 500, 'active' => true,
        ]);
        $this->company = Customer::create([
            'business_id' => $this->business->id, 'name' => 'شركة ABC',
            'allow_credit_sales' => true, 'monthly_billing' => true, 'payment_terms_days' => 30,
        ]);
        $this->actingAs($this->owner);
    }
    // ————— السيناريو (ج): ثلاثةُ طلباتٍ وورقةٌ واحدة —————
    public function test_another_shops_order_cannot_be_billed_here(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirOrder = Order::create([
            'business_id' => $other->id, 'number' => 'X-1', 'customer_name' => 'س',
            'subtotal' => 90, 'discount' => 0, 'tax' => 0, 'total' => 90,
            'status' => 'مكتمل', 'payment_method' => 'نقدي', 'payment_status' => 'غير مدفوع',
        ]);

        $this->expectException(RuntimeException::class);

        try {
            CustomerInvoices::consolidate($this->company, [$theirOrder->id], [], $this->owner->id);
        } finally {
            $this->assertSame(0, CustomerInvoice::count());
        }
    }

    // ————— إلغاءُ طلبٍ مفوتَر —————
    // ————— التذكيرُ المجدول —————
}
