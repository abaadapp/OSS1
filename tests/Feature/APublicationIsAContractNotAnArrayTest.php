<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * النشرُ عقدٌ له نسخة — لا مصفوفةٌ تُجمَّع.
 *
 * وثلاثةٌ يحرسها هذا الملفّ، وكلٌّ منها كان عطبًا قائمًا قبل هذه النسخة:
 *
 * ١) **ما تحفظه الشاشةُ يصل الموقعَ المنشور.** «أخفِ الأسعار» و«السماح
 *    لمحرّكات البحث» كانا يُحفظان ويُقال «حُفظ» ولا يقرؤهما شيء. ومقبضٌ لا
 *    يُدير شيئًا أسوأ من غياب المقبض، لأنّ صاحبه يظنّ أنّه فعل.
 *
 * ٢) **ونشرتان معًا لا تصطدمان.** الرقمُ كان `MAX + 1` بلا قفل، فضغطتان
 *    متقاربتان تكتبان الرقم نفسه ويسقط أحدهما على الفهرس الفريد.
 *
 * ٣) **ونسخةُ الأمس تُقرأ اليوم.** مستندٌ نُشر بشكلٍ قديم لا ينكسر عند
 *    قارئه، ولا يُعاد كتابةُ تاريخ التاجر من تحته.
 */
class APublicationIsAContractNotAnArrayTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط',
            'phone' => '96890000000', 'city' => 'مسقط', 'address' => 'الخوير',
            'site_slug' => 'wrood',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function bid(): int
    {
        return (int) $this->business->id;
    }

    private function catalogue(): Product
    {
        $category = Category::create(['business_id' => $this->bid(), 'name' => 'باقات']);

        return Product::create([
            'business_id' => $this->bid(), 'category_id' => $category->id,
            'name' => 'باقة ورد', 'price' => 12.5, 'active' => true, 'quantity' => 5,
        ]);
    }
    /* ═════════════════════ نشرتان معًا ═════════════════════ */
    /* ═════════════════════ نسخةُ الأمس ═════════════════════ */
    /* ═════════════════════ ما لا يدخل النشرة ═════════════════════ */
    /**
     * و«الأكثر مبيعًا» لا يُبنى لمن لم يبع بعد.
     *
     * متجرٌ رفع مئةَ صنفٍ ولم تقع فيه بيعةٌ واحدة كان يُبنى له شريطٌ فارغ في
     * صدر صفحته أوّلَ يوم — وعليه يحكم على الموقع كلِّه.
     */
    public function test_best_sellers_waits_for_a_first_sale(): void
    {
        $this->catalogue();

        $this->assertNotContains('best_sellers', $this->typesIn($this->build()));
    }
    /* ═════════════════════ المخفيّ لا يُقرأ ═════════════════════ */
    /* ═════════════════════ الحيُّ يبقى حيًّا ═════════════════════ */
    /* ═════════════════════ الطبقاتُ لا تنقلب ═════════════════════ */
}
