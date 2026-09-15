<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * رقمٌ واحدٌ وثلاثةُ دفاتر — ولا دفترَ يقرأ الآخر.
 *
 * ═══ ما يحرسه هذا الملفّ ═══
 *
 * `+968 7114 1624` يحمل اليوم ثلاثةَ أشياء: يُرسل إشعاراتِ الطلبات نيابةً
 * عن المحلّات، ويستقبل دعمَ أصحابها، ويستقبل من يريد أن يشتري افنت بلس. وقد
 * أُذن له بالثالث صراحةً (`crm_whatsapp_shared`).
 *
 * والأملاكُ ثلاثة لا واحد: صاحبُ المحلّ يملك رسائلَه إلى زبائنه، وافنت بلس
 * تملك دفترَ دعمها ودفترَ مبيعاتها. وخلطُ واحدٍ بآخر ليس عيبَ عرضٍ —
 * هو أن يقرأ تاجرٌ ما كتبه تاجرٌ آخر إلى الدعم، أو أن يقرأ موظّفُ مبيعاتِنا
 * ما كتبته زبونةٌ لمحلّ ورودٍ عن هديّةٍ لزوجها.
 *
 * ولذلك لا يُقاس الفصلُ بالنظر إلى ثلاث شاشات — يُقاس هنا: وارِدٌ واحدٌ
 * يدخل من الباب الحقيقيّ، ثمّ **تُعدّ الدفاترُ الثلاثة كلُّها** في كلّ
 * حارس. فصفٌّ يُكتب في غير موضعه يسقط الحارسَ حتّى لو كان موضعُه الصحيح
 * مكتوبًا أيضًا.
 */
class OneNumberAndNoBookReadsAnothersTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $merchant;

    /** الرقمُ الذي أرسلنا إليه إشعارَ طلبٍ يومًا — زبونُ محلٍّ لا عميلٌ محتمَل */
    private const NOTIFIED = '96899887766';

    /** رقمٌ لم نراسله قطّ ولا يخصّ تاجرًا — هذا وحدَه عميلٌ محتمَل */
    private const STRANGER = '96877778888';
    /* ═════════════ الوارد يذهب إلى دفترٍ واحد — والباقي صفر ═════════════ */
    /**
     * وزبونُ المحلّ يردّ على إشعاره: لا يُكتب في دفترٍ أصلًا.
     *
     * وهذا أهمُّ الثلاثة: ردُّه ملكُ محلِّه لا ملكُنا. وكتابتُه في دفتر
     * المبيعات تجعل موظّفَ مبيعاتنا يقرأ ما كتبته زبونةٌ عن هديّتها.
     */
    public function test_a_customer_answering_his_notice_is_written_in_no_book_at_all(): void
    {
        $this->inbound(self::NOTIFIED, 'وصل الورد؟')->assertOk();

        $this->assertSame(['support' => 0, 'crm' => 0, 'trader' => 1], $this->books());
    }

    /** والثلاثةُ على الرقم نفسه في وقتٍ واحد — كلٌّ في دفتره */
    public function test_three_senders_on_one_number_and_none_crosses(): void
    {
        $this->inbound('96891112222', 'عندي سؤال', 'wamid.A')->assertOk();
        $this->inbound(self::STRANGER, 'كم سعر افنت بلس؟', 'wamid.B')->assertOk();
        $this->inbound(self::NOTIFIED, 'وصل الورد؟', 'wamid.C')->assertOk();

        $this->assertSame(['support' => 1, 'crm' => 1, 'trader' => 1], $this->books());
    }

    /* ═════════════ وشاشةُ التاجر لا تعرض إلّا دفترَه ═════════════ */

    /**
     * سجلُّ التاجر لا يعرض رسالةَ دعمٍ — لا رسالتَه هو ولا رسالةَ غيره.
     *
     * وهو ما يُخشى فعلًا: لو خُلط الدفتران لَقرأ تاجرٌ في «سجلّ رسائلي» ما
     * كتبه تاجرٌ آخر إلى الدعم.
     */
    public function test_the_traders_log_shows_no_support_message(): void
    {
        $this->inbound('96891112222', 'كلامٌ لا يخصّ زبائني')->assertOk();

        $rows = $this->traderSees();

        $this->assertCount(1, $rows, 'دخلت رسالةُ دعمٍ إلى سجلّ التاجر');
        $this->assertSame(self::NOTIFIED, $rows[0]['phone']);
    }

    /** ولا رسالةَ عميلٍ محتمَلٍ من دفتر مبيعاتنا */
    public function test_the_traders_log_shows_no_sales_message(): void
    {
        $this->inbound(self::STRANGER, 'كم سعر افنت بلس؟')->assertOk();

        $rows = $this->traderSees();

        $this->assertCount(1, $rows);
        $this->assertSame(self::NOTIFIED, $rows[0]['phone']);
    }

    /**
     * ولا واردًا أصلًا: السجلُّ دفترُ ما **خرج** إلى زبائنه.
     *
     * وصفُّ واردٍ في هذا الجدول — أيًّا كان من كتبه — ليس رسالةً أرسلها
     * التاجر، فلا يُعدّ في «كم خرج» ولا يُعرض في «ماذا جرى لها».
     */
    public function test_the_traders_log_shows_no_inbound_row(): void
    {
        $this->sent('96895554444', ['direction' => 'inbound']);

        $rows = $this->traderSees();

        $this->assertCount(1, $rows, 'صفٌّ واردٌ عُرض في دفتر ما خرج');
    }

    /* ═════════════ ولا دفترَ تاجرٍ يُقرأ من دفتر تاجرٍ آخر ═════════════ */
}
