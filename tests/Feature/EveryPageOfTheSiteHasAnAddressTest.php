<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Models\Website;
use App\Support\Storefront;
use App\Support\Website\Builder;
use App\Support\Website\Published;
use App\Support\Website\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لكلّ صفحةٍ في الموقع عنوانٌ يُفتح — ولكلّ رابطٍ فيه وجهةٌ داخله.
 *
 * ═══ ما كان ═══
 *
 * افنت بلسُ تبني للتاجر موقعًا بأربع صفحات — الرئيسية والمتجر ومن نحن وتواصل
 * معنا — وتكتب في ترويسته وتذييله قائمةً تُشير إليها كلِّها (`Website\Nav`).
 * ثمّ **لا تخدم منها إلّا الرئيسية**: لا مسارَ لصفحةٍ داخليّة في النظام
 * أصلًا. فكلُّ رابطٍ في قائمة كلِّ موقعٍ منشور يردّ ٤٠٤.
 *
 * وطبقةُ الرسم تقبل الصفحة منذ بُنيت (`Site` لها خاصّية `page`) — الناقصُ
 * كان العنوانَ وحده، والمدخلَ الذي يقول للرسم أيَّ صفحةٍ يرسم.
 *
 * ═══ وأسوأ منه على المسار البديل ═══
 *
 * الروابطُ مكتوبةٌ من الجذر (`/shop`)، وهي صحيحةٌ على النطاق الفرعيّ وعلى
 * نطاق التاجر. أمّا على `app.eventplusapp.om/s/متجري` — وهو الطريق العامل على
 * الإنتاج اليوم — فجذرُ المضيف جذرُ **افنت بلس**: «الرئيسية» تُخرج زبونَ
 * التاجر إلى **صفحة دخول افنت بلس**، وسائرُها إلى ٤٠٤.
 *
 * وتُحلّ في الخادم لا في طبقة الرسم: تلك مصدرُها `storefront/src/site`
 * وتُنسخ إلى هنا بأمرٍ واحد (انظر `RendererParityTest`)، فتعديلُها هنا
 * يُمحى عند أوّل مزامنة ويعود العطبُ صامتًا.
 */
class EveryPageOfTheSiteHasAnAddressTest extends TestCase
{
    use RefreshDatabase;

    /** ما بين علامتين — لقراءة جسد الخادم وحده */
    private function between(string $haystack, string $from, string $to): string
    {
        $start = strpos($haystack, $from);

        if ($start === false) {
            return '';
        }

        $end = strpos($haystack, $to, $start);

        return $end === false ? '' : substr($haystack, $start, $end - $start);
    }

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create([
            'name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط',
            'site_slug' => 'wrood', 'phone' => '96890000000', 'city' => 'مسقط',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function publish(): Website
    {
        $site = Builder::create($this->business, 'store', 'modern', $this->owner->id);
        Publisher::publish($site, $this->owner->id);

        return $site;
    }

    /** @return list<string> مساراتُ صفحات الموقع المنشورة */
    private function slugs(): array
    {
        $doc = Published::forBusiness((int) $this->business->id)['site'];

        return collect($doc['pages'])
            ->filter(fn ($p) => ($p['status'] ?? 'published') === 'published')
            ->pluck('slug')->map(fn ($s) => (string) $s)->values()->all();
    }

    /* ══════════════ ١ · لكلّ صفحةٍ عنوان ══════════════ */
    /* ══════════════ ٢ · ولكلّ رابطٍ وجهةٌ داخل المتجر ══════════════ */
    /* ══════════════ ٣ · ولكلّ عنوانٍ رأسُه ونصُّه ══════════════ */
    /* ══════════════ ٤ · ولا يبتلع مسارُ المتاجر عناوينَ افنت بلس ══════════════ */

    /**
     * نمطُ «مضيفٍ ليس لنا» يستثني مضيفات افنت بلس — **في القراءتين**.
     *
     * ═══ وهذا الحارسُ مكتوبٌ بدمٍ ═══
     *
     * الاستثناء كان `(?!.*eventplusapp\.om$)`. وبلا `route:cache` يُطابَق نمطُ
     * المضيف على المضيف وحدَه، فـ`$` نهايتُه و`app.eventplusapp.om` يُستثنى.
     * ومع التخزين يدمج المُطابِقُ المُصرَّف المضيفَ والمسارَ في سلسلةٍ واحدة
     * بفاصلٍ **نقطة** — `app.eventplusapp.om./login` — فتصير `$` نهايةَ الاثنين:
     * والسلسلةُ تنتهي بـ`/login` لا بـ`eventplusapp.om`، فيمرّ الاستثناء ويلتقط
     * المسارُ الجامعُ كلَّ عنوانٍ في النظام.
     *
     * ووقع على الإنتاج: `/login` و`/health` و`/s/{slug}` كلُّها ٤٠٤. ولم
     * تره السويتةُ كلُّها لأنّ الاختبارات لا تُخزّن المسارات ولا تُنادى على
     * مضيف الإنتاج — فيُقاس هنا الشكلان اللذان يُطابَق عليهما فعلًا.
     */
    public function test_the_foreign_host_pattern_excludes_eventplus_in_both_readings(): void
    {
        $regex = '#^(?:'.Storefront::foreignHost().')$#Diu';
        $merged = '#^(?:'.Storefront::foreignHost().')\.#Diu';

        // القراءةُ الأولى: المضيفُ وحدَه (بلا تخزين المسارات)
        foreach (['app.eventplusapp.om', 'eventplusapp.om', 'wrood.eventplusapp.om'] as $ours) {
            $this->assertDoesNotMatchRegularExpression($regex, $ours, "مضيفُ افنت بلس «{$ours}» لم يُستثنَ");
        }

        // والقراءةُ الثانية: المضيفُ والمسارُ مدموجين بنقطة (مع التخزين)
        foreach (['app.eventplusapp.om./login', 'app.eventplusapp.om./health', 'app.eventplusapp.om./s/saad'] as $subject) {
            $this->assertDoesNotMatchRegularExpression($merged, $subject, "«{$subject}» التقطه مسارُ المتاجر");
        }

        // ونطاقُ تاجرٍ حقيقيّ يُلتقط — الحدُّ يستثني ولا يُقفل
        $this->assertMatchesRegularExpression($regex, 'wrood.om');
        $this->assertMatchesRegularExpression($merged, 'wrood.om./about');
    }

    /**
     * ونمطُ النطاق الفرعيّ يحمل الحدَّ نفسَه — وهو الذي نجا لأنّه يحمله.
     *
     * `Storefront::pattern` تستثني المحجوز بـ`(?:\.|$)` منذ بُنيت، وتعليقُها
     * يقول لمَ. وهذا يمنع سقوطَه يوم يُبدَّل.
     */
    public function test_the_subdomain_pattern_keeps_the_same_boundary(): void
    {
        $merged = '#^(?:'.Storefront::pattern().')\.eventplusapp\.om\.#Diu';

        $this->assertDoesNotMatchRegularExpression($merged, 'app.eventplusapp.om./login');
        $this->assertMatchesRegularExpression($merged, 'wrood.eventplusapp.om./about');
    }
}
