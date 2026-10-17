<?php

namespace Tests\Feature;

use App\Mail\DailySummaryMail;
use App\Mail\MonthlyReportMail;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\User;
use App\Support\Demo;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * كلُّ متجرٍ يصله بريدُه بعملته — لا بعملة من سبقه في العامل.
 *
 * ═══ العطبُ الذي وُلد منه هذا الملفّ ═══
 *
 * `Demo::baseCurrency()` تقرأ **متجر الجلسة**. وهو الصواب في طلبٍ يفتحه
 * التاجر، وخطأٌ صامتٌ في موضعين:
 *
 *  • أمرٌ مجدول يمرّ على المتاجر واحدًا واحدًا (الملخّص اليوميّ، التقرير
 *    الشهريّ) — لا جلسةَ فيه أصلًا.
 *  • ورسالةٌ في الطابور تُرسَم بعد أن يُغلق الطلب — والعاملُ نفسُه
 *    قد يعالج متجرين بالتتابع.
 *
 * فتاجرُ دبي يصله «‏5.250 ر.ع» عن بيعةٍ بالدرهم، أو ما هو أسوأ: عملةُ
 * المتجر الذي عُولج قبله في الدورة نفسها. ولا يظهر في اختبارٍ بمتجرٍ واحد،
 * ولا في اختبارٍ كلُّ متاجره بالريال.
 *
 * فيُمرَّر وصفُ العملة إلى الرسالة عند بنائها، ويُقرأ من **متجر صاحبها**
 * لا من الجلسة. وهذا الملفّ يقيسه بمتجرين مختلفَي العملة في دورةٍ واحدة.
 */
class EachShopIsMailedItsOwnCurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Business, 1: Branch} */
    private function shop(string $name, string $email, string $code, string $symbol): array
    {
        $b = Business::create([
            'name' => $name, 'type' => 'محل ورود', 'city' => 'مسقط', 'status' => 'نشط', 'email' => $email,
        ]);
        $branch = Branch::create(['business_id' => $b->id, 'name' => 'الفرع الرئيسي']);
        User::create([
            'business_id' => $b->id, 'name' => 'المالك', 'email' => 'u'.$b->id.'@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        Currency::create([
            'business_id' => $b->id, 'code' => $code, 'name' => $code,
            'symbol' => $symbol, 'rate' => 1.0, 'is_base' => true, 'active' => true,
        ]);

        return [$b, $branch];
    }

    /**
     * بيعةٌ = فاتورةُ عميلٍ صادرة: منها يقرأ الملخّصان (`Revenue`) — والطلباتُ
     * القديمة خرجت مع «المبيعات».
     */
    private function sale(Business $b, Branch $branch, float $total, string $number): CustomerInvoice
    {
        $customer = Customer::create(['business_id' => $b->id, 'name' => 'زبون']);
        $invoice = CustomerInvoice::create([
            'business_id' => $b->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'number' => $number, 'status' => CustomerInvoice::ISSUED, 'issued_at' => now()->toDateString(),
            'subtotal' => $total, 'tax_total' => 0, 'total' => $total,
        ]);
        CustomerInvoiceItem::create([
            'customer_invoice_id' => $invoice->id, 'description' => 'باقة', 'quantity' => 1,
            'unit_price' => $total, 'discount' => 0, 'tax_rate' => 0, 'tax_amount' => 0,
            'line_total' => $total, 'sort_order' => 0,
        ]);

        return $invoice;
    }

    /**
     * والأمرُ المجدول يمرّ على الاثنين في دورةٍ واحدة — ولكلٍّ عملتُه.
     *
     * والترتيبُ مقصود: المتجرُ العمانيّ أوّلًا، فلو سُرّبت عملتُه إلى الثاني
     * — بذاكرةٍ ساكنة أو بقراءةٍ من الجلسة — ظهر في رسالة دبي.
     */
    public function test_a_scheduled_run_mails_two_shops_two_currencies(): void
    {
        app()->setLocale('ar');
        Mail::fake();

        [$muscat, $mb] = $this->shop('متجر مسقط', 'muscat@x.om', 'OMR', 'ر.ع');
        [$dubai, $db] = $this->shop('متجر دبي', 'dubai@x.ae', 'AED', 'د.إ');

        $this->sale($muscat, $mb, 120.5, 'INV-000001')->update(['issued_at' => now()->subMonthNoOverflow()->toDateString()]);
        $this->sale($dubai, $db, 120.5, 'INV-000002')->update(['issued_at' => now()->subMonthNoOverflow()->toDateString()]);

        $this->artisan('reports:email')->assertSuccessful();

        Mail::assertSent(MonthlyReportMail::class, function (MonthlyReportMail $mail) use ($muscat) {
            if (! $mail->hasTo($muscat->email)) {
                return false;
            }
            $body = $mail->render();

            return str_contains($body, 'ر.ع') && ! str_contains($body, 'د.إ');
        });

        Mail::assertSent(MonthlyReportMail::class, function (MonthlyReportMail $mail) use ($dubai) {
            if (! $mail->hasTo($dubai->email)) {
                return false;
            }
            $body = $mail->render();

            return str_contains($body, 'د.إ') && ! str_contains($body, 'ر.ع');
        });
    }

    /** والملخّصُ اليوميّ كذلك — عملةُ صاحبه تصل معه */
    public function test_the_daily_summary_carries_the_shops_currency(): void
    {
        app()->setLocale('ar');
        [$dubai, $db] = $this->shop('متجر دبي', 'dubai@x.ae', 'AED', 'د.إ');
        $this->sale($dubai, $db, 5.25, 'INV-000001');

        $mail = new DailySummaryMail(
            $dubai->name,
            Demo::dailySummaryFor((int) $dubai->id, now()),
            'اليوم',
            Money::of((int) $dubai->id),
        );

        $body = $mail->render();

        $this->assertStringContainsString('د.إ', $body);
        $this->assertStringNotContainsString('ر.ع', $body);
    }
}
