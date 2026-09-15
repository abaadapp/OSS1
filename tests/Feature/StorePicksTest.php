<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ما يُعرض في المتجر، وبكم يُعرض.
 *
 * الصفحة العامّة كانت تُبنى من `where('active', true)` وحدها وبعمود `price`
 * خامًا وبالريال العماني مثبَّتًا في القالب. وثلاثتها تُقرأ عند زبونٍ لا عند
 * موظّف: يرى ورقَ التغليف بضاعةً، ويرى سعرًا لا يدفعه أحد، ويرى عملةً ليست
 * عملة المحلّ الذي يشتري منه.
 */
class StorePicksTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Product $bouquet;
    /** والعدّ في الشاشة يقول ما يُعرض فعلًا — لا كلَّ ما هو فعّال */
    public function test_the_counter_counts_what_is_actually_shown(): void
    {
        Product::create([
            'business_id' => $this->business->id, 'name' => 'ورق تغليف',
            'price' => 0.2, 'cost' => 0.1, 'quantity' => 5, 'active' => true, 'published' => false,
        ]);

        $store = $this->actingAs($this->owner)->get(route('admin.settings.index'))
            ->assertOk()->viewData('page')['props']['store'];

        $this->assertSame(1, $store['productCount']);
        $this->assertCount(2, $store['products']);
    }
    /* ------------------------------ بكم يُعرض ------------------------------ */
}
