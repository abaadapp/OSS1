<?php

namespace Tests\Feature\Import;

use App\Models\Business;
use App\Models\Customer;
use App\Models\ImportDataset;
use App\Models\ImportMappingTemplate;
use App\Models\ImportRun;
use App\Models\JobTitle;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Import\Datasets;
use App\Support\Import\FileGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use RuntimeException;

/**
 * مركزُ الاستيراد يحرس أبوابَه — الشركةُ والصلاحيّةُ والملفُّ والحدود.
 */
class TheImportCenterGuardsItsDoorsTest extends ImportTestCase
{
    private function xlsx(array $rows, bool $formula = false): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        foreach ($rows as $r => $cells) {
            foreach ($cells as $c => $value) {
                $sheet->setCellValue([$c + 1, $r + 1], $value);
            }
        }
        if ($formula) {
            $sheet->setCellValue([3, 2], '=1+1');
        }
        $path = storage_path('framework/testing/'.uniqid('t', true).'.xlsx');
        (new XlsxWriter($book))->save($path);

        return $path;
    }

    /* ─────────────────────────── الشركة ─────────────────────────── */

    public function test_datasets_are_invisible_and_immutable_across_tenants(): void
    {
        $d = $this->suppliers();

        $other = Business::create(['name' => 'شركة أخرى', 'type' => 'عام', 'status' => 'نشط']);
        JobTitle::create(['business_id' => $other->id, 'name' => 'مدير', 'role' => 'admin']);
        $stranger = User::create(['business_id' => $other->id, 'name' => 'غريب', 'email' => 'x@other.om', 'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط']);

        $this->actingAs($stranger);
        $this->get(route('admin.imports.index'))->assertOk()->assertInertia(fn ($p) => $p->has('datasets', 0));
        $this->get(route('admin.imports.show', $d->id))->assertNotFound();
        $this->get(route('admin.imports.preview', $d->id))->assertNotFound();
        $this->put(route('admin.imports.mapping', $d->id), ['columns' => []])->assertNotFound();
        $this->post(route('admin.imports.dryRun', $d->id))->assertNotFound();
        $this->post(route('admin.imports.approve', $d->id))->assertNotFound();
        $this->post(route('admin.imports.import', $d->id))->assertNotFound();
        $this->post(route('admin.imports.rollback', $d->id), ['reason' => 'x'])->assertNotFound();

        /* ومعرِّفٌ للربط من شركةٍ أخرى لا يُقبل — الفهرسُ لا يرى إلّا شركتَه */
        $foreign = Supplier::create(['business_id' => $other->id, 'name' => 'مطابع الشرق', 'phone' => '92221111']);
        $this->actingAs($this->owner);
        Datasets::resolve($d->fresh(), ['1' => 'link:'.$foreign->id], $this->owner);
        $dry = $this->dryRun($d);
        $this->assertSame(1, $dry->totals['errors']);
        $this->assertStringContainsString('ليس من شركتك', json_encode($dry->findings, JSON_UNESCAPED_UNICODE));

        /* والمرجعُ بالاسم لا يعبر الشركات: مورّدُ الأخرى لا يُطابَق */
        Datasets::resolve($d->fresh(), ['1' => 'create', '3' => 'skip'], $this->owner);
        $dry = $this->dryRun($d);
        $this->assertSame(2, $dry->totals['created']);
    }

    /* ─────────────────────────── الصلاحيّات ─────────────────────────── */

    public function test_every_write_needs_its_action_and_the_screen_needs_the_section(): void
    {
        $d = $this->suppliers();

        /* المحاسبُ لا يملك القسم أصلًا */
        $this->actingAs($this->clerk);
        $this->get(route('admin.imports.index'))->assertForbidden();
        $this->post(route('admin.imports.dryRun', $d->id))->assertForbidden();

        /* موظّفٌ مُنح القسمَ للقراءة وحدَها */
        $reader = User::create(['business_id' => $this->business->id, 'name' => 'قارئ', 'email' => 'reader@hadath.om',
            'password' => bcrypt('password'), 'role' => 'accountant', 'status' => 'نشط', 'permissions' => ['dashboard', 'imports']]);
        $this->actingAs($reader);
        $this->get(route('admin.imports.index'))->assertOk();
        $this->get(route('admin.imports.show', $d->id))->assertOk()->assertInertia(fn ($p) => $p->where('may.manage', false)->missing('dataset.path'));
        $this->post(route('admin.imports.store'), ['file' => UploadedFile::fake()->createWithContent('a.csv', "name\nx\n"), 'category' => 'customers'])->assertForbidden();
        $this->put(route('admin.imports.mapping', $d->id), ['columns' => []])->assertForbidden();
        $this->post(route('admin.imports.dryRun', $d->id))->assertForbidden();
        $this->post(route('admin.imports.approve', $d->id))->assertForbidden();
        $this->post(route('admin.imports.import', $d->id))->assertForbidden();
        $this->post(route('admin.imports.rollback', $d->id), ['reason' => 'x'])->assertForbidden();
        $this->put(route('admin.imports.cutover'), ['cutover' => '2024-01-01'])->assertForbidden();

        /* والمديرُ يدير وينفّذ — ولا يملك الماليَّ ولا التراجع */
        $this->actingAs($this->manager);
        Datasets::resolve($d->fresh(), ['3' => 'skip'], $this->manager);
        $this->post(route('admin.imports.dryRun', $d->id))->assertSessionHasNoErrors();
        $this->post(route('admin.imports.approve', $d->id))->assertSessionHasNoErrors();
        $this->post(route('admin.imports.import', $d->id))->assertSessionHasNoErrors();
        $this->assertSame(2, Supplier::count());
        $this->post(route('admin.imports.rollback', $d->id), ['reason' => 'x'])->assertForbidden();
        $this->put(route('admin.imports.cutover'), ['cutover' => '2024-01-01'])->assertForbidden();

        $this->cutover('2024-01-01');
        $e = $this->register('expenses.csv', 'expenses');
        $e = $this->map($e, ['النوع' => 'type', 'المبلغ' => 'amount', 'التاريخ' => 'spent_at']);
        Datasets::strategy($e, ImportDataset::HISTORICAL, $this->manager);
        $this->post(route('admin.imports.dryRun', $e->id))->assertSessionHasNoErrors();
        $this->post(route('admin.imports.approve', $e->id))->assertSessionHasErrors('import');
        $this->assertSame(ImportDataset::READY, $e->fresh()->status);
    }

    /* ─────────────────────────── الملفّ ─────────────────────────── */

    public function test_the_source_file_lives_on_the_private_disk_and_never_reaches_a_url(): void
    {
        $d = $this->suppliers();

        $this->assertSame('local', Datasets::DISK, 'القرصُ الخاصّ لا العامّ');
        $this->assertStringNotContainsString('public', $d->path);
        Storage::disk('local')->assertExists($d->path);
        Storage::disk('public')->assertMissing($d->path);

        $this->get(route('admin.imports.show', $d->id))->assertOk()
            ->assertInertia(fn ($p) => $p->missing('dataset.path'))
            ->assertDontSee('imports/'.$this->business->id.'/DATA-0001');

        $this->assertNull(collect(app('router')->getRoutes()->getRoutesByName())->keys()
            ->first(fn ($n) => str_starts_with($n, 'admin.imports.') && str_contains($n, 'download')), 'لا مسارَ تنزيلٍ للملفّ');
    }

    public function test_unsupported_binary_and_malformed_files_are_rejected_before_they_are_read(): void
    {
        foreach ([['fake.xlsx', 'ملفّ XLSX تالف'], ['binary.csv', 'ليس نصًّا']] as [$name, $why]) {
            try {
                $this->register($name, 'customers');
                $this->fail('قُبل '.$name);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($why, $e->getMessage());
            }
        }

        foreach (['data.sql', 'run.php', 'x.exe', 'sheet.xls', 'notes.txt'] as $name) {
            try {
                FileGuard::extension($name);
                $this->fail('قُبل '.$name);
            } catch (RuntimeException) {
            }
        }

        $this->post(route('admin.imports.store'), ['file' => UploadedFile::fake()->createWithContent('hack.php', '<?php echo 1;'), 'category' => 'customers'])
            ->assertSessionHasErrors('file');
        $this->post(route('admin.imports.store'), ['file' => UploadedFile::fake()->create('dump.sql', 10), 'category' => 'customers'])
            ->assertSessionHasErrors('file');
        $this->assertSame(0, ImportDataset::count());

        /* ومعالجُ CSV مكسور: اقتباسٌ لا يُغلق — يُقرأ صفًّا واحدًا ناقصًا ويُقال، لا ينهار */
        $d = $this->register('malformed.csv', 'customers');
        $this->assertSame(1, $d->row_count);
        $d = $this->map($d, ['name' => 'name', 'phone' => 'phone']);
        $dry = $this->dryRun($d);
        $this->assertSame(1, $dry->totals['errors'] + $dry->totals['created']);
    }

    public function test_xlsx_is_read_in_chunks_and_formulas_arrive_as_text_never_as_results(): void
    {
        $rows = [['الاسم', 'الهاتف', 'ملاحظة']];
        for ($i = 1; $i <= 1200; $i++) {
            $rows[] = ['عميل '.$i, '9'.str_pad((string) (2000000 + $i), 7, '0', STR_PAD_LEFT), 'n'];
        }
        $path = $this->xlsx($rows, formula: true);

        $d = Datasets::registerPath((int) $this->business->id, $path, ['category' => 'customers', 'name' => 'x'], $this->owner);
        $d = Datasets::profile($d, $this->owner);
        $this->assertSame(1200, $d->row_count);
        $this->assertSame(['الاسم', 'الهاتف', 'ملاحظة'], $d->columns);
        $this->assertSame('=1+1', $d->sample[0][2], 'المعادلةُ نصٌّ لا «2»');
        $this->assertSame(1, $d->profile['formulas']);

        $d = $this->map($d, ['الاسم' => 'name', 'الهاتف' => 'phone', 'ملاحظة' => 'notes']);
        $run = $this->importAll($d);
        $this->assertSame(ImportRun::SUCCESS, $run->status);
        $this->assertSame(1200, Customer::count());
        $this->assertSame('=1+1', Customer::where('name', 'عميل 1')->value('notes'), 'حُفظت نصًّا');
        $this->assertGreaterThan(0, $run->totals['warnings']);
    }

    public function test_row_and_column_limits_are_enforced(): void
    {
        $path = storage_path('framework/testing/wide.csv');
        file_put_contents($path, implode(',', array_map(fn ($i) => 'c'.$i, range(1, FileGuard::MAX_COLUMNS + 1)))."\nx\n");
        try {
            Datasets::registerPath((int) $this->business->id, $path, ['category' => 'customers', 'name' => 'w'], $this->owner);
            $this->fail('قُبل ملفٌّ فوق حدّ الأعمدة');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('الأعمدة', $e->getMessage());
        }

        $path = storage_path('framework/testing/tall.csv');
        $h = fopen($path, 'w');
        fwrite($h, "name\n");
        for ($i = 0; $i <= FileGuard::MAX_ROWS; $i++) {
            fwrite($h, "r$i\n");
        }
        fclose($h);
        try {
            $d = Datasets::registerPath((int) $this->business->id, $path, ['category' => 'customers', 'name' => 't'], $this->owner);
            Datasets::profile($d, $this->owner);
            $this->fail('قُبل ملفٌّ فوق حدّ الصفوف');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('للصفوف', $e->getMessage());
        }
    }

    /* ─────────────────────────── الشاشةُ والرفع ─────────────────────────── */

    public function test_the_screen_uploads_registers_profiles_and_shows_the_wizard_state(): void
    {
        $file = UploadedFile::fake()->createWithContent('عملاء.csv', file_get_contents($this->fixture('suppliers.csv')));

        $response = $this->post(route('admin.imports.store'), ['file' => $file, 'category' => 'suppliers', 'name' => 'مورّدون قدامى', 'source' => 'إكسل']);

        /* بالمعرّف الفعليّ لا بـ1: تسلسلاتُ PostgreSQL لا تعود مع كلّ اختبار */
        $d = ImportDataset::firstOrFail();
        $response->assertRedirect(route('admin.imports.show', $d->id));
        $this->assertSame(ImportDataset::NEEDS_MAPPING, $d->status);
        $this->assertSame('عملاء.csv', $d->original_filename);
        $this->assertSame('upload', $d->metadata['registered_via']);

        $this->get(route('admin.imports.show', $d->id))->assertOk()->assertInertia(fn ($p) => $p
            ->where('dataset.code', 'DATA-0001')
            ->where('dataset.status', ImportDataset::NEEDS_MAPPING)
            ->has('dataset.columns', 5)
            ->has('fields')
            ->where('may.manage', true));

        $this->put(route('admin.imports.mapping', $d->id), ['columns' => [
            ['source' => 'id', 'target' => 'external_id'], ['source' => 'name', 'target' => 'name'], ['source' => 'phone', 'target' => 'phone'],
        ]])->assertSessionHasNoErrors();
        $this->assertSame(ImportDataset::MAPPED, $d->fresh()->status);

        $this->get(route('admin.imports.preview', ['id' => $d->id, 'page' => 1, 'per_page' => 10]))->assertOk()->assertJsonPath('total', 3);

        $this->post(route('admin.imports.dryRun', $d->id))->assertSessionHasNoErrors();
        $this->assertSame(ImportDataset::NEEDS_REVIEW, $d->fresh()->status);
        $this->get(route('admin.imports.show', $d->id))->assertOk()->assertInertia(fn ($p) => $p->has('conflicts', 1)->has('dry_run'));

        $this->put(route('admin.imports.resolve', $d->id), ['decisions' => ['3' => 'skip']])->assertSessionHasNoErrors();
        $this->post(route('admin.imports.dryRun', $d->id))->assertSessionHasNoErrors();
        $this->assertSame(ImportDataset::READY, $d->fresh()->status);

        /* الاستيرادُ قبل الاعتماد يُردّ — ثمّ يمرّ بعده */
        $this->post(route('admin.imports.import', $d->id))->assertSessionHasErrors('import');
        $this->assertSame(0, Supplier::count());
        $this->post(route('admin.imports.approve', $d->id))->assertSessionHasNoErrors();
        $this->post(route('admin.imports.import', $d->id))->assertSessionHasNoErrors();
        $this->assertSame(2, Supplier::count());
        $this->assertSame(ImportDataset::VERIFIED, $d->fresh()->status);

        $this->get(route('admin.imports.report', $d->id))->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
            ->assertSee('DATA-0001')->assertDontSee('92221111');
        $this->get(route('admin.imports.rows', [$d->id, $d->fresh()->import_run_id]))->assertOk()->assertJsonCount(3, 'rows');

        /* القالبُ يُحفظ ويُطبَّق على مجموعةٍ جديدة */
        $this->post(route('admin.imports.templates.store', $d->id), ['name' => 'مورّدو الإكسل'])->assertSessionHasNoErrors();
        $again = Datasets::registerPath((int) $this->business->id, $this->fixture('services.csv'), ['category' => 'suppliers', 'name' => 'x'], $this->owner);
        $again = Datasets::profile($again, $this->owner);
        $template = ImportMappingTemplate::firstOrFail();
        $this->post(route('admin.imports.templates.apply', $again->id), ['template_id' => $template->id])->assertSessionHasNoErrors();
        $this->assertSame(ImportDataset::NEEDS_MAPPING, $again->fresh()->status, 'أعمدةُ القالب ليست في هذا الملفّ — يُقال لا يُخمَّن');
    }

    public function test_the_cli_uses_the_same_services(): void
    {
        $this->artisan('data:import', ['action' => 'register', 'target' => $this->fixture('suppliers.csv'),
            '--business' => $this->business->id, '--user' => 'owner@hadath.om', '--category' => 'suppliers', '--name' => 'cli'])
            ->assertSuccessful();

        $d = ImportDataset::firstOrFail();
        $this->assertSame('cli', $d->metadata['registered_via']);
        $this->assertSame(3, $d->row_count);

        $this->map($d, ['id' => 'external_id', 'name' => 'name', 'phone' => 'phone']);
        Datasets::resolve($d->fresh(), ['3' => 'skip'], $this->owner);

        $this->artisan('data:import', ['action' => 'validate', 'target' => 'DATA-0001', '--business' => $this->business->id, '--user' => 'owner@hadath.om'])
            ->assertSuccessful();
        $this->artisan('data:import', ['action' => 'approve', 'target' => 'DATA-0001', '--business' => $this->business->id, '--user' => 'owner@hadath.om'])
            ->assertSuccessful();
        $this->artisan('data:import', ['action' => 'run', 'target' => 'DATA-0001', '--business' => $this->business->id, '--user' => 'owner@hadath.om'])
            ->expectsConfirmation('استيرادٌ حقيقيّ للمجموعة DATA-0001 (المورّدون) — يكتب في قاعدة البيانات. متابعة؟', 'yes')
            ->assertSuccessful();
        $this->assertSame(2, Supplier::count());

        /* وبلا مستخدمٍ يملك الفعل لا يُستورد */
        $this->artisan('data:import', ['action' => 'register', 'target' => $this->fixture('services.csv'),
            '--business' => $this->business->id, '--category' => 'services'])->assertFailed();
    }
}
