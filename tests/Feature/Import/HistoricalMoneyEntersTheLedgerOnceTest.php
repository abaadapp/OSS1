<?php

namespace Tests\Feature\Import;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\ImportDataset;
use App\Models\ImportRun;
use App\Models\JournalLine;
use App\Models\Project;
use App\Models\SupplierInvoice;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Import\Approval;
use App\Support\Import\Datasets;
use App\Support\Import\Provenance;
use App\Support\Import\Runner;
use App\Support\Ledger;
use App\Support\ProjectFinancials;
use App\Support\Revenue;
use App\Support\SupplierInvoices;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * المالُ التاريخيّ يدخل الدفترَ مرّةً — بخدماته، وبتاريخه، وبأساسه (§14–§17).
 *
 * الفواتيرُ والتحصيلاتُ والمصروفاتُ وسنداتُ الموردين من أبوابها؛ والرصيدُ
 * الافتتاحيُّ ذمّةٌ على الأرباح المحتجزة لا إيراد؛ وتاريخُ التحويل يمنع أن
 * تظهر الحقيقةُ الواحدة مرّتين.
 */
class HistoricalMoneyEntersTheLedgerOnceTest extends ImportTestCase
{
    private function masterData(): void
    {
        $customers = $this->register('customers.csv', 'customers');
        $customers = $this->map($customers, ['المعرف' => 'external_id', 'اسم العميل' => 'name']);
        Datasets::resolve($customers, ['4' => 'create'], $this->owner);
        $this->importAll($customers);
        $suppliers = $this->suppliers();
        Datasets::resolve($suppliers, ['3' => 'skip'], $this->owner);
        $this->importAll($suppliers);
        $this->importAll($this->projects());
    }

    private function balance(string $key): float
    {
        $account = Ledger::account((int) $this->business->id, $key);

        return round((float) JournalLine::where('account_id', $account->id)->sum('debit') - (float) JournalLine::where('account_id', $account->id)->sum('credit'), 3);
    }

    public function test_historical_invoices_are_issued_with_their_own_numbers_dates_vat_and_ledger_entries(): void
    {
        $this->masterData();
        $this->cutover('2024-01-01');

        $d = $this->invoices();
        $dry = $this->dryRun($d);
        $this->assertSame(ImportRun::SUCCESS, $dry->status, json_encode($dry->findings, JSON_UNESCAPED_UNICODE));
        $this->assertSame(3, $dry->totals['units'], 'أربعةُ صفوفٍ ثلاثُ فواتير');
        $this->assertEqualsWithDelta(12600.0 + 4200.0, $dry->financial['receivables'], 0.0005, 'الملغاةُ لا تُحسب');
        $this->assertEqualsWithDelta(16000.0, $dry->financial['revenue'], 0.0005);
        $this->assertEqualsWithDelta(800.0, $dry->financial['tax'], 0.0005);
        $this->assertSame(0, CustomerInvoice::count(), 'التجربةُ لا تُصدر فاتورة');

        $run = $this->importAll($d);
        $this->assertSame(ImportRun::SUCCESS, $run->status);
        $this->assertSame(3, CustomerInvoice::count());

        $first = CustomerInvoice::where('number', 'HIST-INV-2024-001')->firstOrFail();
        $this->assertSame(CustomerInvoice::ISSUED, $first->status);
        $this->assertSame('2024-03-20', $first->issued_at->toDateString(), 'بتاريخها الأصليّ لا اليوم');
        $this->assertSame('INV-2024-001', $first->external_reference);
        $this->assertSame(Provenance::HISTORICAL, $first->origin);
        $this->assertCount(2, $first->items);
        $this->assertEqualsWithDelta(12600.0, (float) $first->total, 0.0005);
        $this->assertEqualsWithDelta(600.0, (float) $first->tax_total, 0.0005);
        $this->assertSame((int) Project::where('name', 'مؤتمر الطاقة 2024')->value('id'), (int) $first->project_id);

        $cancelled = CustomerInvoice::where('number', 'HIST-INV-2024-003')->firstOrFail();
        $this->assertSame(CustomerInvoice::CANCELLED, $cancelled->status);

        /* والدفترُ: ذممٌ 16800 · مبيعاتٌ 16000 · ضريبةٌ 800 — بتواريخها */
        $this->assertEqualsWithDelta(16800.0, $this->balance('receivable'), 0.0005);
        $this->assertEqualsWithDelta(-16000.0, $this->balance('sales'), 0.0005);
        $this->assertEqualsWithDelta(-800.0, $this->balance('tax_payable'), 0.0005);
        $this->assertEqualsWithDelta(12600.0, Revenue::total((int) $this->business->id, Carbon::parse('2024-03-01'), Carbon::parse('2024-04-01')), 0.0005, 'إيرادُ مارس 2024 يُقرأ في مارس 2024');

        /* والمطابقة صفر */
        $rec = $d->fresh()->reconciliation;
        $this->assertTrue($rec['balanced']);
        $this->assertEqualsWithDelta(0.0, $rec['financial']['difference'], 0.0005);
        $this->assertEqualsWithDelta(0.0, $rec['financial']['tax_difference'], 0.0005);
        $this->assertSame(ImportDataset::VERIFIED, $d->fresh()->status);

        /* وملفُّ المشروع يقرأ فاتورتَه */
        $this->assertEqualsWithDelta(12600.0, ProjectFinancials::summary(Project::where('name', 'مؤتمر الطاقة 2024')->first())['invoiced'], 0.0005);
    }

