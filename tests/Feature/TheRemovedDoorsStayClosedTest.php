<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * أبوابٌ رُفعت من النظام — وتبقى مرفوعة.
 *
 * لا يكفي أن يُحذف متحكّمٌ ومسار: مسارٌ يعود بالخطأ في دمجٍ لاحق يفتح شاشةً
 * لنظامٍ لم يعد قائمًا، ولا شيء يقول إنّه عاد. فيُفحَص العنوانُ نفسُه.
 *
 * وأخطرُها `/pin-login`: كان بابَ الكاشير — أربعةُ أرقامٍ تفتح جلسةً كاملة
 * بلا بريدٍ ولا كلمة مرور، وفضاءُ الرموز عشرةُ آلاف. فما يُفتح بها ليس
 * حسابًا، وعودتُه صامتةً تعني حسابًا مفتوحًا لمن يجرّب.
 */
class TheRemovedDoorsStayClosedTest extends TestCase
{
    use RefreshDatabase;

    /** العنوان ← ما كان وراءه */
    private const DOORS = [
        '/pin-login' => 'باب الرمز — دخول الكاشير',
        '/pos' => 'نقطة البيع',
        '/pos/checkout' => 'صندوق البيع',
        '/super-admin' => 'لوحة المنصّة',
        '/super-admin/businesses' => 'شركات المنصّة',
        '/admin/website' => 'الموقع الإلكتروني',
        '/admin/marketing' => 'أدوات التسويق',
        '/admin/help' => 'المساعدة والدعم',
        '/admin/preparation' => 'لوحة التجهيز',
        '/admin/integrations' => 'التطبيقات التكاملية',
    ];

    public function test_no_removed_door_answers(): void
    {
        $open = [];

        foreach (self::DOORS as $uri => $what) {
            if ($this->get($uri)->getStatusCode() !== 404) {
                $open[] = "{$uri} ({$what})";
            }
        }

        $this->assertSame([], $open, 'أبوابٌ حُذفت وعادت تُجيب');
    }

    /** والباب لا يُفتح بـPOST أيضًا — الحذف يشمل الفعل لا العرض وحده */
    public function test_the_pin_door_does_not_answer_a_post_either(): void
    {
        $this->post('/pin-login', ['pin' => '1234'])->assertNotFound();
    }
}
