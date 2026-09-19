/**
 * أسماءُ حالات مجموعات البيانات ونتائجُ الصفوف وألوانُها — المرحلة 9.
 *
 * والمفاتيحُ لاتينيّةٌ في القاعدة وعربيّةٌ هنا كما في `lib/projects.ts`.
 */

export const DATASET_STATUS_LABEL: Record<string, string> = {
    registered: 'مسجَّلة',
    profiled: 'مقروءة',
    needs_mapping: 'تحتاج ربطًا',
    mapped: 'مربوطة',
    validation_failed: 'فشل الفحص',
    needs_review: 'تنتظر مراجعة',
    ready: 'جاهزة',
    approved: 'معتمَدة',
    importing: 'تُستورد',
    imported: 'مستورَدة',
    verified: 'مطابَقة',
    failed: 'فشلت',
    cancelled: 'ملغاة',
};

export const datasetTone = (status: string): string =>
    ['validation_failed', 'failed', 'cancelled'].includes(status)
        ? 'bg-[#fef2f2] text-[#b91c1c]'
        : status === 'needs_review'
          ? 'bg-[#fffbeb] text-[#b45309]'
          : ['ready', 'approved', 'verified'].includes(status)
            ? 'bg-[#ecfdf5] text-[#047857]'
            : status === 'imported'
              ? 'bg-[#eff6ff] text-[#1d4ed8]'
              : 'bg-[#f4f4f5] text-[#71717a]';

/** الخطواتُ العشر — وأيُّها بلغتها المجموعة */
export const STEPS: { key: string; label: string }[] = [
    { key: 'register', label: 'التسجيل' },
    { key: 'category', label: 'الصنف' },
    { key: 'mapping', label: 'الربط' },
    { key: 'validation', label: 'الفحص' },
    { key: 'duplicates', label: 'التكرارات' },
    { key: 'dry_run', label: 'التجربة' },
    { key: 'review', label: 'المراجعة' },
    { key: 'approve', label: 'الاعتماد' },
    { key: 'import', label: 'الاستيراد' },
    { key: 'reconcile', label: 'المطابقة' },
];

/** رقمُ الخطوة (1–10) التي تقف عندها المجموعة */
export const stepOf = (status: string): number =>
    ({
        registered: 1,
        profiled: 2,
        needs_mapping: 3,
        mapped: 4,
        validation_failed: 4,
        needs_review: 5,
        ready: 7,
        approved: 8,
        importing: 9,
        imported: 10,
        verified: 10,
        failed: 9,
        cancelled: 0,
    })[status] ?? 1;

export const OUTCOME_LABEL: Record<string, string> = {
    created: 'أُنشئ',
    linked: 'رُبط',
    skipped: 'تُخطّي',
    conflict: 'تعارض',
    error: 'خطأ',
    would_create: 'سيُنشأ',
    would_link: 'سيُربط',
    would_skip: 'سيُتخطّى',
};

export const outcomeTone = (outcome: string): string =>
    outcome === 'error'
        ? 'bg-[#fef2f2] text-[#b91c1c]'
        : outcome === 'conflict'
          ? 'bg-[#fffbeb] text-[#b45309]'
          : ['created', 'would_create'].includes(outcome)
            ? 'bg-[#ecfdf5] text-[#047857]'
            : ['linked', 'would_link'].includes(outcome)
              ? 'bg-[#eff6ff] text-[#1d4ed8]'
              : 'bg-[#f4f4f5] text-[#71717a]';

export const MATCH_LABEL: Record<string, string> = {
    exact: 'مطابقة تامّة',
    probable: 'مطابقة محتمَلة',
    new: 'جديد',
    conflict: 'تعارض',
};

export const KIND_LABEL: Record<string, string> = {
    empty: 'فارغ',
    email: 'بريد',
    phone: 'هاتف',
    date: 'تاريخ',
    amount: 'مبلغ',
    number: 'رقم',
    identifier: 'معرِّف',
    text: 'نصّ',
};

export const RUN_STATUS_LABEL: Record<string, string> = {
    running: 'يعمل',
    success: 'نجح',
    partial: 'جزئيّ',
    failed: 'فشل',
};

export const RUN_KIND_LABEL: Record<string, string> = {
    dry_run: 'تجربة',
    import: 'استيراد',
    rollback: 'تراجع',
};

export const ROLLBACK_VERDICT_LABEL: Record<string, string> = {
    remove: 'يُزال',
    reverse: 'يُلغى بقيدٍ عكسيّ',
    in_use: 'مستعمَلٌ — يبقى',
    not_reversible: 'غيرُ قابلٍ للتراجع',
    unlink: 'يُفكّ أثرُه فقط',
};
