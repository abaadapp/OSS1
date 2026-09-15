<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Review;
use App\Models\Setting;
use App\Models\User;
use App\Support\MarketingSettings;
use App\Support\WhatsAppEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * أدوات التسويق: إعدادٌ يُحفظ فيُقرأ، وتقييمٌ لا يُنشر حتى يُقرأ.
 *
 * أخطر ما في شاشات الإعداد أنّ عطبها صامت: مفتاحٌ يُكتب بحرفٍ ويُقرأ بآخر
 * يُظهر شاشةً سليمة وقيمةً افتراضية، فيضبط التاجر الإعداد ويحفظه ويراه محفوظًا
 * ولا أثر له في النظام. فالحفظ والقراءة يُفحصان معًا من الطرفين.
 */
class MarketingToolsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($owner);
    }

    private function bid(): int
    {
        return $this->business->id;
    }
    /**
     * وشاشةُ السيو عادت — بشرطِ ألّا تعِد بما لا تفعل.
     *
     * كانت تضبط عنوانًا ووصفًا وكلماتٍ مفتاحية ومفتاح «اسمح بالفهرسة»
     * لصفحاتٍ لا يقرؤها محرّكٌ من عندنا: الموقع خارج النظام، فما يُكتب هنا
     * لا يصل صفحةً. والحدودُ فيها كانت مضبوطةً بدقّة — ٦٠ محرفًا و١٦٠ — على
     * شيءٍ لا يقرؤه أحد.
     *
     * وما عاد ليس ذاك: معرّفُ قياسٍ **يُقرأ** فيُبنى منه الوسم ويُبحث عنه في
     * الصفحة، وفحصٌ **يفتح الموقع ويقول ما فيه**. فالحدودُ نفسها — ٦٠ و١٦٠ —
     * صارت تُقاس على ما في صفحته هو لا على ما كُتب في حقلٍ عندنا.
     *
     * وهذا الاختبار يحرس الفرق: لا يعود إلى المجموعة حقلٌ يُملأ ولا يُقرأ.
     */
    public function test_the_seo_screen_stores_only_what_something_reads(): void
    {
        $this->assertTrue(Route::has('admin.marketing.seo'));

        $this->assertSame(
            ['ga_measurement_id'],
            array_keys(MarketingSettings::GROUPS['seo']),
            'عاد إلى شاشة السيو حقلٌ يُملأ ولا يقرؤه شيء',
        );
    }
}
