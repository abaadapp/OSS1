/**
 * الأتمتة كما تُعرض — الأوضاعُ والحالاتُ والجمهورُ ونبراتُها. مصدرٌ واحد
 * للقواعد والسجلّ ومركز العمليات.
 */
export const MODE_LABEL: Record<string, string> = {
    off: 'مطفأة',
    observe: 'مراقبة — تسجّل ولا تفعل',
    live: 'تشغيل — تفعل',
};

export const RULE_MODE_LABEL: Record<string, string> = {
    observe: 'مراقبة',
    live: 'تشغيل',
};

export const RUN_STATUS_LABEL: Record<string, string> = {
    pending: 'معلَّق',
    executing: 'قيد التنفيذ',
    executed: 'نُفّذ',
    observed: 'رُوقب',
    skipped: 'تُخطّي',
    failed: 'فشل',
    cancelled: 'أُلغي',
};

export const KIND_LABEL: Record<string, string> = {
    internal: 'داخلي',
    external: 'يبلغ العميل',
};

export const CHANNEL_LABEL: Record<string, string> = {
    internal: 'داخل النظام',
    email: 'البريد',
    whatsapp: 'واتساب',
};

export const DAY_LABEL: Record<number, string> = {
    0: 'الأحد',
    1: 'الاثنين',
    2: 'الثلاثاء',
    3: 'الأربعاء',
    4: 'الخميس',
    5: 'الجمعة',
    6: 'السبت',
};

export function runTone(status: string): string {
    switch (status) {
        case 'executed':
            return 'bg-[#ecfdf5] text-[#047857]';
        case 'observed':
            return 'bg-[#eff6ff] text-[#1d4ed8]';
        case 'pending':
        case 'executing':
            return 'bg-[#fffbeb] text-[#b45309]';
        case 'failed':
            return 'bg-[#fef2f2] text-[#b91c1c]';
        case 'cancelled':
        case 'skipped':
            return 'bg-[#f3f4f6] text-[#6b7280]';
        default:
            return 'bg-[#f5f5f4] text-[#57534e]';
    }
}

export function modeTone(mode: string): string {
    switch (mode) {
        case 'live':
            return 'bg-[#ecfdf5] text-[#047857]';
        case 'observe':
            return 'bg-[#eff6ff] text-[#1d4ed8]';
        default:
            return 'bg-[#f3f4f6] text-[#6b7280]';
    }
}

/** «قبل 3 أيام» · «في اليوم» · «بعد 7 أيام» */
export function offsetLabel(offset: number): string {
    if (offset === 0) return 'في اليوم';
    return offset < 0 ? `قبل :n أيام`.replace(':n', String(-offset)) : `بعد :n أيام`.replace(':n', String(offset));
}
