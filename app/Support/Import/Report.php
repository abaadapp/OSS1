<?php

namespace App\Support\Import;

use App\Models\ImportDataset;
use App\Models\ImportRun;

/**
 * تقريرُ الاستيراد الدائم — ما يُقرأ بعد سنة ليُعرف ماذا دخل ومن أدخله (§27).
 *
 * بلا بياناتِ أشخاص: أرقامٌ وبصماتٌ وأسماءُ موظّفين وتواريخ. ويُحفظ مع
 * التنفيذ (`import_runs.report`)، ويُصدَّر Markdown إلى الخزنة إن أُريد.
 */
final class Report
{
    /** @return array<string, mixed> */
    public static function build(ImportDataset $dataset, ImportRun $run): array
    {
        $dry = $dataset->dryRun;

        return [
            'dataset' => $dataset->code,
            'name' => $dataset->name,
            'category' => $dataset->category,
            'checksum' => $dataset->checksum,
            'filename' => $dataset->original_filename,
            'rows' => $dataset->row_count,
            'mapping_version' => $dataset->mapping_version,
            'strategy' => $dataset->strategy,
            'cutover' => Cutover::date((int) $dataset->business_id)?->toDateString(),
            'dry_run' => $dry === null ? null : ['id' => $dry->id, 'status' => $dry->status, 'totals' => $dry->totals, 'at' => optional($dry->finished_at)->toDateTimeString()],
            'approval' => ['at' => optional($dataset->approved_at)->toDateTimeString(), 'by' => $dataset->approver?->name, 'fingerprint' => $dataset->approved_fingerprint],
            'import' => ['id' => $run->id, 'status' => $run->status, 'by' => $run->user?->name,
                'started_at' => optional($run->started_at)->toDateTimeString(), 'finished_at' => optional($run->finished_at)->toDateTimeString()],
            'totals' => $run->totals,
            'financial' => $run->financial,
            'reconciliation' => $run->reconciliation,
            'rollback' => $dataset->rollback,
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    /** نصُّ التقرير Markdown — للخزنة (`import-reports/`) بلا بياناتٍ حسّاسة */
    public static function markdown(array $report): string
    {
        $t = $report['totals'] ?? [];
        $r = $report['reconciliation'] ?? [];
        $f = $r['financial'] ?? null;

        $lines = [
            '# تقرير استيراد — '.$report['dataset'],
            '',
            '| الحقل | القيمة |', '|---|---|',
            '| المجموعة | '.$report['dataset'].' — '.$report['name'].' |',
            '| الصنف | '.$report['category'].' |',
            '| الملفّ | '.$report['filename'].' |',
            '| البصمة | `'.$report['checksum'].'` |',
            '| الصفوف | '.($report['rows'] ?? '—').' |',
            '| إصدار الخريطة | '.$report['mapping_version'].' |',
            '| الاستراتيجيّة | '.($report['strategy'] ?? '—').' |',
            '| تاريخ التحويل | '.($report['cutover'] ?? '—').' |',
            '| التجربة | #'.($report['dry_run']['id'] ?? '—').' — '.($report['dry_run']['status'] ?? '—').' |',
            '| الاعتماد | '.($report['approval']['at'] ?? '—').' — '.($report['approval']['by'] ?? '—').' |',
            '| الاستيراد | #'.$report['import']['id'].' — '.$report['import']['status'].' — '.($report['import']['by'] ?? '—').' |',
            '| بدأ / انتهى | '.($report['import']['started_at'] ?? '—').' → '.($report['import']['finished_at'] ?? '—').' |',
            '',
            '## الحصيلة', '',
            '| أُنشئ | رُبط | تُخطّي | تعارضات | أخطاء |', '|---|---|---|---|---|',
            '| '.($t['created'] ?? 0).' | '.($t['linked'] ?? 0).' | '.($t['skipped'] ?? 0).' | '.($t['conflicts'] ?? 0).' | '.($t['errors'] ?? 0).' |',
            '',
            '## المطابقة', '',
            '- صفوف المصدر: '.($r['source_rows'] ?? '—'),
            '- وحدات المصدر: '.($r['source_units'] ?? '—'),
            '- في الوجهة (أُنشئ): '.($r['destination_created'] ?? '—'),
            '- في الوجهة (رُبط): '.($r['destination_linked'] ?? '—'),
            '- متوازنة: '.(($r['balanced'] ?? false) ? 'نعم' : 'لا'),
        ];

        if ($f !== null) {
            $lines[] = '';
            $lines[] = '### المال';
            $lines[] = '';
            $lines[] = '| المصدر | المستورَد | الفرق |';
            $lines[] = '|---|---|---|';
            $lines[] = '| '.number_format((float) $f['source_total'], 3).' | '.number_format((float) $f['imported_total'], 3).' | '.number_format((float) $f['difference'], 3).' |';
        }

        $lines[] = '';
        $lines[] = '## التراجع';
        $lines[] = '';
        $lines[] = '- '.(($report['rollback']['at'] ?? null) ? 'نُفّذ في '.$report['rollback']['at'] : 'لم يُطلب');
        $lines[] = '';
        $lines[] = '_أُنشئ في '.$report['generated_at'].' — لا بيانات أشخاصٍ في هذا التقرير._';

        return implode("\n", $lines)."\n";
    }
}
