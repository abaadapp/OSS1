import { useEffect, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Check, ChevronDown, Circle, ExternalLink, Lock, Phone, Play, Plus, ShieldCheck, Smartphone, Unlock, X } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import { DELIVERY_STATE_LABEL, deliveryTone } from '@/lib/deliveries';
import { CO_STATE_LABEL, coTone } from '@/lib/projects';
import { operationTone } from '@/lib/operations';
import type { Stage, StageKey } from '@/lib/projectStages';
import { cn } from '@/lib/utils';

/* ─────────────────────────── ما تقرؤه المرحلة ─────────────────────────── */

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

export interface ProcessData {
    quotation: { id: number; label: string; total: number; decided_at: string | null; decided_by_name: string | null; evidence: { signer_name: string; verified: boolean } | null } | null;
    requirements: { active: number };
    members: { id: number; name: string | null; role: string | null }[];
    tasks: { id: number; title: string; status: string; due_at: string | null; assignee: string | null }[];
    change_orders: { id: number; label: string; title: string; state: string; status: string }[];
    operations: { id: number; code: string; name: string; status: string; status_label: string; venue_name: string | null; event_starts_at: string | null; ready: boolean; blockers: string[]; crew: { expected: number; checked_in: number }; equipment: { required: number; reserved: number }; checklist: { total: number; done: number }; incidents: { open: number }; url: string; onsite_url: string }[] | null;
    delivery: { readiness: { ready: boolean; blockers: string[] }; packages: { id: number; label: string; title: string; status: string; sent_at: string | null; decided_at: string | null }[] };
    billing: { billed: boolean; paid: boolean; value: number | null; invoiced: number | null; collected: number | null; outstanding: number | null };
    invoices: { id: number; number: string | null; status: string; payment_state: string; issued_at: string | null; due_at: string | null; total: number | null; outstanding: number | null }[] | null;
    close_blockers: string[];
    users: { id: number; name: string }[];
}

export interface ProcessMay {
    manage: boolean;
    close: boolean;
    reopen: boolean;
    change_order: boolean;
    delivery: boolean;
    operations: boolean;
    invoice: boolean;
}

/**
 * مسارُ المشروع — من عرض السعر إلى الإقفال، كلُّ مرحلةٍ بما تمّ منها وما
 * ينقصها وزرِّ خطوتها.
 *
 * الحاليّةُ مفتوحةٌ أوّلَ ما تُرى، والمنجزةُ مطويّةٌ بملخّصها، وما بعدها
 * ينتظر. وكلُّ زرٍّ هنا يمرّ بالباب نفسِه الذي تمرّ به الشاشاتُ الأخرى —
 * لا كتابةَ ثانيةً لشيء: تثبيتُ المتطلّبات وبدءُ التنفيذ والإقفالُ من مسارات
 * المشروع، والفعاليّةُ والتسليمُ والفاتورةُ من شاشاتها.
 */
