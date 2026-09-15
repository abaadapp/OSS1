<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إشعارات ميتا — بابٌ عامّ على الإنترنت.
 *
 * وأخطر ما فيه أنّه لا جلسةَ له: من يعرف عنوانه يستطيع أن يُرسل إليه ما شاء.
 * فالحارس توقيعٌ، والمتجر يُستنتج ممّا ربطناه نحن، ولا يُقرأ من الحمولة
 * معرّفُ متجرٍ ولا معرّف طلب.
 *
 * ولا يكتب هذا الباب في `orders` حرفًا: «سُلّمت الرسالة» ليست «سُلّم الورد».
 */
class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    private WhatsAppConnection $shared;

    private WhatsAppMessage $message;

    private Order $order;
    private function statusPayload(string $status, string $id = 'wamid.ONE', string $phoneId = 'EVENTPLUS-PN'): array
    {
        return ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => $phoneId],
            'statuses' => [['id' => $id, 'status' => $status, 'timestamp' => (string) now()->timestamp]],
        ]]]]]];
    }
    /* -------------------------- حال الرسالة ≠ حال الطلب -------------------------- */

    /**
     * «سُلّمت الرسالة» لا تُقفل الطلب.
     *
     * ولو فعلت لَأقفل إشعارٌ من ميتا طلبًا لم يخرج أحدٌ لتسليمه — ولا يُكتشف
     * ذلك إلا حين يتّصل الزبون سائلًا أين وردُه.
     */
    public function test_a_delivered_message_never_moves_the_order(): void
    {
        $this->hook($this->statusPayload('delivered'))->assertOk();
        $this->hook($this->statusPayload('read'))->assertOk();

        $this->assertSame(OrderStatus::READY, $this->order->fresh()->status);
    }

    /* -------------------------------- العزل -------------------------------- */
    /** وحمولةٌ مشوّهة لا تُسقط الباب */
    public function test_a_malformed_payload_is_answered_calmly(): void
    {
        $this->hook(['entry' => 'not-an-array'])->assertOk();
        $this->hook([])->assertOk();
    }
}
