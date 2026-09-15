<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الباقة حدٌّ في النظام لا سطرٌ في الفاتورة.
 *
 * `PlanLimits` تحرس الإنشاء: لا فرعَ حادي عشر لمن باقتُه عشرة. لكنّها
 * تقيس السقف **الحاليّ** — فتنزيلُ الباقة كان يمرّ بلا فحص، ومتجرٌ بثلاثة
 * فروعٍ يُنقَل إلى باقةِ فرعٍ واحد فتبقى الثلاثة تعمل. ولا شيء يقول إنّها
 * زائدة، ولا شيء يمنع التنزيل.
 */
class PlanBindsTheShopTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;
    private Business $business;
    private Plan $basic;
    private Plan $pro;
    /* --------------------------- السقف عند الإنشاء --------------------------- */

    public function test_a_shop_cannot_pass_its_branch_cap(): void
    {
        $this->business->update(['plan_id' => $this->basic->id]);
        Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);

        $owner = \App\Support\MerchantAccount::owner($this->business);

        $this->actingAs($owner)->post(route('admin.branches.store'), ['name' => 'صلالة'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Branch::where('business_id', $this->business->id)->count());
    }
    /* ----------------------------- باقةٌ وهميّة ----------------------------- */
}
