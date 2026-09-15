<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لغة الواجهة تفضيل شخصي.
 *
 * كانت تُحفظ في settings[business_id,'locale'] المشترك، فالكاشير الذي
 * يبدّل نقطة البيع إلى الإنجليزية يقلب لوحة المالك إنجليزية أيضًا، والمالك
 * حين يعيدها عربية يسلب الكاشير إنجليزيته. متجر فيه موظفون لا يقرأون
 * العربية ومالك لا يقرأ الإنجليزية لا يعمل بإعداد واحد.
 *
 * الأولوية: الجلسة ← تفضيل المستخدم ← إعداد النشاط ← العربية.
 */
class LocalePreferenceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
    }

    private function user(string $role, string $email): User
    {
        return User::create([
            'business_id' => $this->business->id,
            'name' => $role, 'email' => $email,
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
        ]);
    }
    public function test_a_guest_can_switch_the_login_page_without_an_account(): void
    {
        $this->post(route('language.guest'), ['locale' => 'en'])->assertRedirect();
        $this->assertSame('en', session('locale'));
    }
}
