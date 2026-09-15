<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\Demo;
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
