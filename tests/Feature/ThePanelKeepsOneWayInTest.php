<?php

namespace Tests\Feature;

use App\Http\Controllers\SuperAdmin\PageController;
use App\Models\Business;
use App\Models\User;
use App\Support\MerchantAccount;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ثلاثةُ أبوابٍ تُغلق لوحةَ المنصّة، وكان الحارس على واحدٍ منها.
 *
 * ═══ ما كان ═══
 *
 * «لا يمكن حذف آخر مدير منصة» حارسٌ قائمٌ على `destroy` — لأنّ حذفَه يُغلق
 * اللوحة على الجميع ولا حساب يبقى ليستعيده.
 *
 * وبابان آخران يُفضيان إلى النتيجة نفسها بلا حارس:
 *
 * ١) **تنزيلُ الدور.** المشغّل الوحيد يفتح «تعديل بيانات المستخدم» على
 *    حسابه ويختار «كاشير»، فيصير عددُ مدراء المنصّة صفرًا. وهو أسوأ من
 *    الحذف: الحذفُ ناعمٌ يُستعاد من مرشّح «المحذوفون»، وهذا لا مسارَ في
 *    النظام كلِّه يردّه — ولا حسابَ يفتح الشاشة التي فيها المسار.
 * ٢) **إيقافُ الحساب.** الموقوف لا يدخل، ولا يُفعَّل إلا من داخل اللوحة.
 *
 * وعلى الإنتاج مدير منصّةٍ **واحد**: ضغطةٌ واحدة تفصل بين اللوحة وبين
 * إصلاحٍ من قاعدة البيانات.
 *
 * ═══ ومستخدمٌ يُنشأ ثمّ يختفي ═══
 *
 * قائمةُ المستخدمين تستبعد موظّفي المتاجر التجريبيّة عمدًا، وقائمةُ «النشاط
 * التجاري» في نموذج الإضافة كانت تعرضها. فمن أُسند إلى متجرٍ تجريبيّ يُنشأ
 * في القاعدة، ويقول التوست «تم إضافة المستخدم بنجاح»، ثمّ لا يظهر في أيّ
 * صفحة — ويُردّ عند إعادة المحاولة بـ«هذا البريد مستعمل في حساب آخر».
 *
 * ═══ وحالةُ شركةٍ لا تعرفها الشاشة ═══
 *
 * `status` كان `string|max:50` والشاشة تعرض ثلاثًا. وحالةٌ مخترَعة شبحٌ
 * صامت: لا `Tenancy` تحجب بها، ولا عدّادُ «الشركات النشطة» يعدّها.
 */
class ThePanelKeepsOneWayInTest extends TestCase
{
    use RefreshDatabase;

