import { router } from '@inertiajs/react';
import { CalendarCheck, FileCheck2, FileSignature, Lock, PackageCheck, Receipt, Wallet, type LucideIcon } from 'lucide-react';

/**
 * مسارُ المشروع — سبعُ مراحل من عرض السعر إلى الإغلاق.
 *
 * ═══ الحقيقةُ في الخادم ═══
 *
 * أيُّ مرحلةٍ تمّت يحسبه `ProjectWorkflow` من مستندات المشروع (العرض
 * والنطاق الموقَّع والفواتير والفعاليّات والتسليم)، وأين يقف الفريق اختيارُه
 * اليدويّ — يُنقل إلى أيّ مرحلةٍ بلا شرط (`moveStage`). وهذا
 * الملفّ يرسم ما يصله ولا يقرّر شيئًا: اسمُ المرحلة وأيقونتُها، وتحويلُ
 * الحمولة إلى ما يرسمه الشريط. فبطاقةُ القائمة وصفحةُ المشروع تقرآن
 * المصدرَ نفسَه ولا تفترقان.
 */
export type StageKey = 'quote' | 'scope' | 'deposit' | 'execution' | 'delivery' | 'final' | 'closing';

/** done: تمّت · current: يُعمل عليها الآن · todo: بعد الحاليّة ولم تتمّ · failed: وقفت (ملغى) · skipped: قبل الحاليّة ولم تكتمل */
export type StageState = 'done' | 'current' | 'todo' | 'failed' | 'skipped';

export interface Stage {
    key: StageKey;
    label: string;
    icon: LucideIcon;
    state: StageState;
    /** أهي المرحلةُ التي اختارها الفريق؟ — تامّةً كانت أو ناقصة */
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
    /** كلُّ مرحلةٍ تُختار — لا مانعَ في المسار، والإذنُ يُسأل عند النقل */
    reachable?: boolean;
    note: string | null;
    /** ما ينقصها لتتمّ — للعرض، لا يمنع شيئًا */
    pending?: string[];
    /** تحذيرٌ يُقرأ ولا يمنع: تجاوزها المسارُ ولم تكتمل */
    warnings?: string[];
}

export interface Workflow {
    stages: WorkflowStage[];
    focus: StageKey;
    /** أوّلُ مرحلةٍ لم تتمّ — معلومة، لا تحكم «الآن» */
    frontier?: StageKey | null;
    /** جارتا المرحلة الحاليّة — اختصارٌ للنقل، لا الطريقُ الوحيد */
    next: StageKey | null;
    previous?: StageKey | null;
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

/** أتمّت المرحلةُ التي يقف عليها الفريق؟ — فيُقترح «تم — الانتقال» إلى التي بعدها، اقتراحًا لا شرطًا */
export function focusDone(workflow: Pick<Workflow, 'stages' | 'focus'>): boolean {
    return workflow.stages.find((s) => s.key === workflow.focus)?.state === 'done';
}

/** نقلُ المسار إلى أيّ مرحلة — للأمام أو للخلف أو قفزًا؛ والخادمُ يسأل عن الإذن وحدَه */
export function moveStage(projectId: number, stage: StageKey) {
    router.post(route('admin.projects.workflow.move', projectId), { stage }, { preserveScroll: true, preserveState: true });
}

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
