import { CalendarCheck, ClipboardList, FileSignature, Lock, PackageCheck, Receipt, type LucideIcon } from 'lucide-react';

/**
 * مسارُ المشروع — ستُّ مراحل من عرض السعر إلى الإقفال.
 *
 * مصدرٌ واحدٌ تقرأ منه بطاقةُ القائمة وصفحةُ المشروع: فلا تقول البطاقةُ
 * «التسليم» وتقول الصفحةُ «التنفيذ» عن المشروع نفسِه.
 *
 * والمشروعُ يولد «مؤكَّدًا» من قبول العرض (`Projects::fromQuotation`)، فلا
 * مرحلةَ تأكيدٍ تُنتظر: الثانيةُ التخطيط — مسؤولٌ وفريقٌ ومتطلّباتٌ مثبَّتة
 * ومهامّ — وتتمّ ببدء التنفيذ.
 */
export type StageKey = 'quote' | 'planning' | 'execution' | 'delivery' | 'billing' | 'closing';
export type StageState = 'done' | 'current' | 'todo' | 'failed';

export interface Stage {
    key: StageKey;
    label: string;
    icon: LucideIcon;
    state: StageState;
    /** ما يُكتب بجانب الاسم حين تقف المرحلة أو تُنتظر: «ملغى»، «مؤجَّل»، «أُرسل» */
    note?: string;
}

export const STAGES: { key: StageKey; label: string; icon: LucideIcon }[] = [
    { key: 'quote', label: 'عرض السعر', icon: FileSignature },
    { key: 'planning', label: 'التخطيط', icon: ClipboardList },
    { key: 'execution', label: 'التنفيذ', icon: CalendarCheck },
    { key: 'delivery', label: 'التسليم', icon: PackageCheck },
    { key: 'billing', label: 'الفوترة', icon: Receipt },
    { key: 'closing', label: 'الإغلاق', icon: Lock },
];

export interface StageInput {
    status: string;
    operation: string | null;
    delivery: string | null;
    billed: boolean;
}

/** أين بلغ المشروع: أوّلُ مرحلةٍ لم تتمّ هي الحاليّة، وما بعدها ينتظر */
export function projectStages(i: StageInput): Stage[] {
    const closed = i.status === 'closed';
    const done = [
        true,
        closed || i.status === 'in_progress' || i.operation === 'completed',
        closed || i.operation === 'completed',
        closed || i.delivery === 'accepted',
        closed || i.billed,
        closed,
    ];
    const at = done.findIndex((d) => !d);

    return STAGES.map((s, k) => ({
        ...s,
        state: done[k] ? 'done' : k === at ? (i.status === 'cancelled' ? 'failed' : 'current') : 'todo',
        note: k === at ? (i.status === 'cancelled' ? 'ملغى' : i.status === 'on_hold' ? 'مؤجَّل' : undefined) : undefined,
    }));
}

/** عرضٌ لم يصر مشروعًا: المرحلةُ الأولى وحدَها، أو علامةُ الرفض عليها */
export function quoteStages(status: string, note?: string): Stage[] {
    return STAGES.map((s, k) => ({
        ...s,
        state: k === 0 ? (status === 'rejected' ? 'failed' : 'current') : 'todo',
        note: k === 0 ? note : undefined,
    }));
}
