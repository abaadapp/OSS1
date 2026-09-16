<?php

namespace Tests;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\GoodsReceiptNote;
use App\Models\Product;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Demo;
use App\Support\GoodsReceipts;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * الذاكرة الساكنة لا تعيش بين اختبارين.
     *
     * قاعدة البيانات تُلفّ وتُعاد، لكن `Demo::$baseCur` ساكنةٌ تعيش ما عاشت
     * العمليّة — فاختبارٌ يضبط عملته دولارًا يجعل الاختبار الذي يليه يقرأ
     * الدولار وقد مُحيت إعداداته. عطلٌ في الاختبار لا في النظام، وأسوأ منه
     * أنه يظهر ويختفي بترتيب التشغيل.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Demo::flushCurrency();
    }

    /**
     * وردية مفتوحة لهذا النشاط.
     *
     * البيع صار يتطلّبها، وهي شرطٌ للسيناريو لا موضوعُه: اختبارات المخزون
     * والحساب والفواتير تحتاج صندوقًا مفتوحًا لتصل إلى ما تفحصه. تُكتب هنا
     * مباشرةً لأن التهيئة تسبق تسجيل الدخول، فلا مستخدم بعدُ لتُشتقّ منه.
     */
    /**
     * اعتمادُ كلّ استلامٍ معلَّق — وبه تدخل البضاعةُ الرفّ.
     *
     * صار الاستلامُ خطوتين: تُكتب الورقة، ثمّ تُعتمد. وأكثرُ الاختبارات
     * تفحص أثرَ الشحنة على المخزون لا مسارَ الاعتماد نفسه، فالخطوةُ الثانية
     * فيها شرطُ السيناريو لا موضوعُه.
     *
     * وتمرّ بالخدمة لا بالمسار: المسارُ يُفحص في اختباره وحده.
     *
     * @return int عددُ ما اعتُمد
     */
    protected function approvePendingReceipts(int $businessId, ?User $by = null): int
    {
        $notes = GoodsReceiptNote::where('business_id', $businessId)
            ->where('status', GoodsReceipts::PENDING)->orderBy('id')->get();

        foreach ($notes as $note) {
            GoodsReceipts::approve($note, $by ?? auth()->user());
        }

        return $notes->count();
    }

    /**
     * بيعةٌ للشركة — **فاتورةُ عميلٍ صادرة**، لا طلب.
     *
     * ═══ ولمَ معينٌ مشترك ═══
     *
     * أحدَ عشرَ ملفَّ اختبارٍ كان يكتب بيعةً بيده: `Order` + `OrderItem`.
     * وجدولُ الطلبات لا مسارَ يكتب فيه، فكانت الاختباراتُ تُثبّت سلوكًا لا
     * يقع في النظام الحقيقيّ — وتمرّ خضراءَ على لوحةٍ تقرأ صفرًا.
     *
     * فصارت البيعةُ هنا مرّةً واحدة، بالتعريف الذي يقرؤه `Revenue`: فاتورةٌ
     * **صادرة** بتاريخ `issued_at`.
     *
     * ═══ ولا تمرّ على `CustomerInvoices::issue` ═══
     *
     * تلك تكتب قيدًا في الدفتر وتقطع رقمًا وتختم لقطة الشركة — وهي دورةٌ
     * تُفحص في اختبارها وحدَها. وأكثرُ ما يُبنى عليه هنا قراءةُ تقريرٍ أو
     * بطاقةِ لوحة، فالقيدُ فيه شرطُ سيناريو لا موضوعُه. ومن أراد القيدَ
     * فليُصدرها بالخدمة.
     *
     * @param  array<int, array{product?: Product|null, description?: string, quantity?: int|float, unit_price?: float, tax_amount?: float}>  $lines
     * @param  array<string, mixed>  $over  ما يُكتب فوق المحسوب — التاريخ والفرع وكاتبُها
     */
    protected function sellThroughInvoice(int $businessId, array $lines = [], array $over = []): CustomerInvoice
    {
        $subtotal = 0.0;
        $tax = 0.0;

        foreach ($lines as $line) {
            $subtotal += (float) ($line['unit_price'] ?? 0) * (float) ($line['quantity'] ?? 1);
            $tax += (float) ($line['tax_amount'] ?? 0);
        }

        $discount = (float) ($over['discount_total'] ?? 0);

        /*
         * وعميلٌ افتراضيّ إن لم يُعطَ.
         *
         * `customer_invoices.customer_id` لا يقبل الفراغ — الفاتورةُ التزامٌ
         * **على أحد**. وأكثرُ ما يُفحص هنا مبالغُ وتواريخُ لا هويّةُ عميل،
         * فيُصنع واحدٌ مرّةً ويُعاد استعماله.
         */
        $customer = $over['customer_id'] ?? Customer::firstOrCreate(
            ['business_id' => $businessId, 'name' => 'عميل الاختبار'],
        )->id;

        $invoice = CustomerInvoice::create(array_merge([
            'customer_id' => $customer,
            'customer_name' => 'عميل الاختبار',
            'business_id' => $businessId,
            'number' => 'INV-'.str_pad((string) (CustomerInvoice::count() + 1), 5, '0', STR_PAD_LEFT),
            'status' => CustomerInvoice::ISSUED,
            'issued_at' => now()->toDateString(),
            'subtotal' => round($subtotal, 3),
            'discount_total' => $discount,
            'tax_total' => round($tax, 3),
            'total' => round($subtotal - $discount + $tax, 3),
            'issued_by_at' => now(),
        ], $over));

        foreach (array_values($lines) as $i => $line) {
            $product = $line['product'] ?? null;
            $quantity = (float) ($line['quantity'] ?? 1);
            $price = (float) ($line['unit_price'] ?? 0);

            CustomerInvoiceItem::create([
                'customer_invoice_id' => $invoice->id,
                'product_id' => $product?->id,
                'description' => $line['description'] ?? $product?->name ?? 'بند',
                'quantity' => $quantity,
                'unit_price' => $price,
                'discount' => 0,
                'tax_rate' => 0,
                'tax_amount' => (float) ($line['tax_amount'] ?? 0),
                'line_total' => round($price * $quantity, 3),
                'sort_order' => $i,
            ]);
        }

        return $invoice->fresh();
    }

    /**
     * اعتمادُ كلّ سند مورّدٍ معلَّق — وبه تنشأ الذمّة في الدفتر.
     *
     * وصار السندُ يُكتب أوّلًا ويُعتمد ثانيًا، كالاستلام. وأكثرُ الاختبارات
     * تفحص أثرَ الذمّة أو السداد لا مسارَ الاعتماد نفسه.
     *
     * ولا تُطابَق: التجاوزُ مُمرَّرٌ بسببٍ صريح كي تمرّ سنداتُ الاختبارات
     * التي لا أوامرَ مستلَمةً لها — والمطابقةُ تُفحص في اختبارها وحده.
     */
    protected function approvePendingSupplierInvoices(int $businessId, ?User $by = null): int
    {
        $invoices = SupplierInvoice::where('business_id', $businessId)
            ->where('approval_status', SupplierInvoices::PENDING)->orderBy('id')->get();

        foreach ($invoices as $invoice) {
            SupplierInvoices::approve($invoice, $by ?? auth()->user(), 'اعتمادُ اختبار');
        }

        return $invoices->count();
    }
}
