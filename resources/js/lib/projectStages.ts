import { CalendarCheck, FileCheck2, FileSignature, Lock, PackageCheck, Receipt, Wallet, type LucideIcon } from 'lucide-react';

/**
 * مسارُ المشروع — سبعُ مراحل من عرض السعر إلى الإغلاق.
 *
 * ═══ الحقيقةُ في الخادم ═══
 *
 * أيُّ مرحلةٍ تمّت وأين يقف الفريق يحسبه `ProjectWorkflow` من مستندات
 * المشروع (العرض والنطاق الموقَّع والفواتير والفعاليّات والتسليم). وهذا
 * الملفّ يرسم ما يصله ولا يقرّر شيئًا: اسمُ المرحلة وأيقونتُها، وتحويلُ
 * الحمولة إلى ما يرسمه الشريط. فبطاقةُ القائمة وصفحةُ المشروع تقرآن
 * المصدرَ نفسَه ولا تفترقان.
 */
export type StageKey = 'quote' | 'scope' | 'deposit' | 'execution' | 'delivery' | 'final' | 'closing';

/** done: تمّت · current: يُعمل عليها الآن · todo: لم تُفتح · failed: وقفت (ملغى) · skipped: لم تُسجَّل قبل الإقفال */
export type StageState = 'done' | 'current' | 'todo' | 'failed' | 'skipped';

export interface Stage {
    key: StageKey;
    label: string;
    icon: LucideIcon;
    state: StageState;
    /** أهي المرحلةُ التي يعمل عليها الفريق؟ — وقد تكون تامّةً تنتظر «تم — الانتقال» */
    focus?: boolean;
    /** ما يُكتب بجانب الاسم: «ملغى»، «مؤجَّل»، «فُتح»، «بلا دفعة مقدمة» */
    note?: string;
}

/** المرحلةُ كما يرسلها الخادم */
export interface WorkflowStage {
    key: StageKey;
    state: StageState;
    done?: boolean;
    focus: boolean;
    reachable?: boolean;
    note: string | null;
    /** ما ينقصها لتتمّ */
    pending?: string[];
    /** ما يمنع الوصولَ إليها */
    blocked_by?: string[];
}

export interface Workflow {
    stages: WorkflowStage[];
    focus: StageKey;
    frontier?: StageKey | null;
    next: StageKey | null;
    previous?: StageKey | null;
    can_advance: boolean;
    can_back?: boolean;
    /** في البطاقة: ما ينقص المرحلةَ الحاليّة */
    pending?: string[];
}

export const STAGES: { key: StageKey; label: string; icon: LucideIcon }[] = [
    { key: 'quote', label: 'عرض السعر', icon: FileSignature },
    { key: 'scope', label: 'نطاق العمل', icon: FileCheck2 },
    { key: 'deposit', label: 'الدفعة المقدمة', icon: Wallet },
    { key: 'execution', label: 'التنفيذ', icon: CalendarCheck },
    { key: 'delivery', label: 'التسليم', icon: PackageCheck },
    { key: 'final', label: 'الفاتورة النهائية', icon: Receipt },
    { key: 'closing', label: 'الإغلاق', icon: Lock },
];

export const STAGE_LABEL: Record<StageKey, string> = Object.fromEntries(STAGES.map((s) => [s.key, s.label])) as Record<StageKey, string>;

/** حمولةُ الخادم ← ما يرسمه الشريط — بلا حكمٍ واحدٍ يُضاف هنا */
export function toStages(workflow: Pick<Workflow, 'stages'>): Stage[] {
    return STAGES.map((s) => {
        const w = workflow.stages.find((x) => x.key === s.key);

        return { ...s, state: w?.state ?? 'todo', focus: w?.focus ?? false, note: w?.note ?? undefined };
    });
}

/** عرضٌ لم يصر مشروعًا: المرحلةُ الأولى وحدَها، أو علامةُ الرفض عليها */
export function quoteStages(status: string, note?: string): Stage[] {
    return STAGES.map((s, k) => ({
        ...s,
        state: k === 0 ? (status === 'rejected' ? 'failed' : 'current') : 'todo',
        focus: k === 0,
        note: k === 0 ? note : undefined,
    }));
}
