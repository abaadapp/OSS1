<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «كم مشتركًا عندي؟» — حقيقةٌ واحدة من مصدرٍ واحد.
 *
 * كان الجواب مكتوبًا في مكانين: `businesses.status` و`ends_at` وهما ما
 * يُقرّر فعلًا دخول التاجر ومنعه، وجدول `subscriptions` وهو ما تقرؤه لوحة
 * المنصّة. والثاني لا يُكتب إلا بضغطة «تجديد» يتذكّرها إنسان — فالتاجر
 * الذي أُضيف ودفع ولم يُضغط له الزرّ كان يظهر في اللوحة صفرًا.
 *
 * والتجربة ليست اشتراكًا: من دخل بأربعة عشر يومًا مجّانًا لا هو إيراد ولا
 * هو مدين.
 */
class PlatformSubscriberCountTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private Plan $plan;
    private function business(array $attrs = []): Business
    {
        return Business::create(array_merge([
            'name' => 'متجر', 'status' => 'نشط', 'plan_id' => $this->plan->id,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(),
        ], $attrs));
    }

    /** البطاقة كما تصل الشاشة */
    private function card(string $label): ?string
    {
        foreach (Demo::superStats() as $c) {
            if ($c['label'] === __($label)) {
                return (string) $c['value'];
            }
        }

        return null;
    }
    public function test_a_merchant_in_his_trial_is_not_counted_as_a_subscriber(): void
    {
        $this->business(['starts_at' => now(), 'ends_at' => now()->addDays(14)]);

        $this->assertSame('0', $this->card('المشتركون'), 'المجرّب حُسب مشتركًا');
        $this->assertSame('1', $this->card('في التجربة'));
    }
}
