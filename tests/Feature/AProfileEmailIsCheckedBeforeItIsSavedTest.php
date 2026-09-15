<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بريدُ الحساب يُفحص قبل أن يُحفظ — ويُقال سببُ الرفض.
 *
 * كان فوق هذا حدٌّ ثانٍ: بريدُ مدير المنصّة يجب أن يكون على نطاق المنصّة،
 * لأنّ مفتاحَ المنصّة كلّها معلَّقٌ على حسابه. ولوحةُ المنصّة ذهبت، وذهب
 * الحدُّ معها — فلم يبقَ إلا الفحصُ الذي يخصّ كلَّ حساب: ألّا يأخذ عنوانًا
 * يملكه غيره، وأن يُقال له ذلك.
 */
class AProfileEmailIsCheckedBeforeItIsSavedTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'Event Plus', 'status' => 'نشط']);
    }

    private function user(string $email, string $name = 'موظف'): User
    {
        return User::create([
            'business_id' => $this->business->id,
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'نشط',
        ]);
    }

    public function test_any_address_is_accepted(): void
    {
        $user = $this->user('owner@eventplusapp.om', 'المالك');

        $this->actingAs($user)
            ->put(route('profile.update'), ['name' => 'المالك', 'email' => 'me@gmail.com'])
            ->assertSessionHasNoErrors();

        $this->assertSame('me@gmail.com', $user->fresh()->email);
    }

    public function test_a_taken_address_says_so_instead_of_seeming_to_do_nothing(): void
    {
        /*
         * هذا ما أوقع المستخدم: وضع بريدًا يملكه حسابٌ آخر فرُفض بصمت — رسالةٌ
         * افتراضية لا تقول أين ذهب العنوان، فبدا أن الزرّ لا يفعل شيئًا.
         */
        $this->user('taken@eventplusapp.om', 'زميل');
        $user = $this->user('owner@eventplusapp.om', 'المالك');

        $this->actingAs($user)
            ->put(route('profile.update'), ['name' => 'المالك', 'email' => 'taken@eventplusapp.om'])
            ->assertSessionHasErrors(['email' => 'هذا البريد مستعمل في حساب آخر — اختر غيره.']);
    }
}
