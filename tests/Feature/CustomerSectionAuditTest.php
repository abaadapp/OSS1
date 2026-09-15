<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * قسم العملاء — ما تعرضه الشاشة، وما يخرج في الملفّ، ومن يملك أن يمحو.
 *
 * كلّ ما هنا عطبٌ صامت: لا رسالة خطأ في شيءٍ منه، إنما حقلٌ غائب يُقرأ
 * فراغًا، أو ملفٌّ يحمل غير ما طُلب، أو زرٌّ يعمل لمن لا يملكه.
 */
class CustomerSectionAuditTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل الورد', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الخوير']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function customer(array $over = []): Customer
    {
        return Customer::create(array_merge([
            'business_id' => $this->business->id,
            'name' => 'سالم',
            'phone' => '90000001',
        ], $over));
    }

    /* ------------------- ما تقرأه صفحة العميل ------------------- */

    public function test_the_page_carries_the_address_it_displays(): void
    {
        $c = $this->customer(['address' => 'مسقط — الخوير']);

        $this->get(route('admin.customers.show', $c->id))
            ->assertInertia(fn ($p) => $p->where('customer.address', 'مسقط — الخوير'));
    }

    public function test_the_note_box_opens_on_the_note_that_was_saved(): void
    {
        $c = $this->customer();

        $this->post(route('admin.customers.note', $c->id), ['notes' => 'يحب الورد الأبيض'])
            ->assertSessionHasNoErrors();

        // كانت تُحفظ ثم يعود الصندوق خاليًا، فتُكتب فوقها في المرة التالية
        $this->get(route('admin.customers.show', $c->id))
            ->assertInertia(fn ($p) => $p->where('customer.notes', 'يحب الورد الأبيض'));
    }

    public function test_the_edit_form_is_handed_the_branch_so_saving_does_not_detach_it(): void
    {
        $c = $this->customer(['branch_id' => $this->branch->id]);

        $this->get(route('admin.customers.show', $c->id))
            ->assertInertia(fn ($p) => $p->where('customer.branch_id', $this->branch->id));
    }

    public function test_saving_the_form_as_the_page_hands_it_keeps_the_branch(): void
    {
        $c = $this->customer(['branch_id' => $this->branch->id]);

        // النموذج يرسل ما حمّله: فرعٌ فارغ كان يعني فصلَ العميل عن فرعه
        $this->put(route('admin.customers.update', $c->id), [
            'name' => 'سالم', 'phone' => '90000001', 'branch_id' => (string) $this->branch->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->branch->id, $c->fresh()->branch_id);
    }

    /* ------------------- الهاتف والمحذوف ------------------- */

    public function test_a_deleted_customers_number_says_it_is_deleted(): void
    {
        $gone = $this->customer(['name' => 'أحمد', 'phone' => '95555555']);
        $gone->delete();

        $this->post(route('admin.customers.store'), ['name' => 'أحمد', 'phone' => '95555555'])
            ->assertSessionHasErrors('phone');

        $msg = session('errors')->getBag('default')->first('phone');
        $this->assertStringContainsString('محذوف', $msg);
        $this->assertStringContainsString('أحمد', $msg);
    }

    public function test_a_live_number_still_reads_as_another_customer(): void
    {
        $this->customer(['phone' => '96666666']);

        $this->post(route('admin.customers.store'), ['name' => 'آخر', 'phone' => '96666666'])
            ->assertSessionHasErrors('phone');

        $this->assertStringContainsString(
            'عميل آخر',
            session('errors')->getBag('default')->first('phone'),
        );
    }

    public function test_a_free_number_passes(): void
    {
        $this->post(route('admin.customers.store'), ['name' => 'جديد', 'phone' => '97777777'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customers', ['phone' => '97777777']);
    }

    public function test_the_number_of_another_shop_is_not_a_clash(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'status' => 'نشط']);
        Customer::create(['business_id' => $other->id, 'name' => 'غريب', 'phone' => '98888888']);

        $this->post(route('admin.customers.store'), ['name' => 'عندنا', 'phone' => '98888888'])
            ->assertSessionHasNoErrors();
    }

    /* ------------------- الملفّ يتبع الشاشة ------------------- */

    public function test_the_excel_file_carries_only_what_the_search_showed(): void
    {
        $this->customer(['name' => 'سالم', 'phone' => '90000001']);
        $this->customer(['name' => 'مريم', 'phone' => '90000002']);

        $body = $this->get(route('admin.customers.export.xlsx', ['q' => 'مريم']))
            ->streamedContent();

        $this->assertStringContainsString('PK', substr($body, 0, 4));

        $path = tempnam(sys_get_temp_dir(), 'cx').'.xlsx';
        file_put_contents($path, $body);
        $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet()->toArray();
        @unlink($path);

        $flat = json_encode($rows, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('مريم', $flat);
        $this->assertStringNotContainsString('سالم', $flat);
    }

    public function test_the_csv_file_carries_only_what_the_search_showed(): void
    {
        $this->customer(['name' => 'سالم']);
        $this->customer(['name' => 'مريم', 'phone' => '90000002']);

        $body = $this->get(route('admin.export.customers', ['q' => 'مريم']))->streamedContent();

        $this->assertStringContainsString('مريم', $body);
        $this->assertStringNotContainsString('سالم', $body);
    }

    public function test_the_pdf_file_opens_and_follows_the_search(): void
    {
        $this->customer(['name' => 'سالم']);
        $this->customer(['name' => 'مريم', 'phone' => '90000002']);

        $body = $this->get(route('admin.customers.export.pdf', ['q' => 'مريم']))
            ->assertOk()->getContent();

        $this->assertSame('%PDF', substr($body, 0, 4));
    }

    /* ------------------- الاستيراد ------------------- */

    private function upload(string $csv): \Illuminate\Testing\TestResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, $csv);

        return $this->post(route('admin.customers.import.upload'), [
            'file' => new UploadedFile($path, 'customers.csv', 'text/csv', null, true),
        ]);
    }

    public function test_the_import_refuses_a_number_held_by_a_deleted_customer(): void
    {
        $gone = $this->customer(['name' => 'أحمد', 'phone' => '95555555']);
        $gone->delete();

        $this->upload("الاسم,الهاتف\nأحمد,95555555\n");
        $this->post(route('admin.customers.import.confirm'));

        // ولو مرّ لصار في المتجر سجلّان بالهاتف نفسه بعد أول استعادة
        $this->assertSame(0, Customer::where('phone', '95555555')->count());
    }

    public function test_the_import_still_adds_a_free_number(): void
    {
        $this->upload("الاسم,الهاتف\nمريم,91111111\n");
        $this->post(route('admin.customers.import.confirm'));

        $this->assertDatabaseHas('customers', ['phone' => '91111111', 'name' => 'مريم']);
    }

    public function test_the_import_refuses_a_name_longer_than_the_column(): void
    {
        $long = str_repeat('م', 300);
        $this->upload("الاسم,الهاتف\n{$long},92222222\n");
        $this->post(route('admin.customers.import.confirm'));

        $this->assertSame(0, Customer::where('business_id', $this->business->id)->count());
    }

    /* ------------------- من يملك أن يمحو ------------------- */

    private function salesperson(): User
    {
        return User::create([
            'business_id' => $this->business->id, 'name' => 'بائع', 'email' => 's@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
        ]);
    }

    public function test_a_salesperson_cannot_erase_a_customer_for_good(): void
    {
        $c = $this->customer();
        $c->delete();

        $this->actingAs($this->salesperson())
            ->delete(route('admin.customers.purge', $c->id))
            ->assertForbidden();

        $this->assertNotNull(Customer::withTrashed()->find($c->id));
    }
}
