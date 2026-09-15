<?php

namespace Tests\Feature;

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
