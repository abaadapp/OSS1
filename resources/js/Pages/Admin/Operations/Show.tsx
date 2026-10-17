import { useState, type ReactNode } from 'react';
import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, Bell, Check, CheckCircle2, Flag, MapPin, Pencil, Plus, Radio, ShieldAlert, Slash, Smartphone, Trash2, Undo2, XCircle } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import Tabs from '@/Components/Tabs';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import ProjectInventoryForecast, { type ProjectForecast } from '@/Components/InventoryForecast';
import OrganizersTab, { type OperationOrganizerRow, type OrganizerOption } from './partials/OrganizersTab';
import EventTasksTab, { type EventTasks } from './partials/EventTasksTab';
import {
    CHECKLIST_STATUS_LABEL,
    RESERVATION_LABEL,
    checklistTone,
    crewTone,
    incidentTone,
    label,
    operationTone,
    reservationTone,
    scheduleTone,
    severityTone,
    type Labels,
} from '@/lib/operations';

/* ─────────────────────────── الأنواع ─────────────────────────── */

interface Operation {
    id: number;
    code: string;
    name: string;
    status: string;
    project: { id: number; code: string; name: string } | null;
    customer: string | null;
    project_status: string | null;
    venue_name: string | null;
    venue_address: string | null;
    venue_notes: string | null;
    setup_starts_at: string | null;
    doors_open_at: string | null;
    event_starts_at: string | null;
    event_ends_at: string | null;
    teardown_starts_at: string | null;
    teardown_ends_at: string | null;
    timezone: string;
    lead: string | null;
    operations_lead_id: number | null;
    organizers_lead_user_id?: number | null;
    notes: string | null;
    readiness_override: { to: string; reason: string; by: string | null; at: string; blockers: string[] }[] | null;
    went_live_at: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    created_by: string | null;
    active: boolean;
}

interface Readiness {
    phase: string;
    ready: boolean;
    blockers: { key: string; message: string }[];
    warnings: { key: string; message: string }[];
    checks: { key: string; label: string; ok: boolean; severity: string; detail: string | null }[];
}

interface Conflict {
    severity: string;
    kind: string;
    message: string;
}

interface ScheduleItem {
    id: number;
    title: string;
    description: string | null;
    type: string;
    status: string;
    starts_at: string | null;
    ends_at: string | null;
    responsible_user_id: number | null;
    responsible: string | null;
    notes: string | null;
}

interface Requirement {
    id: number;
    kind: string;
    asset_category: string | null;
    product_id: number | null;
    product: string | null;
    description: string;
    required_quantity: number;
    assigned_quantity: number;
    shortfall: number;
    source: string;
    critical: boolean;
    backup: boolean;
    notes: string | null;
    issued_quantity: number;
    returned_quantity: number;
}

/* المستهلَكات — المرحلة 11: صرفٌ من الرفّ على الفعاليّة وردٌّ لما لم يُستهلك */
interface ConsumableIssue {
    id: number;
    /** فرعُ الصرف من فروع عمل المستخدم — وإلّا لا زرَّ إرجاع (انظر Consumables::summary) */
    returnable?: boolean;
    product_id: number;
    product: string | null;
    requirement: string | null;
    issued: number;
    returned: number;
    consumed: number;
    unit_cost: number;
    consumed_cost: number;
    status: string;
    issued_at: string | null;
    returned_at: string | null;
    notes: string | null;
}

interface Reservation {
    id: number;
    asset_id: number;
    asset_code: string | null;
    asset_name: string | null;
    asset_category: string | null;
    asset_status: string | null;
    requirement_id: number | null;
    requirement: string | null;
    status: string;
    reserved_from: string | null;
    reserved_until: string | null;
    checked_out_at: string | null;
    custodian: string | null;
    returned_at: string | null;
    return_condition: string | null;
    missing_accessories: string | null;
    damage_notes: string | null;
}

interface CrewMember {
    id: number;
    user_id: number | null;
    name: string;
    external: boolean;
    phone: string | null;
    role: string;
    status: string;
    briefing_status: string;
    starts_at: string | null;
    ends_at: string | null;
    checked_in_at: string | null;
    checked_out_at: string | null;
    notified_at: string | null;
    notes: string | null;
}

interface ChecklistItem {
    id: number;
    title: string;
    required: boolean;
    status: string;
    assignee: string | null;
    due_at: string | null;
    completed_at: string | null;
    evidence_note: string | null;
    blocked_reason: string | null;
    waived_reason: string | null;
}

interface Checklist {
    id: number;
    name: string;
    phase: string;
    items: ChecklistItem[];
}

interface Incident {
    id: number;
    title: string;
    category: string;
    severity: string;
    status: string;
    description: string | null;
    occurred_at: string | null;
    reported_by: string | null;
    assigned_to: number | null;
    assignee: string | null;
    resolved_at: string | null;
    resolution: string | null;
    carried_forward: boolean;
    asset: string | null;
}

interface AssetOption {
    id: number;
    code: string;
    name: string;
    category: string;
    status: string;
    free: boolean;
}

interface Props {
    operation: Operation;
    readiness: Readiness;
    completion_blockers: string[];
    conflicts: Conflict[];
    transitions: string[];
    schedule: ScheduleItem[];
    requirements: Requirement[];
    reservations: Reservation[];
    crew: CrewMember[];
    attendance: { expected: number; checked_in: number; checked_out: number; missing: number; no_show: number };
    checklists: Checklist[];
    incidents: Incident[];
    /* المرحلة 14: المنظّمون على الفعاليّة ومن يُختار منه */
    organizers?: OperationOrganizerRow[];
    organizer_options?: OrganizerOption[];
    event_tasks?: EventTasks;
    users: { id: number; name: string }[];
    project_members: { user_id: number; name: string | null; role: string | null }[];
    templates: { id: number; name: string; phase: string }[];
    products: { id: number; name: string; kind: string; quantity: number }[];
    consumables: { rows: ConsumableIssue[]; issued_cost: number; consumed_cost: number; returned_cost: number; open: number };
    /** توقّعُ مخزون مشروعها — قراءةٌ لا خصم، لمن يفتح المخزون */
    inventory_forecast?: ProjectForecast | null;
    categories: { value: string; label: string }[];
    assets: AssetOption[];
    labels: Labels;
    may: Record<string, boolean>;
}

/**
 * شاشةُ الفعاليّة — كلُّ ما يخصّ تنفيذها في تبويبات، والجاهزيّةُ في الصدر.
 *
 * ما يُكتب هنا يمرّ بخدمته: نقلُ الحال بـ`EventOperations::transition` الذي
 * يسأل الجاهزيّة، والحجزُ بـ`Reservations::reserve` الذي يقفل القطعة. والشاشةُ
 * تعرض وتطلب ولا تقرّر.
 */