    private function operator(string $email = 'p@eventplusapp.om', string $status = 'نشط'): User
    {
        return User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => $status,
        ]);
    }

    private function shop(bool $demo = false, string $name = 'متجري'): Business
    {
        return Business::create([
            'name' => $name, 'type' => 'عام', 'status' => 'نشط', 'is_demo' => $demo,
        ]);
    }

    /**
     * متجرٌ له حساب دخول — كحال كلّ متجرٍ أُنشئ من الشاشة.
     *
     * وبلا حسابٍ يُلزم `syncAccount` باسم مستخدمٍ وكلمة مرور (وهو صواب:
     * شركةٌ بلا حساب سجلٌّ لا يفتحه أحد)، فتُشوّش رسالتُه على ما نقيسه هنا.
     */
    private function shopWithOwner(string $name = 'متجري'): Business
    {
        $shop = $this->shop(name: $name);
        MerchantAccount::create($shop, 'owner'.$shop->id, 'password123');

        return $shop;
    }
    /* ═══════════ الأبوابُ الثلاثة ═══════════ */

    public function test_the_last_operator_cannot_demote_himself(): void
    {
        $op = $this->operator();
        $shop = $this->shop();

        $this->edit($op, $op, ['role' => 'cashier', 'business_id' => $shop->id])
            ->assertSessionHasErrors('role');

        $this->assertSame('super_admin', $op->fresh()->role);
        $this->assertSame(1, User::where('role', 'super_admin')->count());
    }
    public function test_a_suspended_operator_does_not_count_as_a_way_in(): void
    {
        /*
         * وهذا موضعُ الدقّة: الحارسُ القديم كان يعدّ الصفوف بالدور وحده.
         * فمديرٌ موقوفٌ باقٍ يجعل العدّ اثنين، ويُسمح بتنزيل الوحيد النشط —
         * ولا يبقى من يدخل، ولا من يرفع الإيقاف عن الموقوف.
         */
        $active = $this->operator();
        $this->operator('q@eventplusapp.om', status: 'موقوف');
        $shop = $this->shop();

        $this->assertSame(2, User::where('role', 'super_admin')->count());

        $this->edit($active, $active, ['role' => 'cashier', 'business_id' => $shop->id])
            ->assertSessionHasErrors('role');

        $this->assertSame('super_admin', $active->fresh()->role);
    }

    public function test_with_a_second_operator_the_door_opens(): void
    {
        // والحارسُ لا يمنع ما ليس خطرًا: مديران، فينزل أحدهما
        $op = $this->operator();
        $other = $this->operator('q@eventplusapp.om');
        $shop = $this->shop();

        $this->edit($op, $other, ['role' => 'manager', 'business_id' => $shop->id])
            ->assertSessionHasNoErrors();

        $this->assertSame('manager', $other->fresh()->role);
    }

    public function test_a_merchant_account_is_not_touched_by_the_guard(): void
    {
        $op = $this->operator();
        $shop = $this->shop();
        $clerk = User::create([
            'business_id' => $shop->id, 'name' => 'كاشير', 'email' => 'c@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->edit($op, $clerk, ['role' => 'manager'])->assertSessionHasNoErrors();
        $this->assertSame('manager', $clerk->fresh()->role);
    }

    public function test_the_guard_costs_nothing_on_a_merchant_account(): void
    {
        /*
         * والفحصُ الأوّل في `lastWayIntoThePanel` ليس زينةً: بدونه يُعدّ
         * مدراءُ المنصّة في كلّ تعديلِ حسابٍ وكلّ إيقافٍ وكلّ حذف — استعلامٌ
         * على كلّ باب لسؤالٍ جوابُه معروفٌ سلفًا لغير مدير المنصّة.
         */
        $op = $this->operator();
        $shop = $this->shop();
        $clerk = User::create([
            'business_id' => $shop->id, 'name' => 'كاشير', 'email' => 'c@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $counted = 0;
        DB::listen(function ($q) use (&$counted) {
            if (str_contains($q->sql, 'super_admin') || in_array('super_admin', $q->bindings, true)) {
                $counted++;
            }
        });

        $this->edit($op, $clerk, ['role' => 'manager'])->assertSessionHasNoErrors();

        $this->assertSame(0, $counted, 'عُدّ مدراءُ المنصّة لتعديل حساب تاجر');
    }
    public function test_the_list_holds_every_status_the_system_itself_writes(): void
    {
        /*
         * ولا تُقرأ القائمةُ من نفسها: اختبارٌ يدور على `STATUSES` يمرّ وإن
         * ضاقت — يضيق معها. والقياسُ على ما يكتبه النظام بيده:
         * `destroy` يكتب «معطل»، و`activate` يكتب «منتهي» أو «نشط» —
         * وكلُّها تلتفّ حول التحقّق. فحالةٌ يكتبها النظام ولا تقبلها الشاشة
         * تعني شركةً لا يستطيع المشغّل حفظَ أيّ تعديلٍ عليها.
         */
        foreach (['نشط', 'منتهي', 'معطل'] as $written) {
            $this->assertContains($written, PageController::STATUSES, "«{$written}» يكتبه النظام ولا تقبله الشاشة");
        }
    }
    public function test_a_status_outside_the_list_would_be_a_silent_ghost(): void
    {
        /*
         * ولماذا يُحرس أصلًا: حالةٌ مجهولةٌ لا تُحجب ولا تُعدّ — متجرٌ يعمل
         * ولا يظهر في «الشركات النشطة» ولا يوقفه شيء.
         */
        $shop = $this->shop();
        $shop->forceFill(['status' => 'شبح'])->save();

        $owner = User::create([
            'business_id' => $shop->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->assertNull(Tenancy::blockReason($owner), 'المجهول لا يَحجب');
        $this->assertSame(0, Business::real()->where('status', 'نشط')->count(), 'ولا يُعدّ');
    }
}
