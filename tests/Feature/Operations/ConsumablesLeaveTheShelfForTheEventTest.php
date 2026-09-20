<?php

namespace Tests\Feature\Operations;

use App\Models\JournalLine;
use App\Models\OperationConsumableIssue;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\InventoryMovement;
use App\Support\Intelligence\ProjectProfitability;
use App\Support\Ledger;
use App\Support\Operations\Consumables;
use App\Support\Operations\EventOperations;
use App\Support\Operations\Requirements;
use App\Support\ProjectFinancials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;

/**
 * المستهلَكاتُ تخرج من الرفّ إلى الفعاليّة — المرحلة 11.
 *
 * صرفٌ يحرّك المخزون من بابه الوحيد (`StockLedger`) ويقيّد تكلفةً على المشروع
 * (Dr تكلفة / Cr مخزون)؛ وردٌّ يعيد ما لم يُستهلك بتكلفة الصرف نفسِها؛ ومفتاحُ
 * تكرارٍ يمنع الصرفَ مرّتين بنقرتين؛ ولا يُصرف ما ليس على الرفّ؛ ولا مخزونَ ثانٍ.
 */
class ConsumablesLeaveTheShelfForTheEventTest extends OperationsTestCase
{
    use RefreshDatabase;

    private function product(int $quantity = 100, float $cost = 0.5, string $kind = 'item'): Product
    {
        return Product::create([
            'business_id' => $this->business->id, 'name' => 'بطاقة تعريف', 'price' => 2, 'cost' => $cost, 'kind' => $kind,
            'quantity' => $quantity, 'alert_qty' => 1, 'active' => true, 'sku' => 'CARD-'.random_int(100, 999),
        ]);
    }

    private function balance(string $key): float
    {
        return round(Ledger::balance((int) $this->business->id, $key), 3);
    }

    public function test_issuing_moves_stock_posts_cost_to_the_project_and_returning_reverses_the_rest(): void
    {
        Ledger::seedChart((int) $this->business->id);
        $op = $this->operation();
        $product = $this->product(100, 0.5);
        $req = Requirements::add($op, ['kind' => 'product', 'product_id' => $product->id, 'required_quantity' => 40], $this->owner);

        $this->actingAs($this->owner);
        $issue = Consumables::issue($op, $product, ['quantity' => 40, 'requirement_id' => $req->id, 'key' => 'abc123'], $this->field);

        $this->assertSame(60, (int) $product->fresh()->quantity, 'الرفُّ نقص 40');
        $this->assertSame(OperationConsumableIssue::ISSUED, $issue->status);
        $this->assertEqualsWithDelta(0.5, (float) $issue->unit_cost, 0.0005, 'التكلفةُ تُثبَّت وقتَ الصرف');
        $this->assertEqualsWithDelta(20.0, $this->balance('cogs'), 0.0005);
        $this->assertEqualsWithDelta(-20.0, $this->balance('inventory'), 0.0005);
        $adjustment = StockAdjustment::find($issue->issue_adjustment_id);
        $this->assertSame($this->project->id, (int) $adjustment->project_id, 'التسويةُ تحمل المشروع');
        $this->assertSame(-40, (int) $adjustment->quantity_delta);
        $this->assertSame(1, InventoryMovement::where('product_id', $product->id)->where('type', Consumables::ISSUE_TYPE)->count(), 'حركةٌ من الباب الوحيد');
        $this->assertSame(40, (int) $req->fresh()->issued_quantity);

        $f = ProjectFinancials::summary($this->project->fresh());
        $this->assertEqualsWithDelta(20.0, $f['stock_consumed'], 0.0005);

        /* تغيّرت تكلفةُ الصنف — والردُّ بتكلفة الصرف لا اليوم */
        $product->update(['cost' => 9]);
        $closed = Consumables::giveBack($issue, ['quantity' => 15], $this->field);

        $this->assertSame(75, (int) $product->fresh()->quantity);
        $this->assertSame(OperationConsumableIssue::CLOSED, $closed->status);
        $this->assertSame(25, $closed->consumedQuantity());
        $this->assertEqualsWithDelta(12.5, $closed->consumedCost(), 0.0005);
        $this->assertEqualsWithDelta(12.5, $this->balance('cogs'), 0.0005, '20 − 15 × 0.5');
        $this->assertEqualsWithDelta(-12.5, $this->balance('inventory'), 0.0005);
        $this->assertSame(15, (int) $req->fresh()->returned_quantity);

        $f = ProjectFinancials::summary($this->project->fresh());
        $this->assertEqualsWithDelta(12.5, $f['stock_consumed'], 0.0005, 'الصافي لا الإجمالي — لا عدَّ مزدوج');
        $this->assertEqualsWithDelta(12.5, ProjectProfitability::forProject($this->project->fresh())['direct_costs']['stock_consumed'], 0.0005);

        $summary = Consumables::summary($op);
        $this->assertEqualsWithDelta(12.5, $summary['consumed_cost'], 0.0005);
        $this->assertEqualsWithDelta(7.5, $summary['returned_cost'], 0.0005);
        $this->assertSame(0, $summary['open']);
    }

