<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بطاقاتُ لوحة المنصّة تُقرأ صفًّا واحدًا.
 *
 * كانت اثنتان منها تخرجان عن الشكل: «الفاقد هذا الشهر» تكتب رقمين في خانة
 * القيمة («٢ · ١٥٪») ورقمًا ثالثًا في خانة الاتجاه هو عددٌ لا نسبة، و«في
 * التجربة» بلا اتجاهٍ أصلًا — سطرٌ ناقصٌ تحتها يجعلها أقصر من جاراتها.
 *
 * وشكلُ البطاقة ليس زينة: من يقرأ تسع بطاقاتٍ في نظرةٍ واحدة يقرأ مواضعَها
 * لا كلماتِها. فرقمٌ في موضع النسبة يُقرأ نسبةً — و«−٢» تُقرأ «ناقص ٢٪».
 */
class PlatformCardsReadAsOneRowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // البطاقات تُسمّى بـ__() — والمفتاح هو النصّ العربي
        app()->setLocale('ar');
    }

    /** @return array<string, array<string, mixed>> */
    private function cards(): array
    {
        return collect(Demo::superStats())->keyBy('label')->all();
    }

    private function shop(string $name, array $over = []): Business
    {
        return Business::create(array_merge([
            'name' => $name, 'type' => 'محل ورود', 'status' => 'نشط',
            'starts_at' => now()->subYear(), 'ends_at' => now()->addYear(),
        ], $over));
    }

    /* ------------------------------ الشكل ------------------------------ */

    public function test_every_card_carries_a_percentage_trend(): void
    {
        $this->shop('محل');

        foreach ($this->cards() as $label => $card) {
            $this->assertArrayHasKey('trend', $card, $label);
            $this->assertNotNull($card['trend'], 'البطاقة «'.$label.'» بلا اتجاه');
            $this->assertStringEndsWith('%', (string) $card['trend'], 'اتجاه «'.$label.'» ليس نسبة');
        }
    }

    /** والقيمة رقمٌ واحد لا رقمان في خانةٍ واحدة */
    public function test_no_card_stuffs_two_numbers_into_its_value(): void
    {
        $this->shop('محل');

        foreach ($this->cards() as $label => $card) {
            $this->assertStringNotContainsString('·', (string) $card['value'], 'قيمة «'.$label.'» رقمان');
        }
    }

    /* ------------------------------ المعنى ------------------------------ */

    /** ونسبةُ الفاقد لم تضع: انتقلت من القيمة إلى موضعها */
    public function test_the_churn_rate_moved_into_the_trend_and_the_count_into_the_value(): void
    {
        // أربعةٌ قديمة، خرج منها واحد هذا الشهر = ٢٥٪
        foreach (range(1, 4) as $i) {
            $shop = $this->shop('محل '.$i);
            $shop->timestamps = false;
            $shop->updated_at = now()->subYear();
            $shop->save();
        }

        $gone = Business::orderBy('id')->first();
        $gone->update(['status' => 'معطل']);

        $card = $this->cards()['الفاقد هذا الشهر'];

        $this->assertSame('1', $card['value']);
        $this->assertSame('−25%', $card['trend']);
        $this->assertFalse($card['up'], 'خروجُ متجرٍ سهمٌ أخضر');
        $this->assertSame('danger', $card['color']);
    }

    /** ولا فاقدَ: سهمٌ أخضر وصفرٌ بالمئة */
    public function test_losing_nobody_is_green(): void
    {
        $this->shop('محل');

        $card = $this->cards()['الفاقد هذا الشهر'];

        $this->assertSame('0', $card['value']);
        $this->assertSame('0%', $card['trend']);
        $this->assertTrue($card['up']);
        $this->assertSame('success', $card['color']);
    }
    public function test_a_growing_trial_pool_is_green(): void
    {
        $this->shop('قديم', ['starts_at' => now()->subYear()]);
        $this->shop('جديد هذا الشهر', ['starts_at' => now()->startOfMonth()->addDay()]);

        $card = $this->cards()['في التجربة'];

        $this->assertSame('2', $card['value']);
        $this->assertTrue($card['up']);
    }
}
