<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\ImportDataset;
use App\Models\ImportRun;
use App\Models\User;
use App\Support\Import\Approval;
use App\Support\Import\Datasets;
use App\Support\Import\Fields;
use App\Support\Import\Report;
use App\Support\Import\Runner;
use App\Support\Permissions;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * سطرُ أوامر مركز الاستيراد — الخدماتُ نفسُها التي تناديها الشاشة، لا نسخةٌ ثانية.
 *
 *   data:import register  --business=1 --user=owner@x.om --category=customers --name=… path/to/file.csv
 *   data:import inspect   --business=1 DATA-0001
 *   data:import validate  --business=1 --user=… DATA-0001      (تجربةٌ جافّة)
 *   data:import approve   --business=1 --user=… DATA-0001
 *   data:import run       --business=1 --user=… DATA-0001      (استيرادٌ حقيقيّ — بعد اعتماد)
 *
 * والملفُّ من مسارٍ محلّيّ (الخزنة مثلًا) يُنسخ إلى القرص الخاصّ ويُقرأ من هناك.
 * ولا يُستورد شيءٌ بلا مستخدمٍ يملك الفعلَ: الصلاحيّةُ تُفحص في الخدمة.
 */
class DataImportCommand extends Command
{
    protected $signature = 'data:import
        {action : register · inspect · validate · approve · run · report}
        {target? : مسارُ الملفّ عند التسجيل، أو رمزُ المجموعة (DATA-0001)}
        {--business= : معرّفُ الشركة}
        {--user= : بريدُ المستخدم الذي يعمل باسمه}
        {--category= : صنفُ المجموعة عند التسجيل}
        {--name= : اسمُ المجموعة}
        {--source= : من أين جاء الملفّ}';

    protected $description = 'مركز استيراد البيانات التاريخيّة: تسجيلٌ وقراءةٌ وتجربةٌ واعتمادٌ واستيراد — من الخدمات نفسها';

