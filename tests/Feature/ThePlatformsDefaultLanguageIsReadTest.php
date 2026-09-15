<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * «اللغة الافتراضية» في إعدادات المنصّة — حقلٌ كان يُحفظ ولا يقرؤه شيء.
 *
 * ═══ ما كان ═══
 *
 * المشغّل يفتح إعدادات المنصّة، يختار الإنجليزية، يحفظ، ويقرأ «تم حفظ
 * إعدادات المنصة بنجاح». والصفُّ يُكتب فعلًا في `settings[null,'locale']`.
 * ثمّ لا يتغيّر شيء: `SetLocale` تقرأ الجلسةَ فتفضيلَ المستخدم فإعدادَ
 * النشاط ثمّ تسقط إلى العربية — ولا تمرّ بإعداد المنصّة أبدًا.
 *
 * وشاشتُه تَعِد بما لا يقع، بنصٍّ مكتوبٍ فيها: «لغة المنصة لمن لم يختر بعد».
 * ولا رسالةَ عطبٍ تدلّ المشغّل — مقبضٌ لا يُدير شيئًا، وهو أسوأ من غيابه
 * لأنّه يُطمئن.
 *
 * ═══ وأينَ موضعُه من السلسلة ═══
 *
 * بعد النشاط لا قبله: الأخصُّ يغلب. متجرٌ ضبط لغته لا تُبدّلها المنصّة عليه،
 * وموظّفٌ اختار لغته لا يُبدّلها عليه متجرُه.
 */
class ThePlatformsDefaultLanguageIsReadTest extends TestCase
{
    use RefreshDatabase;

    private function platform(string $locale): void
    {
        Setting::updateOrCreate(['business_id' => null, 'key' => 'locale'], ['value' => $locale]);
    }

    private function operator(): User
    {
        return User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'p@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    private function shop(): Business
    {
        return Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
    }

    private function owner(Business $b, ?string $own = null): User
    {
        return User::create([
            'business_id' => $b->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
            'locale' => $own,
        ]);
    }
    /* ═══════════ ولا تُسقط اللغةُ صفحةً ═══════════ */

    public function test_a_database_without_a_settings_table_still_serves_a_page(): void
    {
        /*
         * هذا الوسيط يجري على كلّ طلب — بما فيه أوّلُ طلبٍ على تنصيبٍ جديد
         * قبل أن تُهاجَر القاعدة. واستعلامٌ يسقط هنا يجعل أوّلَ ما يراه
         * المنصِّب صفحةَ عطبٍ لا صفحةَ دخول.
         *
         * وقد أسقطتُها فعلًا حين أضفتُ القراءة: `ExampleTest` — وهو يعمل بلا
         * هجرات — ردّ خمسمئة. فصار الحارسُ صريحًا لا مصادفةً في اختبارٍ آخر.
         */
        Schema::drop('settings');

        $this->get('/login')->assertOk();
        $this->assertSame('ar', app()->getLocale());
    }

    /* ═══════════ ولا صفوفَ ميّتةً في البذرة ═══════════ */

    public function test_the_seed_writes_no_platform_row_that_nothing_reads(): void
    {
        /*
         * `platform_name` اسمٌ قديم — الشاشةُ تكتب `app_name` و`PlatformConfig`
         * تقرؤه. و`currency` و`currency_decimals` يُقرآن من صفّ المتجر لا من
         * صفّ المنصّة. ثلاثةُ صفوفٍ تُزرع في كلّ تنصيبٍ جديد ولا يقرؤها سطر.
         */
        foreach (['database/seeders/DatabaseSeeder.php', 'database/seeders/DemoSeeder.php'] as $file) {
            $src = file_get_contents(base_path($file));

            $this->assertStringNotContainsString("[null, 'platform_name'", $src, $file);
            $this->assertStringNotContainsString("[null, 'currency'", $src, $file);
            $this->assertStringNotContainsString("[null, 'currency_decimals'", $src, $file);
            // و`vat_rate` يبقى — يقرؤه `Vat`
            $this->assertStringContainsString("[null, 'vat_rate', '5'],", $src, $file);
        }
    }
}
