<?php

namespace Tests\Feature\Operations;

use App\Models\ActivityLog;
use App\Models\AutomationRule;
use App\Models\InternalNotification;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectItem;
use App\Models\Setting;
use App\Support\Automation\Engine;
use App\Support\Automation\Rules;
use App\Support\Automation\Settings;
use App\Support\InventoryForecast;
use App\Support\Ledger;
use App\Support\Operations\Consumables;
use App\Support\Operations\Requirements;
use App\Support\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * المشاريعُ القادمة تتوقّع المخزونَ ولا تحرّكه.
 *
 * المنتجُ المخزونُ وحده يدخل التوقّع (لا خدمة ولا باقة ولا متطلّبٌ مستأجَر). والاحتياجُ
 * من متطلّبات الفعاليّة إن وُجدت، وإلّا من نطاق المشروع «غيرَ مؤكَّد» — ولا يُجمعان عن
 * منتجٍ واحد. والخطُّ الزمنيّ يطرح احتياجَ كلّ مشروعٍ بترتيب تاريخه. ولا حركةَ مخزون:
 * الصرفُ الحقيقيّ وحده يغيّر الرصيد. والتنبيهُ يُقال مرّةً حتى يتغيّر النقص.
 */
class UpcomingProjectsForecastStockWithoutMovingItTest extends OperationsTestCase
{
    use RefreshDatabase;

    private function product(string $name, int $quantity, string $kind = Product::ITEM): Product
    {
        return Product::create(['business_id' => $this->business->id, 'name' => $name, 'price' => 5, 'cost' => 2, 'kind' => $kind,
            'quantity' => $quantity, 'alert_qty' => 1, 'active' => true, 'sku' => 'SKU-'.random_int(1000, 9999)]);
    }

    private function scope(Project $project, Product $product, float $quantity): void
    {
        ProjectItem::create(['business_id' => $this->business->id, 'project_id' => $project->id, 'product_id' => $product->id,
            'description' => $product->name, 'quantity' => $quantity, 'unit_price' => 5, 'line_total' => 5 * $quantity, 'status' => ProjectItem::PENDING]);
    }

    private function upcoming(string $code, string $starts): Project
    {
        $project = $this->project($code);
        $project->update(['starts_at' => $starts, 'ends_at' => $starts]);

        return $project->fresh();
    }

    private function line(Project $project, Product $product): ?array
    {
        return collect(InventoryForecast::for((int) $this->business->id)['projects'][$project->id]['lines'] ?? [])->firstWhere('product_id', $product->id);
    }

    public function test_an_available_product_shows_no_shortage_and_the_forecast_writes_nothing(): void
    {
        $chairs = $this->product('كرسي', 20);
        $this->scope($this->project, $chairs, 6);
        $movements = InventoryMovement::count();

        $line = $this->line($this->project, $chairs);
        $this->assertSame([20, 0.0, 6.0, 14.0, 0.0, InventoryForecast::PROVISIONAL],
            [$line['available'], $line['committed'], $line['required'], $line['projected'], $line['shortage'], $line['source']]);
        $this->assertSame(20, (int) $chairs->fresh()->quantity, 'لا خصم');
        $this->assertSame($movements, InventoryMovement::count(), 'ولا حركةٌ وهميّة');
    }

    public function test_two_projects_in_sequence_take_from_the_same_shelf_and_the_later_one_falls_short(): void
    {
        $screens = $this->product('شاشة', 10);
        $earlier = $this->upcoming('EP-A', '2026-09-25');
        $this->scope($earlier, $screens, 7);
        $this->scope($this->project, $screens, 6);

        $before = $this->line($earlier, $screens);
        $after = $this->line($this->project, $screens);
        $this->assertSame([0.0, 7.0, 3.0], [$before['committed'], $before['required'], $before['projected']]);
        $this->assertSame([10, 7.0, 6.0, -3.0, 3.0], [$after['available'], $after['committed'], $after['required'], $after['projected'], $after['shortage']]);

        $timeline = InventoryForecast::for((int) $this->business->id)['products'][$screens->id]['timeline'];
        $this->assertSame(['EP-A', 'EP-2026-001'], array_column($timeline, 'code'), 'بترتيب التاريخ');
        $this->assertSame([3.0, -3.0], array_column($timeline, 'remaining'));
        $this->assertSame(1, InventoryForecast::for((int) $this->business->id)['projects'][$this->project->id]['shortages']);
    }