    public function test_the_same_key_issues_once_and_a_closed_issue_returns_once(): void
    {
        Ledger::seedChart((int) $this->business->id);
        $op = $this->operation();
        $product = $this->product(10, 1);
        $this->actingAs($this->owner);

        $a = Consumables::issue($op, $product, ['quantity' => 3, 'key' => 'same'], $this->owner);
        $b = Consumables::issue($op, $product, ['quantity' => 3, 'key' => 'same'], $this->owner);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(7, (int) $product->fresh()->quantity, 'نقرتان — حركةٌ واحدة');
        $this->assertSame(1, JournalLine::whereHas('entry', fn ($q) => $q->where('business_id', $this->business->id))->where('debit', '>', 0)->count());

        Consumables::giveBack($a, ['quantity' => 1], $this->owner);
        Consumables::giveBack($a->fresh(), ['quantity' => 1], $this->owner);
        $this->assertSame(8, (int) $product->fresh()->quantity, 'ردٌّ ثانٍ على مقفلٍ لا يفعل شيئًا');
    }

    public function test_you_cannot_issue_more_than_the_shelf_holds_nor_return_more_than_issued(): void
    {
        Ledger::seedChart((int) $this->business->id);
        $op = $this->operation();
        $product = $this->product(5, 1);
        $this->actingAs($this->owner);

        try {
            Consumables::issue($op, $product, ['quantity' => 6], $this->owner);
            $this->fail('صُرف أكثرُ من الرصيد');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('رصيد', $e->getMessage());
        }
        $this->assertSame(5, (int) $product->fresh()->quantity);
        $this->assertSame(0, InventoryMovement::where('product_id', $product->id)->count());

        $issue = Consumables::issue($op, $product, ['quantity' => 4], $this->owner);
        try {
            Consumables::giveBack($issue, ['quantity' => 5], $this->owner);
            $this->fail('رُدّ أكثرُ ممّا صُرف');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('أكثر', $e->getMessage());
        }
        $this->assertSame(OperationConsumableIssue::ISSUED, $issue->fresh()->status);
    }

    public function test_services_bundles_and_closed_events_refuse_consumables(): void
    {
        Ledger::seedChart((int) $this->business->id);
        $op = $this->operation();
        $this->actingAs($this->owner);

        try {
            Consumables::issue($op, $this->product(10, 1, 'service'), ['quantity' => 1], $this->owner);
            $this->fail('خدمةٌ صُرفت من الرفّ');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('الخدمة', $e->getMessage());
        }

        EventOperations::cancel($op, 'أُلغيت', $this->owner);
        $this->expectException(RuntimeException::class);
        Consumables::issue($op->fresh(), $this->product(10, 1), ['quantity' => 1], $this->owner);
    }

    public function test_the_doors_are_the_checkout_and_return_permissions_and_never_cross_businesses(): void
    {
        Ledger::seedChart((int) $this->business->id);
        $op = $this->operation();
        $product = $this->product(10, 1);

        $this->actingAs($this->seller)->post(route('admin.operations.consumables.store', $op->id), ['product_id' => $product->id, 'quantity' => 2])->assertForbidden();
        $this->actingAs($this->stranger)->post(route('admin.operations.consumables.store', $op->id), ['product_id' => $product->id, 'quantity' => 2])->assertNotFound();
        $this->assertSame(10, (int) $product->fresh()->quantity);

        $this->actingAs($this->field)->post(route('admin.operations.consumables.store', $op->id), ['product_id' => $product->id, 'quantity' => 2, 'key' => 'k1'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(8, (int) $product->fresh()->quantity);
        $issue = OperationConsumableIssue::firstOrFail();

        $this->actingAs($this->stranger)->post(route('admin.operations.consumables.return', [$op->id, $issue->id]), ['quantity' => 1])->assertNotFound();
        $this->actingAs($this->field)->post(route('admin.operations.consumables.return', [$op->id, $issue->id]), ['quantity' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(9, (int) $product->fresh()->quantity);

        $foreign = Product::create(['business_id' => $this->other->id, 'name' => 'غريب', 'price' => 1, 'cost' => 1, 'quantity' => 50, 'alert_qty' => 1, 'active' => true, 'sku' => 'X-1']);
        $this->actingAs($this->owner)->post(route('admin.operations.consumables.store', $op->id), ['product_id' => $foreign->id, 'quantity' => 1])->assertNotFound();
        $this->assertSame(50, (int) $foreign->fresh()->quantity);

        $page = $this->actingAs($this->owner)->get(route('admin.operations.show', $op->id));
        $page->assertOk();
        $page->assertInertia(fn ($a) => $a->has('consumables.rows', 1)->where('consumables.rows.0.consumed', 1));
    }
}