    public function test_a_source_total_that_disagrees_with_the_lines_stops_the_invoice(): void
    {
        $this->masterData();
        $this->cutover('2024-01-01');

        $d = $this->register('invoices.csv', 'customer_invoices');
        /* الإجماليُّ يُربط بعمود «الكمية» عمدًا — فيختلف عن المحسوب */
        $d = $this->map($d, ['رقم الفاتورة' => 'number', 'العميل' => 'customer', 'التاريخ' => 'issued_at', 'البند' => 'description',
            'الكمية' => 'quantity', 'سعر الوحدة' => 'unit_price', 'الضريبة%' => 'tax_rate', 'الحالة' => 'status'], ['total' => '999']);
        Datasets::strategy($d, ImportDataset::HISTORICAL, $this->owner);

        $dry = $this->dryRun($d);
        $this->assertSame(ImportRun::FAILED, $dry->status);
        $this->assertSame(3, $dry->totals['errors']);
        $this->assertStringContainsString('لا يطابق المحسوبَ', json_encode($dry->findings, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, CustomerInvoice::count());
    }

    public function test_payments_link_to_historical_invoices_and_over_allocation_is_refused(): void
    {
        $this->masterData();
        $this->cutover('2024-01-01');
        $this->importAll($this->invoices());

        $d = $this->payments();
        $run = $this->importAll($d);
        $this->assertSame(ImportRun::SUCCESS, $run->status, json_encode($run->findings, JSON_UNESCAPED_UNICODE));
        $this->assertSame(3, CustomerPayment::count());

        $invoice = CustomerInvoice::where('number', 'HIST-INV-2024-001')->firstOrFail();
        $this->assertEqualsWithDelta(0.0, $invoice->outstanding(), 0.0005, 'سُدّدت بالتحصيل المستورَد');
        /* والدفعةُ بلا فاتورةٍ تُخصَّص على الأقدم كما يفعل النظامُ — 4200 − 2000 − 500 */
        $this->assertEqualsWithDelta(1700.0, CustomerInvoice::where('number', 'HIST-INV-2024-002')->first()->outstanding(), 0.0005);

        $payment = CustomerPayment::where('external_reference', 'RCT-100')->firstOrFail();
        $this->assertSame(Provenance::HISTORICAL, $payment->origin);
        $this->assertSame('2024-03-25', $payment->occurred_at->toDateString());
        $this->assertEqualsWithDelta(16800.0 - 12600.0 - 2500.0, $this->balance('receivable'), 0.0005, 'الذممُ تنقص بالتحصيل المخصَّص وغير المخصَّص');
        $this->assertEqualsWithDelta(15100.0, round($this->balance('bank') + $this->balance('cash'), 3), 0.0005, 'تحويلٌ 12600 إلى البنك ونقدٌ 2500 إلى الصندوق');

        /* دفعةٌ تفوق ما بقي على فاتورتها تُوقَف */
        file_put_contents(storage_path('framework/testing/over.csv'), "رقم السند,العميل,المبلغ,الوسيلة,التاريخ,الفاتورة\nRCT-200,C-3,5000,نقدي,12/12/2025,INV-2024-002\n");
        $over = Datasets::registerPath((int) $this->business->id, storage_path('framework/testing/over.csv'), ['category' => 'payments', 'name' => 'over'], $this->owner);
        $over = Datasets::profile($over, $this->owner);
        $over = $this->map($over, ['رقم السند' => 'number', 'العميل' => 'customer', 'المبلغ' => 'amount', 'الوسيلة' => 'method', 'التاريخ' => 'occurred_at', 'الفاتورة' => 'invoice']);
        Datasets::strategy($over, ImportDataset::HISTORICAL, $this->owner);
        $dry = $this->dryRun($over);
        $this->assertSame(1, $dry->totals['errors']);
        $this->assertStringContainsString('تفوق ما بقي', json_encode($dry->findings, JSON_UNESCAPED_UNICODE));
    }

    public function test_expenses_post_when_paid_and_stay_committed_when_not(): void
    {
        $this->masterData();
        $this->cutover('2024-01-01');

        $run = $this->importAll($this->expenses());
        $this->assertSame(ImportRun::SUCCESS, $run->status, json_encode($run->findings, JSON_UNESCAPED_UNICODE));
        $this->assertSame(3, Expense::count());

        $paid = Expense::where('description', 'شاحنة المعدات')->firstOrFail();
        $this->assertSame(Expense::PAID, $paid->status);
        $this->assertNotNull($paid->transaction_id, 'المسدَّد له حركةٌ وقيد');
        $this->assertSame(Provenance::HISTORICAL, $paid->origin);
        $this->assertStringStartsWith('HIST-', $paid->reference);

        $unpaid = Expense::where('description', 'قهوة الحفل')->firstOrFail();
        $this->assertSame(Expense::UNPAID, $unpaid->status);
        $this->assertNull($unpaid->transaction_id);

        $this->assertEqualsWithDelta(3150.5, ProjectFinancials::summary(Project::where('name', 'مؤتمر الطاقة 2024')->first())['expenses_paid'], 0.0005);
        $this->assertEqualsWithDelta(80.0, ProjectFinancials::summary(Project::where('name', 'حفل التخرج')->first())['committed'], 0.0005);
        $this->assertEqualsWithDelta(3150.5, $run->reconciliation['financial']['imported_total'] - 80.0, 0.0005);
    }

    public function test_supplier_invoices_post_net_of_vat_and_a_paid_one_settles_from_the_bank(): void
    {
        $this->masterData();
        $this->cutover('2024-01-01');

        $run = $this->importAll($this->supplierInvoices());
        $this->assertSame(ImportRun::SUCCESS, $run->status, json_encode($run->findings, JSON_UNESCAPED_UNICODE));
        $this->assertSame(3, SupplierInvoice::count());

        $paid = SupplierInvoice::where('supplier_ref', 'PR-77')->firstOrFail();
        $this->assertSame(SupplierInvoices::APPROVED, $paid->approval_status);
        $this->assertEqualsWithDelta(1050.0, (float) $paid->paid, 0.0005);
        $this->assertEqualsWithDelta(0.0, $paid->outstanding(), 0.0005);
        $this->assertSame(Provenance::HISTORICAL, $paid->origin);

        $pending = SupplierInvoice::where('supplier_ref', 'LT-6')->firstOrFail();
        $this->assertSame(SupplierInvoices::PENDING, $pending->approval_status);

        /* الدفترُ: مخزونٌ 1600 صافيًا · ضريبةٌ 80 مدينة · ذممٌ 630 باقية بعد سداد 1050 من البنك */
        /* المرحلة 12 §18: سندٌ تاريخيٌّ بلا أمرِ شراءٍ لأصنافٍ مخزونة — مشترياتٌ مباشرة لا مخزون */
        $this->assertEqualsWithDelta(1600.0, $this->balance('direct_purchases'), 0.0005);
        $this->assertEqualsWithDelta(80.0, $this->balance('tax_payable'), 0.0005);
        $this->assertEqualsWithDelta(-630.0, $this->balance('payable'), 0.0005);
        $this->assertEqualsWithDelta(-1050.0, $this->balance('bank'), 0.0005);

        $rec = $run->reconciliation['financial'];
        $this->assertEqualsWithDelta(1600.0, $rec['imported_total'], 0.0005);
        $this->assertEqualsWithDelta(0.0, $rec['difference'], 0.0005);
        $this->assertEqualsWithDelta(630.0, $rec['imported_outstanding'], 0.0005);
        $this->assertEqualsWithDelta(630.0, ProjectFinancials::summary(Project::where('name', 'حفل التخرج')->first())['supplier_outstanding'], 0.0005);
    }

    public function test_opening_balances_are_receivables_on_retained_earnings_not_revenue(): void
    {
        $this->masterData();
        $this->cutover('2026-01-01');

        $d = $this->openingBalances();
        $dry = $this->dryRun($d);
        $this->assertSame(ImportRun::SUCCESS, $dry->status, json_encode($dry->findings, JSON_UNESCAPED_UNICODE));
        $this->assertEqualsWithDelta(1750.25, $dry->financial['opening_receivables'], 0.0005);
        $this->assertEqualsWithDelta(630.0, $dry->financial['opening_payables'], 0.0005);

        $run = $this->importAll($d);
        $this->assertSame(ImportRun::SUCCESS, $run->status);

        $ob = CustomerInvoice::where('is_opening_balance', true)->get();
        $this->assertCount(2, $ob);
        $one = $ob->firstWhere('customer_id', Customer::where('name', 'وزارة الثقافة')->value('id'));
        $this->assertSame(CustomerInvoice::ISSUED, $one->status);
        $this->assertSame('2026-01-01', $one->issued_at->toDateString(), 'بتاريخ التحويل');
        $this->assertEqualsWithDelta(500.0, (float) $one->total, 0.0005);
        $this->assertEqualsWithDelta(0.0, (float) $one->tax_total, 0.0005, 'بلا ضريبة');
        $this->assertEqualsWithDelta(500.0, $one->outstanding(), 0.0005);

        /* الدفتر: ذممُ عملاء 1750.25 مقابلَ الأرباح المحتجزة — والمبيعاتُ صفر */
        $this->assertEqualsWithDelta(1750.25, $this->balance('receivable'), 0.0005);
        $this->assertEqualsWithDelta(-630.0, $this->balance('payable'), 0.0005);
        $this->assertEqualsWithDelta(-1120.25, $this->balance('retained_earnings'), 0.0005, '1750.25 دائنة − 630 مدينة');
        $this->assertEqualsWithDelta(0.0, $this->balance('sales'), 0.0005);
        $this->assertEqualsWithDelta(0.0, $this->balance('inventory'), 0.0005);
        $this->assertEqualsWithDelta(0.0, Revenue::total((int) $this->business->id, Carbon::parse('2026-01-01'), Carbon::parse('2026-02-01')), 0.0005, 'ليس إيرادًا');

        /* والتحصيلُ يُخصَّص عليها كأيّ فاتورة */
        CustomerPayments::record((int) $this->business->id, $one->customer, 200, ['method' => 'نقدي', 'occurred_at' => '2026-01-10'], [$one->id => 200], $this->owner->id);
        $this->assertEqualsWithDelta(300.0, $one->fresh()->outstanding(), 0.0005);

        /* ورصيدٌ ثانٍ للطرف نفسه يُتخطّى */
        $again = ImportDataset::find($d->id);
        $again->update(['status' => ImportDataset::READY]);
        Approval::approve($again->fresh(), $this->owner);
        $second = Runner::import($again->fresh(), $this->owner);
        $this->assertSame(0, $second->totals['created']);
        $this->assertSame(2, CustomerInvoice::where('is_opening_balance', true)->count());
    }

    public function test_the_cutover_date_forbids_the_same_debt_twice(): void
    {
        $this->masterData();

        /* بلا تحويلٍ لا يُستورد رصيدٌ افتتاحيّ */
        $dry = $this->dryRun($this->openingBalances());
        $this->assertSame(ImportRun::FAILED, $dry->status);
        $this->assertStringContainsString('حدّد تاريخ التحويل', json_encode($dry->findings, JSON_UNESCAPED_UNICODE));

        /* وبتحويلٍ في 2025: فواتيرُ 2024 لا تُستورد مستنداتٍ */
        $this->cutover('2025-01-01');
        $dry = $this->dryRun($this->invoices());
        $this->assertSame(ImportRun::FAILED, $dry->status);
        $this->assertSame(2, $dry->totals['errors'], 'فاتورتا 2024');
        $this->assertSame(1, $dry->totals['created'], 'فاتورةُ ديسمبر 2025 وحدها');
        $this->assertStringContainsString('قبل تاريخ التحويل', json_encode($dry->findings, JSON_UNESCAPED_UNICODE));

        /* وبتحويلٍ قديم تُستورد الفواتيرُ كلُّها — ثمّ لا يُقبل رصيدٌ افتتاحيٌّ لعميلٍ عليه فاتورةٌ تاريخيّة باقية */
        $this->cutover('2024-01-01');
        $this->importAll(ImportDataset::where('category', 'customer_invoices')->firstOrFail());
        $this->cutover('2026-01-01');
        $ob = ImportDataset::where('category', 'opening_balances')->firstOrFail();
        $dry = $this->dryRun($ob);
        $this->assertSame(ImportRun::FAILED, $dry->status);
        $this->assertStringContainsString('يعدّها مرّتين', json_encode($dry->findings, JSON_UNESCAPED_UNICODE));
    }

    public function test_a_credit_note_on_a_historical_invoice_keeps_the_native_semantics(): void
    {
        $this->masterData();
        $this->cutover('2024-01-01');
        $this->importAll($this->invoices());

        $invoice = CustomerInvoice::where('number', 'HIST-INV-2024-002')->firstOrFail();
        CustomerInvoices::creditNote($invoice, 1050, 50, 'تخفيض', $this->owner->id);

        $this->assertEqualsWithDelta(3150.0, $invoice->fresh()->outstanding(), 0.0005);
        $this->assertEqualsWithDelta(1000.0, $this->balance('sales_returns'), 0.0005);
        $summary = ProjectFinancials::summary(Project::where('name', 'حفل التخرج')->first());
        $this->assertEqualsWithDelta(1050.0, $summary['credited'], 0.0005);
        $this->assertEqualsWithDelta(3150.0, $summary['net_invoiced'], 0.0005);
    }

    public function test_financial_import_needs_the_financial_action_and_a_strategy(): void
    {
        $this->masterData();
        $this->cutover('2024-01-01');

        $d = $this->register('expenses.csv', 'expenses');
        $d = $this->map($d, ['النوع' => 'type', 'المبلغ' => 'amount', 'التاريخ' => 'spent_at']);

        try {
            $this->dryRun($d);
            $this->fail('جُرّب استيرادٌ ماليّ بلا استراتيجيّة');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('استراتيجيّة', $e->getMessage());
        }

        Datasets::strategy($d, ImportDataset::HISTORICAL, $this->owner);
        $this->dryRun($d);

        try {
            Approval::approve($d->fresh(), $this->manager);
            $this->fail('اعتمد المديرُ استيرادًا ماليًّا بلا الصلاحيّة');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('الماليّ', $e->getMessage());
        }
        $this->assertSame(0, Expense::count());
    }
}
