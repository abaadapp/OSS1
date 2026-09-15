<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * شاشة الدخول: بابٌ واحد، وهويّةُ جهازٍ فوقه.
 *
 * كان لها بابٌ ثانٍ — تبويب «رمز الموظف» — يُفتح افتراضيًّا على كل جهازٍ سبق
 * أن دخل منه أحد، ويصل إلى لوحة أرقامٍ تفتح حسابًا بأربعة أرقام. رُفع الباب
 * كلّه، وبقيت هوية الجهاز: اسم المتجر وفرعه فوق البطاقة، وبابُ نسيانه تحتها.
 *
 * وهذه الاختبارات تحرس ما بقي: أن الرمز ذهب فعلًا، وأن الهوية تُعرض لمن
 * يُعرف جهازه وحده.
 */
class LoginScreenTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجر الورد', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الخوير']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'email' => 'salem@eventplus.om',
            'role' => 'admin', 'job_title' => 'مدير', 'status' => 'نشط', 'password' => 'secret-pass',
        ]);

        RateLimiter::clear('login:salem@eventplus.om|127.0.0.1');
        RateLimiter::clear('login-hour:salem@eventplus.om|127.0.0.1');
    }
    /** والمسار نفسه رُفع — لا شاشةً مستقلّة ولا محاولةً تُقبل */
    public function test_the_pin_routes_are_gone(): void
    {
        $this->get('/pin-login')->assertNotFound();
        $this->post('/pin-login', ['pin' => '1234'])->assertNotFound();
    }
    /* --------------------------- حدّ المحاولات --------------------------- */
}
