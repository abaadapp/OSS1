<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use App\Support\DemoStore;

/**
 * كلّ شاشةٍ تُفتح — على متجرٍ ممتلئ لا على قاعدةٍ فارغة.
 *
 * أرخص فحصٍ يكشف أكثر: صفحةٌ تنكسر بخمسمئة لا يراها اختبارٌ يقيس منطقًا في
 * دالّة. وعلى متجرٍ ممتلئ عمدًا — الشاشات تنكسر على البيانات لا على غيابها:
 * علاقةٌ فارغة، أو قسمةٌ على صفر، أو حقلٌ null في صفٍّ قديم.
 */
class EveryScreenOpensTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $business = DemoStore::create('متجر الفحص', 'صغير');
        $this->owner = $business->users()->where('role', 'admin')->firstOrFail();

        }

    use RefreshDatabase;

    private User $owner;

    /** @return list<array{string, string}> */
    private function screens(string $prefix): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || ! str_starts_with($name, $prefix) || ! in_array('GET', $route->methods(), true)) {
                continue;
            }
            // ما يحمل معرّفًا يُفحص بمعرّفٍ حقيقيّ في اختباره الخاص، لا هنا
            if (str_contains($route->uri(), '{')) {
                continue;
            }
            $out[] = [$name, $route->uri()];
        }

        return $out;
    }

    private function sweep(User $as, string $prefix): void
    {
        $broken = [];

        foreach ($this->screens($prefix) as [$name, $uri]) {
            $response = $this->actingAs($as)->get('/'.ltrim($uri, '/'));
            $status = $response->getStatusCode();

            // ٢٠٠ أو تحويلٌ مقصود — وما عداهما عطب
            if ($status >= 500 || $status === 404) {
                $broken[] = $name.' → '.$status;
            }
        }

        $this->assertSame([], $broken, 'شاشاتٌ لا تُفتح');
    }
    public function test_every_merchant_screen_opens(): void
    {
        $this->sweep($this->owner, 'admin.');
    }

    /**
     * ولا شاشتان في لوحة التاجر تحملان الاسمَ نفسَه.
     *
     * ═══ وكيف وُجد هذا ═══
     *
     * كانت «الظهور في البحث» اسمَ شاشتين: واحدةٌ في «التسويق» تربط Google
     * Analytics وتفحص الصفحة، وأخرى في «الموقع الإلكتروني» يُكتب فيها عنوانُ
     * الصفحة ووصفُها. ومن سأل «لماذا لا أظهر في Google» يفتح إحداهما
     * بالصدفة — وقد تكون التي لا تكتب في صفحته حرفًا.
     *
     * وأسوأُ منه: نصائحُ الأولى تقول «اكتبه في الموقع الإلكتروني ‹ الظهور في
     * البحث»، فيقرؤها وهو واقفٌ على صفحةٍ تحمل الاسمَ نفسَه.
     *
     * ولوحةُ المنصّة تُستثنى: شاشاتُها لا يفتحها تاجرٌ قطّ، و«التقارير» عنده
     * غيرُ «التقارير» عندنا — واسمان متطابقان لا يلتقيان لا يُخلطان.
     */
    public function test_no_two_merchant_screens_share_a_name(): void
    {
        $titles = [];

        foreach ($this->pages(resource_path('js/Pages/Admin')) as $file) {
            preg_match('/<PageHeader\s[^>]*?title="([^"]+)"/s', (string) file_get_contents($file), $m);

            if (($m[1] ?? '') !== '') {
                /*
                 * والمسارُ لا الاسمُ المجرّد.
                 *
                 * الشاشتان المتصادمتان كلتاهما `Seo.tsx` — واحدةٌ تحت
                 * `Website` وأخرى تحت `Marketing`. و`basename` تجعلهما
                 * ملفًّا واحدًا في عين الحارس، فيمرّ التصادمُ الذي وُجد
                 * الحارسُ لأجله. وقد نجت طفرةٌ أعادت الاسمَ المكرَّر قبل أن
                 * يُصلَح هذا السطر.
                 */
                $titles[$m[1]][] = str_replace(resource_path('js/Pages/'), '', (string) $file);
            }
        }

        $clashes = [];

        foreach ($titles as $title => $files) {
            if (count(array_unique($files)) > 1) {
                $clashes[] = $title.' → '.implode('، ', array_unique($files));
            }
        }

        $this->assertSame([], $clashes, 'شاشتان في لوحة التاجر تحملان الاسم نفسه');
    }

    /** @return list<string> */
    private function pages(string $dir): array
    {
        $out = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            /* والرسّامُ المستعارُ خارجَ القياس — ليس شاشةً في القائمة */
            if ($file->isFile() && $file->getExtension() === 'tsx'
                && ! str_contains($file->getPathname(), 'preview/renderer')) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
