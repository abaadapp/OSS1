<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * أعطالٌ لا تُرى: الأرقام تبقى معقولة بعدها.
 *
 * ثلاثةٌ من نوعٍ واحد — النظام يقرأ الغياب قيمةً: عمودٌ ناقصٌ في ملفّ
 * الاستيراد يعني صفرًا، وتاريخُ يومٍ يعني منتصف ليلته. ولا شيء في الشاشة
 * يقول إن شيئًا ضاع.
 */
class SilentDataLossTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function file(string $body, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".$body);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    /* ======================= استيراد المنتجات ======================= */

    public function test_a_price_list_does_not_wipe_the_stock(): void
    {
        /*
         * أكثر ما يُستورد: قائمة أسعارٍ محدَّثة من المورّد — اسمٌ وسعر لا غير.
         * وكانت **تمحو مخزون المتجر كلّه إلى صفر** لأن العمود الغائب يُقرأ
         * صفرًا. لا رسالة، ولا تراجعَ يخطر ببال أحد: الأرقام تبدو معقولة.
         */
        $p = Product::create([
            'business_id' => $this->business->id, 'name' => 'كيس أرز',
            'price' => 10, 'cost' => 6, 'quantity' => 40, 'barcode' => '6291001', 'sku' => 'R-1', 'active' => true,
        ]);
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'product_id' => $p->id, 'quantity' => 40,
        ]);

        $this->import("الاسم,السعر\nكيس أرز,12\n", 'products.csv', 'products');

        $fresh = $p->fresh();
        $this->assertSame(40, (int) $fresh->quantity, 'مُحي المخزون لأن الملفّ لم يذكره');
        $this->assertSame(12.0, (float) $fresh->price, 'لم يُحدَّث السعر وهو المقصود من الملفّ');
        $this->assertSame(6.0, (float) $fresh->cost, 'مُحيت التكلفة فصار كل بيعٍ ربحًا صافيًا');
        $this->assertSame('6291001', $fresh->barcode, 'مُحي الباركود فتوقّف الماسح');
        $this->assertSame(40, (int) BranchStock::where('product_id', $p->id)->value('quantity'));
    }

    public function test_a_stated_quantity_is_still_applied(): void
    {
        // الحماية ليست تجميدًا: ما ذكره الملفّ يُكتب كما كان
        $p = Product::create([
            'business_id' => $this->business->id, 'name' => 'كيس أرز',
            'price' => 10, 'cost' => 6, 'quantity' => 40, 'active' => true,
        ]);
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'product_id' => $p->id, 'quantity' => 40,
        ]);

        $this->import("الاسم,السعر,الكمية\nكيس أرز,10,55\n", 'products.csv', 'products');

        $this->assertSame(55, (int) $p->fresh()->quantity);
        $this->assertSame(55, (int) BranchStock::where('product_id', $p->id)->value('quantity'));
    }

    public function test_a_zero_written_on_purpose_is_obeyed(): void
    {
        // صفرٌ مكتوبٌ صراحةً نيّةٌ لا غياب: «نفد الصنف» تُكتب صفرًا
        $p = Product::create([
            'business_id' => $this->business->id, 'name' => 'كيس أرز',
            'price' => 10, 'cost' => 6, 'quantity' => 40, 'active' => true,
        ]);
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'product_id' => $p->id, 'quantity' => 40,
        ]);

        $this->import("الاسم,السعر,الكمية\nكيس أرز,10,0\n", 'products.csv', 'products');

        $this->assertSame(0, (int) $p->fresh()->quantity);
    }

    public function test_the_preview_says_what_will_not_be_touched(): void
    {
        Product::create([
            'business_id' => $this->business->id, 'name' => 'كيس أرز',
            'price' => 10, 'cost' => 6, 'quantity' => 40, 'active' => true,
        ]);

        $this->actingAs($this->owner)->post(route('admin.products.import.upload'), [
            'file' => $this->file("الاسم,السعر\nكيس أرز,12\n", 'products.csv'),
            'branch_id' => $this->branch->id,
        ]);

        $props = $this->actingAs($this->owner)->get(route('admin.products.import.preview'))
            ->assertOk()->viewData('page')['props'];

        $this->assertContains('الكمية', $props['untouched']);
        $this->assertContains('التكلفة', $props['untouched']);
        $this->assertNotContains('السعر', $props['untouched']);
    }

    /* ======================= استيراد العملاء ======================= */

    public function test_a_contact_list_does_not_wipe_what_it_does_not_mention(): void
    {
        $c = Customer::create([
            'business_id' => $this->business->id, 'name' => 'سالم',
            'phone' => '99887766', 'email' => 'salem@eventplus.om', 'address' => 'مسقط — الخوير',
        ]);

        $this->import("الاسم,الهاتف\nسالم,99887766\n", 'customers.csv', 'customers');

        $fresh = $c->fresh();
        $this->assertSame('salem@eventplus.om', $fresh->email, 'مُحي البريد لأن الملفّ لم يذكره');
        $this->assertSame('مسقط — الخوير', $fresh->address, 'مُحي العنوان');
    }

    public function test_a_stated_field_is_still_applied(): void
    {
        $c = Customer::create([
            'business_id' => $this->business->id, 'name' => 'سالم',
            'phone' => '99887766', 'email' => 'old@eventplus.om',
        ]);

        $this->import("الاسم,الهاتف,البريد\nسالم,99887766,new@eventplus.om\n", 'customers.csv', 'customers');

        $this->assertSame('new@eventplus.om', $c->fresh()->email);
    }

    private function import(string $csv, string $filename, string $kind): void
    {
        $this->actingAs($this->owner)->post(route("admin.{$kind}.import.upload"), [
            'file' => $this->file($csv, $filename),
            'branch_id' => $this->branch->id,
        ]);
        $this->actingAs($this->owner)->post(route("admin.{$kind}.import.confirm"));
    }
    /* =========================== المالية =========================== */

    public function test_an_expense_booked_in_finance_actually_counts(): void
    {
        /*
         * كانت المالية تقبل «مصروف» وتكتبه صفًّا في المعاملات — وبطاقاتها
         * تجمع الدخل وحده، والربح يُقرأ من جدول المصروفات. فالمبلغ يظهر في
         * الجدول ولا ينقص ربحًا ولا يدخل تقريرًا.
         */
        $this->actingAs($this->owner)->post(route('admin.finance.store'), [
            'kind' => 'expense', 'amount' => 45, 'side' => 'cash', 'description' => 'كهرباء',
        ])->assertSessionHasNoErrors();
        $this->assertSame(45.0, (float) Expense::where('business_id', $this->business->id)->sum('amount'));
    }

    public function test_an_expense_screen_entry_shows_in_the_ledger_too(): void
    {
        $this->actingAs($this->owner)->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => 300, 'method' => 'تحويل بنكي',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Transaction::where('type', 'مصروف')->count(), 'المصروف غائب عن دفتر المالية');
        $this->assertSame(300.0, (float) Expense::sum('amount'));
    }

    public function test_transaction_references_never_collide(): void
    {
        /*
         * كان `'TRX-' . random_int(60000, 99999)`: أربعون ألف قيمة بلا قيد،
         * فاحتمال التكرار يبلغ النصف بعد ٢٣٥ معاملة — وفي دفترٍ ماليّ مرجعان
         * متطابقان يعنيان أن التاجر لا يعرف أيّ صفٍّ يُصحّح.
         */
        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($this->owner)->post(route('admin.finance.store'), [
                'kind' => 'other_income', 'amount' => 5, 'side' => 'cash',
            ])->assertSessionHasNoErrors();
        }

        /*
         * والترتيبُ يُطلب صراحةً.
         *
         * `pluck` بلا `orderBy` لا ترتيبَ لها في PostgreSQL — يردّ المحرّكُ
         * الصفوفَ كما وجدها لا كما كُتبت. وكان الاختبارُ يقرأ أوّلَ ما يُردّ
         * ويظنّه أوّلَ ما كُتب، فيمرّ على SQLite (وهو يردّ بترتيب المفتاح)
         * ويسقط على محرّك الإنتاج. وافتراضُ ترتيبٍ لم يُطلب عطبٌ في كلّ
         * استعلامٍ يقع فيه، لا في هذا وحده.
         */
        $refs = Transaction::where('business_id', $this->business->id)
            ->orderBy('id')->pluck('reference');

        $this->assertCount(30, $refs->unique(), 'مرجعان متطابقان في دفتر ماليّ');
        $this->assertSame('TRX-000001', $refs->first());
        $this->assertSame('TRX-000030', $refs->last());
    }

    public function test_each_store_numbers_its_own_transactions(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Transaction::create([
            'business_id' => $other->id, 'reference' => 'TRX-000900', 'description' => '—',
            'method' => 'نقدي', 'type' => 'دخل', 'amount' => 1, 'occurred_at' => now(),
        ]);

        $this->actingAs($this->owner)->post(route('admin.finance.store'), [
            'kind' => 'other_income', 'amount' => 5, 'side' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->assertSame('TRX-000001', Transaction::where('business_id', $this->business->id)->value('reference'));
    }
}
