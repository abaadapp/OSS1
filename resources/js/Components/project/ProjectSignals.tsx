import { AlertTriangle } from 'lucide-react';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { StageKey } from '@/lib/projectStages';
import { cn } from '@/lib/utils';

/**
 * تنبيهٌ غيرُ حرِج — شارةٌ قصيرة فوق التبويبات تفتح تبويبَها.
 *
 * والحرِجُ (حارسُ النطاق، فوترةٌ فوق القيمة، خطأٌ ردّه الخادم) يُقال كاملًا
 * بالأحمر ولا يصير شارة. وهذه ما يُنتبه إليه ولا يوقف العمل.
 */
export interface Signal {
    key: string;
    label: string;
    n?: number;
    /** التبويبُ الذي يُفتح بالضغط */
    tab: string;
    /** ومرحلةٌ في «المسار» يُنزل إليها — للفاتورة المسودّة */
    stage?: StageKey;
    tone: 'danger' | 'warning';
}

/** ما تقرؤه الإشارات — كلُّه ممّا تحمله صفحةُ المشروع أصلًا، بلا رقمٍ جديد */
export interface SignalSource {
    open: boolean;
    baselined: boolean;
    requirements: { active: boolean }[];
    tasks: { status: string; due_at: string | null }[];
    inventory_forecast?: { shortages: number } | null;
    billing: { deposit: { invoices: { status: string }[] }; final: { invoices: { status: string }[] } };
    invoices: { status: string }[] | null;
    operations: { status: string; ready: boolean }[] | null;
    delivery: { readiness: { ready: boolean; blockers: string[] }; packages: unknown[] };
    performance: { contribution: number } | null;
    /** اليومُ بصيغة Y-m-d — يُمرَّر فيُختبر بلا ساعة */
    today: string;
}

/** حالاتُ الفعاليّة التي لا يُسأل فيها عن الجاهزيّة — جرت أو انتهت أو جهزت */
const SETTLED = ['completed', 'cancelled', 'ready', 'live', 'teardown'];

/** فعاليّةٌ في التخطيط لم تجهز بعد — الشرطُ الذي كان يُضيء تبويبَ العمليات */
export const isUnready = (o: { status: string; ready: boolean }) => !o.ready && !SETTLED.includes(o.status);

/** المهمّةُ المتأخّرة: مفتوحةٌ فات موعدُها — كما يعدّها الخادم على بطاقة القائمة */
export const isLate = (task: { status: string; due_at: string | null }, today: string) => task.status !== 'done' && !!task.due_at && task.due_at < today;

/**
 * الإشاراتُ بترتيب أهمّيّتها — والشروطُ نفسُها التي كانت تُضيء التبويبات.
 *
 * لا حكمَ يُضاف هنا: نقصُ المخزون من `InventoryForecast`، والجاهزيّةُ من
 * `DeliveryReadiness`، والمساهمةُ من `ProjectProfitability`. والشاشةُ تعدّ
 * ما وصلها فقط.
 */
export function projectSignals(s: SignalSource): Signal[] {
    const out: Signal[] = [];
    const late = s.tasks.filter((x) => isLate(x, s.today)).length;
    const deposits = s.billing.deposit.invoices.filter((i) => i.status === 'مسودة').length;
    const drafts = (s.invoices ?? [...s.billing.deposit.invoices, ...s.billing.final.invoices]).filter((i) => i.status === 'مسودة').length;
    const notReady = (s.operations ?? []).filter(isUnready).length;
    const shortages = s.inventory_forecast?.shortages ?? 0;

    if (shortages > 0) out.push({ key: 'inventory', label: 'نقص مخزون', n: shortages, tab: 'inventory', tone: 'danger' });
    if (late > 0) out.push({ key: 'tasks', label: 'مهام متأخرة', n: late, tab: 'tasks', tone: 'danger' });
    if (s.performance && s.performance.contribution < 0) out.push({ key: 'performance', label: 'مساهمة سالبة', tab: 'performance', tone: 'danger' });
    if (drafts > 0) out.push({ key: 'drafts', label: 'فاتورة مسودة', n: drafts, tab: 'process', stage: deposits > 0 ? 'deposit' : 'final', tone: 'warning' });
    if (s.open && !s.baselined) out.push({ key: 'requirements', label: 'متطلّبات غير مثبَّتة', n: s.requirements.filter((r) => r.active).length, tab: 'requirements', tone: 'warning' });
    if (notReady > 0) out.push({ key: 'operations', label: 'فعاليّة غير جاهزة', n: notReady, tab: 'operations', tone: 'warning' });
    if (s.open && !s.delivery.readiness.ready && s.delivery.packages.length > 0) {
        out.push({ key: 'delivery', label: 'موانع التسليم', n: s.delivery.readiness.blockers.length, tab: 'delivery', tone: 'warning' });
    }

    return out;
}

/**
 * الشاراتُ نفسُها — «نقص مخزون ٢»، «مهام متأخرة ٣» — وكلٌّ زرٌّ يفتح تبويبَه.
 *
 * وما لا إشارةَ فيه لا يُرسم له سطرٌ فارغ.
 */
export function SignalBadges({ signals, onOpen, className }: { signals: Signal[]; onOpen: (signal: Signal) => void; className?: string }) {
    const t = useTranslate();
    if (signals.length === 0) return null;

    return (
        <ul aria-label={t('تنبيهات المشروع')} className={cn('flex flex-wrap items-center gap-1.5', className)}>
            {signals.map((s) => (
                <li key={s.key}>
                    <button
                        type="button"
                        onClick={() => onOpen(s)}
                        className={cn(
                            'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[12px] font-medium transition-colors pointer-coarse:py-1.5',
                            s.tone === 'danger' ? 'bg-[#fef2f2] text-[#b91c1c] hover:bg-[#fee2e2]' : 'bg-[#fffbeb] text-[#b45309] hover:bg-[#fef3c7]',
                        )}
                    >
                        <AlertTriangle aria-hidden className="size-3 shrink-0" />
                        {t(s.label)}
                        {!!s.n && (
                            <>
                                {' '}
                                <span className="tabular-nums">{number(s.n)}</span>
                            </>
                        )}
                    </button>
                </li>
            ))}
        </ul>
    );
}
