/**
 * حالاتُ حزمة التسليم كما تُعرض — مصدرٌ واحد للقائمة والشاشة.
 *
 * والمفاتيحُ من `DeliveryPackage::STATUSES` في الخادم؛ ما لا يُعرف يُعرض
 * بمفتاحه لا يُخفى.
 */
export const DELIVERY_STATE_LABEL: Record<string, string> = {
    draft: 'مسودة',
    ready: 'جاهزة للإرسال',
    sent: 'بانتظار العميل',
    accepted: 'قبلها العميل',
    declined: 'رفضها العميل',
    cancelled: 'ملغاة',
};

export function deliveryTone(state: string): string {
    switch (state) {
        case 'accepted':
            return 'bg-[#ecfdf5] text-[#047857]';
        case 'declined':
            return 'bg-[#fef2f2] text-[#b91c1c]';
        case 'sent':
            return 'bg-[#eff6ff] text-[#1d4ed8]';
        case 'ready':
            return 'bg-[#fffbeb] text-[#b45309]';
        case 'cancelled':
            return 'bg-[#f3f4f6] text-[#6b7280]';
        default:
            return 'bg-[#f5f5f4] text-[#57534e]';
    }
}

/** حجمُ ملفٍّ مقروءًا — `1.2 MB` لا `1258291` */
export function fileSize(bytes: number | null): string {
    if (bytes === null || bytes === undefined) return '';
    if (bytes >= 1048576) return `${(bytes / 1048576).toFixed(1)} MB`;
    if (bytes >= 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${bytes} B`;
}
