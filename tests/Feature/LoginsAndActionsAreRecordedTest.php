<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الدخول والخروج والأفعال تُقيَّد — بلا استثناء.
 *
 * كان في السجلّ بابٌ للإسكات: دخول مدير المنصّة وخروجه لا يُكتبان، لأنه
 * يدخل كلّ يوم مرّاتٍ فيدفع ما يُراقَب حقًّا خارج الصفحة الأولى. وذهبت
 * لوحةُ المنصّة وذهب معها الاستثناء — فصار السجلّ يكتب كلّ شيء، وهو ما
 * يجب أن يكون في نظامٍ داخليٍّ لا أحد فيه فوق الدفتر.
 *
 * ومحاولةُ الدخول الفاشلة تبقى كما كانت: إشارةُ أمنٍ لا ضجيجَ إدارة.
 */
class LoginsAndActionsAreRecordedTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $business = Business::create(['name' => 'Event Plus', 'type' => 'شركة تقنية', 'status' => 'نشط']);
        Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $business->id, 'name' => 'مالك الشركة', 'email' => 'owner@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    public function test_a_login_is_recorded(): void
    {
        $this->post(route('login.attempt'), ['email' => 'owner@eventplus.om', 'password' => 'password']);

        $this->assertSame(1, ActivityLog::where('action', 'login')->count(),
            'دخولٌ لم يُسجَّل');
    }

    /** والخروج معه: لا أحد فوق الدفتر بعد أن ذهبت لوحة المنصّة */
    public function test_a_logout_is_recorded_too(): void
    {
        $this->actingAs($this->owner)->post(route('logout'));

        $this->assertSame(1, ActivityLog::where('action', 'logout')->count(),
            'خروجٌ لم يُسجَّل');
    }

    /** ومحاولةٌ فاشلة تبقى: إشارةُ أمنٍ لا ضجيجَ إدارة */
    public function test_a_failed_attempt_is_still_recorded(): void
    {
        $this->post(route('login.attempt'), ['email' => 'owner@eventplus.om', 'password' => 'خطأ']);

        $this->assertSame(1, ActivityLog::where('action', 'login_failed')->count(),
            'محاولة دخول فاشلة اختفت من السجلّ');
    }

    public function test_other_actions_are_recorded_by_name(): void
    {
        $this->actingAs($this->owner);
        Activity::log('deleted', 'حذف صنفًا');

        $this->assertSame(1, ActivityLog::where('action', 'deleted')->count(),
            'فعلٌ اختفى من السجلّ');
    }
}
