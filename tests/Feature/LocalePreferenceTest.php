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
    public function test_arabic_is_the_default_when_nobody_chose(): void
    {
        $this->assertSame('ar', $this->localeOf($this->user('admin', 'a@test.local')));
    }
    public function test_the_business_setting_still_acts_as_the_default(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'locale', 'value' => 'en']);
        $newcomer = $this->user('cashier', 'new@test.local');

        $this->assertSame('en', $this->localeOf($newcomer), 'من لم يختر يرث افتراضي المتجر');
    }

    public function test_a_personal_choice_beats_the_business_default(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'locale', 'value' => 'en']);
        $owner = $this->user('admin', 'owner@test.local');
        $owner->update(['locale' => 'ar']);

        $this->assertSame('ar', $this->localeOf($owner));
    }
    public function test_a_guest_can_switch_the_login_page_without_an_account(): void
    {
        $this->post(route('language.guest'), ['locale' => 'en'])->assertRedirect();
        $this->assertSame('en', session('locale'));
    }

    /**
     * تسميات الشهور تتبع اللغة في كل المخططات.
     *
     * كانت ثلاثة منها تقرأ جدول الأشهر العربي مباشرةً بلا فحص اللغة: «مارس»
     * و«يوليو» على لوحةٍ إنجليزية كاملة. ومن لا يقرأ العربية لا يعرف أيّ
     * عمودٍ أيّ شهر — فيقرأ المخطط بالعكس ولا يشكّ أنه أخطأ.
     */
    public function test_chart_month_labels_follow_the_locale(): void
    {
        $series = [
            fn () => \App\Support\Demo::revenueSeries()['labels'],
            fn () => \App\Support\Demo::businessesGrowthSeries()['labels'],
            fn () => \App\Support\Demo::businessSalesSeries($this->business->id)['labels'],
            fn () => \App\Support\Demo::salesSeries()['labels'],
        ];

        app()->setLocale('en');
        foreach ($series as $i => $make) {
            foreach ($make() as $label) {
                $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', $label, "مخطط رقم {$i} يعرض شهرًا بالعربية في الوضع الإنجليزي: {$label}");
            }
        }

        app()->setLocale('ar');
        foreach ($series as $i => $make) {
            foreach ($make() as $label) {
                $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $label, "مخطط رقم {$i} يعرض شهرًا بالإنجليزية في الوضع العربي: {$label}");
            }
        }
    }
}
