<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الربط والتفعيل — الخطوة التي تسبق كلَّ ما في شاشة إشعارات واتساب.
 *
 * وكانت الشاشة تفتح على سطرٍ أخضر يقول «مفعّل» بشرطين اثنين، ثمّ تحته مقابضُ
 * الأحداث. فيقرأ التاجر «مفعّل» ويُشعل ما يريد ويمضي — **والمُرسِل يمتنع
 * لسببٍ ثالثٍ لا تعرضه الشاشة**: باقةٌ لا تشمل واتساب، أو وصلةٌ لا تعمل.
 * فينتظر رسائل لا تخرج، ولا شيء يقول له لماذا.
 *
 * وأخطرُ ما يحرسه هذا الملفّ أنّ **«جاهز» لا تُقال إلا حين يقبل المُرسِل**:
 * الحالُ يُشتقّ من `blockReason` و`WhatsAppConnections::resolve` نفسيهما، فلا
 * يفترق ما على الشاشة عمّا يفعله النظام.
 */
class WhatsAppReadinessTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;
    private function step(string $key): array
    {
        return collect($this->readiness()['steps'])->firstWhere('key', $key) ?? [];
    }
    public function test_everything_open_is_ready(): void
    {
        $this->assertTrue($this->readiness()['ready']);

        foreach ($this->readiness()['steps'] as $step) {
            $this->assertTrue($step['done'], "خطوة «{$step['key']}» غير مكتملة وكلُّ شيء مفتوح");
        }
    }

    /* ========================= الخطوات بأسمائها ========================= */

    public function test_a_plan_that_does_not_sell_whatsapp_is_named_as_its_own_step(): void
    {
        /*
         * وهذا هو السبب الثالث الذي لم تكن الشاشة تعرضه: الحساب مفعَّل
         * والمنصّة مفعّلة — والباقة لا تشمل واتساب. فيقرأ «مفعّل» وينتظر.
         */
        $this->onPlan([]);

        $this->assertFalse($this->readiness()['ready']);
        $this->assertFalse($this->step('plan')['done']);
        $this->assertNotNull($this->step('plan')['fix']);
    }
    public function test_the_steps_that_only_eventplus_can_open_are_marked_as_theirs(): void
    {
        /*
         * والفرق يُقال: خطوةٌ ينتظر فيها افنت بلس لا يُطلب منه إصلاحها، وخطوةٌ
         * بيده تُقال بصيغة الأمر. ولو خُلطتا لَبقي ينتظر ما عليه أن يفعله،
         * أو حاول ما لا يملكه.
         */
        foreach (['platform', 'account', 'plan'] as $key) {
            $this->assertTrue($this->step($key)['theirs'], "خطوة «{$key}» تُطلب من التاجر وهي ليست بيده");
        }
    }
    /* ========================== لا ضجيج ========================== */

    public function test_a_completed_step_carries_no_instruction(): void
    {
        // نصيحةٌ تحت خطوةٍ مكتملة ضجيجٌ يُخفي الخطوة التي تحتاج عملًا
        foreach ($this->readiness()['steps'] as $step) {
            if ($step['done']) {
                $this->assertNull($step['fix'], "خطوة مكتملة «{$step['key']}» تحمل تعليمات");
            }
        }
    }

    public function test_the_steps_come_in_the_order_they_are_done(): void
    {
        // المنصّة ثمّ الحساب ثمّ الباقة ثمّ الرقم — ولا يُقرأ ترتيبٌ غيره
        $keys = array_column($this->readiness()['steps'], 'key');

        $this->assertSame(['platform', 'account', 'plan', 'shared', 'templates'], $keys);
    }
}
