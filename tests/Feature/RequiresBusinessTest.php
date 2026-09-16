<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لوحةُ الشركة لا تُفتح لمن لا شركةَ له.
 *
 * ═══ ولماذا يُردّ عند الباب لا في كلّ شاشة ═══
 *
 * `HandleInertiaRequests` لا يرسل `context` بلا `business_id`، والشاشاتُ
 * تقرأ `context!.currency` فتنهار قبل أن تُركَّب الواجهة. فمن يمرّ بلا
 * شركةٍ يرى شاشةً بيضاء لا رسالةً تقول له لماذا.
 *
 * وحسابٌ بلا شركةٍ ليس فرضًا: يُنشئه من يكتب صفًّا بيده في القاعدة، أو
 * تتركه هجرةٌ قديمة. فالحارسُ يبقى وإن لم تُنشئ الشاشاتُ مثلَه اليوم.
 *
 * ═══ ولماذا شركتان في التهيئة ═══
 *
 * شركةٌ واحدة تجعل الاختبار يمرّ بلا أن يعني شيئًا: لا فرقَ بين «شركتي»
 * و«أوّلِ شركةٍ في الجدول» حين تكون واحدة. والثانيةُ هي التي تجعل خلطَهما
 * ممكنًا لو سقط الحارس يومًا — فهي شرطُ صدقِ الاختبار لا زينةٌ فيه.
 */
class RequiresBusinessTest extends TestCase
{
    use RefreshDatabase;

    private Business $mine;

    protected function setUp(): void
    {
        parent::setUp();

        // الأولى في الجدول — وليست شركةَ المستخدم، عمدًا
        Business::create(['name' => 'شركةٌ أخرى', 'status' => 'نشط']);
        $this->mine = Business::create(['name' => 'شركتي', 'status' => 'نشط']);
    }

    private function account(?int $businessId, string $email): User
    {
        return User::create([
            'business_id' => $businessId,
            'name' => 'مستخدم',
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'نشط',
        ]);
    }

    public function test_an_account_with_no_company_is_refused_at_the_door(): void
    {
        $orphan = $this->account(null, 'nobody@eventplus.om');

        $this->actingAs($orphan)->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_a_real_company_user_passes_through_untouched(): void
    {
        $owner = $this->account($this->mine->id, 'owner@eventplus.om');

        $this->actingAs($owner)->get(route('admin.dashboard'))->assertOk();
    }

    /** ولا يُردّ الزائرُ بـ403 — يُساق إلى الدخول، فالرفضُ ليس جوابَ من لم يُسأل بعد */
    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }
}
