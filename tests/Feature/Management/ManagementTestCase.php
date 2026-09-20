<?php

namespace Tests\Feature\Management;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\SupplierInvoices;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * الأرضيّةُ المشتركة لاختبارات المرحلة 11 — شركتان وخمسةُ أدوار ومستنداتٌ تُصنع بسطر.
 *
 * المالكُ يقرأ ويعتمد؛ والمديرُ يقرأ ويُعدّ ولا يعتمد؛ والمحاسبُ يقرأ المالَ
 * والمشاريعَ والعملاءَ ولا يُعدّ موازنةً؛ والبائعُ لا يفتح الإدارة؛ والغريبُ
 * من شركةٍ أخرى لا يرى شيئًا من هنا. والزمنُ مثبَّت: 20 سبتمبر 2026.
 */
abstract class ManagementTestCase extends TestCase
{
    protected Business $business;

    protected Business $other;

    protected User $owner;

    protected User $manager;

    protected User $accountant;

    protected User $seller;

    protected User $stranger;

    protected Customer $customer;

    protected Supplier $supplier;

    protected BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow(Carbon::parse('2026-09-20 10:00:00', 'Asia/Muscat'));

        $this->business = $this->company('افنت بلس', 'hello@eventplus.om');
        $this->other = $this->company('شركة أخرى', 'hello@other.om');

        $make = fn (Business $b, string $name, string $email, string $role, ?array $permissions = null) => User::create([
            'business_id' => $b->id, 'name' => $name, 'email' => $email, 'password' => bcrypt('password'),
            'role' => $role, 'status' => 'نشط', 'permissions' => $permissions, 'phone' => '9'.random_int(1000000, 9999999),
        ]);

        $this->owner = $make($this->business, 'المالك', 'owner@eventplus.om', 'admin');
        $this->manager = $make($this->business, 'المدير', 'manager@eventplus.om', 'manager');
        $this->accountant = $make($this->business, 'المحاسب', 'books@eventplus.om', 'accountant');
        $this->seller = $make($this->business, 'البائع', 'sales@eventplus.om', 'sales');
        $this->stranger = $make($this->other, 'غريب', 'owner@other.om', 'admin');

        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة الطاقة', 'phone' => '99887766']);
        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّد المسرح']);
        $this->bank = BankAccount::create(['business_id' => $this->business->id, 'bank_name' => 'بنك مسقط', 'label' => 'الحساب الرئيسي', 'active' => true, 'is_primary' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function company(string $name, string $email): Business
    {
        $b = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط', 'email' => $email]);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $b->id, 'name' => 'مدير', 'role' => 'admin']);
        Currency::create(['business_id' => $b->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Setting::create(['business_id' => $b->id, 'key' => 'vat_rate', 'value' => '5']);
        Ledger::seedChart($b->id);

        return $b;
    }

    protected function project(string $code = 'EP-2026-001', float $approved = 10000, array $over = [], ?Business $b = null, ?Customer $c = null): Project
    {
        $b ??= $this->business;

        return Project::create($over + [
            'business_id' => $b->id, 'customer_id' => ($c ?? $this->customer)->id,
            'code' => $code, 'name' => 'مؤتمر '.$code, 'status' => Project::IN_PROGRESS,
            'starts_at' => '2026-09-05', 'ends_at' => '2026-09-06', 'owner_id' => $this->manager->id, 'approved_value' => $approved,
        ]);
    }

    /** فاتورةٌ صادرة — بندٌ واحد بسعرٍ صافٍ والضريبةُ من إعداد الشركة (5٪) */
    protected function invoice(float $net, string $issuedAt = '2026-09-10', ?Project $project = null, ?Customer $customer = null, array $data = []): CustomerInvoice
    {
        $invoice = CustomerInvoices::create((int) $this->business->id, $customer ?? $this->customer,
            $data + ['project_id' => $project?->id, 'issued_at' => $issuedAt, 'due_at' => Carbon::parse($issuedAt)->addDays(30)->toDateString()],
            [['description' => 'تنظيم', 'quantity' => 1, 'unit_price' => $net]], $this->owner->id);
        $invoice = CustomerInvoices::issue($invoice->fresh(), $this->owner->id);
        /* الإصدارُ يختم بتاريخ اليوم — والاختبارُ يحتاج تاريخًا بعينه ليُقاس على المدّة */
        $invoice->forceFill(['issued_at' => Carbon::parse($issuedAt)->toDateString()])->save();

        return $invoice->fresh();
    }

    protected function pay(CustomerInvoice $invoice, ?float $amount = null, string $at = '2026-09-12', string $method = 'نقدي'): CustomerPayment
    {
        $amount ??= (float) $invoice->total;

        return CustomerPayments::record((int) $this->business->id, $invoice->customer, $amount,
            ['method' => $method, 'bank_account_id' => $this->bank->id, 'occurred_at' => $at], [$invoice->id => $amount], $this->owner->id);
    }

    protected function supplierInvoice(float $subtotal, float $tax = 0, ?Project $project = null, string $issuedAt = '2026-09-08', bool $approve = true): SupplierInvoice
    {
        $invoice = SupplierInvoices::create((int) $this->business->id, [
            'supplier_id' => $this->supplier->id, 'supplier_ref' => 'R-'.random_int(1000, 9999), 'issued_at' => $issuedAt,
            'subtotal' => $subtotal, 'tax' => $tax,
        ], $this->owner);
        $invoice->update(['project_id' => $project?->id]);
        if ($approve) {
            SupplierInvoices::approve($invoice->fresh(), $this->owner, 'اعتمادٌ مباشر');
        }

        return $invoice->fresh();
    }

    protected function expense(float $amount, string $at = '2026-09-09', ?Project $project = null, string $status = 'مدفوع', string $type = 'نقل'): Expense
    {
        return Expense::create([
            'business_id' => $this->business->id, 'reference' => 'EXP-'.random_int(1000, 9999), 'type' => $type,
            'description' => 'شاحنة', 'amount' => $amount, 'method' => 'نقدي',
            'status' => $status, 'spent_at' => $at, 'project_id' => $project?->id,
        ]);
    }

    protected function product(string $name = 'بطاقة', int $quantity = 100, float $cost = 0.5, ?Business $b = null): Product
    {
        return Product::create([
            'business_id' => ($b ?? $this->business)->id, 'name' => $name, 'price' => $cost * 3, 'cost' => $cost,
            'quantity' => $quantity, 'alert_qty' => 1, 'active' => true, 'sku' => 'SKU-'.random_int(1000, 9999),
        ]);
    }
}
