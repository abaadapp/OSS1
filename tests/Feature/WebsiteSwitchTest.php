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
    /* ----------------------- وما يُديره المفتاح ----------------------- */
    /* --------------------------- الميّت يبقى ميّتًا --------------------------- */
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
