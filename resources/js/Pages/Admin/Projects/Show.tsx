import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, CalendarCheck, Check, CheckCircle2, FileText, Lock, MoreHorizontal, PackageCheck, Pause, Play, Plus, Receipt, ShieldCheck, Slash, Smartphone, Trash2, Unlock, Wallet } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
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
interface Task { id: number; title: string; description: string | null; assignee_id: number | null; assignee: string | null; due_at: string | null; status: string; priority: string; completed_at: string | null }
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

/**
 * المشروعُ — نطاقُه ومتطلّباتُه وفريقُه ومهامُّه وأوامرُ تغييره ومالُه.
 *
 * ═══ ثلاثةُ أسئلةٍ يجيبها تبويبُ النطاق ═══
 *
 * ما وافق عليه العميل أصلًا (العرضُ ببرهانه)، وما تغيّر بعده (أوامرُ التغيير
 * المقبولة)، وما يجب على الفريق تنفيذَه الآن (الحاليّ). والقيمةُ الحاليّة
 * تُشتقّ في الخادم — انظر `ProjectScope`.
 */
export default function ProjectShow({ project: p, scope, requirements, members, tasks, change_orders, financials, delivery, cancellation, operations, performance, billing, invoices, close_blockers, workflow, quotation, scope_of_work, channels, users, may, contacts = [], purchases = null, inventory_forecast = null }: Props) {
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

    const tabs = [
        /* المسارُ أوّلًا: من عرض السعر إلى الإقفال، وكلُّ مرحلةٍ بزرّها */
        { key: 'process', label: 'المسار' },
        { key: 'overview', label: 'البيانات' },
        { key: 'scope', label: 'النطاق', count: scope.current.items.length },
        { key: 'requirements', label: 'المتطلّبات', count: requirements.filter((r) => r.active).length, alert: !p.baselined && open },
        { key: 'team', label: 'الفريق', count: members.length },
        { key: 'tasks', label: 'المهامّ', count: tasks.filter((x) => x.status !== 'done').length },
        { key: 'change_orders', label: 'أوامر التغيير', count: change_orders.length },
        { key: 'delivery', label: 'التسليم', count: delivery.packages.length, alert: open && !delivery.readiness.ready && delivery.packages.length > 0 },
        /* المراسلات — المرحلة 6: ما قيل للعميل وما جرى، بترتيبه */
        { key: 'communications', label: 'المراسلات' },
        /* العمليات — المرحلة 10: التنفيذُ على الأرض؛ لمن يفتح القسم */
        ...(operations !== null ? [{ key: 'operations', label: 'العمليات', count: operations.filter((o) => !['completed', 'cancelled'].includes(o.status)).length, alert: operations.some((o) => !o.ready && !['completed', 'cancelled', 'ready', 'live', 'teardown'].includes(o.status)) }] : []),
        /* المشتريات — المرحلة 14: تبويبٌ مساند لا مرحلة؛ أوامرُ الشراء الحقيقيّة على المشروع */
        ...(purchases ? [{ key: 'purchases', label: 'المشتريات', count: purchases.summary.orders, alert: purchases.summary.pending > 0 }] : []),
        ...(inventory_forecast ? [{ key: 'inventory', label: 'المخزون', count: inventory_forecast.shortages, alert: inventory_forecast.shortages > 0 }] : []),
        ...(may.budget ? [{ key: 'financials', label: 'المال' }] : []),
        /* الأداء — المرحلة 11 */
        ...(performance !== null ? [{ key: 'performance', label: 'الأداء', alert: performance.contribution < 0 }] : []),
    ];
    /* وتبويبٌ في الرابط لا يملكه الناظر (المالُ مثلًا) يرجع إلى النظرة العامّة */
    const current = tabs.some((x) => x.key === tab) ? tab : 'process';

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

    return (
        <AdminLayout title={p.code}>
            <BackLink routeName="admin.projects.index" href={route('admin.projects.index')} label="المشاريع والمعارض" />

            <PageHeader
                title={p.name}
                subtitle={`${p.code} · ${p.customer}`}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
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
                        {/* وبقيّةُ الأفعال في قائمةٍ واحدة — لا صفَّ أزرارٍ يتنافس على العين */}
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="outline" size="icon" aria-label={t('خيارات')}>
                                    <MoreHorizontal />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {open && may.change_order && (
                                    <DropdownMenuItem onSelect={() => router.visit(route('admin.changeOrders.create', p.id))}>
                                        <Plus />
                                        {t('أمر تغيير')}
                                    </DropdownMenuItem>
                                )}
                                {open && !p.baselined && may.manage && (
                                    <DropdownMenuItem onSelect={() => setBaselining(true)}>
                                        <Lock />
                                        {t('تثبيت المتطلّبات')}
                                    </DropdownMenuItem>
                                )}
                                {(p.status === 'confirmed' || p.status === 'on_hold') && may.manage && (
                                    <DropdownMenuItem onSelect={() => post('admin.projects.transition', { status: 'in_progress' })}>
                                        <Play />
                                        {t('بدء التنفيذ')}
                                    </DropdownMenuItem>
                                )}
                                {p.status === 'in_progress' && may.manage && (
                                    <DropdownMenuItem onSelect={() => post('admin.projects.transition', { status: 'on_hold' })}>
                                        <Pause />
                                        {t('تأجيل')}
                                    </DropdownMenuItem>
                                )}
                                {open && may.close && (
                                    <DropdownMenuItem onSelect={() => post('admin.projects.close')}>
                                        <Lock />
                                        {t('إقفال')}
                                    </DropdownMenuItem>
                                )}
                                {p.status === 'closed' && may.reopen && (
                                    <DropdownMenuItem onSelect={() => post('admin.projects.reopen')}>
                                        <Unlock />
                                        {t('إعادة فتح')}
                                    </DropdownMenuItem>
                                )}
                                {open && may.manage && (
                                    <DropdownMenuItem onSelect={() => setCancelling(true)} className="text-[#b91c1c]">
                                        <Slash />
                                        {t('إلغاء المشروع')}
                                    </DropdownMenuItem>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                }
            />

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
            {open && !p.baselined && at === 'scope' && (
                <Alert tone="warning">{t('المتطلّبات لم تُثبَّت بعد — تُثبَّت حين يُرسَل نطاق العمل للتوقيع، وبعدها لا تتغيّر إلّا بأمر تغيير يقبله العميل.')}</Alert>
            )}
            {financials && financials.invoiced_above_approved === true && (
                <Alert tone="danger">{t('المفوتَر على هذا المشروع فوق قيمته المعتمَدة الحاليّة — راجع الحاجة إلى إشعار دائن.')}</Alert>
            )}

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <span className={'rounded-full px-2 py-1 text-[12px] ' + projectTone(p.status)}>{t(PROJECT_STATUS_LABEL[p.status] ?? p.status)}</span>
                <span className="text-[15px] font-semibold tabular-nums">{m(p.current_value)}</span>
                {p.current_value !== null && p.approved_value !== null && Math.abs(p.current_value - p.approved_value) > 0.0005 && (
                    <span className="text-[12px] text-[#9ca3af]">
                        {t('الأصل')}: {m(p.approved_value)}
                    </span>
                )}
                {p.baselined && (
                    <span className="inline-flex items-center gap-1 rounded-full bg-[#ecfdf5] px-2 py-1 text-[11px] text-[#065f46]">
                        <ShieldCheck className="size-3" />
                        {t('متطلّبات مثبَّتة')} · {p.requirements_baselined_at}
                    </span>
                )}
            </div>

            <ProjectStepper stages={stages} onPick={pick} size="lg" className="mb-4" />

            {/* ونقصُ المخزون المتوقَّع يُقال فوق التبويبات — والتفصيلُ في تبويب «المخزون» */}
            {inventory_forecast && inventory_forecast.shortages > 0 && current !== 'inventory' && (
                <button type="button" onClick={() => setTab('inventory')} className="mb-3 flex w-full items-center gap-2 rounded-[12px] border border-[#fecaca] bg-[#fef2f2] px-4 py-2 text-start text-[13px] text-[#b91c1c]">
                    {t('نقص مخزون: :n بنود', { n: String(inventory_forecast.shortages) })} — {t('افتح تبويب المخزون')}
                </button>
            )}

            <Tabs tabs={tabs} current={current} onChange={setTab} className="mb-4" />

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

            {current === 'overview' && <Overview p={p} users={users} may={may} scope={scope} contacts={contacts} />}
            {current === 'scope' && <ScopeTab scope={scope} m={m} />}
            {current === 'requirements' && <RequirementsTab p={p} requirements={requirements} items={scope.current.items} may={may} open={open} />}
            {current === 'team' && <TeamTab p={p} members={members} users={users} may={may} open={open} />}
            {current === 'tasks' && <TasksTab p={p} tasks={tasks} users={users} may={may} />}
            {current === 'change_orders' && <ChangeOrdersTab p={p} rows={change_orders} m={m} may={may} open={open} />}
            {current === 'delivery' && <DeliveryTab p={p} delivery={delivery} may={may} open={open} />}
            {current === 'communications' && <CommunicationsTab projectId={p.id} open={open} />}
            {current === 'operations' && operations !== null && <OperationsTab p={p} rows={operations} may={may} open={open} />}
            {current === 'inventory' && inventory_forecast && (
                <Card className="p-4">
                    <h2 className="mb-1 text-[15px] font-semibold">{t('توقّع المخزون')}</h2>
                    <p className="mb-3 text-[12px] text-[#9ca3af]">{t('لكل منتج مخزّن: المتوفر الآن، وما تحتاجه مشاريع قبله في الجدول، واحتياج هذا المشروع، والمتوقع بعده.')}</p>
                    <ProjectInventoryForecast data={inventory_forecast} projectId={p.id} />
                </Card>
            )}
            {current === 'purchases' && purchases && <PurchasesTab projectId={p.id} data={purchases} m={m} />}
            {current === 'financials' && financials && <FinancialsTab f={financials} m={m} frozen={p.financials_frozen} />}
            {current === 'performance' && performance !== null && <PerformanceTab perf={performance} m={m} />}

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

function Alert({ tone, children }: { tone: 'danger' | 'warning'; children: React.ReactNode }) {
    return (
        <div className={'mb-4 rounded-lg px-4 py-3 text-[13px] ' + (tone === 'danger' ? 'bg-[#fef2f2] text-[#b91c1c]' : 'bg-[#fffbeb] text-[#b45309]')}>
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

function Overview({ p, users, may, scope, contacts }: { p: Project; users: { id: number; name: string }[]; may: Props['may']; scope: Scope; contacts: CustomerContact[] }) {
    const t = useTranslate();
    const [editing, setEditing] = useState(false);
    const [form, setForm] = useState({
        name: p.name, event_type: p.event_type ?? '', location: p.location ?? '',
        contact_person: p.contact_person ?? '', contact_phone: p.contact_phone ?? '', contact_email: p.contact_email ?? '',
        customer_contact_id: p.customer_contact_id ? String(p.customer_contact_id) : '',
        owner_id: p.owner_id ? String(p.owner_id) : '', notes: p.notes ?? '', internal_notes: p.internal_notes ?? '',
    });
    const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [k]: e.target.value });

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <Card className="p-4">
                <div className="mb-3 flex items-center justify-between">
                    <h2 className="text-[15px] font-semibold">{t('الفعالية')}</h2>
                    {may.manage && p.status !== 'closed' && p.status !== 'cancelled' && (
                        <Button variant="outline" size="sm" onClick={() => setEditing(!editing)}>{editing ? t('تراجع') : t('تعديل')}</Button>
                    )}
                </div>
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
    );
}

/* ─────────────────────────── النطاق ─────────────────────────── */

function ScopeTab({ scope, m }: { scope: Scope; m: (v: number | null | undefined) => string }) {
    const t = useTranslate();
    const q = (v: number) => number(v);

    return (
        <div className="space-y-4">
            <Card className="p-4">
                <h2 className="mb-1 text-[15px] font-semibold">{t('النطاق الحالي')}</h2>
                <p className="mb-3 text-[12px] text-[#9ca3af]">{t('ما يجب على الفريق تنفيذه الآن — الأصل بعد أوامر التغيير المقبولة.')}</p>
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

            <div className="grid gap-4 lg:grid-cols-2">
                <Card className="p-4">
                    <h2 className="mb-1 text-[15px] font-semibold">{t('الأصل')}</h2>
                    <p className="mb-3 text-[12px] text-[#9ca3af]">{t('كما قُبل في العرض — لا يُكتب فيه بعدها.')}</p>
                    <ul className="space-y-1 text-[13px]">
                        {scope.original.items.map((i) => (
                            <li key={i.id} className="flex justify-between gap-3">
                                <span>{i.description}</span>
                                <span className="tabular-nums text-[#6b7280]">{q(i.quantity)} {i.unit ?? ''} · {m(i.line_total)}</span>
                            </li>
                        ))}
                    </ul>
                    <div className="mt-3 text-[13px] font-semibold tabular-nums">{t('القيمة الأصلية')}: {m(scope.original.approved_value)}</div>
                    {scope.original.requirements.length > 0 && (
                        <div className="mt-3 text-[12px] text-[#6b7280]">
                            {t('متطلّبات الأصل')}: {scope.original.requirements.map((r) => r.title).join(' · ')}
                        </div>
                    )}
                </Card>

                <Card className="p-4">
                    <h2 className="mb-1 text-[15px] font-semibold">{t('ما تغيّر')}</h2>
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
                </Card>
            </div>
        </div>
    );
}

/* ─────────────────────────── المتطلّبات ─────────────────────────── */

function RequirementsTab({ p, requirements, items, may, open }: { p: Project; requirements: Requirement[]; items: ScopeItem[]; may: Props['may']; open: boolean }) {
    const t = useTranslate();
    const editable = may.manage && open && !p.baselined;
    const [form, setForm] = useState({ title: '', details: '', project_item_id: '' });
    const [editingId, setEditingId] = useState<number | null>(null);

    const submit = () => {
        if (editingId) {
            router.put(route('admin.projects.requirements.update', [p.id, editingId]), form, { preserveScroll: true, onSuccess: () => { setEditingId(null); setForm({ title: '', details: '', project_item_id: '' }); } });
        } else {
            router.post(route('admin.projects.requirements.store', p.id), form, { preserveScroll: true, onSuccess: () => setForm({ title: '', details: '', project_item_id: '' }) });
        }
    };

    return (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
            <Card className="p-4">
                <h2 className="mb-1 text-[15px] font-semibold">{t('المتطلّبات')}</h2>
                <p className="mb-3 text-[12px] text-[#9ca3af]">{t('شروطٌ يجب أن تصدق عند التسليم — بلا سعر ولا كميّة. وما أُزيل يبقى مكتوبًا مع الأمر الذي أزاله.')}</p>
                {requirements.length === 0 ? (
                    <p className="text-[13px] text-[#9ca3af]">{t('لا متطلّبات بعد')}</p>
                ) : (
                    <ul className="space-y-2 text-[13px]">
                        {requirements.map((r) => (
                            <li key={r.id} className={'rounded-lg border p-3 ' + (r.active ? 'border-[#f4f4f5]' : 'border-dashed border-[#e8e8e8] text-[#9ca3af]')}>
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
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
                                            <Button variant="outline" size="sm" onClick={() => { setEditingId(r.id); setForm({ title: r.title, details: r.details ?? '', project_item_id: r.project_item_id ? String(r.project_item_id) : '' }); }}>{t('تعديل')}</Button>
                                            <Button variant="outline" size="sm" onClick={() => router.delete(route('admin.projects.requirements.destroy', [p.id, r.id]), { preserveScroll: true })}><Trash2 /></Button>
                                        </div>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Card className="p-4">
                {editable ? (
                    <>
                        <h2 className="mb-3 text-[15px] font-semibold">{editingId ? t('تعديل متطلّب') : t('متطلّب جديد')}</h2>
                        <div className="space-y-3">
                            <Field label="المتطلّب" required><Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} /></Field>
                            <Field label="التفاصيل"><Textarea rows={3} value={form.details} onChange={(e) => setForm({ ...form, details: e.target.value })} /></Field>
                            <Field label="على بند" hint="اختياري — شرطٌ على بندٍ بعينه">
                                <Select placeholder="المشروع كلّه" value={form.project_item_id} onChange={(e) => setForm({ ...form, project_item_id: e.target.value })} options={items.map((i) => ({ label: i.description, value: i.id }))} />
                            </Field>
                            <div className="flex gap-2">
                                <Button disabled={form.title.trim() === ''} onClick={submit}><Plus />{editingId ? t('حفظ') : t('إضافة')}</Button>
                                {editingId && <Button variant="outline" onClick={() => { setEditingId(null); setForm({ title: '', details: '', project_item_id: '' }); }}>{t('تراجع')}</Button>}
                            </div>
                        </div>
                    </>
                ) : (
                    <p className="text-[13px] text-[#6b7280]">
                        {p.baselined
                            ? t('ثُبّتت المتطلّبات في :at — والتغيير بأمر تغيير يقبله العميل.', { at: p.requirements_baselined_at ?? '' })
                            : t('لا تملك تعديل المتطلّبات.')}
                    </p>
                )}
            </Card>
        </div>
    );
}

/* ─────────────────────────── الفريق ─────────────────────────── */

function TeamTab({ p, members, users, may, open }: { p: Project; members: Member[]; users: { id: number; name: string }[]; may: Props['may']; open: boolean }) {
    const t = useTranslate();
    const [userId, setUserId] = useState('');
    const [role, setRole] = useState('');

    return (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
            <Card className="p-4">
                <h2 className="mb-3 text-[15px] font-semibold">{t('الفريق')}</h2>
                {members.length === 0 ? <p className="text-[13px] text-[#9ca3af]">{t('لا أعضاء بعد')}</p> : (
                    <ul className="divide-y divide-[#f4f4f5] text-[13px]">
                        {members.map((mb) => (
                            <li key={mb.id} className="flex items-center justify-between py-2">
                                <span><span className="font-medium">{mb.name}</span>{mb.role && <span className="text-[#9ca3af]"> — {mb.role}</span>}</span>
                                {may.manage && open && <Button variant="outline" size="sm" onClick={() => router.delete(route('admin.projects.members.destroy', [p.id, mb.id]), { preserveScroll: true })}><Trash2 /></Button>}
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
            {may.manage && open && (
                <Card className="p-4">
                    <h2 className="mb-3 text-[15px] font-semibold">{t('إضافة عضو')}</h2>
                    <div className="space-y-3">
                        <Field label="الموظّف" required>
                            <Select placeholder="اختر" value={userId} onChange={(e) => setUserId(e.target.value)} options={users.filter((u) => !members.some((mb) => mb.user_id === u.id)).map((u) => ({ label: u.name, value: u.id }))} />
                        </Field>
                        <Field label="دوره في المشروع"><Input value={role} onChange={(e) => setRole(e.target.value)} placeholder="منسّق ميداني" /></Field>
                        <Button disabled={userId === ''} onClick={() => router.post(route('admin.projects.members.store', p.id), { user_id: userId, role }, { preserveScroll: true, onSuccess: () => { setUserId(''); setRole(''); } })}><Plus />{t('إضافة')}</Button>
                    </div>
                </Card>
            )}
        </div>
    );
}

/* ─────────────────────────── المهامّ ─────────────────────────── */

function TasksTab({ p, tasks, users, may }: { p: Project; tasks: Task[]; users: { id: number; name: string }[]; may: Props['may'] }) {
    const t = useTranslate();
    const [form, setForm] = useState({ title: '', description: '', assignee_id: '', due_at: '', priority: 'normal' });

    return (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
            <Card className="p-4">
                <h2 className="mb-1 text-[15px] font-semibold">{t('المهامّ')}</h2>
                <p className="mb-3 text-[12px] text-[#9ca3af]">{t('عملٌ داخلي لشخص وموعد — ليس نطاقًا يوافق عليه العميل.')}</p>
                {tasks.length === 0 ? <p className="text-[13px] text-[#9ca3af]">{t('لا مهامّ بعد')}</p> : (
                    <ul className="divide-y divide-[#f4f4f5] text-[13px]">
                        {tasks.map((task) => (
                            <li key={task.id} className="flex items-start justify-between gap-3 py-2">
                                <div className={task.status === 'done' ? 'text-[#9ca3af] line-through' : ''}>
                                    <div className="font-medium">{task.title}{task.priority === 'high' && <span className="ms-2 rounded-full bg-[#fef2f2] px-2 py-0.5 text-[11px] text-[#b91c1c] no-underline">{t('عاجل')}</span>}</div>
                                    <div className="text-[12px] text-[#6b7280]">{task.assignee ?? t('بلا منفّذ')}{task.due_at && <span dir="ltr"> · {task.due_at}</span>}</div>
                                    {task.description && <div className="mt-0.5 text-[12px] text-[#6b7280]">{task.description}</div>}
                                </div>
                                {may.manage && (
                                    <div className="flex gap-1">
                                        <Button variant="outline" size="sm" onClick={() => router.put(route('admin.projects.tasks.update', [p.id, task.id]), { title: task.title, status: task.status === 'done' ? 'open' : 'done' }, { preserveScroll: true })}>
                                            <Check />{task.status === 'done' ? t('إعادة فتح') : t('تمّ')}
                                        </Button>
                                        <Button variant="outline" size="sm" onClick={() => router.delete(route('admin.projects.tasks.destroy', [p.id, task.id]), { preserveScroll: true })}><Trash2 /></Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
            {may.manage && (
                <Card className="p-4">
                    <h2 className="mb-3 text-[15px] font-semibold">{t('مهمّة جديدة')}</h2>
                    <div className="space-y-3">
                        <Field label="المهمّة" required><Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} /></Field>
                        <Field label="التفاصيل"><Textarea rows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></Field>
                        <Field label="المنفّذ"><Select placeholder="بلا منفّذ" value={form.assignee_id} onChange={(e) => setForm({ ...form, assignee_id: e.target.value })} options={users.map((u) => ({ label: u.name, value: u.id }))} /></Field>
                        <Field label="الموعد"><Input type="date" dir="ltr" value={form.due_at} onChange={(e) => setForm({ ...form, due_at: e.target.value })} /></Field>
                        <Field label="الأولوية"><Select value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })} options={[{ label: 'منخفضة', value: 'low' }, { label: 'عادية', value: 'normal' }, { label: 'عاجلة', value: 'high' }]} /></Field>
                        <Button disabled={form.title.trim() === ''} onClick={() => router.post(route('admin.projects.tasks.store', p.id), form, { preserveScroll: true, onSuccess: () => setForm({ title: '', description: '', assignee_id: '', due_at: '', priority: 'normal' }) })}><Plus />{t('إضافة')}</Button>
                    </div>
                </Card>
            )}
        </div>
    );
}

/* ─────────────────────────── أوامر التغيير ─────────────────────────── */

function ChangeOrdersTab({ p, rows, m, may, open }: { p: Project; rows: ChangeOrderRow[]; m: (v: number | null | undefined) => string; may: Props['may']; open: boolean }) {
    const t = useTranslate();

    return (
        <Card className="p-4">
            <div className="mb-3 flex items-center justify-between">
                <h2 className="text-[15px] font-semibold">{t('أوامر التغيير')}</h2>
                {open && may.change_order && (
                    <Button size="sm" asChild>
                        <SmartLink routeName="admin.changeOrders.create" href={route('admin.changeOrders.create', p.id)}><Plus />{t('أمر تغيير')}</SmartLink>
                    </Button>
                )}
            </div>
            {rows.length === 0 ? <p className="text-[13px] text-[#9ca3af]">{t('لا أوامر تغيير — النطاق كما قُبل.')}</p> : (
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
            )}
        </Card>
    );
}

/* ─────────────────────────── المال ─────────────────────────── */

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
            {may.operations_manage && open && (
                <div className="flex justify-end">
                    <Button onClick={() => router.post(route('admin.operations.store'), { project_id: p.id })}>
                        <Plus />
                        {t('فعاليّة جديدة')}
                    </Button>
                </div>
            )}
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

function FinancialsTab({ f, m, frozen }: { f: Record<string, number | boolean>; m: (v: number | null | undefined) => string; frozen: Record<string, number> | null }) {
    const t = useTranslate();
    const n = (k: string) => Number(f[k] ?? 0);
    const Line = ({ label, k, strong, tone }: { label: string; k: string; strong?: boolean; tone?: string }) => (
        <div className={'flex justify-between gap-3 py-1 ' + (strong ? 'font-semibold' : '')}>
            <span className={strong ? '' : 'text-[#6b7280]'}>{t(label)}</span>
            <span className={'tabular-nums ' + (tone ?? '')}>{m(n(k))}</span>
        </div>
    );

    return (
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
                <p className="mb-2 text-[12px] text-[#9ca3af]">{t('ثلاثة أوعية لا تتداخل: ما صار تكلفةً في الدفتر، وما وُعد به ولم يصر قيدًا بعد، وما لم يُسدَّد من الأوّل.')}</p>
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
    );
}

/**
 * التسليم — حزمُ المشروع وجاهزيّةُ إقفاله من جهتها.
 *
 * والموانعُ من الخادم (`DeliveryReadiness`) لا تُشتقّ هنا: ما يمنع الإقفالَ
 * يُقرأ بالنصّ نفسِه الذي يردّ به الإقفال.
 */
function DeliveryTab({ p, delivery, may, open }: { p: Project; delivery: Delivery; may: Props['may']; open: boolean }) {
    const t = useTranslate();

    return (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
            <Card className="p-4">
                <div className="mb-3 flex items-center justify-between gap-2">
                    <h2 className="font-bold text-[#111]">{t('حزم التسليم')}</h2>
                    {open && may.delivery && (
                        <Button size="sm" asChild>
                            <SmartLink routeName="admin.deliveries.create" href={route('admin.deliveries.create', { project_id: p.id })}>
                                <PackageCheck />
                                {t('حزمة تسليم جديدة')}
                            </SmartLink>
                        </Button>
                    )}
                </div>
                {delivery.packages.length === 0 ? (
                    <p className="text-[13px] text-[#9ca3af]">{t('لا حزم تسليم بعد')}</p>
                ) : (
                    <ul className="divide-y divide-[#f3f4f6] text-[13px]">
                        {delivery.packages.map((d) => (
                            <li key={d.id} className="flex items-center justify-between gap-3 py-2">
                                <div className="min-w-0">
                                    <SmartLink routeName="admin.deliveries.show" href={route('admin.deliveries.show', d.id)} className="font-medium text-[#1d4ed8]">
                                        {d.label}
                                    </SmartLink>
                                    <div className="truncate text-[12px] text-[#6b7280]">{d.title}</div>
                                </div>
                                <div className="text-end">
                                    <span className={'rounded-full px-2 py-1 text-[11px] ' + deliveryTone(d.status)}>
                                        {t(DELIVERY_STATE_LABEL[d.status] ?? d.status)}
                                    </span>
                                    <div className="text-[11px] text-[#9ca3af]" dir="ltr">{d.decided_at ?? d.sent_at ?? ''}</div>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Card className="p-4">
                <h2 className="mb-2 font-bold text-[#111]">{t('جاهزية الإتمام')}</h2>
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
    );
}

/**
 * تبويبُ الأداء — المرحلة 11.
 *
 * الصيغةُ واحدة (`ProjectProfitability` فوق `ProjectFinancials`): إيرادٌ صافٍ
 * وتكلفةٌ مباشرة ومساهمةٌ وهامش — والاكتمالُ مكتوبٌ فوقها لا مخفيٌّ تحتها.
 */
function PerformanceTab({ perf, m }: { perf: Performance; m: (v: number | null | undefined) => string }) {
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
            <div className="flex flex-wrap items-center gap-2">
                <CompletenessBadge completeness={perf.completeness} />
                <span className="text-[12px] text-[#9ca3af]">{perf.basis}</span>
            </div>
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
            <Card className="overflow-hidden">
                <h2 className="px-4 pt-3 text-[15px] font-semibold">{t('خطّ الزمن الماليّ')}</h2>
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
            </Card>
        </div>
    );
}
