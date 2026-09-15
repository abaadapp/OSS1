<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ربط خرائط Google — صفحةٌ تربط، لا زرٌّ يخرج من النظام.
 *
 * وكان زرًّا في شاشة التقييمات يفتح `business.google.com` في تبويبٍ خارجيّ:
 * اسمُه «ربط» ولا يربط شيئًا — يُخرج التاجر من لوحته ولا يعود بمعرّفٍ ولا
 * يُحفظ شيء.
 *
 * وأخطرُ ما يحرسه هذا الملفّ أنّ **معرّفًا خاطئًا لا يُخطئ أحدًا في الشاشة**:
 * الحفظ ينجح، والرمز يُطبع، ويمسحه الزبون فيفتح ملفَّ محلٍّ آخر — ولا يرى
 * صاحبُه ذلك أبدًا لأنّه لا يمسح إيصاله بنفسه.
 */
class GoogleReviewLinkTest extends TestCase
{
    use RefreshDatabase;

    private const PLACE = 'ChIJN1t_tDeuEmsRUsoyG83frY4';

    private Business $business;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }
    public function test_the_reviews_screen_now_leads_into_the_system(): void
    {
        /*
         * زرٌّ اسمُه «ربط» يفتح موقعًا خارجيًّا لا يربط شيئًا: يُخرج التاجر
         * من لوحته ويتركه هناك.
         */
        $source = file_get_contents(resource_path('js/Pages/Admin/Marketing/Reviews.tsx'));

        // رابطًا لا ذِكرًا: الكلمة تَرِد في تعليقٍ يشرح ما كان
        $this->assertStringNotContainsString('href="https://business.google.com', $source, 'الزرّ ما زال يخرج من النظام');
        $this->assertStringContainsString('admin.integrations.google', $source, 'الزرّ لا يقود إلى صفحة الربط');
    }
    /** والنصّان موجودان في الشاشة — فالحقل بلا نصَّين مقبضٌ لا يُدير شيئًا */
    public function test_the_card_carries_both_wordings(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Admin/Integrations/Google.tsx'));

        $this->assertStringContainsString('platformKey', $source, 'الشاشة لا تقرأ حال مفتاح المنصّة');
        $this->assertStringContainsString('مطلوب — لا تُقرأ تقييماتك قبل أن تلصق مفتاحك', $source);
        $this->assertStringContainsString('اختياريّ — تُقرأ تقييماتك بمفتاح افنت بلس', $source);
    }
    /**
     * والعنوانُ يُقرأ من الإعداد، لا رقمًا محفورًا في الواجهة.
     *
     * الخادمُ يُبدَّل يومًا، ورقمٌ مكتوبٌ في شاشةٍ يبقى يُعرض بعد أن يصير
     * خطأً — فيقيّد التاجر مفتاحه بعنوانٍ لا نناديه منه.
     */
    public function test_no_server_address_is_carved_into_the_screen(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Admin/Integrations/Google.tsx'));

        $this->assertSame(
            0,
            preg_match('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', $source),
            'عنوانٌ محفورٌ في الشاشة — يبقى يُعرض بعد أن يصير خطأً',
        );
        $this->assertStringContainsString('serverIp', $source, 'الشاشة لا تقرأ العنوان من الخادم');
    }

    /** والدليلُ يحمل ما لا يُخمَّن: الأسماءَ كما تظهر عند Google، وتحذيرَ القديمة */
    public function test_the_guide_names_what_google_shows_him(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Admin/Integrations/Google.tsx'));

        foreach ([
            'console.cloud.google.com',
            'Places API (New)',
            'APIs and services → Credentials → Create credentials → API key',
            'Application restrictions → IP addresses',
            'API restrictions → Restrict key → Places API (New)',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source, "الدليل لا يذكر: $needle");
        }

        // وتحذيرُ القديمة: أشيعُ خطأٍ يُرجع 403 بلا سببٍ يُفهم
        $this->assertStringContainsString('وهي غير «Places API» القديمة', $source, 'لا تحذير من الواجهة القديمة');
    }
}
