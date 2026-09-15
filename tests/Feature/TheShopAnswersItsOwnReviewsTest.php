<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchGooglePlace;
use App\Models\Business;
use App\Models\GoogleBusinessAccount;
use App\Models\GoogleBusinessReview;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\Demo;
use App\Support\GoogleBusiness;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * المتجرُ يقرأ تقييماته ويردّ عليها — بإذنِ صاحبه.
 *
 * ═══ أثقلُ ما هنا ═══
 *
 * **ردٌّ لم تقبله Google لا يُكتب عندنا.** ولو كُتب لَرأى التاجر ردَّه معروضًا
 * وقد رُدّ عندهم: يظنّ أنّه أجاب زبونًا لم يصله شيء، ولا يفتح ملفَّه ليتحقّق.
 *
 * ═══ والثاني ═══
 *
 * الرمزان معمَّيان ولا يخرجان إلى شاشةٍ ولا سجلّ. من نسخ القاعدة لا ينسخ
 * معها إذنًا يردّ باسم التاجر على زبائنه.
 */
class TheShopAnswersItsOwnReviewsTest extends TestCase
{
    use RefreshDatabase;

    private const LOCATION = 'locations/456';

    private const ACCOUNT = 'accounts/123';

    private Business $shop;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Http::preventStrayRequests();

        config([
            'services.google_business.client_id' => 'test-client-id',
            'services.google_business.client_secret' => 'test-client-secret',
            'services.google_business.redirect' => 'https://app.eventplusapp.om/admin/integrations/google-business/callback',
        ]);

