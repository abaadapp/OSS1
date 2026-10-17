<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\ServiceCategory;
use App\Models\Supplier;
use App\Models\User;
use App\Support\PurchaseOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\WalksTheProjectJourney;
use Tests\TestCase;

/**
 * المورّدُ يقول ما يقدّمه بكلماته — نصٌّ حرٌّ لا قائمةٌ ولا كتالوج.
 *
 * «تصوير فوتوغرافي»، «شاشات LED»، «تأجير كراسي»: تُكتب في نموذج المورّد
 * فتُحفظ كما كُتبت، ويُبحث بها، وتُعرض في قائمته وصفحته ومشترياتِ المشروع.
 * ولا يُنشأ شيءٌ في كتالوجٍ قبلها. والمورّدُ السابق يظهر بما كان له: خدماتُه
 * المربوطة، وإلّا اسمُ نوعه — والصفوفُ القديمة باقية لا تُمحى.
 */
class ASupplierSaysWhatItProvidesInItsOwnWordsTest extends TestCase
{
    use RefreshDatabase;
    use WalksTheProjectJourney;

    private const MIGRATION = '2026_10_17_114500_a_supplier_says_what_it_does_in_its_own_words.php';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Mail::fake();
        $this->journeyCompany();
    }

    private function store(array $data): Supplier
    {
        $this->actingAs($this->owner)->post(route('admin.suppliers.store'), $data)->assertSessionHasNoErrors();

        return Supplier::where('name', $data['name'])->sole();
    }

    public function test_the_service_is_written_freely_and_kept_as_written(): void
    {
        $studio = $this->store(['name' => 'استوديو الضوء', 'service' => '  تصوير فوتوغرافي وفيديو  ', 'whatsapp' => '97770000']);
        $chairs = $this->store(['name' => 'مخازن الكراسي', 'service' => 'تأجير كراسي']);
        $plain = $this->store(['name' => 'مورّدٌ بلا خدمة']);

        $this->assertSame('تصوير فوتوغرافي وفيديو', $studio->service, 'تُحفظ كما كُتبت — مشذَّبةً');
        $this->assertSame('97770000', $studio->whatsapp);
        $this->assertSame(Supplier::ACTIVE, $studio->status, 'النشطُ حين لا تُقال الحال');
        $this->assertSame('تأجير كراسي', $chairs->service);
        $this->assertNull($plain->service);
        $this->assertSame(0, ServiceCategory::count(), 'لا كتالوجَ يُنشأ ليُكتب المورّد');

        /* ونموذجٌ قديم يرسل نوعًا وأرقامَ كتالوج — لا يُردّ ولا يُقرأ */
        $old = $this->store(['name' => 'نموذجٌ قديم', 'supplier_type' => 'magic', 'service_ids' => [999], 'service' => 'طباعة']);
        $this->assertSame('طباعة', $old->service);

        $this->actingAs($this->owner)->post(route('admin.suppliers.store'), ['name' => 'طويل', 'service' => str_repeat('خ', 256)])
            ->assertSessionHasErrors('service');
    }

    public function test_editing_keeps_what_the_form_does_not_carry(): void
    {
        $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'شركة النور', 'name_en' => 'Al Noor Co.',
            'service' => 'صوتيات', 'status' => Supplier::ACTIVE]);

        // النموذجُ لا يحمل الاسمَ الإنجليزيّ — فلا يُمحى منه ما كان
        $this->actingAs($this->owner)->put(route('admin.suppliers.update', $supplier->id), [
            'name' => 'شركة النور', 'service' => 'صوتيات وإضاءة', 'status' => Supplier::INACTIVE, 'phone' => '91234567',
        ])->assertSessionHasNoErrors();
        $supplier->refresh();
        $this->assertSame('صوتيات وإضاءة', $supplier->service);
        $this->assertSame('Al Noor Co.', $supplier->name_en);
        $this->assertSame(Supplier::INACTIVE, $supplier->status);

        // وحفظٌ لا يعرف الخدمة لا يمحوها، وإفراغُها بيدٍ يمحوها
        $this->actingAs($this->owner)->put(route('admin.suppliers.update', $supplier->id), ['name' => 'شركة النور'])->assertSessionHasNoErrors();
        $this->assertSame('صوتيات وإضاءة', $supplier->refresh()->service);
        $this->actingAs($this->owner)->put(route('admin.suppliers.update', $supplier->id), ['name' => 'شركة النور', 'service' => ''])->assertSessionHasNoErrors();
        $this->assertNull($supplier->refresh()->service);
    }

    public function test_the_list_and_the_search_read_the_written_service(): void
    {
        $this->store(['name' => 'استوديو الضوء', 'service' => 'تصوير فوتوغرافي']);
        $this->store(['name' => 'شاشات العرض', 'service' => 'شاشات LED']);

        $this->actingAs($this->owner)->get(route('admin.suppliers.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Admin/Suppliers/Index')
                ->where('suppliers.0.name', 'استوديو الضوء')
                ->where('suppliers.0.service', 'تصوير فوتوغرافي')
                ->where('suppliers.1.service', 'شاشات LED')
                ->where('statuses.inactive', 'متوقف')
                ->missing('services')
                ->missing('types'));

        $found = collect($this->actingAs($this->owner)->getJson(route('admin.search', ['q' => 'تصوير']))->json('groups'))
            ->flatMap(fn ($g) => $g['items'] ?? [])->pluck('label')->all();
        $this->assertContains('استوديو الضوء', $found, 'البحثُ بما يقدّمه');
        $this->assertNotContains('شاشات العرض', $found);
    }

    public function test_the_supplier_page_says_the_service_beside_its_orders_and_projects(): void
    {
        $supplier = $this->store(['name' => 'استوديو الضوء', 'service' => 'تصوير فوتوغرافي']);
        $project = $this->acceptedProject(1000, 2);
        PurchaseOrder::create(['business_id' => $this->business->id, 'number' => 'PO-000001', 'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name,
            'status' => PurchaseOrders::SENT, 'items_subtotal' => 300, 'tax' => 0, 'total' => 300, 'ordered_at' => now(), 'project_id' => $project->id]);

        $this->actingAs($this->owner)->get(route('admin.suppliers.show', $supplier->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Admin/Suppliers/Show')
                ->where('supplier.service', 'تصوير فوتوغرافي')
                ->missing('supplier.services')
                ->missing('types')
                ->where('orders.0.number', 'PO-000001')
                ->where('projects.0.code', $project->code));

        // ومشترياتُ المشروع تقول مورّدَها بما يقدّمه
        $this->actingAs($this->owner)->get(route('admin.projects.show', $project->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('purchases.suppliers.0.service', 'تصوير فوتوغرافي'));
    }

    public function test_the_catalog_has_no_door_left_but_its_rows_stay(): void
    {
        foreach (['admin.suppliers.services.store', 'admin.suppliers.services.update', 'admin.suppliers.services.destroy'] as $name) {
            $this->assertFalse(Route::has($name), $name.' — لا كتالوجَ يُدار');
        }

        $this->assertTrue(Schema::hasTable('service_categories'));
        $this->assertTrue(Schema::hasTable('service_category_supplier'));
        $this->assertTrue(Schema::hasColumn('suppliers', 'supplier_type'), 'العمودُ القديم باقٍ بصفوفه');
    }

    public function test_an_older_supplier_is_given_its_text_once_from_what_it_had(): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION);
        $migration->down();

        $bid = $this->business->id;
        $id = fn (array $row) => DB::table('suppliers')->insertGetId($row + ['business_id' => $bid, 'created_at' => now(), 'updated_at' => now()]);
        $sound = $id(['name' => 'شركة النور', 'supplier_type' => 'services']);
        $both = $id(['name' => 'المتكاملة', 'supplier_type' => 'both']);
        $services = $id(['name' => 'خدماتٌ بلا كتالوج', 'supplier_type' => 'services']);
        $goods = $id(['name' => 'مخازن الورق', 'supplier_type' => 'products']);

        $cat = fn (string $name, int $order) => DB::table('service_categories')->insertGetId(['business_id' => $bid, 'name' => $name, 'sort_order' => $order, 'active' => true]);
        $audio = $cat('صوتيات', 1);
        $light = $cat('إضاءة', 2);
        $furniture = $cat('أثاث', 3);
        DB::table('service_category_supplier')->insert([
            ['business_id' => $bid, 'supplier_id' => $sound, 'service_category_id' => $light],
            ['business_id' => $bid, 'supplier_id' => $sound, 'service_category_id' => $audio],
            ['business_id' => $bid, 'supplier_id' => $both, 'service_category_id' => $furniture],
        ]);

        $migration->up();

        $text = fn (int $sid) => DB::table('suppliers')->where('id', $sid)->value('service');
        $this->assertSame('صوتيات، إضاءة', $text($sound), 'خدماتُه المربوطة بترتيب الكتالوج');
        $this->assertSame('أثاث', $text($both));
        $this->assertSame('مقدم خدمات', $text($services), 'بلا خدماتٍ مربوطة: اسمُ نوعه كما كان يُعرض');
        $this->assertSame('مورد منتجات', $text($goods));

        // ولا يُمحى شيء
        $this->assertSame(3, DB::table('service_category_supplier')->count());
        $this->assertSame('services', DB::table('suppliers')->where('id', $sound)->value('supplier_type'));
    }

    public function test_the_doors_are_the_suppliers_section_and_the_company(): void
    {
        $supplier = $this->store(['name' => 'استوديو الضوء', 'service' => 'تصوير']);
        $seller = User::create(['business_id' => $this->business->id, 'name' => 'بائع', 'email' => 'seller@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط']);
        $this->actingAs($seller)->get(route('admin.suppliers.show', $supplier->id))->assertForbidden();
        $this->actingAs($seller)->put(route('admin.suppliers.update', $supplier->id), ['name' => 'x', 'service' => 'y'])->assertForbidden();

        $this->journeyCompany('شركة أخرى');
        $this->actingAs($this->owner)->get(route('admin.suppliers.show', $supplier->id))->assertNotFound();
        $this->actingAs($this->owner)->put(route('admin.suppliers.update', $supplier->id), ['name' => 'x', 'service' => 'y'])->assertNotFound();
        $this->assertSame('تصوير', $supplier->refresh()->service);
    }
}
