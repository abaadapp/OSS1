<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لغة الواجهة تفضيلٌ شخصيّ — تُكتب في حساب من بدّلها وحده.
 *
 * والمبدّل زرٌّ ظاهر في الشريط العلوي وتبويبٌ في الإعدادات معه: الإعدادات
 * موضعُ ما يُضبط مرّة، واللغة يبدّلها الموظّف كل يوم — ومن لا يقرأ العربية
 * لا يقرأ كلمة «الإعدادات» ليصل إليها.
 */
class LanguageIsAPersonalPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_switches_through_their_own_route(): void
    {
        $business = Business::create(['name' => 'Event Plus', 'type' => 'شركة تقنية', 'status' => 'نشط']);
        $owner = User::create([
            'business_id' => $business->id, 'name' => 'المالك', 'email' => 'owner@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($owner)
            ->post(route('admin.language.update'), ['locale' => 'en'])
            ->assertRedirect();

        $this->assertSame('en', $owner->fresh()->locale);
    }
}
