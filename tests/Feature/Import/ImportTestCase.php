<?php

namespace Tests\Feature\Import;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\ImportDataset;
use App\Models\ImportRun;
use App\Models\JobTitle;
use App\Models\Setting;
use App\Models\User;
use App\Support\Import\Approval;
use App\Support\Import\Cutover;
use App\Support\Import\Datasets;
use App\Support\Import\Runner;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * الأساسُ المشترك لاختبارات المرحلة 9 — شركةٌ ومالكٌ وخزنةُ عيّناتٍ مجهَّلة.
 *
 * العيّناتُ في `tests/Fixtures/import` مصنوعةٌ لا حقيقيّة: لا عميلَ فيها ولا
 * رقمَ هاتفٍ لأحد. والقرصُ الخاصُّ مزيَّف (`Storage::fake`) فلا ملفَّ يبقى.
 */
abstract class ImportTestCase extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected User $manager;

    protected User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('local');

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط', 'email' => 'hello@hadath.om']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير فرع', 'role' => 'manager']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'محاسب', 'role' => 'accountant']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);
        Ledger::seedChart($this->business->id);

        $this->owner = User::create(['business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@hadath.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط']);
        $this->manager = User::create(['business_id' => $this->business->id, 'name' => 'المدير', 'email' => 'manager@hadath.om',
            'password' => bcrypt('password'), 'role' => 'manager', 'status' => 'نشط']);
        $this->clerk = User::create(['business_id' => $this->business->id, 'name' => 'محاسب', 'email' => 'clerk@hadath.om',
            'password' => bcrypt('password'), 'role' => 'accountant', 'status' => 'نشط']);

        $this->actingAs($this->owner);
    }

    protected function fixture(string $name): string
    {
        return base_path('tests/Fixtures/import/'.$name);
    }

    /** تسجيلُ عيّنةٍ وقراءتُها */
    protected function register(string $fixture, string $category, array $data = []): ImportDataset
    {
        $dataset = Datasets::registerPath((int) $this->business->id, $this->fixture($fixture), ['category' => $category, 'name' => $fixture] + $data, $this->owner);

        return Datasets::profile($dataset, $this->owner);
    }

    /** خريطةٌ من أزواج «عمود المصدر ⇽ الحقل» */
    protected function map(ImportDataset $dataset, array $pairs, array $constants = [], array $extra = []): ImportDataset
    {
        $columns = [];
        foreach ($pairs as $source => $target) {
            $columns[] = ['source' => $source, 'target' => $target] + ($extra[$source] ?? []);
        }

        return Datasets::map($dataset, ['columns' => $columns, 'constants' => $constants], $this->owner);
    }

    protected function cutover(?string $date): void
    {
        Cutover::set((int) $this->business->id, $date);
    }

    protected function dryRun(ImportDataset $dataset): ImportRun
    {
        return Runner::dryRun($dataset->fresh(), $this->owner);
    }

    /** تجربةٌ ثمّ اعتمادٌ ثمّ استيراد — الطريقُ كلُّه */
    protected function importAll(ImportDataset $dataset, ?User $as = null): ImportRun
    {
        $as ??= $this->owner;
        $this->dryRun($dataset);
        Approval::approve($dataset->fresh(), $as);

        return Runner::import($dataset->fresh(), $as);
    }

    /* ─────────────────────────── خرائطُ العيّنات ─────────────────────────── */

    protected function customers(): ImportDataset
    {
        $d = $this->register('customers.csv', 'customers');

        return $this->map($d, ['المعرف' => 'external_id', 'اسم العميل' => 'name', 'الاسم القانوني' => 'legal_name', 'الهاتف' => 'phone',
            'البريد' => 'email', 'الرقم الضريبي' => 'tax_number', 'جهة الاتصال' => 'contact_person', 'ملاحظات' => 'notes']);
    }

    protected function suppliers(): ImportDataset
    {
        $d = $this->register('suppliers.csv', 'suppliers');

        return $this->map($d, ['id' => 'external_id', 'name' => 'name', 'phone' => 'phone', 'email' => 'email', 'contact' => 'contact_person']);
    }

    protected function services(): ImportDataset
    {
        $d = $this->register('services.csv', 'services');

        return $this->map($d, ['الرمز' => 'sku', 'اسم الخدمة' => 'name', 'النوع' => 'kind', 'السعر' => 'price', 'الضريبة' => 'tax']);
    }

    protected function projects(): ImportDataset
    {
        $d = $this->register('projects.csv', 'projects');

        return $this->map($d, ['الرمز' => 'code', 'اسم المشروع' => 'name', 'العميل' => 'customer', 'الحالة' => 'status',
            'البداية' => 'starts_at', 'النهاية' => 'ends_at', 'القيمة' => 'approved_value', 'الإقفال' => 'closed_at']);
    }

    protected function invoices(): ImportDataset
    {
        $d = $this->register('invoices.csv', 'customer_invoices');
        $d = $this->map($d, ['رقم الفاتورة' => 'number', 'العميل' => 'customer', 'التاريخ' => 'issued_at', 'الاستحقاق' => 'due_at',
            'البند' => 'description', 'الكمية' => 'quantity', 'سعر الوحدة' => 'unit_price', 'الضريبة%' => 'tax_rate',
            'الإجمالي' => 'total', 'المشروع' => 'project', 'الحالة' => 'status']);

        return Datasets::strategy($d, ImportDataset::HISTORICAL, $this->owner);
    }

    protected function payments(): ImportDataset
    {
        $d = $this->register('payments.csv', 'payments');
        $d = $this->map($d, ['رقم السند' => 'number', 'العميل' => 'customer', 'المبلغ' => 'amount', 'الوسيلة' => 'method',
            'التاريخ' => 'occurred_at', 'الفاتورة' => 'invoice']);

        return Datasets::strategy($d, ImportDataset::HISTORICAL, $this->owner);
    }

    protected function expenses(): ImportDataset
    {
        $d = $this->register('expenses.csv', 'expenses');
        $d = $this->map($d, ['النوع' => 'type', 'الوصف' => 'description', 'المبلغ' => 'amount', 'الوسيلة' => 'method',
            'التاريخ' => 'spent_at', 'الحالة' => 'status', 'المشروع' => 'project']);

        return Datasets::strategy($d, ImportDataset::HISTORICAL, $this->owner);
    }

    protected function supplierInvoices(): ImportDataset
    {
        $d = $this->register('supplier_invoices.csv', 'supplier_invoices');
        $d = $this->map($d, ['المورد' => 'supplier', 'رقم السند' => 'supplier_ref', 'التاريخ' => 'issued_at', 'الصافي' => 'subtotal',
            'الضريبة' => 'tax', 'الإجمالي' => 'total', 'المسدد' => 'paid', 'الحالة' => 'status', 'المشروع' => 'project']);

        return Datasets::strategy($d, ImportDataset::HISTORICAL, $this->owner);
    }

    protected function openingBalances(): ImportDataset
    {
        $d = $this->register('opening_balances.csv', 'opening_balances');
        $d = $this->map($d, ['النوع' => 'party_type', 'الطرف' => 'party', 'الرصيد' => 'amount', 'المرجع' => 'reference']);

        return Datasets::strategy($d, ImportDataset::OPENING_BALANCE, $this->owner);
    }
}