    public function handle(): int
    {
        $business = Business::find((int) $this->option('business'));
        if ($business === null) {
            $this->error('حدّد الشركة: --business=ID');

            return self::FAILURE;
        }

        $user = null;
        if ($this->option('user')) {
            $user = User::where('business_id', $business->id)->where('email', $this->option('user'))->first();
            if ($user === null) {
                $this->error('لا مستخدمَ بهذا البريد في هذه الشركة.');

                return self::FAILURE;
            }
        }

        try {
            return match ($this->argument('action')) {
                'register' => $this->register($business, $user),
                'inspect' => $this->inspect($business),
                'validate' => $this->validateDataset($business, $user),
                'approve' => $this->approve($business, $user),
                'run' => $this->runImport($business, $user),
                'report' => $this->report($business),
                default => $this->bail('فعلٌ غير معروف'),
            };
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function register(Business $business, ?User $user): int
    {
        if (! $user?->may(Permissions::IMPORT_MANAGE)) {
            return $this->bail('التسجيل يحتاج مستخدمًا يملك import.manage — --user=');
        }
        $path = (string) $this->argument('target');
        $category = (string) $this->option('category');
        if (! in_array($category, Fields::CATEGORIES, true)) {
            return $this->bail('الصنف: '.implode(' · ', Fields::CATEGORIES));
        }

        $dataset = Datasets::registerPath((int) $business->id, $path, [
            'category' => $category, 'name' => $this->option('name'), 'source' => $this->option('source'), 'via' => 'cli',
        ], $user);
        $dataset = Datasets::profile($dataset, $user);

        $this->info('سُجّلت '.$dataset->code.' — '.$dataset->row_count.' صفًّا · '.$dataset->column_count.' عمودًا · '.$dataset->checksum);
        $this->line('الأعمدة: '.implode(' | ', $dataset->columns ?? []));
        $this->line('اربط الأعمدة من الشاشة ثمّ: data:import validate '.$dataset->code);

        return self::SUCCESS;
    }

    private function dataset(Business $business): ImportDataset
    {
        $code = strtoupper(trim((string) $this->argument('target')));

        return ImportDataset::where('business_id', $business->id)->where('code', $code)->firstOrFail();
    }

    private function inspect(Business $business): int
    {
        $d = $this->dataset($business);
        $this->table(['الحقل', 'القيمة'], [
            ['الرمز', $d->code], ['الاسم', $d->name], ['الصنف', Fields::label($d->category)], ['الحال', Datasets::statusLabel($d->status)],
            ['الصفوف', (string) $d->row_count], ['الأعمدة', (string) $d->column_count], ['البصمة', $d->checksum],
            ['إصدار الخريطة', (string) $d->mapping_version], ['الاستراتيجيّة', (string) ($d->strategy ?? '—')],
            ['الاعتماد', Approval::stands($d) ? 'قائم' : '—'],
        ]);
        foreach ($d->profile['columns'] ?? [] as $c) {
            $this->line(sprintf('  %-30s %-12s فارغ %3d%%  مميّز %d', mb_substr($c['name'], 0, 30), $c['kind'], $c['empty_percent'], $c['distinct']));
        }

        return self::SUCCESS;
    }

    private function validateDataset(Business $business, ?User $user): int
    {
        $run = Runner::dryRun($this->dataset($business), $user);
        $this->totals($run);

        return $run->status === ImportRun::SUCCESS ? self::SUCCESS : self::FAILURE;
    }

    private function approve(Business $business, ?User $user): int
    {
        $d = Approval::approve($this->dataset($business), $user);
        $this->info('اعتُمدت '.$d->code.' — البصمة '.$d->approved_fingerprint);

        return self::SUCCESS;
    }

    private function runImport(Business $business, ?User $user): int
    {
        $d = $this->dataset($business);
        if (! $this->confirm('استيرادٌ حقيقيّ للمجموعة '.$d->code.' ('.Fields::label($d->category).') — يكتب في قاعدة البيانات. متابعة؟')) {
            return self::FAILURE;
        }
        $run = Runner::import($d, $user);
        $this->totals($run);
        $this->line('المطابقة: '.(($run->reconciliation['balanced'] ?? false) ? 'متوازنة ✓' : 'فرق ✗'));

        return $run->status === ImportRun::SUCCESS ? self::SUCCESS : self::FAILURE;
    }

    private function report(Business $business): int
    {
        $d = $this->dataset($business);
        if ($d->importRun?->report === null) {
            return $this->bail('لا تقريرَ بعد — لم تُستورد المجموعة.');
        }
        $this->line(Report::markdown($d->importRun->report));

        return self::SUCCESS;
    }

    private function totals(ImportRun $run): void
    {
        $t = $run->totals ?? [];
        $this->info(($run->kind === ImportRun::DRY_RUN ? 'التجربة' : 'الاستيراد').' #'.$run->id.' — '.$run->status);
        $this->line('سيُنشأ/أُنشئ '.($t['created'] ?? 0).' · يُربط '.($t['linked'] ?? 0).' · يُتخطّى '.($t['skipped'] ?? 0).' · تعارضات '.($t['conflicts'] ?? 0).' · أخطاء '.($t['errors'] ?? 0).' · تنبيهات '.($t['warnings'] ?? 0));
        foreach (array_slice($run->findings ?? [], 0, 20) as $f) {
            $this->line('  صفّ '.$f['row'].': '.implode(' · ', $f['messages'] ?? []) ?: ($f['outcome'] ?? ''));
        }
        if ($run->financial) {
            $f = $run->financial;
            $this->line('الأثر الماليّ — ذمم عملاء '.number_format($f['receivables'], 3).' · ذمم موردين '.number_format($f['payables'], 3).' · إيراد '.number_format($f['revenue'], 3).' · مصروف '.number_format($f['expenses'], 3));
        }
    }

    private function bail(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
