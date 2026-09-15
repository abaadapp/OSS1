<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CrmLead;
use App\Models\CrmNote;
use App\Models\CrmStageEvent;
use App\Models\CrmTask;
use App\Models\User;
use App\Support\Crm;
use App\Support\CrmLeads;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * دفترُ مبيعات افنت بلس — ما يُكتب فيه، ومن يكتب، وما لا يُكتب بحال.
 *
 * وأخطرُ ما يُحرَس هنا ثلاثة: أنّ رقمًا واحدًا لا يصير عميلَين، وأنّ
 * الملاحظات الداخليّة لا يبلغها تاجر، وأنّ متجرًا واحدًا لا يُحسب اشتراكَين.
 */
class EventPlusKeepsASalesBookOfItsOwnTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'سالم', 'email' => 'salem@eventplus.om',
            'password' => bcrypt('secret'), 'role' => 'super_admin', 'status' => 'active',
        ]);

        $this->otherAdmin = User::create([
            'name' => 'ريم', 'email' => 'reem@eventplus.om',
            'password' => bcrypt('secret'), 'role' => 'super_admin', 'status' => 'active',
        ]);
    }
    /* ═══════════════════ الرقم هويّة ═══════════════════ */

    /**
     * رقمٌ واحد كُتب بثلاث صيغٍ — صفٌّ واحد.
     *
     * وهذا ما يمنع أن يردّ موظّفان على نصفَي محادثةٍ واحدة.
     */
    public function test_one_number_written_three_ways_is_one_lead(): void
    {
        $this->actingAs($this->admin);

        $a = CrmLeads::findOrCreateByPhone('91234567');
        $b = CrmLeads::findOrCreateByPhone('+968 9123 4567');
        $c = CrmLeads::findOrCreateByPhone('0096891234567');

        $this->assertTrue($a['created']);
        $this->assertFalse($b['created'], 'الصيغة الدولية صنعت صفًّا ثانيًا');
        $this->assertFalse($c['created'], 'بادئة 00 صنعت صفًّا ثالثًا');

        $this->assertSame($a['lead']->id, $b['lead']->id);
        $this->assertSame($a['lead']->id, $c['lead']->id);
        $this->assertSame(1, CrmLead::count());
    }

    /**
     * والحارسُ الأخير في القاعدة لا في الكود.
     *
     * ═══ وهذا ما أثبتته الطفرة ═══
     *
     * جرّبتُ أن أجعل القراءةَ السابقة تبحث بالرقم الخام بدل المطبَّع —
     * فنجت كلُّ الحرّاس. والسببُ أنّ تلك القراءة **ليست** ما يمنع التكرار:
     * هي اختصارُ طريق. الذي يمنعه فهرسُ التفرّد على العمود المطبَّع، ومعه
     * سقوطُ `Contention` إلى الصفّ الذي كتبه السابق.
     *
     * فالحارسُ يُكتب على ما يحرس فعلًا: القاعدةُ نفسُها تردّ الثاني. وبلا
     * ذلك تبقى بين القراءة والكتابة نافذةٌ يمرّ منها إشعارٌ متزامنٌ من ميتا
     * فيصير للرقم الواحد صفّان — ثمّ يردّ موظّفان على نصفَي محادثة.
     */
    public function test_the_database_itself_refuses_a_second_row_for_one_number(): void
    {
        $this->actingAs($this->admin);
        $this->lead('91234567');

        $this->expectException(UniqueConstraintViolationException::class);

        CrmLead::create([
            'phone' => '96891234567',
            'source' => 'manual',
            'status' => Crm::ACTIVE,
            'stage' => Crm::NEW,
        ]);
    }

    /** والرقمُ يُحفظ كما كتبه صاحبه — ويُقارَن مطبَّعًا */
    public function test_the_raw_number_is_kept_as_written(): void
    {
        $this->actingAs($this->admin);

        $lead = CrmLeads::findOrCreateByPhone('+968 9123 4567')['lead'];

        $this->assertSame('96891234567', $lead->phone);
        $this->assertSame('+968 9123 4567', $lead->phone_raw);
    }
    /**
     * والتاريخُ يبقى وإن مضت المراحل.
     *
     * وهو ما يجعل مسارَ التحويل قابلًا للحساب: عميلٌ صار مشتركًا لا يُعدّ في
     * «مهتمّ» بعمود المرحلة، ويُعدّ في تاريخه.
     */
    public function test_the_funnel_reads_history_not_the_current_stage(): void
    {
        $this->actingAs($this->admin);
        $lead = $this->lead('91234567');

        foreach ([Crm::CONTACTED, Crm::INTERESTED, Crm::QUALIFIED] as $stage) {
            CrmLeads::moveStage($lead, $stage, $this->admin);
        }

        $this->assertSame(Crm::QUALIFIED, $lead->fresh()->stage);

        $reached = CrmStageEvent::where('lead_id', $lead->id)->pluck('to_stage')->all();
        $this->assertContains(Crm::INTERESTED, $reached, 'مرحلةٌ مرّ بها ضاعت من التاريخ');
    }
    /** والحالُ يُشتقّ من المرحلة ولا يُكتب باليد */
    public function test_status_follows_the_stage(): void
    {
        $this->actingAs($this->admin);
        $lead = $this->lead('91234567');

        CrmLeads::moveStage($lead, Crm::INTERESTED, $this->admin);
        $this->assertSame(Crm::ACTIVE, $lead->fresh()->status);

        CrmLeads::lose($lead, 'price', null, $this->admin);
        $this->assertSame(Crm::LOST_STATUS, $lead->fresh()->status);
    }
    /* ═══════════════════ أدواتٌ ═══════════════════ */

    private function lead(string $phone): CrmLead
    {
        return CrmLeads::findOrCreateByPhone($phone)['lead'];
    }
}
