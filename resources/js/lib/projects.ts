/**
 * أسماءُ حالات المشروع وأمر التغيير وألوانُها — في موضعٍ واحد لا في كلّ شاشة.
 *
 * والمفاتيحُ لاتينيّةٌ في القاعدة وعربيّةٌ هنا — للسبب المكتوب في
 * `lib/quotations.ts`: تُقارَن في الكود وتُرشَّح في الروابط.
 */

export const PROJECT_STATUS_LABEL: Record<string, string> = {
    draft: 'مسودة',
    confirmed: 'مؤكَّد',
    in_progress: 'قيد التنفيذ',
    on_hold: 'مؤجَّل',
    closed: 'مُقفَل',
    cancelled: 'ملغى',
};

export const projectTone = (status: string): string =>
    status === 'cancelled'
        ? 'bg-[#fef2f2] text-[#b91c1c]'
        : status === 'closed'
          ? 'bg-[#f4f4f5] text-[#71717a]'
          : status === 'on_hold'
            ? 'bg-[#fffbeb] text-[#b45309]'
            : status === 'in_progress'
              ? 'bg-[#ecfdf5] text-[#047857]'
              : 'bg-[#eff6ff] text-[#1d4ed8]';

/** الحالُ كما تُعرض — المخزَّنةُ الستُّ والمشتقّةُ الثلاث، كالعرض */
export const CO_STATE_LABEL: Record<string, string> = {
    draft: 'مسودة',
    pending_approval: 'بانتظار الاعتماد',
    sent: 'أُرسل',
    accepted: 'مقبول',
    declined: 'مرفوض',
    cancelled: 'ملغى',
    unopened: 'لم يُفتح بعد',
    awaiting_client: 'بانتظار العميل',
    expired: 'منتهي الصلاحية',
};

export const coTone = (state: string): string =>
    state === 'expired' || state === 'declined'
        ? 'bg-[#fef2f2] text-[#b91c1c]'
        : state === 'accepted'
          ? 'bg-[#ecfdf5] text-[#047857]'
          : state === 'pending_approval'
            ? 'bg-[#fffbeb] text-[#b45309]'
            : state === 'cancelled'
              ? 'bg-[#f4f4f5] text-[#71717a]'
              : 'bg-[#eff6ff] text-[#1d4ed8]';

export const PARTY_LABEL: Record<string, string> = {
    customer: 'العميل',
    company: 'الشركة',
};
