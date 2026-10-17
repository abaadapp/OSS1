import { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, CalendarCheck, Check, CheckCircle2, FileText, Lock, MoreHorizontal, PackageCheck, Pause, Pencil, Play, Plus, Receipt, ShieldCheck, Slash, Smartphone, Trash2, Unlock, Wallet } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import Tabs from '@/Components/Tabs';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { CO_STATE_LABEL, coTone, PARTY_LABEL, PROJECT_STATUS_LABEL, projectTone } from '@/lib/projects';
import { DELIVERY_STATE_LABEL, deliveryTone } from '@/lib/deliveries';
import { operationTone } from '@/lib/operations';
import CompletenessBadge from '@/Components/management/CompletenessBadge';
import { marginTone, pct, type CompletenessInfo } from '@/lib/management';
import CommunicationsTab from './partials/CommunicationsTab';
import PurchasesTab, { type ProjectPurchases } from './partials/PurchasesTab';
import ContactPicker from '@/Components/ContactPicker';
import type { CustomerContact } from '@/lib/contacts';
import ProcessTab, { type Billing, type BillingInvoice, type QuotationInfo, type ScopeInfo } from './partials/ProcessTab';
import ProjectStepper from '@/Components/ProjectStepper';
import TabHead from '@/Components/project/TabHead';
import MoreDetails from '@/Components/project/MoreDetails';
import { isLate, isUnready, projectSignals, SignalBadges, type Signal } from '@/Components/project/ProjectSignals';
import { STAGE_LABEL, toStages, type StageKey, type Workflow } from '@/lib/projectStages';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/Components/ui/dropdown-menu';
import type { PageProps } from '@/types';
import ProjectInventoryForecast, { type ProjectForecast } from '@/Components/InventoryForecast';

interface Project {
    id: number;
    code: string;
    name: string;
    customer: string;
    status: string;
    starts_at: string | null;
    ends_at: string | null;
    /** والمالُ لمن يملك رؤيته — null لمن لا: يُشكَّل في الخادم لا يُخفى هنا */
    approved_value: number | null;
    current_value: number | null;
    quoted_value: number | null;
    owner: string | null;
    owner_id: number | null;
    baselined: boolean;
    event_type: string | null;
    location: string | null;
    contact_person: string | null;
    contact_phone: string | null;
    contact_email: string | null;
    customer_contact_id?: number | null;
    notes: string | null;
    internal_notes: string | null;
    requirements_baselined_at: string | null;
    requirements_baselined_by: string | null;
    closed_at: string | null;
    cancellation_reason: string | null;
    cancellation_policy: Record<string, unknown> | null;
    cancellation_fee_invoice: { id: number; number: string | null; total: number } | null;
    financials_frozen: Record<string, number> | null;
    quotation_id: number | null;
    scope_broken: string[];
}

/** التسليم — المرحلة 5: الحزمُ وجاهزيّةُ الإقفال من جهتها */
interface Delivery {
    readiness: { ready: boolean; blockers: string[]; accepted_count: number; awaiting_count: number; unresolved_decline_count: number; last_accepted_at: string | null };
    packages: { id: number; label: string; title: string; status: string; sent_at: string | null; decided_at: string | null }[];
}

/** حكمُ سياسة الإلغاء لو أُلغي الآن */
interface Cancellation {
    free: boolean;
    fee_amount: number;
    fee_percent: number;
    required_days: number;
    available_days: number | null;
    message: string;
}

interface Requirement {
    id: number;
    title: string;
    details: string | null;
    project_item_id: number | null;
    item: string | null;
    baseline: boolean;
    active: boolean;
    added_by: { id: number; label: string; status: string } | null;
    removed_by: { id: number; label: string; status: string } | null;
}

interface ScopeItem {
    id: number;
    description: string;
    unit: string | null;
    quantity: number;
    unit_price: number | null;
    line_total: number | null;
    delivered_quantity: number;
    status: string;
    origin: 'quotation' | 'change_order';
}

interface Scope {
    original: {
        quotation: {
            id: number;
            label: string;
            total: number;
            content_hash: string | null;
            decided_at: string | null;
            decided_by_name: string | null;
            event_starts_at: string | null;
            evidence: { signer_name: string; decided_at: string | null; verified: boolean } | null;
        } | null;
        items: { id: number; description: string; unit: string | null; quantity: number; unit_price: number | null; line_total: number | null }[];
        requirements: { id: number; title: string }[];
        approved_value: number | null;
        baselined_at: string | null;
    };
    changes: {
        id: number;
        number: string | null;
        title: string;
        requested_by_party: string;
        total_delta: number | null;
        new_starts_at: string | null;
        decided_at: string | null;
        decided_by_name: string | null;
        items: { description: string; quantity: number }[];
        added: { title: string }[];
        removed: { title: string }[];
    }[];
    current: { items: ScopeItem[]; requirements: { id: number; title: string }[]; starts_at: string | null; ends_at: string | null; current_value: number | null };
}

/** التشغيل — المرحلة 10: فعاليّاتُ المشروع بجاهزيّتها، ملخّصًا لا شاشةً */
interface OperationRow {
    id: number;
    code: string;
    name: string;
    status: string;
    status_label: string;
    venue_name: string | null;
    event_starts_at: string | null;
    event_ends_at: string | null;
    lead: string | null;
    ready: boolean;
    blockers: string[];
    warnings: number;
    conflicts: number;
    critical_conflicts: number;
    crew: { expected: number; checked_in: number; missing: number };
    equipment: { required: number; assigned: number; reserved: number; outstanding: number };
    checklist: { total: number; done: number; required_pending: number };
    incidents: { open: number; total: number };
    url: string;
    onsite_url: string;
}

interface Member { id: number; user_id: number; name: string | null; role: string | null }
interface Task { id: number; title: string; description: string | null; assignee_id: number | null; assignee: string | null; event_operation_id?: number | null; due_at: string | null; status: string; priority: string; completed_at: string | null }
interface TaskOperation { id: number; code: string; name: string }
interface ChangeOrderRow { id: number; label: string; sequence: number; title: string; status: string; state: string; total_delta: number | null; new_starts_at: string | null; requested_by_party: string; sent_at: string | null; decided_at: string | null }

interface Props {
    project: Project;
    scope: Scope;
    requirements: Requirement[];
    members: Member[];
    tasks: Task[];
    change_orders: ChangeOrderRow[];
    financials: Record<string, number | boolean> | null;
    delivery: Delivery;
    cancellation: Cancellation | null;
    operations: OperationRow[] | null;
    /** توقّعُ المخزون — قراءةٌ لا خصم، لمن يفتح المخزون (`InventoryForecast`) */
    inventory_forecast?: ProjectForecast | null;
    /** فعاليّاتُ المشروع لنموذج المهمّة — تقف المهمّةُ على إحداها فتصل صاحبَها مع أخواتها */
    task_operations?: TaskOperation[];
    /* الأداء — المرحلة 11: الربحيّةُ بصيغتها الواحدة واكتمالُها وخطُّ زمنِها؛ لمن يقرأ تحليلات المشاريع */
    performance: Performance | null;
    billing: Billing;
    invoices: BillingInvoice[] | null;
    close_blockers: string[];
    /* المسار — من `ProjectWorkflow` في الخادم، ومعه ما تحتاجه مراحلُه */
    workflow: Workflow;
    quotation: QuotationInfo | null;
    scope_of_work: ScopeInfo;
    channels: { email: boolean; whatsapp: boolean; scope_whatsapp_template: boolean };
    users: { id: number; name: string }[];
    /* المرحلة 14: أشخاصُ اتصال الجهة، ومشترياتُ المشروع لمن يفتح المشتريات */
    contacts?: CustomerContact[];
    purchases?: ProjectPurchases | null;
    may: { manage: boolean; budget: boolean; close: boolean; reopen: boolean; change_order: boolean; override_policy: boolean; delivery: boolean; operations: boolean; operations_manage: boolean; performance: boolean; invoice: boolean; commercial: boolean; purchases?: boolean };
}

interface Performance {
    baseline: number;
    change_orders_delta: number;
    contracted: number;
    invoiced: number;
    credited: number;
    net_invoiced: number;
    revenue: number;
    collected: number;
    receivable: number;
    direct_costs: { supplier_invoices: number; expenses: number; stock_consumed: number; total: number };
    committed: number;
    expected_cost: number;
    contribution: number;
    margin_percent: number | null;
    forecast_margin: number;
    forecast_margin_percent: number | null;
    invoiced_above_approved: boolean;
    completeness: CompletenessInfo;
    basis: string;
    timeline: { at: string; kind: string; label: string; amount: number; direction: 'in' | 'out' | 'cash'; url: string | null }[];
}

/** المبلغُ كما يُرسم — وشرطةٌ لما لم يُرسَل لمن لا يملك رؤيته */
type Money = (v: number | null | undefined) => string;

/**
 * المشروعُ — نطاقُه ومتطلّباتُه وفريقُه ومهامُّه وأوامرُ تغييره ومالُه.
 *
 * ═══ رأسٌ هادئ ═══
 *
 * الرأسُ يقول الأساسيَّ وحده: الاسمُ والرمزُ والعميلُ والحالُ والموعدُ
 * والقيمةُ الحاليّة، وفعلٌ أساسيٌّ واحد هو خطوةُ المرحلة الحاليّة — والبقيّةُ
 * في قائمة «⋯». وتحته شريطُ المراحل مضغوطًا، وشاراتٌ قصيرةٌ لما يُنتبه إليه
 * تفتح تبويبَها. والحرِجُ وحده يُقال كاملًا بالأحمر. وما سوى ذلك من تفصيلٍ
 * في «البيانات» و«المسار».
 *
 * ═══ ثلاثةُ أسئلةٍ يجيبها تبويبُ النطاق ═══
 *
 * ما وافق عليه العميل أصلًا (العرضُ ببرهانه)، وما تغيّر بعده (أوامرُ التغيير
 * المقبولة)، وما يجب على الفريق تنفيذَه الآن (الحاليّ). والقيمةُ الحاليّة
 * تُشتقّ في الخادم — انظر `ProjectScope`.
 */
