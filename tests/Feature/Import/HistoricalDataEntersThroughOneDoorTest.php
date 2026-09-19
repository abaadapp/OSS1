<?php

namespace Tests\Feature\Import;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\ImportDataset;
use App\Models\ImportLink;
use App\Models\ImportRow;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\Project;
use App\Models\Supplier;
use App\Support\Import\Approval;
use App\Support\Import\Datasets;
use App\Support\Import\Provenance;
use App\Support\Import\Runner;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * البياناتُ التاريخيّة تدخل من بابٍ واحد — التسجيلُ والقراءةُ والربطُ والتجربةُ
 * والاعتمادُ والاستيرادُ والأثر — على البيانات المرجعيّة.
 */
class HistoricalDataEntersThroughOneDoorTest extends ImportTestCase
{
    public function test_a_file_is_registered_with_a_code_a_checksum_and_a_private_path_then_profiled(): void
    {
        $d = $this->register('customers.csv', 'customers', ['source' => 'إكسل قديم']);

        $this->assertSame('DATA-0001', $d->code);
        $this->assertSame(hash_file('sha256', $this->fixture('customers.csv')), $d->checksum);
        $this->assertSame(ImportDataset::NEEDS_MAPPING, $d->status);
        $this->assertSame(6, $d->row_count);
        $this->assertSame(8, $d->column_count);
        $this->assertContains('اسم العميل', $d->columns);
        $this->assertStringStartsWith('imports/'.$this->business->id.'/DATA-0001', $d->path);
        Storage::disk('local')->assertExists($d->path);

        $kinds = collect($d->profile['columns'])->pluck('kind', 'name');
        $this->assertSame('phone', $kinds['الهاتف']);
        $this->assertSame('email', $kinds['البريد']);
        $this->assertSame(1, $d->profile['formulas'], 'معادلةٌ رُصدت ولم تُحسب');
        $this->assertCount(6, $d->sample);

        /* والملفُّ نفسُه ثانيةً يُقال */
        try {
            $this->register('customers.csv', 'customers');
            $this->fail('سُجّل الملفُّ نفسُه مرّتين');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('DATA-0001', $e->getMessage());
        }

        $this->assertSame('DATA-0002', Datasets::nextCode((int) $this->business->id));
        $this->assertTrue(ActivityLog::where('subject_type', 'import_dataset')->where('description', 'like', '%سجّل مجموعة%')->exists());
    }

    public function test_mapping_targets_only_the_whitelist_and_required_fields_gate_the_run(): void
    {
        $d = $this->register('customers.csv', 'customers');

        foreach ([
            ['columns' => [['source' => 'اسم العميل', 'target' => 'customers.name']]],
            ['columns' => [['source' => 'اسم العميل', 'target' => 'business_id']]],
            ['columns' => [['source' => 'عمود لا وجود له', 'target' => 'name']]],
            ['columns' => [['source' => 'اسم العميل', 'target' => 'name', 'transforms' => ['eval']]]],
            ['constants' => ['role' => 'admin']],
        ] as $bad) {
            try {
                Datasets::map($d, $bad, $this->owner);
                $this->fail('قُبلت خريطةٌ خارج القائمة');
            } catch (RuntimeException) {
            }
        }

        $d = $this->map($d, ['الهاتف' => 'phone']);
        $this->assertSame(ImportDataset::NEEDS_MAPPING, $d->status, 'الاسمُ إلزاميٌّ ولم يُربط');
        try {
            Runner::dryRun($d, $this->owner);
            $this->fail('جُرّبت مجموعةٌ ناقصةُ الخريطة');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('اسم العميل', $e->getMessage());
        }

        $d = $this->map($d, ['الهاتف' => 'phone', 'اسم العميل' => 'name']);
        $this->assertSame(ImportDataset::MAPPED, $d->status);
        $this->assertSame(2, $d->mapping_version);
    }

