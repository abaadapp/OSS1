<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * الخريطةُ تُجيب عن الفرع الذي باع — لا عن فرعٍ آخر، ولا بنصفِ خبر.
 *
 * ═══ أربعةُ عيوبٍ وُجدت في فحصٍ، وهذا حارسُها ═══
 *
 * **الأوّل — رمزٌ يُطبع لفرعٍ لم يبِع.** كان الإيصال يقرأ فرعَه، فإن لم
 * يُقرأ — حُذف الفرعُ وبقي رقمُه في طلباته، أو وصل رقمٌ ليس للمتجر — سقط
 * إلى **الفرع الأوّل** صامتًا. فيمسح زبونٌ اشترى من المعبيلة رمزًا فيكتب
 * تقييمًا يُحسب للخوض. وهو عطبٌ لا يراه صاحبُه أبدًا: لا يمسح إيصالاته
 * بنفسه، والعدّان يفسدان معًا.
 *
 * **الثاني — علامةُ تمامٍ على عملٍ لم يتمّ.** خطوةُ «فروعك مربوطة» كانت
 * تكتمل بربط **فرعٍ واحد**. فمتجرٌ بثلاثةٍ يربط أوّلَها فيرى الخطوةَ خضراء
 * ويُغلق الشاشة — وإيصالُ الفرعين الآخرين يخرج بلا رمزٍ إلى الأبد.
 *
 * **الثالث — سببٌ يُبتلع.** زرُّ «حدِّث الآن» كان يقول عن كلّ إخفاق «تعذّر
 * الاتّصال بـ Google». وGoogle تقول ما يُصلَح: فعِّل الواجهة، اربط الفوترة،
 * وسِّع قيدَ المفتاح. فكان التاجر يُعيد الضغط على رفضٍ لا يزول بالتكرار.
 *
 * **الرابع — رقمان لمحلٍّ واحد.** المعدّلُ يُعرض في بطاقة التقييمات من
 * ذاكرةِ ستِّ ساعات، وفي صفّ الفرع من عمودٍ يُزامَن كلَّ اثنتي عشرة. ومصدران
 * بساعتين يفترقان — فيقرأ التاجر رقمين مختلفين عن محلِّه في لحظةٍ واحدة.
 */
class TheMapAnswersForTheBranchThatSoldTest extends TestCase
{
    use RefreshDatabase;

    private const A = 'ChIJN1t_tDeuEmsRUsoyG83frY4';

    private const B = 'ChIJP3Sa8ziYEmsRUKgyFmh9AQM';

    private Business $shop;

    private Branch $khoud;

    private Branch $mawaleh;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Cache::clear();
        Http::preventStrayRequests();

        $this->shop = Business::create([
            'name' => 'ورد افنت بلس', 'type' => 'محل ورود', 'status' => 'نشط', 'phone' => '91234567',
        ]);
        $this->khoud = Branch::create(['business_id' => $this->shop->id, 'name' => 'فرع الخوض']);
        $this->mawaleh = Branch::create(['business_id' => $this->shop->id, 'name' => 'فرع المعبيلة']);
        JobTitle::create(['business_id' => $this->shop->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'map@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }
    private function fakeDetails(?float $rating = 4.2, int $count = 300): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response([
            'id' => self::A,
            'displayName' => ['text' => 'ورد افنت بلس — الخوض'],
            'rating' => $rating,
            'userRatingCount' => $count,
            'googleMapsUri' => 'https://maps.google.com/?cid=111',
        ], 200)]);
    }

    private function review(string $placeId): string
    {
        return 'https://search.google.com/local/writereview?placeid='.$placeId;
    }

    /* ═══════════════════ ١ · الرمز يتبع بائعَه ═══════════════════ */
    /* ═══════════════════ ٢ · لا علامةَ تمامٍ على ناقص ═══════════════════ */

    /** فرعٌ من فرعين مربوطٌ — والخطوةُ ليست تامّة */
    public function test_one_linked_branch_does_not_complete_the_step(): void
    {
        $this->platformKey();
        $this->linked($this->khoud, self::A);

        $step = $this->placeStep();

        $this->assertFalse($step['done'], 'اكتملت خطوةُ الفروع وفرعٌ منها بلا ربط');
    }

    /** وتُسمّي الناقصَ بالاسم — لا «ابحث عن فرعك» لمن له ثلاثة */
    public function test_the_step_names_the_unlinked_branch(): void
    {
        $this->platformKey();
        $this->linked($this->khoud, self::A);

        $this->assertStringContainsString('فرع المعبيلة', (string) $this->placeStep()['fix']);
        $this->assertStringNotContainsString('فرع الخوض', (string) $this->placeStep()['fix']);
    }

    /** وتكتمل حين تُربط كلُّها */
    public function test_the_step_completes_when_every_branch_is_linked(): void
    {
        $this->platformKey();
        $this->linked($this->khoud, self::A);
        $this->linked($this->mawaleh, self::B);

        $this->assertTrue($this->placeStep()['done']);
    }

    /**
     * ومتجرٌ بلا فروعٍ لا تكتمل خطوتُه — ويُقال له ماذا يفعل.
     *
     * «كلُّها مربوطة» عن صفرٍ من صفرٍ صحيحةٌ منطقًا وكاذبةٌ معنًى.
     */
    public function test_a_shop_with_no_branches_is_not_complete(): void
    {
        $this->platformKey();
        Branch::where('business_id', $this->shop->id)->delete();

        $step = $this->placeStep();

        $this->assertFalse($step['done'], 'اكتملت خطوةُ الفروع لمتجرٍ بلا فروع');
        $this->assertStringContainsString('أضِف فرعًا', (string) $step['fix']);
    }
    /* ═══════════════════ ٤ · رقمٌ واحدٌ لا رقمان ═══════════════════ */
}
