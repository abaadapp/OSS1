<?php

namespace Tests\Feature\Import;

use App\Models\ActivityLog;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Communication;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\DocumentApproval;
use App\Models\ImportDataset;
use App\Models\ImportLink;
use App\Models\ImportRow;
use App\Models\ImportRun;
use App\Models\InternalNotification;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Support\Automation\Engine;
use App\Support\Automation\Rules;
use App\Support\Automation\Settings;
use App\Support\CommandCenter;
use App\Support\CustomerInvoices;
use App\Support\Import\Approval;
use App\Support\Import\Datasets;
use App\Support\Import\Provenance;
use App\Support\Import\Rollback;
use App\Support\Import\Runner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * ما استُورد يبقى صامتًا وقابلًا للتتبّع — لا رسالةَ ولا تذكيرَ ولا دليلَ مختلَق،
 * ولكلّ كيانٍ أثرٌ إلى صفّه، وتراجعٌ حيث يكون آمنًا.
 */
class ImportedHistoryStaysQuietAndTraceableTest extends ImportTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-20 10:00', 'Asia/Muscat'));
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.om']);
        Mail::fake();
        Http::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function everything(): void
    {
        $customers = $this->register('customers.csv', 'customers');
        $customers = $this->map($customers, ['المعرف' => 'external_id', 'اسم العميل' => 'name', 'البريد' => 'email']);
        Datasets::resolve($customers, ['4' => 'create', '6' => 'skip'], $this->owner);
        /* الصفُّ السادس بريدُه غير صالح — يُخرَج من الخريطة بربط البريد ثمّ يُقرَّر تخطّيه لا يكفي؛ نُسقط البريدَ للاختبار */
        $customers = $this->map($customers, ['المعرف' => 'external_id', 'اسم العميل' => 'name']);
        Datasets::resolve($customers, ['4' => 'create'], $this->owner);
        $this->importAll($customers);
        Customer::query()->update(['email' => 'client@example.om', 'phone' => '91234567']);

        $suppliers = $this->suppliers();
        Datasets::resolve($suppliers, ['3' => 'skip'], $this->owner);
        $this->importAll($suppliers);
        $this->importAll($this->projects());
        $this->cutover('2024-01-01');
        $this->importAll($this->invoices());
    }

    /** قاعدةُ تذكير الفاتورة مفعَّلةً على تشغيل — والباقي مُطفأ */
    private function liveInvoiceRule(): void
    {
        Settings::save((int) $this->business->id, ['mode' => Settings::LIVE], $this->owner);
        Rules::ensure((int) $this->business->id);
        AutomationRule::where('business_id', $this->business->id)->where('key', '!=', 'invoice.due')->update(['enabled' => false]);
        $rule = AutomationRule::where('business_id', $this->business->id)->where('key', 'invoice.due')->firstOrFail();
        Rules::save($rule, ['enabled' => true, 'mode' => AutomationRule::LIVE, 'steps' => [['offset' => 0, 'audience' => 'customer'], ['offset' => 3, 'audience' => 'customer']],
            'channel' => 'email', 'template_key' => 'invoice.reminder', 'business_hours' => false, 'cooldown_hours' => 1], $this->owner);
    }

    /* ─────────────────────────── الأتمتة والمراسلات ─────────────────────────── */

    public function test_the_import_itself_sends_no_email_no_whatsapp_and_writes_no_communication(): void
    {
        $this->everything();
        $this->importAll($this->payments());

        Mail::assertNothingSent();
        Http::assertNothingSent();
        $this->assertSame(0, Communication::count(), 'لا رسالةَ تاريخيّةً مختلَقة');
        $this->assertSame(0, InternalNotification::count());
        $this->assertSame(0, AutomationRun::count());
    }

    public function test_automation_never_reminds_about_a_historical_invoice_even_when_it_is_years_overdue(): void
    {
        $this->everything();
        $this->liveInvoiceRule();

        /* فاتورةُ 2025 المستورَدة تستحقّ في 2026-01-04 — متأخّرةٌ اليومَ بأشهر؛ ونصنع فاتورةً أصليّةً تستحقّ اليوم */
        $native = CustomerInvoices::create((int) $this->business->id, Customer::first(), ['issued_at' => '2026-09-10', 'due_at' => '2026-09-20'],
            [['description' => 'خدمة', 'quantity' => 1, 'unit_price' => 100]], $this->owner->id);
        CustomerInvoices::issue($native, $this->owner->id);
        CustomerInvoice::where('origin', Provenance::HISTORICAL)->update(['due_at' => '2026-09-20']);

        $stats = Engine::tick((int) $this->business->id);

        $this->assertSame(1, $stats['executed'], 'الأصليّةُ وحدَها');
        $runs = AutomationRun::all();
        $this->assertCount(1, $runs);
        $this->assertSame(CustomerInvoice::class, $runs[0]->target_type);
        $this->assertSame($native->id, (int) $runs[0]->target_id);
        Mail::assertSentCount(1);
        $this->assertSame(1, Communication::count());
        $this->assertSame($native->id, (int) Communication::sole()->subject_id);

        /* وتنفيذٌ فُتح قبل أن يُعرف المنشأ يُتخطّى لحظةَ الفعل */
        $historical = CustomerInvoice::where('origin', Provenance::HISTORICAL)->where('status', CustomerInvoice::ISSUED)->first();
        $rule = AutomationRule::where('business_id', $this->business->id)->where('key', 'invoice.due')->firstOrFail();
        $step = ['offset' => 0, 'audience' => 'customer'];
        $run = AutomationRun::create([
            'business_id' => $this->business->id, 'automation_rule_id' => $rule->id, 'trigger' => 'invoice_due',
            'target_type' => CustomerInvoice::class, 'target_id' => $historical->id, 'step' => 0, 'audience' => 'customer',
            'occurrence' => '2026-09-20', 'idempotency_key' => Engine::idempotencyKey($rule, $historical, '2026-09-20', $step),
            'status' => AutomationRun::PENDING, 'evaluated_at' => now(), 'scheduled_for' => now(), 'target_label' => $historical->number,
        ]);
        Engine::processPending((int) $this->business->id);
        $this->assertSame(AutomationRun::SKIPPED, $run->fresh()->status);
        $this->assertSame(Engine::HISTORICAL, $run->fresh()->skipped_reason);
        Mail::assertSentCount(1);
    }

    /* ─────────────────────────── لا دليلَ مختلَق ─────────────────────────── */

    public function test_historical_quotations_and_projects_carry_no_fabricated_workflow_evidence(): void
    {
        $customers = $this->register('customers.csv', 'customers');
        $customers = $this->map($customers, ['المعرف' => 'external_id', 'اسم العميل' => 'name']);
        Datasets::resolve($customers, ['4' => 'create'], $this->owner);
        $this->importAll($customers);

        file_put_contents(storage_path('framework/testing/quotes.csv'), implode("\n", [
            'رقم,العميل,العنوان,الحالة,التاريخ,البند,الكمية,السعر,الضريبة,القرار,من',
            'Q-2024-7,C-1,مؤتمر الطاقة,accepted,01/02/2024,تنظيم,1,10000,5,10/02/2024,سالم',
            'Q-2024-8,C-2,معرض,rejected,05/02/2024,تنظيم,1,3000,5,,',
            'Q-2024-9,C-3,حفل,sent,05/02/2024,تنظيم,1,3000,5,,',
        ])."\n");
        $q = Datasets::registerPath((int) $this->business->id, storage_path('framework/testing/quotes.csv'), ['category' => 'quotations', 'name' => 'q'], $this->owner);
        $q = Datasets::profile($q, $this->owner);
        $q = $this->map($q, ['رقم' => 'number', 'العميل' => 'customer', 'العنوان' => 'title', 'الحالة' => 'status', 'التاريخ' => 'issued_at',
            'البند' => 'description', 'الكمية' => 'quantity', 'السعر' => 'unit_price', 'الضريبة' => 'tax_rate', 'القرار' => 'decided_at', 'من' => 'decided_by_name']);

        $run = $this->importAll($q);
        $this->assertSame(ImportRun::SUCCESS, $run->status, json_encode($run->findings, JSON_UNESCAPED_UNICODE).' '.$run->error);
        $this->assertSame(3, Quotation::count());

        $accepted = Quotation::where('number', 'HIST-Q-2024-7')->firstOrFail();
        $this->assertSame(Quotation::ACCEPTED, $accepted->status);
        $this->assertSame('2024-02-10', $accepted->decided_at->toDateString());
        $this->assertSame('سالم', $accepted->decided_by_name);
        $this->assertNull($accepted->content_hash, 'لا بصمةَ إرسال');
        $this->assertNull($accepted->document_snapshot, 'لا لقطةَ ختم');
        $this->assertSame(0, DocumentApproval::count(), 'لا توقيعَ مختلَق');
        $this->assertSame(0, Project::where('quotation_id', $accepted->id)->count(), 'ولا مشروعَ يُولد من قبولٍ تاريخيّ');
        $this->assertSame(Provenance::HISTORICAL, $accepted->origin);
        $this->assertEqualsWithDelta(10500.0, (float) $accepted->total, 0.0005);
        $this->assertSame(0, Communication::count());
        Mail::assertNothingSent();
    }

    /* ─────────────────────────── الاعتمادُ يسقط بما يتغيّر ─────────────────────────── */

    public function test_a_changed_mapping_or_strategy_or_file_invalidates_the_approval(): void
    {
        $d = $this->suppliers();
        Datasets::resolve($d, ['3' => 'skip'], $this->owner);
        $dry = $this->dryRun($d);
        $this->assertSame(ImportRun::SUCCESS, $dry->status, json_encode($dry->findings, JSON_UNESCAPED_UNICODE));
        Approval::approve($d->fresh(), $this->owner);
        $this->assertTrue(Approval::stands($d->fresh()));

        /* الخريطة — وتغييرُها يمحو قراراتِ التكرار أيضًا: قرارٌ على خريطةٍ لم تعد قائمة */
        $this->map($d->fresh(), ['id' => 'external_id', 'name' => 'name', 'phone' => 'phone']);
        $this->assertFalse(Approval::stands($d->fresh()));
        $this->assertSame(ImportDataset::MAPPED, $d->fresh()->status);
        $this->assertNull($d->fresh()->resolutions);
        try {
            Runner::import($d->fresh(), $this->owner);
            $this->fail('استُوردت مجموعةٌ سقط اعتمادُها');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('اعتماد', $e->getMessage());
        }
        $this->assertSame(0, Supplier::count());

        /* التجربةُ من جديد ثمّ الاعتماد — ثمّ يُبدَّل الملفُّ على القرص */
        Datasets::resolve($d->fresh(), ['3' => 'skip'], $this->owner);
        $this->dryRun($d);
        Approval::approve($d->fresh(), $this->owner);
        Storage::disk('local')->put($d->path, "id,name,phone,email,contact\nS-9,دخيل,90000000,,\n");
        try {
            Runner::import($d->fresh(), $this->owner);
            $this->fail('استُورد ملفٌّ تغيّرت بصمتُه');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('بصمة', $e->getMessage());
        }
        $this->assertSame(0, Supplier::count());

        /* والاستراتيجيّةُ تُسقط الاعتمادَ كذلك — على صنفٍ ماليّ */
        $this->cutover('2024-01-01');
        $e = $this->register('expenses.csv', 'expenses');
        $e = $this->map($e, ['النوع' => 'type', 'المبلغ' => 'amount', 'التاريخ' => 'spent_at', 'الحالة' => 'status']);
        Datasets::strategy($e, ImportDataset::HISTORICAL, $this->owner);
        $this->dryRun($e);
        Approval::approve($e->fresh(), $this->owner);
        Datasets::strategy($e->fresh(), null, $this->owner);
        $this->assertFalse(Approval::stands($e->fresh()));

        /* والتجربةُ لا تكفي وحدَها: لا استيرادَ بلا اعتماد */
        Datasets::strategy($e->fresh(), ImportDataset::HISTORICAL, $this->owner);
        $this->dryRun($e);
        try {
            Runner::import($e->fresh(), $this->owner);
            $this->fail('استُورد بلا اعتماد');
        } catch (RuntimeException) {
        }
    }

    /* ─────────────────────────── التراجع ─────────────────────────── */

    public function test_rollback_removes_unused_master_data_reverses_documents_and_keeps_what_is_in_use(): void
    {
        $this->everything();
        $this->importAll($this->payments());

        /* العملاءُ: مستعمَلون في مشاريع وفواتير — لا يُحذفون */
        $customers = ImportDataset::where('category', 'customers')->firstOrFail();
        $eligibility = Rollback::eligibility($customers);
        $this->assertFalse($eligibility['eligible'] && $eligibility['counts'][Rollback::IN_USE] === 0);
        $this->assertGreaterThan(0, $eligibility['counts'][Rollback::IN_USE]);

        Rollback::execute($customers, 'تجربة', $this->owner);
        $this->assertSame(3, Customer::count(), 'ما استُعمل بقي — والثلاثةُ بلا استعمال أُزيلوا');
        $this->assertTrue(Customer::where('name', 'وزارة الثقافة')->exists());
        $this->assertNotSame(ImportDataset::CANCELLED, $customers->fresh()->status, 'لم يكتمل التراجع فبقيت مستورَدة');
        $this->assertSame(3, $customers->fresh()->rollback['counts']['kept']);

        /* الفواتيرُ: التي عليها سداد لا تُعكَس — والأخرى تُلغى من بابها */
        $invoices = ImportDataset::where('category', 'customer_invoices')->firstOrFail();
        $before = Rollback::eligibility($invoices);
        $this->assertSame(2, $before['counts'][Rollback::NOT_REVERSIBLE], 'فاتورتان سُدّد منهما');
        $this->assertFalse($before['eligible']);

        /* التحصيلاتُ أوّلًا: تُلغى بقيدٍ عكسيّ — ثمّ تصير الفواتيرُ قابلة */
        $payments = ImportDataset::where('category', 'payments')->firstOrFail();
        $entries = JournalEntry::count();
        $run = Rollback::execute($payments, 'خطأ في الملفّ', $this->owner);
        $this->assertSame(ImportRun::SUCCESS, $run->status, json_encode($run->findings, JSON_UNESCAPED_UNICODE));
        $this->assertSame(3, CustomerPayment::whereNotNull('cancelled_at')->count(), 'أُلغيت لا حُذفت');
        $this->assertSame(3, CustomerPayment::count());
        $this->assertGreaterThan($entries, JournalEntry::count(), 'قيودٌ عكسيّة لا محو');
        $this->assertSame(ImportDataset::CANCELLED, $payments->fresh()->status);

        $after = Rollback::eligibility($invoices);
        $this->assertTrue($after['eligible']);
        Rollback::execute($invoices, 'أرقامٌ خاطئة', $this->owner);
        $this->assertSame(3, CustomerInvoice::where('status', CustomerInvoice::CANCELLED)->count(), 'المستورَدة أُلغيت — ولم تُحذف');
        $this->assertSame(0, ImportLink::where('dataset_id', $invoices->id)->whereNull('rolled_back_at')->count());

        /* والمورّدون بلا استعمال يُزالون */
        $suppliers = ImportDataset::where('category', 'suppliers')->firstOrFail();
        Rollback::execute($suppliers, 'تجربة', $this->owner);
        $this->assertSame(0, Supplier::count());
        $this->assertSame(ImportDataset::CANCELLED, $suppliers->fresh()->status);

        try {
            Rollback::execute($suppliers->fresh(), 'x', $this->manager);
            $this->fail('تراجعٌ بلا صلاحيّة');
        } catch (RuntimeException) {
        }
    }

    /* ─────────────────────────── الأثرُ والسجلّ ─────────────────────────── */

    public function test_every_imported_record_traces_to_its_source_row_and_the_activity_log_has_no_pii(): void
    {
        $d = $this->suppliers();
        Datasets::resolve($d, ['3' => 'skip'], $this->owner);
        $this->importAll($d);

        foreach (Supplier::all() as $supplier) {
            $ref = Provenance::sourceRef($supplier);
            $this->assertMatchesRegularExpression('/^DATA-\d{4}#ROW-\d+$/', (string) $ref);
            $link = ImportLink::where('source_ref', $ref)->firstOrFail();
            $this->assertSame((int) $supplier->id, (int) $link->linkable_id);
            $this->assertSame($d->id, (int) $link->dataset_id);
        }

        $log = ActivityLog::where('subject_type', 'import_dataset')->pluck('description')->implode("\n");
        foreach (['registered' => 'سجّل مجموعة', 'mapped' => 'ربط أعمدة', 'dry' => 'جرّب استيراد', 'approved' => 'اعتمد استيراد', 'started' => 'بدأ استيراد', 'done' => 'اكتمل استيراد', 'decided' => 'قرّر في تكرارات'] as $key => $needle) {
            $this->assertStringContainsString($needle, $log, $key);
        }
        $this->assertStringNotContainsString('92221111', $log, 'لا هاتفَ في السجلّ');
        $this->assertStringNotContainsString('print@example.om', $log, 'لا بريدَ في السجلّ');
        $this->assertStringNotContainsString('مطابع الشرق', $log, 'ولا اسمَ مورّدٍ في السجلّ');

        $rows = ImportRow::where('dataset_id', $d->id)->pluck('messages')->flatten()->implode(' ');
        $this->assertStringNotContainsString('92221111', $rows);
    }

    public function test_the_command_center_surfaces_only_migration_that_needs_a_hand(): void
    {
        $failed = $this->customers();
        $this->dryRun($failed);
        $this->assertSame(ImportDataset::VALIDATION_FAILED, $failed->fresh()->status);

        $review = $this->suppliers();
        $this->dryRun($review);
        $this->assertSame(ImportDataset::NEEDS_REVIEW, $review->fresh()->status);

        $ok = $this->services();
        $this->importAll($ok);
        $this->assertSame(ImportDataset::VERIFIED, $ok->fresh()->status);

        $section = CommandCenter::migration((int) $this->business->id);
        $this->assertSame(2, $section['total'], 'الناجحُ لا يُذكر');
        $kinds = collect($section['rows'])->pluck('kind')->all();
        $this->assertContains('migration_validation_failed', $kinds);
        $this->assertContains('migration_needs_review', $kinds);
        $this->assertSame(2, CommandCenter::for((int) $this->business->id, $this->owner)['summary']['migration']);
    }

    public function test_a_large_file_is_read_in_chunks_and_imports_without_loading_everything(): void
    {
        $path = storage_path('framework/testing/big.csv');
        $handle = fopen($path, 'w');
        fwrite($handle, "id,name,phone\n");
        for ($i = 1; $i <= 3000; $i++) {
            fwrite($handle, "B-$i,عميل رقم $i,9".str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT)."\n");
        }
        fclose($handle);

        $d = Datasets::registerPath((int) $this->business->id, $path, ['category' => 'customers', 'name' => 'big'], $this->owner);
        $d = Datasets::profile($d, $this->owner);
        $this->assertSame(3000, $d->row_count);
        $this->assertSame(1000, $d->profile['probed'], 'يُسبر ألفٌ لا الكلّ');
        $this->assertCount(20, $d->sample);

        $d = $this->map($d, ['id' => 'external_id', 'name' => 'name', 'phone' => 'phone']);
        $before = memory_get_peak_usage(true);
        $run = $this->importAll($d);
        $this->assertSame(ImportRun::SUCCESS, $run->status);
        $this->assertSame(3000, Customer::count());
        $this->assertSame(3000, ImportRow::where('run_id', $run->id)->count());
        $this->assertLessThan(160 * 1024 * 1024, memory_get_peak_usage(true) - $before + 1, 'لا الملفُّ كلُّه في الذاكرة');

        $preview = Datasets::preview($d, 60, 50);
        $this->assertCount(50, $preview['rows']);
        $this->assertSame(2951, $preview['rows'][0]['number']);
        $this->assertCount(0, Datasets::preview($d, 61, 50)['rows']);
    }
}
