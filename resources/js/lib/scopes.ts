/**
 * حالاتُ نطاق العمل وألوانُها — في موضعٍ واحد للمشروع وشاشة النطاق.
 *
 * المخزَّنةُ الستّ (`ScopeOfWork::STATUSES`) والمشتقّةُ «فُتح» (`presentedState`):
 * مسودّة ← جاهز للإرسال ← أُرسل ← فُتح ← موقَّع، أو طُلب تعديله، أو حلّت محلَّه أحدث.
 */
export const SCOPE_STATE_LABEL: Record<string, string> = {
    draft: 'مسودة',
    ready: 'جاهز للإرسال',
    sent: 'أُرسل للتوقيع',
    opened: 'فتحه العميل',
    signed: 'موقَّع ومعتمد',
    declined: 'طلب العميل تعديله',
    superseded: 'نسخة سابقة',
};

export const scopeTone = (state: string): string =>
    state === 'signed'
        ? 'bg-[#ecfdf5] text-[#047857]'
        : state === 'declined'
          ? 'bg-[#fffbeb] text-[#b45309]'
          : state === 'superseded'
            ? 'bg-[#f4f4f5] text-[#71717a]'
            : state === 'draft'
              ? 'bg-[#f4f4f5] text-[#52525b]'
              : state === 'ready'
                ? 'bg-[#f5f3ff] text-[#6d28d9]'
                : 'bg-[#eff6ff] text-[#1d4ed8]';
