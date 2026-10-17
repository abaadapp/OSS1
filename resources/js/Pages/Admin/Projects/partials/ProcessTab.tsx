import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    Check,
    ChevronDown,
    Circle,
    ExternalLink,
    Eye,
    FileText,
    Lock,
    MessageCircle,
    Phone,
    Plus,
    Receipt,
    ShieldCheck,
    Smartphone,
    Unlock,
    Wallet,
    X,
} from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import CopyButton from '@/Components/CopyButton';
import SendMessageDialog from '@/Components/SendMessageDialog';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input, Textarea } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import { DELIVERY_STATE_LABEL, deliveryTone } from '@/lib/deliveries';
import { CO_STATE_LABEL, coTone } from '@/lib/projects';
import { operationTone } from '@/lib/operations';
import { STAGE_LABEL, type Stage, type StageKey, type Workflow, type WorkflowStage } from '@/lib/projectStages';
import { SCOPE_STATE_LABEL, scopeTone } from '@/lib/scopes';
import { cn } from '@/lib/utils';

/* ─────────────────────────── ما تقرؤه المراحل ─────────────────────────── */

export interface ProcessProject {
    id: number;
    name: string;
    customer: string;
    status: string;
    starts_at: string | null;
    ends_at: string | null;
    location: string | null;
    owner: string | null;
    owner_id: number | null;
    baselined: boolean;
    requirements_baselined_at: string | null;
    contact_person: string | null;
    contact_phone: string | null;
    contact_email: string | null;
    event_type: string | null;
    notes: string | null;
    internal_notes: string | null;
    closed_at: string | null;
    current_value: number | null;
    approved_value: number | null;
}

export interface QuotationInfo {
    id: number;
    label: string;
    title: string;
    total: number | null;
    sent_at: string | null;
    decided_at: string | null;
    decided_by_name: string | null;
    content_hash: string | null;
    public_url: string | null;
    evidence: {
        signer_name: string;
        signer_title: string | null;
        signer_email: string | null;
        signer_phone: string | null;
        decided_at: string | null;
        ip: string | null;
        consent_text: string | null;
        content_hash: string;
        verified: boolean;
    } | null;
}

export interface ScopeRevision {
    id: number;
    number: string;
    revision: number;
    status: string;
    state: string;
    sent_at: string | null;
    decided_at: string | null;
    decided_by_name?: string | null;
    view_count?: number;
    first_viewed_at?: string | null;
    last_viewed_at?: string | null;
    url?: string | null;
    content_hash?: string | null;
    stale?: boolean;
    decline_reason?: string | null;
    approval?: { signer_name: string; signer_title: string | null; decided_at: string | null; ip: string | null; verified: boolean } | null;
}

export interface ScopeInfo {
    required: boolean;
    latest: ScopeRevision | null;
    signed: ScopeRevision | null;
    revisions: ScopeRevision[];
}

export interface BillingInvoice {
    id: number;
    number: string | null;
    status: string;
    role: string | null;
    payment_state: string;
    issued_at: string | null;
    due_at: string | null;
    total: number | null;
    outstanding: number | null;
    percent: number | null;
    method: string | null;
}

export interface Billing {
    billed: boolean;
    paid: boolean;
    value: number | null;
    invoiced: number | null;
    gross_invoiced: number | null;
    credited: number | null;
    drafts: number | null;
    unbilled: number | null;
    billable: number | null;
    collected: number | null;
    outstanding: number | null;
    rate: number | null;
    deposit: { done: boolean; issued: number; drafts: number; waived: boolean; paid: boolean; waived_at: string | null; waiver_reason: string | null; invoices: BillingInvoice[] };
    final: { quote: { value: number; invoiced: number; credited: number; net_invoiced: number; amount: number; net: number; tax: number; total: number; rate: number; drafts: number } | null; invoices: BillingInvoice[] };
    may: { create: boolean; issue: boolean; waive: boolean };
}

export interface ProcessData {
    workflow: Workflow;
    quotation: QuotationInfo | null;
    scope: ScopeInfo;
    requirements: { active: number };
    members: { id: number; name: string | null; role: string | null }[];
    tasks: { id: number; title: string; status: string; due_at: string | null; assignee: string | null }[];
    change_orders: { id: number; label: string; title: string; state: string; status: string }[];
    operations: { id: number; code: string; name: string; status: string; status_label: string; venue_name: string | null; event_starts_at: string | null; ready: boolean; blockers: string[]; crew: { expected: number; checked_in: number }; equipment: { required: number; reserved: number }; checklist: { total: number; done: number }; incidents: { open: number }; url: string; onsite_url: string }[] | null;
    delivery: { readiness: { ready: boolean; blockers: string[] }; packages: { id: number; label: string; title: string; status: string; sent_at: string | null; decided_at: string | null }[] };
    billing: Billing;
    invoices: BillingInvoice[] | null;
    close_blockers: string[];
    channels: { email: boolean; whatsapp: boolean; scope_whatsapp_template: boolean };
    users: { id: number; name: string }[];
}

export interface ProcessMay {
    manage: boolean;
    close: boolean;
    reopen: boolean;
    change_order: boolean;
    delivery: boolean;
    operations: boolean;
    operations_manage: boolean;
    invoice: boolean;
    commercial: boolean;
}

/**
 * مسارُ المشروع — سبعُ مراحل، كلٌّ منها بما تمّ منها وما ينقصها وزرِّ خطوتها.
 *
 * ═══ الحقيقةُ من الخادم ═══
 *
 * أيُّ مرحلةٍ تمّت، وأين يقف الفريق، وما يمنع الوصولَ إلى ما بعدها —
 * كلُّه في `workflow` من `ProjectWorkflow`. وهنا يُرسم ويُضغط: كلُّ زرٍّ يمرّ
 * بالباب الذي تمرّ به الشاشاتُ الأخرى، والخادمُ يعيد السؤالَ قبل أن يكتب.
 *
 * و«تم — الانتقال» يظهر في المرحلة التي يقف عليها الفريق حين تتمّ، و«رجوع»
 * يعيد المؤشّر خطوةً للمراجعة — لا يمحو فاتورةً ولا توقيعًا.
 */
