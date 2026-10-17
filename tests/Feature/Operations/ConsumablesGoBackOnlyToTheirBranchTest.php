<?php

namespace Tests\Feature\Operations;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Product;
use App\Support\Ledger;
use App\Support\Operations\Consumables;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * إرجاعُ المستلزم يُعيده إلى فرع صرفه — فلا يُرجِعه من لا يعمل في ذلك الفرع.
 *
 * صرفُ الفعاليّة يبقى صرفَها كلَّه بتكلفته (هو تكلفةُ المشروع)، لكنّ الإرجاعَ
 * يكتب في رصيد فرعٍ بعينه. فمنفّذٌ محصورٌ في الفرع الرئيسيّ لا يُعيد ما صُرف
 * من صور، ولا يُعرض له زرُّه.
 */
class ConsumablesGoBackOnlyToTheirBranchTest extends OperationsTestCase
{
    use RefreshDatabase;

    public function test_a_restricted_user_does_not_return_stock_into_another_branch(): void
    {
        Ledger::seedChart((int) $this->business->id);
        $main = Branch::where('business_id', $this->business->id)->firstOrFail();
        $sur = Branch::create(['business_id' => $this->business->id, 'name' => 'صور']);
        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'شريط لاصق', 'price' => 2, 'cost' => 1, 'kind' => 'item',
            'quantity' => 20, 'alert_qty' => 1, 'active' => true, 'sku' => 'TAPE-1',
        ]);
        BranchStock::create(['business_id' => $this->business->id, 'branch_id' => $main->id, 'product_id' => $product->id, 'quantity' => 10]);
        BranchStock::create(['business_id' => $this->business->id, 'branch_id' => $sur->id, 'product_id' => $product->id, 'quantity' => 10]);
        $op = $this->operation();

        $this->actingAs($this->owner);
        $issue = Consumables::issue($op, $product, ['quantity' => 4, 'branch_id' => $sur->id, 'key' => 'sur-1'], $this->owner);
        $this->assertSame(6, (int) BranchStock::where('branch_id', $sur->id)->value('quantity'));

        $this->field->branches()->sync([$main->id]);
        $this->actingAs($this->field);

        // ولا يصرف من صور بطلبٍ يدويّ، ولا يُرجِع إليها
        $this->post(route('admin.operations.consumables.store', $op->id), ['product_id' => $product->id, 'quantity' => 1, 'branch_id' => $sur->id])
            ->assertForbidden();
        $this->post(route('admin.operations.consumables.return', [$op->id, $issue->id]), ['quantity' => 2])->assertForbidden();
        $this->assertSame(6, (int) BranchStock::where('branch_id', $sur->id)->value('quantity'));
        $this->assertFalse(Consumables::summary($op)['rows'][0]['returnable']);

        // ومن لا تُقيَّد فروعه يُرجِع كما كان
        $this->actingAs($this->owner);
        $this->assertTrue(Consumables::summary($op)['rows'][0]['returnable']);
        $this->post(route('admin.operations.consumables.return', [$op->id, $issue->id]), ['quantity' => 2])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(8, (int) BranchStock::where('branch_id', $sur->id)->value('quantity'));
    }
}
