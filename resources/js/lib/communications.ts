/**
 * المراسلات كما تُعرض — القنواتُ والحالاتُ ونبراتُها. مصدرٌ واحد للسجلّ
 * والبطاقة والتبويب.
 *
 * والمفاتيحُ من `Communication::STATUSES` و`CHANNELS` في الخادم؛ ما لا
 * يُعرف يُعرض بمفتاحه لا يُخفى.
 */
export const CHANNEL_LABEL: Record<string, string> = {
    email: 'البريد',
    whatsapp: 'واتساب',
};

export const STATUS_LABEL: Record<string, string> = {
    queued: 'في الانتظار',
    sent: 'أُرسلت',
    delivered: 'وصلت',
    read: 'قُرئت',
    failed: 'فشلت',
    bounced: 'ارتدّت',
};

export const TEMPLATE_STATUS_LABEL: Record<string, string> = {
    active: 'فعّال',
    inactive: 'موقوف',
    pending: 'بانتظار اعتماد المزوّد',
    rejected: 'رفضه المزوّد',
};

export const PURPOSE_LABEL: Record<string, string> = {
    quotation: 'عروض الأسعار',
    change_order: 'أوامر التغيير',
    delivery: 'التسليم',
    invoice: 'الفواتير',
    project: 'المشاريع',
    event: 'الفعاليات',
    notice: 'أخبار داخلية',
};

export const SUBJECT_LABEL: Record<string, string> = {
    quotation: 'عرض سعر',
    project: 'مشروع',
    customer_invoice: 'فاتورة',
    delivery_package: 'حزمة تسليم',
    change_order: 'أمر تغيير',
    customer: 'عميل',
};

export function statusTone(status: string): string {
    switch (status) {
        case 'read':
            return 'bg-[#ecfdf5] text-[#047857]';
        case 'delivered':
            return 'bg-[#f0fdf4] text-[#15803d]';
        case 'sent':
            return 'bg-[#eff6ff] text-[#1d4ed8]';
        case 'queued':
            return 'bg-[#fffbeb] text-[#b45309]';
        case 'failed':
        case 'bounced':
            return 'bg-[#fef2f2] text-[#b91c1c]';
        default:
            return 'bg-[#f5f5f4] text-[#57534e]';
    }
}

export function templateStatusTone(status: string): string {
    switch (status) {
        case 'active':
            return 'bg-[#ecfdf5] text-[#047857]';
        case 'pending':
            return 'bg-[#fffbeb] text-[#b45309]';
        case 'rejected':
            return 'bg-[#fef2f2] text-[#b91c1c]';
        default:
            return 'bg-[#f3f4f6] text-[#6b7280]';
    }
}

/** الحدثُ الواحد في خطّ الزمن — أثرٌ أو رسالة */
export interface TimelineRow {
    at: string | null;
    kind: 'activity' | 'communication';
    icon: string;
    color: string;
    title: string;
    detail?: string | null;
    by?: string | null;
    status?: string;
    channel?: string;
    recipient?: string;
    communication_id?: number;
    internal?: boolean;
    subject_type?: string | null;
    subject_id?: number | null;
}

/** صفُّ رسالةٍ كما يعيده الخادم في السجلّ والبطاقة */
export interface CommunicationRow {
    id: number;
    channel: string;
    status: string;
    recipient: string;
    recipient_name: string | null;
    title: string;
    template_key: string;
    template_name: string;
    internal: boolean;
    customer: string | null;
    customer_id: number | null;
    project: string | null;
    project_id: number | null;
    subject_type: string | null;
    subject_id: number | null;
    subject_label: string | null;
    initiated_by: string | null;
    retry_of_id: number | null;
    failure_reason: string | null;
    created_at: string | null;
    sent_at: string | null;
    delivered_at: string | null;
    read_at: string | null;
    failed_at: string | null;
}

/** `2026-09-19T10:35:00+04:00` → `2026-09-19 10:35` */
export function clock(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    const p = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

/** اليومُ وحده — لعناوين الأيّام في خطّ الزمن */
export function day(iso: string | null | undefined): string {
    return clock(iso).slice(0, 10);
}
