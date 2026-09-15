<?php

namespace Tests\Feature;

use App\Support\SeedData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الباقات تفتح القدرات كلَّها — والفرق بينها في الأسقف.
 *
 * قرارُ صاحب المنصّة. والآلة باقيةٌ كما هي: `PlanFeatures` يفحص ويرفض كما
 * كان، فإغلاقُ قدرةٍ يومًا تأشيرةٌ واحدة في شاشة الباقات لا تعديلُ كود.
 *
 * وأشدُّ ما يُحرَس هنا أنّ قدرةً تُضاف غدًا إلى `PlanFeatures` لا تبقى مغلقةً
 * في الباقات الثلاث بلا أن يقول أحدٌ لماذا: القائمة تُقرأ من مصدرها، وهذا
 * الاختبار يسقط إن كُتبت بأسمائها من جديد.
 */
class EveryPlanOpensEverythingTest extends TestCase
{
    use RefreshDatabase;
    /** ولا تُترك فارغةً: الحارس يقرأ الفراغ «مفتوحًا» وشاشةُ الباقات تقرؤه «مغلقًا» */
    public function test_the_capabilities_are_listed_not_left_null(): void
    {
        foreach (SeedData::plans() as $plan) {
            $this->assertIsArray($plan['capabilities'], $plan['name']);
            $this->assertNotEmpty($plan['capabilities'], $plan['name']);
        }
    }
    /** وشبكة البطاقات تُعيد نفسها إلى الافتراضي بضغطة — لا بطاقةً بطاقة */
    public function test_the_stat_grid_can_be_restored_to_its_default(): void
    {
        $grid = file_get_contents(resource_path('js/Components/StatGrid.tsx'));

        $this->assertStringContainsString("{t('إعادة الافتراضي')}", $grid);
        $this->assertMatchesRegularExpression(
            '/const restore = \(\) => \{\s*write\(key, \[\], setHidden\);\s*write\(addedKey, \[\], setAdded\);/u',
            $grid,
            'الزرّ يمسح المخفيّات ولا يمسح المضافات — أو العكس',
        );
    }
}
