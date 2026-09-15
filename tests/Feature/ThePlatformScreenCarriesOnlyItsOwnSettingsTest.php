<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * شاشةُ إعدادات المنصّة تحمل إعداداتِها وحدها — لا الجدولَ كلَّه.
 *
 * ═══ العطب الذي أُغلق ═══
 *
 * كانت `platformSettings` تنشر كلَّ صفوف `business_id = null` فوق
 * الافتراضيّات. وفي الجدول ما ليس للشاشة: `google_places_key` — مفتاحُ
 * خرائط Google للمنصّة كلِّها، تُحتسب عليه فاتورةُ نداءات كلّ التجّار.
 *
 * وهو **معمًّى** في القاعدة، فلم تظهر منه كلمةٌ مقروءة. لكنّ نصَّه المعمّى
 * كاملًا — ٢٥٦ حرفًا — كان يصل حمولةَ الصفحة، فيأخذه من فتح «مصدر الصفحة»
 * أو أدوات المتصفّح أو امتدادًا يقرأ الصفحات. وفكُّه لا يحتاج إلّا
 * `APP_KEY` — وهو في `.env` وفي كلّ نسخةٍ احتياطيّة.
 *
 * ═══ ولمَ لم يمسكه حارسٌ قائم ═══
 *
 * كان في `EachBranchHasItsOwnPlaceTest` حارسٌ يقول إنّ المفتاح لا يظهر —
 * ويفحص **النصَّ الخام**. والخامُ لم يظهر قطّ، والمعمّى ظهر دائمًا. فمرّ
 * الحارسُ وهو لا يقيس ما ظُنّ أنّه يقيسه.
 *
 * فهذه الحالات تقيس **المحفوظ في القاعدة** لا ما نظنّه فيها.
 */
class ThePlatformScreenCarriesOnlyItsOwnSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $boss;

    protected function setUp(): void
    {
        parent::setUp();

        $this->boss = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'sa@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }
    /* ------------------------- ما لا يتسرّب ------------------------- */
    /**
     * وسرٌّ يُخزَّن غدًا لا يتسرّب لأنّ أحدًا نسي أن يستثنيه.
     *
     * وهذا هو الفرق بين قائمةِ منعٍ وقائمةِ سماح: الأولى تُنسى عند كلّ
     * إضافة، والثانية تُغلق ما لم يُفتح عمدًا.
     */
    public function test_a_secret_stored_later_does_not_leak_either(): void
    {
        Setting::create([
            'business_id' => null,
            'key' => 'some_future_provider_secret',
            'value' => Crypt::encryptString('a-secret-nobody-remembered-to-exclude'),
        ]);

        $body = $this->body();

        $this->assertStringNotContainsString('some_future_provider_secret', $body);
        $this->assertArrayNotHasKey('some_future_provider_secret', $this->props()['settings']);
    }
    /* ------------------------- وما يجب أن يصل ------------------------- */

    /** والإغلاقُ لا يكسر الشاشة: المحفوظُ المسموح يصلها */
    public function test_a_saved_value_still_reaches_the_screen(): void
    {
        Setting::updateOrCreate(
            ['business_id' => null, 'key' => 'app_name'],
            ['value' => 'افنت بلس'],
        );

        $this->assertSame('افنت بلس', $this->props()['settings']['app_name']);
    }
}
