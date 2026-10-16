<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoicePayment;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MonthlyExpenses;
use App\Support\Permissions;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * كلُّ دفعةٍ على سند المورّد تحفظ إيصالَها — والسدادُ من بابه الواحد.
 *
 * `SupplierInvoices::settle` يكتب القيدَ والدفعةَ والمسدَّدَ معًا تحت قفل السند،
 * والإيصالُ على القرص الخاصّ ويُمحى إن سقطت المعاملة. ومرفقُ السند فاتورةُ المورّد
 * لا إيصالُ دفعة. والإيصالُ يُفتح ببابٍ يسأل الشركةَ والسندَ وفتحَ المرفقات معًا.
 * والسداداتُ السابقة لجدول الدفعات تبقى تُقرأ من الدفتر كما كانت.
 */
class EachSupplierPaymentKeepsItsOwnReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('local');
        Storage::fake('public');
        Carbon::setTestNow('2026-10-16 10:00:00');

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create(['business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط']);
        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مورّد الصوتيات']);
        Ledger::seedChart((int) $this->business->id);
    }

    private function bid(): int
    {
        return (int) $this->business->id;
    }

    /** سندٌ معتمَد بألف — مباشرةً من بابه، بلا شاشة */
    private function invoice(float $total = 1000, string $ref = 'INV-1000', bool $approve = true): SupplierInvoice
    {
        $invoice = SupplierInvoices::create($this->bid(), ['supplier_id' => $this->supplier->id, 'supplier_ref' => $ref,
            'issued_at' => '2026-10-01', 'subtotal' => $total, 'tax' => 0], $this->owner);

        return $approve ? SupplierInvoices::approve($invoice, $this->owner, 'اعتمادُ اختبار') : $invoice;
    }

    private function pdf(string $name = 'إيصال تحويل 1.pdf', int $kb = 60): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    private function pay(SupplierInvoice $invoice, array $data, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->post(route('admin.purchases.invoices.pay', $invoice->id), $data + [
            'paid_at' => '2026-10-16', 'from' => 'bank',
        ]);
    }

    private function showPayments(SupplierInvoice $invoice, ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->owner)->get(route('admin.purchases.invoices.show', $invoice->id))
            ->assertOk()->viewData('page')['props']['payments'];
    }

    /* ─────────────────────────── الدفعات ─────────────────────────── */

    public function test_a_full_payment_with_a_receipt_closes_the_bill_and_keeps_the_receipt_privately(): void
    {
        $invoice = $this->invoice();

        $this->pay($invoice, ['amount' => 1000, 'receipt' => $this->pdf()])->assertSessionHasNoErrors()->assertSessionHas('toast.msg', 'سُجّلت الدفعة');

        $invoice->refresh();
        $this->assertSame('مدفوع', $invoice->status);
        $this->assertSame(0.0, $invoice->outstanding());
        $this->assertSame(1000.0, (float) $invoice->paid);

        $payment = SupplierInvoicePayment::sole();
        $this->assertSame([$this->bid(), $invoice->id, 'bank', '2026-10-16', 1000.0, $this->owner->id],
            [(int) $payment->business_id, (int) $payment->supplier_invoice_id, $payment->paid_from, $payment->paid_at->toDateString(), (float) $payment->amount, (int) $payment->created_by]);
        $this->assertSame('إيصال تحويل 1.pdf', $payment->receipt_name, 'الاسمُ الأصليُّ للعرض وحده');
        $this->assertStringStartsWith("supplier-invoice-payments/{$this->bid()}/", $payment->receipt);
        $this->assertStringNotContainsString('إيصال', $payment->receipt, 'ولا يُبنى المسارُ من الاسم الأصليّ');
        Storage::disk('local')->assertExists($payment->receipt);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'ولا شيءَ على القرص العامّ');
        $this->assertNull($invoice->attachment, 'ومرفقُ السند — فاتورةُ المورّد — لا يُمسّ');

        $this->assertTrue(ActivityLog::where('business_id', $this->bid())
            ->where('description', 'سجّل دفعة 1,000.000 على سند المورّد INV-1000 (بنك) مع إيصال')->exists());
    }

    public function test_a_partial_payment_with_a_receipt_leaves_the_rest_outstanding(): void
    {
        $invoice = $this->invoice();

        $this->pay($invoice, ['amount' => 300, 'receipt' => $this->pdf()])->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame('جزئي', $invoice->status);
        $this->assertSame(300.0, (float) $invoice->paid);
        $this->assertSame(700.0, $invoice->outstanding());
        Storage::disk('local')->assertExists(SupplierInvoicePayment::sole()->receipt);
    }

    public function test_three_payments_are_three_rows_each_with_its_own_receipt_or_none(): void
    {
        $invoice = $this->invoice();

        $this->pay($invoice, ['amount' => 300, 'paid_at' => '2026-10-16', 'receipt' => $this->pdf('إيصال تحويل 1.pdf'), 'request_key' => 'k-1'])->assertSessionHasNoErrors();
        $this->pay($invoice, ['amount' => 400, 'paid_at' => '2026-10-20', 'receipt' => UploadedFile::fake()->create('تحويل 2.jpg', 80, 'image/jpeg'), 'request_key' => 'k-2'])->assertSessionHasNoErrors();
        $this->assertSame('جزئي', $invoice->fresh()->status);
        $this->pay($invoice, ['amount' => 300, 'paid_at' => '2026-10-25', 'from' => 'cash', 'request_key' => 'k-3'])->assertSessionHasNoErrors();

        $payments = SupplierInvoicePayment::orderBy('id')->get();
        $this->assertCount(3, $payments);
        $this->assertSame([300.0, 400.0, 300.0], $payments->map(fn ($p) => (float) $p->amount)->all());
        $this->assertSame(['bank', 'bank', 'cash'], $payments->pluck('paid_from')->all());
        $this->assertSame(['إيصال تحويل 1.pdf', 'تحويل 2.jpg', null], $payments->pluck('receipt_name')->all());
        $this->assertNotSame($payments[0]->receipt, $payments[1]->receipt, 'لكلّ دفعةٍ ملفُّها');
        $this->assertNull($payments[2]->receipt, 'والنقدُ بلا إيصال دفعةٌ صحيحة');
        $this->assertCount(3, $payments->pluck('journal_entry_id')->unique(), 'لكلّ دفعةٍ قيدُها');
        $this->assertCount(2, Storage::disk('local')->allFiles());

        $invoice->refresh();
        $this->assertSame(['مدفوع', 1000.0, 0.0], [$invoice->status, (float) $invoice->paid, $invoice->outstanding()]);

        /* وصفحةُ السند: ثلاثةُ أسطر، ولكلّ إيصالٍ رابطُه، ولا رابطَ لما لا إيصالَ له */
        $rows = $this->showPayments($invoice);
        $this->assertSame(['2026-10-16', '2026-10-20', '2026-10-25'], array_column($rows, 'at'));
        $this->assertSame(['bank', 'bank', 'cash'], array_column($rows, 'from'));
        $this->assertSame(['المالك', 'المالك', 'المالك'], array_column($rows, 'by'));
        $this->assertSame(route('admin.purchases.invoices.payments.receipt', [$invoice->id, $payments[0]->id]), $rows[0]['receipt']);
        $this->assertSame(route('admin.purchases.invoices.payments.receipt', [$invoice->id, $payments[1]->id]), $rows[1]['receipt']);
        $this->assertNull($rows[2]['receipt']);
        $this->assertFalse($rows[2]['has_receipt']);
        $this->assertSame(JournalEntry::whereKey($payments[1]->journal_entry_id)->value('number'), $rows[1]['number']);

        /* وكلُّ إيصالٍ يُفتح بنفسه — لا يُفتح إيصالُ دفعةٍ من رابط أختها */
        $this->get($rows[0]['receipt'])->assertOk()->assertHeader('content-disposition');
        $this->get($rows[1]['receipt'])->assertOk();
        $this->get(route('admin.purchases.invoices.payments.receipt', [$invoice->id, $payments[2]->id]))->assertNotFound();
    }

    public function test_a_payment_without_a_receipt_works_as_before(): void
    {
        $invoice = $this->invoice(500);

        $this->pay($invoice, ['amount' => 200, 'from' => 'cash'])->assertSessionHasNoErrors();

        $payment = SupplierInvoicePayment::sole();
        $this->assertNull($payment->receipt);
        $this->assertNull($payment->receipt_name);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertTrue(ActivityLog::where('description', 'سجّل دفعة 200.000 على سند المورّد INV-1000 (نقد)')->exists());
    }

    /* ─────────────────────────── الحرّاس ─────────────────────────── */

    public function test_no_payment_above_the_outstanding_and_none_on_an_unapproved_bill_and_no_file_is_left(): void
    {
        $invoice = $this->invoice(500);
        $this->pay($invoice, ['amount' => 900, 'receipt' => $this->pdf()])->assertSessionHasErrors('amount');

        $pending = $this->invoice(400, 'INV-PENDING', approve: false);
        $this->pay($pending, ['amount' => 100, 'receipt' => $this->pdf()])->assertSessionHasErrors('amount');

        $this->assertSame(0, SupplierInvoicePayment::count());
        $this->assertSame(0, JournalEntry::where('source', 'سداد مورّد')->count());
        $this->assertSame([0.0, 0.0], [(float) $invoice->fresh()->paid, (float) $pending->fresh()->paid]);
        $this->assertSame([], Storage::disk('local')->allFiles(), 'ولا ملفَّ بلا دفعة');
    }

    public function test_a_refusal_inside_the_transaction_takes_the_entry_the_row_and_the_file_with_it(): void
    {
        $invoice = $this->invoice(500);

        /* البابُ نفسُه بلا حرّاس المتحكّم — الرفضُ يقع تحت القفل بعد رفع الملف */
        try {
            SupplierInvoices::settle($invoice, 900, 'bank', now(), $this->owner->id, $this->pdf());
            $this->fail('دُفع فوق المستحقّ');
        } catch (RuntimeException) {
        }
        $this->assertSame([], Storage::disk('local')->allFiles());

        /* وفشلٌ بعد القيد — عند كتابة صفّ الدفعة: يسقط القيدُ والملفُّ معه */
        SupplierInvoicePayment::creating(fn () => throw new RuntimeException('انقطاع'));
        try {
            SupplierInvoices::settle($invoice, 100, 'bank', now(), $this->owner->id, $this->pdf());
            $this->fail('لم يسقط');
        } catch (RuntimeException $e) {
            $this->assertSame('انقطاع', $e->getMessage());
        }
        SupplierInvoicePayment::flushEventListeners();

        /* وإيصالٌ لم يُكتب على القرص — لا دفعةَ يظنّ صاحبُها أنّ إيصالَها معها */
        $tmp = tempnam(sys_get_temp_dir(), 'rcpt');
        file_put_contents($tmp, '%PDF');
        $unwritable = new class($tmp, 'إيصال.pdf', 'application/pdf', null, true) extends UploadedFile
        {
            public function store($path = '', $options = []): string|false
            {
                return false;
            }
        };
        try {
            SupplierInvoices::settle($invoice, 100, 'bank', now(), $this->owner->id, $unwritable);
            $this->fail('سُجّلت دفعةٌ بإيصالٍ لم يُكتب');
        } catch (RuntimeException $e) {
            $this->assertSame('تعذّر حفظ الإيصال — لم تُسجَّل الدفعة، أعد المحاولة.', $e->getMessage());
        }

        $this->assertSame(0, JournalEntry::where('source', 'سداد مورّد')->count(), 'لا قيدَ بلا دفعة');
        $this->assertSame(0, SupplierInvoicePayment::count());
        $this->assertSame(0.0, (float) $invoice->fresh()->paid);
        $this->assertSame([], Storage::disk('local')->allFiles(), 'ولا ملفَّ يتيم');
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
    }

    public function test_only_allowed_receipt_files_are_accepted(): void
    {
        $invoice = $this->invoice(500);

        foreach ([UploadedFile::fake()->create('x.exe', 10, 'application/octet-stream'), UploadedFile::fake()->create('x.php', 10, 'text/plain'),
            UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml')] as $bad) {
            $this->pay($invoice, ['amount' => 100, 'receipt' => $bad])->assertSessionHasErrors(['receipt' => 'صيغة الإيصال غير مدعومة — المقبول: PDF أو صورة (JPG، PNG، WEBP، HEIC).']);
        }
        $this->pay($invoice, ['amount' => 100, 'receipt' => $this->pdf('كبير.pdf', SupplierInvoicePayment::MAX_KB + 1)])
            ->assertSessionHasErrors(['receipt' => 'حجم الإيصال أكبر من 10 ميجابايت.']);
        foreach (['heic', 'webp', 'png', 'jpeg'] as $ext) {
            $this->pay($invoice, ['amount' => 10, 'receipt' => UploadedFile::fake()->create("ok.$ext", 20)])->assertSessionHasNoErrors();
        }

        $this->assertSame(4, SupplierInvoicePayment::count());
        $this->assertCount(4, Storage::disk('local')->allFiles());
    }

    /* ─────────────────────────── الدفتر ─────────────────────────── */

    public function test_each_payment_is_one_entry_payable_debit_cash_or_bank_credit_never_an_expense(): void
    {
        $invoice = $this->invoice();
        $expensesBefore = MonthlyExpenses::for($this->bid(), '2026-10')['total'];

        $this->pay($invoice, ['amount' => 600, 'from' => 'bank'])->assertSessionHasNoErrors();
        $this->pay($invoice, ['amount' => 150, 'from' => 'cash'])->assertSessionHasNoErrors();

        foreach (SupplierInvoicePayment::with('journalEntry.lines.account')->get() as $payment) {
            $entry = $payment->journalEntry;
            $this->assertSame(['سداد مورّد', SupplierInvoice::class, $invoice->id], [$entry->source, $entry->sourceable_type, (int) $entry->sourceable_id]);
            $this->assertSame($payment->paid_at->toDateString(), $entry->entry_date->toDateString());
            $lines = $entry->lines->map(fn ($l) => [$l->account->system_key, (float) $l->debit, (float) $l->credit])->all();
            $this->assertSame([['payable', (float) $payment->amount, 0.0], [$payment->paid_from, 0.0, (float) $payment->amount]], $lines);
        }

        $this->assertSame(2, JournalEntry::where('source', 'سداد مورّد')->count());
        $this->assertSame(250.0, Ledger::account($this->bid(), 'payable')->balance());
        $this->assertSame(-600.0, Ledger::account($this->bid(), 'bank')->balance());
        $this->assertSame(-150.0, Ledger::account($this->bid(), 'cash')->balance());
        $this->assertTrue(Ledger::trialBalance($this->bid())['balanced']);
        $this->assertSame($expensesBefore, MonthlyExpenses::for($this->bid(), '2026-10')['total'], 'السدادُ لا يُعدّ مصروفًا ثانيًا');
    }

    public function test_a_retried_request_records_one_payment_and_one_entry(): void
    {
        $invoice = $this->invoice(500);
        $data = ['amount' => 500, 'receipt' => $this->pdf(), 'request_key' => 'form-7f3a'];

        $this->pay($invoice, $data)->assertSessionHasNoErrors();
        /* ضغطةٌ ثانية بالمفتاح نفسِه بعد أن سُدّد السندُ كلُّه — نجاحٌ لا «المستحقّ صفر» */
        $this->pay($invoice, ['receipt' => $this->pdf('نسخة.pdf')] + $data)->assertSessionHasNoErrors()
            ->assertSessionHas('toast.msg', 'سُجّلت هذه الدفعة من قبل');

        $this->assertSame(1, SupplierInvoicePayment::count());
        $this->assertSame(1, JournalEntry::where('source', 'سداد مورّد')->count());
        $this->assertSame(500.0, (float) $invoice->fresh()->paid);
        $this->assertCount(1, Storage::disk('local')->allFiles(), 'وملفُّ الطلب المكرَّر لا يُحفظ');
        $this->assertSame(1, ActivityLog::where('description', 'like', 'سجّل دفعة%')->count());

        /* والبابُ نفسُه بالمفتاح نفسِه يعود بالدفعة الأولى */
        $again = SupplierInvoices::settle($invoice->fresh(), 500, 'bank', now(), $this->owner->id, null, 'form-7f3a');
        $this->assertFalse($again->getRelation('payment')->wasRecentlyCreated);
        $this->assertSame(1, SupplierInvoicePayment::count());

        /* ومفتاحُ دفعةٍ على سندٍ آخر لا يُستعمل هنا */
        $other = $this->invoice(100, 'INV-OTHER');
        $this->expectException(RuntimeException::class);
        SupplierInvoices::settle($other, 50, 'cash', now(), $this->owner->id, null, 'form-7f3a');
    }

    /* ─────────────────────────── التاريخ ─────────────────────────── */

    public function test_payments_recorded_before_the_table_still_show_from_the_ledger_without_invented_details(): void
    {
        $invoice = $this->invoice();

        /* سدادٌ قديم: قيدٌ كما كان يكتبه `settle` قبل جدول الدفعات — بلا صفّ */
        Ledger::post($this->bid(), 'سداد سند مورّد: INV-1000', [
            ['account' => 'payable', 'debit' => 250], ['account' => 'cash', 'credit' => 250],
        ], Carbon::parse('2026-10-05'), 'سداد مورّد', null, $this->owner->id, $invoice);
        $invoice->update(['paid' => 250]);
        $invoice->syncStatus();

        $this->pay($invoice->fresh(), ['amount' => 100, 'receipt' => $this->pdf()])->assertSessionHasNoErrors();

        $rows = $this->showPayments($invoice);
        $this->assertCount(2, $rows);
        [$old, $new] = $rows;
        $this->assertSame(['2026-10-05', 250.0, 'cash', 'المالك', true, false, null, null], [$old['at'], $old['amount'], $old['from'], $old['by'], $old['legacy'], $old['has_receipt'], $old['receipt'], $old['id']],
            'المصدرُ من سطره الدائن ومسجِّلُه من القيد — ولا إيصالَ يُخترع');
        $this->assertFalse($new['legacy']);
        $this->assertNotNull($new['receipt']);
        $this->assertSame(['جزئي', 350.0, 650.0], [$invoice->fresh()->status, (float) $invoice->fresh()->paid, $invoice->fresh()->outstanding()]);
        $this->assertSame(1, SupplierInvoicePayment::count(), 'ولا يُملأ الجدولُ بالقديم');
    }

    /* ─────────────────────────── الأبواب ─────────────────────────── */

    public function test_a_receipt_opens_only_for_its_company_its_bill_and_whoever_may_open_attachments(): void
    {
        $invoice = $this->invoice();
        $this->pay($invoice, ['amount' => 300, 'receipt' => $this->pdf()])->assertSessionHasNoErrors();
        $payment = SupplierInvoicePayment::sole();
        $url = route('admin.purchases.invoices.payments.receipt', [$invoice->id, $payment->id]);

        $this->actingAs($this->owner)->get($url)->assertOk();

        /* سندٌ آخر من الشركة نفسِها في الرابط — الدفعةُ لا تُفتح من عنوانه */
        $sibling = $this->invoice(200, 'INV-SIBLING');
        $this->get(route('admin.purchases.invoices.payments.receipt', [$sibling->id, $payment->id]))->assertNotFound();

        /* من يدفع ويقرأ السندات بلا «فتح المرفقات»: يعرف أنّ إيصالًا موجود، ولا يفتحه */
        $clerk = User::create(['business_id' => $this->bid(), 'name' => 'المحاسب', 'email' => 'clerk@eventplus.om', 'password' => bcrypt('password'),
            'role' => 'sales', 'status' => 'نشط', 'permissions' => ['dashboard', 'purchases', Permissions::INVOICE_VIEW, Permissions::INVOICE_PAY]]);
        $this->actingAs($clerk)->get($url)->assertForbidden();
        $row = $this->showPayments($invoice, $clerk)[0];
        $this->assertTrue($row['has_receipt']);
        $this->assertNull($row['receipt']);
        /* والدفعُ نفسُه لا يحتاج «فتح المرفقات» — ورفعُ الإيصال لا يتجاوز «السداد» */
        $this->pay($invoice, ['amount' => 50, 'receipt' => $this->pdf()], $clerk)->assertSessionHasNoErrors();
        $viewer = User::create(['business_id' => $this->bid(), 'name' => 'القارئ', 'email' => 'viewer@eventplus.om', 'password' => bcrypt('password'),
            'role' => 'sales', 'status' => 'نشط', 'permissions' => ['dashboard', 'purchases', Permissions::INVOICE_VIEW, Permissions::ATTACHMENT_VIEW]]);
        $this->pay($invoice, ['amount' => 50, 'receipt' => $this->pdf()], $viewer)->assertForbidden();
        $this->assertSame(2, SupplierInvoicePayment::count());

        /* وشركةٌ أخرى: لا الإيصالُ ولا السندُ ولا السداد — ولو عرفت الأرقام */
        $other = Business::create(['name' => 'شركة أخرى', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $other->id, 'name' => 'الرئيسي']);
        $stranger = User::create(['business_id' => $other->id, 'name' => 'غريب', 'email' => 'x@other.om', 'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط']);
        $this->actingAs($stranger)->get($url)->assertNotFound();
        $this->actingAs($stranger)->get(route('admin.purchases.invoices.show', $invoice->id))->assertNotFound();
        $this->actingAs($stranger)->post(route('admin.purchases.invoices.pay', $invoice->id), ['amount' => 10, 'paid_at' => '2026-10-16', 'from' => 'cash'])->assertNotFound();
        $this->assertSame(2, SupplierInvoicePayment::count());
        $this->assertSame(350.0, (float) $invoice->fresh()->paid);
    }
}
