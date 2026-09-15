<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\PosDevice;
use App\Models\User;
use App\Support\PosTerminal;
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

    /** يفعّل جهازًا مباشرةً ويُرجع [الجهاز، الرمز الخام] */
    private function device(Branch $branch, string $name = 'كاشير 01'): array
    {
        $raw = Str::random(64);
        $device = PosDevice::create([
            'business_id' => $branch->business_id,
            'branch_id' => $branch->id,
            'name' => $name,
            'token_hash' => hash('sha256', $raw),
            'status' => PosDevice::ACTIVE,
            'activated_at' => now(),
        ]);

        return [$device, $raw];
    }

    private function onDevice(PosDevice $device, string $raw): self
    {
        return $this->withCookie(PosTerminal::COOKIE, $device->id.'|'.$raw);
    }
    /* ------------------------- لا باب إلا واحد ------------------------- */

    /** شاشة الرمز ومسارها رُفعا من النظام */
    public function test_the_pin_door_no_longer_exists(): void
    {
        $this->get('/pin-login')->assertNotFound();
        $this->post('/pin-login', ['pin' => '1234'])->assertNotFound();
    }

    /* -------------------------- عزل المستأجرين -------------------------- */

    /** موظف من متجر أ لا يبيع على جهاز متجر ب — يُخرَج من الجلسة */
    public function test_an_employee_cannot_use_another_tenants_device(): void
    {
        $cashier = $this->cashier($this->a, 'a1@eventplus.om');
        [$devB, $rawB] = $this->device($this->branchB);

        $this->enterPos($devB, $rawB, $cashier)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /* ---------------------------- إذن الفرع ---------------------------- */

    /** ممنوعٌ من فرعٍ لا يبيع على جهازه ولو دخل ببريدٍ صحيح */
    public function test_an_employee_not_assigned_to_the_branch_is_refused(): void
    {
        $cashier = $this->cashier($this->a, 'k@eventplus.om', [$this->khuwair->id]);
        [$dev, $raw] = $this->device($this->seeb);

        $this->enterPos($dev, $raw, $cashier)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /** ومن له فرعان يعمل على جهازي الفرعين */
    public function test_an_employee_of_two_branches_works_on_both_devices(): void
    {
        $cashier = $this->cashier($this->a, 'k2@eventplus.om', [$this->khuwair->id, $this->seeb->id]);
        [$d1, $r1] = $this->device($this->khuwair);
        [$d2, $r2] = $this->device($this->seeb, 'كاشير 02');

        $this->enterPos($d1, $r1, $cashier);
        $this->assertAuthenticated();
        $this->assertSame($this->khuwair->id, session('current_branch'));

        $this->enterPos($d2, $r2, $cashier);
        $this->assertAuthenticated();
        $this->assertSame($this->seeb->id, session('current_branch'));
    }

    /** وبلا تحديد يعمل في كل فروع متجره — وإلا أُقفل كل كاشير قائم يوم النشر */
    public function test_an_employee_without_branches_works_everywhere_in_their_tenant(): void
    {
        $cashier = $this->cashier($this->a, 'k3@eventplus.om');
        [$dev, $raw] = $this->device($this->seeb);

        $this->enterPos($dev, $raw, $cashier);
        $this->assertAuthenticated();
        $this->assertSame($this->seeb->id, session('current_branch'));
    }
    /* -------------------------- سياق الجلسة -------------------------- */

    /** الجلسة تحمل الموظف والفرع والجهاز والمتجر الصحيح */
    public function test_the_session_carries_the_right_context(): void
    {
        $cashier = $this->cashier($this->a, 'k8@eventplus.om', [$this->seeb->id]);
        [$dev, $raw] = $this->device($this->seeb);

        $this->enterPos($dev, $raw, $cashier);

        $this->assertSame($cashier->id, auth()->id());
        $this->assertSame($this->a->id, auth()->user()->business_id);
        $this->assertSame($this->seeb->id, session('current_branch'));
        $this->assertNotNull($dev->fresh()->last_seen_at);
    }
}
