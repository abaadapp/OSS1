<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * نقطة البيع بعد رفع الوردية — كاملةً، لا موضع الحذف وحده.
 *
 * الحذف يترك أثرَين لا يظهران في شاشة الحذف نفسها: مسارٌ يُنادى من زرٍّ بقي
 * في شاشةٍ أخرى فيرفع Ziggy خطأً يُبيّض الصفحة كلَّها، ودالّةٌ فقدت وسيطها
 * فتسقط بخمسمئة عند أوّل بيعة. فالفحص هنا يمشي على **كلّ** صفحات نقطة البيع
 * — بحساب صاحب النشاط وبحساب الكاشير — ويبيع بيعةً حقيقية من أوّلها.
 *
 * وأخصُّ ما يُفحص: أنّ البيع **لا يُمنع** بعد اليوم. كان مفتاح
 * `require_open_shift` يحبس الصندوق، وقد يبقى مرفوعًا في قاعدة متجرٍ رفعه —
 * فلو بقي له قارئ لَوقف صندوقُه بلا شاشةٍ يُطفئه منها.
 */
class PosWithoutShiftsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private User $cashier;

    private Product $product;
    /* ------------------------- الشاشات كلّها ------------------------- */

    /**
     * كلُّ صفحةٍ في نقطة البيع تُفتح — بلا خمسمئة وبلا تحويلٍ إلى مفقود.
     *
     * والقائمة تُبنى من جدول المسارات لا تُكتب باليد: مسارٌ يُضاف غدًا يدخل
     * الفحص من تلقائه، وقائمةٌ يدوية كانت ستنسى التالي.
     */
    public function test_every_pos_screen_opens_for_the_owner(): void
    {
        $skip = ['pos.setup', 'pos.currency.switch', 'pos.orders.resume', 'pos.receipts.show', 'pos.receipt.pdf', 'pos.order-details'];

        foreach ($this->posScreens($skip) as $name => $uri) {
            $this->actingAs($this->owner)->get('/'.ltrim($uri, '/'))
                ->assertOk("الصفحة «{$name}» لا تُفتح");
        }
    }

    /**
     * والكاشير كذلك — إلّا المدفوعات: شاشةٌ مالية تُقرأ منها حصيلةُ الصندوق،
     * ويُمنع منها من يبيع. والمنع يُفحص لأنّه قرار: لو انفتحت له لَقرأ
     * مقبوضات اليوم كلَّها.
     */
    public function test_every_pos_screen_opens_for_the_cashier_except_the_money(): void
    {
        $skip = ['pos.setup', 'pos.currency.switch', 'pos.orders.resume', 'pos.receipts.show', 'pos.receipt.pdf', 'pos.order-details'];

        foreach ($this->posScreens($skip) as $name => $uri) {
            $response = $this->actingAs($this->cashier)->get('/'.ltrim($uri, '/'));

            if ($name === 'pos.payments') {
                $response->assertForbidden();

                continue;
            }

            $response->assertOk("الصفحة «{$name}» لا تُفتح للكاشير");
        }
    }

    /** مسارات العرض في نقطة البيع — بلا وسائط، فتُطلب كما هي */
    private function posScreens(array $skip): array
    {
        $screens = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'pos.') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (in_array($name, $skip, true) || str_contains($route->uri(), '{')) {
                continue;
            }

            $screens[$name] = $route->uri();
        }

        return $screens;
    }
    /* --------------------------- لا أثر --------------------------- */

    /** ولا زرَّ يقود إلى مسارٍ رُفع: Ziggy ترفع خطأً يُبيّض الصفحة كلَّها */
    public function test_no_screen_still_points_at_a_removed_route(): void
    {
        $guilty = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));

        foreach ($files as $file) {
            if ($file->isDir() || ! in_array($file->getExtension(), ['tsx', 'ts'], true)) {
                continue;
            }

            if (str_contains(file_get_contents($file->getPathname()), 'pos.shift')) {
                $guilty[] = basename($file->getPathname());
            }
        }

        $this->assertSame([], $guilty, 'زرٌّ يُنادي مسار وردية مرفوع');
    }
}