    public function test_a_dry_run_writes_no_business_records_and_reports_what_it_would_do(): void
    {
        $d = $this->customers();
        $run = $this->dryRun($d);

        $this->assertSame(0, Customer::count(), 'التجربةُ لا تكتب عميلًا');
        $this->assertSame(0, ImportLink::count());
        $this->assertSame(ImportRun::DRY_RUN, $run->kind);
        $this->assertSame(ImportRun::FAILED, $run->status, 'صفٌّ بهاتفٍ وبريدٍ غير صالحين خطأ');

        $t = $run->totals;
        $this->assertSame(6, $t['rows']);
        $this->assertSame(4, $t['created'], 'أربعةٌ جدد');
        $this->assertSame(1, $t['linked'], 'الصفُّ الرابع يطابق الثاني بالهاتف تمامًا — يُربط بما «سيُنشأ» في التجربة نفسِها');
        $this->assertSame(0, $t['conflicts']);
        $this->assertSame(1, $t['errors'], 'هاتفٌ «12» وبريدٌ «bad-email»');

        $rows = ImportRow::where('run_id', $run->id)->orderBy('row_number')->get();
        $this->assertSame(ImportRow::WOULD_CREATE, $rows[0]->outcome);
        $this->assertSame(ImportRow::WOULD_LINK, $rows[3]->outcome);
        $this->assertSame(ImportRow::ERROR, $rows[5]->outcome);
        $this->assertStringContainsString('هاتفٌ غير صالح', implode(' ', $rows[5]->messages));
        $this->assertStringContainsString('معادلة', implode(' ', $rows[4]->messages), 'المعادلةُ تُنبَّه ولا تُنفَّذ');
        $this->assertSame(ImportDataset::VALIDATION_FAILED, $d->fresh()->status);

        /* ولا يُعتمد ما فشل */
        try {
            Approval::approve($d->fresh(), $this->owner);
            $this->fail('اعتُمدت مجموعةٌ فشل فحصُها');
        } catch (RuntimeException) {
        }
    }

    public function test_the_full_road_creates_links_and_traces_and_a_second_import_creates_nothing(): void
    {
        /* الصفُّ السادس خطأٌ — يُصلَح بقرار تخطٍّ؟ لا: الخطأ يمنع؛ فنُخرجه من الملفّ عبر خريطةٍ بلا هاتف/بريد لهذا الاختبار */
        $d = $this->register('customers.csv', 'customers');
        $d = $this->map($d, ['المعرف' => 'external_id', 'اسم العميل' => 'name', 'الهاتف' => 'phone', 'الرقم الضريبي' => 'tax_number']);

        $dry = $this->dryRun($d);
        $this->assertSame(1, $dry->totals['errors']);

        /* قرارُ «تخطٍّ» على الصفّ السادس لا يُسكت خطأ الصيغة: الخطأُ يبقى خطأً */
        Datasets::resolve($d->fresh(), ['6' => 'skip'], $this->owner);
        $dry = $this->dryRun($d);
        $this->assertSame(1, $dry->totals['errors'], 'قرارُ التخطّي لا يغطّي هاتفًا غير صالح');

        /* وقرارُ ربطٍ بمعرِّفٍ ليس في الشركة يُردّ */
        Datasets::resolve($d->fresh(), ['4' => 'link:999999'], $this->owner);
        $dry = $this->dryRun($d);
        $this->assertSame(2, $dry->totals['errors']);
        $this->assertStringContainsString('ليس من شركتك', json_encode($dry->findings, JSON_UNESCAPED_UNICODE));
    }