    public function test_an_operational_requirement_replaces_the_scope_line_for_the_same_product_never_both(): void
    {
        $chairs = $this->product('كرسي', 10);
        $this->scope($this->project, $chairs, 6);
        $op = $this->operation();
        Requirements::add($op, ['kind' => 'product', 'product_id' => $chairs->id, 'required_quantity' => 8, 'source' => 'inventory'], $this->owner);

        $line = $this->line($this->project, $chairs);
        $this->assertSame([8.0, InventoryForecast::CONFIRMED, 2.0], [$line['required'], $line['source'], $line['projected']], 'التشغيليُّ وحده — لا 6 + 8');
    }

    public function test_services_packages_assets_and_external_requirements_stay_out(): void
    {
        $service = $this->product('تصوير', 0, Product::SERVICE);
        $package = $this->product('باقة الحفل', 0, Product::PACKAGE);
        $rented = $this->product('منصة', 1);
        $this->scope($this->project, $service, 2);
        $this->scope($this->project, $package, 1);
        $op = $this->operation();
        Requirements::add($op, ['kind' => 'product', 'product_id' => $rented->id, 'required_quantity' => 5, 'source' => 'rented'], $this->owner);
        Requirements::add($op, ['kind' => 'asset_category', 'asset_category' => 'laptop', 'required_quantity' => 3], $this->owner);
        Requirements::add($op, ['kind' => 'external', 'description' => 'مولّد', 'required_quantity' => 1], $this->owner);

        $this->assertSame([], InventoryForecast::for((int) $this->business->id)['projects'][$this->project->id]['lines']);
        $this->assertArrayNotHasKey($service->id, InventoryForecast::for((int) $this->business->id)['products']);
    }

    public function test_a_real_stock_movement_changes_the_forecast_and_an_issue_is_not_subtracted_twice(): void
    {
        Ledger::seedChart((int) $this->business->id);
        $cards = $this->product('بطاقة', 10);
        $op = $this->operation();
        $req = Requirements::add($op, ['kind' => 'product', 'product_id' => $cards->id, 'required_quantity' => 8, 'source' => 'inventory'], $this->owner);
        $this->assertSame(2.0, $this->line($this->project, $cards)['projected']);

        /* صرفٌ حقيقيّ: نقص الرفّ 3 ونقص الباقي من المتطلّب 3 — والمتوقَّعُ كما هو */
        $this->actingAs($this->owner);
        Consumables::issue($op, $cards, ['quantity' => 3, 'requirement_id' => $req->id, 'key' => 'forecast-1'], $this->field);
        $line = $this->line($this->project, $cards);
        $this->assertSame([7, 5.0, 2.0], [$line['available'], $line['required'], $line['projected']]);

        /* واستلامٌ يزيد الرفّ — فيرتفع المتوقَّع */
        DB::transaction(fn () => StockLedger::move((int) $this->business->id, null, [$cards->id => 5], StockLedger::MANUAL, 'المالك', 'استلام'));
        $this->assertSame(7.0, $this->line($this->project, $cards)['projected']);
    }

    public function test_the_window_is_a_company_setting_not_a_number_in_the_code(): void
    {
        $tents = $this->product('خيمة', 2);
        $later = $this->upcoming('EP-LATE', '2026-10-25');
        $this->scope($later, $tents, 5);

        $this->assertSame(14, InventoryForecast::days((int) $this->business->id));
        $this->assertNull($this->line($later, $tents), 'خارج نافذة ١٤ يومًا');

        $this->actingAs($this->owner)->post(route('admin.settings.update'), ['inventory_forecast_days' => 40])->assertSessionHasNoErrors();
        $this->assertSame('40', Setting::where('business_id', $this->business->id)->where('key', InventoryForecast::SETTING)->value('value'));
        $this->assertSame(3.0, $this->line($later, $tents)['shortage']);
    }

