<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Project;
use App\Models\Quotation;
use App\Support\Communication\Subjects;
use App\Support\CustomerContacts;
use App\Support\CustomerInvoices;
use App\Support\Quotations;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\Concerns\WalksTheProjectJourney;
use Tests\TestCase;

/**
 * الجهةُ تحفظ أشخاصَها — صفٌّ لكلّ شخص، وأساسيٌّ واحد (المرحلة 14).
 *
 * لا `phone_2` ولا `contact_person_2`: جدولٌ مستقلّ بأيّ عدد، وأساسيٌّ واحد
 * بقيدٍ في القاعدة وتحت قفل. والمعطَّلُ لا يُختار في مستندٍ جديد، ومن اختير
 * في عرضٍ يُعطَّل ولا يُحذف. والعرضُ يحفظ من اختير مرجعًا ولقطة، والمشروعُ
 * يرثه. والجهةُ تُعرف بأسماء أشخاصها في البحث، ونافذةُ المراسلة تعرضهم.
 * والأعمدةُ القديمة سندٌ لمن لم يُنقل، ومن له اسمٌ فيها صار شخصَه مرّةً.
 */
class ACompanyKeepsManyContactsAndOnePrimaryTest extends TestCase
{
    use RefreshDatabase;
    use WalksTheProjectJourney;

