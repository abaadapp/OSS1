<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * رسالةٌ لم تصل تُقال — لا تُكتب في جدولٍ لا يفتحه أحد.
 *
 * ═══ العطب ═══
 *
 * مراحلُ ربط واتساب أربعٌ، وكلُّها تقرأ **إعدادًا**: مفعَّلٌ في المنصّة،
 * ومفعَّلٌ لحسابك، وباقتُك تشمله، والرقمُ جاهز. ولا واحدةَ منها تقرأ
 * **نتيجة**.
 *
 * فحين حجبت Meta تطبيقَنا بقيت الأربعُ خضراء و«جاهز» فوقها، وكلُّ رسالةٍ
 * تخرج تُردّ وتُكتب `failed` في `whatsapp_messages` — جدولٌ لا تفتحه شاشة.
 * فباع التاجر، ولم يصل زبونَه شيء، ونظر إلى لوحته فطمأنته.
 *
 * **ولم يعرف أنّ شيئًا انكسر حتّى سأل.** وطمأنينةٌ كاذبة أسوأ من تحذيرٍ
 * كاذب: الثاني يُزعج، والأوّل يُنيم.
 */
class ASilentFailureIsWorseThanALoudOneTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;
    /* ══════════════ متى يُنذَر ══════════════ */
    /* ══════════════ على مَن العطب ══════════════ */
    /* ══════════════ أين يُقرأ ══════════════ */
    /**
     * ويصل الجرس — فمن لا يفتح شاشة واتساب يعرف.
     *
     * وهو الفارقُ العمليّ: التاجر يفتح لوحته كلَّ يوم ولا يفتح شاشة الربط
     * إلّا حين يشكّ. ولو بقي الخبرُ هناك لَما وصله حتّى يسأل.
     */
    public function test_the_bell_carries_it(): void
    {
        $this->message('failed', '200', 'API access blocked.');

        $this->actingAs($this->owner);
        $keys = collect(Demo::notifications())->pluck('key');

        $this->assertTrue($keys->contains('wa-delivery'), 'لم يصل الجرسَ خبرُ رسالةٍ لم تصل');
    }

    /** ولا يُزعج الجرسُ متجرًا رسائلُه تصل */
    public function test_the_bell_stays_quiet_when_all_is_well(): void
    {
        $this->message('delivered');

        $this->actingAs($this->owner);
        $keys = collect(Demo::notifications())->pluck('key');

        $this->assertFalse($keys->contains('wa-delivery'));
    }
}