export default function ProcessTab({
    p,
    d,
    stages,
    may,
    m,
    focus,
    onTab,
    onBaseline,
    post,
}: {
    p: ProcessProject;
    d: ProcessData;
    stages: Stage[];
    may: ProcessMay;
    m: (v: number | null | undefined) => string;
    /** مرحلةٌ طُلبت من الشريط فوق — تُفتح ويُنزل إليها */
    focus: StageKey | null;
    onTab: (key: string) => void;
    onBaseline: () => void;
    post: (name: string, data?: Record<string, string>) => void;
}) {
    const t = useTranslate();
    const open = p.status === 'confirmed' || p.status === 'in_progress' || p.status === 'on_hold';
    const current = stages.find((s) => s.state === 'current' || s.state === 'failed')?.key ?? null;
    const [expanded, setExpanded] = useState<Set<StageKey>>(() => new Set(current ? [current] : []));

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

    const openTasks = d.tasks.filter((x) => x.status !== 'done');
    const today = new Date().toISOString().slice(0, 10);
    const lateTasks = openTasks.filter((x) => x.due_at && x.due_at < today);
    const pendingCo = d.change_orders.filter((c) => c.status === 'pending_approval' || c.status === 'sent');

    /* ما يُكتب تحت عنوان المرحلة وهي مطويّة — سطرٌ يقول أين هي */
    const summary: Record<StageKey, string> = {
        quote: d.quotation ? `${d.quotation.label}${d.quotation.decided_at ? ' · ' + t('قُبل في') + ' ' + d.quotation.decided_at : ''}` : t('مشروع بلا عرض سعر مقبول'),
        planning: [
            p.owner ? t('المسؤول: :name', { name: p.owner }) : t('بلا مسؤول'),
            t(':n في الفريق', { n: String(d.members.length) }),
            p.baselined ? t('المتطلّبات مثبَّتة') : t('المتطلّبات لم تُثبَّت'),
            t(':n مهامّ مفتوحة', { n: String(openTasks.length) }),
        ].join(' · '),
        execution:
            d.operations === null
                ? t('العمليات خارج صلاحيتك')
                : d.operations.length === 0
                  ? t('لا فعاليّة مجدولة بعد')
                  : d.operations.map((o) => `${o.name} · ${o.status_label}`).join(' — '),
        delivery: d.delivery.packages.length === 0 ? t('لا حزمة تسليم بعد') : d.delivery.packages.map((x) => `${x.label} · ${t(DELIVERY_STATE_LABEL[x.status] ?? x.status)}`).join(' — '),
        billing:
            d.billing.invoiced !== null && d.billing.value !== null
                ? `${t('فُوتر')} ${m(d.billing.invoiced)} ${t('من')} ${m(d.billing.value)} · ${t('حُصّل')} ${m(d.billing.collected)}`
                : d.billing.billed
                  ? t('فُوترت القيمة كلّها')
                  : t('لم تُفوتر القيمة كلّها بعد'),
        closing: p.status === 'closed' ? t('أُقفل في :at', { at: p.closed_at ?? '' }) : d.close_blockers.length === 0 ? t('جاهز للإقفال') : t(':n مانع قبل الإقفال', { n: String(d.close_blockers.length) }),
    };

    const body: Record<StageKey, ReactNode> = {
        /* ─── عرض السعر ─── */
        quote: (
            <>
                {d.quotation ? (
                    <Facts>
                        <Fact label="العرض">
                            <SmartLink routeName="admin.quotations.show" href={route('admin.quotations.show', d.quotation.id)} className="text-[#1d4ed8] hover:underline">
                                {d.quotation.label}
                            </SmartLink>
                        </Fact>
                        <Fact label="قيمته">{m(d.quotation.total)}</Fact>
                        <Fact label="قُبل في">{d.quotation.decided_at ?? '—'}</Fact>
                        <Fact label="قَبِله">{d.quotation.evidence?.signer_name ?? d.quotation.decided_by_name ?? '—'}</Fact>
                    </Facts>
                ) : (
                    <p className="text-[13px] text-[#a1a1aa]">{t('مشروع بلا عرض سعر مقبول')}</p>
                )}
                {d.quotation?.evidence && (
                    <Check2 ok={d.quotation.evidence.verified}>{d.quotation.evidence.verified ? t('البصمة تطابق') : t('البصمة لا تطابق — تغيّر المستند بعد القرار')}</Check2>
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
                    {d.quotation && <LinkButton routeName="admin.quotations.show" href={route('admin.quotations.show', d.quotation.id)} icon={<ExternalLink />} label="فتح العرض" />}
                    {open && may.change_order && <LinkButton routeName="admin.changeOrders.create" href={route('admin.changeOrders.create', p.id)} icon={<Plus />} label="أمر تغيير" />}
                </Actions>
            </>
        ),

        /* ─── التخطيط ─── */
        planning: (
            <>
                <ul className="space-y-2">
                    <Item ok={!!p.owner} label={p.owner ? t('المسؤول: :name', { name: p.owner }) : t('لم يُعيَّن مسؤول للمشروع')}>
                        {may.manage && open && <OwnerPicker p={p} users={d.users} />}
                    </Item>
                    <Item ok={d.members.length > 0} label={d.members.length > 0 ? t('الفريق: :names', { names: d.members.map((x) => x.name).filter(Boolean).slice(0, 4).join('، ') }) : t('لا أحد في فريق المشروع')}>
                        <MiniButton onClick={() => onTab('team')}>{t('الفريق')}</MiniButton>
                    </Item>
                    <Item ok={p.baselined} label={p.baselined ? t('المتطلّبات مثبَّتة · :at', { at: p.requirements_baselined_at ?? '' }) : t(':n متطلّب — لم تُثبَّت بعد', { n: String(d.requirements.active) })}>
                        <MiniButton onClick={() => onTab('requirements')}>{t('المتطلّبات')}</MiniButton>
                        {!p.baselined && open && may.manage && <MiniButton strong onClick={onBaseline}>{t('تثبيت')}</MiniButton>}
                    </Item>
                    <Item
                        ok={openTasks.length > 0 && lateTasks.length === 0}
                        warn={lateTasks.length > 0}
                        label={
                            lateTasks.length > 0
                                ? t(':n مهامّ مفتوحة، :late متأخّرة', { n: String(openTasks.length), late: String(lateTasks.length) })
                                : openTasks.length > 0
                                  ? t(':n مهامّ مفتوحة', { n: String(openTasks.length) })
                                  : t('لا مهامّ مفتوحة')
                        }
                    >
                        <MiniButton onClick={() => onTab('tasks')}>{t('المهامّ')}</MiniButton>
                    </Item>
                </ul>
                <Actions>
                    {(p.status === 'confirmed' || p.status === 'on_hold') && may.manage && (
                        <Button onClick={() => post('admin.projects.transition', { status: 'in_progress' })}>
                            <Play />
                            {p.status === 'on_hold' ? t('استئناف التنفيذ') : t('بدء التنفيذ')}
                        </Button>
                    )}
                </Actions>
            </>
        ),

        /* ─── التنفيذ ─── */
        execution: (
            <>
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
                                        <a href={o.url} className="font-semibold text-[#18181b] hover:underline">
                                            {o.name}
                                        </a>
                                        <div className="text-[12px] text-[#71717a]">
                                            <span dir="ltr" className="tabular-nums">{o.event_starts_at ?? '—'}</span>
                                            {o.venue_name ? ` · ${o.venue_name}` : ''}
                                        </div>
                                    </div>
                                    <span className={'rounded-full px-2 py-0.5 text-[11px] ' + operationTone(o.status)}>{o.status_label}</span>
                                </div>
                                <div className="mt-2 flex flex-wrap gap-1.5 text-[12px]">
                                    <Chip ok={o.ready} label={o.ready ? t('جاهزة') : t('غير جاهزة')} />
                                    <Chip ok={o.crew.expected > 0} label={t('الفريق :n', { n: String(o.crew.expected) })} />
                                    <Chip ok={o.equipment.required === 0 || o.equipment.reserved >= o.equipment.required} label={t('المعدّات :a/:b', { a: String(o.equipment.reserved), b: String(o.equipment.required) })} />
                                    <Chip ok={o.checklist.total === 0 || o.checklist.done === o.checklist.total} label={t('التحقّق :a/:b', { a: String(o.checklist.done), b: String(o.checklist.total) })} />
                                    {o.incidents.open > 0 && <Chip ok={false} label={t(':n بلاغ مفتوح', { n: String(o.incidents.open) })} />}
                                </div>
                                {!o.ready && o.blockers.length > 0 && <Blockers items={o.blockers} />}
                                <div className="mt-2 flex flex-wrap gap-2">
                                    <a href={o.url} className="inline-flex h-8 items-center gap-1 rounded-[8px] border border-[var(--ui-border,#e8e8e8)] px-3 text-[12.5px] font-medium hover:bg-[#f4f4f5]">
                                        {t('فتح الفعاليّة')}
                                    </a>
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
                        <Button variant={d.operations.length === 0 ? 'primary' : 'outline'} onClick={() => onTab('operations')}>
                            <Plus />
                            {d.operations.length === 0 ? t('جدولة الفعاليّة') : t('فعاليّة أخرى')}
                        </Button>
                    </Actions>
                )}
            </>
        ),

        /* ─── التسليم ─── */
        delivery: (
            <>
                {d.delivery.packages.length === 0 ? (
                    <p className="text-[13px] text-[#a1a1aa]">{t('لا حزمة تسليم بعد — ما يُسلَّم للعميل في ختام العمل، ويقبله فيُقفَل المشروع.')}</p>
                ) : (
                    <ul className="divide-y divide-[#f3f4f6]">
                        {d.delivery.packages.map((x) => (
                            <li key={x.id} className="flex items-center justify-between gap-3 py-2 text-[13px]">
                                <div className="min-w-0">
                                    <SmartLink routeName="admin.deliveries.show" href={route('admin.deliveries.show', x.id)} className="font-medium text-[#1d4ed8] hover:underline">
                                        {x.label}
                                    </SmartLink>
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
                        <LinkButton
                            strong={d.delivery.packages.length === 0}
                            routeName="admin.deliveries.create"
                            href={route('admin.deliveries.create', { project_id: p.id })}
                            icon={<Plus />}
                            label="حزمة تسليم جديدة"
                        />
                    )}
                </Actions>
            </>
        ),

        /* ─── الفوترة والتحصيل ─── */
        billing: (
            <>
                {d.billing.value !== null && d.billing.invoiced !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Progress label="فُوتر" value={d.billing.invoiced} of={d.billing.value} m={m} />
                        <Progress label="حُصّل" value={d.billing.collected ?? 0} of={d.billing.invoiced} m={m} tone="#16a34a" />
                    </div>
                )}
                {d.billing.outstanding !== null && d.billing.outstanding > 0.0005 && (
                    <p className="mt-2 text-[13px] text-[#b45309]">
                        {t('باقٍ على العميل')}: <span className="font-semibold tabular-nums">{m(d.billing.outstanding)}</span>
                    </p>
                )}
                {d.invoices !== null && (
                    <div className="mt-3">
                        {d.invoices.length === 0 ? (
                            <p className="text-[13px] text-[#a1a1aa]">{t('لا فواتير على هذا المشروع بعد.')}</p>
                        ) : (
                            <ul className="divide-y divide-[#f3f4f6]">
                                {d.invoices.map((i) => (
                                    <li key={i.id} className="flex items-center justify-between gap-3 py-2 text-[13px]">
                                        <div className="min-w-0">
                                            <SmartLink routeName="admin.customerInvoices.show" href={route('admin.customerInvoices.show', i.id)} className="font-medium text-[#1d4ed8] hover:underline">
                                                {i.number ?? t('مسودة #:id', { id: String(i.id) })}
                                            </SmartLink>
                                            <div className="text-[12px] text-[#71717a]" dir="ltr">
                                                {i.issued_at ?? ''}
                                                {i.due_at ? ` → ${i.due_at}` : ''}
                                            </div>
                                        </div>
                                        <div className="text-end">
                                            <div className="font-semibold tabular-nums">{m(i.total)}</div>
                                            <div className={cn('text-[11.5px]', i.outstanding && i.outstanding > 0 ? 'text-[#b45309]' : 'text-[#16a34a]')}>
                                                {t(i.status === 'صادرة' ? i.payment_state : i.status)}
                                            </div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}
                <Actions>
                    {may.invoice && open && (
                        <LinkButton
                            strong={!d.billing.billed}
                            routeName="admin.customerInvoices.create"
                            href={route('admin.customerInvoices.create', { project_id: p.id })}
                            icon={<Plus />}
                            label="إصدار فاتورة"
                        />
                    )}
                </Actions>
            </>
        ),

        /* ─── الإقفال ─── */
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
                {pendingCo.length > 0 && open && (
                    <p className="mt-2 text-[12px] text-[#71717a]">{t('أوامر التغيير المعلّقة في مرحلة «عرض السعر» أعلاه.')}</p>
                )}
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
            <ol className="relative space-y-3">
                <span className="absolute inset-y-4 start-[19px] w-px bg-[#e4e4e7]" aria-hidden />
                {stages.map((s) => {
                    const isOpen = expanded.has(s.key);
                    const Icon = s.icon;

                    return (
                        <li key={s.key} id={`stage-${s.key}`} className="relative scroll-mt-24 ps-12">
                            <span
                                className={cn(
                                    'absolute start-0 top-3 flex size-10 items-center justify-center rounded-full border-4 border-white',
                                    s.state === 'done' && 'bg-[#6d28d9] text-white',
                                    s.state === 'current' && 'bg-[#f3e8ff] text-[#6d28d9] ring-2 ring-[#6d28d9]',
                                    s.state === 'todo' && 'bg-[#f4f4f5] text-[#a1a1aa]',
                                    s.state === 'failed' && 'bg-[#dc2626] text-white',
                                )}
                            >
                                {s.state === 'done' ? <Check className="size-4" strokeWidth={3} /> : s.state === 'failed' ? <X className="size-4" strokeWidth={3} /> : <Icon className="size-4" />}
                            </span>

                            <div
                                className={cn(
                                    'overflow-hidden rounded-[14px] border bg-white transition-shadow',
                                    s.state === 'current' ? 'border-[#c4b5fd] shadow-[0_6px_24px_-8px_rgba(109,40,217,0.25)]' : 'border-[var(--ui-border,#e8e8e8)]',
                                )}
                            >
                                <button type="button" onClick={() => toggle(s.key)} aria-expanded={isOpen} className="flex w-full items-center gap-3 px-4 py-3 text-start">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className={cn('text-[15px] font-semibold', s.state === 'todo' ? 'text-[#71717a]' : 'text-[#18181b]')}>{t(s.label)}</span>
                                            {s.state === 'current' && <span className="rounded-full bg-[#6d28d9] px-2 py-0.5 text-[10.5px] font-medium text-white">{t('الآن')}</span>}
                                            {s.state === 'done' && <span className="text-[11.5px] text-[#6d28d9]">{t('تمّت')}</span>}
                                            {s.note && <span className="rounded-full bg-[#fffbeb] px-2 py-0.5 text-[10.5px] font-medium text-[#b45309]">{t(s.note)}</span>}
                                        </div>
                                        <div className="mt-0.5 truncate text-[12.5px] text-[#71717a]">{summary[s.key]}</div>
                                    </div>
                                    <ChevronDown className={cn('size-4 shrink-0 text-[#a1a1aa] transition-transform', isOpen && 'rotate-180')} />
                                </button>
                                {isOpen && <div className="border-t border-[#f0f0f0] px-4 py-3">{body[s.key]}</div>}
                            </div>
                        </li>
                    );
                })}
            </ol>

            {/* ─────────── الجانب: من وأين ومتى وكم ─────────── */}
            <aside className="space-y-3">
                <Side title="الفعاليّة">
                    <Facts single>
                        <Fact label="العميل">{p.customer}</Fact>
                        {p.event_type && <Fact label="النوع">{p.event_type}</Fact>}
                        <Fact label="الموعد">
                            <span dir="ltr" className="tabular-nums">
                                {p.starts_at ?? '—'}
                                {p.ends_at && p.ends_at !== p.starts_at ? ` → ${p.ends_at}` : ''}
                            </span>
                        </Fact>
                        {p.location && <Fact label="المكان">{p.location}</Fact>}
                        <Fact label="المسؤول">{p.owner ?? '—'}</Fact>
                    </Facts>
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

                {d.billing.value !== null && (
                    <Side title="المال">
                        <Facts single>
                            <Fact label="القيمة الحاليّة">
                                <span className="font-semibold tabular-nums">{m(d.billing.value)}</span>
                            </Fact>
                            <Fact label="فُوتر">{m(d.billing.invoiced)}</Fact>
                            <Fact label="حُصّل">{m(d.billing.collected)}</Fact>
                            <Fact label="باقٍ على العميل">{m(d.billing.outstanding)}</Fact>
                        </Facts>
                    </Side>
                )}

                <Side title="المهامّ" action={<MiniButton onClick={() => onTab('tasks')}>{t('الكلّ')}</MiniButton>}>
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

/* ─────────────────────────── قطعٌ صغيرة ─────────────────────────── */

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

function Item({ ok, warn, label, children }: { ok: boolean; warn?: boolean; label: string; children?: ReactNode }) {
    return (
        <li className="flex flex-wrap items-center gap-2 rounded-[10px] bg-[#fafafa] px-3 py-2">
            <span className={cn('flex size-5 shrink-0 items-center justify-center rounded-full', warn ? 'bg-[#fef2f2] text-[#dc2626]' : ok ? 'bg-[#dcfce7] text-[#15803d]' : 'bg-[#f4f4f5] text-[#a1a1aa]')}>
                {warn ? <AlertTriangle className="size-3" /> : ok ? <Check className="size-3" strokeWidth={3} /> : <Circle className="size-2.5" />}
            </span>
            <span className="min-w-0 flex-1 text-[13px] text-[#27272a]">{label}</span>
            {children && <span className="flex flex-wrap items-center gap-1.5">{children}</span>}
        </li>
    );
}

function Check2({ ok, children }: { ok: boolean; children: ReactNode }) {
    return (
        <div className={cn('mt-2 inline-flex items-center gap-1.5 rounded-[8px] px-2.5 py-1.5 text-[12.5px]', ok ? 'bg-[#ecfdf5] text-[#065f46]' : 'bg-[#fef2f2] text-[#b91c1c]')}>
            {ok ? <ShieldCheck className="size-3.5" /> : <AlertTriangle className="size-3.5" />}
            {children}
        </div>
    );
}

function Chip({ ok, label }: { ok: boolean; label: string }) {
    return <span className={cn('rounded-[6px] px-1.5 py-0.5', ok ? 'bg-[#f0fdf4] text-[#15803d]' : 'bg-[#fffbeb] text-[#b45309]')}>{label}</span>;
}

function Blockers({ items }: { items: string[] }) {
    return (
        <ul className="mt-2 space-y-1">
            {items.map((b) => (
                <li key={b} className="flex items-start gap-1.5 rounded-[8px] bg-[#fef2f2] px-2.5 py-1.5 text-[12.5px] text-[#b91c1c]">
                    <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
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

function Progress({ label, value, of, m, tone = '#6d28d9' }: { label: string; value: number; of: number; m: (v: number) => string; tone?: string }) {
    const t = useTranslate();
    const pct = of > 0 ? Math.min(100, Math.round((value / of) * 100)) : 0;
    return (
        <div>
            <div className="mb-1 flex items-baseline justify-between text-[12.5px]">
                <span className="text-[#71717a]">{t(label)}</span>
                <span className="tabular-nums">
                    <span className="font-semibold">{m(value)}</span> <span className="text-[#a1a1aa]">/ {m(of)}</span>
                </span>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-[#f4f4f5]">
                <div className="h-full rounded-full transition-[width]" style={{ width: `${pct}%`, backgroundColor: tone }} />
            </div>
        </div>
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
        <>
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
        </>
    );
}
