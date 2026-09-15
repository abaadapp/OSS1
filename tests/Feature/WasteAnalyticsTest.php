<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Support\Waste;
use App\Support\WasteInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تحليلات الهالك — قراءةٌ فوق تعديلات المخزون القائمة.
 *
 * وأكثر ما يُحرس هنا شيئان: أنّ الصفوف القديمة لا تُمسّ، وأنّ الملاحظات لا
 * تُقال على بياناتٍ قليلة. «هلك مئة بالمئة» جملةٌ صحيحة حسابيًّا حين بيعت
 * قطعةٌ وهلكت قطعة، وكاذبةٌ عمليًّا — وثلاثُ جملٍ كهذه تُفقد الشاشة كلَّها
 * مصداقيّتها.
 */
class WasteAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private Branch $branch;
    private Product $rose;
    private Product $tulip;
    private Category $flowers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل الهالك', 'email' => 'w@test.local', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الخوض']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك', 'email' => 'owner@w.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->flowers = Category::create(['business_id' => $this->business->id, 'name' => 'ورود']);

        $this->rose = Product::create([
            'business_id' => $this->business->id, 'category_id' => $this->flowers->id,
            'name' => 'ورد أبيض', 'price' => 2, 'cost' => 1, 'quantity' => 500, 'active' => true,
        ]);

        $this->tulip = Product::create([
            'business_id' => $this->business->id, 'category_id' => $this->flowers->id,
            'name' => 'توليب', 'price' => 3, 'cost' => 2, 'quantity' => 200, 'active' => true,
        ]);
    }

    private function adjust(Product $product, float $delta, string $reason, ?string $at = null, ?Branch $branch = null, float $cost = 1): StockAdjustment
    {
        return StockAdjustment::create([
            'business_id' => $this->business->id,
            'branch_id' => ($branch ?? $this->branch)->id,
            'product_id' => $product->id,
            'number' => StockAdjustment::nextNumber($this->business->id),
            'quantity_delta' => $delta,
            'cost_at_time' => $cost,
            'reason' => $reason,
            'adjusted_at' => $at ?? now()->toDateString(),
        ]);
    }

    private function window(): array
    {
        return ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()];
    }

    /* ------------------------------ التصنيف ------------------------------ */

    public function test_only_damage_and_loss_count_as_waste(): void
    {
        $this->assertTrue(Waste::isWaste('تلف'));
        $this->assertTrue(Waste::isWaste('فقد'));
        // «جرد» تصحيحُ عدٍّ لا خسارة، و«إهداء» خروجٌ بقرارٍ لا بحادث
        $this->assertFalse(Waste::isWaste('جرد'));
        $this->assertFalse(Waste::isWaste('إهداء'));
        $this->assertFalse(Waste::isWaste('تصحيح'));
        $this->assertFalse(Waste::isWaste(null));
    }
    /* ------------------------------ المقارنة ----------------------------- */

    public function test_the_previous_window_matches_the_length_of_the_current_one(): void
    {
        $window = Waste::previousWindow('2026-08-11', '2026-08-20');   // عشرة أيام

        $this->assertSame('2026-08-01', $window['from']);
        $this->assertSame('2026-08-10', $window['to']);
    }

    /* ------------------------------ الملاحظات ---------------------------- */

    public function test_no_insight_is_offered_on_a_nearly_empty_period(): void
    {
        $this->adjust($this->rose, -1, 'تلف', cost: 0.5);

        $this->assertSame([], WasteInsights::all($this->business->id, $this->window()));
    }

    public function test_a_real_rise_is_reported(): void
    {
        $window = ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString()];
        $previous = Waste::previousWindow($window['from'], $window['to']);

        $this->adjust($this->rose, -100, 'تلف', $window['from'], cost: 1);
        $this->adjust($this->rose, -20, 'تلف', $previous['to'], cost: 1);

        $insights = WasteInsights::all($this->business->id, $window);
        $texts = array_column($insights, 'text');

        $this->assertNotEmpty($insights);
        $this->assertTrue(
            collect($texts)->contains(fn ($x) => str_contains($x, 'ارتفع') || str_contains($x, 'rose')),
            'لم تُذكر الزيادة: '.implode(' | ', $texts),
        );
    }

    public function test_a_single_branch_shop_is_never_told_one_branch_dominates(): void
    {
        $this->adjust($this->rose, -100, 'تلف');

        $texts = array_column(WasteInsights::all($this->business->id, $this->window()), 'text');

        // فرعٌ واحد ليس «أعلى من بقيّة الفروع» — لا بقيّة هناك
        $this->assertFalse(collect($texts)->contains(fn ($x) => str_contains($x, 'الخوض')));
    }
    public function test_the_screen_opens_and_carries_its_numbers(): void
    {
        $this->adjust($this->rose, -10, 'تلف');

        $this->actingAs($this->owner)->get(route('admin.reports.waste'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Reports/Waste')
                ->where('totals.quantity', 10)
                ->where('totals.value', 10));
    }
}
