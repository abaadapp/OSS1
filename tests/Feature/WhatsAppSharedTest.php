<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * الرقم المشترك: رقمٌ واحد لافنت بلس، وحصّةٌ مستقلّة لكلّ محلّ.
 *
 * وأخطر ما يُحرَس هنا شيئان: أنّ حصّة متجرٍ لا تُخصم من متجرٍ آخر وإن خرجت
 * الرسالتان من الرقم نفسه، وأنّ اسم محلّ الورد هو ما يقرؤه الزبون لا اسم
 * افنت بلس — الرقم رقمنا، والطلب طلبُه.
 */
class WhatsAppSharedTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $customer;
    /* ------------------------------- التهيئة ------------------------------- */

    private function platform(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => null, 'key' => $key], ['value' => $value]);
    }
    private function order(?Business $business = null, ?Customer $customer = null, array $extra = []): Order
    {
        $business ??= $this->business;
        $customer ??= $this->customer;

        return Order::create(array_merge([
            'business_id' => $business->id,
            'branch_id' => Branch::where('business_id', $business->id)->value('id'),
            'customer_id' => $customer->id,
            'number' => 'INV-'.uniqid(),
            'status' => OrderStatus::PENDING,
            'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
        ], $extra));
    }

    private function fakeMeta(string $id = 'wamid.TEST'): void
    {
        Http::fake([
            '*/messages' => Http::response(['messages' => [['id' => $id]]], 200),
        ]);
    }

    /* ----------------------------- الأحداث الأربعة ----------------------------- */
    /* ------------------------------ اسم المحلّ ------------------------------ */

    /**
     * الرسالة تحمل اسم محلّ الورد لا اسم افنت بلس.
     *
     * الرقم رقم افنت بلس، فإن لم يقل النصّ اسم المحلّ قرأها الزبون رسالةً من
     * جهةٍ لا يعرفها عن طلبٍ لا يذكره — فلا يثق بها، أو يردّ على رقمٍ لا
     * يقرأ ردَّه أحد.
     */
    public function test_the_message_carries_the_flower_shop_name_not_eventplus(): void
    {
        $this->fakeMeta();

        $order = $this->order();
        $order->update(['status' => OrderStatus::READY]);

        $sent = null;
        Http::assertSent(function ($request) use (&$sent) {
            $sent = $request->data();

            return true;
        });

        $params = $sent['template']['components'][0]['parameters'];
        $this->assertSame('محل ورد', $params[0]['text']);
        $this->assertSame($order->number, $params[1]['text']);
    }
    /* -------------------------------- الأمان -------------------------------- */
}
