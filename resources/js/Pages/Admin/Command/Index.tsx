import { usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    Banknote,
    CalendarClock,
    CheckCircle2,
    Clock,
    Database,
    Gavel,
    MessageSquareWarning,
    PackageCheck,
    Radio,
    ShieldAlert,
    Users,
    Zap,
    type LucideIcon,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import { Card } from '@/Components/ui/card';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { PROJECT_STATUS_LABEL, projectTone } from '@/lib/projects';
import { operationTone } from '@/lib/operations';
import type { PageProps } from '@/types';

interface ActionRow {
    kind: string;
    severity: 'danger' | 'warning' | 'info';
    title: string;
    detail: string | null;
    action: string;
    url: string;
    at: string | null;
    days: number | null;
    amount: number | null;
}

interface Section {
    rows: ActionRow[];
    total: number;
}

interface UpcomingRow {
    id: number;
    code: string;
    name: string;
    customer: string;
    status: string;
    owner: string | null;
    starts_at: string | null;
    ends_at: string | null;
    days_to_start: number | null;
    running: boolean;
    location: string | null;
    open_tasks: number;
    late_tasks: number;
    baselined: boolean;
    delivery: 'accepted' | 'sent' | 'none';
    issues: string[];
    severity: 'ok' | 'warning' | 'danger';
    url: string;
}

interface BlockerRow {
    id: number;
    code: string;
    name: string;
    customer: string;
    status: string;
    starts_at: string | null;
    operational: string[];
    financial: string[];
    severity: 'warning' | 'danger';
    url: string;
}

interface TeamRow {
    id: number;
    name: string;
    role: string;
    open_tasks: number;
    late_tasks: number;
    projects_owned: number;
    projects_member: number;
    next_task: { title: string; due_at: string | null; project: string | null; url: string } | null;
}

interface Money {
    receivables: {
        total: number;
        overdue: number;
        overdue_count: number;
        due_soon: number;
        top: { customer_id: number | null; customer: string; amount: number; oldest_due: string | null; url: string }[];
        url: string;
    };
    payables: {
        supplier_total: number;
        supplier_overdue: number;
        supplier_overdue_count: number;
        supplier_due_soon: number;
        expenses_unpaid: number;
        open_purchase_orders: number;
        open_purchase_orders_count: number;
        top: { supplier_id: number | null; supplier: string; amount: number; oldest_due: string | null; url: string }[];
        url: string;
    };
    net: number;
}

/** التشغيل — المرحلة 10: فعاليّةٌ على الأرض بجاهزيّتها وتعارضاتها */
interface OperationRow {
    id: number;
    code: string;
    name: string;
    project: string | null;
    venue: string | null;
    status: string;
    lead: string | null;
    starts_at: string | null;
    ends_at: string | null;
    days_to_start: number | null;
    bucket: string;
    ready: boolean;
    blockers: string[];
    blocker_count: number;
    warning_count: number;
    conflicts: { critical: number; warning: number };
    crew: { expected: number; checked_in: number; missing: number };
    severity: 'ok' | 'warning' | 'danger';
    url: string;
    onsite_url: string;
}

interface Operations {
    rows: OperationRow[];
    total: number;
    live: number;
    not_ready: number;
    counts: { live: number; today: number; tomorrow: number; week: number };
    conflicts: Section;
    outstanding: Section;
    incidents: Section;
}

interface Center {
    generated_at: string;
    sees_money: boolean;
    summary: { decisions: number; late: number; delivery: number; communications: number; upcoming: number; blockers: number; automation: number; migration: number; operations: number };
    decisions: Section;
    late: Section;
    delivery: Section;
    communications: Section & { sent_today: number };
    upcoming: { rows: UpcomingRow[]; total: number; soon: number };
    blockers: { rows: BlockerRow[]; total: number };
    team: { rows: TeamRow[]; unassigned: number };
    /* الأتمتة — المرحلة 8: ما فشل أو لم تقدر عليه، والإيقافُ العامّ */
    automation: Section & { paused: boolean; mode: string; configured: boolean; pending: number };
    /* نقلُ البيانات — المرحلة 9: فحصٌ فشل أو تعارضاتٌ أو استيرادٌ لم يوازن — والناجحُ لا يُذكر */
    migration: Section;
    /* التشغيل — المرحلة 10 */
    operations: Operations;
    money: Money | null;
}

const ROLE_LABEL: Record<string, string> = {
    admin: 'صاحب النشاط',
    manager: 'مدير',
    accountant: 'محاسب',
    inventory: 'أمين مخزن',
    sales: 'مبيعات',
    delivery: 'تنفيذ ميداني',
};

const SEVERITY: Record<string, string> = {
    danger: 'bg-[#dc2626]',
    warning: 'bg-[#d97706]',
    info: 'bg-[#2563eb]',
    ok: 'bg-[#059669]',
};

/**
 * مركزُ العمليات — ما يحتاج يدًا اليوم، مرتّبًا بالإلحاح لا بالقسم.
 *
 * ليست لوحةَ رسوم: كلُّ صفٍّ فعلٌ ينتظر — عنوانُه يقود إلى شاشته، وزرُّه
 * يقول ما يُفعل، ورقمُه (إن كان) يقول كم. وما لا يحتاج يدًا لا يُعرض،
 * والقسمُ الفارغ يقول ذلك بسطرٍ واحد ويصمت.
 */
export default function CommandIndex({ center: c }: { center: Center }) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const m = (v: number | null | undefined) => (v === null || v === undefined ? null : money(v, context!.currency));

    const chips: { key: string; label: string; count: number; icon: LucideIcon; tone: string }[] = [
        { key: 'decisions', label: 'يحتاج قرارًا', count: c.summary.decisions, icon: Gavel, tone: 'warning' },
        { key: 'late', label: 'متأخّر', count: c.summary.late, icon: Clock, tone: 'danger' },
        { key: 'delivery', label: 'التسليم', count: c.summary.delivery, icon: PackageCheck, tone: 'info' },
        { key: 'communications', label: 'رسائل لم تصل', count: c.summary.communications, icon: MessageSquareWarning, tone: 'danger' },
        { key: 'upcoming', label: 'يبدأ خلال أسبوع', count: c.summary.upcoming, icon: CalendarClock, tone: 'info' },
        { key: 'blockers', label: 'مشاريع معطَّلة', count: c.summary.blockers, icon: ShieldAlert, tone: 'warning' },
        { key: 'automation', label: 'أتمتة تحتاج يدًا', count: c.summary.automation + (c.automation.paused ? 1 : 0), icon: Zap, tone: 'warning' },
        ...(c.summary.migration > 0 ? [{ key: 'migration', label: 'نقل بيانات يحتاج يدًا', count: c.summary.migration, icon: Database, tone: 'warning' }] : []),
        { key: 'operations', label: 'فعاليّات تحتاج يدًا', count: c.summary.operations, icon: Radio, tone: c.operations.live > 0 ? 'ok' : 'warning' },
    ];

    const jump = (key: string) => document.getElementById(`cc-${key}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });

    return (
        <AdminLayout title={t('مركز العمليات')}>
            <PageHeader
                title={t('مركز العمليات')}
                subtitle={t('ما يحتاج يدًا اليوم — مرتّبًا بالإلحاح. آخر قراءة: :at', { at: c.generated_at })}
            />

            {/* ─────────── الملخّص: ستّة أرقام كلٌّ يقود إلى قسمه ─────────── */}
            <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
                {chips.map((ch) => (
                    <button
                        key={ch.key}
                        type="button"
                        onClick={() => jump(ch.key)}
                        className={
                            'flex items-center gap-3 rounded-xl border bg-white p-3 text-start transition hover:bg-[#fafafa] ' +
                            (ch.count > 0 ? 'border-[#e5e7eb]' : 'border-[#f3f4f6] opacity-60')
                        }
                    >
                        <span className={'flex size-9 shrink-0 items-center justify-center rounded-lg text-white ' + (ch.count > 0 ? SEVERITY[ch.tone] : 'bg-[#d1d5db]')}>
                            <ch.icon className="size-4" />
                        </span>
                        <span className="min-w-0">
                            <span className="block text-[22px] font-bold leading-none tabular-nums text-[#111]">{number(ch.count)}</span>
                            <span className="block truncate text-[12px] text-[#6b7280]">{t(ch.label)}</span>
                        </span>
                    </button>
                ))}
            </div>

            <div className="space-y-5">
                <ActionSection id="decisions" title="يحتاج قرارًا" hint="خصومٌ واستثناءاتٌ وسنداتٌ تنتظر توقيعًا — وعروضٌ فات وقتُها" icon={Gavel} section={c.decisions} m={m} />
                <ActionSection id="late" title="متأخّر" hint="ما فات موعدُه ولم يُنجَز أو يُسدَّد أو يُقفَل" icon={Clock} section={c.late} m={m} />
                <ActionSection id="delivery" title="التسليم" hint="حزمٌ تنتظر الإرسال أو العميل أو التنقيح — ومشاريعُ تنتظر حزمتَها أو إقفالَها" icon={PackageCheck} section={c.delivery} m={m} />

                {c.money && <MoneySection money={c.money} m={m} />}

                <ActionSection
                    id="communications"
                    title="رسائل لم تصل"
                    hint={t('فشلت أو ارتدّت خلال ثلاثين يومًا ولم تنجح إعادتُها — أُرسل اليوم: :n', { n: String(c.communications.sent_today) })}
                    icon={MessageSquareWarning}
                    section={c.communications}
                    m={m}
                    raw
                />

                <UpcomingSection data={c.upcoming} />
                <OperationsSection data={c.operations} m={m} />
                <BlockersSection data={c.blockers} />
                <TeamSection data={c.team} />

                {(c.automation.configured || c.automation.rows.length > 0) && (
                    <div id="cc-automation" className="scroll-mt-4">
                        {c.automation.paused && (
                            <div className="mb-2 rounded-lg border border-[#fecaca] bg-[#fef2f2] px-4 py-2 text-[13px] text-[#b91c1c]">
                                {t('الأتمتة موقوفة — لا يخرج تذكير ولا تنبيه حتى تُستأنف.')}{' '}
                                <a href={route('admin.automation.index')} className="underline">
                                    {t('الأتمتة')}
                                </a>
                            </div>
                        )}
                        <ActionSection
                            id="automation-rows"
                            title="الأتمتة"
                            hint={t('ما فشلت فيه أو لم تقدر عليه خلال أسبوع — والوضع: :mode · معلَّق: :n', { mode: c.automation.paused ? t('موقوفة') : c.automation.mode, n: String(c.automation.pending) })}
                            icon={Zap}
                            section={c.automation}
                            m={m}
                            raw
                        />
                    </div>
                )}

                {c.migration.rows.length > 0 && (
                    <div id="cc-migration" className="scroll-mt-4">
                        <ActionSection
                            id="migration-rows"
                            title="نقل البيانات"
                            hint={t('مجموعاتٌ فشل فحصُها أو تنتظر قرارًا أو لم توازن مطابقتُها — والناجحُ لا يُذكر هنا')}
                            icon={Database}
                            section={c.migration}
                            m={m}
                            raw
                        />
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}

/* ─────────────────────────── قسمُ أفعال ─────────────────────────── */

function ActionSection({ id, title, hint, icon: Icon, section, m, raw }: {
    id: string;
    title: string;
    hint: string;
    icon: LucideIcon;
    section: Section;
    m: (v: number | null | undefined) => string | null;
    /** التلميحُ مترجَمٌ سلفًا — لا يمرّ بـ`t()` ثانيةً */
    raw?: boolean;
}) {
    const t = useTranslate();

    return (
        <Card id={`cc-${id}`} className="scroll-mt-4 p-4">
            <SectionHead icon={Icon} title={t(title)} hint={raw ? hint : t(hint)} count={section.total} />
            {section.rows.length === 0 ? (
                <Empty />
            ) : (
                <ul className="divide-y divide-[#f3f4f6]">
                    {section.rows.map((r, i) => (
                        <li key={`${r.kind}-${i}`} className="flex items-start gap-3 py-2.5">
                            <span className={'mt-2 size-2 shrink-0 rounded-full ' + SEVERITY[r.severity]} />
                            <div className="min-w-0 flex-1">
                                <a href={r.url} className="font-medium text-[#111] hover:text-[#1d4ed8]">
                                    {r.title}
                                </a>
                                {r.detail && <div className="truncate text-[12px] text-[#6b7280]">{r.detail}</div>}
                            </div>
                            <div className="flex shrink-0 flex-col items-end gap-1 text-end">
                                <a href={r.url} className="inline-flex items-center gap-1 rounded-full border border-[#e5e7eb] px-2.5 py-1 text-[12px] text-[#111] hover:border-[#111]">
                                    {r.action}
                                    <ArrowLeft className="size-3" />
                                </a>
                                <div className="flex items-center gap-2 text-[11px] text-[#9ca3af]">
                                    {r.amount !== null && <span className="tabular-nums text-[#111]">{m(r.amount)}</span>}
                                    <Days days={r.days} at={r.at} />
                                </div>
                            </div>
                        </li>
                    ))}
                    {section.total > section.rows.length && (
                        <li className="pt-2 text-[12px] text-[#9ca3af]">{t('و:n أخرى', { n: String(section.total - section.rows.length) })}</li>
                    )}
                </ul>
            )}
        </Card>
    );
}

/* ─────────────────────────── المال ─────────────────────────── */

function MoneySection({ money: mo, m }: { money: Money; m: (v: number | null | undefined) => string | null }) {
    const t = useTranslate();

    return (
        <div id="cc-money" className="grid gap-4 lg:grid-cols-2">
            <Card className="p-4">
                <SectionHead icon={Banknote} title={t('من يدين لنا')} hint={t('فواتيرُ عملاءَ صادرةٌ لم تُحصَّل')} count={mo.receivables.overdue_count} />
                <dl className="mb-3 grid grid-cols-3 gap-2 text-[13px]">
                    <Figure label="المستحقّ كلُّه" value={m(mo.receivables.total)} />
                    <Figure label="متأخّر" value={m(mo.receivables.overdue)} tone="danger" sub={t(':n فاتورة', { n: String(mo.receivables.overdue_count) })} />
                    <Figure label="يستحقّ خلال أسبوع" value={m(mo.receivables.due_soon)} tone="warning" />
                </dl>
                {mo.receivables.top.length > 0 && (
                    <ul className="divide-y divide-[#f3f4f6] text-[13px]">
                        {mo.receivables.top.map((r) => (
                            <li key={r.customer_id ?? r.customer} className="flex items-center justify-between gap-3 py-1.5">
                                <a href={r.url} className="min-w-0 truncate text-[#111] hover:text-[#1d4ed8]">
                                    {r.customer}
                                </a>
                                <span className="flex shrink-0 items-center gap-2">
                                    <span className="text-[11px] text-[#9ca3af]" dir="ltr">{r.oldest_due}</span>
                                    <span className="tabular-nums font-medium text-[#b91c1c]">{m(r.amount)}</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                <a href={mo.receivables.url} className="mt-2 inline-flex items-center gap-1 text-[12px] text-[#1d4ed8]">
                    {t('الذمم المدينة')}
                    <ArrowLeft className="size-3" />
                </a>
            </Card>

            <Card className="p-4">
                <SectionHead icon={Banknote} title={t('ما علينا')} hint={t('سنداتُ موردين معتمَدة ومصاريفُ لم تُسدَّد وأوامرُ شراءٍ مفتوحة')} count={mo.payables.supplier_overdue_count} />
                <dl className="mb-3 grid grid-cols-3 gap-2 text-[13px]">
                    <Figure label="للموردين" value={m(mo.payables.supplier_total)} />
                    <Figure label="متأخّر" value={m(mo.payables.supplier_overdue)} tone="danger" sub={t(':n سند', { n: String(mo.payables.supplier_overdue_count) })} />
                    <Figure label="يستحقّ خلال أسبوع" value={m(mo.payables.supplier_due_soon)} tone="warning" />
                    <Figure label="مصاريف غير مسدَّدة" value={m(mo.payables.expenses_unpaid)} />
                    <Figure label="أوامر شراء مفتوحة" value={m(mo.payables.open_purchase_orders)} sub={t(':n أمر', { n: String(mo.payables.open_purchase_orders_count) })} />
                    <Figure label="الصافي بعد الالتزامات" value={m(mo.net)} tone={mo.net < 0 ? 'danger' : 'ok'} />
                </dl>
                {mo.payables.top.length > 0 && (
                    <ul className="divide-y divide-[#f3f4f6] text-[13px]">
                        {mo.payables.top.map((r) => (
                            <li key={r.supplier_id ?? r.supplier} className="flex items-center justify-between gap-3 py-1.5">
                                <a href={r.url} className="min-w-0 truncate text-[#111] hover:text-[#1d4ed8]">
                                    {r.supplier}
                                </a>
                                <span className="flex shrink-0 items-center gap-2">
                                    <span className="text-[11px] text-[#9ca3af]" dir="ltr">{r.oldest_due}</span>
                                    <span className="tabular-nums font-medium text-[#111]">{m(r.amount)}</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                <a href={mo.payables.url} className="mt-2 inline-flex items-center gap-1 text-[12px] text-[#1d4ed8]">
                    {t('سندات الموردين')}
                    <ArrowLeft className="size-3" />
                </a>
            </Card>
        </div>
    );
}

function Figure({ label, value, tone, sub }: { label: string; value: string | null; tone?: 'danger' | 'warning' | 'ok'; sub?: string }) {
    const t = useTranslate();
    const color = tone === 'danger' ? 'text-[#b91c1c]' : tone === 'warning' ? 'text-[#b45309]' : tone === 'ok' ? 'text-[#047857]' : 'text-[#111]';

    return (
        <div className="rounded-lg bg-[#f9fafb] px-3 py-2">
            <dt className="text-[11px] text-[#9ca3af]">{t(label)}</dt>
            <dd className={'tabular-nums font-bold ' + color}>{value ?? '—'}</dd>
            {sub && <dd className="text-[11px] text-[#9ca3af]">{sub}</dd>}
        </div>
    );
}

/* ─────────────────────────── التشغيل ─────────────────────────── */

const BUCKET_LABEL: Record<string, string> = {
    live: 'على الهواء',
    on_site: 'في الموقع',
    today: 'اليوم',
    tomorrow: 'غدًا',
    week: 'خلال أسبوع',
    later: 'لاحقًا',
};

/**
 * الفعاليّاتُ على الأرض — المرحلة 10.
 *
 * ما على الهواء، وما يبدأ اليوم وغدًا وخلال أسبوع بجاهزيّته، ثمّ التعارضاتُ
 * وما خرج ولم يعد والحوادثُ الحرجة — كلٌّ يقود إلى شاشته أو إلى شاشة الموقع.
 */
function OperationsSection({ data, m }: { data: Operations; m: (v: number | null | undefined) => string | null }) {
    const t = useTranslate();

    return (
        <div id="cc-operations" className="scroll-mt-4 space-y-4">
            <Card className="p-4">
                <SectionHead
                    icon={Radio}
                    title={t('الفعاليّات على الأرض')}
                    hint={t('على الهواء: :live · اليوم: :today · غدًا: :tomorrow · خلال أسبوع: :week · غير جاهزة وقريبة: :nr', {
                        live: String(data.counts.live), today: String(data.counts.today), tomorrow: String(data.counts.tomorrow), week: String(data.counts.week), nr: String(data.not_ready),
                    })}
                    count={data.rows.length}
                />
                {data.rows.length === 0 ? (
                    <p className="text-[13px] text-[#9ca3af]">{t('لا فعاليّات خلال أسبوعين')}</p>
                ) : (
                    <ul className="divide-y divide-[#f3f4f6]">
                        {data.rows.map((o) => (
                            <li key={o.id} className="flex items-start gap-3 py-2.5">
                                <span className={'mt-2 size-2 shrink-0 rounded-full ' + SEVERITY[o.severity]} />
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <a href={o.url} className="font-medium text-[#111] hover:text-[#1d4ed8]">
                                            {o.code} — {o.name}
                                        </a>
                                        <span className={'rounded-full px-2 py-0.5 text-[11px] ' + operationTone(o.status)}>{t(BUCKET_LABEL[o.bucket] ?? o.bucket)}</span>
                                        {!o.ready && <span className="rounded-full bg-[#fef2f2] px-2 py-0.5 text-[11px] text-[#b91c1c]">{t(':n موانع', { n: String(o.blocker_count) })}</span>}
                                        {o.ready && <span className="rounded-full bg-[#ecfdf5] px-2 py-0.5 text-[11px] text-[#047857]">{t('جاهزة')}</span>}
                                        {o.conflicts.critical > 0 && <span className="rounded-full bg-[#fef2f2] px-2 py-0.5 text-[11px] text-[#b91c1c]">{t(':n تعارضات', { n: String(o.conflicts.critical) })}</span>}
                                    </div>
                                    <div className="text-[12px] text-[#6b7280]">
                                        <span dir="ltr" className="tabular-nums">{o.starts_at ?? '—'}</span>
                                        {o.venue ? ` · ${o.venue}` : ''}
                                        {o.lead ? ` · ${o.lead}` : ''}
                                        {` · ${t('الفريق')} ${o.crew.checked_in}/${o.crew.expected}`}
                                    </div>
                                    {!o.ready && o.blockers.length > 0 && <div className="text-[12px] text-[#b91c1c]">{o.blockers.join(' · ')}</div>}
                                </div>
                                <a href={o.onsite_url} className="inline-flex shrink-0 items-center gap-1 rounded-full border border-[#e5e7eb] px-2.5 py-1 text-[12px] text-[#111] hover:border-[#111]">
                                    {t('شاشة الموقع')}
                                    <ArrowLeft className="size-3" />
                                </a>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            {data.conflicts.rows.length > 0 && <ActionSection id="operations-conflicts" title="تعارضات الموارد" hint="قطعٌ محجوزة مرّتين أو خارج الخدمة أو لم تُردّ، وموظّفون في وقتين معًا" icon={ShieldAlert} section={data.conflicts} m={m} />}
            {data.outstanding.rows.length > 0 && <ActionSection id="operations-outstanding" title="معدّات لم تُردّ" hint="خرجت إلى الميدان ولم يُسجَّل ردُّها — لا تصير متاحةً بمرور الوقت" icon={PackageCheck} section={data.outstanding} m={m} />}
            {data.incidents.rows.length > 0 && <ActionSection id="operations-incidents" title="حوادث حرجة مفتوحة" hint="عاليةٌ أو حرجة ولم تُحلّ — تمنع إتمام فعاليّتها" icon={AlertTriangle} section={data.incidents} m={m} />}
        </div>
    );
}

/* ─────────────────────────── القادم ─────────────────────────── */

function UpcomingSection({ data }: { data: Center['upcoming'] }) {
    const t = useTranslate();

    return (
        <Card id="cc-upcoming" className="scroll-mt-4 p-4">
            <SectionHead icon={CalendarClock} title={t('القادم والجاري')} hint={t('ما يبدأ خلال أسبوعين وما يجري الآن — بجاهزيّته لا بتاريخه')} count={data.total} />
            {data.rows.length === 0 ? (
                <p className="text-[13px] text-[#9ca3af]">{t('لا فعاليّات قريبة')}</p>
            ) : (
                <ul className="divide-y divide-[#f3f4f6]">
                    {data.rows.map((p) => (
                        <li key={p.id} className="flex items-start gap-3 py-2.5">
                            <span className={'mt-2 size-2 shrink-0 rounded-full ' + SEVERITY[p.severity]} />
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <a href={p.url} className="font-medium text-[#111] hover:text-[#1d4ed8]">
                                        {p.code} — {p.name}
                                    </a>
                                    <span className={'rounded-full px-2 py-0.5 text-[11px] ' + projectTone(p.status)}>{t(PROJECT_STATUS_LABEL[p.status] ?? p.status)}</span>
                                    {p.running && <span className="rounded-full bg-[#ecfdf5] px-2 py-0.5 text-[11px] text-[#047857]">{t('يجري الآن')}</span>}
                                </div>
                                <div className="text-[12px] text-[#6b7280]">
                                    {p.customer}
                                    {p.location ? ` · ${p.location}` : ''}
                                    {p.owner ? ` · ${p.owner}` : ''}
                                    {` · ${t('مهامّ مفتوحة')}: ${number(p.open_tasks)}`}
                                    {` · ${t('التسليم')}: ${t(p.delivery === 'accepted' ? 'مقبول' : p.delivery === 'sent' ? 'بانتظار العميل' : 'لا حزمة بعد')}`}
                                </div>
                                {p.issues.length > 0 && (
                                    <div className="mt-1 flex flex-wrap gap-1">
                                        {p.issues.map((x) => (
                                            <span key={x} className="rounded-full bg-[#fef2f2] px-2 py-0.5 text-[11px] text-[#b91c1c]">
                                                {x}
                                            </span>
                                        ))}
                                    </div>
                                )}
                            </div>
                            <div className="shrink-0 text-end text-[12px]" dir="ltr">
                                <div className="font-medium text-[#111]">{p.starts_at ?? '—'}</div>
                                <div className="text-[#9ca3af]">
                                    {p.ends_at ? `→ ${p.ends_at}` : ''}
                                </div>
                                {p.days_to_start !== null && p.days_to_start > 0 && (
                                    <div className="text-[11px] text-[#6b7280]">{t('بعد :n يومًا', { n: String(p.days_to_start) })}</div>
                                )}
                                {p.days_to_start === 0 && <div className="text-[11px] text-[#b45309]">{t('اليوم')}</div>}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

/* ─────────────────────────── العوائق ─────────────────────────── */

function BlockersSection({ data }: { data: Center['blockers'] }) {
    const t = useTranslate();

    return (
        <Card id="cc-blockers" className="scroll-mt-4 p-4">
            <SectionHead icon={ShieldAlert} title={t('مشاريع معطَّلة')} hint={t('كلُّ مشروعٍ مفتوحٍ عليه ما يعطّله — تشغيليًّا أو ماليًّا')} count={data.total} />
            {data.rows.length === 0 ? (
                <Empty text="لا عوائق — كلُّ المشاريع المفتوحة تمشي" />
            ) : (
                <ul className="divide-y divide-[#f3f4f6]">
                    {data.rows.map((p) => (
                        <li key={p.id} className="flex items-start gap-3 py-2.5">
                            <span className={'mt-2 size-2 shrink-0 rounded-full ' + SEVERITY[p.severity]} />
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <a href={p.url} className="font-medium text-[#111] hover:text-[#1d4ed8]">
                                        {p.code} — {p.name}
                                    </a>
                                    <span className={'rounded-full px-2 py-0.5 text-[11px] ' + projectTone(p.status)}>{t(PROJECT_STATUS_LABEL[p.status] ?? p.status)}</span>
                                    <span className="text-[12px] text-[#9ca3af]">{p.customer}</span>
                                </div>
                                <div className="mt-1 flex flex-wrap gap-1">
                                    {p.operational.map((x) => (
                                        <span key={x} className="rounded-full bg-[#fffbeb] px-2 py-0.5 text-[11px] text-[#92400e]">
                                            {x}
                                        </span>
                                    ))}
                                    {p.financial.map((x) => (
                                        <span key={x} className="rounded-full bg-[#fef2f2] px-2 py-0.5 text-[11px] text-[#b91c1c]">
                                            {x}
                                        </span>
                                    ))}
                                </div>
                            </div>
                            <a href={p.url} className="inline-flex shrink-0 items-center gap-1 rounded-full border border-[#e5e7eb] px-2.5 py-1 text-[12px] text-[#111] hover:border-[#111]">
                                {t('افتح المشروع')}
                                <ArrowLeft className="size-3" />
                            </a>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

/* ─────────────────────────── الفريق ─────────────────────────── */

function TeamSection({ data }: { data: Center['team'] }) {
    const t = useTranslate();

    return (
        <Card id="cc-team" className="scroll-mt-4 p-4">
            <SectionHead
                icon={Users}
                title={t('الفريق')}
                hint={data.unassigned > 0 ? t('و:n مهامّ مفتوحة بلا مسؤول', { n: String(data.unassigned) }) : t('من يعمل على ماذا الآن')}
                count={data.rows.length}
            />
            {data.rows.length === 0 ? (
                <p className="text-[13px] text-[#9ca3af]">{t('لا مهامّ ولا مشاريع مسنَدة بعد')}</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-[13px]">
                        <thead>
                            <tr className="text-start text-[11px] text-[#9ca3af]">
                                <th className="py-1 pe-3 text-start font-normal">{t('الاسم')}</th>
                                <th className="py-1 pe-3 text-end font-normal">{t('مهامّ مفتوحة')}</th>
                                <th className="py-1 pe-3 text-end font-normal">{t('متأخّرة')}</th>
                                <th className="py-1 pe-3 text-end font-normal">{t('يملك')}</th>
                                <th className="py-1 pe-3 text-end font-normal">{t('عضو في')}</th>
                                <th className="py-1 text-start font-normal">{t('المهمّة التالية')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-[#f3f4f6]">
                            {data.rows.map((u) => (
                                <tr key={u.id}>
                                    <td className="py-2 pe-3">
                                        <div className="font-medium text-[#111]">{u.name}</div>
                                        <div className="text-[11px] text-[#9ca3af]">{t(ROLE_LABEL[u.role] ?? u.role)}</div>
                                    </td>
                                    <td className="py-2 pe-3 text-end tabular-nums">{number(u.open_tasks)}</td>
                                    <td className={'py-2 pe-3 text-end tabular-nums ' + (u.late_tasks > 0 ? 'font-bold text-[#b91c1c]' : 'text-[#9ca3af]')}>{number(u.late_tasks)}</td>
                                    <td className="py-2 pe-3 text-end tabular-nums">{number(u.projects_owned)}</td>
                                    <td className="py-2 pe-3 text-end tabular-nums">{number(u.projects_member)}</td>
                                    <td className="py-2">
                                        {u.next_task ? (
                                            <a href={u.next_task.url} className="text-[#111] hover:text-[#1d4ed8]">
                                                {u.next_task.title}
                                                <span className="ms-2 text-[11px] text-[#9ca3af]" dir="ltr">
                                                    {u.next_task.project} {u.next_task.due_at ?? ''}
                                                </span>
                                            </a>
                                        ) : (
                                            <span className="text-[#d4d4d8]">—</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}

/* ─────────────────────────── مشتركات ─────────────────────────── */

function SectionHead({ icon: Icon, title, hint, count }: { icon: LucideIcon; title: string; hint: string; count: number }) {
    return (
        <div className="mb-3 flex items-start justify-between gap-3">
            <div className="flex items-start gap-2">
                <Icon className="mt-0.5 size-4 text-[#6b7280]" />
                <div>
                    <h2 className="font-bold text-[#111]">{title}</h2>
                    <p className="text-[12px] text-[#9ca3af]">{hint}</p>
                </div>
            </div>
            <span className={'rounded-full px-2.5 py-0.5 text-[12px] tabular-nums ' + (count > 0 ? 'bg-[#111] text-white' : 'bg-[#f3f4f6] text-[#9ca3af]')}>{number(count)}</span>
        </div>
    );
}

function Empty({ text = 'لا شيء يحتاج يدًا هنا' }: { text?: string }) {
    const t = useTranslate();

    return (
        <p className="flex items-center gap-2 text-[13px] text-[#047857]">
            <CheckCircle2 className="size-4" />
            {t(text)}
        </p>
    );
}

/** «منذ ٣ أيام» لما فات، «بعد ٥ أيام» لما لم يحن، «اليوم» — والتاريخُ تحته */
function Days({ days, at }: { days: number | null; at: string | null }) {
    const t = useTranslate();

    if (days === null) return null;

    const label = days > 0 ? t('منذ :n يومًا', { n: String(days) }) : days < 0 ? t('بعد :n يومًا', { n: String(-days) }) : t('اليوم');

    return (
        <span className="inline-flex items-center gap-1" title={at ?? undefined}>
            {days > 0 && <AlertTriangle className="size-3 text-[#b91c1c]" />}
            {label}
        </span>
    );
}
