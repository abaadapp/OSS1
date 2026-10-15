<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\PurchaseOrder;
use App\Models\ServiceCategory;
use App\Models\Supplier;
use App\Models\User;
use App\Support\PurchaseOrders;
use App\Support\ServiceProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\WalksTheProjectJourney;
use Tests\TestCase;

/**
 * مقدّمُ الخدمة مورّدٌ بخدماتٍ — لا عالمٌ ماليٌّ ثانٍ (المرحلة 14).
 *
 * المورّدُ منتجاتٍ أو خدماتٍ أو كليهما، وله خدماتٌ من كتالوجٍ تديره الشركة
 * يُبحث به ويُرشَّح. وصفحتُه تقول بياناته وخدماتِه وأوامرَه وسنداتِه ومشاريعَه.
 * والكتالوجُ بالشركة، والأبوابُ بقسم «الموردين» نفسِه.
 */
class AServiceProviderIsASupplierWithServicesTest extends TestCase
{
    use RefreshDatabase;
    use WalksTheProjectJourney;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Mail::fake();
        $this->journeyCompany();
        ServiceProviders::ensureCategories((int) $this->business->id);
    }

    private function service(string $name): ServiceCategory
    {
        return ServiceCategory::where('business_id', $this->business->id)->where('name', $name)->firstOrFail();
    }

    private function store(array $data): void
    {
        $this->actingAs($this->owner)->post(route('admin.suppliers.store'), $data)->assertSessionHasNoErrors();
    }

    public function test_a_supplier_is_a_product_supplier_a_service_provider_or_both(): void
    {
        $this->store(['name' => 'مخازن الورد']);
        $this->store(['name' => 'شركة النور للصوتيات', 'supplier_type' => 'services', 'whatsapp' => '97770000',
            'service_ids' => [$this->service('صوتيات')->id, $this->service('إضاءة')->id, $this->service('شاشات')->id]]);
        $this->store(['name' => 'المتكاملة', 'supplier_type' => 'both', 'service_ids' => [$this->service('أثاث')->id]]);

        $this->assertSame(Supplier::PRODUCTS, Supplier::where('name', 'مخازن الورد')->sole()->supplier_type, 'من لا يُقال نوعُه مورّدُ منتجات');
        $noor = Supplier::where('name', 'شركة النور للصوتيات')->sole();
        $this->assertSame(Supplier::SERVICES, $noor->supplier_type);
        $this->assertTrue($noor->providesServices());
        $this->assertSame('97770000', $noor->whatsapp);
        $this->assertEqualsCanonicalizing(['صوتيات', 'إضاءة', 'شاشات'], $noor->serviceCategories->pluck('name')->all(), 'مورّدٌ بعدّة خدمات');
        $this->assertSame(Supplier::BOTH, Supplier::where('name', 'المتكاملة')->sole()->supplier_type);
        $this->assertTrue(ActivityLog::where('description', 'like', 'أضاف إلى خدمات المورد شركة النور للصوتيات:%')->exists());

        $this->actingAs($this->owner)->post(route('admin.suppliers.store'), ['name' => 'خطأ', 'supplier_type' => 'magic'])->assertSessionHasErrors('supplier_type');
    }

    public function test_suppliers_are_found_by_the_service_they_provide(): void
    {
        $this->store(['name' => 'استوديو الضوء', 'supplier_type' => 'services', 'service_ids' => [$this->service('تصوير')->id, $this->service('فيديو')->id]]);
        $this->store(['name' => 'شركة النقل السريع', 'supplier_type' => 'services', 'service_ids' => [$this->service('نقل')->id]]);
        $this->store(['name' => 'مخازن الورد']);

        $props = $this->actingAs($this->owner)->get(route('admin.suppliers.index'))->assertOk()->viewData('page')['props'];
        $photography = collect($props['services'])->firstWhere('name', 'تصوير')['id'];
        $providers = collect($props['suppliers'])->filter(fn ($s) => collect($s['services'])->pluck('id')->contains($photography))->pluck('name')->values()->all();

        $this->assertSame(['استوديو الضوء'], $providers, 'مقدّم خدمة ← تصوير');
        $this->assertCount(16, $props['services'], 'كتالوجُ البداية — يُدار من الشاشة');
        $this->assertSame('مقدم خدمات', $props['types']['services']);
    }

    public function test_the_supplier_page_shows_services_orders_invoices_and_projects(): void
    {
        $this->store(['name' => 'استوديو الضوء', 'supplier_type' => 'services', 'service_ids' => [$this->service('تصوير')->id]]);
        $supplier = Supplier::sole();
        $project = $this->acceptedProject(1000, 2);
        PurchaseOrder::create(['business_id' => $this->business->id, 'number' => 'PO-000001', 'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name,
            'status' => PurchaseOrders::SENT, 'items_subtotal' => 300, 'tax' => 0, 'total' => 300, 'ordered_at' => now(), 'project_id' => $project->id]);

        $this->actingAs($this->owner)->get(route('admin.suppliers.show', $supplier->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Admin/Suppliers/Show')
                ->where('supplier.services.0.name', 'تصوير')
                ->where('orders.0.number', 'PO-000001')
                ->where('orders.0.project.code', $project->code)
                ->where('projects.0.code', $project->code));
    }

    public function test_the_services_catalog_is_managed_and_a_used_service_is_deactivated_not_deleted(): void
    {
        $this->actingAs($this->owner)->post(route('admin.suppliers.services.store'), ['name' => 'ألعاب نارية'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('admin.suppliers.services.store'), ['name' => 'ألعاب نارية'])
            ->assertSessionHasErrors(['service' => 'هذه الخدمة موجودة في الكتالوج.']);

        $this->store(['name' => 'شركة الأمن', 'supplier_type' => 'services', 'service_ids' => [$this->service('أمن')->id]]);
        $this->actingAs($this->owner)->delete(route('admin.suppliers.services.destroy', $this->service('أمن')->id))
            ->assertSessionHasErrors(['service' => 'يقدّم هذه الخدمة مورد أو أكثر — عطّلها بدل حذفها.']);
        $this->actingAs($this->owner)->put(route('admin.suppliers.services.update', $this->service('أمن')->id), ['name' => 'أمن', 'active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($this->service('أمن')->active);

        $this->actingAs($this->owner)->delete(route('admin.suppliers.services.destroy', $this->service('ألعاب نارية')->id))->assertSessionHasNoErrors();
        $this->assertSame(0, ServiceCategory::where('name', 'ألعاب نارية')->count());
    }

    public function test_the_doors_are_the_suppliers_section_and_the_catalog_is_per_company(): void
    {
        $this->store(['name' => 'استوديو الضوء', 'supplier_type' => 'services', 'service_ids' => [$this->service('تصوير')->id]]);
        $supplier = Supplier::sole();
        $seller = User::create(['business_id' => $this->business->id, 'name' => 'بائع', 'email' => 'seller@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط']);
        $this->actingAs($seller)->get(route('admin.suppliers.show', $supplier->id))->assertForbidden();

        $ours = $this->service('تصوير')->id;
        $mine = $this->business;
        $this->journeyCompany('شركة أخرى');
        $this->actingAs($this->owner)->get(route('admin.suppliers.show', $supplier->id))->assertNotFound();
        /* وخدمةٌ من كتالوج شركةٍ أخرى لا تُلصق بمورّدها */
        $this->actingAs($this->owner)->post(route('admin.suppliers.store'), ['name' => 'مورّدهم', 'supplier_type' => 'services', 'service_ids' => [$ours]])
            ->assertSessionHasErrors('service_ids.0');
        $this->assertSame(1, Supplier::count());
        $this->assertNotNull($mine);
    }
}
