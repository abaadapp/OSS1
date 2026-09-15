<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchGooglePlace;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Support\GoogleReviews;
use App\Support\Integration;
use App\Support\WhatsAppFeature;
use App\Support\WhatsAppMode;
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
    /**
     * ومن كانت أداتُه تعمل أصلًا لا يُوقَف عند باب.
     *
     * «اربط» أمام تاجرٍ رسائلُه تخرج منذ شهور سؤالٌ عمّا تمّ — يجعله يظنّ
     * أنّ ربطه انفكّ.
     */
    public function test_a_tool_that_already_works_shows_no_door(): void
    {
        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);
        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'EVENTPLUS-PN',
            'display_phone_number' => '+96890000000',
            'access_token' => 'platform-token-value-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
            'connected_at' => now(),
        ]);

        $this->assertTrue($this->props('admin.integrations.whatsapp')['automation']['readiness']['connected']);
    }

    public function test_the_google_door_closes_once_the_shop_is_pinned(): void
    {
        /* والمعرّفُ يسكن الفرع لا إعداداتِ المتجر — لكلّ فرعٍ ملفُّه */
        /* والمتجرُ في هذا الملفّ قد لا يكون له فرع — والربطُ ينتمي إلى فرع */
        $branchId = Branch::where('business_id', $this->business->id)->value('id')
            ?? Branch::create([
                'business_id' => $this->business->id, 'name' => 'الرئيسي',
            ])->id;

        BranchGooglePlace::create([
            'branch_id' => $branchId,
            'place_id' => 'ChIJrTLr-GyuEmsRBfy61i59si0',
            'place_name' => 'محل الورد',
            'linked_at' => now(),
        ]);

        $this->assertTrue($this->props('admin.integrations.google')['readiness']['connected']);
    }

    /* --------------------- افنت بلس مهيّأةٌ للربط أوّلًا --------------------- */

    /**
     * مفتاحُ الخرائط على افنت بلس لا على التاجر.
     *
     * وكان عليه أن يفتح حسابًا في Google Cloud وينشئ مشروعًا ويربط بطاقةً
     * ليقرأ تقييمات محلّه — فلا يفعل، فتبقى الشاشة فارغة ولا شيء يقول إنّ
     * الطريق لم يكن معبَّدًا أصلًا.
     */
    public function test_the_platform_key_completes_the_first_stage_for_every_merchant(): void
    {
        $first = fn () => $this->props('admin.integrations.google')['readiness']['steps'][0];

        $this->assertFalse($first()['done'], 'الخطوة الأولى تمّت بلا مفتاحٍ في المنصّة');
        $this->assertTrue($first()['theirs'], 'خطوةُ افنت بلس تُقال للتاجر بصيغة الأمر');

        GoogleReviews::storePlatformKey('AIza-platform-key');

        $this->assertTrue($first()['done']);
    }

    /** ومفتاحُ افنت بلس لا يُقال للتاجر «مفتاحك محفوظ» — هو لم يحفظ شيئًا */
    public function test_the_platform_key_is_not_shown_as_the_merchants_own(): void
    {
        GoogleReviews::storePlatformKey('AIza-platform-key');

        $this->assertNull($this->props('admin.integrations.google')['keyHint']);
    }

    /** ومفتاحُ التاجر يتقدّم على مفتاح المنصّة: فاتورتُه فاتورتُه */
    public function test_a_merchants_own_key_wins_over_the_platform_key(): void
    {
        GoogleReviews::storePlatformKey('AIza-platform-key');
        GoogleReviews::storeKey($this->business->id, 'AIza-merchant-key');

        $this->assertSame('AIza-merchant-key', GoogleReviews::apiKey($this->business->id));
        $this->assertSame('••••-key', $this->props('admin.integrations.google')['keyHint']);
    }
    /* -------------------------- شكلٌ واحدٌ لهما -------------------------- */

    /**
     * الأداتان تُرسمان برسّامٍ واحد — فحقولُهما واحدة.
     *
     * أداةٌ تكتب حقلًا باسمٍ آخر تعني شاشةً تعرف كلَّ أداةٍ على حدة، وتعني
     * خطوةً تُضاف في إحداهما ولا تظهر في الأخرى. وثالثةٌ تأتي غدًا فتكتب
     * شكلًا ثالثًا.
     */
    public function test_both_tools_speak_the_same_shape(): void
    {
        $whatsapp = WhatsAppFeature::readiness($this->business->fresh());
        $google = GoogleReviews::readiness($this->business->id, ['state' => 'unlinked', 'error' => null]);

        $this->assertSame(['connected', 'ready', 'steps'], array_keys($google));
        $this->assertEqualsCanonicalizing(array_keys($whatsapp), array_keys($google));

        $fields = ['key', 'label', 'done', 'detail', 'fix', 'theirs'];
        foreach ([...$whatsapp['steps'], ...$google['steps']] as $step) {
            $this->assertSame($fields, array_keys($step), 'خطوةٌ بحقولٍ غير حقول أختها');
        }
    }

    /** وما تمّ لا يُقال كيف يُصلَح — نصيحةٌ تحت خطوةٍ مكتملة ضجيج */
    public function test_a_finished_stage_carries_no_instruction(): void
    {
        $step = Integration::step('k', 'خطوة', true, detail: 'تفصيل', fix: 'أصلحها');

        $this->assertNull($step['fix']);
        $this->assertSame('تفصيل', $step['detail']);
    }
}
