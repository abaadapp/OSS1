<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionClass;
use Tests\TestCase;

/**
 * شاشةُ إعدادات المنصّة: تحفظ، وإن رَدَّت قالت لماذا.
 *
 * ═══ العطب الذي وُلد منه هذا الملفّ ═══
 *
 * `SETTING_DEFAULTS['default_plan']` كان **«أساسية»** — اسمٌ لا تحمله أيُّ
 * باقةٍ ينشئها النظام (هي «الباقة الأساسية» و«الباقة الاحترافية» و«باقة
 * المؤسسات»). والشاشةُ تُرسل الحقولَ كلَّها في كلّ حفظٍ مهما كان التبويبُ
 * المفتوح، فيقرأ `update` هذا الافتراضيَّ ويردّ **الطلبَ كلَّه** لأنّ الاسم
 * لا يطابق باقة.
 *
 * ورسالةُ الرفض تُكتب على `default_plan` — وحقلُه في تبويب «الاشتراكات».
 * فمن كان في تبويب «واتساب» يضغط «حفظ» ولا يرى شيئًا: ترتدّ الصفحة كأنّ
 * شيئًا لم يكن. وقِيس أثرُه على الإنتاج: جدولُ إعدادات المنصّة فيه **صفٌّ
 * واحد**، فالشاشةُ لم تحفظ حرفًا منذ كُتب ذلك الافتراضيّ.
 *
 * وعطبان لا واحد: قيمةٌ افتراضيّةٌ تشير إلى ما لا وجود له، وبابٌ يُردّ ولا
 * يُقال لماذا.
 */
class ARejectedSaveSaysWhyTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->super = User::create([
            'name' => 'مدير المنصة', 'email' => 'p@eventplus.om', 'password' => bcrypt('password'),
            'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }
    /**
     * والشاشةُ تفتح تبويبَ الحقل المعطوب وتقول ما وقع.
     *
     * وتُقاس على شكل الكود لا على نصٍّ عربيٍّ في تعليق: خريطةٌ تربط كلَّ
     * حقلٍ بتبويبه، وأثرٌ يفتح تبويبَ أوّلِ خطأ، وشريطٌ يطبع الرسائل.
     */
    public function test_the_screen_surfaces_an_error_that_lives_in_a_folded_tab(): void
    {
        $screen = file_get_contents(resource_path('js/Pages/Platform/Settings/Index.tsx'));

        $this->assertStringContainsString('const FIELD_TAB: Record<string, string> = {', $screen);
        $this->assertStringContainsString("default_plan: 'subscriptions',", $screen);
        $this->assertStringContainsString('setTab(owner);', $screen, 'لا يُفتح تبويبُ الخطأ');
        $this->assertStringContainsString('{errorKeys.length > 0 && (', $screen, 'لا شريطَ يقول ما وقع');
    }
}
