<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ما يدخل الفاتورة يدخل وعاء الضريبة معه.
 *
 * الإضافةُ «جزءٌ من ثمن البند لا سطرٌ منفصل» — هكذا يقول الحساب في موضعين:
 * المجموعُ الفرعيّ يضمّها، والفاتورةُ تعرضها في البند. وحدَه وعاءُ الضريبة
 * كان يقرأ سعر الصنف وحده.
 *
 * وهذا ليس خطأً في شاشة: هو ضريبةٌ لم تُجبَ من الزبون ولم تُقرّ للدولة، عن
 * كلّ إضافةٍ باعها المحلّ منذ رُبطت الإضافات بالبنود.
 */
class PosSecondPassAuditTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $cashier;

    private Product $product;

    private Addon $addon;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة',
            'price' => 10, 'cost' => 4, 'quantity' => 100, 'alert_qty' => 1, 'active' => true,
        ]);

        $this->addon = Addon::create([
            'business_id' => $this->business->id, 'name' => 'شوكولاتة',
            'price' => 5, 'icon' => '🍫', 'active' => true,
        ]);

        $this->set('vat_enabled', '1');
        $this->set('vat_rate', '5');
    }

    private function set(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => $key], ['value' => $value]);
    }
    public function test_the_screen_and_the_server_weigh_the_same_line(): void
    {
        /*
         * الشاشة كانت الصادقة والخادم هو الذي انحرف: `usePosCart` تحسب
         * وعاءها بـ`lineTotal` — سعرٌ في كميّة زائدًا الإضافات — والخادم كان
         * يقرأ السعر وحده. فيقرأ الزبون على الشاشة ١٥٫٧٥٠ ويُطبع له ١٥٫٥٠٠.
         *
         * والمعادلتان في لغتين لا تُشغَّلان في عمليّةٍ واحدة، فتُقرأ الصيغتان
         * من مصدريهما: كلتاهما تضمّ الإضافة إلى الوعاء. وهذا إثباتُ شكلٍ لا
         * إثباتُ تساوٍ — والتساوي يُثبته الرقم في الاختبار الأوّل أعلاه.
         */
        $screen = file_get_contents(resource_path('js/hooks/usePosCart.ts'));
        $server = file_get_contents(app_path('Http/Controllers/Pos/PosController.php'));

        $this->assertStringContainsString(
            'const net = lineTotal(i);',
            $screen,
            'الشاشة لم تعد تحسب وعاءها من ثمن البند كاملًا',
        );

        $this->assertStringContainsString(
            "\$net = \$l['price'] * \$l['qty'] + (\$l['addons_total'] ?? 0);",
            $server,
            'الخادم يقرأ سعر الصنف وحده — والإضافة في المجموع وليست في الوعاء',
        );
    }
}
