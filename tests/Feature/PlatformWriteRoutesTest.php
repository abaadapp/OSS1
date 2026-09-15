<?php

namespace Tests\Feature;

use App\Mail\RecoveryOtpMail;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Support\WhatsAppMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * بابان في لوحة المنصّة كانا يعملان بلا حارس.
 *
 * `businesses.recovery.resend` و`whatsapp.shared.disconnect` — لا اختبارَ
 * واحدًا يذكرهما، وهما يعملان اليوم صحيحين. لكنّ العملَ اليوم بلا حارسٍ هو
 * الخطر نفسه: لا شيء يقول إنّ شيئًا انكسر حتى يكسره تعديلٌ بعيد.
 *
 * وأخطر ما يُحرَس فيهما اثنان: أنّ إعادة إرسال الرمز **لا تختم** العنوان —
 * ولو ختمته لَصار كلُّ حسابٍ مفتوحًا لمن يجلس على شاشة الدعم؛ وأنّ فصل
 * الرقم المشترك **تعطيلٌ لا حذف** — الحذف يقطع صلة تاريخ الرسائل بوصلتها.
 */
class PlatformWriteRoutesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.gmail.com']);
        Mail::fake();

        $this->business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'root@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    private function shared(string $status = WhatsAppConnection::ACTIVE): WhatsAppConnection
    {
        return WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'EVENTPLUS-PN',
            'display_phone_number' => '+96890000000',
            'access_token' => 'platform-token-value-0123456789',
            'status' => $status,
            'connected_at' => now(),
        ]);
    }
    /* ------------------------- فاتورة الاشتراك على ورق ------------------------- */

    private function invoice(string $status = 'مدفوعة'): Invoice
    {
        $plan = Plan::create(['name' => 'الباقة الاحترافية', 'monthly_price' => 25, 'yearly_price' => 250]);

        return Invoice::create([
            'number' => 'INV-2026-0001',
            'business_id' => $this->business->id,
            'plan_id' => $plan->id,
            'amount' => 25,
            'issued_at' => '2026-09-01',
            'status' => $status,
        ]);
    }
    /** اسم المنصّة على الورقة يأتي من إعداداتها لا من ثابتٍ في القالب */
    public function test_the_platform_identity_reaches_the_paper(): void
    {
        Setting::updateOrCreate(['business_id' => null, 'key' => 'company'], ['value' => 'افنت بلس للحلول']);
        app()->setLocale('ar');

        $html = view('pdf.platform-invoice', [
            'invoice' => $this->invoice()->load('business', 'plan'),
            'platform' => ['app_name' => 'افنت بلس', 'company' => 'افنت بلس للحلول', 'email' => '', 'phone' => '', 'website' => ''],
        ])->render();

        $this->assertStringContainsString('افنت بلس للحلول', $html);
        $this->assertStringContainsString('محل ورد', $html);
        $this->assertStringContainsString('الباقة الاحترافية', $html);
    }
}