    public function test_the_shortfall_alert_is_said_once_until_the_shortage_changes(): void
    {
        $screens = $this->product('شاشة', 4);
        $this->scope($this->project, $screens, 6);
        Settings::save((int) $this->business->id, ['mode' => Settings::LIVE], $this->owner);
        Rules::ensure((int) $this->business->id);
        AutomationRule::where('business_id', $this->business->id)->where('trigger', '!=', 'project_inventory_shortfall')->update(['enabled' => false]);
        $tick = fn () => Engine::tick((int) $this->business->id);
        $alerts = fn () => InternalNotification::where('kind', 'project_inventory_shortfall')->count();

        $tick();
        $owner = InternalNotification::where('kind', 'project_inventory_shortfall')->where('user_id', $this->manager->id)->sole();
        $this->assertStringContainsString('شاشة (نقص 2)', $owner->title);
        $this->assertTrue(InternalNotification::where('kind', 'project_inventory_shortfall')->where('user_id', $this->storekeeper->id)->exists(), 'والمخزونُ يعرف');
        $this->assertTrue(ActivityLog::where('description', 'like', 'نبّهت الأتمتة إلى نقص مخزون في المشروع EP-2026-001%')->exists());
        $first = $alerts();

        $tick();
        Carbon::setTestNow(now()->addDay());
        $tick();
        $this->assertSame($first, $alerts(), 'النقصُ نفسُه لا يُعاد — لا في الدورة ولا في الغد');

        /* زاد النقص — بصمةٌ جديدة فيُقال */
        ProjectItem::where('product_id', $screens->id)->update(['quantity' => 9]);
        Carbon::setTestNow(now()->addDay());
        $tick();
        $this->assertGreaterThan($first, $after = $alerts());

        /* تحسّن المخزونُ وزال النقص — ثمّ عاد: يُقال من جديد */
        $screens->update(['quantity' => 50]);
        Carbon::setTestNow(now()->addDay());
        $tick();
        $this->assertSame($after, $alerts(), 'لا «نقص» حين لا نقص');
        $screens->update(['quantity' => 4]);
        Carbon::setTestNow(now()->addDay());
        $tick();
        $this->assertGreaterThan($after, $alerts());
    }

    public function test_the_screens_show_it_to_who_may_read_stock_and_another_company_sees_none_of_it(): void
    {
        $screens = $this->product('شاشة', 4);
        $this->scope($this->project, $screens, 6);
        $op = $this->operation();

        $this->actingAs($this->owner)->get(route('admin.projects.show', $this->project->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('inventory_forecast.shortages', 1)->where('inventory_forecast.lines.0.name', 'شاشة'));
        $this->actingAs($this->owner)->get(route('admin.projects.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('projects', fn ($rows) => collect($rows)->firstWhere('id', $this->project->id)['inventory_shortages'] === 1));
        $this->actingAs($this->owner)->get(route('admin.operations.show', $op->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('inventory_forecast.shortages', 1));
        $this->actingAs($this->storekeeper)->get(route('admin.inventory.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('forecast.products.0.name', 'شاشة')->where('forecast.days', 14));

        /* من لا يفتح المخزون لا يُرسل إليه التوقّع */
        $this->actingAs($this->field)->get(route('admin.projects.show', $this->project->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('inventory_forecast', null));

        /* وشركةٌ أخرى: لا مشاريعُ «أ» ولا منتجاتُها في توقّعها */
        $theirs = $this->project('EP-THEIRS', (int) $this->other->id);
        ProjectItem::create(['business_id' => $this->other->id, 'project_id' => $theirs->id, 'product_id' => $screens->id,
            'description' => 'تسلّل', 'quantity' => 100, 'unit_price' => 1, 'line_total' => 100, 'status' => ProjectItem::PENDING]);
        $forecast = InventoryForecast::for((int) $this->other->id);
        $this->assertSame([], $forecast['products'], 'منتجُ «أ» لا يُقرأ في «ب» ولو أُشير إليه');
        $this->assertSame(6.0, $this->line($this->project, $screens)['required'], 'وبندُ «ب» لا يُجمع على «أ»');
    }
}