    public function test_customers_import_end_to_end_with_duplicate_decisions_idempotency_and_provenance(): void
    {
        $d = $this->register('suppliers.csv', 'suppliers');
        $d = $this->map($d, ['id' => 'external_id', 'name' => 'name', 'phone' => 'phone', 'email' => 'email', 'contact' => 'contact_person']);

        $dry = $this->dryRun($d);
        $this->assertSame(2, $dry->totals['created']);
        $this->assertSame(1, $dry->totals['conflicts'], 'الصفُّ الثالث يطابق الأوّل بالاسم وحده — محتمَلٌ لا يُدمج بصمت');
        $this->assertSame(ImportDataset::NEEDS_REVIEW, $d->fresh()->status);

        try {
            Approval::approve($d->fresh(), $this->owner);
            $this->fail('اعتُمدت مجموعةٌ فيها تعارضٌ لم يُقرَّر');
        } catch (RuntimeException) {
        }

        Datasets::resolve($d->fresh(), ['3' => 'skip'], $this->owner);
        $run = $this->importAll($d);

        $this->assertSame(ImportRun::SUCCESS, $run->status);
        $this->assertSame(2, Supplier::count());
        $this->assertSame(2, $run->totals['created']);
        $this->assertSame(1, $run->totals['skipped']);
        $this->assertSame(ImportDataset::VERIFIED, $d->fresh()->status);
        $this->assertTrue($d->fresh()->reconciliation['balanced']);

        $supplier = Supplier::where('name', 'مطابع الشرق')->firstOrFail();
        $this->assertSame(Provenance::HISTORICAL, $supplier->origin);
        $link = ImportLink::where('linkable_id', $supplier->id)->firstOrFail();
        $this->assertSame('DATA-0001#ROW-1', $link->source_ref);
        $this->assertSame('S-1', $link->external_id);
        $this->assertSame('DATA-0001#ROW-1', Provenance::sourceRef($supplier));

        /* الاستيرادُ ثانيةً لا يكرّر: كلُّ صفٍّ له أثر */
        $again = ImportDataset::find($d->id);
        $again->update(['status' => ImportDataset::READY]);
        Approval::approve($again->fresh(), $this->owner);
        $second = Runner::import($again->fresh(), $this->owner);
        $this->assertSame(2, Supplier::count(), 'لا مورّدَ ثالث');
        $this->assertSame(0, $second->totals['created']);
        $this->assertSame(3, $second->totals['skipped']);
        $this->assertSame(2, ImportLink::where('action', ImportLink::CREATED)->count());

        /* والتقريرُ يقول ما جرى بلا بيانات أشخاص */
        $report = $d->fresh()->importRun->report;
        $this->assertSame('DATA-0001', $report['dataset']);
        $this->assertSame($d->checksum, $report['checksum']);
        $this->assertSame('المالك', $report['import']['by']);
        $this->assertStringNotContainsString('92221111', json_encode($report, JSON_UNESCAPED_UNICODE));
    }

    public function test_services_and_projects_import_with_references_to_imported_customers(): void
    {
        $customers = $this->register('customers.csv', 'customers');
        $customers = $this->map($customers, ['المعرف' => 'external_id', 'اسم العميل' => 'name']);
        Datasets::resolve($customers, ['4' => 'create'], $this->owner);
        $this->importAll($customers);
        $this->assertSame(6, Customer::count());

        $this->importAll($this->services());
        $this->assertSame(3, Product::count());
        $this->assertSame(Product::PACKAGE, Product::where('sku', 'SV-3')->value('kind'));

        $run = $this->importAll($this->projects());
        $this->assertSame(ImportRun::SUCCESS, $run->status, json_encode($run->findings, JSON_UNESCAPED_UNICODE));
        $this->assertSame(3, Project::count());

        $closed = Project::where('name', 'مؤتمر الطاقة 2024')->firstOrFail();
        $this->assertSame(Project::CLOSED, $closed->status);
        $this->assertSame('2024-03-20', $closed->closed_at->toDateString());
        $this->assertEqualsWithDelta(12500.0, (float) $closed->approved_value, 0.0005);
        $this->assertSame(Provenance::HISTORICAL, $closed->origin);
        $this->assertNull($closed->quotation_id, 'لا عرضَ مختلَق');
        $this->assertNull($closed->requirements_baselined_at, 'لا تثبيتَ مختلَق');
        $this->assertNull($closed->financials_frozen, 'لا لقطةَ إقفالٍ مختلَقة');
        $this->assertSame((int) Customer::where('name', 'وزارة الثقافة')->value('id'), (int) $closed->customer_id, 'العميلُ بمعرِّفه القديم');
        $this->assertStringStartsWith('EP-', $closed->code);
        $this->assertSame('P-2024-01', ImportLink::where('linkable_id', $closed->id)->where('linkable_type', $closed->getMorphClass())->value('external_id'));

        $cancelled = Project::where('name', 'معرض الخليج')->firstOrFail();
        $this->assertSame(Project::CANCELLED, $cancelled->status);
        $this->assertNull($cancelled->cancellation_fee_invoice_id, 'لا رسمَ إلغاءٍ يُفوتَر لتاريخ');
    }
}
