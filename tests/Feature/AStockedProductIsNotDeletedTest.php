<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صنفٌ في يده بضاعةٌ يُحذف بعد أن يُقال العدد — لا صامتًا.
 *
 * كان الحذف يمرّ بلا سؤالٍ عن الرصيد: يضغط التاجر «حذف» على صنفٍ في مستودعه
 * مئتا قطعة، فتختفي البطاقة من كلّ شاشة وتنقص قيمةُ المخزون مئتين × التكلفة
 * في لحظة — بلا سببٍ يُقرأ في أيّ تقرير. وتبقى صفوفُ `branch_stocks` تحمل
 * المئتين إلى حين المحو النهائيّ: لا قيدَ مفتاحٍ يأخذها معه، ولا شاشة تعرضها.
 *
 * **ولا يُمنع.** ستٌّ وعشرون ومئةٌ من أصل مئةٍ وسبعةٍ وعشرين صنفًا على
 * الإنتاج فيها بضاعة، فمنعٌ مطلق يعني ألّا يُحذف شيءٌ أبدًا. والعطبُ في الصمت
 * لا في الفعل: يُقال العددُ ويُطلب إقرارٌ صريح، ويُقال البديل — وأكثرُ من
 * يضغط «حذف» يريد «عطّله».
 *
 * والرصيدُ صفرًا يُحذف كما كان: تاريخُ البيع محفوظٌ في `order_items` باسمه
 * ولقطةِ سعره، فلا ينكسر تقرير.
 */
class AStockedProductIsNotDeletedTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'ورود مسقط', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function product(int $quantity, string $name = 'قميص'): Product
    {
        $category = Category::create(['business_id' => $this->business->id, 'name' => 'عام']);

        $product = Product::create([
            'business_id' => $this->business->id, 'category_id' => $category->id,
            'name' => $name, 'sku' => 'SKU-'.uniqid(), 'price' => 10, 'cost' => 6,
            'quantity' => $quantity, 'alert_qty' => 2, 'active' => true,
        ]);

        BranchStock::ensureAllocated($this->business->id, $product->id, $quantity);

        return $product;
    }
    public function test_the_warning_carries_the_button_that_confirms_it(): void
    {
        /*
         * تحذيرٌ يقول «أكّد» ولا زرَّ معه يجعل التاجر يعيد الضغط على الحذف
         * نفسه فيقرأ التحذير نفسه — بابٌ يُعرض ولا يُفتح.
         */
        $product = $this->product(200);

        $this->delete(route('admin.products.destroy', $product->id))
            ->assertSessionHas('toast', fn ($toast) => isset($toast['confirm']['url']));
    }

    public function test_the_refusal_says_how_many_and_what_to_do_instead(): void
    {
        /*
         * «تعذّر الحذف» وحدها تجعل التاجر يعيد الضغط. والعددُ هو ما يوقفه:
         * من يقرأ «فيه ٢٠٠» يعرف أنّه أخطأ في الصنف أو نسي جردًا.
         */
        $product = $this->product(200, 'وردٌ جوريّ');

        $this->delete(route('admin.products.destroy', $product->id))
            ->assertSessionHas('toast', function ($toast) {
                return str_contains($toast['msg'], '200')
                    && str_contains($toast['msg'], 'وردٌ جوريّ')
                    && str_contains($toast['msg'], 'عطّله');
            });
    }
    public function test_its_branch_stock_is_never_left_behind_by_a_delete(): void
    {
        /*
         * هذا هو الأثرُ لا الرسالة: صفٌّ يحمل مئتي قطعةٍ لمنتجٍ لا تعرضه شاشة.
         */
        $product = $this->product(200);

        $this->delete(route('admin.products.destroy', $product->id));

        $orphan = BranchStock::where('product_id', $product->id)->where('quantity', '>', 0)
            ->whereNotIn('product_id', Product::query()->select('id'))->count();

        $this->assertSame(0, $orphan, 'رصيدٌ يتيمٌ في فرعٍ لمنتجٍ محذوف');
    }
    public function test_the_switch_goes_both_ways(): void
    {
        // مقبضٌ لا يُدير إلا في اتّجاهٍ واحد يحبس من ضغطه بالخطأ
        $product = $this->product(5);

        $this->post(route('admin.products.toggle', $product->id));
        $this->post(route('admin.products.toggle', $product->id));

        $this->assertTrue((bool) $product->fresh()->active);
    }

    public function test_a_neighbours_product_is_neither_toggled_nor_deleted(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Product::create([
            'business_id' => $other->id, 'name' => 'صنفُ الجار', 'sku' => 'X-1',
            'price' => 1, 'cost' => 1, 'quantity' => 0, 'alert_qty' => 1, 'active' => true,
        ]);

        $this->post(route('admin.products.toggle', $theirs->id))->assertNotFound();
        $this->delete(route('admin.products.destroy', $theirs->id))->assertNotFound();

        $this->assertTrue((bool) $theirs->fresh()->active);
    }
}
