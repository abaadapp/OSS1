<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\FlowerOrder;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الطلبُ الذي يُنفَّذ لاحقًا له موعد — وإلّا اختفى.
 *
 * ═══ ما كان يقع ═══
 *
 * `Order::awaitingPreparation` تشترط الموعد لدخول اللوحة، وصندوقُ البيع يكتب
 * «مكتمل» لكلّ بيعةٍ بلا موعد. فطلبُ توصيلٍ حُفظ بلا موعد يُولد مغلقًا ولا
 * يُرى على لوحة التجهيز أبدًا: له مستلِمٌ وهاتفٌ وعنوان، ولا أحد يصنعه.
 *
 * وشاشةُ الصندوق كانت ترفضه («موعد التسليم مطلوب للطلبات التي تُجهَّز»)
 * والخادمُ يقبله. وقاعدةٌ مكتوبةٌ في الشاشة وحدها ليست قاعدة — هي عادةُ
 * متصفّحٍ واحد، يتخطّاها كلّ ما لا يمرّ من ذلك المتصفّح.
 *
 * وأخطرُ منه بابُ التعديل: طلبٌ قائمٌ على اللوحة يُفرَّغ موعدُه من «تفاصيل
 * الطلب» فيسقط منها صامتًا، ويبقى «قيد التجهيز» لا يجهّزه أحد.
 *
 * ═══ وملاحظةُ السطر تُقرأ ═══
 *
 * «بلا ورد أحمر» يكتبها الكاشير على الصنف، ويرسلها الخادم إلى اللوحة، ولا
 * ترسمها البطاقة. الاسمُ يقول ماذا يُصنَع، والملاحظةُ تقول كيف.
 */
class AnOrderThatIsPreparedHasADateTest extends TestCase
{
    use RefreshDatabase;

    private const BOARD = 'resources/js/Pages/Admin/Preparation/Index.tsx';

    private const DIALOG = 'resources/js/Pages/Pos/partials/PaymentDialog.tsx';

    private Business $business;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد', 'price' => 25, 'cost' => 10,
            'quantity' => 50, 'alert_qty' => 2,
        ]);

        $this->actingAs($this->owner);
    }
    /* ------------------------------ حارسان لا يفترقان ------------------------------ */

    /**
     * الشاشةُ والخادمُ يقولان الجملة نفسها.
     *
     * وهي كانت مكتوبةً في الشاشة وحدها — فحُذفت من الشاشة يومًا لَما بقي
     * لها أثر، أو بقيت في الشاشة والخادم يقبل، وهو ما وقع.
     */
    public function test_the_screen_and_the_server_refuse_with_the_same_words(): void
    {
        $refusal = 'موعد التسليم مطلوب للطلبات التي تُجهَّز.';

        $this->assertStringContainsString($refusal, file_get_contents(base_path(self::DIALOG)));

        // والخادمُ يترجمها كغيرها — فالمقارنة على المصدر المترجَم لا على النصّ
        $errors = FlowerOrder::afterValidation(['fulfillment_type' => FlowerOrder::PICKUP]);
        $this->assertSame(__($refusal), $errors['scheduled_for'] ?? null);
    }
    /** ويرسمها من يقف عند الطاولة — لا تصل الشاشةَ لتُطرح */
    public function test_the_board_draws_the_line_note(): void
    {
        $this->assertStringContainsString('{i.note && (', file_get_contents(base_path(self::BOARD)));
    }
}
