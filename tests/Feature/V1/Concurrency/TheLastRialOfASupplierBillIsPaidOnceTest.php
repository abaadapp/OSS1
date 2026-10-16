<?php

namespace Tests\Feature\V1\Concurrency;

use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoicePayment;
use App\Support\SupplierInvoices;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * آخرُ ريالٍ على سند المورّد يُدفع مرّةً — ولو طلبه عاملان في اللحظة نفسِها.
 *
 * قفلُ صفّ السند في `SupplierInvoices::settle` يجعل الثاني ينتظر الأوّل ثمّ يقرأ ما
 * بقي: لا يصير المدفوعُ ضعفَ الإجمالي، ولا قيدان ولا دفعتان، ولا يبقى إيصالُ
 * المرفوض على القرص. وطلبان بمفتاح النموذج نفسِه دفعةٌ واحدة.
 */
class TheLastRialOfASupplierBillIsPaidOnceTest extends RaceTestCase
{
    private function bill(): array
    {
        $b = $this->company();
        $owner = $this->user($b);
        $supplier = Supplier::create(['business_id' => $b->id, 'name' => 'مورّد']);
        $invoice = SupplierInvoices::approve(SupplierInvoices::create((int) $b->id, ['supplier_id' => $supplier->id, 'supplier_ref' => 'R-100',
            'issued_at' => now()->toDateString(), 'subtotal' => 100, 'tax' => 0], $owner), $owner, 'اعتمادُ اختبار');

        return [$b, $owner, $invoice];
    }

    public function test_two_payments_of_the_whole_outstanding_at_once_pay_it_once_and_leave_one_receipt(): void
    {
        Storage::fake('local');
        [$b, $owner, $invoice] = $this->bill();
        $receipts = [UploadedFile::fake()->create('أ.pdf', 20, 'application/pdf'), UploadedFile::fake()->create('ب.pdf', 20, 'application/pdf')];

        $results = $this->race(array_map(fn (UploadedFile $file) => fn () => SupplierInvoices::settle(
            SupplierInvoice::findOrFail($invoice->id), 100, 'bank', now(), $owner->id, $file,
        )->getRelation('payment')->id, $receipts));

        $this->assertSame(1, $this->successes($results), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(100.0, (float) $invoice->fresh()->paid);
        $this->assertSame('مدفوع', $invoice->fresh()->status);
        $this->assertSame(1, SupplierInvoicePayment::where('supplier_invoice_id', $invoice->id)->count());
        $this->assertSame(1, JournalEntry::where('business_id', $b->id)->where('source', 'سداد مورّد')->count());
        $this->assertCount(1, Storage::disk('local')->allFiles(), 'إيصالُ الطلب المرفوض لا يبقى');
    }

    public function test_the_same_form_submitted_twice_at_once_records_one_payment(): void
    {
        [$b, $owner, $invoice] = $this->bill();

        $results = $this->race([
            fn () => SupplierInvoices::settle(SupplierInvoice::findOrFail($invoice->id), 40, 'cash', now(), $owner->id, null, 'form-same')->getRelation('payment')->id,
            fn () => SupplierInvoices::settle(SupplierInvoice::findOrFail($invoice->id), 40, 'cash', now(), $owner->id, null, 'form-same')->getRelation('payment')->id,
        ]);

        $this->assertSame(2, $this->successes($results), 'الثاني يعود بالدفعة الأولى ولا يفشل: '.json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame($results[0]['value'], $results[1]['value']);
        $this->assertSame(1, SupplierInvoicePayment::where('supplier_invoice_id', $invoice->id)->count());
        $this->assertSame(40.0, (float) $invoice->fresh()->paid);
        $this->assertSame(1, JournalEntry::where('business_id', $b->id)->where('source', 'سداد مورّد')->count());
    }
}
