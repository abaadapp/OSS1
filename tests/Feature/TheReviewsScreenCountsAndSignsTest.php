<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Review;
use App\Models\User;
use App\Support\Website\MerchantData;
use App\Support\Website\Preview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * شاشةُ التقييمات: تُعدّ ولا تُحمّل، وما يُنشر للعامّة يُقيَّد.
 *
 * ═══ أربعةُ أرقامٍ بجدولٍ كامل ═══
 *
 * البطاقات الأربع فوق الشاشة كانت تُحسب بـ`Review::…->get()`: كلُّ تقييمٍ في
 * المتجر يُبنى نموذجًا في الذاكرة ليُعدّ. والصفحة تحته مُرقَّمةٌ بعشرين —
 * فالجدول يُمسح كاملًا لأجل أربعة أعداد، ويكبر الثمن مع كلّ تقييمٍ يصل.
 *
 * ═══ ونشرٌ بلا أثر ═══
 *
 * التسجيلُ يُقيَّد والحذفُ يُقيَّد، والنشرُ والرفضُ والردّ لا. وهي أخطر
 * الثلاثة: النشرُ يُخرج كلامَ زبونٍ إلى واجهة المتجر، والردُّ يكتب باسم
 * المحلّ ردًّا يقرؤه كلّ زائر — ولا يقول السجلُّ من فعلها ولا متى.
 */