export default function ProcessTab({
    p,
    d,
    stages,
    may,
    m,
    focus,
    onTab,
}: {
    p: ProcessProject;
    d: ProcessData;
    stages: Stage[];
    may: ProcessMay;
    m: (v: number | null | undefined) => string;
    /** مرحلةٌ طُلبت من الشريط فوق — تُفتح ويُنزل إليها */
    focus: StageKey | null;
    onTab: (key: string) => void;
}) {
    const t = useTranslate();
    const open = p.status === 'confirmed' || p.status === 'in_progress' || p.status === 'on_hold';
    const wf = d.workflow;
    const byKey = useMemo(() => Object.fromEntries(wf.stages.map((s) => [s.key, s])) as Record<StageKey, WorkflowStage>, [wf.stages]);
    const [expanded, setExpanded] = useState<Set<StageKey>>(() => new Set([wf.focus]));
    const [sending, setSending] = useState(false);
    const [depositing, setDepositing] = useState(false);
    const [waiving, setWaiving] = useState(false);
    const [finalizing, setFinalizing] = useState(false);

    useEffect(() => {
        if (!focus) return;
        setExpanded((prev) => new Set(prev).add(focus));
        window.setTimeout(() => document.getElementById(`stage-${focus}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 30);
    }, [focus]);

    const toggle = (k: StageKey) =>
        setExpanded((prev) => {
            const next = new Set(prev);
            if (next.has(k)) next.delete(k);
            else next.add(k);
            return next;
        });

    const post = (name: string, data: Record<string, string> = {}) => router.post(route(name, p.id), data, { preserveScroll: true });
    const openTasks = d.tasks.filter((x) => x.status !== 'done');
    const today = new Date().toISOString().slice(0, 10);
    const pendingCo = d.change_orders.filter((c) => c.status === 'pending_approval' || c.status === 'sent');
    const scope = d.scope.latest;
    const b = d.billing;
    const liveOps = (d.operations ?? []).filter((o) => o.status !== 'cancelled');

    /* ما يُكتب تحت عنوان المرحلة وهي مطويّة — سطرٌ يقول أين هي */
    const summary: Record<StageKey, string> = {
        quote: d.quotation ? `${d.quotation.label}${d.quotation.decided_at ? ' · ' + t('قُبل في') + ' ' + d.quotation.decided_at : ''}` : t('مشروع بلا عرض سعر مقبول'),
        scope: !d.scope.required
            ? t('مشروعٌ مستورَد — بلا نطاق موقَّع')
            : d.scope.signed
              ? t(':n · وقّعه :name في :at', { n: d.scope.signed.number, name: d.scope.signed.decided_by_name ?? '—', at: d.scope.signed.decided_at ?? '' })
              : scope
                ? `${scope.number} · ${t(SCOPE_STATE_LABEL[scope.state] ?? scope.state)}`
                : t('لم يُعدّ نطاق العمل بعد'),
        deposit: b.deposit.waived
            ? t('بلا دفعة مقدمة')
            : b.deposit.invoices.length === 0
              ? t('لا فاتورة دفعة بعد')
              : b.deposit.invoices.map((i) => `${i.number ?? t('مسودة')} · ${t(i.status === 'صادرة' ? i.payment_state : i.status)}`).join(' — '),
        execution:
            d.operations === null ? t('العمليات خارج صلاحيتك') : liveOps.length === 0 ? t('لا فعاليّة مجدولة بعد') : liveOps.map((o) => `${o.name} · ${o.status_label}`).join(' — '),
        delivery: d.delivery.packages.length === 0 ? t('لا حزمة تسليم بعد') : d.delivery.packages.map((x) => `${x.label} · ${t(DELIVERY_STATE_LABEL[x.status] ?? x.status)}`).join(' — '),
        final:
            b.unbilled !== null && b.value !== null
                ? b.unbilled > 0.0005
                    ? t('باقٍ للفوترة :amount من :value', { amount: m(b.unbilled), value: m(b.value) })
                    : t('فُوترت القيمة كلّها')
                : b.billed
                  ? t('فُوترت القيمة كلّها')
                  : t('لم تُفوتر القيمة كلّها بعد'),
        closing: p.status === 'closed' ? t('أُقفل في :at', { at: p.closed_at ?? '' }) : d.close_blockers.length === 0 ? t('جاهز للإقفال') : t(':n مانع قبل الإقفال', { n: String(d.close_blockers.length) }),
    };

    const body: Record<StageKey, ReactNode> = {
        /* ─── عرض السعر ─── */
        quote: (
            <>
                {d.quotation ? (
                    <>
                        <Facts>
                            <Fact label="العرض">
                                <SmartLink routeName="admin.quotations.show" href={route('admin.quotations.show', d.quotation.id)} className="text-[#1d4ed8] hover:underline">
                                    {d.quotation.label}
                                </SmartLink>
                            </Fact>
                            <Fact label="قيمته">{m(d.quotation.total)}</Fact>
                            <Fact label="أُرسل في">{d.quotation.sent_at ?? '—'}</Fact>
                            <Fact label="قُبل في">{d.quotation.decided_at ?? '—'}</Fact>
                        </Facts>
                        <Evidence title="دليل القبول" evidence={d.quotation.evidence} fallbackName={d.quotation.decided_by_name} />
                    </>
                ) : (
                    <p className="text-[13px] text-[#a1a1aa]">{t('مشروع بلا عرض سعر مقبول')}</p>
                )}
                {d.change_orders.length > 0 && (
                    <div className="mt-3">
                        <div className="mb-1.5 text-[12px] font-medium text-[#71717a]">{t('أوامر التغيير')}</div>
                        <ul className="space-y-1">
                            {d.change_orders.map((c) => (
                                <li key={c.id} className="flex items-center justify-between gap-2 text-[13px]">
                                    <SmartLink routeName="admin.changeOrders.show" href={route('admin.changeOrders.show', c.id)} className="truncate text-[#1d4ed8] hover:underline">
                                        {c.label} — {c.title}
                                    </SmartLink>
                                    <span className={'shrink-0 rounded-full px-2 py-0.5 text-[11px] ' + coTone(c.state)}>{t(CO_STATE_LABEL[c.state] ?? c.state)}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
                <Actions>
                    {d.quotation && <LinkButton routeName="admin.quotations.show" href={route('admin.quotations.show', d.quotation.id)} icon={<FileText />} label="فتح العرض" />}
                    {d.quotation?.public_url && <ExternalButton href={d.quotation.public_url} label="العرض كما قبله العميل" />}
                    {open && may.change_order && <LinkButton routeName="admin.changeOrders.create" href={route('admin.changeOrders.create', p.id)} icon={<Plus />} label="أمر تغيير" />}
                </Actions>
            </>
        ),

        /* ─── نطاق العمل ─── */
        scope: !d.scope.required ? (
            <p className="text-[13px] text-[#71717a]">{t('مشروعٌ مستورَد من التاريخ — جرى قبل النظام، فلا يُطلب له نطاقٌ موقَّع.')}</p>
        ) : (
            <>
                {scope === null ? (
                    <p className="text-[13px] text-[#71717a]">{t('نطاق العمل مستندٌ يوقّعه العميل قبل الدفعة والتنفيذ: البنود والمتطلبات من النطاق المعتمد، ومعها وصف التنفيذ وما يشمله وما لا يشمله ومسؤوليات الطرفين.')}</p>
                ) : (
                    <>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-mono text-[12.5px] text-[#3f3f46]" dir="ltr">{scope.number}</span>
                            <span className={'rounded-full px-2 py-0.5 text-[11px] ' + scopeTone(scope.state)}>{t(SCOPE_STATE_LABEL[scope.state] ?? scope.state)}</span>
                            {scope.sent_at && <span className="text-[12px] text-[#71717a]">{t('أُرسل في :at', { at: scope.sent_at })}</span>}
                            {(scope.view_count ?? 0) > 0 && <span className="inline-flex items-center gap-1 text-[12px] text-[#71717a]"><Eye className="size-3.5" />{t('فُتح :n مرّة', { n: String(scope.view_count) })}</span>}
                        </div>
                        {scope.state === 'declined' && scope.decline_reason && (
                            <p className="mt-2 rounded-[8px] bg-[#fffbeb] px-2.5 py-1.5 text-[12.5px] text-[#92400e]">{t('طلب العميل تعديلًا')}: {scope.decline_reason}</p>
                        )}
                        {d.scope.signed && d.scope.signed.id !== scope.id && (
                            <p className="mt-2 text-[12.5px] text-[#71717a]">{t('النسخة الموقَّعة السارية: :n — حتى تُوقَّع الأحدث.', { n: d.scope.signed.number })}</p>
                        )}
                        {d.scope.signed?.approval && (
                            <Check2 ok={d.scope.signed.approval.verified}>
                                {t('وقّعه :name', { name: d.scope.signed.approval.signer_name })}
                                {d.scope.signed.approval.signer_title ? ` — ${d.scope.signed.approval.signer_title}` : ''} · {d.scope.signed.approval.decided_at}
                                {' · '}
                                {d.scope.signed.approval.verified ? t('البصمة تطابق') : t('البصمة لا تطابق — تغيّر المستند بعد القرار')}
                            </Check2>
                        )}
                        {scope.state === 'sent' || scope.state === 'opened' ? (
                            !d.channels.whatsapp ? (
                                <p className="mt-2 text-[12px] text-[#b45309]">{t('واتساب غير موصول — انسخ الرابط وأرسله يدويًا.')}</p>
                            ) : !d.channels.scope_whatsapp_template ? (
                                <p className="mt-2 text-[12px] text-[#b45309]">{t('لا قالب واتساب معتمَد لنطاق العمل بعد — اضبطه من «المراسلات ‹ القوالب» أو انسخ الرابط.')}</p>
                            ) : null
                        ) : null}
                    </>
                )}
                <Actions>
                    {scope === null && open && may.manage && (
                        <Button onClick={() => post('admin.projects.scope.store')}>
                            <FileText />
                            {t('إعداد نطاق العمل')}
                        </Button>
                    )}
                    {scope !== null && <LinkButton strong={!byKey.scope.done && wf.focus === 'scope'} routeName="admin.projects.scope.show" href={route('admin.projects.scope.show', p.id)} icon={<FileText />} label="فتح نطاق العمل" />}
                    {scope?.url && (scope.state === 'sent' || scope.state === 'opened') && (
                        <>
                            <CopyButton text={scope.url} label="نسخ الرابط" size="md" />
                            <Button variant="outline" onClick={() => setSending(true)}>
                                <MessageCircle />
                                {t('إرسال عبر واتساب')}
                            </Button>
                        </>
                    )}
                    {scope?.url && <ExternalButton href={scope.url} label="الصفحة كما يراها العميل" />}
                </Actions>
                {scope && <SendMessageDialog subjectType="scope_of_work" subjectId={scope.id} open={sending} onOpenChange={setSending} channel="whatsapp" />}
            </>
        ),

        /* ─── الدفعة المقدّمة ─── */
        deposit: (
            <>
                {b.value !== null && (
                    <div className="grid gap-2 sm:grid-cols-3">
                        <Figure label="قيمة المشروع الحالية" value={m(b.value)} />
                        <Figure label="صافي ما فُوتر" value={m(b.invoiced)} />
                        <Figure label="باقٍ للفوترة" value={m(b.unbilled)} tone={(b.unbilled ?? 0) > 0 ? undefined : 'ok'} />
                    </div>
                )}
                {b.deposit.waived && (
                    <Check2 ok>
                        {t('بلا دفعة مقدمة')} — {b.deposit.waiver_reason} · {b.deposit.waived_at}
                    </Check2>
                )}
                <InvoiceList rows={b.deposit.invoices} m={m} empty="لا فاتورة دفعة مقدمة بعد." />
                {b.deposit.invoices.some((i) => i.status === 'صادرة') && (
                    <p className="mt-2 text-[12px] text-[#71717a]">{b.deposit.paid ? t('حُصّلت الدفعة المقدمة كاملة.') : t('المرحلة تتمّ بإصدار الفاتورة — والتحصيل يُتابع منفصلًا.')}</p>
                )}
                {!byKey.scope.done && <Blockers items={[t('الدفعة المقدمة بعد توقيع العميل على نطاق العمل.')]} soft />}
                <Actions>
                    {open && b.may.create && byKey.scope.done && (
                        <Button variant={byKey.deposit.done ? 'outline' : 'primary'} onClick={() => setDepositing(true)}>
                            <Wallet />
                            {b.deposit.invoices.some((i) => i.status !== 'ملغاة') ? t('دفعة إضافية') : t('إنشاء فاتورة الدفعة المقدمة')}
                        </Button>
                    )}
                    {open && b.may.waive && !b.deposit.waived && !byKey.deposit.done && (
                        <Button variant="ghost" onClick={() => setWaiving(true)}>
                            {t('بلا دفعة مقدمة')}
                        </Button>
                    )}
                </Actions>
                <DepositDialog projectId={p.id} open={depositing} onOpenChange={setDepositing} billing={b} m={m} hasDeposit={b.deposit.invoices.some((i) => i.status !== 'ملغاة')} />
                <WaiveDialog projectId={p.id} open={waiving} onOpenChange={setWaiving} />
            </>
        ),

        /* ─── التنفيذ ─── */
        execution: (
            <>
                <p className="mb-2 text-[12px] text-[#71717a]">{t('تكتمل المرحلة حين تُتمّ الفعاليّة في شاشة العمليات ولا تبقى فعاليّةٌ قائمة لم تكتمل.')}</p>
                {d.operations === null ? (
                    <p className="text-[13px] text-[#a1a1aa]">{t('العمليات خارج صلاحيتك')}</p>
                ) : d.operations.length === 0 ? (
                    <p className="text-[13px] text-[#a1a1aa]">{t('لا فعاليّة مجدولة بعد — حدّد موعدها ومكانها ثمّ جهّز فريقها ومعدّاتها.')}</p>
                ) : (
                    <ul className="space-y-2">
                        {d.operations.map((o) => (
                            <li key={o.id} className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="min-w-0">
                                        <a href={o.url} className="font-semibold text-[#18181b] hover:underline">{o.name}</a>
                                        <div className="text-[12px] text-[#71717a]">
                                            <span dir="ltr" className="tabular-nums">{o.event_starts_at ?? '—'}</span>
                                            {o.venue_name ? ` · ${o.venue_name}` : ''}
                                        </div>
                                    </div>
                                    <span className={'rounded-full px-2 py-0.5 text-[11px] ' + operationTone(o.status)}>{o.status_label}</span>
                                </div>
                                {!['completed', 'cancelled'].includes(o.status) && (
                                    <div className="mt-2 flex flex-wrap gap-1.5 text-[12px]">
                                        <Chip ok={o.ready} label={o.ready ? t('جاهزة') : t('غير جاهزة')} />
                                        <Chip ok={o.crew.expected > 0} label={t('الفريق :n', { n: String(o.crew.expected) })} />
                                        <Chip ok={o.equipment.required === 0 || o.equipment.reserved >= o.equipment.required} label={t('المعدّات :a/:b', { a: String(o.equipment.reserved), b: String(o.equipment.required) })} />
                                        <Chip ok={o.checklist.total === 0 || o.checklist.done === o.checklist.total} label={t('التحقّق :a/:b', { a: String(o.checklist.done), b: String(o.checklist.total) })} />
                                        {o.incidents.open > 0 && <Chip ok={false} label={t(':n بلاغ مفتوح', { n: String(o.incidents.open) })} />}
                                    </div>
                                )}
                                {!o.ready && o.blockers.length > 0 && !['completed', 'cancelled'].includes(o.status) && <Blockers items={o.blockers.slice(0, 4)} />}
                                <div className="mt-2 flex flex-wrap gap-2">
                                    <a href={o.url} className="inline-flex h-8 items-center gap-1 rounded-[8px] border border-[var(--ui-border,#e8e8e8)] px-3 text-[12.5px] font-medium hover:bg-[#f4f4f5]">{t('فتح الفعاليّة')}</a>
                                    <a href={o.onsite_url} className="inline-flex h-8 items-center gap-1 rounded-[8px] border border-[var(--ui-border,#e8e8e8)] px-3 text-[12.5px] font-medium hover:bg-[#f4f4f5]">
                                        <Smartphone className="size-3.5" />
                                        {t('شاشة الموقع')}
                                    </a>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                {d.operations !== null && open && (
                    <Actions>
                        {may.operations_manage ? (
                            <Button variant={liveOps.length === 0 ? 'primary' : 'outline'} onClick={() => router.post(route('admin.operations.store'), { project_id: p.id })}>
                                <Plus />
                                {liveOps.length === 0 ? t('جدولة الفعاليّة') : t('فعاليّة أخرى')}
                            </Button>
                        ) : (
                            <Button variant="outline" onClick={() => onTab('operations')}>{t('العمليات')}</Button>
                        )}
                    </Actions>
                )}
            </>
        ),

        /* ─── التسليم ─── */
        delivery: (
            <>
                {d.delivery.packages.length === 0 ? (
                    <p className="text-[13px] text-[#a1a1aa]">{t('لا حزمة تسليم بعد — ما يُسلَّم للعميل في ختام العمل، ويقبله فتنتقل المرحلة إلى الفاتورة النهائية.')}</p>
                ) : (
                    <ul className="divide-y divide-[#f3f4f6]">
                        {d.delivery.packages.map((x) => (
                            <li key={x.id} className="flex items-center justify-between gap-3 py-2 text-[13px]">
                                <div className="min-w-0">
                                    <SmartLink routeName="admin.deliveries.show" href={route('admin.deliveries.show', x.id)} className="font-medium text-[#1d4ed8] hover:underline">{x.label}</SmartLink>
                                    <div className="truncate text-[12px] text-[#71717a]">{x.title}</div>
                                </div>
                                <div className="text-end">
                                    <span className={'rounded-full px-2 py-0.5 text-[11px] ' + deliveryTone(x.status)}>{t(DELIVERY_STATE_LABEL[x.status] ?? x.status)}</span>
                                    <div className="text-[11px] text-[#a1a1aa]" dir="ltr">{x.decided_at ?? x.sent_at ?? ''}</div>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                {open && !d.delivery.readiness.ready && d.delivery.packages.length > 0 && <Blockers items={d.delivery.readiness.blockers} />}
                <Actions>
                    {open && may.delivery && (
                        <LinkButton strong={d.delivery.packages.length === 0 && wf.focus === 'delivery'} routeName="admin.deliveries.create" href={route('admin.deliveries.create', { project_id: p.id })} icon={<Plus />} label="حزمة تسليم جديدة" />
                    )}
                </Actions>
            </>
        ),

        /* ─── الفاتورة النهائيّة ─── */
        final: (
            <>
                {b.final.quote && (
                    <dl className="space-y-1 rounded-[12px] bg-[#fafafa] p-3 text-[13px]">
                        <Line label="القيمة الحالية للمشروع" hint="الأصل + أوامر التغيير المقبولة" value={m(b.final.quote.value)} />
                        <Line label="المفوتَر" value={m(b.final.quote.invoiced)} />
                        {b.final.quote.credited > 0 && <Line label="إشعارات دائن" value={`− ${m(b.final.quote.credited)}`} />}
                        <Line label="صافي ما فُوتر" value={`− ${m(b.final.quote.net_invoiced)}`} />
                        <div className="my-1 border-t border-[#e4e4e7]" />
                        <Line label="الفاتورة النهائية" value={m(b.final.quote.amount)} strong />
                        {b.final.quote.rate > 0 && b.final.quote.amount > 0 && (
                            <p className="text-[11.5px] text-[#a1a1aa]">{t('منها ضريبة :tax (:rate٪) على صافٍ :net', { tax: m(b.final.quote.tax), rate: String(b.final.quote.rate), net: m(b.final.quote.net) })}</p>
                        )}
                    </dl>
                )}
                <InvoiceList rows={b.final.invoices} m={m} empty="لا فاتورة نهائية بعد." />
                {b.collected !== null && b.outstanding !== null && (b.invoiced ?? 0) > 0 && (
                    <div className="mt-3 grid gap-2 sm:grid-cols-2">
                        <Figure label="حُصّل من المشروع" value={m(b.collected)} tone="ok" />
                        <Figure label="باقٍ على العميل" value={m(b.outstanding)} tone={b.outstanding > 0.0005 ? 'warn' : 'ok'} />
                    </div>
                )}
                {!byKey.delivery.done && !byKey.final.done && <Blockers items={[t('الفاتورة النهائية بعد قبول التسليم.')]} soft />}
                {(b.drafts ?? 0) > 0 && <Blockers items={[t('على المشروع فاتورةٌ مسودّة — أصدرها أو احذفها قبل الفاتورة النهائية.')]} />}
                <Actions>
                    {open && b.may.create && byKey.delivery.done && !byKey.final.done && (b.drafts ?? 0) === 0 && (
                        <Button onClick={() => setFinalizing(true)}>
                            <Receipt />
                            {t('إنشاء الفاتورة النهائية')}
                        </Button>
                    )}
                </Actions>
                <FinalDialog projectId={p.id} open={finalizing} onOpenChange={setFinalizing} billing={b} m={m} />
            </>
        ),

        /* ─── الإغلاق ─── */
        closing: (
            <>
                {p.status === 'closed' ? (
                    <Check2 ok>{t('أُقفل المشروع في :at — وجُمّدت أرقامه.', { at: p.closed_at ?? '' })}</Check2>
                ) : p.status === 'cancelled' ? (
                    <Check2 ok={false}>{t('المشروع ملغى.')}</Check2>
                ) : d.close_blockers.length === 0 ? (
                    <Check2 ok>{t('لا مانع — المشروع جاهز للإقفال.')}</Check2>
                ) : (
                    <>
                        <p className="mb-1 text-[13px] text-[#52525b]">{t('قبل الإقفال:')}</p>
                        <Blockers items={d.close_blockers} />
                    </>
                )}
                {pendingCo.length > 0 && open && <p className="mt-2 text-[12px] text-[#71717a]">{t('أوامر التغيير المعلّقة في مرحلة «عرض السعر» أعلاه.')}</p>}
                <Actions>
                    {open && may.close && (
                        <Button disabled={d.close_blockers.length > 0} onClick={() => post('admin.projects.close')}>
                            <Lock />
                            {t('إقفال المشروع')}
                        </Button>
                    )}
                    {p.status === 'closed' && may.reopen && (
                        <Button variant="outline" onClick={() => post('admin.projects.reopen')}>
                            <Unlock />
                            {t('إعادة فتح')}
                        </Button>
                    )}
                </Actions>
            </>
        ),
    };

    return (
        <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
            {/* ─────────── المراحل ─────────── */}
            <ol className="relative min-w-0 space-y-3">
                <span className="absolute inset-y-4 start-[19px] w-px bg-[#e4e4e7]" aria-hidden />
                {stages.map((s) => {
                    const isOpen = expanded.has(s.key);
                    const Icon = s.icon;
                    const w = byKey[s.key];
                    const locked = !s.focus && s.state === 'todo';

                    return (
                        <li key={s.key} id={`stage-${s.key}`} className="relative scroll-mt-24 ps-12">
                            <span
                                className={cn(
                                    'absolute start-0 top-3 flex size-10 items-center justify-center rounded-full border-4 border-white',
                                    s.state === 'done' && 'bg-[#6d28d9] text-white',
                                    s.state === 'current' && 'bg-[#f3e8ff] text-[#6d28d9] ring-2 ring-[#6d28d9]',
                                    (s.state === 'todo' || s.state === 'skipped') && 'bg-[#f4f4f5] text-[#a1a1aa]',
                                    s.state === 'failed' && 'bg-[#dc2626] text-white',
                                    s.focus && s.state === 'done' && 'ring-2 ring-[#c4b5fd]',
                                )}
                            >
                                {s.state === 'done' ? <Check className="size-4" strokeWidth={3} /> : s.state === 'failed' ? <X className="size-4" strokeWidth={3} /> : <Icon className="size-4" />}
                            </span>

                            <div
                                className={cn(
                                    'overflow-hidden rounded-[14px] border bg-white transition-shadow',
                                    s.focus ? 'border-[#c4b5fd] shadow-[0_6px_24px_-8px_rgba(109,40,217,0.25)]' : 'border-[var(--ui-border,#e8e8e8)]',
                                )}
                            >
                                <button type="button" onClick={() => toggle(s.key)} aria-expanded={isOpen} className="flex w-full items-center gap-3 px-4 py-3 text-start">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className={cn('text-[15px] font-semibold', s.state === 'todo' || s.state === 'skipped' ? 'text-[#71717a]' : 'text-[#18181b]')}>{t(s.label)}</span>
                                            {s.focus && <span className="rounded-full bg-[#6d28d9] px-2 py-0.5 text-[10.5px] font-medium text-white">{t('الآن')}</span>}
                                            {s.state === 'done' && <span className="text-[11.5px] text-[#6d28d9]">{t('تمّت')}</span>}
                                            {locked && <Lock className="size-3.5 text-[#a1a1aa]" aria-label={t('لم تُفتح بعد')} />}
                                            {s.note && <span className="rounded-full bg-[#fffbeb] px-2 py-0.5 text-[10.5px] font-medium text-[#b45309]">{t(s.note)}</span>}
                                        </div>
                                        <div className="mt-0.5 truncate text-[12.5px] text-[#71717a]">{summary[s.key]}</div>
                                    </div>
                                    <ChevronDown className={cn('size-4 shrink-0 text-[#a1a1aa] transition-transform', isOpen && 'rotate-180')} />
                                </button>
                                {isOpen && (
                                    <div className="border-t border-[#f0f0f0] px-4 py-3">
                                        {locked && (w?.blocked_by?.length ?? 0) > 0 && (
                                            <div className="mb-3 rounded-[10px] bg-[#f4f4f5] px-3 py-2 text-[12.5px] text-[#52525b]">
                                                <div className="mb-1 flex items-center gap-1.5 font-medium">
                                                    <Lock className="size-3.5" />
                                                    {t('لا تُفتح هذه المرحلة بعد')}
                                                </div>
                                                <ul className="list-disc space-y-0.5 ps-5">
                                                    {w!.blocked_by!.map((x) => (
                                                        <li key={x}>{x}</li>
                                                    ))}
                                                </ul>
                                            </div>
                                        )}
                                        {s.focus && !w?.done && (w?.pending?.length ?? 0) > 0 && s.key !== 'closing' && <Blockers items={w!.pending!} soft />}
                                        <div className={cn(s.focus && !w?.done && (w?.pending?.length ?? 0) > 0 && 'mt-3')}>{body[s.key]}</div>
                                        {s.focus && open && may.manage && <StageNav wf={wf} projectId={p.id} />}
                                    </div>
                                )}
                            </div>
                        </li>
                    );
                })}
            </ol>

            {/*
                ─────────── الجانب: من وأين وكم ───────────
                والعميلُ والموعدُ والقيمةُ الحاليّة في رأس الصفحة — لا تُعاد هنا.
            */}
            <aside className="min-w-0 space-y-3">
                <Side title="الفعاليّة">
                    <Facts single>
                        {p.event_type && <Fact label="النوع">{p.event_type}</Fact>}
                        {p.location && <Fact label="المكان">{p.location}</Fact>}
                        <Fact label="المسؤول">{p.owner ?? '—'}</Fact>
                    </Facts>
                    {may.manage && open && <OwnerPicker p={p} users={d.users} />}
                </Side>

                {(p.contact_person || p.contact_phone || p.contact_email) && (
                    <Side title="جهة الاتصال">
                        {p.contact_person && <div className="text-[13.5px] font-medium">{p.contact_person}</div>}
                        <div className="mt-1 flex flex-wrap gap-2">
                            {p.contact_phone && (
                                <a href={`tel:${p.contact_phone}`} className="inline-flex items-center gap-1 rounded-[8px] bg-[#f4f4f5] px-2 py-1 text-[12.5px] hover:bg-[#e9e9eb]" dir="ltr">
                                    <Phone className="size-3.5" />
                                    {p.contact_phone}
                                </a>
                            )}
                            {p.contact_email && (
                                <a href={`mailto:${p.contact_email}`} className="truncate rounded-[8px] bg-[#f4f4f5] px-2 py-1 text-[12.5px] hover:bg-[#e9e9eb]" dir="ltr">
                                    {p.contact_email}
                                </a>
                            )}
                        </div>
                    </Side>
                )}

                {b.value !== null && (
                    <Side title="المال">
                        <Facts single>
                            <Fact label="فُوتر">{m(b.invoiced)}</Fact>
                            <Fact label="حُصّل">{m(b.collected)}</Fact>
                            <Fact label="باقٍ على العميل">{m(b.outstanding)}</Fact>
                        </Facts>
                    </Side>
                )}

                <Side title="الفريق والمهامّ" action={<MiniButton onClick={() => onTab('tasks')}>{t('الكلّ')}</MiniButton>}>
                    <div className="mb-2 text-[12.5px] text-[#52525b]">
                        {d.members.length > 0 ? t('الفريق: :names', { names: d.members.map((x) => x.name).filter(Boolean).slice(0, 4).join('، ') }) : t('لا أحد في فريق المشروع')}
                    </div>
                    {openTasks.length === 0 ? (
                        <p className="text-[12.5px] text-[#a1a1aa]">{t('لا مهامّ مفتوحة')}</p>
                    ) : (
                        <ul className="space-y-1.5">
                            {openTasks.slice(0, 5).map((x) => (
                                <li key={x.id} className="flex items-center justify-between gap-2 text-[12.5px]">
                                    <span className="truncate">{x.title}</span>
                                    {x.due_at && (
                                        <span className={cn('shrink-0 tabular-nums', x.due_at < today ? 'font-medium text-[#b91c1c]' : 'text-[#71717a]')} dir="ltr">
                                            {x.due_at.slice(5)}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Side>
            </aside>
        </div>
    );
}

/* ─────────────────────────── التنقّل ─────────────────────────── */

/**
 * «تم — الانتقال» و«رجوع» — في المرحلة التي يقف عليها الفريق وحدَها.
 *
 * والانتقالُ معطَّلٌ حتى تتمّ المرحلة (والخادمُ يردّه على كلّ حال)، والرجوعُ
 * خطوةٌ للمراجعة: يقول قبل الضغط إنّه لا يمحو شيئًا.
 */
export function StageNav({ wf, projectId }: { wf: Workflow; projectId: number }) {
    const t = useTranslate();
    const [confirmBack, setConfirmBack] = useState(false);
    const go = (name: string) => router.post(route(name, projectId), {}, { preserveScroll: true });

    if (!wf.next && !wf.can_back) return null;

    return (
        <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-dashed border-[#e4e4e7] pt-3">
            {wf.can_back && wf.previous ? (
                <Button variant="ghost" size="sm" onClick={() => setConfirmBack(true)}>
                    <ArrowRight className="ltr:rotate-180" />
                    {t('رجوع إلى :stage', { stage: t(STAGE_LABEL[wf.previous]) })}
                </Button>
            ) : (
                <span />
            )}
            {wf.next && (
                <Button disabled={!wf.can_advance} onClick={() => go('admin.projects.workflow.advance')} title={wf.can_advance ? undefined : t('تكتمل المرحلة أولًا')}>
                    {t('تم — الانتقال إلى :stage', { stage: t(STAGE_LABEL[wf.next]) })}
                    <ArrowLeft className="ltr:rotate-180" />
                </Button>
            )}
            <Dialog open={confirmBack} onOpenChange={setConfirmBack}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('رجوع إلى :stage', { stage: t(STAGE_LABEL[wf.previous ?? 'quote']) })}</DialogTitle>
                        <DialogDescription>
                            {t('الرجوع خطوةٌ للمراجعة: لا تُلغى فاتورة، ولا يُنقض توقيع، ولا يُعكس قيد. وما يحتاج تغييرًا بعدها له بابه — نسخةٌ جديدة من نطاق العمل، أو أمر تغيير، أو إلغاءٌ وإشعار دائن لفاتورة صدرت.')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirmBack(false)}>{t('تراجع')}</Button>
                        <Button onClick={() => { go('admin.projects.workflow.back'); setConfirmBack(false); }}>{t('رجوع')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

/* ─────────────────────────── نوافذُ الفوترة ─────────────────────────── */

interface DepositQuote {
    method: string;
    percent: number | null;
    share: number | null;
    amount: number;
    net: number;
    tax: number;
    total: number;
    rate: number;
    value: number;
    billable_before: number;
    remaining_after: number;
}

const newKey = () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `k-${Date.now()}-${Math.random().toString(36).slice(2)}`);

/**
 * فاتورةُ الدفعة المقدّمة — نسبةٌ (٥٠٪ افتراضًا) أو مبلغٌ ثابت.
 *
 * الأرقامُ تُسأل من الخادم وهي تُكتب (`billing.preview`)، والإنشاءُ يعيد
 * الحسابَ نفسَه هناك: ما يظهر هنا معاينةٌ لا رقمٌ يُرسَل. ومفتاحُ الطلب
 * يُولد مع كلّ فتحٍ للنافذة — ضغطتان على «إنشاء» فاتورةٌ واحدة.
 */
function DepositDialog({ projectId, open, onOpenChange, billing, m, hasDeposit }: { projectId: number; open: boolean; onOpenChange: (v: boolean) => void; billing: Billing; m: (v: number | null | undefined) => string; hasDeposit: boolean }) {
    const t = useTranslate();
    const [method, setMethod] = useState<'percent' | 'fixed'>('percent');
    const [percent, setPercent] = useState('50');
    const [amount, setAmount] = useState('');
    const [issue, setIssue] = useState(billing.may.issue);
    const [additional, setAdditional] = useState(false);
    const [quote, setQuote] = useState<DepositQuote | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [key, setKey] = useState(newKey);

    useEffect(() => {
        if (open) {
            setKey(newKey());
            setIssue(billing.may.issue);
            setAdditional(false);
        }
    }, [open, billing.may.issue]);

    useEffect(() => {
        if (!open) return;
        const value = method === 'percent' ? percent : amount;
        if (value.trim() === '') {
            setQuote(null);
            setError(null);
            return;
        }
        const ctrl = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(route('admin.projects.billing.preview', { id: projectId, method, [method === 'percent' ? 'percent' : 'amount']: value }), { headers: { Accept: 'application/json' }, signal: ctrl.signal })
                .then((r) => r.json())
                .then((data: { quote: DepositQuote | null; error: string | null }) => {
                    setQuote(data.quote);
                    setError(data.error);
                })
                .catch(() => undefined);
        }, 250);
        return () => {
            window.clearTimeout(timer);
            ctrl.abort();
        };
    }, [open, method, percent, amount, projectId]);

    const submit = () => {
        setBusy(true);
        router.post(
            route('admin.projects.billing.deposit', projectId),
            { method, percent: method === 'percent' ? percent : '', amount: method === 'fixed' ? amount : '', issue: issue ? '1' : '0', additional: additional ? '1' : '0', request_key: key },
            { preserveScroll: true, onSuccess: () => onOpenChange(false), onFinish: () => setBusy(false) },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('فاتورة الدفعة المقدمة')}</DialogTitle>
                    <DialogDescription>{t('فاتورة عميل حقيقية على المشروع — تدخل الذمم والدفتر عند الإصدار. والمبلغ يحسبه النظام من القيمة الحالية.')}</DialogDescription>
                </DialogHeader>
                <div className="space-y-3 px-5">
                    <div className="inline-flex rounded-[10px] bg-[#f4f4f5] p-1 text-[13px]">
                        {(['percent', 'fixed'] as const).map((k) => (
                            <button key={k} type="button" onClick={() => setMethod(k)} className={cn('rounded-[8px] px-3 py-1.5 font-medium', method === k ? 'bg-white shadow-sm' : 'text-[#71717a]')}>
                                {k === 'percent' ? t('نسبة من القيمة') : t('مبلغ ثابت')}
                            </button>
                        ))}
                    </div>
                    {method === 'percent' ? (
                        <Field label="النسبة ٪" hint="٥٠٪ افتراضًا — وتُغيَّر لكل مشروع">
                            <Input dir="ltr" step="0.01" value={percent} onChange={(e) => setPercent(e.target.value)} />
                        </Field>
                    ) : (
                        <Field label="المبلغ (شامل الضريبة)">
                            <Input dir="ltr" step="0.001" value={amount} onChange={(e) => setAmount(e.target.value)} />
                        </Field>
                    )}

                    {error && <p className="rounded-[8px] bg-[#fef2f2] px-3 py-2 text-[12.5px] text-[#b91c1c]">{error}</p>}
                    {quote && (
                        <dl className="space-y-1 rounded-[12px] bg-[#fafafa] p-3 text-[13px]">
                            <Line label="قيمة المشروع الحالية" value={m(quote.value)} />
                            <Line label="طريقة الحساب" value={quote.method === 'percent' ? t('نسبة :p٪', { p: String(quote.percent) }) : t('مبلغ ثابت (:p٪ من القيمة)', { p: String(quote.share ?? 0) })} />
                            {quote.rate > 0 && <Line label="الصافي" value={m(quote.net)} />}
                            {quote.rate > 0 && <Line label="الضريبة" hint={`${quote.rate}%`} value={m(quote.tax)} />}
                            <Line label="مبلغ الدفعة" value={m(quote.total)} strong />
                            <div className="my-1 border-t border-[#e4e4e7]" />
                            <Line label="المتبقي بعد هذه الفاتورة" value={m(quote.remaining_after)} />
                        </dl>
                    )}

                    {billing.may.issue ? (
                        <label className="flex items-center gap-2 text-[13px]">
                            <input type="checkbox" checked={issue} onChange={(e) => setIssue(e.target.checked)} />
                            {t('إصدار الفاتورة الآن — يُقطع رقمها ويُقيَّد في الدفتر')}
                        </label>
                    ) : (
                        <p className="text-[12px] text-[#71717a]">{t('تُحفظ مسودّةً — يصدرها من يملك صلاحية «إصدار فاتورة عميل».')}</p>
                    )}
                    {hasDeposit && (
                        <label className="flex items-center gap-2 rounded-[8px] bg-[#fffbeb] px-2.5 py-2 text-[13px] text-[#92400e]">
                            <input type="checkbox" checked={additional} onChange={(e) => setAdditional(e.target.checked)} />
                            {t('على المشروع دفعة مقدمة — هذه دفعة إضافية مقصودة')}
                        </label>
                    )}
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>{t('تراجع')}</Button>
                    <Button disabled={busy || quote === null || (hasDeposit && !additional)} onClick={submit}>
                        <Wallet />
                        {issue ? t('إصدار الدفعة') : t('حفظ مسودّة')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** الفاتورةُ النهائيّة — بلا حقل مبلغ: الخادمُ يحسبها، والنافذةُ تعرض حسابَه */
function FinalDialog({ projectId, open, onOpenChange, billing, m }: { projectId: number; open: boolean; onOpenChange: (v: boolean) => void; billing: Billing; m: (v: number | null | undefined) => string }) {
    const t = useTranslate();
    const [issue, setIssue] = useState(billing.may.issue);
    const [busy, setBusy] = useState(false);
    const [key, setKey] = useState(newKey);
    const q = billing.final.quote;

    useEffect(() => {
        if (open) {
            setKey(newKey());
            setIssue(billing.may.issue);
        }
    }, [open, billing.may.issue]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('الفاتورة النهائية')}</DialogTitle>
                    <DialogDescription>{t('القيمة الحالية بعد أوامر التغيير − صافي ما فُوتر بعد إشعارات الدائن. يحسبها النظام ولا تتجاوز قيمة المشروع.')}</DialogDescription>
                </DialogHeader>
                <div className="space-y-3 px-5">
                    {q ? (
                        <dl className="space-y-1 rounded-[12px] bg-[#fafafa] p-3 text-[13px]">
                            <Line label="القيمة الحالية للمشروع" value={m(q.value)} />
                            <Line label="صافي ما فُوتر" value={`− ${m(q.net_invoiced)}`} />
                            <div className="my-1 border-t border-[#e4e4e7]" />
                            <Line label="الفاتورة النهائية" value={m(q.amount)} strong />
                        </dl>
                    ) : (
                        <p className="text-[13px] text-[#71717a]">{t('المبلغ يحسبه الخادم من القيمة الحالية وما فُوتر.')}</p>
                    )}
                    {billing.may.issue ? (
                        <label className="flex items-center gap-2 text-[13px]">
                            <input type="checkbox" checked={issue} onChange={(e) => setIssue(e.target.checked)} />
                            {t('إصدار الفاتورة الآن — يُقطع رقمها ويُقيَّد في الدفتر')}
                        </label>
                    ) : (
                        <p className="text-[12px] text-[#71717a]">{t('تُحفظ مسودّةً — يصدرها من يملك صلاحية «إصدار فاتورة عميل».')}</p>
                    )}
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>{t('تراجع')}</Button>
                    <Button
                        disabled={busy}
                        onClick={() => {
                            setBusy(true);
                            router.post(route('admin.projects.billing.final', projectId), { issue: issue ? '1' : '0', request_key: key }, { preserveScroll: true, onSuccess: () => onOpenChange(false), onFinish: () => setBusy(false) });
                        }}
                    >
                        <Receipt />
                        {issue ? t('إصدار الفاتورة النهائية') : t('حفظ مسودّة')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function WaiveDialog({ projectId, open, onOpenChange }: { projectId: number; open: boolean; onOpenChange: (v: boolean) => void }) {
    const t = useTranslate();
    const [reason, setReason] = useState('');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('بلا دفعة مقدمة')}</DialogTitle>
                    <DialogDescription>{t('قرارٌ يُسجَّل باسمك وسببه — وتكتمل به مرحلة الدفعة المقدمة. ويبقى ممكنًا إصدار دفعة لاحقًا.')}</DialogDescription>
                </DialogHeader>
                <div className="px-5">
                    <Field label="السبب" required>
                        <Textarea rows={2} value={reason} onChange={(e) => setReason(e.target.value)} placeholder={t('عميلٌ حكوميّ يدفع بعد التسليم')} />
                    </Field>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>{t('تراجع')}</Button>
                    <Button disabled={reason.trim() === ''} onClick={() => router.post(route('admin.projects.billing.waive', projectId), { reason }, { preserveScroll: true, onSuccess: () => onOpenChange(false) })}>
                        {t('تسجيل القرار')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/* ─────────────────────────── قطعٌ صغيرة ─────────────────────────── */

function Evidence({ title, evidence, fallbackName }: { title: string; evidence: QuotationInfo['evidence']; fallbackName: string | null }) {
    const t = useTranslate();

    if (!evidence) {
        return fallbackName ? <p className="mt-2 text-[12.5px] text-[#71717a]">{t('سُجّل القبول نيابةً عن العميل — :name', { name: fallbackName })}</p> : null;
    }

    return (
        <div className="mt-3 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-3">
            <div className="mb-2 flex items-center justify-between gap-2">
                <span className="text-[12.5px] font-semibold text-[#3f3f46]">{t(title)}</span>
                <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px]', evidence.verified ? 'bg-[#ecfdf5] text-[#065f46]' : 'bg-[#fef2f2] text-[#b91c1c]')}>
                    <ShieldCheck className="size-3" />
                    {evidence.verified ? t('البصمة تطابق') : t('البصمة لا تطابق — تغيّر المستند بعد القرار')}
                </span>
            </div>
            <Facts>
                <Fact label="الموقّع">{evidence.signer_name}</Fact>
                {evidence.signer_title && <Fact label="الصفة">{evidence.signer_title}</Fact>}
                {evidence.signer_email && <Fact label="البريد"><span dir="ltr">{evidence.signer_email}</span></Fact>}
                {evidence.signer_phone && <Fact label="الهاتف"><span dir="ltr">{evidence.signer_phone}</span></Fact>}
                <Fact label="الوقت"><span dir="ltr">{evidence.decided_at ?? '—'}</span></Fact>
                {evidence.ip && <Fact label="العنوان"><span dir="ltr">{evidence.ip}</span></Fact>}
            </Facts>
            <div className="mt-2 truncate font-mono text-[10.5px] text-[#a1a1aa]" dir="ltr" title={evidence.content_hash}>
                {evidence.content_hash}
            </div>
            {evidence.consent_text && (
                <details className="mt-1 text-[12px] text-[#71717a]">
                    <summary className="cursor-pointer">{t('نصّ الإقرار')}</summary>
                    <p className="mt-1 leading-6">{evidence.consent_text}</p>
                </details>
            )}
        </div>
    );
}

function InvoiceList({ rows, m, empty }: { rows: BillingInvoice[]; m: (v: number | null | undefined) => string; empty: string }) {
    const t = useTranslate();

    if (rows.length === 0) return <p className="mt-2 text-[13px] text-[#a1a1aa]">{t(empty)}</p>;

    return (
        <ul className="mt-2 divide-y divide-[#f3f4f6]">
            {rows.map((i) => (
                <li key={i.id} className="flex items-center justify-between gap-3 py-2 text-[13px]">
                    <div className="min-w-0">
                        <SmartLink routeName="admin.customerInvoices.show" href={route('admin.customerInvoices.show', i.id)} className="font-medium text-[#1d4ed8] hover:underline">
                            {i.number ?? t('مسودة #:id', { id: String(i.id) })}
                        </SmartLink>
                        <div className="text-[12px] text-[#71717a]">
                            {i.method === 'percent' && i.percent !== null ? t('نسبة :p٪', { p: String(i.percent) }) + ' · ' : ''}
                            <span dir="ltr">{i.issued_at ?? ''}</span>
                        </div>
                    </div>
                    <div className="text-end">
                        <div className="font-semibold tabular-nums">{m(i.total)}</div>
                        <div className={cn('text-[11.5px]', i.status === 'صادرة' ? (i.outstanding && i.outstanding > 0 ? 'text-[#b45309]' : 'text-[#16a34a]') : 'text-[#71717a]')}>
                            {t(i.status === 'صادرة' ? i.payment_state : i.status)}
                        </div>
                    </div>
                </li>
            ))}
        </ul>
    );
}

function Facts({ children, single }: { children: ReactNode; single?: boolean }) {
    return <dl className={cn('grid gap-x-6 gap-y-2 text-[13px]', !single && 'sm:grid-cols-2')}>{children}</dl>;
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    const t = useTranslate();
    return (
        <div className="flex items-baseline justify-between gap-3">
            <dt className="shrink-0 text-[#a1a1aa]">{t(label)}</dt>
            <dd className="min-w-0 truncate text-end font-medium text-[#27272a]">{children}</dd>
        </div>
    );
}

function Line({ label, value, hint, strong }: { label: string; value: string; hint?: string; strong?: boolean }) {
    const t = useTranslate();
    return (
        <div className={cn('flex items-baseline justify-between gap-3', strong && 'text-[15px] font-semibold')}>
            <dt className={strong ? 'text-[#18181b]' : 'text-[#71717a]'}>
                {t(label)}
                {hint && <span className="ms-1 text-[11px] text-[#a1a1aa]">({t(hint)})</span>}
            </dt>
            <dd className="tabular-nums" dir="ltr">{value}</dd>
        </div>
    );
}

function Figure({ label, value, tone }: { label: string; value: string; tone?: 'ok' | 'warn' }) {
    const t = useTranslate();
    return (
        <div className="rounded-[10px] bg-[#fafafa] px-3 py-2">
            <div className="text-[11.5px] text-[#a1a1aa]">{t(label)}</div>
            <div className={cn('text-[15px] font-semibold tabular-nums', tone === 'ok' && 'text-[#047857]', tone === 'warn' && 'text-[#b45309]')} dir="ltr">{value}</div>
        </div>
    );
}

function Check2({ ok, children }: { ok: boolean; children: ReactNode }) {
    return (
        <div className={cn('mt-2 inline-flex items-center gap-1.5 rounded-[8px] px-2.5 py-1.5 text-[12.5px]', ok ? 'bg-[#ecfdf5] text-[#065f46]' : 'bg-[#fef2f2] text-[#b91c1c]')}>
            {ok ? <ShieldCheck className="size-3.5 shrink-0" /> : <AlertTriangle className="size-3.5 shrink-0" />}
            <span>{children}</span>
        </div>
    );
}

function Chip({ ok, label }: { ok: boolean; label: string }) {
    return <span className={cn('rounded-[6px] px-1.5 py-0.5', ok ? 'bg-[#f0fdf4] text-[#15803d]' : 'bg-[#fffbeb] text-[#b45309]')}>{label}</span>;
}

function Blockers({ items, soft }: { items: string[]; soft?: boolean }) {
    return (
        <ul className="mt-2 space-y-1">
            {items.map((b) => (
                <li key={b} className={cn('flex items-start gap-1.5 rounded-[8px] px-2.5 py-1.5 text-[12.5px]', soft ? 'bg-[#fffbeb] text-[#92400e]' : 'bg-[#fef2f2] text-[#b91c1c]')}>
                    {soft ? <Circle className="mt-1 size-2.5 shrink-0" /> : <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />}
                    {b}
                </li>
            ))}
        </ul>
    );
}

function Actions({ children }: { children: ReactNode }) {
    return <div className="mt-3 flex flex-wrap gap-2 empty:hidden">{children}</div>;
}

function LinkButton({ routeName, href, icon, label, strong }: { routeName: string; href: string; icon: ReactNode; label: string; strong?: boolean }) {
    const t = useTranslate();
    return (
        <Button variant={strong ? 'primary' : 'outline'} asChild>
            <SmartLink routeName={routeName} href={href}>
                {icon}
                {t(label)}
                {strong && <ArrowLeft className="ltr:rotate-180" />}
            </SmartLink>
        </Button>
    );
}

function ExternalButton({ href, label }: { href: string; label: string }) {
    const t = useTranslate();
    return (
        <Button variant="ghost" asChild>
            <a href={href} target="_blank" rel="noopener noreferrer">
                <ExternalLink />
                {t(label)}
            </a>
        </Button>
    );
}

function MiniButton({ onClick, strong, children }: { onClick: () => void; strong?: boolean; children: ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'h-7 rounded-[7px] px-2.5 text-[12px] font-medium transition-colors',
                strong ? 'bg-[#111] text-white hover:opacity-90' : 'border border-[var(--ui-border,#e8e8e8)] bg-white text-[#3f3f46] hover:bg-[#f4f4f5]',
            )}
        >
            {children}
        </button>
    );
}

function Side({ title, action, children }: { title: string; action?: ReactNode; children: ReactNode }) {
    const t = useTranslate();
    return (
        <section className="rounded-[14px] border border-[var(--ui-border,#e8e8e8)] bg-white p-4">
            <div className="mb-2.5 flex items-center justify-between">
                <h3 className="text-[13px] font-semibold text-[#18181b]">{t(title)}</h3>
                {action}
            </div>
            {children}
        </section>
    );
}

/** تعيينُ المسؤول من مكانه — يحفظ المشروعَ ببياناته كما هي ومسؤولِه الجديد */
function OwnerPicker({ p, users }: { p: ProcessProject; users: { id: number; name: string }[] }) {
    const t = useTranslate();
    const [owner, setOwner] = useState(p.owner_id ? String(p.owner_id) : '');
    const dirty = owner !== (p.owner_id ? String(p.owner_id) : '');

    return (
        <div className="mt-2 flex flex-wrap items-center gap-1.5">
            <Select className="h-7 min-w-[9rem] text-[12px]" placeholder="بلا مسؤول" value={owner} onChange={(e) => setOwner(e.target.value)} options={users.map((u) => ({ label: u.name, value: u.id }))} />
            {dirty && (
                <MiniButton
                    strong
                    onClick={() =>
                        router.put(
                            route('admin.projects.update', p.id),
                            {
                                name: p.name,
                                event_type: p.event_type ?? '',
                                location: p.location ?? '',
                                contact_person: p.contact_person ?? '',
                                contact_phone: p.contact_phone ?? '',
                                contact_email: p.contact_email ?? '',
                                owner_id: owner,
                                notes: p.notes ?? '',
                                ...(p.internal_notes !== null ? { internal_notes: p.internal_notes } : {}),
                            },
                            { preserveScroll: true },
                        )
                    }
                >
                    {t('حفظ')}
                </MiniButton>
            )}
        </div>
    );
}
