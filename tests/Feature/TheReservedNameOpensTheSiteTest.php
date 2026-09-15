<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الاسمُ الذي حجزه التاجر هو الذي يفتح موقعه.
 *
 * ═══ حجزٌ لا يؤدّي إلى شيء ═══
 *
 * العارضُ الخارجيّ كان يبحث عن الاسم المحجوز في مفتاح `site_subdomain` —
 * **مفتاحٌ لا يكتبه شيءٌ في النظام كلِّه**: لا شاشةَ تحفظه ولا متحكّمَ يمرّره،
 * ولا صفَّ له في قاعدة الإنتاج. فالفرعُ كلُّه لا يقع أبدًا.
 *
 * والاسمُ المحجوز فعلًا في مكانٍ آخر: `businesses.site_slug` — هو الذي
 * يُفحص تفرّدُه، وهو الذي يُعرض للتاجر عنوانًا (`متجري.eventplusapp.om`)، وهو
 * الذي يخدمه مسارُ متجر افنت بلس. فمن حجز اسمه وبنى موقعه ونشره ثمّ فتح عنوانه
 * وجد «غير موجود» — والحجزُ في لوحته يقول إنّه له.
 *
 * ═══ ولاحقةٌ من مصدرين ═══
 *
 * `DomainOptions::suffix` كانت تقرأ إعدادَ منصّةٍ **لا يكتبه أحد**، و`Route`
 * والعنوانُ المعروض يُبنيان من `config('storefront.domain')`. فلاحقتان لشيءٍ
 * واحد: لو ضُبطت الأولى يومًا لَبحث العارض في لاحقةٍ لا يخدمها الخادم.
 *
 * ولا يُغني نجاحُهما اليوم بالمصادفة — كلتاهما تردّ `eventplusapp.om` — عن مصدرٍ
 * واحد: المصادفةُ تنقلب يوم يُضبط أحدُهما.
 */
class TheReservedNameOpensTheSiteTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_01_100000_a_domain_already_set_is_a_choice_made.php';

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }
    private function reserve(string $slug): void
    {
        $this->business->forceFill(['site_slug' => $slug])->save();
    }
    /* --------------------------- اللاحقة الواحدة --------------------------- */
    /**
     * ولا يُقرأ المفتاح في شفرةٍ حيّة.
     *
     * والفحص على النصّ المقتبس (`'site_subdomain'`) لا على الكلمة: ذِكرُها
     * في شرحٍ يقول «رُفعت ولماذا» توثيقٌ لا قراءة — وأوّلُ صياغةٍ لهذا
     * الحارس سقطت على تعليقي أنا.
     */
    public function test_no_live_code_reads_the_dead_key(): void
    {
        $files = [
            'app/Http/Controllers/PublishedSiteController.php',
            'app/Http/Controllers/Admin/Website/Concerns.php',
            'app/Support/MarketingSettings.php',
        ];

        foreach ($files as $file) {
            $this->assertStringNotContainsString(
                "'site_subdomain'",
                file_get_contents(base_path($file)),
                "{$file} ما زال يقرأ مفتاحًا لا يكتبه أحد",
            );
        }
    }

    /* ------------------------------ الهجرة ------------------------------ */

    /**
     * والهجرةُ القديمة لا تنفجر على قاعدةٍ فيها نطاق.
     *
     * كانت تكتب `DomainOptions::OWN` — ثابتٌ **رُفع** من الصنف بعدها (وحارسٌ
     * في `OneListForTheAddressPathsTest` يمنع عودته). فسطرُها لا يُنفَّذ إلا
     * على قاعدةٍ فيها صفُّ `site_domain`، وهناك ينفجر: «ثابتٌ غير معرَّف».
     *
     * ولا تقع على الإنتاج — جرت يوم كان الثابت قائمًا — بل على استعادةِ نسخةٍ
     * أو خادمٍ جديد يُبنى من الصفر ببياناتٍ حقيقية. وهو أسوأُ وقتٍ تنفجر فيه.
     */
    public function test_the_old_migration_survives_a_database_with_a_domain(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'site_domain', 'value' => 'wrood.om']);

        (require base_path(self::MIGRATION))->up();

        $this->assertTrue(true, 'الهجرة نُفّذت بلا انفجار');
    }
}