        $this->shop = Business::create(['name' => 'ورد افنت بلس', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'فرع الخوض']);
        JobTitle::create(['business_id' => $this->shop->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    /* ═══════════════ أدوات ═══════════════ */

    private function connected(?string $refresh = 'refresh-token-value'): GoogleBusinessAccount
    {
        return GoogleBusinessAccount::create([
            'business_id' => $this->shop->id,
            'account_name' => self::ACCOUNT,
            'account_email' => 'owner@gmail.com',
            'access_token' => 'access-token-value',
            'refresh_token' => $refresh,
            'token_expires_at' => now()->addHour(),
            'scopes' => GoogleBusiness::SCOPE,
            'linked_at' => now(),
        ]);
    }

    private function mapped(): BranchGooglePlace
    {
        return BranchGooglePlace::create([
            'branch_id' => $this->branch->id,
            'place_id' => 'ChIJPLACE',
            'place_name' => 'ورد افنت بلس',
            'gbp_location' => self::LOCATION,
            'gbp_linked_at' => now(),
            'linked_at' => now(),
        ]);
    }

    /** تقييمٌ كما ترسله Google — بحقولها هي */
    private function googleReview(string $id, string $stars = 'ONE', ?array $reply = null): array
    {
        return array_filter([
            'reviewId' => $id,
            'starRating' => $stars,
            'comment' => 'الورد وصل ذابلًا',
            'reviewer' => ['displayName' => 'زبونة', 'profilePhotoUrl' => 'https://lh3.google.com/p.jpg'],
            'createTime' => '2026-09-01T10:00:00Z',
            'updateTime' => '2026-09-01T10:00:00Z',
            'reviewReply' => $reply,
        ]);
    }

    private function stored(string $id = 'rev-1', int $rating = 1): GoogleBusinessReview
    {
        return GoogleBusinessReview::create([
            'branch_id' => $this->branch->id,
            'review_id' => $id,
            'rating' => $rating,
            'comment' => 'الورد وصل ذابلًا',
            'author' => 'زبونة',
            'reviewed_at' => now()->subDay(),
            'first_seen_at' => now()->subDay(),
        ]);
    }
    /** والنطاقُ المطلوب هو نطاقُ إدارة الملفّ، ومعه ما يُعيد رمزَ التجديد */
    public function test_the_authorisation_url_asks_for_offline_business_scope(): void
    {
        $url = GoogleBusiness::authUrl('state-123');

        $this->assertStringContainsString('accounts.google.com', $url);
        $this->assertStringContainsString(urlencode(GoogleBusiness::SCOPE), $url);
        $this->assertStringContainsString('access_type=offline', $url);
        // وبلا `prompt=consent` لا تُعيد Google رمزَ تجديدٍ لمن أذن من قبل
        $this->assertStringContainsString('prompt=consent', $url);
        $this->assertStringContainsString('state=state-123', $url);
    }
    /**
     * وردٌّ بلا رمزِ تجديدٍ لا يمحو المحفوظ.
     *
     * Google تُعيده في أوّل إذنٍ وحده أحيانًا. ولو كُتب `null` فوقه لَانقطعت
     * المزامنةُ بعد ساعة، ولا شيء يقول لماذا.
     */
    public function test_a_response_without_a_refresh_token_keeps_the_stored_one(): void
    {
        $this->connected(refresh: 'the-first-refresh');

        GoogleBusiness::store($this->shop->id, [
            'access_token' => 'a-new-access',
            'expires_in' => 3600,
        ]);

        $this->assertSame(
            'the-first-refresh',
            GoogleBusinessAccount::firstOrFail()->refresh_token,
            'مُحي رمزُ التجديد بردٍّ لا يحمله',
        );
    }

    /** ورمزٌ منتهٍ يُجدَّد قبل النداء لا بعد أن يُردّ بـ٤٠١ */
    public function test_an_expired_token_is_refreshed_before_the_call(): void
    {
        $account = $this->connected();
        $account->forceFill(['token_expires_at' => now()->subMinutes(5)])->save();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'a-fresh-token', 'expires_in' => 3600,
            ], 200),
        ]);

        $this->assertSame('a-fresh-token', GoogleBusiness::accessToken($account->fresh()));
        $this->assertSame('a-fresh-token', GoogleBusinessAccount::firstOrFail()->access_token);
    }

    /** وتجديدٌ مرفوضٌ يُقيَّد ويُقرأ — لا يُبتلع فتصمت المزامنة بلا سبب */
    public function test_a_refused_refresh_is_recorded_not_swallowed(): void
    {
        $account = $this->connected();
        $account->forceFill(['token_expires_at' => now()->subMinutes(5)])->save();

        Http::fake(['oauth2.googleapis.com/token' => Http::response([
            'error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.',
        ], 400)]);

        $this->assertNull(GoogleBusiness::accessToken($account->fresh()));
        $this->assertNotNull(GoogleBusinessAccount::firstOrFail()->last_error);
    }
    /** وسحبٌ لم يصل Google لا يُبقي الرمزين عندنا */
    public function test_a_failed_revoke_still_wipes_locally(): void
    {
        $account = $this->connected();
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response('', 500)]);

        GoogleBusiness::revoke($account);

        $raw = DB::table('google_business_accounts')->firstOrFail();
        $this->assertNull($raw->refresh_token);
    }
    /** وسحبٌ ثانٍ لا يُكرّر ولا يُعيد عدَّ الجديد */
    public function test_a_second_pull_does_not_duplicate(): void
    {
        $this->connected();
        $this->mapped();

        Http::fake(['mybusiness.googleapis.com/*' => Http::response([
            'reviews' => [$this->googleReview('rev-1')],
        ], 200)]);

        GoogleBusiness::syncReviews($this->branch);
        $again = GoogleBusiness::syncReviews($this->branch);

        $this->assertSame(1, GoogleBusinessReview::count());
        $this->assertSame(0, $again['new'], 'عُدّ تقييمٌ قديمٌ جديدًا');
    }

    /**
     * وما اختفى من Google يُختم ولا يبقى معروضًا.
     *
     * زبونٌ حذف تقييمه. وبقاؤه يعني تاجرًا يردّ على كلامٍ لم يعد موجودًا.
     */
    public function test_a_review_gone_from_google_is_stamped_not_shown(): void
    {
        $this->connected();
        $this->mapped();

        Http::fake(['mybusiness.googleapis.com/*' => Http::sequence()
            ->push(['reviews' => [$this->googleReview('rev-1'), $this->googleReview('rev-2', 'FIVE')]], 200)
            ->push(['reviews' => [$this->googleReview('rev-2', 'FIVE')]], 200)]);

        GoogleBusiness::syncReviews($this->branch);
        GoogleBusiness::syncReviews($this->branch);

        $this->assertNotNull(GoogleBusinessReview::where('review_id', 'rev-1')->value('gone_at'));
        $this->assertSame(1, GoogleBusinessReview::live()->count());
        $this->assertSame(2, GoogleBusinessReview::count(), 'مُحي أثرُ تقييمٍ بدل أن يُختم');
    }

    /**
     * والردُّ يُقرأ من Google لا يُترك على ما عندنا.
     *
     * التاجر قد يردّ من تطبيق Google نفسِه أو يُحذف ردُّه هناك — وشاشةٌ تعرض
     * ردًّا لم يعد قائمًا تكذب بهدوء.
     */
    public function test_a_reply_removed_at_google_disappears_here(): void
    {
        $this->connected();
        $this->mapped();

        Http::fake(['mybusiness.googleapis.com/*' => Http::sequence()
            ->push(['reviews' => [$this->googleReview('rev-1', 'ONE', [
                'comment' => 'نعتذر', 'updateTime' => '2026-09-02T10:00:00Z',
            ])]], 200)
            ->push(['reviews' => [$this->googleReview('rev-1', 'ONE')]], 200)]);

        GoogleBusiness::syncReviews($this->branch);
        $this->assertSame('نعتذر', GoogleBusinessReview::firstOrFail()->reply);

        GoogleBusiness::syncReviews($this->branch);
        $this->assertNull(GoogleBusinessReview::firstOrFail()->reply, 'بقي ردٌّ حُذف عند Google معروضًا');
    }

    /** وفرعٌ غيرُ مربوطٍ لا يُنادى له أحد */
    public function test_an_unmapped_branch_calls_nobody(): void
    {
        $this->connected();

        $result = GoogleBusiness::syncReviews($this->branch);

        Http::assertNothingSent();
        $this->assertFalse($result['ok']);
    }

    /** ورفضُ Google يُقيَّد ويُقرأ في الشاشة */
    public function test_a_refused_pull_is_recorded_on_the_account(): void
    {
        $this->connected();
        $this->mapped();

        Http::fake(['mybusiness.googleapis.com/*' => Http::response([
            'error' => ['message' => 'Business Profile API has not been used'],
        ], 403)]);

        $result = GoogleBusiness::syncReviews($this->branch);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Business Profile', (string) $result['error']);
        $this->assertNotNull(GoogleBusinessAccount::firstOrFail()->last_error);
    }
    /** وانقطاعُ الشبكة كذلك — لا يُكتب شيء */
    public function test_a_network_failure_does_not_mark_a_reply_published(): void
    {
        $this->connected();
        $this->mapped();
        $review = $this->stored();

        Http::fake(fn () => throw new ConnectionException('timeout'));

        $result = GoogleBusiness::reply($review, 'نعتذر');

        $this->assertFalse($result['ok']);
        $this->assertNull($review->fresh()->reply);
    }

    /** وإذنٌ منتهٍ لا يُصحَّح بكتابةٍ محلّية */
    public function test_an_expired_grant_blocks_the_reply(): void
    {
        $account = $this->connected();
        $account->forceFill(['token_expires_at' => now()->subHour()])->save();
        $this->mapped();
        $review = $this->stored();

        /*
         * ونداءُ الردّ **مسموحٌ وناجحٌ** في هذه التجربة.
         *
         * ولو مُنع لَكان المنعُ هو ما يُسقط الردّ، لا الفحصُ الذي نقيسه —
         * فيبدو الحارسُ عاملًا وهو ميّت. فيُفتح البابُ ويُقاس أنّ أحدًا لم
         * يمرّ منه.
         */
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
            'mybusiness.googleapis.com/*' => Http::response(['comment' => 'نعتذر'], 200),
        ]);

        $result = GoogleBusiness::reply($review->fresh(), 'نعتذر');

        $this->assertFalse($result['ok']);
        $this->assertNull($review->fresh()->reply, 'كُتب ردٌّ بإذنٍ منتهٍ');

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'mybusiness.googleapis.com'));
    }
    /* ═══════════════ ٧ · التنبيهات ═══════════════ */

    /** التقييمُ المنخفضُ يُنبَّه به — ولونُه يُقرأ قبل نصّه */
    public function test_a_low_review_raises_a_danger_notification(): void
    {
        $this->stored('rev-low', 1);

        $items = $this->notifications();

        $this->assertNotEmpty($items, 'لم يُنبَّه بتقييمٍ منخفض');
        $this->assertSame('danger', $items[0]['color']);
        $this->assertStringContainsString('منخفض', $items[0]['text']);
    }

    /** والمرتفعُ يُنبَّه به إخبارًا لا إنذارًا */
    public function test_a_high_review_is_informational(): void
    {
        $this->stored('rev-high', 5);

        $items = $this->notifications();

        $this->assertSame('info', $items[0]['color']);
        $this->assertStringNotContainsString('منخفض', $items[0]['text']);
    }

    /** والحدُّ يُطاع: من رفعه إلى ثلاثٍ عُدّت الثالثةُ منخفضة */
    public function test_the_threshold_is_obeyed(): void
    {
        MarketingSettings::save($this->shop->id, 'google', ['gbp_low_rating' => '3']);
        $this->stored('rev-3', 3);

        $this->assertSame('danger', $this->notifications()[0]['color']);
    }

    /** ومن أطفأ المقبض لا يُنبَّه */
    public function test_disabling_alerts_silences_them(): void
    {
        MarketingSettings::save($this->shop->id, 'google', ['gbp_alerts_enabled' => '0']);
        $this->stored('rev-off', 1);

        $this->assertSame([], $this->notifications());
    }

    /**
     * وتقييماتُ سنتين لا تنهال يومَ الربط.
     *
     * «الجديد» يُقاس بأوّل مرّةٍ وصلنا فيها لا بتاريخ Google.
     */
    public function test_old_reviews_do_not_flood_the_bell_on_the_first_link(): void
    {
        $review = $this->stored('rev-old', 1);
        $review->forceFill(['first_seen_at' => now()->subMonths(3)])->save();

        $this->assertSame([], $this->notifications());
    }

    /** ولا يُنبَّه مديرُ المنصّة بتقييمِ متجر: شأنُ صاحبه */
    public function test_the_platform_admin_is_not_told_about_a_shops_review(): void
    {
        $this->stored('rev-1', 1);

        $admin = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'sa@eventplusapp.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $items = collect($this->actingAs($admin)->notifications())
            ->filter(fn ($i) => str_starts_with((string) $i['key'], 'gbp-review-'));

        $this->assertCount(0, $items);
    }

    /** @return list<array<string, mixed>> */
    private function notifications(): array
    {
        return collect(Demo::notifications())
            ->filter(fn ($i) => str_starts_with((string) ($i['key'] ?? ''), 'gbp-review-'))
            ->values()->all();
    }
}