export default function ProjectShow({ project: p, scope, requirements, members, tasks, change_orders, financials, delivery, cancellation, operations, performance, billing, invoices, close_blockers, workflow, quotation, scope_of_work, channels, users, may, contacts = [], purchases = null, inventory_forecast = null, task_operations = [] }: Props) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const { errors } = usePage().props as { errors?: Record<string, string> };
    /* ومبلغٌ لم يُرسَل (لمن لا يملك رؤيته) يُرسَم شرطةً لا صفرًا */
    const m = (v: number | null | undefined) => (v === null || v === undefined ? '—' : money(v, context!.currency));
    const open = p.status === 'confirmed' || p.status === 'in_progress' || p.status === 'on_hold';

    /*
     * التبويبُ من الرابط: «تجهيز التسليم» على بطاقة المشروع يفتح التسليمَ لا
     * النظرةَ العامّة. والتنقّلُ بين التبويبات يكتبه في الرابط، فالتحديثُ
     * والنسخُ يعودان إليه.
     */
    const [tab, setTabState] = useState(() => (typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('tab')) ?? 'process');
    const [focus, setFocus] = useState<StageKey | null>(null);
    const setTab = (key: string) => {
        setTabState(key);
        const url = new URL(window.location.href);
        if (key === 'process') url.searchParams.delete('tab');
        else url.searchParams.set('tab', key);
        window.history.replaceState(window.history.state, '', url.toString());
    };
    const [cancelling, setCancelling] = useState(false);
    const [reason, setReason] = useState('');
    const [waiveReason, setWaiveReason] = useState('');
    const [waiving, setWaiving] = useState(false);
    const [baselining, setBaselining] = useState(false);

    const post = (name: string, data: Record<string, string> = {}) =>
        router.post(route(name, p.id), data, { preserveScroll: true });

    /*
     * والمراحلُ من مصدرها الواحد في الخادم (`ProjectWorkflow`) — كما على بطاقة
     * القائمة. الشاشةُ ترسمها ولا تحسبها.
     */
    const stages = toStages(workflow);
    const at = workflow.focus;
    const focusStage = workflow.stages.find((s) => s.key === at);
    const liveOp = operations?.find((o) => !['completed', 'cancelled'].includes(o.status)) ?? null;
    const pick = (key: StageKey) => {
        setTab('process');
        setFocus(null);
        window.setTimeout(() => setFocus(key), 0);
    };

    /*
     * وما يُنتبه إليه — شاراتٌ تفتح تبويبَها، ونقطةٌ حمراء على التبويب نفسِه.
     * والشروطُ هي التي كانت تُضيء التبويبات؛ لا رقمَ يُخترع هنا.
     */
    const today = new Date().toISOString().slice(0, 10);
    const signals = projectSignals({ open, baselined: p.baselined, requirements, tasks, inventory_forecast, billing, invoices, operations, delivery, performance, today });
    const flagged = (key: string) => signals.some((s) => s.tab === key);
    const openSignal = (s: Signal) => (s.stage ? pick(s.stage) : setTab(s.tab));

    /*
     * والعدّادُ حيث يفيد عددُ ما في التبويب قبل فتحه — والصفرُ لا يُكتب
     * (`Tabs`). والنقطةُ الحمراءُ لما يحتاج انتباهًا وحده: أمرُ شراءٍ ينتظر
     * الاستلام مجرى عملٍ لا مشكلة.
     */
    const tabs = [
        /* المسارُ أوّلًا: من عرض السعر إلى الإقفال، وكلُّ مرحلةٍ بزرّها */
        { key: 'process', label: 'المسار', alert: flagged('process') },
        { key: 'overview', label: 'البيانات' },
        { key: 'scope', label: 'النطاق' },
        { key: 'requirements', label: 'المتطلّبات', count: requirements.filter((r) => r.active).length, alert: flagged('requirements') },
        { key: 'team', label: 'الفريق', count: members.length },
        { key: 'tasks', label: 'المهامّ', count: tasks.filter((x) => x.status !== 'done').length, alert: flagged('tasks') },
        { key: 'change_orders', label: 'أوامر التغيير', count: change_orders.length },
        { key: 'delivery', label: 'التسليم', count: delivery.packages.length, alert: flagged('delivery') },
        /* المراسلات — المرحلة 6: ما قيل للعميل وما جرى، بترتيبه */
        { key: 'communications', label: 'المراسلات' },
        /* العمليات — المرحلة 10: التنفيذُ على الأرض؛ لمن يفتح القسم */
        ...(operations !== null ? [{ key: 'operations', label: 'العمليات', count: operations.filter((o) => !['completed', 'cancelled'].includes(o.status)).length, alert: flagged('operations') }] : []),
        /* المشتريات — المرحلة 14: تبويبٌ مساند لا مرحلة؛ أوامرُ الشراء الحقيقيّة على المشروع */
        ...(purchases ? [{ key: 'purchases', label: 'المشتريات', count: purchases.summary.orders }] : []),
        ...(inventory_forecast ? [{ key: 'inventory', label: 'المخزون', alert: flagged('inventory') }] : []),
        ...(may.budget ? [{ key: 'financials', label: 'المال' }] : []),
        /* الأداء — المرحلة 11 */
        ...(performance !== null ? [{ key: 'performance', label: 'الأداء', alert: flagged('performance') }] : []),
    ];
    /* وتبويبٌ في الرابط لا يملكه الناظر (المالُ مثلًا) يرجع إلى المسار */
    const current = tabs.some((x) => x.key === tab) ? tab : 'process';

    /*
     * والتبويبُ النشط يُرى في شريطه: شارةٌ تفتح «الأداء» على الجوّال تُبقيه
     * خارج الشريط الضيّق لولا هذا. والشريطُ وحده يمرّ أفقيًّا — لا الصفحة،
     * ولا رأسيًّا كما تفعل `scrollIntoView`. والفرقُ يُضاف إلى `scrollLeft`
     * فيصدق في الاتّجاهين.
     */
    const strip = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const bar = strip.current?.querySelector<HTMLElement>('[role="tablist"]');
        const active = bar?.querySelector<HTMLElement>('[aria-selected="true"]');
        if (!bar || !active) return;
        const b = bar.getBoundingClientRect();
        const a = active.getBoundingClientRect();
        if (a.left < b.left) bar.scrollLeft -= b.left - a.left + 16;
        else if (a.right > b.right) bar.scrollLeft += a.right - b.right + 16;
    }, [current]);

    /* الخطوةُ التالية — زرٌّ واحدٌ في الرأس: «تم — الانتقال» إن تمّت المرحلة، وإلّا ما تنتظره */
    const next: { label: string; params?: Record<string, string>; icon: React.ReactNode; run?: () => void; href?: string; routeName?: string } | null = (() => {
        if (!open) return null;
        if (workflow.can_advance && workflow.next && may.manage) {
            return { label: 'تم — الانتقال إلى :stage', params: { stage: t(STAGE_LABEL[workflow.next]) }, icon: <ArrowLeft className="ltr:rotate-180" />, run: () => post('admin.projects.workflow.advance') };
        }
        if (focusStage?.done) return null;
        if (at === 'scope') return { label: scope_of_work.latest ? 'متابعة نطاق العمل' : 'إعداد نطاق العمل', icon: <FileText />, routeName: 'admin.projects.scope.show', href: route('admin.projects.scope.show', p.id) };
        if (at === 'deposit' || at === 'final') return { label: STAGE_LABEL[at], icon: at === 'deposit' ? <Wallet /> : <Receipt />, run: () => pick(at) };
        if (at === 'execution' && operations !== null) {
            return liveOp ? { label: 'متابعة الفعاليّة', icon: <CalendarCheck />, href: liveOp.url } : { label: 'جدولة الفعاليّة', icon: <CalendarCheck />, run: () => pick('execution') };
        }
        if (at === 'delivery' && may.delivery) return { label: 'تجهيز التسليم', icon: <PackageCheck />, run: () => pick('delivery') };
        if (at === 'closing' && may.close) return close_blockers.length === 0 ? { label: 'إقفال المشروع', icon: <Lock />, run: () => post('admin.projects.close') } : { label: 'ما يمنع الإقفال', icon: <AlertTriangle />, run: () => pick('closing') };
        return null;
    })();

    /* وبقيّةُ الأفعال في قائمة «⋯» — لا صفَّ أزرارٍ يتنافس على العين، ولا قائمةَ فارغة */
    const actions: { key: string; label: string; icon: React.ReactNode; run: () => void; destructive?: boolean }[] = [
        ...(open && may.change_order ? [{ key: 'change_order', label: 'أمر تغيير', icon: <Plus />, run: () => router.visit(route('admin.changeOrders.create', p.id)) }] : []),
        ...(open && !p.baselined && may.manage ? [{ key: 'baseline', label: 'تثبيت المتطلّبات', icon: <Lock />, run: () => setBaselining(true) }] : []),
        ...((p.status === 'confirmed' || p.status === 'on_hold') && may.manage ? [{ key: 'start', label: 'بدء التنفيذ', icon: <Play />, run: () => post('admin.projects.transition', { status: 'in_progress' }) }] : []),
        ...(p.status === 'in_progress' && may.manage ? [{ key: 'hold', label: 'تأجيل', icon: <Pause />, run: () => post('admin.projects.transition', { status: 'on_hold' }) }] : []),
        ...(open && may.close ? [{ key: 'close', label: 'إقفال', icon: <Lock />, run: () => post('admin.projects.close') }] : []),
        ...(p.status === 'closed' && may.reopen ? [{ key: 'reopen', label: 'إعادة فتح', icon: <Unlock />, run: () => post('admin.projects.reopen') }] : []),
        ...(open && may.manage ? [{ key: 'cancel', label: 'إلغاء المشروع', icon: <Slash />, run: () => setCancelling(true), destructive: true }] : []),
    ];

    return (
        <AdminLayout title={p.code}>
            {/* ‏min-w-0: لا شيءَ هنا يمدّ الصفحة عرضًا — ما يطول يمرّ داخل شريطه (التبويبات، المراحل، الجداول) */}
            <div data-project-page className="min-w-0">
                <BackLink routeName="admin.projects.index" href={route('admin.projects.index')} label="المشاريع والمعارض" />

                <header className="mb-4 min-w-0">
                    {/* على الجوّال: الاسمُ بعرض الشاشة والأفعالُ تحته — لا عمودٌ ضيّقٌ بجانب الأزرار */}
                    <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between sm:gap-x-4">
                        <div className="min-w-0 flex-1">
                            <h1 className="break-words text-[20px] font-bold leading-snug tracking-tight text-[#111] sm:text-[22px]">{p.name}</h1>
                            <div data-project-meta className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] text-[#6b7280]">
                                <span className="font-mono text-[12.5px] text-[#3f3f46]" dir="ltr">{p.code}</span>
                                <Sep />
                                <span className="min-w-0 break-words">{p.customer}</span>
                                {p.starts_at && (
                                    <>
                                        <Sep />
                                        <span className="tabular-nums" dir="ltr">
                                            {p.starts_at}
                                            {p.ends_at && p.ends_at !== p.starts_at ? ` → ${p.ends_at}` : ''}
                                        </span>
                                    </>
                                )}
                                <span className={'rounded-full px-2 py-0.5 text-[12px] ' + projectTone(p.status)}>{t(PROJECT_STATUS_LABEL[p.status] ?? p.status)}</span>
                                {p.current_value !== null && <span className="text-[14px] font-semibold tabular-nums text-[#111]">{m(p.current_value)}</span>}
                            </div>
                        </div>

                        <div className="flex shrink-0 items-center gap-2">
                            {next &&
                                (next.href ? (
                                    <Button asChild>
                                        <SmartLink routeName={next.routeName ?? 'admin.operations.show'} href={next.href}>
                                            {next.icon}
                                            {t(next.label, next.params)}
                                        </SmartLink>
                                    </Button>
                                ) : (
                                    <Button onClick={next.run}>
                                        {t(next.label, next.params)}
                                        {next.icon}
                                    </Button>
                                ))}
                            {actions.length > 0 && (
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button variant="outline" size="icon" aria-label={t('خيارات')}>
                                            <MoreHorizontal />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        {actions.map((a) => (
                                            <DropdownMenuItem key={a.key} onSelect={a.run} destructive={a.destructive}>
                                                {a.icon}
                                                {t(a.label)}
                                            </DropdownMenuItem>
                                        ))}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                        </div>
                    </div>

                    <SignalBadges signals={signals} onOpen={openSignal} className="mt-3" />
                </header>

                {/* الحرِجُ وحده يُقال كاملًا: خطأٌ ردّه الخادم، أو رقمٌ افترق عن حقيقته */}
                {errors?.project && <Alert tone="danger">{errors.project}</Alert>}
                {errors?.workflow && <Alert tone="danger">{errors.workflow}</Alert>}
                {errors?.billing && <Alert tone="danger">{errors.billing}</Alert>}
                {errors?.scope && <Alert tone="danger">{errors.scope}</Alert>}
                {errors?.requirement && <Alert tone="danger">{errors.requirement}</Alert>}
                {errors?.task && <Alert tone="danger">{errors.task}</Alert>}
                {errors?.member && <Alert tone="danger">{errors.member}</Alert>}
                {/* والحارسُ يُقال بالأحمر لا يُخفى: كميّةٌ كُتبت من غير بابها */}
                {p.scope_broken.length > 0 && (
                    <Alert tone="danger">
                        {t('كميّةُ بندٍ لا توافق الأصلَ وأوامرَ التغيير المقبولة')}: {p.scope_broken.join(' · ')}
                    </Alert>
                )}
                {financials && financials.invoiced_above_approved === true && (
                    <Alert tone="danger">{t('المفوتَر على هذا المشروع فوق قيمته المعتمَدة الحاليّة — راجع الحاجة إلى إشعار دائن.')}</Alert>
                )}

                <ProjectStepper stages={stages} onPick={pick} className="mb-3" />

                {/*
                    الأسماءُ كما هي، والشريطُ أخفّ: حشوٌ أصغر ونصٌّ أصغر، ويمرّ أفقيًّا
                    داخل نفسه (`overflow-x-auto` في `Tabs`) — لا تمرّ الصفحةُ كلُّها.
                */}
                <div ref={strip} className="mb-4 min-w-0">
                    <Tabs tabs={tabs} current={current} onChange={setTab} className="[&>button]:gap-1.5 [&>button]:px-3 [&>button]:py-2.5 [&>button]:text-[13px]" />
                </div>

                {current === 'process' && (
                    <ProcessTab
                        p={p}
                        d={{
                            workflow,
                            quotation,
                            scope: scope_of_work,
                            requirements: { active: requirements.filter((r) => r.active).length },
                            members,
                            tasks,
                            change_orders,
                            operations,
                            delivery,
                            billing,
                            invoices,
                            close_blockers,
                            channels,
                            users,
                        }}
                        stages={stages}
                        may={may}
                        m={m}
                        focus={focus}
                        onTab={setTab}
                    />
                )}

                {current === 'overview' && <Overview p={p} users={users} may={may} scope={scope} contacts={contacts} m={m} />}
                {current === 'scope' && <ScopeTab scope={scope} m={m} />}
                {current === 'requirements' && <RequirementsTab p={p} requirements={requirements} items={scope.current.items} may={may} open={open} error={errors?.requirement} />}
                {current === 'team' && <TeamTab p={p} members={members} users={users} may={may} open={open} error={errors?.member} />}
                {current === 'tasks' && <TasksTab p={p} tasks={tasks} users={users} may={may} events={task_operations} today={today} error={errors?.task} />}
                {current === 'change_orders' && <ChangeOrdersTab p={p} rows={change_orders} m={m} may={may} open={open} />}
                {current === 'delivery' && <DeliveryTab p={p} delivery={delivery} may={may} open={open} />}
                {current === 'communications' && <CommunicationsTab projectId={p.id} open={open} />}
                {current === 'operations' && operations !== null && <OperationsTab p={p} rows={operations} may={may} open={open} />}
                {current === 'inventory' && inventory_forecast && <InventoryTab data={inventory_forecast} projectId={p.id} />}
                {current === 'purchases' && purchases && <PurchasesTab projectId={p.id} data={purchases} m={m} />}
                {current === 'financials' && financials && <FinancialsTab f={financials} m={m} frozen={p.financials_frozen} />}
                {current === 'performance' && performance !== null && <PerformanceTab perf={performance} m={m} />}
            </div>

            <Dialog open={baselining} onOpenChange={setBaselining}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('تثبيت المتطلّبات')}</DialogTitle>
                        <DialogDescription>
                            {t('بعد التثبيت لا تُعدَّل المتطلّبات ولا تُحذف — وما يتغيّر بعده يمرّ بأمر تغيير يقبله العميل. ولا يُعاد التثبيت.')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setBaselining(false)}>{t('تراجع')}</Button>
                        <Button onClick={() => { post('admin.projects.baseline'); setBaselining(false); }}>
                            <Lock />
                            {t('تثبيت')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={cancelling} onOpenChange={setCancelling}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('إلغاء المشروع')}</DialogTitle>
                        <DialogDescription>{t('تُراجَع مستنداته القائمة يدويًّا — الإلغاء لا يمحو فاتورة ولا سندًا.')}</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 px-5">
                        {/* وحكمُ السياسة يُقرأ قبل الزرّ: كم يُحتسب على العميل — أو لا شيء */}
                        {cancellation && (
                            <div className={'rounded-lg px-3 py-2 text-[13px] ' + (cancellation.free ? 'bg-[#ecfdf5] text-[#047857]' : 'bg-[#fef2f2] text-[#b91c1c]')}>
                                {cancellation.message}
                                {!cancellation.free && cancellation.fee_amount > 0 && (
                                    <div className="mt-1 font-bold">{t('رسم الإلغاء')}: {m(cancellation.fee_amount)} {t('— يُصدَر فاتورةً على العميل')}</div>
                                )}
                            </div>
                        )}
                        <Field label="سبب الإلغاء" required>
                            <Input value={reason} onChange={(e) => setReason(e.target.value)} />
                        </Field>
                        {cancellation && !cancellation.free && cancellation.fee_amount > 0 && may.override_policy && (
                            <>
                                <label className="flex items-center gap-2 text-[13px]">
                                    <input type="checkbox" checked={waiving} onChange={(e) => setWaiving(e.target.checked)} />
                                    {t('إعفاء العميل من الرسم — استثناءٌ من السياسة باسمي')}
                                </label>
                                {waiving && (
                                    <Field label="سبب الإعفاء" required>
                                        <Textarea rows={2} value={waiveReason} onChange={(e) => setWaiveReason(e.target.value)} />
                                    </Field>
                                )}
                            </>
                        )}
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCancelling(false)}>{t('تراجع')}</Button>
                        <Button
                            disabled={reason.trim() === '' || (waiving && waiveReason.trim() === '')}
                            onClick={() => {
                                post('admin.projects.transition', { status: 'cancelled', reason, ...(waiving ? { exception_reason: waiveReason } : {}) });
                                setCancelling(false);
                            }}
                        >
                            {t('إلغاء المشروع')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}

/* فاصلُ الرأس — وعلى الجوّال يلتفّ السطرُ فلا يبدأ سطرٌ بنقطةٍ معلّقة */
const Sep = () => <span aria-hidden className="hidden text-[#d4d4d8] sm:inline">·</span>;

function Alert({ tone, children }: { tone: 'danger' | 'warning'; children: React.ReactNode }) {
    return (
        <div role="alert" className={'mb-3 rounded-lg px-4 py-3 text-[13px] ' + (tone === 'danger' ? 'bg-[#fef2f2] text-[#b91c1c]' : 'bg-[#fffbeb] text-[#b45309]')}>
            {children}
        </div>
    );
}

function Row({ label, value, ltr }: { label: string; value: string | null | undefined; ltr?: boolean }) {
    const t = useTranslate();
    if (!value) return null;
    return (
        <div className="flex justify-between gap-3 sm:block">
            <dt className="text-[#9ca3af]">{t(label)}</dt>
            <dd className="font-medium" dir={ltr ? 'ltr' : undefined}>{value}</dd>
        </div>
    );
}

/* ─────────────────────────── نظرة عامة ─────────────────────────── */

/**
 * البيانات — ما خرج من الرأس ليهدأ: تثبيتُ المتطلّبات والقيمةُ الأصليّة في
 * سطر الخلاصة، ثمّ سجلُّ الفعاليّة كاملًا وما وافق عليه العميل.
 */
function Overview({ p, users, may, scope, contacts, m }: { p: Project; users: { id: number; name: string }[]; may: Props['may']; scope: Scope; contacts: CustomerContact[]; m: Money }) {
    const t = useTranslate();
    const [editing, setEditing] = useState(false);
    const [form, setForm] = useState({
        name: p.name, event_type: p.event_type ?? '', location: p.location ?? '',
        contact_person: p.contact_person ?? '', contact_phone: p.contact_phone ?? '', contact_email: p.contact_email ?? '',
        customer_contact_id: p.customer_contact_id ? String(p.customer_contact_id) : '',
        owner_id: p.owner_id ? String(p.owner_id) : '', notes: p.notes ?? '', internal_notes: p.internal_notes ?? '',
    });
    const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [k]: e.target.value });
    /* والأصلُ يُذكر حين افترق عن الحاليّ بأمر تغيير — وإلّا فهو هو */
    const changed = p.current_value !== null && p.approved_value !== null && Math.abs(p.current_value - p.approved_value) > 0.0005;

    return (
        <div>
            <TabHead
                facts={[
                    /* وغيرُ المثبَّتة شارةٌ فوق التبويبات — لا تُعاد هنا */
                    p.baselined && { label: 'متطلّبات مثبَّتة', value: p.requirements_baselined_at ?? undefined, tone: 'ok' },
                    changed && { label: 'القيمة الأصلية', value: m(p.approved_value) },
                ]}
                action={
                    may.manage && p.status !== 'closed' && p.status !== 'cancelled' && (
                        <Button variant="outline" size="sm" onClick={() => setEditing(!editing)}>
                            {!editing && <Pencil />}
                            {editing ? t('تراجع') : t('تعديل')}
                        </Button>
                    )
                }
            />
            <div className="grid gap-4 lg:grid-cols-2">
                <Card className="p-4">
                    <h2 className="mb-3 text-[15px] font-semibold">{t('الفعالية')}</h2>
                    {!editing ? (
                        <dl className="grid gap-x-6 gap-y-2 text-[13px] sm:grid-cols-2">
                            <Row label="الاسم" value={p.name} />
                            <Row label="العميل" value={p.customer} />
                            <Row label="النوع" value={p.event_type} />
                            <Row label="الموقع" value={p.location} />
                            <Row label="الموعد" value={p.starts_at ? `${p.starts_at}${p.ends_at && p.ends_at !== p.starts_at ? ` → ${p.ends_at}` : ''}` : null} ltr />
                            <Row label="المسؤول" value={p.owner} />
                            <Row label="جهة الاتصال" value={p.contact_person} />
                            <Row label="الهاتف" value={p.contact_phone} ltr />
                            <Row label="البريد" value={p.contact_email} ltr />
                            <Row label="العرض المقبول" value={scope.original.quotation?.label} />
                            <Row label="أُقفل في" value={p.closed_at} ltr />
                            <Row label="سبب الإلغاء" value={p.cancellation_reason} />
                            <Row label="رسم الإلغاء" value={p.cancellation_fee_invoice ? `${p.cancellation_fee_invoice.number ?? '#' + p.cancellation_fee_invoice.id}` : null} />
                            <Row label="سياسة الإلغاء" value={p.cancellation_policy ? String(p.cancellation_policy.message ?? '') : null} />
                        </dl>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="اسم المشروع" required className="sm:col-span-2"><Input value={form.name} onChange={set('name')} /></Field>
                            <Field label="النوع"><Input value={form.event_type} onChange={set('event_type')} /></Field>
                            <Field label="الموقع"><Input value={form.location} onChange={set('location')} /></Field>
                            <Field label="المسؤول">
                                <Select placeholder="بلا مسؤول" value={form.owner_id} onChange={set('owner_id')} options={users.map((u) => ({ label: u.name, value: u.id }))} />
                            </Field>
                            {contacts.length > 0 && (
                                <Field label="من أشخاص اتصال الجهة" hint="يملأ الاسم والهاتف والبريد — وتبقى تُعدَّل بيد" className="sm:col-span-2">
                                    <ContactPicker
                                        contacts={contacts}
                                        value={form.customer_contact_id}
                                        onPick={(c) => setForm({
                                            ...form,
                                            customer_contact_id: c ? String(c.id) : '',
                                            contact_person: c ? c.name : form.contact_person,
                                            contact_phone: c ? (c.phone ?? '') : form.contact_phone,
                                            contact_email: c ? (c.email ?? '') : form.contact_email,
                                        })}
                                    />
                                </Field>
                            )}
                            <Field label="جهة الاتصال"><Input value={form.contact_person} onChange={set('contact_person')} /></Field>
                            <Field label="الهاتف"><Input dir="ltr" value={form.contact_phone} onChange={set('contact_phone')} /></Field>
                            <Field label="البريد"><Input dir="ltr" type="email" value={form.contact_email} onChange={set('contact_email')} /></Field>
                            <Field label="ملاحظات" className="sm:col-span-2"><Textarea rows={3} value={form.notes} onChange={set('notes')} /></Field>
                            <Field label="ملاحظات داخلية" hint="لا تبلغ العميل" className="sm:col-span-2"><Textarea rows={2} value={form.internal_notes} onChange={set('internal_notes')} /></Field>
                            <div className="sm:col-span-2">
                                <Button onClick={() => router.put(route('admin.projects.update', p.id), form, { preserveScroll: true, onSuccess: () => setEditing(false) })}>
                                    <Check />
                                    {t('حفظ')}
                                </Button>
                            </div>
                        </div>
                    )}
                    {p.starts_at && !editing && (
                        <p className="mt-3 text-[12px] text-[#9ca3af]">{t('الموعدُ من النطاق المعتمَد — يتغيّر بأمر تغيير لا من هنا.')}</p>
                    )}
                </Card>

                <Card className="p-4">
                    <h2 className="mb-3 text-[15px] font-semibold">{t('ما وافق عليه العميل')}</h2>
                    {scope.original.quotation ? (
                        <dl className="grid gap-x-6 gap-y-2 text-[13px] sm:grid-cols-2">
                            <div className="flex justify-between gap-3 sm:block">
                                <dt className="text-[#9ca3af]">{t('العرض')}</dt>
                                <dd className="font-medium">
                                    <SmartLink routeName="admin.quotations.show" href={route('admin.quotations.show', scope.original.quotation.id)} className="text-[#1d4ed8]">
                                        {scope.original.quotation.label}
                                    </SmartLink>
                                </dd>
                            </div>
                            <Row label="قُبل في" value={scope.original.quotation.decided_at} ltr />
                            <Row label="قَبِله" value={scope.original.quotation.evidence?.signer_name ?? scope.original.quotation.decided_by_name} />
                            <Row label="الموعد الأصلي" value={scope.original.quotation.event_starts_at} ltr />
                            {scope.original.quotation.evidence && (
                                <div className="sm:col-span-2">
                                    <span className={'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] ' + (scope.original.quotation.evidence.verified ? 'bg-[#ecfdf5] text-[#065f46]' : 'bg-[#fef2f2] text-[#b91c1c]')}>
                                        <ShieldCheck className="size-3" />
                                        {scope.original.quotation.evidence.verified ? t('البصمة تطابق') : t('البصمة لا تطابق — تغيّر المستند بعد القرار')}
                                    </span>
                                </div>
                            )}
                        </dl>
                    ) : (
                        <p className="text-[13px] text-[#9ca3af]">{t('مشروع بلا عرض سعر مقبول')}</p>
                    )}
                    {p.notes && <p className="mt-3 whitespace-pre-line text-[13px] text-[#52525b]">{p.notes}</p>}
                </Card>
            </div>
        </div>
    );
}

