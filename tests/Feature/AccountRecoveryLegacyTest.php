<?php

namespace Tests\Feature;

use App\Mail\RecoveryOtpMail;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\PasswordRecoveryChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * المتاجر القديمة — ومنعُ الاستيلاء عليها.
 *
 * هذا الملفّ يحرس القرار المركزيّ كلّه: أنّ من يعرف اسم دخول محلٍّ **لا**
 * يستطيع أن يربط بريده هو ثمّ يأخذ الحساب. ولو مرّ فحصٌ واحد هنا وهو معطّل
 * لَكان كلُّ متجرٍ في المنصّة مفتوحًا لمن يقرأ فاتورةً.
 */
class AccountRecoveryLegacyTest extends TestCase
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

        $this->business = Business::create(['name' => 'محل قديم', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        // متجرٌ قديم: لا بريد استعادة إطلاقًا
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'qadeem@eventplusapp.om',
            'password' => bcrypt('old-password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->super = User::create([
            'business_id' => null, 'name' => 'حسابٌ بلا شركة', 'email' => 'root@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function codeFromMail(): string
    {
        $code = null;
        Mail::assertSent(RecoveryOtpMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return (string) $code;
    }

    /* ==================== لا استيلاء على متجرٍ قديم ==================== */

    /**
     * من عرف اسم الدخول لا يأخذ الحساب.
     *
     * هذا هو الفحص الذي تقوم عليه المهمّة: لا شيء في هذا الطلب — ولا في أيّ
     * تركيبةٍ منه — يُرسل رمزًا إلى بريدٍ يملكه الطالب.
     */
    public function test_knowing_the_login_email_does_not_let_anyone_attach_their_own_inbox(): void
    {
        foreach ([
            ['email' => 'qadeem@eventplusapp.om', 'recovery_email' => 'attacker@evil.com'],
            ['email' => 'qadeem@eventplusapp.om', 'target_email' => 'attacker@evil.com'],
            ['email' => 'qadeem@eventplusapp.om', 'new_email' => 'attacker@evil.com'],
            ['email' => 'qadeem@eventplusapp.om'],
        ] as $payload) {
            $this->post(route('recovery.start'), $payload);
        }

        Mail::assertNothingSent();
        $this->assertNull($this->owner->fresh()->recovery_email);
        $this->assertSame(0, PasswordRecoveryChallenge::count());
        $this->assertTrue(Hash::check('old-password', $this->owner->fresh()->password));
    }

    /** والجواب عامّ لا يقول إنّ الحساب موجودٌ بلا وسيلة استعادة */
    public function test_the_answer_stays_generic_for_a_legacy_account(): void
    {
        $known = $this->post(route('recovery.start'), ['email' => 'qadeem@eventplusapp.om']);
        $unknown = $this->post(route('recovery.start'), ['email' => 'nobody@eventplusapp.om']);

        $this->assertSame(
            $known->getSession()->get('status'),
            $unknown->getSession()->get('status'),
            'جوابان مختلفان يجعلان الشاشة أداة جردٍ للحسابات',
        );
    }
}