class TheReviewsScreenCountsAndSignsTest extends TestCase
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
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function review(string $status = 'معلّق', int $rating = 5): Review
    {
        return Review::create([
            'business_id' => $this->business->id,
            'author_name' => 'زائر', 'rating' => $rating, 'comment' => 'جميل', 'status' => $status,
        ]);
    }
    /* ------------------------------ الأعداد ------------------------------ */

    public function test_the_four_numbers_are_right(): void
    {
        $this->review('معلّق');
        $this->review('منشور', 4);
        $this->review('منشور', 5);
        $this->review('مرفوض', 1);

        $s = $this->summary();

        $this->assertSame(4, $s['count']);
        $this->assertSame(1, $s['pending']);
        $this->assertSame(2, $s['published']);
        // المعدّل على المنشور وحده: المعلّق لم يُقرأ بعد، والمرفوض لا رأيَ له
        $this->assertSame(4.5, $s['average']);
    }

    public function test_a_shop_with_no_reviews_reads_zero_not_a_crash(): void
    {
        $s = $this->summary();

        $this->assertSame(0, $s['count']);
        $this->assertSame(0.0, $s['average']);
    }

    /** ولا تُحتسب في المعدّل نجومُ ما لم يُنشر */
    public function test_pending_stars_do_not_move_the_average(): void
    {
        $this->review('منشور', 5);
        $this->review('معلّق', 1);

        $this->assertSame(5.0, $this->summary()['average']);
    }

    /** ولا تقرأ الشاشةُ تقييماتِ جارها */
    public function test_a_neighbours_reviews_are_not_counted(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Review::create([
            'business_id' => $other->id, 'author_name' => 'زائر',
            'rating' => 1, 'comment' => 'سيّئ', 'status' => 'منشور',
        ]);
        $this->review('منشور', 5);

        $s = $this->summary();
        $this->assertSame(1, $s['count']);
        $this->assertSame(5.0, $s['average']);
    }
    /* ------------------------------ السجلّ ------------------------------ */

    private function lastLog(): ?ActivityLog
    {
        return ActivityLog::orderByDesc('id')->first();
    }
    /* ══════════════ ما يُعرض لا ما يُنشر ══════════════ */

    /**
     * نجومٌ بلا كلامٍ ليست شهادةً تُعرض — والمواضعُ الثلاثة تقول ذلك معًا.
     *
     * ═══ وما كان يقع ═══
     *
     * بانِي المواقع يعدّ كلَّ منشور، وشرطُ عرضِ القسم يقرأ كلَّ منشور،
     * والرسمُ يعرض المنشورَ **الذي له تعليق**. فمتجرٌ نشر خمسةَ تقييماتٍ
     * بنجومٍ بلا كلام يرى «٥ تقييمات» ويُعرض عليه قسمُ الآراء، ثمّ تخرج
     * صفحتُه وفيه لا شيء — ولا يعرف لماذا: العدّادُ يقول خمسة.
     */
    public function test_a_starred_review_without_words_is_not_offered_as_a_testimonial(): void
    {
        Review::create([
            'business_id' => $this->business->id,
            'author_name' => 'زائر', 'rating' => 5, 'comment' => null, 'status' => 'منشور',
        ]);

        $this->assertFalse(
            MerchantData::available($this->business->id)['reviews'],
            'عُرض قسمُ الآراء على متجرٍ لا شهادةَ فيه',
        );
        $this->assertSame(0, $this->shown(), 'عُدَّ ما لا يُعرض');
    }

    /** وتقييمٌ منشورٌ له كلامٌ تقوله الثلاثةُ معًا */
    public function test_a_published_review_with_words_agrees_everywhere(): void
    {
        $this->review('منشور');

        $this->assertTrue(MerchantData::available($this->business->id)['reviews']);
        $this->assertSame(1, $this->shown());
    }

    /**
     * ولا موضعَ ثالثٌ يعيد كتابة الشرط بيده.
     *
     * «فحصان لسؤالٍ واحد يفترقان يوم يُبدَّل أحدهما» — وقد افترقا فعلًا.
     * فالشرطُ في `Review::scopeShowable` وحدَه، ومن نسخه هنا يسقط.
     */
    public function test_no_reader_rewrites_the_showable_rule(): void
    {
        $files = [
            base_path('app/Support/Website/MerchantData.php'),
            base_path('app/Support/Website/Preview.php'),
            base_path('app/Http/Controllers/Admin/Website/BuilderController.php'),
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            $this->assertStringNotContainsString(
                "Review::where('business_id', \$businessId)->where('status'",
                $source,
                basename($file).' يعيد كتابة شرط العرض بيده',
            );
            $this->assertStringContainsString('showable()', $source, basename($file).' لا يقرأ من المصدر الواحد');
        }
    }

    /** كم شهادةً تُعرض فعلًا في الصفحة */
    private function shown(): int
    {
        return count((new \ReflectionMethod(Preview::class, 'reviews'))
            ->invoke(null, $this->business->id, 6));
    }

    /* ══════════════ الترتيب يطابق ما يُقرأ ══════════════ */

    /**
     * ترتيبُ «المُقيِّم» يقرأ الاسمَ المعروض لا العمودَ الخام.
     *
     * الشاشةُ تعرض اسمَ العميل المسجَّل إن كان، و`author_name` **فارغٌ**
     * حينها. فكان الترتيبُ يصفّ أسماءً ظاهرةً بفراغاتٍ خلفها.
     */
    public function test_sorting_by_reviewer_follows_the_shown_name(): void
    {
        /*
         * والاسمان مقلوبان قصدًا: المسجَّلُ يأتي آخرًا بحروفه، والزائرُ أوّلًا.
         *
         * فلو رُتّب على `author_name` الخام لَتقدّم المسجَّلُ — عمودُه فارغٌ
         * والفراغُ يتقدّم. ولولا القلبُ لَمرّ الحارسُ على الحالين معًا.
         */
        $customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'ياسر', 'phone' => '91110000',
        ]);

        Review::create([
            'business_id' => $this->business->id, 'customer_id' => $customer->id,
            'rating' => 5, 'comment' => 'ممتاز', 'status' => 'منشور',
        ]);
        Review::create([
            'business_id' => $this->business->id, 'author_name' => 'أحمد',
            'rating' => 4, 'comment' => 'جيّد', 'status' => 'منشور',
        ]);

        $names = collect($this->rows(['sort' => 'author', 'dir' => 'asc']))->pluck('author')->all();

        $this->assertSame(['أحمد', 'ياسر'], $names, 'رُتّبت أسماءٌ ظاهرةٌ بعمودٍ فارغ');
    }

    /** والبحثُ يجد اسمَ العميل المسجَّل — وهو ما يقرؤه الباحث في العمود */
    public function test_search_finds_the_registered_customer_name(): void
    {
        $customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'سميرة', 'phone' => '91110000',
        ]);

        Review::create([
            'business_id' => $this->business->id, 'customer_id' => $customer->id,
            'rating' => 5, 'comment' => 'ممتاز', 'status' => 'منشور',
        ]);

        $this->assertCount(1, $this->rows(['q' => 'سميرة']));
    }
}