/* ─────────────────────────── النطاق ─────────────────────────── */

/**
 * النطاق — الحاليُّ أوّلًا (ما يُنفَّذ الآن)، والأصلُ وما تغيّر مطويّان
 * تحته: يُقرآن حين يُسأل «من أين جاء هذا البند؟» لا في كلّ زيارة.
 */
function ScopeTab({ scope, m }: { scope: Scope; m: Money }) {
    const t = useTranslate();
    const q = (v: number) => number(v);

    return (
        <div className="space-y-4">
            <TabHead
                facts={[
                    { label: 'البنود', n: scope.current.items.filter((i) => i.quantity > 0).length },
                    scope.current.current_value !== null && { label: 'القيمة المعتمَدة الحالية', value: m(scope.current.current_value) },
                    { label: 'أوامر تغيير مقبولة', n: scope.changes.length },
                ]}
            />

            <Card className="p-4">
                <h2 className="mb-3 text-[15px] font-semibold">{t('النطاق الحالي')}</h2>
                <div className="overflow-x-auto">
                    <table className="w-full text-[13px]">
                        <thead className="text-[12px] text-[#6b7280]">
                            <tr className="border-b border-[#e8e8e8]">
                                <th className="py-2 text-start">{t('البيان')}</th>
                                <th className="py-2 text-center">{t('الكمية')}</th>
                                <th className="py-2 text-end">{t('سعر الوحدة')}</th>
                                <th className="py-2 text-end">{t('المبلغ')}</th>
                                <th className="py-2 text-center">{t('المصدر')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {scope.current.items.map((i) => (
                                <tr key={i.id} className={'border-b border-[#f4f4f5] ' + (i.quantity <= 0 ? 'text-[#9ca3af] line-through' : '')}>
                                    <td className="py-2 font-medium">{i.description}</td>
                                    <td className="py-2 text-center tabular-nums">{q(i.quantity)} {i.unit ?? ''}</td>
                                    <td className="py-2 text-end tabular-nums">{m(i.unit_price)}</td>
                                    <td className="py-2 text-end tabular-nums">{m(i.line_total)}</td>
                                    <td className="py-2 text-center text-[11px] text-[#6b7280]">{i.origin === 'quotation' ? t('العرض') : t('أمر تغيير')}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="mt-3 flex flex-wrap justify-between gap-2 text-[13px]">
                    <span dir="ltr" className="text-[#6b7280]">
                        {scope.current.starts_at ?? '—'}{scope.current.ends_at && scope.current.ends_at !== scope.current.starts_at ? ` → ${scope.current.ends_at}` : ''}
                    </span>
                    <span className="font-semibold tabular-nums">{t('القيمة المعتمَدة الحالية')}: {m(scope.current.current_value)}</span>
                </div>
            </Card>

            {/* الأصلُ وما تغيّر — مطويّان لا مخفيّان */}
            <MoreDetails label="الأصل وما تغيّر">
                <p className="mb-3 text-[12px] text-[#9ca3af]">{t('ما يجب على الفريق تنفيذه الآن — الأصل بعد أوامر التغيير المقبولة.')}</p>
                <div className="grid gap-4 lg:grid-cols-2">
                    <section>
                        <h2 className="mb-1 text-[14px] font-semibold">{t('الأصل')}</h2>
                        <p className="mb-3 text-[12px] text-[#9ca3af]">{t('كما قُبل في العرض — لا يُكتب فيه بعدها.')}</p>
                        <ul className="space-y-1 text-[13px]">
                            {scope.original.items.map((i) => (
                                <li key={i.id} className="flex justify-between gap-3">
                                    <span className="min-w-0 break-words">{i.description}</span>
                                    <span className="shrink-0 tabular-nums text-[#6b7280]">{q(i.quantity)} {i.unit ?? ''} · {m(i.line_total)}</span>
                                </li>
                            ))}
                        </ul>
                        <div className="mt-3 text-[13px] font-semibold tabular-nums">{t('القيمة الأصلية')}: {m(scope.original.approved_value)}</div>
                        {scope.original.requirements.length > 0 && (
                            <div className="mt-3 text-[12px] text-[#6b7280]">
                                {t('متطلّبات الأصل')}: {scope.original.requirements.map((r) => r.title).join(' · ')}
                            </div>
                        )}
                    </section>

                    <section>
                        <h2 className="mb-1 text-[14px] font-semibold">{t('ما تغيّر')}</h2>
                        <p className="mb-3 text-[12px] text-[#9ca3af]">{t('أوامر التغيير المقبولة بترتيبها.')}</p>
                        {scope.changes.length === 0 ? (
                            <p className="text-[13px] text-[#9ca3af]">{t('لا تغيير مقبول بعد')}</p>
                        ) : (
                            <ul className="space-y-3 text-[13px]">
                                {scope.changes.map((c) => (
                                    <li key={c.id} className="rounded-lg border border-[#f4f4f5] p-3">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <SmartLink routeName="admin.changeOrders.show" href={route('admin.changeOrders.show', c.id)} className="font-medium text-[#1d4ed8]">
                                                {c.number} — {c.title}
                                            </SmartLink>
                                            <span className={'tabular-nums font-semibold ' + ((c.total_delta ?? 0) < 0 ? 'text-[#b91c1c]' : 'text-[#047857]')}>
                                                {(c.total_delta ?? 0) >= 0 && c.total_delta !== null ? '+' : ''}{m(c.total_delta)}
                                            </span>
                                        </div>
                                        <div className="mt-1 text-[12px] text-[#6b7280]">
                                            {t('طلبه')}: {t(PARTY_LABEL[c.requested_by_party] ?? c.requested_by_party)} · {t('قَبِله')}: {c.decided_by_name ?? '—'} · {c.decided_at}
                                        </div>
                                        <div className="mt-1 text-[12px] text-[#52525b]">
                                            {c.items.map((i, k) => <span key={k}>{i.quantity >= 0 ? '+' : ''}{q(i.quantity)} {i.description} · </span>)}
                                            {c.added.map((r, k) => <span key={'a' + k}>+ {r.title} · </span>)}
                                            {c.removed.map((r, k) => <span key={'r' + k}>− {r.title} · </span>)}
                                            {c.new_starts_at && <span dir="ltr">→ {c.new_starts_at}</span>}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </MoreDetails>
        </div>
    );
}

/* ─────────────────────────── المتطلّبات ─────────────────────────── */

/**
 * المتطلّبات — شروطٌ تصدق عند التسليم بلا سعرٍ ولا كميّة، وما أُزيل يبقى
 * مكتوبًا مع الأمر الذي أزاله.
 *
 * القائمةُ بعرضها كلِّه، و«إضافة متطلّب» نافذةٌ — هي نفسُها للتعديل. فلا
 * نموذجٌ مفتوحٌ بجانب القائمة في كلّ زيارة.
 */
function RequirementsTab({ p, requirements, items, may, open, error }: { p: Project; requirements: Requirement[]; items: ScopeItem[]; may: Props['may']; open: boolean; error?: string }) {
    const t = useTranslate();
    const editable = may.manage && open && !p.baselined;
    const blank = { title: '', details: '', project_item_id: '' };
    const [form, setForm] = useState(blank);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [dialog, setDialog] = useState(false);
    const active = requirements.filter((r) => r.active).length;

    const start = (r?: Requirement) => {
        setEditingId(r?.id ?? null);
        setForm(r ? { title: r.title, details: r.details ?? '', project_item_id: r.project_item_id ? String(r.project_item_id) : '' } : blank);
        setDialog(true);
    };
    const finish = () => {
        setDialog(false);
        setEditingId(null);
        setForm(blank);
    };
    const submit = () => {
        if (editingId) {
            router.put(route('admin.projects.requirements.update', [p.id, editingId]), form, { preserveScroll: true, onSuccess: finish });
        } else {
            router.post(route('admin.projects.requirements.store', p.id), form, { preserveScroll: true, onSuccess: finish });
        }
    };

    return (
        <div>
            <TabHead
                facts={[
                    { label: 'الفعّالة', n: active },
                    { label: 'أُزيلت', n: requirements.length - active },
                    p.baselined ? { label: 'مثبَّتة', value: p.requirements_baselined_at ?? undefined, tone: 'ok' } : open && { label: 'غير مثبَّتة بعد' },
                ]}
                action={
                    editable && (
                        <Button size="sm" onClick={() => start()}>
                            <Plus />
                            {t('إضافة متطلّب')}
                        </Button>
                    )
                }
            />

            <Card className="p-4">
                {requirements.length === 0 ? (
                    <p className="text-[13px] text-[#9ca3af]">{t('لا متطلّبات بعد')}</p>
                ) : (
                    <ul className="space-y-2 text-[13px]">
                        {requirements.map((r) => (
                            <li key={r.id} className={'rounded-lg border p-3 ' + (r.active ? 'border-[#f4f4f5]' : 'border-dashed border-[#e8e8e8] text-[#9ca3af]')}>
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <div className={'font-medium ' + (r.active ? '' : 'line-through')}>{r.title}</div>
                                        {r.details && <div className="mt-0.5 whitespace-pre-line text-[12px] text-[#6b7280]">{r.details}</div>}
                                        <div className="mt-1 flex flex-wrap gap-2 text-[11px] text-[#9ca3af]">
                                            {r.item && <span>{t('على البند')}: {r.item}</span>}
                                            {r.baseline ? <span>{t('من الأصل')}</span> : r.added_by && (
                                                <span>
                                                    {t('أضافه')} <SmartLink routeName="admin.changeOrders.show" href={route('admin.changeOrders.show', r.added_by.id)} className="text-[#1d4ed8]">{r.added_by.label}</SmartLink>
                                                    {r.added_by.status !== 'accepted' && ` (${t(CO_STATE_LABEL[r.added_by.status] ?? r.added_by.status)})`}
                                                </span>
                                            )}
                                            {r.removed_by && (
                                                <span>
                                                    {r.removed_by.status === 'accepted' ? t('أزاله') : t('يقترح إزالته')} <SmartLink routeName="admin.changeOrders.show" href={route('admin.changeOrders.show', r.removed_by.id)} className="text-[#1d4ed8]">{r.removed_by.label}</SmartLink>
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                    {editable && (
                                        <div className="flex gap-1">
                                            <Button variant="outline" size="sm" onClick={() => start(r)}>{t('تعديل')}</Button>
                                            <Button variant="outline" size="sm" aria-label={t('حذف')} onClick={() => router.delete(route('admin.projects.requirements.destroy', [p.id, r.id]), { preserveScroll: true })}><Trash2 /></Button>
                                        </div>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                {/* متى تُثبَّت ولماذا لا تُعدَّل — سطرٌ تحت القائمة لا فوقها */}
                {(p.baselined || open) && (
                    <p className="mt-3 text-[12px] text-[#9ca3af]">
                        {p.baselined
                            ? t('ثُبّتت المتطلّبات في :at — والتغيير بأمر تغيير يقبله العميل.', { at: p.requirements_baselined_at ?? '' })
                            : editable
                              ? t('المتطلّبات لم تُثبَّت بعد — تُثبَّت حين يُرسَل نطاق العمل للتوقيع، وبعدها لا تتغيّر إلّا بأمر تغيير يقبله العميل.')
                              : t('لا تملك تعديل المتطلّبات.')}
                    </p>
                )}
            </Card>

            <Dialog open={dialog} onOpenChange={(v) => (v ? setDialog(true) : finish())}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{editingId ? t('تعديل متطلّب') : t('متطلّب جديد')}</DialogTitle>
                        <DialogDescription>{t('شروطٌ يجب أن تصدق عند التسليم — بلا سعر ولا كميّة. وما أُزيل يبقى مكتوبًا مع الأمر الذي أزاله.')}</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 px-5">
                        {error && <p className="text-[12.5px] text-[#b91c1c]">{error}</p>}
                        <Field label="المتطلّب" required><Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} /></Field>
                        <Field label="التفاصيل"><Textarea rows={3} value={form.details} onChange={(e) => setForm({ ...form, details: e.target.value })} /></Field>
                        <Field label="على بند" hint="اختياري — شرطٌ على بندٍ بعينه">
                            <Select placeholder="المشروع كلّه" value={form.project_item_id} onChange={(e) => setForm({ ...form, project_item_id: e.target.value })} options={items.map((i) => ({ label: i.description, value: i.id }))} />
                        </Field>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={finish}>{t('تراجع')}</Button>
                        <Button disabled={form.title.trim() === ''} onClick={submit}>
                            {editingId ? <Check /> : <Plus />}
                            {editingId ? t('حفظ') : t('إضافة')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

/* ─────────────────────────── الفريق ─────────────────────────── */

/** الفريق — «الأعضاء ٥» ثمّ «إضافة عضو» ثمّ القائمة */
function TeamTab({ p, members, users, may, open, error }: { p: Project; members: Member[]; users: { id: number; name: string }[]; may: Props['may']; open: boolean; error?: string }) {
    const t = useTranslate();
    const [adding, setAdding] = useState(false);
    const [userId, setUserId] = useState('');
    const [role, setRole] = useState('');
    const finish = () => {
        setAdding(false);
        setUserId('');
        setRole('');
    };

    return (
        <div>
            <TabHead
                facts={[{ label: 'الأعضاء', n: members.length }]}
                empty="لا أعضاء بعد"
                action={
                    may.manage && open && (
                        <Button size="sm" onClick={() => setAdding(true)}>
                            <Plus />
                            {t('إضافة عضو')}
                        </Button>
                    )
                }
            />

            {members.length > 0 && (
                <Card className="p-4">
                    <ul className="divide-y divide-[#f4f4f5] text-[13px]">
                        {members.map((mb) => (
                            <li key={mb.id} className="flex items-center justify-between gap-3 py-2">
                                <span className="min-w-0"><span className="font-medium">{mb.name}</span>{mb.role && <span className="text-[#9ca3af]"> — {mb.role}</span>}</span>
                                {may.manage && open && <Button variant="outline" size="sm" aria-label={t('حذف')} onClick={() => router.delete(route('admin.projects.members.destroy', [p.id, mb.id]), { preserveScroll: true })}><Trash2 /></Button>}
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <Dialog open={adding} onOpenChange={(v) => (v ? setAdding(true) : finish())}>
                <DialogContent className="max-w-md" aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>{t('إضافة عضو')}</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3 px-5">
                        {error && <p className="text-[12.5px] text-[#b91c1c]">{error}</p>}
                        <Field label="الموظّف" required>
                            <Select placeholder="اختر" value={userId} onChange={(e) => setUserId(e.target.value)} options={users.filter((u) => !members.some((mb) => mb.user_id === u.id)).map((u) => ({ label: u.name, value: u.id }))} />
                        </Field>
                        <Field label="دوره في المشروع"><Input value={role} onChange={(e) => setRole(e.target.value)} placeholder="منسّق ميداني" /></Field>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={finish}>{t('تراجع')}</Button>
                        <Button disabled={userId === ''} onClick={() => router.post(route('admin.projects.members.store', p.id), { user_id: userId, role }, { preserveScroll: true, onSuccess: finish })}><Plus />{t('إضافة')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

/* ─────────────────────────── المهامّ ─────────────────────────── */

/**
 * المهامّ — عملٌ داخليّ لشخصٍ وموعد، لا نطاقٌ يوافق عليه العميل.
 *
 * «المفتوحة ٤ · المتأخّرة ١ · المكتملة ٧» ثمّ «إضافة مهمّة» ثمّ القائمة.
 * والمتأخّرةُ كما يعدّها الخادم على بطاقة القائمة: مفتوحةٌ فات موعدُها.
 */
function TasksTab({ p, tasks, users, may, events, today, error }: { p: Project; tasks: Task[]; users: { id: number; name: string }[]; may: Props['may']; events: TaskOperation[]; today: string; error?: string }) {
    const t = useTranslate();
    const blank = { title: '', description: '', assignee_id: '', due_at: '', priority: 'normal', event_operation_id: '' };
    const [form, setForm] = useState(blank);
    const [adding, setAdding] = useState(false);
    const eventOf = (id: number | null | undefined) => events.find((e) => e.id === id);
    const pending = tasks.filter((x) => x.status !== 'done');
    const finish = () => {
        setAdding(false);
        setForm(blank);
    };

    return (
        <div>
            <TabHead
                facts={[
                    { label: 'المفتوحة', n: pending.length },
                    { label: 'المتأخّرة', n: pending.filter((x) => isLate(x, today)).length, tone: 'danger' },
                    { label: 'المكتملة', n: tasks.length - pending.length },
                ]}
                empty="لا مهامّ بعد"
                action={
                    may.manage && (
                        <Button size="sm" onClick={() => setAdding(true)}>
                            <Plus />
                            {t('إضافة مهمّة')}
                        </Button>
                    )
                }
            />

            {tasks.length > 0 && (
                <Card className="p-4">
                    <ul className="divide-y divide-[#f4f4f5] text-[13px]">
                        {tasks.map((task) => (
                            <li key={task.id} className="flex flex-wrap items-start justify-between gap-3 py-2">
                                <div className={'min-w-0 ' + (task.status === 'done' ? 'text-[#9ca3af] line-through' : '')}>
                                    <div className="font-medium">{task.title}{task.priority === 'high' && <span className="ms-2 rounded-full bg-[#fef2f2] px-2 py-0.5 text-[11px] text-[#b91c1c] no-underline">{t('عاجل')}</span>}</div>
                                    <div className="text-[12px] text-[#6b7280]">
                                        {task.assignee ?? t('بلا منفّذ')}
                                        {task.due_at && (
                                            <>
                                                {' · '}
                                                <span dir="ltr" className={isLate(task, today) ? 'font-medium text-[#b91c1c]' : undefined}>{task.due_at}</span>
                                            </>
                                        )}
                                        {eventOf(task.event_operation_id) && <span className="ms-2 rounded-full bg-[#f5f3ff] px-2 py-0.5 text-[11px] text-[#6d28d9] no-underline">{t('فعالية')}: {eventOf(task.event_operation_id)!.code}</span>}
                                    </div>
                                    {task.description && <div className="mt-0.5 text-[12px] text-[#6b7280]">{task.description}</div>}
                                </div>
                                {may.manage && (
                                    <div className="flex gap-1">
                                        <Button variant="outline" size="sm" onClick={() => router.put(route('admin.projects.tasks.update', [p.id, task.id]), { title: task.title, status: task.status === 'done' ? 'open' : 'done' }, { preserveScroll: true })}>
                                            <Check />{task.status === 'done' ? t('إعادة فتح') : t('تمّ')}
                                        </Button>
                                        <Button variant="outline" size="sm" aria-label={t('حذف')} onClick={() => router.delete(route('admin.projects.tasks.destroy', [p.id, task.id]), { preserveScroll: true })}><Trash2 /></Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <Dialog open={adding} onOpenChange={(v) => (v ? setAdding(true) : finish())}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('مهمّة جديدة')}</DialogTitle>
                        <DialogDescription>{t('عملٌ داخلي لشخص وموعد — ليس نطاقًا يوافق عليه العميل.')}</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 px-5">
                        {error && <p className="text-[12.5px] text-[#b91c1c]">{error}</p>}
                        <Field label="المهمّة" required><Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} /></Field>
                        <Field label="التفاصيل"><Textarea rows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></Field>
                        <Field label="المنفّذ"><Select placeholder="بلا منفّذ" value={form.assignee_id} onChange={(e) => setForm({ ...form, assignee_id: e.target.value })} options={users.map((u) => ({ label: u.name, value: u.id }))} /></Field>
                        <Field label="الموعد"><Input type="date" dir="ltr" value={form.due_at} onChange={(e) => setForm({ ...form, due_at: e.target.value })} /></Field>
                        <Field label="الأولوية"><Select value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })} options={[{ label: 'منخفضة', value: 'low' }, { label: 'عادية', value: 'normal' }, { label: 'عاجلة', value: 'high' }]} /></Field>
                        {events.length > 0 && (
                            <Field label="الفعالية" hint="اختياري — مهام الموظف في الفعالية تصله في رسالة واحدة">
                                <Select placeholder="مهمة عامة للمشروع" value={form.event_operation_id} onChange={(e) => setForm({ ...form, event_operation_id: e.target.value })}
                                    options={events.map((e) => ({ label: `${e.code} — ${e.name}`, value: e.id }))} />
                            </Field>
                        )}
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={finish}>{t('تراجع')}</Button>
                        <Button disabled={form.title.trim() === ''} onClick={() => router.post(route('admin.projects.tasks.store', p.id), form, { preserveScroll: true, onSuccess: finish })}><Plus />{t('إضافة')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

/* ─────────────────────────── أوامر التغيير ─────────────────────────── */

function ChangeOrdersTab({ p, rows, m, may, open }: { p: Project; rows: ChangeOrderRow[]; m: Money; may: Props['may']; open: boolean }) {
    const t = useTranslate();

    return (
        <div>
            <TabHead
                facts={[
                    { label: 'الأوامر', n: rows.length },
                    { label: 'بانتظار القرار', n: rows.filter((c) => c.status === 'pending_approval' || c.status === 'sent').length },
                    { label: 'المقبولة', n: rows.filter((c) => c.status === 'accepted').length },
                ]}
                empty="لا أوامر تغيير — النطاق كما قُبل."
                action={
                    open && may.change_order && (
                        <Button size="sm" asChild>
                            <SmartLink routeName="admin.changeOrders.create" href={route('admin.changeOrders.create', p.id)}><Plus />{t('أمر تغيير')}</SmartLink>
                        </Button>
                    )
                }
            />
            {rows.length > 0 && (
                <Card className="p-4">
                    <div className="overflow-x-auto">
                        <table className="w-full text-[13px]">
                            <thead className="text-[12px] text-[#6b7280]">
                                <tr className="border-b border-[#e8e8e8]">
                                    <th className="py-2 text-start">{t('الأمر')}</th>
                                    <th className="py-2 text-start">{t('طلبه')}</th>
                                    <th className="py-2 text-end">{t('فرق القيمة')}</th>
                                    <th className="py-2 text-center">{t('موعد جديد')}</th>
                                    <th className="py-2 text-center">{t('الحالة')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((c) => (
                                    <tr key={c.id} className="border-b border-[#f4f4f5]">
                                        <td className="py-2">
                                            <SmartLink routeName="admin.changeOrders.show" href={route('admin.changeOrders.show', c.id)} className="font-medium text-[#1d4ed8]">{c.label}</SmartLink>
                                            <div className="text-[11px] text-[#9ca3af]">{c.title}</div>
                                        </td>
                                        <td className="py-2">{t(PARTY_LABEL[c.requested_by_party] ?? c.requested_by_party)}</td>
                                        <td className={'py-2 text-end tabular-nums font-semibold ' + ((c.total_delta ?? 0) < 0 ? 'text-[#b91c1c]' : (c.total_delta ?? 0) > 0 ? 'text-[#047857]' : '')}>{c.total_delta !== null && c.total_delta >= 0 ? '+' : ''}{m(c.total_delta)}</td>
                                        <td className="py-2 text-center" dir="ltr">{c.new_starts_at ?? '—'}</td>
                                        <td className="py-2 text-center"><span className={'rounded-full px-2 py-1 text-[11px] ' + coTone(c.state)}>{t(CO_STATE_LABEL[c.state] ?? c.state)}</span></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            )}
        </div>
    );
}

/* ─────────────────────────── العمليات ─────────────────────────── */

/**
 * العمليات — المرحلة 10: فعاليّاتُ المشروع بجاهزيّتها.
 *
 * ملخّصٌ يقود إلى شاشة الفعاليّة، لا الشاشةَ نفسَها: الجاهزيّةُ والفريقُ
 * والمعدّاتُ والقوائمُ والحوادثُ أرقامًا، والتفصيلُ خلف الرابط. ولا يُنقل
 * شيءٌ تجاريّ إلى هنا.
 */
function OperationsTab({ p, rows, may, open }: { p: Project; rows: OperationRow[]; may: Props['may']; open: boolean }) {
    const t = useTranslate();

    return (
        <div className="space-y-3">
            <TabHead
                facts={[
                    { label: 'الفعاليّات', n: rows.length },
                    { label: 'الجارية', n: rows.filter((o) => !['completed', 'cancelled'].includes(o.status)).length },
                    { label: 'غير جاهزة', n: rows.filter(isUnready).length, tone: 'danger' },
                ]}
                action={
                    may.operations_manage && open && (
                        <Button size="sm" onClick={() => router.post(route('admin.operations.store'), { project_id: p.id })}>
                            <Plus />
                            {t('فعاليّة جديدة')}
                        </Button>
                    )
                }
            />
            {rows.length === 0 && (
                <Card className="p-8 text-center text-[13px] text-[#9ca3af]">
                    <CalendarCheck className="mx-auto mb-2 size-6" />
                    {t('لا فعاليّات تشغيليّة بعد — أنشئ واحدةً ليُخطَّط الموعدُ والفريقُ والمعدّات')}
                </Card>
            )}
            {rows.map((o) => (
                <Card key={o.id} className="p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <SmartLink routeName="admin.operations.show" href={o.url} className="font-mono text-[12px] text-[#1d4ed8]">{o.code}</SmartLink>
                                <span className="font-semibold">{o.name}</span>
                                <span className={'rounded-full px-2 py-0.5 text-[11px] ' + operationTone(o.status)}>{o.status_label}</span>
                                {!['completed', 'cancelled'].includes(o.status) && (
                                    <span className={'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] ' + (o.ready ? 'bg-[#ecfdf5] text-[#065f46]' : 'bg-[#fef2f2] text-[#b91c1c]')}>
                                        {o.ready ? <CheckCircle2 className="size-3" /> : <AlertTriangle className="size-3" />}
                                        {o.ready ? t('جاهزة') : t(':n موانع', { n: String(o.blockers.length) })}
                                    </span>
                                )}
                            </div>
                            <div className="mt-1 text-[12px] text-[#6b7280]">
                                <span dir="ltr" className="tabular-nums">{o.event_starts_at ?? '—'}</span>
                                {o.venue_name && ` · ${o.venue_name}`}
                                {o.lead && ` · ${t('القائد')}: ${o.lead}`}
                            </div>
                            {!o.ready && o.blockers.length > 0 && !['completed', 'cancelled'].includes(o.status) && (
                                <div className="mt-1 text-[12px] text-[#b91c1c]">{o.blockers.slice(0, 3).join(' · ')}{o.blockers.length > 3 ? ` +${o.blockers.length - 3}` : ''}</div>
                            )}
                        </div>
                        <Button variant="outline" size="sm" asChild>
                            <SmartLink routeName="admin.operations.onsite" href={o.onsite_url}><Smartphone />{t('شاشة الموقع')}</SmartLink>
                        </Button>
                    </div>
                    <dl className="mt-3 grid grid-cols-2 gap-2 text-[12px] sm:grid-cols-5">
                        <Figure label="الفريق" value={`${o.crew.checked_in}/${o.crew.expected}`} tone={o.crew.expected === 0 ? 'danger' : undefined} />
                        <Figure label="المعدّات" value={`${o.equipment.assigned}/${o.equipment.required}`} tone={o.equipment.assigned < o.equipment.required ? 'warning' : undefined} />
                        <Figure label="لم تُردّ" value={String(o.equipment.outstanding)} tone={o.equipment.outstanding > 0 ? 'warning' : undefined} />
                        <Figure label="القوائم" value={`${o.checklist.done}/${o.checklist.total}`} tone={o.checklist.required_pending > 0 ? 'warning' : undefined} />
                        <Figure label="حوادث مفتوحة" value={String(o.incidents.open)} tone={o.incidents.open > 0 ? 'danger' : undefined} />
                    </dl>
                    {o.conflicts > 0 && (
                        <div className={'mt-2 text-[12px] ' + (o.critical_conflicts > 0 ? 'text-[#b91c1c]' : 'text-[#b45309]')}>
                            {t(':n تعارضات في المعدّات أو الفريق', { n: String(o.conflicts) })}
                        </div>
                    )}
                </Card>
            ))}
        </div>
    );
}

function Figure({ label, value, tone }: { label: string; value: string; tone?: 'danger' | 'warning' }) {
    const t = useTranslate();

    return (
        <div className="rounded-lg bg-[#fafafa] px-3 py-2">
            <dt className="text-[11px] text-[#9ca3af]">{t(label)}</dt>
            <dd className={'text-[15px] font-semibold tabular-nums ' + (tone === 'danger' ? 'text-[#b91c1c]' : tone === 'warning' ? 'text-[#b45309]' : '')}>{value}</dd>
        </div>
    );
}

/* ─────────────────────────── المخزون ─────────────────────────── */

/**
 * المخزون — «بنود فيها نقص ٢» ثمّ التوقّعُ نفسُه. قراءةٌ لا خصم
 * (`InventoryForecast`)؛ وشرحُ الأعمدة سطرٌ تحت الجدول لا فقرةٌ فوقه.
 */
function InventoryTab({ data, projectId }: { data: ProjectForecast; projectId: number }) {
    const t = useTranslate();
    const known = data.in_window && data.lines.length > 0;

    return (
        <div>
            <TabHead
                facts={
                    known
                        ? [data.shortages > 0 ? { label: 'بنود فيها نقص', n: data.shortages, tone: 'danger' } : { label: 'لا نقص في المخزون', tone: 'ok' }, { label: 'المنتجات', n: data.lines.length }]
                        : []
                }
            />
            <Card className="p-4">
                <ProjectInventoryForecast data={data} projectId={projectId} />
                {known && <p className="mt-1 text-[11.5px] text-[#9ca3af]">{t('لكل منتج مخزّن: المتوفر الآن، وما تحتاجه مشاريع قبله في الجدول، واحتياج هذا المشروع، والمتوقع بعده.')}</p>}
            </Card>
        </div>
    );
}

/* ─────────────────────────── المال ─────────────────────────── */

function FinancialsTab({ f, m, frozen }: { f: Record<string, number | boolean>; m: Money; frozen: Record<string, number> | null }) {
    const t = useTranslate();
    const n = (k: string) => Number(f[k] ?? 0);
    const Line = ({ label, k, strong, tone }: { label: string; k: string; strong?: boolean; tone?: string }) => (
        <div className={'flex justify-between gap-3 py-1 ' + (strong ? 'font-semibold' : '')}>
            <span className={strong ? '' : 'text-[#6b7280]'}>{t(label)}</span>
            <span className={'tabular-nums ' + (tone ?? '')}>{m(n(k))}</span>
        </div>
    );

    return (
        <div>
            <TabHead
                facts={[
                    { label: 'المفوتَر', value: m(n('invoiced')) },
                    { label: 'المحصَّل', value: m(n('collected')) },
                    { label: 'الباقي على العميل', value: m(n('customer_outstanding')) },
                ]}
            />
            <div className="grid gap-4 lg:grid-cols-3">
                <Card className="p-4 text-[13px]">
                    <h2 className="mb-2 flex items-center gap-2 text-[15px] font-semibold"><Wallet className="size-4" />{t('الإيراد')}</h2>
                    <Line label="القيمة المعروضة" k="quoted_value" />
                    <Line label="القيمة المعتمَدة الأصلية" k="approved_value" />
                    <Line label="القيمة المعتمَدة الحالية" k="current_value" strong />
                    <Line label="المفوتَر" k="invoiced" />
                    {n('credited') > 0 && <Line label="إشعارات دائن" k="credited" />}
                    {n('credited') > 0 && <Line label="صافي المفوتَر" k="net_invoiced" strong tone={f.invoiced_above_approved ? 'text-[#b91c1c]' : ''} />}
                    <Line label="المحصَّل" k="collected" />
                    <Line label="الباقي على العميل" k="customer_outstanding" />
                </Card>
                <Card className="p-4 text-[13px]">
                    <h2 className="mb-2 text-[15px] font-semibold">{t('التكلفة')}</h2>
                    <Line label="سندات موردين معتمَدة" k="supplier_actual" />
                    <Line label="مصروفات مدفوعة" k="expenses_paid" />
                    <Line label="عتاد مستهلَك" k="stock_consumed" />
                    <Line label="التكلفة الفعلية" k="actual" strong />
                    <div className="my-2 border-t border-[#f4f4f5]" />
                    <Line label="أوامر شراء مفتوحة" k="open_purchase_orders" />
                    <Line label="سندات معلَّقة بلا أمر" k="pending_supplier_invoices" />
                    <Line label="مصروفات غير مدفوعة" k="expenses_unpaid" />
                    <Line label="الملتزَم" k="committed" strong tone={n('committed') > 0 ? 'text-[#b45309]' : ''} />
                    <div className="my-2 border-t border-[#f4f4f5]" />
                    <Line label="المستحقّ للموردين" k="supplier_outstanding" />
                    {/* والشرحُ تحت الأرقام لا فوقها */}
                    <p className="mt-2 text-[11.5px] text-[#9ca3af]">{t('ثلاثة أوعية لا تتداخل: ما صار تكلفةً في الدفتر، وما وُعد به ولم يصر قيدًا بعد، وما لم يُسدَّد من الأوّل.')}</p>
                </Card>
                <Card className="p-4 text-[13px]">
                    <h2 className="mb-2 text-[15px] font-semibold">{t('الربحية')}</h2>
                    <Line label="التكلفة المتوقَّعة عند الإتمام" k="expected_cost" />
                    <Line label="الربح المحقَّق" k="realized_gross_profit" strong tone={n('realized_gross_profit') < 0 ? 'text-[#b91c1c]' : 'text-[#047857]'} />
                    <div className="flex justify-between gap-3 py-1"><span className="text-[#6b7280]">{t('نسبة الربح المحقَّق')}</span><span className="tabular-nums">{number(n('realized_margin_percent'))}%</span></div>
                    <Line label="الهامش المتوقَّع" k="forecast_margin" strong tone={n('forecast_margin') < 0 ? 'text-[#b91c1c]' : 'text-[#047857]'} />
                    <div className="flex justify-between gap-3 py-1"><span className="text-[#6b7280]">{t('نسبة الهامش المتوقَّع')}</span><span className="tabular-nums">{number(n('forecast_margin_percent'))}%</span></div>
                    {frozen && (
                        <p className="mt-3 rounded-md bg-[#fafafa] px-3 py-2 text-[12px] text-[#6b7280]">
                            {t('لقطة الإقفال')}: {t('الربح المحقَّق')} {m(Number(frozen.realized_gross_profit ?? 0))} · {t('التكلفة الفعلية')} {m(Number(frozen.actual ?? 0))}
                        </p>
                    )}
                </Card>
            </div>
        </div>
    );
}

/* ─────────────────────────── التسليم ─────────────────────────── */

/**
 * التسليم — حزمُ المشروع وجاهزيّةُ إقفاله من جهتها.
 *
 * والموانعُ من الخادم (`DeliveryReadiness`) لا تُشتقّ هنا: ما يمنع الإقفالَ
 * يُقرأ بالنصّ نفسِه الذي يردّ به الإقفال.
 */
function DeliveryTab({ p, delivery, may, open }: { p: Project; delivery: Delivery; may: Props['may']; open: boolean }) {
    const t = useTranslate();

    return (
        <div>
            <TabHead
                facts={[
                    { label: 'الحزم', n: delivery.packages.length },
                    { label: 'المقبولة', n: delivery.readiness.accepted_count },
                    { label: 'بانتظار العميل', n: delivery.readiness.awaiting_count },
                ]}
                empty="لا حزم تسليم بعد"
                action={
                    open && may.delivery && (
                        <Button size="sm" asChild>
                            <SmartLink routeName="admin.deliveries.create" href={route('admin.deliveries.create', { project_id: p.id })}>
                                <PackageCheck />
                                {t('حزمة تسليم جديدة')}
                            </SmartLink>
                        </Button>
                    )
                }
            />
            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
                {delivery.packages.length > 0 && (
                    <Card className="p-4">
                        <ul className="divide-y divide-[#f3f4f6] text-[13px]">
                            {delivery.packages.map((d) => (
                                <li key={d.id} className="flex items-center justify-between gap-3 py-2">
                                    <div className="min-w-0">
                                        <SmartLink routeName="admin.deliveries.show" href={route('admin.deliveries.show', d.id)} className="font-medium text-[#1d4ed8]">
                                            {d.label}
                                        </SmartLink>
                                        <div className="truncate text-[12px] text-[#6b7280]">{d.title}</div>
                                    </div>
                                    <div className="shrink-0 text-end">
                                        <span className={'rounded-full px-2 py-1 text-[11px] ' + deliveryTone(d.status)}>
                                            {t(DELIVERY_STATE_LABEL[d.status] ?? d.status)}
                                        </span>
                                        <div className="text-[11px] text-[#9ca3af]" dir="ltr">{d.decided_at ?? d.sent_at ?? ''}</div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}

                <Card className={'p-4 ' + (delivery.packages.length === 0 ? 'lg:col-span-2' : '')}>
                    <h2 className="mb-2 text-[14px] font-semibold text-[#111]">{t('جاهزية الإتمام')}</h2>
                    {delivery.readiness.ready ? (
                        <p className="flex items-center gap-2 text-[13px] text-[#047857]">
                            <Check className="size-4" />
                            {t('التسليم مكتمل ومقبول — جاهز للفوترة النهائية.')}
                        </p>
                    ) : (
                        <ul className="list-disc space-y-1 ps-5 text-[13px] text-[#92400e]">
                            {delivery.readiness.blockers.map((b) => (
                                <li key={b}>{b}</li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </div>
    );
}

/* ─────────────────────────── الأداء ─────────────────────────── */

/**
 * تبويبُ الأداء — المرحلة 11.
 *
 * الصيغةُ واحدة (`ProjectProfitability` فوق `ProjectFinancials`): إيرادٌ صافٍ
 * وتكلفةٌ مباشرة ومساهمةٌ وهامش — والاكتمالُ مكتوبٌ بجانب الخلاصة لا مخفيٌّ
 * تحتها. وخطُّ الزمن الماليّ مطويٌّ تحت الأرقام.
 */
function PerformanceTab({ perf, m }: { perf: Performance; m: Money }) {
    const t = useTranslate();
    const Line = ({ label, v, strong, tone, hint }: { label: string; v: number | null; strong?: boolean; tone?: string; hint?: string }) => (
        <div className={'flex justify-between gap-3 py-1 ' + (strong ? 'font-semibold' : '')} title={hint}>
            <span className={strong ? '' : 'text-[#6b7280]'}>{t(label)}</span>
            <span className={'tabular-nums ' + (tone ?? '')} dir="ltr">{m(v)}</span>
        </div>
    );
    const KIND_LABEL: Record<string, string> = { quotation: 'عرض', change_order: 'أمر تغيير', invoice: 'فاتورة', collection: 'تحصيل', supplier_invoice: 'سند مورّد', expense: 'مصروف', stock: 'مخزون' };
    const KIND_TONE: Record<string, string> = { quotation: 'bg-[#eff6ff] text-[#1d4ed8]', change_order: 'bg-[#eff6ff] text-[#1d4ed8]', invoice: 'bg-[#f4f4f5] text-[#52525b]', collection: 'bg-[#ecfdf5] text-[#047857]', supplier_invoice: 'bg-[#fef2f2] text-[#b91c1c]', expense: 'bg-[#fef2f2] text-[#b91c1c]', stock: 'bg-[#fffbeb] text-[#b45309]' };

    return (
        <div className="space-y-4">
            <TabHead
                facts={[
                    { label: 'المساهمة المحقَّقة', value: m(perf.contribution), tone: perf.contribution < 0 ? 'danger' : 'ok' },
                    { label: 'الهامش', value: pct(perf.margin_percent) },
                ]}
                action={<CompletenessBadge completeness={perf.completeness} />}
            />
            <div className="grid gap-4 lg:grid-cols-3">
                <Card className="p-4 text-[13px]">
                    <h2 className="mb-2 text-[15px] font-semibold">{t('الإيراد')}</h2>
                    <Line label="القيمة المعتمَدة الأصلية" v={perf.baseline} />
                    <Line label="أوامر التغيير المقبولة" v={perf.change_orders_delta} />
                    <Line label="المتعاقَد" v={perf.contracted} strong />
                    <div className="my-2 border-t border-[#f4f4f5]" />
                    <Line label="المفوتَر" v={perf.invoiced} tone={perf.invoiced_above_approved ? 'text-[#b91c1c]' : ''} hint={perf.invoiced_above_approved ? t('فوق المعتمَد') : undefined} />
                    {perf.credited > 0 && <Line label="إشعارات دائن" v={perf.credited} />}
                    <Line label="الإيراد الصافي" v={perf.revenue} strong hint={t('صافٍ من الضريبة بعد إشعارات الدائن')} />
                    <Line label="المحصَّل" v={perf.collected} />
                    <Line label="الذمّة" v={perf.receivable} tone={perf.receivable > 0 ? 'text-[#b45309]' : ''} />
                </Card>
                <Card className="p-4 text-[13px]">
                    <h2 className="mb-2 text-[15px] font-semibold">{t('التكلفة المباشرة')}</h2>
                    <Line label="سندات موردين معتمَدة" v={perf.direct_costs.supplier_invoices} />
                    <Line label="مصروفات مدفوعة" v={perf.direct_costs.expenses} />
                    <Line label="مستهلَكات من الرفّ" v={perf.direct_costs.stock_consumed} hint={t('صافي ما صُرف للفعاليّة ناقصَ ما رُدّ — بتكلفته وقتَ الصرف')} />
                    <Line label="التكلفة الفعلية" v={perf.direct_costs.total} strong />
                    <div className="my-2 border-t border-[#f4f4f5]" />
                    <Line label="ملتزَمٌ لم يصر قيدًا" v={perf.committed} />
                    <Line label="التكلفة المتوقَّعة" v={perf.expected_cost} />
                </Card>
                <Card className="p-4 text-[13px]">
                    <h2 className="mb-2 text-[15px] font-semibold">{t('المساهمة')}</h2>
                    <Line label="المساهمة المحقَّقة" v={perf.contribution} strong tone={perf.contribution < 0 ? 'text-[#b91c1c]' : 'text-[#047857]'} />
                    <div className="flex justify-between gap-3 py-1"><span className="text-[#6b7280]">{t('الهامش')}</span><span className={'tabular-nums ' + marginTone(perf.margin_percent)} dir="ltr">{pct(perf.margin_percent)}</span></div>
                    <div className="my-2 border-t border-[#f4f4f5]" />
                    <Line label="المساهمة المتوقَّعة" v={perf.forecast_margin} hint={t('المتعاقَد − التكلفة المتوقَّعة')} />
                    <div className="flex justify-between gap-3 py-1"><span className="text-[#6b7280]">{t('الهامش المتوقَّع')}</span><span className="tabular-nums" dir="ltr">{pct(perf.forecast_margin_percent)}</span></div>
                    {perf.completeness.status !== 'complete' && <p className="mt-2 text-[11px] text-[#b45309]">{perf.completeness.reasons.join(' · ')}</p>}
                </Card>
            </div>
            {perf.basis && <p className="text-[11.5px] text-[#9ca3af]">{perf.basis}</p>}
            <MoreDetails label="خطّ الزمن الماليّ">
                <div className="overflow-x-auto">
                    <table className="w-full text-[12px]">
                        <thead className="text-[11px] text-[#6b7280]"><tr><th className="px-3 py-2 text-start">{t('التاريخ')}</th><th className="px-3 py-2 text-start">{t('الواقعة')}</th><th className="px-3 py-2 text-end">{t('المبلغ')}</th></tr></thead>
                        <tbody>
                            {perf.timeline.length === 0 && <tr><td colSpan={3} className="px-3 py-6 text-center text-[#9ca3af]">{t('لا وقائعَ ماليّة بعد')}</td></tr>}
                            {perf.timeline.map((row, i) => (
                                <tr key={i} className="border-t border-[#f4f4f5]">
                                    <td className="px-3 py-1.5" dir="ltr">{row.at || '—'}</td>
                                    <td className="px-3 py-1.5"><span className={'me-1 rounded-full px-1.5 text-[10px] ' + (KIND_TONE[row.kind] ?? 'bg-[#f4f4f5]')}>{t(KIND_LABEL[row.kind] ?? row.kind)}</span>{row.url ? <a href={row.url} className="hover:text-[#1d4ed8]">{row.label}</a> : row.label}</td>
                                    <td className={'px-3 py-1.5 text-end tabular-nums ' + (row.direction === 'out' ? 'text-[#b91c1c]' : row.direction === 'cash' ? 'text-[#047857]' : '')} dir="ltr">{row.direction === 'out' ? '−' : ''}{m(row.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </MoreDetails>
        </div>
    );
}
