<?php

namespace Tests\Feature;

use App\Mail\RecoveryOtpMail;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
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
