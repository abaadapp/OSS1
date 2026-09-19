<?php

namespace App\Support\Import;

use App\Models\ImportDataset;
use App\Models\ImportRun;
use App\Models\User;
use App\Support\Activity;
use App\Support\Permissions;
use RuntimeException;

/**
 * بوّابةُ المراجعة — لا استيرادَ بلا يدٍ بعد تجربةٍ ناجحة (§22).
 *
 * الاعتمادُ بصمةٌ على **ما رآه المعتمِد**: بصمةُ الملفّ، ورقمُ الخريطة،
 * والاستراتيجيّة، وقراراتُ التكرار، والتجربةُ نفسُها. تغيّر أحدُها سقط
 * الاعتمادُ وعادت المجموعةُ إلى التجربة. ولا يُعتمد ما في تجربته خطأ.
 */
final class Approval
{
    public static function fingerprint(ImportDataset $dataset, ?int $dryRunId = null): string
    {
        return hash('sha256', implode('|', [
            $dataset->checksum,
            (int) $dataset->mapping_version,
            (string) $dataset->strategy,
            json_encode($dataset->resolutions ?? [], JSON_UNESCAPED_UNICODE),
            (int) ($dryRunId ?? $dataset->dry_run_id),
            Cutover::date((int) $dataset->business_id)?->toDateString() ?? '',
        ]));
    }

    public static function approve(ImportDataset $dataset, ?User $actor = null): ImportDataset
    {
        Datasets::assertOpen($dataset);

        if ($dataset->status !== ImportDataset::READY) {
            throw new RuntimeException(__('لا يُعتمد إلّا ما نجحت تجربتُه — الحال الآن: :s', ['s' => Datasets::statusLabel($dataset->status)]));
        }

        $dry = $dataset->dryRun;

        if ($dry === null || $dry->status !== ImportRun::SUCCESS || $dry->checksum !== $dataset->checksum || (int) $dry->mapping_version !== (int) $dataset->mapping_version) {
            throw new RuntimeException(__('التجربةُ الأخيرة لا تطابق المجموعةَ الآن — أعد التجربة.'));
        }
        if ((int) ($dry->totals['errors'] ?? 0) > 0 || (int) ($dry->totals['conflicts'] ?? 0) > 0) {
            throw new RuntimeException(__('في التجربة أخطاءٌ أو تعارضاتٌ لم تُحلّ — لا يُعتمد.'));
        }
        if (Fields::isFinancial($dataset->category) && ! ($actor?->may(Permissions::IMPORT_FINANCIAL) ?? false)) {
            throw new RuntimeException(__('اعتمادُ استيرادٍ ماليّ يحتاج صلاحيّة الاستيراد الماليّ.'));
        }

        $dataset->update([
            'status' => ImportDataset::APPROVED,
            'approved_at' => now(),
            'approved_by' => $actor?->id,
            'reviewed_by' => $actor?->id,
            'approved_fingerprint' => self::fingerprint($dataset, $dry->id),
        ]);

        Activity::log('status', 'اعتمد استيراد المجموعة '.$dataset->code.' بعد تجربةٍ ناجحة (#'.$dry->id.')', [
            'subject_id' => $dataset->id, 'subject_type' => 'import_dataset', 'icon' => 'shield-check', 'color' => 'success',
        ]);

        return $dataset->fresh();
    }

    /** الاعتمادُ قائمٌ وما اعتُمد هو ما على الطاولة الآن؟ */
    public static function stands(ImportDataset $dataset): bool
    {
        return $dataset->status === ImportDataset::APPROVED
            && $dataset->approved_fingerprint !== null
            && hash_equals($dataset->approved_fingerprint, self::fingerprint($dataset));
    }

    /** إسقاطُ اعتمادٍ لأنّ شيئًا تغيّر — تعود المجموعةُ إلى ما قبل التجربة */
    public static function revoke(ImportDataset $dataset, string $why): void
    {
        $wasApproved = $dataset->status === ImportDataset::APPROVED;

        if (in_array($dataset->status, [ImportDataset::APPROVED, ImportDataset::READY, ImportDataset::VALIDATION_FAILED, ImportDataset::NEEDS_REVIEW], true)) {
            $dataset->update([
                'status' => Mapper::missing($dataset->category, $dataset->mapping ?? []) === [] && $dataset->mapping
                    ? ImportDataset::MAPPED : ImportDataset::NEEDS_MAPPING,
                'approved_at' => null, 'approved_by' => null, 'approved_fingerprint' => null,
            ]);
        }

        if ($wasApproved) {
            Activity::log('status', 'سقط اعتماد المجموعة '.$dataset->code.' — '.$why, [
                'subject_id' => $dataset->id, 'subject_type' => 'import_dataset', 'color' => 'warning',
            ]);
        }
    }
}