    private Customer $ministry;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Mail::fake();
        $this->journeyCompany();
        $this->ministry = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة الثقافة', 'customer_type' => 'جهة حكومية',
            'phone' => '24000000', 'email' => 'info@culture.gov.om']);
    }

    private function contact(string $name, array $over = []): CustomerContact
    {
        return CustomerContacts::save($this->ministry, $over + ['name' => $name], $this->owner);
    }

    private function quote(array $data = []): Quotation
    {
        return Quotations::create((int) $this->business->id, $this->ministry->fresh(), $data + ['title' => 'معرض الكتاب', 'issued_at' => now()->toDateString()],
            [['description' => 'تنظيم', 'quantity' => 1, 'unit_price' => 1000]], $this->owner);
    }

    /* ─────────────────────────── الأشخاص ─────────────────────────── */

    public function test_a_company_holds_any_number_of_contacts_and_the_first_becomes_primary(): void
    {
        $this->actingAs($this->owner);
        foreach ([['محمد أحمد', 'مدير التسويق', '91111111'], ['سارة الهنائية', 'منسّقة الفعاليّات', '92222222'], ['خالد', 'المحاسب', '93333333']] as [$name, $title, $phone]) {
            $this->post(route('admin.customers.contacts.store', $this->ministry->id), ['name' => $name, 'job_title' => $title, 'phone' => $phone, 'email' => "x{$phone}@culture.gov.om"])
                ->assertSessionHasNoErrors();
        }

        $contacts = CustomerContacts::of($this->ministry);
        $this->assertCount(3, $contacts);
        $this->assertSame('محمد أحمد', $contacts->first()->name, 'الأوّلُ صار أساسيًّا وحدَه');
        $this->assertSame(1, $contacts->where('is_primary', true)->count());
        $this->assertTrue(ActivityLog::where('description', 'أضاف شخص الاتصال سارة الهنائية إلى وزارة الثقافة')->exists());

        $this->get(route('admin.customers.show', $this->ministry->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('contacts.0.name', 'محمد أحمد')->where('contacts.0.is_primary', true)
                ->has('contacts', 3)->where('profile.organization', true));
    }

    public function test_only_one_primary_and_the_database_refuses_a_second(): void
    {
        $a = $this->contact('محمد');
        $b = $this->contact('سارة');

        $this->actingAs($this->owner)->post(route('admin.customers.contacts.primary', [$this->ministry->id, $b->id]))->assertSessionHasNoErrors();
        $this->assertFalse($a->fresh()->is_primary);
        $this->assertTrue($b->fresh()->is_primary);
        $this->assertTrue(ActivityLog::where('description', 'جعل سارة شخص الاتصال الأساسي في وزارة الثقافة')->exists());

        /* والقيدُ في القاعدة نفسِها — لا في الكود وحده */
        $this->expectException(QueryException::class);
        CustomerContact::whereKey($a->id)->update(['is_primary' => true]);
    }

    public function test_a_deactivated_contact_loses_primary_and_is_not_chosen_on_a_new_document(): void
    {
        $a = $this->contact('محمد');
        $this->actingAs($this->owner)->post(route('admin.customers.contacts.active', [$this->ministry->id, $a->id]), ['active' => 0])->assertSessionHasNoErrors();

        $fresh = $a->fresh();
        $this->assertFalse($fresh->active);
        $this->assertFalse($fresh->is_primary);
        $this->assertSame([], CustomerContacts::options($this->ministry), 'المعطَّلُ لا يُعرض للاختيار');

        $this->expectException(RuntimeException::class);
        $this->quote(['customer_contact_id' => $a->id]);
    }

    public function test_a_contact_chosen_on_a_document_is_deactivated_not_deleted(): void
    {
        $a = $this->contact('محمد');
        $this->quote(['customer_contact_id' => $a->id]);

        $this->actingAs($this->owner)->delete(route('admin.customers.contacts.destroy', [$this->ministry->id, $a->id]))
            ->assertSessionHasErrors(['contact' => 'اختير هذا الشخص في عرض سعر أو مشروع — عطّله بدل حذفه ليبقى اسمه في مستنداته.']);
        $this->assertNotNull($a->fresh());

        $b = $this->contact('غير مستعمل');
        $this->actingAs($this->owner)->delete(route('admin.customers.contacts.destroy', [$this->ministry->id, $b->id]))->assertSessionHasNoErrors();
        $this->assertNull($b->fresh());
    }

    /* ─────────────────────────── البحث والمستندات ─────────────────────────── */

    public function test_the_company_is_found_by_its_contacts_name_phone_or_email(): void
    {
        $this->contact('محمد أحمد البلوشي', ['phone' => '95554444', 'email' => 'm.balushi@culture.gov.om']);
        Customer::create(['business_id' => $this->business->id, 'name' => 'شركة أخرى', 'customer_type' => 'شركة']);

        foreach (['البلوشي', '95554444', 'm.balushi'] as $q) {
            $rows = $this->actingAs($this->owner)->get(route('admin.customers.organizations', ['q' => $q]))->assertOk()
                ->viewData('page')['props']['customers'];
            $this->assertSame(['وزارة الثقافة'], collect($rows)->pluck('name')->all(), "البحثُ بـ«{$q}» يجد الجهة من شخصها");
        }
    }

    public function test_the_quotation_keeps_the_chosen_contact_and_the_project_inherits_it(): void
    {
        $this->contact('محمد', ['phone' => '91111111']);
        $sara = $this->contact('سارة الهنائية', ['phone' => '92222222', 'email' => 'sara@culture.gov.om']);

        $q = $this->quote(['customer_contact_id' => $sara->id]);
        $this->assertSame($sara->id, $q->customer_contact_id);
        $this->assertSame('سارة الهنائية', $q->attention_to, 'لقطةٌ من الشخص المختار');
        $this->assertSame('92222222', $q->contact_phone);
        $this->assertSame('sara@culture.gov.om', $q->contact_email);

        Quotations::send($q, $this->owner);
        Quotations::recordDecision($q->fresh(), Quotation::ACCEPTED, ['decided_by_name' => 'سارة'], $this->owner);
        $project = Project::where('quotation_id', $q->id)->sole();
        $this->assertSame($sara->id, $project->customer_contact_id);
        $this->assertSame('سارة الهنائية', $project->contact_person);

        /* والاسمُ في الورقة لقطة — تعديلُ الشخص بعدها لا يكتب فوق ما خرج */
        CustomerContacts::save($this->ministry, ['name' => 'سارة الهنائية (مديرة)'], $this->owner, $sara);
        $this->assertSame('سارة الهنائية', $q->fresh()->attention_to);
    }

    public function test_without_a_choice_the_primary_fills_the_paper_and_the_old_columns_remain_a_fallback(): void
    {
        $this->ministry->update(['contact_person' => 'قديم', 'contact_phone' => '90000000', 'contact_email' => 'old@culture.gov.om']);
        $this->assertSame('قديم', $this->quote()->attention_to, 'بلا أشخاصٍ: الأعمدةُ القديمة سند');

        $this->contact('محمد الجديد', ['phone' => '91111111', 'email' => 'new@culture.gov.om']);
        $q = $this->quote();
        $this->assertSame('محمد الجديد', $q->attention_to);
        $this->assertSame('new@culture.gov.om', $q->contact_email);
        $this->assertNull($q->customer_contact_id, 'لم يُختر أحد — فلا مرجع، والاسمُ من الأساسيّ');

        $invoice = CustomerInvoices::create((int) $this->business->id, $this->ministry->fresh(), [], [['description' => 'x', 'quantity' => 1, 'unit_price' => 10]], $this->owner->id);
        $this->assertSame('محمد الجديد', $invoice->attention_to);
    }

    public function test_the_compose_window_offers_the_contacts_and_their_whatsapp(): void
    {
        $this->contact('سارة', ['phone' => '92222222', 'whatsapp' => '97777777', 'email' => 'sara@culture.gov.om', 'job_title' => 'منسّقة']);
        $q = $this->quote(['contact_email' => 'buyer@culture.gov.om', 'contact_phone' => '91234567', 'attention_to' => 'المشتريات']);

        $r = Subjects::recipients($q->fresh());
        $emails = array_column($r['email'], 'value');
        $phones = array_column($r['phone'], 'value');

        $this->assertSame('buyer@culture.gov.om', $emails[0], 'ما في المستند أوّلًا — الأتمتةُ تراسل أوّل عنوان');
        $this->assertContains('sara@culture.gov.om', $emails);
        $this->assertContains('96892222222', $phones);
        $this->assertContains('96897777777', $phones, 'رقمُ واتساب الشخص خيارٌ مستقلّ');
        $this->assertStringContainsString('واتساب', collect($r['phone'])->firstWhere('value', '96897777777')['label']);
    }

    /* ─────────────────────────── النقل والعزل ─────────────────────────── */

    public function test_the_backfill_turns_a_named_old_contact_into_one_primary_once(): void
    {
        $named = Customer::create(['business_id' => $this->business->id, 'name' => 'شركة النور', 'customer_type' => 'شركة',
            'contact_person' => 'أحمد', 'contact_phone' => '91110000', 'contact_email' => 'ahmed@noor.om']);
        $nameless = Customer::create(['business_id' => $this->business->id, 'name' => 'شركة بلا شخص', 'customer_type' => 'شركة', 'contact_phone' => '91110001']);
        $person = Customer::create(['business_id' => $this->business->id, 'name' => 'فرد', 'customer_type' => 'فرد', 'contact_person' => 'نفسه']);

        $migration = require base_path('database/migrations/2026_10_16_100000_companies_keep_their_people_organizers_take_the_field_and_the_project_buys_its_services.php');
        $migration->backfill();
        $migration->backfill();

        $contacts = CustomerContact::where('customer_id', $named->id)->get();
        $this->assertCount(1, $contacts, 'مرّةً واحدة — الإعادةُ لا تكرّر');
        $this->assertTrue($contacts->first()->is_primary);
        $this->assertSame(['أحمد', '91110000', 'ahmed@noor.om'], [$contacts->first()->name, $contacts->first()->phone, $contacts->first()->email]);
        $this->assertSame(0, CustomerContact::where('customer_id', $nameless->id)->count(), 'بلا اسمٍ لا يُخترع شخص');
        $this->assertSame(0, CustomerContact::where('customer_id', $person->id)->count(), 'الفردُ هو نفسُه جهةُ الاتصال');
        $this->assertSame('91110001', CustomerContacts::primary($nameless)['phone'], 'والأعمدةُ القديمة تبقى سندًا');
    }

    public function test_another_company_neither_sees_nor_touches_these_contacts(): void
    {
        $sara = $this->contact('سارة');
        $mine = $this->business;
        $this->journeyCompany('شركة أخرى');
        $theirs = Customer::create(['business_id' => $this->business->id, 'name' => 'جهتهم', 'customer_type' => 'شركة']);

        $this->actingAs($this->owner)->put(route('admin.customers.contacts.update', [$this->ministry->id, $sara->id]), ['name' => 'مخترق'])->assertNotFound();
        $this->actingAs($this->owner)->post(route('admin.customers.contacts.primary', [$this->ministry->id, $sara->id]))->assertNotFound();
        $this->actingAs($this->owner)->delete(route('admin.customers.contacts.destroy', [$this->ministry->id, $sara->id]))->assertNotFound();
        /* ولا يُلتفّ عليه بجهتهم: الشخصُ ليس من أشخاصها */
        $this->actingAs($this->owner)->put(route('admin.customers.contacts.update', [$theirs->id, $sara->id]), ['name' => 'مخترق'])->assertNotFound();

        $this->assertSame('سارة', $sara->fresh()->name);
        $this->expectException(RuntimeException::class);
        CustomerContacts::pick((int) $this->business->id, $theirs->id, $sara->id);
        $this->assertNotNull($mine);
    }
}
