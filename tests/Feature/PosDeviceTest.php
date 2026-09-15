<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الجهاز يعرف المتجر والفرع، والموظف يدخل ببريده وكلمة مروره.
 *
 * كان الجهاز يُعرَف بكوكي تحمل رقم المتجر لا غير: بلا فرع، بلا سجلّ، بلا
 * إبطال. فجهاز الخوير وجهاز السيب متطابقان في نظر النظام، والفرع يأتي من
 * جلسة المتصفّح — أي من مبدّل الفروع في لوحة الإدارة. وتبديلٌ في تبويبٍ آخر
 * كان ينقل مبيعات فرعٍ إلى فرعٍ آخر بلا إنذار.
 *
 * ولم يكن شيء يمنع كاشير الخوير من الدخول على جهاز السيب: `users.branch` نصٌّ
 * حرّ لا يُفحص عند الدخول أصلًا.
 *
 * وكان إسناد الفرع يُفحص عند لوحة الأرقام. ثمّ رُفع الدخول بالرمز، فانتقل
 * الفحص إلى حارس الطلب (BindPosBranch) — وهذه الاختبارات تقيسه هناك.
 */
class PosDeviceTest extends TestCase
{
    use RefreshDatabase;

    private Business $a;

    private Business $b;

    private Branch $khuwair;

    private Branch $seeb;

    private Branch $branchB;

    private User $ownerA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Business::create(['name' => 'متجر أ', 'type' => 'عام', 'status' => 'نشط']);
        $this->b = Business::create(['name' => 'متجر ب', 'type' => 'عام', 'status' => 'نشط']);

        foreach ([$this->a, $this->b] as $biz) {
            JobTitle::create(['business_id' => $biz->id, 'name' => 'كاشير', 'role' => 'cashier']);
        }

        $this->khuwair = Branch::create(['business_id' => $this->a->id, 'name' => 'الخوير']);
        $this->seeb = Branch::create(['business_id' => $this->a->id, 'name' => 'السيب']);
        $this->branchB = Branch::create(['business_id' => $this->b->id, 'name' => 'فرع ب']);

        $this->ownerA = User::create([
            'business_id' => $this->a->id, 'name' => 'مالك أ', 'email' => 'a@eventplus.om',
            'password' => 'password', 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /* ------------------------------ أدوات ------------------------------ */

    private function cashier(Business $biz, string $email, array $branches = []): User
    {
        $u = User::create([
            'business_id' => $biz->id, 'name' => 'كاشير '.$email, 'email' => $email,
            'password' => 'password', 'role' => 'cashier', 'job_title' => 'كاشير',
            'status' => 'نشط',
        ]);

        if ($branches) {
            $u->branches()->sync($branches);
        }

        return $u;
    }
    /* ------------------------- لا باب إلا واحد ------------------------- */

    /** شاشة الرمز ومسارها رُفعا من النظام */
    public function test_the_pin_door_no_longer_exists(): void
    {
        $this->get('/pin-login')->assertNotFound();
        $this->post('/pin-login', ['pin' => '1234'])->assertNotFound();
    }

    /* -------------------------- عزل المستأجرين -------------------------- */
    /* ---------------------------- إذن الفرع ---------------------------- */
    /* -------------------------- سياق الجلسة -------------------------- */
}
