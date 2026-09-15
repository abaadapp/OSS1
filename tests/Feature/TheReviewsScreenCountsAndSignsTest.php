<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
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
    /* ------------------------------ السجلّ ------------------------------ */

    private function lastLog(): ?ActivityLog
    {
        return ActivityLog::orderByDesc('id')->first();
    }
    /* ══════════════ ما يُعرض لا ما يُنشر ══════════════ */
    /* ══════════════ الترتيب يطابق ما يُقرأ ══════════════ */
}
