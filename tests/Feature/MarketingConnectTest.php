<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * أدواتُ التسويق تُفتح على بابٍ لا على شاشة.
 *
 * كانت الشاشة تُعرض كاملةً لمن لم يربط شيئًا: حقولُ معرّفاتٍ من حساب ميتا،
 * ومفتاحُ Google Cloud، ومقابضُ أحداثٍ لا تُرسل حرفًا قبل الربط. فيقرأ التاجر
 * عشرين سطرًا ليعرف أنّ لا شيء منها يعمل بعد، ثمّ يبحث عن الخطوة الأولى بين
 * البقيّة — فلا يبدأ.
 *
 * فصار البابُ بابًا: أيقونةٌ وزرٌّ واحد. وما وراءه مراحلُ بترتيبها، ولكلٍّ
 * حالُها ومن يملك إصلاحها.
 */
class MarketingConnectTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function props(string $route, array $query = []): array
    {
        return $this->actingAs($this->owner)->get(route($route, $query))
            ->assertOk()->viewData('page')['props'];
    }

    /* ------------------------------ البوّابة ------------------------------ */

    public function test_a_merchant_who_never_started_sees_the_door_not_the_screen(): void
    {
        // ولا رقمَ مشتركًا مربوطًا في المنصّة، فلا شيء جاهزٌ بعد
        $this->assertFalse($this->props('admin.integrations.whatsapp')['automation']['readiness']['connected']);
        $this->assertFalse($this->props('admin.integrations.google')['readiness']['connected']);
    }
    /* --------------------- افنت بلس مهيّأةٌ للربط أوّلًا --------------------- */
    /* -------------------------- شكلٌ واحدٌ لهما -------------------------- */
}
