/**
 * حالاتُ الفعاليّات والمعدّات والفريق والحوادث وألوانُها — المرحلة 10.
 *
 * الأسماءُ العربيّة تأتي من الخادم (`labels`) فلا تُكرَّر هنا؛ وما هنا
 * الألوانُ والترتيبُ — ما تعرفه الشاشةُ وحدَها.
 */

export type Labels = Record<string, Record<string, string>>;

/** اسمٌ من قاموس الخادم — أو المفتاحُ نفسُه إن غاب */
export const label = (labels: Labels, group: string, key: string | null | undefined): string =>
    key ? (labels[group]?.[key] ?? key) : '—';

export const operationTone = (status: string): string =>
    status === 'live'
        ? 'bg-[#ecfdf5] text-[#047857]'
        : status === 'cancelled'
          ? 'bg-[#fef2f2] text-[#b91c1c]'
          : status === 'completed'
            ? 'bg-[#f4f4f5] text-[#71717a]'
            : ['ready', 'setup', 'teardown'].includes(status)
              ? 'bg-[#eff6ff] text-[#1d4ed8]'
              : status === 'preparing'
                ? 'bg-[#fffbeb] text-[#b45309]'
                : 'bg-[#f4f4f5] text-[#71717a]';

export const assetTone = (status: string): string =>
    status === 'available'
        ? 'bg-[#ecfdf5] text-[#047857]'
        : status === 'deployed'
          ? 'bg-[#eff6ff] text-[#1d4ed8]'
          : status === 'reserved'
            ? 'bg-[#fffbeb] text-[#b45309]'
            : ['damaged', 'lost'].includes(status)
              ? 'bg-[#fef2f2] text-[#b91c1c]'
              : 'bg-[#f4f4f5] text-[#71717a]';

export const reservationTone = (status: string): string =>
    status === 'checked_out'
        ? 'bg-[#eff6ff] text-[#1d4ed8]'
        : status === 'returned'
          ? 'bg-[#ecfdf5] text-[#047857]'
          : status === 'cancelled'
            ? 'bg-[#f4f4f5] text-[#71717a]'
            : 'bg-[#fffbeb] text-[#b45309]';

export const RESERVATION_LABEL: Record<string, string> = {
    reserved: 'محجوزة',
    checked_out: 'في الميدان',
    returned: 'رُدّت',
    cancelled: 'ملغى',
};

export const crewTone = (status: string): string =>
    status === 'checked_in'
        ? 'bg-[#ecfdf5] text-[#047857]'
        : status === 'checked_out'
          ? 'bg-[#eff6ff] text-[#1d4ed8]'
          : status === 'no_show'
            ? 'bg-[#fef2f2] text-[#b91c1c]'
            : status === 'cancelled'
              ? 'bg-[#f4f4f5] text-[#71717a]'
              : status === 'confirmed'
                ? 'bg-[#eff6ff] text-[#1d4ed8]'
                : 'bg-[#fffbeb] text-[#b45309]';

export const severityTone = (severity: string): string =>
    severity === 'critical' || severity === 'danger'
        ? 'bg-[#fef2f2] text-[#b91c1c]'
        : severity === 'high' || severity === 'warning'
          ? 'bg-[#fffbeb] text-[#b45309]'
          : severity === 'medium'
            ? 'bg-[#eff6ff] text-[#1d4ed8]'
            : 'bg-[#f4f4f5] text-[#71717a]';

export const incidentTone = (status: string): string =>
    status === 'open'
        ? 'bg-[#fef2f2] text-[#b91c1c]'
        : status === 'investigating'
          ? 'bg-[#fffbeb] text-[#b45309]'
          : 'bg-[#ecfdf5] text-[#047857]';

export const checklistTone = (status: string): string =>
    status === 'done'
        ? 'bg-[#ecfdf5] text-[#047857]'
        : status === 'blocked'
          ? 'bg-[#fef2f2] text-[#b91c1c]'
          : status === 'waived'
            ? 'bg-[#fffbeb] text-[#b45309]'
            : 'bg-[#f4f4f5] text-[#71717a]';

export const CHECKLIST_STATUS_LABEL: Record<string, string> = {
    pending: 'لم يُنجَز',
    done: 'أُنجز',
    blocked: 'معطَّل',
    waived: 'أُعفي',
};

export const scheduleTone = (status: string): string =>
    status === 'completed'
        ? 'bg-[#ecfdf5] text-[#047857]'
        : status === 'in_progress'
          ? 'bg-[#eff6ff] text-[#1d4ed8]'
          : status === 'blocked'
            ? 'bg-[#fef2f2] text-[#b91c1c]'
            : status === 'skipped'
              ? 'bg-[#f4f4f5] text-[#71717a]'
              : status === 'ready'
                ? 'bg-[#fffbeb] text-[#b45309]'
                : 'bg-[#f4f4f5] text-[#71717a]';

/** الحالاتُ التي تُعرض أزرارُ نقلها في الشاشة — بترتيب الدورة */
export const TRANSITION_ORDER = ['preparing', 'ready', 'setup', 'live', 'teardown', 'completed', 'planning', 'cancelled'];

/** الوقتُ فقط من «Y-m-d H:i» */
export const clock = (value: string | null | undefined): string => (value ? value.slice(11, 16) : '—');

/** اليومُ فقط */
export const day = (value: string | null | undefined): string => (value ? value.slice(0, 10) : '—');