export default function OperationShow(p: Props) {
    const t = useTranslate();
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const [ask, dialog] = useConfirm();
    const o = p.operation;
    const L = p.labels;
    /* والتبويبُ من الرابط إن ذُكر — رابطُ التنبيه «مهام الفعاليّة» يفتح تبويبَها */
    const [tab, setTab] = useState(() => (typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('tab')) ?? 'overview');
    const [overriding, setOverriding] = useState<string | null>(null);
    const [overrideReason, setOverrideReason] = useState('');
    const [cancelling, setCancelling] = useState(false);
    const [cancelReason, setCancelReason] = useState('');

    const post = (name: string, data: Record<string, string | number | boolean | null | undefined> = {}, params: (number | string)[] = []) =>
        router.post(route(name, [o.id, ...params]), data, { preserveScroll: true });

    const transition = (status: string) => {
        const gated = status === 'ready' || status === 'live';
        if (gated && !p.readiness.ready) {
            if (p.may.override) {
                setOverriding(status);
                setOverrideReason('');
            } else {
                post('admin.operations.transition', { status });
            }

            return;
        }
        post('admin.operations.transition', { status });
    };

    const openIncidents = p.incidents.filter((i) => i.status === 'open' || i.status === 'investigating').length;
    const requiredPending = p.checklists.reduce((n, c) => n + c.items.filter((i) => i.required && i.status !== 'done' && i.status !== 'waived').length, 0);
    const criticalConflicts = p.conflicts.filter((c) => c.severity === 'critical').length;

    const tabs = [
        { key: 'overview', label: 'نظرة عامة', alert: !p.readiness.ready && o.active },
        { key: 'schedule', label: 'الجدول', count: p.schedule.length },
        { key: 'equipment', label: 'المعدّات', count: p.reservations.filter((r) => r.status !== 'cancelled').length, alert: criticalConflicts > 0 },
        { key: 'crew', label: 'الفريق', count: p.crew.filter((c) => c.status !== 'cancelled').length },
        { key: 'organizers', label: 'المنظّمون', count: (p.organizers ?? []).filter((o) => o.status !== 'cancelled').length, alert: p.conflicts.some((c) => c.kind.startsWith('organizer_')) },
        { key: 'tasks', label: 'المهام', count: (p.event_tasks?.people ?? []).reduce((n, x) => n + x.open, 0), alert: (p.event_tasks?.people ?? []).some((x) => x.last_dispatch?.status === 'failed') },
        { key: 'checklists', label: 'قوائم التحقّق', count: requiredPending, alert: requiredPending > 0 && o.active },
        { key: 'incidents', label: 'الحوادث', count: openIncidents, alert: openIncidents > 0 },
        { key: 'history', label: 'السجلّ' },
    ];

    return (
        <AdminLayout title={o.code}>
            <BackLink routeName="admin.operations.index" href={route('admin.operations.index')} label="العمليات" />

            <PageHeader
                title={o.code}
                subtitle={`${o.name}${o.project ? ` — ${o.project.code}` : ''}${o.customer ? ` · ${o.customer}` : ''}`}
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <SmartLink routeName="admin.operations.onsite" href={route('admin.operations.onsite', o.id)}>
                                <Smartphone />
                                {t('شاشة الموقع')}
                            </SmartLink>
                        </Button>
                        {p.may.status && p.transitions.filter((s) => s !== 'cancelled' && s !== 'completed').map((s) => (
                            <Button key={s} variant={s === 'live' ? 'success' : 'outline'} onClick={() => transition(s)}>
                                {s === 'live' ? <Radio /> : s === 'ready' ? <CheckCircle2 /> : <Flag />}
                                {label(L, 'status', s)}
                            </Button>
                        ))}
                        {p.may.status && p.transitions.includes('completed') && (
                            <Button
                                onClick={async () => {
                                    if (p.completion_blockers.length > 0) {
                                        post('admin.operations.complete');

                                        return;
                                    }
                                    if (!(await ask({ title: t('إتمام الفعاليّة'), message: t('تُختم الفعاليّة: المعدّات عادت، والحوادث الحرجة حُلّت، وقائمة الإقفال أُنجزت. ولا يُقفل المشروع.'), action: t('إتمام') }))) return;
                                    post('admin.operations.complete');
                                }}
                            >
                                <Check />
                                {t('إتمام')}
                            </Button>
                        )}
                        {p.may.manage && p.transitions.includes('cancelled') && (
                            <Button variant="outline" onClick={() => setCancelling(true)}>
                                <Slash />
                                {t('إلغاء')}
                            </Button>
                        )}
                    </div>
                }
            />

            {(['operation', 'schedule', 'requirement', 'reservation', 'consumable', 'crew', 'organizers', 'tasks', 'checklist', 'incident'] as const).map((k) => errors?.[k] && <Alert key={k} tone="danger">{errors[k]}</Alert>)}
            {o.readiness_override && o.readiness_override.length > 0 && (
                <Alert tone="warning">
                    <ShieldAlert className="me-1 inline size-4" />
                    {t('تُجوزت الجاهزيّة :n مرّة — آخرها: :reason (:by · :at)', {
                        n: String(o.readiness_override.length),
                        reason: o.readiness_override[o.readiness_override.length - 1].reason,
                        by: o.readiness_override[o.readiness_override.length - 1].by ?? '—',
                        at: o.readiness_override[o.readiness_override.length - 1].at,
                    })}
                </Alert>
            )}
            {o.status === 'cancelled' && <Alert tone="danger">{t('أُلغيت الفعاليّة')}: {o.cancellation_reason}</Alert>}
            {p.completion_blockers.length > 0 && (o.status === 'teardown') && (
                <Alert tone="warning">{t('موانع الإتمام')}: {p.completion_blockers.join(' · ')}</Alert>
            )}

            <div className="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
                <span className={'rounded-full px-2 py-1 text-[12px] ' + operationTone(o.status)}>{label(L, 'status', o.status)}</span>
                {o.event_starts_at && <span className="tabular-nums text-[#6b7280]" dir="ltr">{o.event_starts_at}{o.event_ends_at ? ` → ${o.event_ends_at}` : ''}</span>}
                {o.venue_name && <span className="inline-flex items-center gap-1 text-[#6b7280]"><MapPin className="size-3" />{o.venue_name}</span>}
                {o.lead && <span className="text-[#6b7280]">{t('القائد')}: {o.lead}</span>}
                {o.active && (
                    <span className={'inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] ' + (p.readiness.ready ? 'bg-[#ecfdf5] text-[#065f46]' : 'bg-[#fef2f2] text-[#b91c1c]')}>
                        {p.readiness.ready ? <CheckCircle2 className="size-3" /> : <AlertTriangle className="size-3" />}
                        {p.readiness.ready ? t('جاهزة') : t(':n موانع', { n: String(p.readiness.blockers.length) })}
                        {p.readiness.warnings.length > 0 && ` · ${t(':n تحذيرات', { n: String(p.readiness.warnings.length) })}`}
                    </span>
                )}
            </div>

            <Tabs tabs={tabs} current={tab} onChange={setTab} className="mb-4" />

            {tab === 'overview' && <Overview p={p} />}
            {tab === 'schedule' && <ScheduleTab p={p} />}
            {tab === 'equipment' && <EquipmentTab p={p} />}
            {tab === 'crew' && <CrewTab p={p} />}
            {tab === 'organizers' && (
                <OrganizersTab
                    operationId={o.id}
                    active={o.active}
                    leadId={o.organizers_lead_user_id ?? null}
                    users={p.users}
                    organizers={p.organizers ?? []}
                    options={p.organizer_options ?? []}
                    statusLabels={L.organizer_status ?? {}}
                    genderLabels={L.gender ?? {}}
                    conflicts={p.conflicts.filter((c) => c.kind.startsWith('organizer_'))}
                    canManage={Boolean(p.may.crew)}
                />
            )}
            {tab === 'tasks' && p.event_tasks && (
                <EventTasksTab operationId={o.id} projectId={o.project?.id ?? null} active={o.active} data={p.event_tasks} canManage={Boolean(p.may.crew)} />
            )}
            {tab === 'checklists' && <ChecklistsTab p={p} />}
            {tab === 'incidents' && <IncidentsTab p={p} />}
            {tab === 'history' && <HistoryTab p={p} />}

            <Dialog open={overriding !== null} onOpenChange={(v) => !v && setOverriding(null)}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('تجاوز الجاهزيّة')}</DialogTitle>
                        <DialogDescription>{t('الفعاليّة ليست جاهزة. التجاوزُ يُكتب باسمك وسببك ويبقى في سجلّها — ولا يُخفي الموانع.')}</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <ul className="list-disc space-y-1 ps-5 text-[13px] text-[#b91c1c]">
                            {p.readiness.blockers.map((b) => <li key={b.key + b.message}>{b.message}</li>)}
                        </ul>
                        <Field label="سبب التجاوز" required>
                            <Textarea rows={2} value={overrideReason} onChange={(e) => setOverrideReason(e.target.value)} />
                        </Field>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="danger"
                            disabled={overrideReason.trim() === ''}
                            onClick={() => {
                                post('admin.operations.transition', { status: overriding, override_reason: overrideReason });
                                setOverriding(null);
                            }}
                        >
                            {t('تجاوز وانتقل')}
                        </Button>
                        <Button variant="outline" onClick={() => setOverriding(null)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={cancelling} onOpenChange={setCancelling}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('إلغاء الفعاليّة')}</DialogTitle>
                        <DialogDescription>{t('تُحرَّر الحجوزُ التي لم تُسلَّم، ويبقى ما خرج معلَّقًا حتى يُردّ.')}</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <Field label="السبب" required>
                            <Textarea rows={2} value={cancelReason} onChange={(e) => setCancelReason(e.target.value)} />
                        </Field>
                    </div>
                    <DialogFooter>
                        <Button variant="danger" disabled={cancelReason.trim() === ''} onClick={() => { post('admin.operations.cancel', { reason: cancelReason }); setCancelling(false); }}>{t('إلغاء الفعاليّة')}</Button>
                        <Button variant="outline" onClick={() => setCancelling(false)}>{t('رجوع')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {dialog}
        </AdminLayout>
    );
}

/* ─────────────────────────── نظرة عامة ─────────────────────────── */

