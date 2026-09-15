<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تفعيل الموقع الإلكتروني — ومفتاحٌ يُدير ما يقول إنّه يديره.
 *
 * كان الإطفاء الوحيد أن يمحو التاجر نطاقه: من أراد أن يُخفي موقعه شهرًا —
 * يُعاد بناؤه، أو انتهى حجزُه — خسر ما كتبه ثمّ عاد يبحث عنه. وكانت في
 * الإعدادات بطاقتان تَعِدان بموقع ولا تحملان مفتاحًا: «إعدادات الدومين»
 * توعد بـ«نشره للزوّار»، و«إعدادات الموقع» توعد بـ«ما يراه زائر موقعك»
 * وتفتح على رافع شعار.
 *
 * وما يفحصه هذا الملفّ ليس أنّ المفتاح يُحفظ، بل أنّ **ما وعدت به البطاقة
 * تحته يقع**: زرُّ الترويسة يختفي، والفحص يقول «مُطفأ» لا «بلا نطاق»،
 * والنطاق يبقى محفوظًا فلا يُعاد كتابتُه عند التشغيل.
 */
class WebsiteSwitchTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'صاحب النشاط', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }
    /**
     * والإطفاء يُخزَّن '0' لا سلسلةً فارغة.
     *
     * `false` يُكتب فارغًا، و`group` تعدّ الفارغةَ قصدًا لا غيابًا فتردّها كما
     * هي — ثمّ تُقارن بـ'1' فتكون خطأً بالمصادفة لا بالضبط. والمصادفة تنقلب.
     */
    public function test_switching_off_is_written_as_a_zero(): void
    {
        $this->save(['site_on' => false, 'site_domain' => 'mystore.om'])->assertSessionHasNoErrors();

        $this->assertSame('0', Setting::where('business_id', $this->business->id)
            ->where('key', 'site_on')->value('value'));
    }
    /* ----------------------- وما يُديره المفتاح ----------------------- */

    /** زرُّ «الموقع الإلكتروني» في الشريط العلوي */
    public function test_the_header_button_follows_the_switch(): void
    {
        $this->actingAs($this->owner);

        $this->save(['site_on' => true, 'site_domain' => 'mystore.om']);
        $this->assertSame('https://mystore.om', Demo::websiteUrl());

        $this->save(['site_on' => false, 'site_domain' => 'mystore.om']);
        $this->assertNull(Demo::websiteUrl(), 'الزرّ يفتح موقعًا أطفأه صاحبُه');
    }

    /** والشاشةُ تقرؤه من المصدر نفسه لا من عمودٍ ثانٍ */
    public function test_the_shared_context_carries_the_same_answer(): void
    {
        $this->save(['site_on' => false, 'site_domain' => 'mystore.om']);

        $props = $this->actingAs($this->owner)->get(route('admin.settings.index'))
            ->assertOk()->viewData('page')['props'];

        $this->assertNull($props['context']['website']);
    }
    /* --------------------------- الميّت يبقى ميّتًا --------------------------- */

    /**
     * و`site_enabled` القديم لا يُبعث.
     *
     * كان معناه «انشر متجري للزوّار» ولا متجرَ يُنشر، فرُفع. وصفوفُه قد تبقى
     * في قاعدة متجرٍ رفعها يومًا أو في نسخةٍ تُستعاد — فلو حمل المفتاحُ الجديد
     * اسمَه لَأطفأت قيمةٌ قديمة موقعًا لم يطلب صاحبُه إطفاءه.
     */
    public function test_the_old_dead_key_never_governs_the_new_switch(): void
    {
        $this->actingAs($this->owner);

        Setting::create([
            'business_id' => $this->business->id, 'key' => 'site_enabled', 'value' => '0',
        ]);
        $this->save(['site_on' => true, 'site_domain' => 'mystore.om']);

        $this->assertSame('https://mystore.om', Demo::websiteUrl(), 'مفتاحٌ ميّت أطفأ موقعًا مفعَّلًا');
    }

    /** ومن ضبط نطاقه قبل المفتاح يبقى زرُّه يعمل بلا أن يفتح شيئًا */
    public function test_a_domain_set_before_the_switch_existed_keeps_working(): void
    {
        Setting::create([
            'business_id' => $this->business->id, 'key' => 'site_domain', 'value' => 'old.om',
        ]);

        $this->actingAs($this->owner);

        $this->assertSame('https://old.om', Demo::websiteUrl());
    }
}
