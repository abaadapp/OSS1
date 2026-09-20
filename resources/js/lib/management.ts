/**
 * أنواعُ الذكاء الإداريّ وما تعرفه الشاشةُ وحدَها — المرحلة 11.
 *
 * الأرقامُ تأتي من الخادم بأساسها ومدّتها وعملتها؛ وهنا الأنواعُ والألوانُ
 * وقراءةُ الاكتمال. ولا حسابَ ماليًّا في المتصفّح.
 */

export interface PeriodInfo {
    key: string;
    label: string;
    from: string;
    to: string;
    timezone: string;
    days: number;
}

export interface PeriodOption {
    key: string;
    label: string;
}

export interface Currency {
    code: string;
    symbol?: string;
    decimals?: number;
    [key: string]: unknown;
}

export interface CompletenessInfo {
    status: 'complete' | 'partial' | 'insufficient';
    reasons: string[];
}

export interface Change {
    percent: number | null;
    status: 'ok' | 'insufficient_data';
    delta?: number;
}

export interface Alert {
    kind: string;
    severity: 'critical' | 'warning' | 'info';
    title: string;
    detail: string;
    facts: { value?: number; threshold?: number; period?: string; [k: string]: unknown };
    url: string;
    at: string;
}

export const COMPLETENESS_LABEL: Record<string, string> = {
    complete: 'مكتمل',
    partial: 'جزئيّ',
    insufficient: 'غير كافٍ',
};

export const completenessTone = (status: string): string =>
    status === 'complete'
        ? 'bg-[#ecfdf5] text-[#047857]'
        : status === 'partial'
          ? 'bg-[#fffbeb] text-[#b45309]'
          : 'bg-[#f4f4f5] text-[#71717a]';

export const severityTone = (severity: string): string =>
    severity === 'critical'
        ? 'bg-[#fef2f2] text-[#b91c1c]'
        : severity === 'warning'
          ? 'bg-[#fffbeb] text-[#b45309]'
          : 'bg-[#eff6ff] text-[#1d4ed8]';

export const SEVERITY_LABEL: Record<string, string> = { critical: 'حرج', warning: 'تحذير', info: 'معلومة' };

export const marginTone = (percent: number | null): string =>
    percent === null ? 'text-[#9ca3af]' : percent < 0 ? 'text-[#b91c1c]' : percent < 15 ? 'text-[#b45309]' : 'text-[#047857]';

export const BUDGET_STATUS_LABEL: Record<string, string> = {
    draft: 'مسودّة',
    approved: 'معتمَدة',
    superseded: 'حلّت محلّها نسخة',
    archived: 'مؤرشفة',
};

export const budgetTone = (status: string): string =>
    status === 'approved'
        ? 'bg-[#ecfdf5] text-[#047857]'
        : status === 'draft'
          ? 'bg-[#fffbeb] text-[#b45309]'
          : 'bg-[#f4f4f5] text-[#71717a]';

export const FORECAST_STATUS_LABEL: Record<string, string> = { draft: 'مسودّة', approved: 'معتمَد', superseded: 'حلّ محلّه أحدث' };

export const CATEGORY_LABEL: Record<string, string> = { committed: 'ملتزَم', expected: 'متوقَّع', scenario: 'سيناريو', pipeline: 'خطّ أنابيب' };

export const categoryTone = (category: string): string =>
    category === 'committed'
        ? 'bg-[#eff6ff] text-[#1d4ed8]'
        : category === 'expected'
          ? 'bg-[#f4f4f5] text-[#71717a]'
          : category === 'pipeline'
            ? 'bg-[#fdf4ff] text-[#7e22ce]'
            : 'bg-[#fffbeb] text-[#b45309]';

export const REVIEW_STATUS_LABEL: Record<string, string> = { draft: 'مسودّة', reviewed: 'رُوجعت', closed: 'مقفلة' };

export const RUNWAY_LABEL: Record<string, string> = { ok: 'مدرج', cash_positive: 'صافي الحرق موجب', insufficient_data: 'لا بيانات كافية' };

/** نسبةٌ مقروءة — أو شرطةٌ حين لا أساس */
export const pct = (value: number | null | undefined): string => (value === null || value === undefined ? '—' : `${value}%`);