function Overview({ p }: { p: Props }) {
    const t = useTranslate();
    const o = p.operation;
    const [editing, setEditing] = useState(false);
    const [form, setForm] = useState({
        name: o.name, venue_name: o.venue_name ?? '', venue_address: o.venue_address ?? '', venue_notes: o.venue_notes ?? '',
        setup_starts_at: o.setup_starts_at ?? '', doors_open_at: o.doors_open_at ?? '', event_starts_at: o.event_starts_at ?? '', event_ends_at: o.event_ends_at ?? '',
        teardown_starts_at: o.teardown_starts_at ?? '', teardown_ends_at: o.teardown_ends_at ?? '', operations_lead_id: o.operations_lead_id ? String(o.operations_lead_id) : '', notes: o.notes ?? '',
    });

    const save = () => router.put(route('admin.operations.update', o.id), form, { preserveScroll: true, onSuccess: () => setEditing(false) });

    return (
        <div className="grid gap-4 lg:grid-cols-3">
            <Card className="p-4 lg:col-span-2">
                <div className="mb-3 flex items-center justify-between">
                    <h3 className="text-[14px] font-semibold">{t('الموعد والمكان')}</h3>
                    {p.may.manage && o.active && (
                        <Button variant="outline" size="sm" onClick={() => setEditing(!editing)}>
                            <Pencil />
                            {t('تعديل')}
                        </Button>
                    )}
                </div>
                {!editing ? (
                    <dl className="grid gap-x-6 gap-y-2 text-[13px] sm:grid-cols-2">
                        <Row label="الاسم" value={o.name} />
                        <Row label="المكان" value={o.venue_name} />
                        <Row label="العنوان" value={o.venue_address} />
                        <Row label="بداية التجهيز" value={o.setup_starts_at} ltr />
                        <Row label="فتح الأبواب" value={o.doors_open_at} ltr />
                        <Row label="بداية الفعاليّة" value={o.event_starts_at} ltr />
                        <Row label="نهاية الفعاليّة" value={o.event_ends_at} ltr />
                        <Row label="بداية الفكّ" value={o.teardown_starts_at} ltr />
                        <Row label="نهاية الفكّ" value={o.teardown_ends_at} ltr />
                        <Row label="المنطقة الزمنية" value={o.timezone} ltr />
                        <Row label="قائد العمليات" value={o.lead} />
                        <Row label="أنشأها" value={o.created_by} />
                        {o.venue_notes && <div className="sm:col-span-2"><span className="text-[#6b7280]">{t('ملاحظات المكان')}: </span>{o.venue_notes}</div>}
                        {o.notes && <div className="whitespace-pre-line sm:col-span-2"><span className="text-[#6b7280]">{t('ملاحظات')}: </span>{o.notes}</div>}
                    </dl>
                ) : (
                    <div className="space-y-3">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="الاسم" required><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
                            <Field label="قائد العمليات">
                                <Select value={form.operations_lead_id} placeholder="بلا قائد" onChange={(e) => setForm({ ...form, operations_lead_id: e.target.value })} options={p.users.map((u) => ({ value: u.id, label: u.name }))} />
                            </Field>
                            <Field label="المكان"><Input value={form.venue_name} onChange={(e) => setForm({ ...form, venue_name: e.target.value })} /></Field>
                            <Field label="العنوان"><Input value={form.venue_address} onChange={(e) => setForm({ ...form, venue_address: e.target.value })} /></Field>
                            <Field label="بداية التجهيز"><Input type="datetime-local" value={form.setup_starts_at} onChange={(e) => setForm({ ...form, setup_starts_at: e.target.value })} /></Field>
                            <Field label="فتح الأبواب"><Input type="datetime-local" value={form.doors_open_at} onChange={(e) => setForm({ ...form, doors_open_at: e.target.value })} /></Field>
                            <Field label="بداية الفعاليّة"><Input type="datetime-local" value={form.event_starts_at} onChange={(e) => setForm({ ...form, event_starts_at: e.target.value })} /></Field>
                            <Field label="نهاية الفعاليّة"><Input type="datetime-local" value={form.event_ends_at} onChange={(e) => setForm({ ...form, event_ends_at: e.target.value })} /></Field>
                            <Field label="بداية الفكّ"><Input type="datetime-local" value={form.teardown_starts_at} onChange={(e) => setForm({ ...form, teardown_starts_at: e.target.value })} /></Field>
                            <Field label="نهاية الفكّ"><Input type="datetime-local" value={form.teardown_ends_at} onChange={(e) => setForm({ ...form, teardown_ends_at: e.target.value })} /></Field>
                        </div>
                        <Field label="ملاحظات المكان"><Textarea rows={2} value={form.venue_notes} onChange={(e) => setForm({ ...form, venue_notes: e.target.value })} /></Field>
                        <Field label="ملاحظات"><Textarea rows={3} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></Field>
                        <div className="flex gap-2">
                            <Button onClick={save}>{t('حفظ')}</Button>
                            <Button variant="outline" onClick={() => setEditing(false)}>{t('إلغاء')}</Button>
                        </div>
                    </div>
                )}
            </Card>

            <div className="space-y-4">
                <Card className="p-4">
                    <h3 className="mb-2 text-[14px] font-semibold">
                        {t('الجاهزيّة')} <span className="text-[11px] font-normal text-[#9ca3af]">({p.readiness.phase === 'live' ? t('للبثّ') : t('قبل الفعاليّة')})</span>
                    </h3>
                    {p.readiness.blockers.length > 0 && (
                        <ul className="mb-2 space-y-1 text-[13px]">
                            {p.readiness.blockers.map((b) => (
                                <li key={b.key + b.message} className="flex items-start gap-2 text-[#b91c1c]"><XCircle className="mt-0.5 size-3.5 shrink-0" />{b.message}</li>
                            ))}
                        </ul>
                    )}
                    {p.readiness.warnings.length > 0 && (
                        <ul className="mb-2 space-y-1 text-[13px]">
                            {p.readiness.warnings.map((w) => (
                                <li key={w.key + w.message} className="flex items-start gap-2 text-[#b45309]"><AlertTriangle className="mt-0.5 size-3.5 shrink-0" />{w.message}</li>
                            ))}
                        </ul>
                    )}
                    <details className="text-[12px] text-[#6b7280]">
                        <summary className="cursor-pointer">{t('كلّ الفحوص (:n)', { n: String(p.readiness.checks.length) })}</summary>
                        <ul className="mt-2 space-y-1">
                            {p.readiness.checks.map((c, i) => (
                                <li key={c.key + i} className={'flex items-start gap-2 ' + (c.ok ? 'text-[#047857]' : c.severity === 'critical' ? 'text-[#b91c1c]' : 'text-[#b45309]')}>
                                    {c.ok ? <Check className="mt-0.5 size-3 shrink-0" /> : <XCircle className="mt-0.5 size-3 shrink-0" />}
                                    {c.label}
                                </li>
                            ))}
                        </ul>
                    </details>
                </Card>

                <Card className="p-4">
                    <h3 className="mb-2 text-[14px] font-semibold">{t('التعارضات')} <span className="text-[11px] font-normal text-[#9ca3af]">({p.conflicts.length})</span></h3>
                    {p.conflicts.length === 0 ? (
                        <p className="text-[12px] text-[#9ca3af]">{t('لا تعارض في المعدّات ولا الفريق')}</p>
                    ) : (
                        <ul className="space-y-1 text-[13px]">
                            {p.conflicts.map((c, i) => (
                                <li key={c.kind + i} className="flex items-start gap-2">
                                    <span className={'mt-0.5 rounded-full px-1.5 text-[10px] ' + severityTone(c.severity)}>{c.severity === 'critical' ? t('مانع') : t('تحذير')}</span>
                                    <span>{c.message}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </div>
    );
}

/* ─────────────────────────── الجدول ─────────────────────────── */

function ScheduleTab({ p }: { p: Props }) {
    const t = useTranslate();
    const [ask, dialog] = useConfirm();
    const o = p.operation;
    const L = p.labels;
    const blank = { title: '', description: '', type: 'other', starts_at: '', ends_at: '', responsible_user_id: '', responsible_name: '', notes: '' };
    const [editing, setEditing] = useState<{ id: number | null; form: typeof blank } | null>(null);

    const submit = () => {
        if (!editing) return;
        const opts = { preserveScroll: true, onSuccess: () => setEditing(null) };
        editing.id === null
            ? router.post(route('admin.operations.schedule.store', o.id), editing.form, opts)
            : router.put(route('admin.operations.schedule.update', [o.id, editing.id]), editing.form, opts);
    };

    return (
        <Card className="p-4">
            <div className="mb-3 flex items-center justify-between">
                <h3 className="text-[14px] font-semibold">{t('الجدول التشغيليّ')}</h3>
                {p.may.manage && o.active && (
                    <Button size="sm" onClick={() => setEditing({ id: null, form: blank })}><Plus />{t('بند')}</Button>
                )}
            </div>
            {p.schedule.length === 0 && <p className="py-6 text-center text-[13px] text-[#9ca3af]">{t('لا بنود بعد — خروجُ المعدّات، وصولُ الفريق، فتحُ الأبواب، الفكّ…')}</p>}
            <ul className="divide-y divide-[#f4f4f5]">
                {p.schedule.map((i) => (
                    <li key={i.id} className="flex flex-wrap items-center gap-3 py-2 text-[13px]">
                        <span className="w-[110px] shrink-0 tabular-nums text-[#6b7280]" dir="ltr">{i.starts_at ? i.starts_at.slice(5) : '—'}{i.ends_at ? ` → ${i.ends_at.slice(11)}` : ''}</span>
                        <span className={'rounded-full px-2 py-0.5 text-[11px] ' + scheduleTone(i.status)}>{label(L, 'schedule_status', i.status)}</span>
                        <span className="min-w-0 flex-1">
                            <span className="font-medium">{i.title}</span>
                            <span className="ms-2 text-[11px] text-[#9ca3af]">{label(L, 'schedule_type', i.type)}{i.responsible ? ` · ${i.responsible}` : ''}</span>
                            {i.description && <span className="block text-[12px] text-[#6b7280]">{i.description}</span>}
                        </span>
                        {p.may.manage && o.active && (
                            <span className="flex gap-1">
                                <Button variant="ghost" size="sm" onClick={() => setEditing({ id: i.id, form: { title: i.title, description: i.description ?? '', type: i.type, starts_at: i.starts_at ?? '', ends_at: i.ends_at ?? '', responsible_user_id: i.responsible_user_id ? String(i.responsible_user_id) : '', responsible_name: i.responsible_user_id ? '' : (i.responsible ?? ''), notes: i.notes ?? '' } })}><Pencil /></Button>
                                <Button variant="ghost" size="sm" onClick={async () => { if (await ask({ message: t('حذف البند «:title»؟', { title: i.title }), danger: true, action: t('حذف') })) router.delete(route('admin.operations.schedule.destroy', [o.id, i.id]), { preserveScroll: true }); }}><Trash2 /></Button>
                            </span>
                        )}
                    </li>
                ))}
            </ul>

            <Dialog open={editing !== null} onOpenChange={(v) => !v && setEditing(null)}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{editing?.id === null ? t('بند جديد') : t('تعديل البند')}</DialogTitle></DialogHeader>
                    {editing && (
                        <div className="space-y-3 px-5 pb-5">
                            <Field label="العنوان" required><Input value={editing.form.title} onChange={(e) => setEditing({ ...editing, form: { ...editing.form, title: e.target.value } })} /></Field>
                            <div className="grid grid-cols-2 gap-3">
                                <Field label="النوع">
                                    <Select value={editing.form.type} onChange={(e) => setEditing({ ...editing, form: { ...editing.form, type: e.target.value } })} options={Object.entries(L.schedule_type ?? {}).map(([v, l]) => ({ value: v, label: l }))} />
                                </Field>
                                <Field label="المسؤول">
                                    <Select value={editing.form.responsible_user_id} placeholder="—" onChange={(e) => setEditing({ ...editing, form: { ...editing.form, responsible_user_id: e.target.value } })} options={p.users.map((u) => ({ value: u.id, label: u.name }))} />
                                </Field>
                                <Field label="من"><Input type="datetime-local" value={editing.form.starts_at} onChange={(e) => setEditing({ ...editing, form: { ...editing.form, starts_at: e.target.value } })} /></Field>
                                <Field label="إلى"><Input type="datetime-local" value={editing.form.ends_at} onChange={(e) => setEditing({ ...editing, form: { ...editing.form, ends_at: e.target.value } })} /></Field>
                            </div>
                            <Field label="اسم مسؤول خارجيّ" hint="إن لم يكن من موظّفي النظام"><Input value={editing.form.responsible_name} onChange={(e) => setEditing({ ...editing, form: { ...editing.form, responsible_name: e.target.value } })} /></Field>
                            <Field label="الوصف"><Textarea rows={2} value={editing.form.description} onChange={(e) => setEditing({ ...editing, form: { ...editing.form, description: e.target.value } })} /></Field>
                        </div>
                    )}
                    <DialogFooter>
                        <Button disabled={!editing || editing.form.title.trim() === ''} onClick={submit}>{t('حفظ')}</Button>
                        <Button variant="outline" onClick={() => setEditing(null)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {dialog}
        </Card>
    );
}

/* ─────────────────────────── المعدّات ─────────────────────────── */

function EquipmentTab({ p }: { p: Props }) {
    const t = useTranslate();
    const [ask, dialog] = useConfirm();
    const o = p.operation;
    const L = p.labels;
    const blankReq = { kind: 'asset_category', asset_category: '', product_id: '', description: '', required_quantity: '1', assigned_quantity: '0', source: 'owned', critical: true, backup: false, notes: '' };
    const [req, setReq] = useState<{ id: number | null; form: typeof blankReq } | null>(null);
    const [reserving, setReserving] = useState<{ requirement_id: number | null; category: string | null } | null>(null);
    const [reserveForm, setReserveForm] = useState({ asset_id: '', from: '', until: '', notes: '' });
    const [checkout, setCheckout] = useState<Reservation | null>(null);
    const [checkoutForm, setCheckoutForm] = useState({ custodian_id: '', condition: 'good', notes: '' });
    const [returning, setReturning] = useState<Reservation | null>(null);
    const [returnForm, setReturnForm] = useState({ condition: 'good', missing_accessories: '', damage_notes: '' });
    /* صرفُ مستهلَك: مفتاحُ تكرارٍ يُولَّد عند فتح النافذة فلا يُصرف مرّتين بنقرتين */
    const [issuing, setIssuing] = useState<{ requirement_id: number | null; product_id: string } | null>(null);
    const [issueForm, setIssueForm] = useState({ quantity: '1', notes: '', key: '' });
    const [givingBack, setGivingBack] = useState<ConsumableIssue | null>(null);
    const [giveBackForm, setGiveBackForm] = useState({ quantity: '0', notes: '' });
    const { context } = usePage<PageProps>().props;
    const m = (v: number) => money(v, context!.currency);
    const openIssue = (requirementId: number | null, productId: number | null) => {
        setIssuing({ requirement_id: requirementId, product_id: productId ? String(productId) : '' });
        setIssueForm({ quantity: '1', notes: '', key: `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}` });
    };

    const submitReq = () => {
        if (!req) return;
        const data = { ...req.form, product_id: req.form.product_id || null, required_quantity: Number(req.form.required_quantity), assigned_quantity: Number(req.form.assigned_quantity) };
        const opts = { preserveScroll: true, onSuccess: () => setReq(null) };
        req.id === null
            ? router.post(route('admin.operations.requirements.store', o.id), data, opts)
            : router.put(route('admin.operations.requirements.update', [o.id, req.id]), data, opts);
    };

    const candidates = p.assets.filter((a) => !reserving?.category || a.category === reserving.category);
    const active = p.reservations.filter((r) => r.status !== 'cancelled');

    return (
        <div className="space-y-4">
            {/* توقّعُ المخزون لمشروع الفعاليّة — من متطلّباتها إن وُجدت، ومن نطاق المشروع مبدئيًّا */}
            {p.inventory_forecast && p.inventory_forecast.in_window && p.inventory_forecast.lines.length > 0 && (
                <Card className="p-4">
                    <h3 className="mb-3 text-[14px] font-semibold">
                        {t('توقّع المخزون')}
                        {p.inventory_forecast.shortages > 0 && <span className="ms-2 rounded-full bg-[#fef2f2] px-2 py-0.5 text-[11.5px] font-medium text-[#b91c1c]">{t('نقص مخزون: :n بنود', { n: String(p.inventory_forecast.shortages) })}</span>}
                    </h3>
                    <ProjectInventoryForecast data={p.inventory_forecast} projectId={p.operation.project?.id ?? 0} />
                </Card>
            )}
            <Card className="p-4">
                <div className="mb-3 flex items-center justify-between">
                    <h3 className="text-[14px] font-semibold">{t('المتطلّبات')} <span className="text-[11px] font-normal text-[#9ca3af]">— {t('ماذا نحتاج')}</span></h3>
                    {p.may.manage && o.active && <Button size="sm" onClick={() => setReq({ id: null, form: blankReq })}><Plus />{t('متطلَّب')}</Button>}
                </div>
                {p.requirements.length === 0 && <p className="py-4 text-center text-[13px] text-[#9ca3af]">{t('لم تُكتب متطلّبات — «٤ حواسيب، ٤ طابعات، ٢٠٠٠ بطاقة»')}</p>}
                <ul className="divide-y divide-[#f4f4f5]">
                    {p.requirements.map((r) => (
                        <li key={r.id} className="flex flex-wrap items-center gap-3 py-2 text-[13px]">
                            <span className={'w-[64px] shrink-0 text-center tabular-nums font-semibold ' + (r.shortfall > 0 ? (r.critical ? 'text-[#b91c1c]' : 'text-[#b45309]') : 'text-[#047857]')}>{r.assigned_quantity} / {r.required_quantity}</span>
                            <span className="min-w-0 flex-1">
                                <span className="font-medium">{r.description}</span>
                                <span className="ms-2 text-[11px] text-[#9ca3af]">
                                    {r.kind === 'asset_category' ? t('قطع ذات هويّة') : r.kind === 'product' ? t('من الكتالوج') : t('خارجيّ')} · {label(L, 'source', r.source)}
                                    {r.critical && <span className="ms-1 rounded-full bg-[#fef2f2] px-1.5 text-[#b91c1c]">{t('حرج')}</span>}
                                    {r.backup && <span className="ms-1 rounded-full bg-[#eff6ff] px-1.5 text-[#1d4ed8]">{t('احتياطيّ')}</span>}
                                    {r.kind === 'product' && r.issued_quantity > 0 && <span className="ms-1 rounded-full bg-[#fffbeb] px-1.5 text-[#b45309]">{t('صُرف')} {r.issued_quantity}{r.returned_quantity > 0 ? ` · ${t('رُدّ')} ${r.returned_quantity}` : ''}</span>}
                                </span>
                            </span>
                            <span className="flex gap-1">
                                {r.kind === 'asset_category' && p.may.reserve && o.active && r.shortfall > 0 && (
                                    <Button variant="outline" size="sm" onClick={() => { setReserving({ requirement_id: r.id, category: r.asset_category }); setReserveForm({ asset_id: '', from: '', until: '', notes: '' }); }}>{t('احجز قطعة')}</Button>
                                )}
                                {r.kind === 'product' && r.product_id !== null && p.may.checkout && o.active && (
                                    <Button variant="outline" size="sm" onClick={() => openIssue(r.id, r.product_id)}>{t('اصرف من الرفّ')}</Button>
                                )}
                                {p.may.manage && o.active && (
                                    <>
                                        <Button variant="ghost" size="sm" onClick={() => setReq({ id: r.id, form: { kind: r.kind, asset_category: r.asset_category ?? '', product_id: r.product_id ? String(r.product_id) : '', description: r.description, required_quantity: String(r.required_quantity), assigned_quantity: String(r.assigned_quantity), source: r.source, critical: r.critical, backup: r.backup, notes: r.notes ?? '' } })}><Pencil /></Button>
                                        <Button variant="ghost" size="sm" onClick={async () => { if (await ask({ message: t('حذف المتطلَّب «:what»؟', { what: r.description }), danger: true, action: t('حذف') })) router.delete(route('admin.operations.requirements.destroy', [o.id, r.id]), { preserveScroll: true }); }}><Trash2 /></Button>
                                    </>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            </Card>

            <Card className="p-4">
                <div className="mb-3 flex items-center justify-between">
                    <h3 className="text-[14px] font-semibold">{t('القطع المحجوزة')} <span className="text-[11px] font-normal text-[#9ca3af]">— {t('أيّ قطعٍ نأخذ')}</span></h3>
                    {p.may.reserve && o.active && <Button size="sm" variant="outline" onClick={() => { setReserving({ requirement_id: null, category: null }); setReserveForm({ asset_id: '', from: '', until: '', notes: '' }); }}><Plus />{t('حجز')}</Button>}
                </div>
                {active.length === 0 && <p className="py-4 text-center text-[13px] text-[#9ca3af]">{t('لا قطع محجوزة بعد')}</p>}
                <ul className="divide-y divide-[#f4f4f5]">
                    {active.map((r) => (
                        <li key={r.id} className="flex flex-wrap items-center gap-3 py-2 text-[13px]">
                            <span className={'rounded-full px-2 py-0.5 text-[11px] ' + reservationTone(r.status)}>{t(RESERVATION_LABEL[r.status] ?? r.status)}</span>
                            <span className="min-w-0 flex-1">
                                {p.may.assets ? (
                                    <SmartLink routeName="admin.assets.show" href={route('admin.assets.show', r.asset_id)} className="font-mono text-[12px] text-[#1d4ed8]">{r.asset_code}</SmartLink>
                                ) : <span className="font-mono text-[12px]">{r.asset_code}</span>}
                                <span className="ms-2 font-medium">{r.asset_name}</span>
                                {r.asset_status && r.asset_status !== 'available' && r.asset_status !== 'reserved' && r.asset_status !== 'deployed' && (
                                    <span className="ms-2 rounded-full bg-[#fef2f2] px-1.5 text-[10px] text-[#b91c1c]">{label(L, 'asset_status', r.asset_status)}</span>
                                )}
                                <span className="block text-[11px] text-[#9ca3af]" dir="ltr">{r.reserved_from} → {r.reserved_until}{r.custodian ? ` · ${r.custodian}` : ''}{r.return_condition ? ` · ${t('رُدّت')}: ${r.return_condition === 'lost' ? t('مفقودة') : label(L, 'condition', r.return_condition)}` : ''}</span>
                                {(r.missing_accessories || r.damage_notes) && <span className="block text-[11px] text-[#b91c1c]">{r.missing_accessories}{r.missing_accessories && r.damage_notes ? ' · ' : ''}{r.damage_notes}</span>}
                            </span>
                            <span className="flex gap-1">
                                {r.status === 'reserved' && p.may.checkout && o.active && <Button variant="outline" size="sm" onClick={() => { setCheckout(r); setCheckoutForm({ custodian_id: '', condition: 'good', notes: '' }); }}>{t('تسليم')}</Button>}
                                {r.status === 'reserved' && p.may.reserve && <Button variant="ghost" size="sm" onClick={async () => { if (await ask({ message: t('إلغاء حجز :code؟', { code: r.asset_code ?? '' }), action: t('إلغاء الحجز') })) router.post(route('admin.operations.reservations.cancel', [o.id, r.id]), {}, { preserveScroll: true }); }}><Undo2 /></Button>}
                                {r.status === 'checked_out' && p.may.return && <Button size="sm" onClick={() => { setReturning(r); setReturnForm({ condition: 'good', missing_accessories: '', damage_notes: '' }); }}>{t('ردّ')}</Button>}
                            </span>
                        </li>
                    ))}
                </ul>
            </Card>

            <Card className="p-4">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 className="text-[14px] font-semibold">{t('المستهلَكات')} <span className="text-[11px] font-normal text-[#9ca3af]">— {t('ما صُرف من الرفّ وما عاد')}</span></h3>
                    <div className="flex items-center gap-2">
                        {p.consumables.rows.length > 0 && <span className="text-[11px] text-[#6b7280]">{t('استُهلك')}: <span dir="ltr">{m(p.consumables.consumed_cost)}</span> · {t('رُدّ')}: <span dir="ltr">{m(p.consumables.returned_cost)}</span></span>}
                        {p.may.checkout && o.active && <Button size="sm" variant="outline" onClick={() => openIssue(null, null)}><Plus />{t('صرف')}</Button>}
                    </div>
                </div>
                {p.consumables.rows.length === 0 && <p className="py-4 text-center text-[13px] text-[#9ca3af]">{t('لم يُصرف شيءٌ من الرفّ بعد — الصرفُ يحرّك المخزون ويُحمَّل على المشروع')}</p>}
                <ul className="divide-y divide-[#f4f4f5]">
                    {p.consumables.rows.map((c) => (
                        <li key={c.id} className="flex flex-wrap items-center gap-3 py-2 text-[13px]">
                            <span className={'rounded-full px-2 py-0.5 text-[11px] ' + (c.status === 'closed' ? 'bg-[#f4f4f5] text-[#71717a]' : 'bg-[#fffbeb] text-[#b45309]')}>{c.status === 'closed' ? t('مقفل') : t('مصروف')}</span>
                            <span className="min-w-0 flex-1">
                                <span className="font-medium">{c.product ?? '—'}</span>
                                {c.requirement && <span className="ms-2 text-[11px] text-[#9ca3af]">{c.requirement}</span>}
                                <span className="block text-[11px] text-[#9ca3af]"><span dir="ltr">{c.issued_at}</span> · {t('صُرف')} {c.issued} · {t('رُدّ')} {c.returned} · {t('استُهلك')} {c.consumed} × <span dir="ltr">{m(c.unit_cost)}</span> = <span dir="ltr">{m(c.consumed_cost)}</span></span>
                                {c.notes && <span className="block text-[11px] text-[#6b7280]">{c.notes}</span>}
                            </span>
                            {c.status === 'issued' && p.may.return && c.returnable !== false && <Button size="sm" onClick={() => { setGivingBack(c); setGiveBackForm({ quantity: '0', notes: '' }); }}>{t('ردّ وإقفال')}</Button>}
                        </li>
                    ))}
                </ul>
            </Card>

            {/* صرفُ مستهلَك */}
            <Dialog open={issuing !== null} onOpenChange={(v) => !v && setIssuing(null)}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{t('صرفٌ من الرفّ')}</DialogTitle><DialogDescription>{t('تُخصم الكميّةُ من المخزون الآن بتكلفتها وتُحمَّل على المشروع — وما يعود يُردّ لاحقًا')}</DialogDescription></DialogHeader>
                    {issuing && (
                        <div className="space-y-3 px-5 pb-5">
                            <Field label="الصنف" required>
                                <Select value={issuing.product_id} placeholder="اختر صنفًا" onChange={(e) => setIssuing({ ...issuing, product_id: e.target.value })} options={p.products.filter((x) => x.kind !== 'service').map((x) => ({ value: x.id, label: `${x.name} (${t('بالرفّ')}: ${x.quantity})` }))} />
                            </Field>
                            <Field label="الكميّة" required><Input type="number" min={1} value={issueForm.quantity} onChange={(e) => setIssueForm({ ...issueForm, quantity: e.target.value })} dir="ltr" /></Field>
                            <Field label="ملاحظة"><Input value={issueForm.notes} onChange={(e) => setIssueForm({ ...issueForm, notes: e.target.value })} /></Field>
                        </div>
                    )}
                    <DialogFooter>
                        <Button disabled={!issuing?.product_id || Number(issueForm.quantity) < 1} onClick={() => router.post(route('admin.operations.consumables.store', o.id), { product_id: Number(issuing!.product_id), quantity: Number(issueForm.quantity), requirement_id: issuing!.requirement_id, notes: issueForm.notes, key: issueForm.key }, { preserveScroll: true, onSuccess: () => setIssuing(null) })}>{t('صرف')}</Button>
                        <Button variant="outline" onClick={() => setIssuing(null)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ردُّ مستهلَك */}
            <Dialog open={givingBack !== null} onOpenChange={(v) => !v && setGivingBack(null)}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{t('ردٌّ إلى الرفّ وإقفال')}</DialogTitle><DialogDescription>{t('ما يُردّ يعود إلى المخزون بتكلفة الصرف نفسِها — والباقي مستهلَكٌ على المشروع')}</DialogDescription></DialogHeader>
                    {givingBack && (
                        <div className="space-y-3 px-5 pb-5">
                            <p className="text-[13px]">{givingBack.product} — {t('صُرف')} {givingBack.issued}</p>
                            <Field label="الكميّة المردودة" required hint="صفرٌ يعني استُهلك كلُّه"><Input type="number" min={0} max={givingBack.issued} value={giveBackForm.quantity} onChange={(e) => setGiveBackForm({ ...giveBackForm, quantity: e.target.value })} dir="ltr" /></Field>
                            <Field label="ملاحظة"><Input value={giveBackForm.notes} onChange={(e) => setGiveBackForm({ ...giveBackForm, notes: e.target.value })} /></Field>
                        </div>
                    )}
                    <DialogFooter>
                        <Button disabled={!givingBack || Number(giveBackForm.quantity) < 0 || Number(giveBackForm.quantity) > (givingBack?.issued ?? 0)} onClick={() => router.post(route('admin.operations.consumables.return', [o.id, givingBack!.id]), { quantity: Number(giveBackForm.quantity), notes: giveBackForm.notes }, { preserveScroll: true, onSuccess: () => setGivingBack(null) })}>{t('ردّ وإقفال')}</Button>
                        <Button variant="outline" onClick={() => setGivingBack(null)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* متطلَّب */}
            <Dialog open={req !== null} onOpenChange={(v) => !v && setReq(null)}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{req?.id === null ? t('متطلَّب جديد') : t('تعديل المتطلَّب')}</DialogTitle></DialogHeader>
                    {req && (
                        <div className="space-y-3 px-5 pb-5">
                            <Field label="النوع">
                                <Select value={req.form.kind} onChange={(e) => setReq({ ...req, form: { ...req.form, kind: e.target.value } })} options={[{ value: 'asset_category', label: t('قطع ذات هويّة (من سجلّ المعدّات)') }, { value: 'product', label: t('صنف من الكتالوج/المخزون') }, { value: 'external', label: t('خارجيّ — تأجير أو من العميل') }]} />
                            </Field>
                            {req.form.kind === 'asset_category' && (
                                <Field label="صنف القطع" required>
                                    <Select value={req.form.asset_category} placeholder="اختر صنفًا" onChange={(e) => setReq({ ...req, form: { ...req.form, asset_category: e.target.value } })} options={p.categories} />
                                </Field>
                            )}
                            {req.form.kind === 'product' && (
                                <Field label="الصنف" required>
                                    <Select value={req.form.product_id} placeholder="اختر صنفًا" onChange={(e) => setReq({ ...req, form: { ...req.form, product_id: e.target.value } })} options={p.products.map((x) => ({ value: x.id, label: x.name }))} />
                                </Field>
                            )}
                            <Field label="الوصف" hint="يُترك فارغًا ليأخذ اسم الصنف"><Input value={req.form.description} onChange={(e) => setReq({ ...req, form: { ...req.form, description: e.target.value } })} /></Field>
                            <div className="grid grid-cols-2 gap-3">
                                <Field label="الكميّة المطلوبة" required><Input type="number" min={1} value={req.form.required_quantity} onChange={(e) => setReq({ ...req, form: { ...req.form, required_quantity: e.target.value } })} /></Field>
                                {req.form.kind !== 'asset_category' && <Field label="المُعَدّ منها" hint="يُكتب بيد — لا حجزَ للكميّات"><Input type="number" min={0} value={req.form.assigned_quantity} onChange={(e) => setReq({ ...req, form: { ...req.form, assigned_quantity: e.target.value } })} /></Field>}
                                <Field label="المصدر">
                                    <Select value={req.form.source} onChange={(e) => setReq({ ...req, form: { ...req.form, source: e.target.value } })} options={Object.entries(L.source ?? {}).map(([v, l]) => ({ value: v, label: l }))} />
                                </Field>
                            </div>
                            <label className="flex items-center gap-2 text-[13px]"><input type="checkbox" checked={req.form.critical} onChange={(e) => setReq({ ...req, form: { ...req.form, critical: e.target.checked } })} />{t('حرج — نقصُه يمنع الجاهزيّة')}</label>
                            <label className="flex items-center gap-2 text-[13px]"><input type="checkbox" checked={req.form.backup} onChange={(e) => setReq({ ...req, form: { ...req.form, backup: e.target.checked } })} />{t('احتياطيّ — قطعة بديلة')}</label>
                        </div>
                    )}
                    <DialogFooter>
                        <Button onClick={submitReq}>{t('حفظ')}</Button>
                        <Button variant="outline" onClick={() => setReq(null)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* حجز */}
            <Dialog open={reserving !== null} onOpenChange={(v) => !v && setReserving(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('حجز قطعة')}</DialogTitle>
                        <DialogDescription>{t('القطعة الواحدة لا تُحجز لفعاليّتين في وقتٍ واحد — يُفحص التداخل عند الحفظ.')}</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <Field label="القطعة" required hint="المشغولةُ في نافذة الفعاليّة تُعرض ولا تُختار">
                            <Select value={reserveForm.asset_id} placeholder="اختر قطعة" onChange={(e) => setReserveForm({ ...reserveForm, asset_id: e.target.value })} options={candidates.map((a) => ({ value: a.id, label: `${a.code} — ${a.name}${a.free ? '' : ` (${t('مشغولة')})`}`, disabled: !a.free }))} />
                        </Field>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="من" hint="يُترك فارغًا لنافذة الفعاليّة"><Input type="datetime-local" value={reserveForm.from} onChange={(e) => setReserveForm({ ...reserveForm, from: e.target.value })} /></Field>
                            <Field label="إلى"><Input type="datetime-local" value={reserveForm.until} onChange={(e) => setReserveForm({ ...reserveForm, until: e.target.value })} /></Field>
                        </div>
                        <Field label="ملاحظات"><Input value={reserveForm.notes} onChange={(e) => setReserveForm({ ...reserveForm, notes: e.target.value })} /></Field>
                    </div>
                    <DialogFooter>
                        <Button disabled={reserveForm.asset_id === ''} onClick={() => router.post(route('admin.operations.reservations.store', o.id), { ...reserveForm, requirement_id: reserving?.requirement_id }, { preserveScroll: true, onSuccess: () => setReserving(null) })}>{t('حجز')}</Button>
                        <Button variant="outline" onClick={() => setReserving(null)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* تسليم */}
            <Dialog open={checkout !== null} onOpenChange={(v) => !v && setCheckout(null)}>
                <DialogContent className="max-w-md">
                    <DialogHeader><DialogTitle>{t('تسليم :code', { code: checkout?.asset_code ?? '' })}</DialogTitle></DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <Field label="في عهدة">
                            <Select value={checkoutForm.custodian_id} placeholder="—" onChange={(e) => setCheckoutForm({ ...checkoutForm, custodian_id: e.target.value })} options={p.users.map((u) => ({ value: u.id, label: u.name }))} />
                        </Field>
                        <Field label="الحال عند الخروج">
                            <Select value={checkoutForm.condition} onChange={(e) => setCheckoutForm({ ...checkoutForm, condition: e.target.value })} options={Object.entries(L.condition ?? {}).map(([v, l]) => ({ value: v, label: l }))} />
                        </Field>
                        <Field label="ملاحظات"><Input value={checkoutForm.notes} onChange={(e) => setCheckoutForm({ ...checkoutForm, notes: e.target.value })} /></Field>
                    </div>
                    <DialogFooter>
                        <Button onClick={() => checkout && router.post(route('admin.operations.reservations.checkout', [o.id, checkout.id]), checkoutForm, { preserveScroll: true, onSuccess: () => setCheckout(null) })}>{t('تسليم')}</Button>
                        <Button variant="outline" onClick={() => setCheckout(null)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ردّ */}
            <Dialog open={returning !== null} onOpenChange={(v) => !v && setReturning(null)}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('ردّ :code', { code: returning?.asset_code ?? '' })}</DialogTitle>
                        <DialogDescription>{t('التالفةُ تصير «تالفة» في السجلّ حتى تُعاد إلى الخدمة بيد؛ والمفقودةُ «مفقودة».')}</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <Field label="الحال عند العودة" required>
                            <Select value={returnForm.condition} onChange={(e) => setReturnForm({ ...returnForm, condition: e.target.value })} options={[...Object.entries(L.condition ?? {}).map(([v, l]) => ({ value: v, label: l })), { value: 'lost', label: t('مفقودة') }]} />
                        </Field>
                        <Field label="ملحقات ناقصة"><Input value={returnForm.missing_accessories} onChange={(e) => setReturnForm({ ...returnForm, missing_accessories: e.target.value })} /></Field>
                        <Field label="وصف التلف"><Textarea rows={2} value={returnForm.damage_notes} onChange={(e) => setReturnForm({ ...returnForm, damage_notes: e.target.value })} /></Field>
                    </div>
                    <DialogFooter>
                        <Button onClick={() => returning && router.post(route('admin.operations.reservations.return', [o.id, returning.id]), returnForm, { preserveScroll: true, onSuccess: () => setReturning(null) })}>{t('تسجيل الردّ')}</Button>
                        <Button variant="outline" onClick={() => setReturning(null)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {dialog}
        </div>
    );
}

/* ─────────────────────────── الفريق ─────────────────────────── */

function CrewTab({ p }: { p: Props }) {
    const t = useTranslate();
    const [ask, dialog] = useConfirm();
    const o = p.operation;
    const L = p.labels;
    const [adding, setAdding] = useState(false);
    const [form, setForm] = useState({ user_id: '', external_name: '', external_phone: '', role: 'registration_staff', starts_at: '', ends_at: '', notes: '' });
    const rows = p.crew.filter((c) => c.status !== 'cancelled');

    return (
        <Card className="p-4">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-[14px] font-semibold">
                    {t('الفريق')} <span className="text-[11px] font-normal text-[#9ca3af]">— {t('متوقَّع :e · حضر :i · غائب :m', { e: String(p.attendance.expected), i: String(p.attendance.checked_in), m: String(p.attendance.missing) })}</span>
                </h3>
                {p.may.crew && o.active && <Button size="sm" onClick={() => setAdding(true)}><Plus />{t('تعيين')}</Button>}
            </div>
            {rows.length === 0 && <p className="py-4 text-center text-[13px] text-[#9ca3af]">{t('لا فريق معيَّن بعد')}</p>}
            <ul className="divide-y divide-[#f4f4f5]">
                {rows.map((c) => (
                    <li key={c.id} className="flex flex-wrap items-center gap-3 py-2 text-[13px]">
                        <span className={'rounded-full px-2 py-0.5 text-[11px] ' + crewTone(c.status)}>{label(L, 'crew_status', c.status)}</span>
                        <span className="min-w-0 flex-1">
                            <span className="font-medium">{c.name}</span>
                            {c.external && <span className="ms-1 rounded-full bg-[#f4f4f5] px-1.5 text-[10px] text-[#71717a]">{t('متعاقد')}</span>}
                            <span className="ms-2 text-[11px] text-[#9ca3af]">{label(L, 'role', c.role)}{c.phone ? ` · ${c.phone}` : ''}</span>
                            <span className="block text-[11px] text-[#9ca3af]" dir="ltr">{c.starts_at ?? '—'} → {c.ends_at ?? '—'}</span>
                            <span className="block text-[11px]">
                                {c.briefing_status === 'briefed' ? <span className="text-[#047857]">{t('أُحيط')}</span> : c.briefing_status === 'not_required' ? <span className="text-[#9ca3af]">{t('لا إحاطة')}</span> : <span className="text-[#b45309]">{t('لم يُحَط')}</span>}
                                {c.notified_at && <span className="text-[#9ca3af]"> · {t('بُلّغ')} {c.notified_at}</span>}
                            </span>
                        </span>
                        {p.may.crew && o.active && (
                            <span className="flex gap-1">
                                {c.briefing_status === 'pending' && <Button variant="outline" size="sm" onClick={() => router.put(route('admin.operations.crew.update', [o.id, c.id]), { briefing_status: 'briefed' }, { preserveScroll: true })}>{t('أُحيط')}</Button>}
                                {c.status === 'planned' && <Button variant="outline" size="sm" onClick={() => router.put(route('admin.operations.crew.update', [o.id, c.id]), { status: 'confirmed' }, { preserveScroll: true })}>{t('تأكيد')}</Button>}
                                {!c.external && <Button variant="ghost" size="sm" onClick={() => router.post(route('admin.operations.crew.notify', [o.id, c.id]), {}, { preserveScroll: true })}><Bell /></Button>}
                                <Button variant="ghost" size="sm" onClick={async () => { if (await ask({ message: t('إزالة :name من الفريق؟', { name: c.name }), danger: true, action: t('إزالة') })) router.delete(route('admin.operations.crew.destroy', [o.id, c.id]), { preserveScroll: true }); }}><Trash2 /></Button>
                            </span>
                        )}
                    </li>
                ))}
            </ul>

            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('تعيين في الفريق')}</DialogTitle>
                        <DialogDescription>{t('موظّفٌ من النظام أو متعاقدٌ باسمه وهاتفه — ولا يُنشأ حسابٌ لمتعاقد. والموظّفُ لا يُعيَّن في فعاليّتين متداخلتين.')}</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <Field label="الموظّف" hint="اتركه فارغًا لمتعاقدٍ خارجيّ">
                            <Select value={form.user_id} placeholder="—" onChange={(e) => setForm({ ...form, user_id: e.target.value })} options={p.users.map((u) => ({ value: u.id, label: p.project_members.some((m) => m.user_id === u.id) ? `${u.name} · ${t('من فريق المشروع')}` : u.name }))} />
                        </Field>
                        {form.user_id === '' && (
                            <div className="grid grid-cols-2 gap-3">
                                <Field label="اسم المتعاقد"><Input value={form.external_name} onChange={(e) => setForm({ ...form, external_name: e.target.value })} /></Field>
                                <Field label="هاتفه"><Input value={form.external_phone} onChange={(e) => setForm({ ...form, external_phone: e.target.value })} dir="ltr" /></Field>
                            </div>
                        )}
                        <Field label="الدور" required>
                            <Select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })} options={Object.entries(L.role ?? {}).map(([v, l]) => ({ value: v, label: l }))} />
                        </Field>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="من" hint="يُترك فارغًا لنافذة الفعاليّة"><Input type="datetime-local" value={form.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} /></Field>
                            <Field label="إلى"><Input type="datetime-local" value={form.ends_at} onChange={(e) => setForm({ ...form, ends_at: e.target.value })} /></Field>
                        </div>
                        <Field label="ملاحظات"><Input value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></Field>
                    </div>
                    <DialogFooter>
                        <Button disabled={form.user_id === '' && form.external_name.trim() === ''} onClick={() => router.post(route('admin.operations.crew.store', o.id), form, { preserveScroll: true, onSuccess: () => setAdding(false) })}>{t('تعيين')}</Button>
                        <Button variant="outline" onClick={() => setAdding(false)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {dialog}
        </Card>
    );
}

/* ─────────────────────────── قوائم التحقّق ─────────────────────────── */

function ChecklistsTab({ p }: { p: Props }) {
    const t = useTranslate();
    const o = p.operation;
    const L = p.labels;
    const [attaching, setAttaching] = useState('');
    const [waiving, setWaiving] = useState<ChecklistItem | null>(null);
    const [reason, setReason] = useState('');
    const [adding, setAdding] = useState<number | null>(null);
    const [newItem, setNewItem] = useState({ title: '', required: true });

    const item = (name: string, id: number, data: Record<string, string | number | boolean | null | undefined> = {}) => router.post(route(name, [o.id, id]), data, { preserveScroll: true });

    return (
        <div className="space-y-4">
            {p.may.checklist && o.active && (
                <Card className="flex flex-wrap items-end gap-3 p-4">
                    <Field label="إلحاق قائمة من قالب" className="min-w-[240px]">
                        <Select value={attaching} placeholder="اختر قالبًا" onChange={(e) => setAttaching(e.target.value)} options={p.templates.map((x) => ({ value: x.id, label: `${x.name} (${label(L, 'phase', x.phase)})` }))} />
                    </Field>
                    <Button disabled={attaching === ''} onClick={() => router.post(route('admin.operations.checklists.store', o.id), { template_id: attaching }, { preserveScroll: true, onSuccess: () => setAttaching('') })}>{t('إلحاق')}</Button>
                    <Button variant="outline" asChild><SmartLink routeName="admin.operations.templates" href={route('admin.operations.templates')}>{t('القوالب')}</SmartLink></Button>
                </Card>
            )}
            {p.checklists.length === 0 && <Card className="p-6 text-center text-[13px] text-[#9ca3af]">{t('لا قوائم تحقّق — ألحق قالبًا')}</Card>}
            {p.checklists.map((c) => {
                const done = c.items.filter((i) => i.status === 'done' || i.status === 'waived').length;

                return (
                    <Card key={c.id} className="p-4">
                        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <h3 className="text-[14px] font-semibold">
                                {c.name} <span className="text-[11px] font-normal text-[#9ca3af]">— {label(L, 'phase', c.phase)} · {done}/{c.items.length}</span>
                            </h3>
                            {p.may.checklist && o.active && <Button variant="outline" size="sm" onClick={() => { setAdding(c.id); setNewItem({ title: '', required: true }); }}><Plus />{t('بند')}</Button>}
                        </div>
                        <div className="mb-2 h-1.5 overflow-hidden rounded-full bg-[#f4f4f5]"><div className="h-full bg-[#059669]" style={{ width: `${c.items.length ? (done / c.items.length) * 100 : 0}%` }} /></div>
                        <ul className="divide-y divide-[#f4f4f5]">
                            {c.items.map((i) => (
                                <li key={i.id} className="flex flex-wrap items-center gap-3 py-2 text-[13px]">
                                    <button
                                        type="button"
                                        disabled={!o.active}
                                        onClick={() => item(i.status === 'done' ? 'admin.operations.checklistItems.reopen' : 'admin.operations.checklistItems.complete', i.id)}
                                        className={'flex size-6 shrink-0 items-center justify-center rounded-full border ' + (i.status === 'done' ? 'border-[#059669] bg-[#059669] text-white' : i.status === 'waived' ? 'border-[#d97706] bg-[#fffbeb] text-[#b45309]' : i.status === 'blocked' ? 'border-[#dc2626] bg-[#fef2f2] text-[#b91c1c]' : 'border-[#d1d5db]')}
                                        aria-label={t(CHECKLIST_STATUS_LABEL[i.status] ?? i.status)}
                                    >
                                        {i.status === 'done' && <Check className="size-3.5" />}
                                        {i.status === 'blocked' && <XCircle className="size-3.5" />}
                                        {i.status === 'waived' && <ShieldAlert className="size-3.5" />}
                                    </button>
                                    <span className={'min-w-0 flex-1 ' + (i.status === 'done' ? 'text-[#9ca3af] line-through' : '')}>
                                        {i.title}
                                        {i.required && i.status !== 'done' && <span className="ms-1 rounded-full bg-[#fef2f2] px-1.5 text-[10px] text-[#b91c1c] no-underline">{t('إلزاميّ')}</span>}
                                        {i.assignee && <span className="ms-2 text-[11px] text-[#9ca3af]">{i.assignee}</span>}
                                        {i.completed_at && <span className="ms-2 text-[11px] text-[#9ca3af]" dir="ltr">{i.completed_at}</span>}
                                        {i.evidence_note && <span className="block text-[11px] text-[#6b7280]">{i.evidence_note}</span>}
                                        {i.blocked_reason && <span className="block text-[11px] text-[#b91c1c]">{t('معطَّل')}: {i.blocked_reason}</span>}
                                        {i.waived_reason && <span className="block text-[11px] text-[#b45309]">{t('أُعفي')}: {i.waived_reason}</span>}
                                    </span>
                                    <span className={'rounded-full px-2 py-0.5 text-[11px] ' + checklistTone(i.status)}>{t(CHECKLIST_STATUS_LABEL[i.status] ?? i.status)}</span>
                                    {o.active && i.status !== 'done' && i.required && p.may.checklist && i.status !== 'waived' && (
                                        <Button variant="ghost" size="sm" onClick={() => { setWaiving(i); setReason(''); }}>{t('إعفاء')}</Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Card>
                );
            })}

            <Dialog open={waiving !== null} onOpenChange={(v) => !v && setWaiving(null)}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('إعفاء بند إلزاميّ')}</DialogTitle>
                        <DialogDescription>{t('الإعفاءُ ليس إنجازًا: يُكتب باسمك وسببك ويبقى تحذيرًا في الجاهزيّة.')}</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <p className="text-[13px] font-medium">{waiving?.title}</p>
                        <Field label="السبب" required><Textarea rows={2} value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
                    </div>
                    <DialogFooter>
                        <Button variant="danger" disabled={reason.trim() === ''} onClick={() => { if (waiving) item('admin.operations.checklistItems.waive', waiving.id, { reason }); setWaiving(null); }}>{t('إعفاء')}</Button>
                        <Button variant="outline" onClick={() => setWaiving(null)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={adding !== null} onOpenChange={(v) => !v && setAdding(null)}>
                <DialogContent className="max-w-md">
                    <DialogHeader><DialogTitle>{t('بند جديد')}</DialogTitle></DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <Field label="العنوان" required><Input value={newItem.title} onChange={(e) => setNewItem({ ...newItem, title: e.target.value })} /></Field>
                        <label className="flex items-center gap-2 text-[13px]"><input type="checkbox" checked={newItem.required} onChange={(e) => setNewItem({ ...newItem, required: e.target.checked })} />{t('إلزاميّ')}</label>
                    </div>
                    <DialogFooter>
                        <Button disabled={newItem.title.trim() === ''} onClick={() => adding !== null && router.post(route('admin.operations.checklists.items.store', [o.id, adding]), newItem, { preserveScroll: true, onSuccess: () => setAdding(null) })}>{t('إضافة')}</Button>
                        <Button variant="outline" onClick={() => setAdding(null)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

/* ─────────────────────────── الحوادث ─────────────────────────── */

function IncidentsTab({ p }: { p: Props }) {
    const t = useTranslate();
    const o = p.operation;
    const L = p.labels;
    const [reporting, setReporting] = useState(false);
    const [form, setForm] = useState({ title: '', category: 'equipment', severity: 'medium', description: '', assigned_to: '' });
    const [resolving, setResolving] = useState<Incident | null>(null);
    const [resolution, setResolution] = useState('');
    const [carrying, setCarrying] = useState<Incident | null>(null);
    const [reason, setReason] = useState('');

    const act = (name: string, id: number, data: Record<string, string | number | boolean | null | undefined> = {}) => router.post(route(name, [o.id, id]), data, { preserveScroll: true });

    return (
        <Card className="p-4">
            <div className="mb-3 flex items-center justify-between">
                <h3 className="text-[14px] font-semibold">{t('الحوادث')}</h3>
                {p.may.incidents && <Button size="sm" onClick={() => setReporting(true)}><AlertTriangle />{t('تسجيل حادث')}</Button>}
            </div>
            {p.incidents.length === 0 && <p className="py-4 text-center text-[13px] text-[#9ca3af]">{t('لا حوادث')}</p>}
            <ul className="divide-y divide-[#f4f4f5]">
                {p.incidents.map((i) => (
                    <li key={i.id} className="flex flex-wrap items-start gap-3 py-2 text-[13px]">
                        <span className={'rounded-full px-2 py-0.5 text-[11px] ' + severityTone(i.severity)}>{label(L, 'severity', i.severity)}</span>
                        <span className={'rounded-full px-2 py-0.5 text-[11px] ' + incidentTone(i.status)}>{label(L, 'incident_status', i.status)}</span>
                        <span className="min-w-0 flex-1">
                            <span className="font-medium">{i.title}</span>
                            <span className="ms-2 text-[11px] text-[#9ca3af]">{label(L, 'incident_category', i.category)} · {i.occurred_at} · {i.reported_by ?? '—'}{i.assignee ? ` → ${i.assignee}` : ''}{i.asset ? ` · ${i.asset}` : ''}</span>
                            {i.description && <span className="block text-[12px] text-[#6b7280]">{i.description}</span>}
                            {i.resolution && <span className="block text-[12px] text-[#047857]">{t('الحلّ')}: {i.resolution}</span>}
                            {i.carried_forward && <span className="block text-[11px] text-[#b45309]">{t('أُقرّ بحمله إلى ما بعد الإتمام')}</span>}
                        </span>
                        {p.may.incidents && (
                            <span className="flex flex-wrap gap-1">
                                {i.status === 'open' && <Button variant="outline" size="sm" onClick={() => router.put(route('admin.operations.incidents.update', [o.id, i.id]), { status: 'investigating' }, { preserveScroll: true })}>{t('قيد المعالجة')}</Button>}
                                {(i.status === 'open' || i.status === 'investigating') && <Button size="sm" onClick={() => { setResolving(i); setResolution(''); }}>{t('حُلّ')}</Button>}
                                {(i.status === 'open' || i.status === 'investigating') && (i.severity === 'high' || i.severity === 'critical') && !i.carried_forward && p.may.status && <Button variant="ghost" size="sm" onClick={() => { setCarrying(i); setReason(''); }}>{t('حمل')}</Button>}
                                {i.status === 'resolved' && <Button variant="outline" size="sm" onClick={() => act('admin.operations.incidents.close', i.id)}>{t('إقفال')}</Button>}
                                {(i.status === 'resolved' || i.status === 'closed') && <Button variant="ghost" size="sm" onClick={() => act('admin.operations.incidents.reopen', i.id)}><Undo2 /></Button>}
                            </span>
                        )}
                    </li>
                ))}
            </ul>

            <Dialog open={reporting} onOpenChange={setReporting}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{t('تسجيل حادث')}</DialogTitle></DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <Field label="العنوان" required><Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} /></Field>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="التصنيف"><Select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} options={Object.entries(L.incident_category ?? {}).map(([v, l]) => ({ value: v, label: l }))} /></Field>
                            <Field label="الشدّة"><Select value={form.severity} onChange={(e) => setForm({ ...form, severity: e.target.value })} options={Object.entries(L.severity ?? {}).map(([v, l]) => ({ value: v, label: l }))} /></Field>
                        </div>
                        <Field label="مسنَد إلى"><Select value={form.assigned_to} placeholder="—" onChange={(e) => setForm({ ...form, assigned_to: e.target.value })} options={p.users.map((u) => ({ value: u.id, label: u.name }))} /></Field>
                        <Field label="الوصف"><Textarea rows={3} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></Field>
                    </div>
                    <DialogFooter>
                        <Button disabled={form.title.trim() === ''} onClick={() => router.post(route('admin.operations.incidents.store', o.id), form, { preserveScroll: true, onSuccess: () => { setReporting(false); setForm({ title: '', category: 'equipment', severity: 'medium', description: '', assigned_to: '' }); } })}>{t('تسجيل')}</Button>
                        <Button variant="outline" onClick={() => setReporting(false)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={resolving !== null} onOpenChange={(v) => !v && setResolving(null)}>
                <DialogContent className="max-w-md">
                    <DialogHeader><DialogTitle>{t('حلّ الحادث')}</DialogTitle></DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <p className="text-[13px] font-medium">{resolving?.title}</p>
                        <Field label="كيف عولج" required><Textarea rows={3} value={resolution} onChange={(e) => setResolution(e.target.value)} /></Field>
                    </div>
                    <DialogFooter>
                        <Button disabled={resolution.trim() === ''} onClick={() => { if (resolving) act('admin.operations.incidents.resolve', resolving.id, { resolution }); setResolving(null); }}>{t('حُلّ')}</Button>
                        <Button variant="outline" onClick={() => setResolving(null)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={carrying !== null} onOpenChange={(v) => !v && setCarrying(null)}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('حمل الحادث إلى ما بعد الإتمام')}</DialogTitle>
                        <DialogDescription>{t('حادثٌ حرج لم يُحلّ يمنع الإتمام — إلّا أن يُقرَّ بحمله صراحةً وبسبب.')}</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <Field label="السبب" required><Textarea rows={2} value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
                    </div>
                    <DialogFooter>
                        <Button variant="danger" disabled={reason.trim() === ''} onClick={() => { if (carrying) act('admin.operations.incidents.carryForward', carrying.id, { reason }); setCarrying(null); }}>{t('إقرار')}</Button>
                        <Button variant="outline" onClick={() => setCarrying(null)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Card>
    );
}

/* ─────────────────────────── السجلّ ─────────────────────────── */

function HistoryTab({ p }: { p: Props }) {
    const t = useTranslate();
    const o = p.operation;

    return (
        <Card className="p-4">
            <h3 className="mb-3 text-[14px] font-semibold">{t('ما جرى')}</h3>
            <dl className="grid gap-x-6 gap-y-2 text-[13px] sm:grid-cols-2">
                <Row label="على الهواء منذ" value={o.went_live_at} ltr />
                <Row label="أُتمّت" value={o.completed_at} ltr />
                <Row label="أُلغيت" value={o.cancelled_at} ltr />
            </dl>
            {o.readiness_override && o.readiness_override.length > 0 && (
                <div className="mt-4">
                    <h4 className="mb-2 text-[13px] font-semibold text-[#b91c1c]">{t('تجاوزات الجاهزيّة')}</h4>
                    <ul className="space-y-2 text-[13px]">
                        {o.readiness_override.map((ov, i) => (
                            <li key={i} className="rounded-lg bg-[#fef2f2] px-3 py-2">
                                <div><span className="font-medium">{ov.by ?? '—'}</span> · <span dir="ltr">{ov.at}</span> · {t('إلى')} «{ov.to}»</div>
                                <div className="text-[#b91c1c]">{ov.reason}</div>
                                <div className="text-[11px] text-[#6b7280]">{ov.blockers.join(' · ')}</div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            <p className="mt-4 text-[12px] text-[#9ca3af]">{t('وكلُّ فعلٍ — حجزٌ وتسليمٌ وردٌّ وتعيينٌ ونقلُ حال — مقيَّدٌ في سجلّ النشاط باسم فاعله.')}</p>
        </Card>
    );
}

/* ─────────────────────────── أدوات ─────────────────────────── */

function Alert({ tone, children }: { tone: 'danger' | 'warning'; children: ReactNode }) {
    return (
        <div className={'mb-4 rounded-lg px-4 py-3 text-[13px] ' + (tone === 'danger' ? 'bg-[#fef2f2] text-[#b91c1c]' : 'bg-[#fffbeb] text-[#b45309]')}>
            {children}
        </div>
    );
}

function Row({ label: text, value, ltr }: { label: string; value: string | null | undefined; ltr?: boolean }) {
    const t = useTranslate();
    if (!value) return null;

    return (
        <div className="flex justify-between gap-3 sm:block">
            <dt className="text-[#6b7280]">{t(text)}</dt>
            <dd className={ltr ? 'tabular-nums' : ''} dir={ltr ? 'ltr' : undefined}>{value}</dd>
        </div>
    );
}
