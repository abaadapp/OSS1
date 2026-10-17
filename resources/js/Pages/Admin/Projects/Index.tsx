import type { ReactNode } from 'react';
import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, CalendarDays, Check, ListChecks, MoreHorizontal, Plus, UserRound, X } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import StatCard from '@/Components/StatCard';
import SectionTabs, { PROJECT_TABS } from '@/Components/SectionTabs';
import DataTable, { type Filter, type ServerPagination } from '@/Components/DataTable';
import { Button } from '@/Components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/Components/ui/dropdown-menu';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { PROJECT_STATUS_LABEL } from '@/lib/projects';
import { STATE_LABEL } from '@/lib/quotations';
import { cn } from '@/lib/utils';
import { focusDone, moveStage, quoteStages, STAGE_LABEL, toStages, type Stage, type Workflow } from '@/lib/projectStages';
import type { PageProps } from '@/types';

interface Row {
    id: number;
    code: string;
    name: string;
    customer: string;
    customer_id: number | null;
    status: string;
    starts_at: string | null;
    ends_at: string | null;
    approved_value: number | null;
    current_value: number | null;
    owner: string | null;
    owner_id: number | null;
    baselined: boolean;
    open_tasks: number;
    late_tasks: number;
    location: string | null;
    created_at: string | null;
    quotation: { id: number; number: string | null } | null;
    operation: { id: number; status: string } | null;
    delivery: { id: number; status: string } | null;
    /** المسارُ من الخادم — `ProjectWorkflow`، المصدرُ نفسُه الذي تقرؤه صفحةُ المشروع */
    workflow: Workflow;
    /** منتجاتٌ مخزّنة ينقصها المشروعُ القادم في التوقّع — لمن يفتح المخزون (`InventoryForecast`) */
    inventory_shortages?: number;
}

interface QuoteRow {
    id: number;
    number: string | null;
    title: string;
    customer: string;
    status: string;
    location: string | null;
    starts_at: string | null;
    ends_at: string | null;
    total: number | null;
    created_at: string | null;
    created_by: number | null;
}

type Item = { kind: 'project'; p: Row } | { kind: 'quote'; q: QuoteRow };

interface Props {
    projects: Row[];
    quotes: QuoteRow[];
    me: number;
    pagination: ServerPagination;
    filters: Record<string, string | undefined>;
    statuses: string[];
    customers: { id: number; name: string }[];
    totals: { open: number; upcoming: number; unbaselined: number; pending_change_orders: number };
    may: { delivery: boolean; manage: boolean };
}

/**
 * المشاريع — من عرض السعر إلى الإغلاق، بطاقةً واحدة.
 *
 * المشروعُ يبدأ عرضَ سعر: فما لم يُقبل بعدُ بطاقةٌ في مرحلتها الأولى، وما
 * قُبل مشروعٌ يمضي في مراحله — تأكيدٌ فتنفيذٌ فتسليمٌ فإغلاق. وعلى كلّ
 * بطاقةٍ مرحلتُها الحاليّة وأهمُّ تنبيهين وزرُّ خطوتها التالية، فيُعرف أين
 * هو وما يُنتظر منه دون أن يُفتح — والمسارُ كاملًا في صفحته. و«مشروع جديد»
 * يبدأ بعرض سعر — انظر `Projects::fromQuotation`.
 */
export default function ProjectsIndex({ projects, quotes, me, pagination, filters, statuses, customers, totals, may }: Props) {
    const { context } = usePage<PageProps>().props;
    const t = useTranslate();
    const m = (v: number | null) => (v === null ? '—' : money(v, context!.currency));

    const items: Item[] = [...quotes.map((q): Item => ({ kind: 'quote', q })), ...projects.map((p): Item => ({ kind: 'project', p }))];

    const tableFilters: Filter<Item>[] = [
        {
            label: 'كل المراحل',
            asTabs: true,
            param: 'status',
            options: [{ label: 'عروض أسعار', value: 'quote' }, ...statuses.map((s) => ({ label: PROJECT_STATUS_LABEL[s] ?? s, value: s }))],
        },
        {
            label: 'العميل',
            param: 'customer_id',
            options: customers.map((c) => ({ label: c.name, value: String(c.id) })),
        },
        {
            label: 'عدد الصفوف',
            param: 'per_page',
            options: [20, 50, 100].map((n) => ({ label: String(n), value: String(n) })),
        },
    ];

    return (
        <AdminLayout title={t('المشاريع والمعارض')}>
            <PageHeader
                title={t('المشاريع والمعارض')}
                subtitle={t('كلّ مشروع من عرض السعر إلى التسليم — في بطاقة واحدة')}
                actions={
                    may.delivery && (
                        <Button onClick={() => router.visit(route('admin.quotations.create'))}>
                            <Plus />
                            {t('مشروع جديد')}
                        </Button>
                    )
                }
            />

            <SectionTabs tabs={PROJECT_TABS} current="admin.projects.index" />

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard stat={{ label: t('مشاريع قائمة'), value: number(totals.open), icon: 'clipboard-list', color: 'primary' }} index={0} />
                <StatCard stat={{ label: t('تبدأ خلال أسبوع'), value: number(totals.upcoming), icon: 'clock', color: 'success' }} index={1} />
                <StatCard stat={{ label: t('بلا متطلّبات مثبَّتة'), value: number(totals.unbaselined), icon: 'alert-triangle', color: 'warning' }} index={2} />
                <StatCard stat={{ label: t('أوامر تغيير معلَّقة'), value: number(totals.pending_change_orders), icon: 'git-branch', color: 'info' }} index={3} />
            </div>

            <DataTable
                rows={items}
                columns={[]}
                rowKey={(i) => `${i.kind}-${i.kind === 'project' ? i.p.id : i.q.id}`}
                searchPlaceholder="رمز المشروع أو اسمه أو العميل"
                searchable={() => ''}
                filters={tableFilters}
                empty="لا مشاريع بعد — ابدأ بعرض سعر، ويصير مشروعًا حين يقبله العميل"
                server={{ pagination, params: filters }}
                renderBody={(rows) => (
                    <div className="space-y-3 pt-1">
                        {rows.map((i) =>
                            i.kind === 'project' ? (
                                <ProjectCard key={`p-${i.p.id}`} p={i.p} mine={i.p.owner_id === me} money={m} manage={may.manage} />
                            ) : (
                                <QuoteCard key={`q-${i.q.id}`} q={i.q} mine={i.q.created_by === me} money={m} />
                            ),
                        )}
                    </div>
                )}
            />
        </AdminLayout>
    );
}

/* ─────────────────────────── المراحل ─────────────────────────── */

/* والمراحلُ من `projectStages` — مصدرُ البطاقة وصفحة المشروع معًا */
const PURPLE = '#6d28d9';

/* ─────────────────────────── البطاقة ─────────────────────────── */

const EDGE: Record<string, string> = {
    quote: '#a1a1aa',
    rejected: '#dc2626',
    draft: '#a1a1aa',
    confirmed: '#2563eb',
    in_progress: PURPLE,
    on_hold: '#d97706',
    closed: '#16a34a',
    cancelled: '#dc2626',
};

const PILL: Record<string, string> = {
    quote: 'bg-[#f4f4f5] text-[#52525b]',
    rejected: 'bg-[#fef2f2] text-[#b91c1c]',
    draft: 'bg-[#f4f4f5] text-[#52525b]',
    confirmed: 'bg-[#eff6ff] text-[#1d4ed8]',
    in_progress: 'bg-[#f3e8ff] text-[#6d28d9]',
    on_hold: 'bg-[#fffbeb] text-[#b45309]',
    closed: 'bg-[#f0fdf4] text-[#15803d]',
    cancelled: 'bg-[#fef2f2] text-[#b91c1c]',
};

interface Next {
    label: string;
    params?: Record<string, string>;
    routeName?: string;
    href?: string;
    /** فعلٌ يُرسَل من البطاقة نفسِها — «تم — الانتقال» — والخادمُ يعيد السؤال */
    run?: () => void;
}

/**
 * الخطوةُ التالية للمشروع — من المرحلة التي يقف عليها (`workflow.focus`).
 *
 * وما يُقرَّر هنا اسمُ الزرّ ووجهتُه فقط: أيُّ مرحلةٍ تمّت جاء من الخادم.
 * و«تم — الانتقال» اقتراحٌ حين تتمّ الحاليّة لا شرط — كلُّ مرحلةٍ تُختار من
 * شريط صفحة المشروع.
 */
function projectNext(p: Row, manage: boolean): Next | null {
    const page = { routeName: 'admin.projects.show', href: route('admin.projects.show', p.id) };
    const at = (tab: string) => ({ routeName: 'admin.projects.show', href: route('admin.projects.show', { id: p.id, tab }) });
    const w = p.workflow;
    if (p.status === 'closed' || p.status === 'cancelled') return null;
    if (focusDone(w) && w.next && manage) {
        const to = w.next;
        return {
            label: 'تم — الانتقال إلى :stage',
            params: { stage: STAGE_LABEL[to] },
            run: () => moveStage(p.id, to),
        };
    }
    switch (w.focus) {
        case 'quote':
            return p.quotation ? { label: 'فتح العرض', routeName: 'admin.quotations.show', href: route('admin.quotations.show', p.quotation.id) } : { label: 'فتح المشروع', ...page };
        case 'scope':
            return { label: 'نطاق العمل', routeName: 'admin.projects.scope.show', href: route('admin.projects.scope.show', p.id) };
        case 'deposit':
            return { label: 'الدفعة المقدمة', ...page };
        case 'execution':
            return p.operation && !['completed', 'cancelled'].includes(p.operation.status)
                ? { label: 'متابعة الفعاليّة', routeName: 'admin.operations.show', href: route('admin.operations.show', p.operation.id) }
                : { label: 'جدولة الفعاليّة', ...at('operations') };
        case 'delivery':
            return p.delivery
                ? { label: 'متابعة التسليم', routeName: 'admin.deliveries.show', href: route('admin.deliveries.show', p.delivery.id) }
                : { label: 'تجهيز التسليم', ...at('delivery') };
        case 'final':
            return { label: 'الفاتورة النهائية', ...page };
        default:
            return { label: 'إغلاق المشروع', ...page };
    }
}

function quoteNext(q: QuoteRow): Next | null {
    const show = { routeName: 'admin.quotations.show', href: route('admin.quotations.show', q.id) };
    if (q.status === 'draft') return { label: 'إرسال العرض', ...show };
    if (q.status === 'pending_approval') return { label: 'اعتماد العرض', ...show };
    if (q.status === 'sent') return { label: 'تسجيل قرار العميل', ...show };
    return null;
}

/**
 * البطاقة — سطرٌ لما هو (الاسمُ والحال)، وسطرٌ لمن ومتى وكم، وسطرٌ للمرحلة
 * الحاليّة وأهمِّ تنبيهين وزرِّ خطوتها. ولا شريطَ مراحل كاملًا ولا شرحَ ما
 * ينقص: ذلك في صفحة المشروع، والبطاقةُ كلُّها رابطٌ إليها.
 */
function Card({
    edge,
    title,
    titleLink,
    pill,
    pillLabel,
    mine,
    meta,
    stage,
    alerts,
    next,
    menu,
    hint,
}: {
    edge: string;
    title: string;
    titleLink: { routeName: string; href: string };
    pill: string;
    pillLabel: string;
    mine: boolean;
    meta: ReactNode;
    stage: Stage;
    /** أهمُّ تنبيهين — والبقيّةُ في صفحة المشروع */
    alerts: ReactNode[];
    next: Next | null;
    menu: { label: string; routeName: string; href: string }[];
    /** ما ينقص المرحلةَ الحاليّة — تلميحٌ على شارتها لا سطرٌ تحتها */
    hint?: string | null;
}) {
    const t = useTranslate();

    return (
        <article className="relative overflow-hidden rounded-[14px] border border-[var(--ui-border,#e8e8e8)] bg-white transition-shadow hover:shadow-[0_4px_18px_rgba(0,0,0,0.05)]">
            <span className="absolute inset-y-0 start-0 w-[3px]" style={{ backgroundColor: edge }} />

            <div className="flex items-start justify-between gap-3 px-4 pt-3 sm:px-5">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        {/* الرابطُ يمتدّ على البطاقة كلِّها (after:inset-0) — والأزرارُ فوقه بـ z-10 */}
                        <SmartLink routeName={titleLink.routeName} href={titleLink.href} className="min-w-0 break-words text-[15.5px] font-semibold text-[#18181b] after:absolute after:inset-0 hover:underline">
                            {title}
                        </SmartLink>
                        <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11.5px] font-medium', pill)}>
                            <span className="size-1.5 rounded-full bg-current" />
                            {t(pillLabel)}
                        </span>
                        {mine && (
                            <span className="inline-flex items-center gap-1 rounded-full bg-[#eef2ff] px-2 py-0.5 text-[11.5px] font-medium text-[#4338ca]">
                                <UserRound className="size-3" />
                                {t('مسؤوليتي')}
                            </span>
                        )}
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[12.5px] text-[#52525b]">{meta}</div>
                </div>

                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <button type="button" aria-label={t('خيارات')} className="relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full text-[#3f3f46] hover:bg-[#f4f4f5]">
                            <MoreHorizontal className="size-4" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        {menu.map((it) => (
                            <DropdownMenuItem key={it.href} onSelect={() => router.visit(it.href)}>
                                {t(it.label)}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-2 px-4 pb-3 pt-2 sm:px-5">
                <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                    <StageChip stage={stage} hint={hint} />
                    {alerts.slice(0, 2)}
                </div>
                {next &&
                    (next.run ? (
                        <Button size="sm" variant="primary" className="relative z-10 shrink-0" onClick={next.run}>
                            {t(next.label, next.params)}
                            <ArrowLeft className="ltr:rotate-180" />
                        </Button>
                    ) : (
                        <Button size="sm" variant="outline" asChild className="relative z-10 shrink-0">
                            <SmartLink routeName={next.routeName ?? 'admin.projects.show'} href={next.href ?? '#'}>
                                {t(next.label, next.params)}
                            </SmartLink>
                        </Button>
                    ))}
            </div>
        </article>
    );
}

/**
 * المرحلةُ الحاليّة وحدَها — اسمُها وأيقونتُها، و✓ إن تمّت تنتظر الانتقال،
 * و✕ إن وقفت. وما ينقصها في التلميح لا في سطرٍ تحت البطاقة.
 */
function StageChip({ stage, hint }: { stage: Stage; hint?: string | null }) {
    const t = useTranslate();
    const Icon = stage.state === 'done' ? Check : stage.state === 'failed' ? X : stage.icon;

    return (
        <span
            data-stage={stage.key}
            title={hint ?? undefined}
            className={cn(
                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[12px] font-medium',
                stage.state === 'failed' ? 'bg-[#fef2f2] text-[#b91c1c]' : 'bg-[#f5f3ff] text-[#6d28d9]',
            )}
        >
            <Icon className="size-3.5 shrink-0" />
            {t(stage.label)}
            {stage.note && <span className="font-normal opacity-80">· {t(stage.note)}</span>}
        </span>
    );
}

const Dot = () => <span className="text-[#d4d4d8]">·</span>;

function Meta({ code, customer, starts, created, value }: { code: string | null; customer: string; starts: string | null; created: string | null; value: string | null }) {
    const t = useTranslate();

    return (
        <>
            {code && (
                <>
                    <span className="font-semibold tabular-nums text-[#27272a]" dir="ltr">#{code}</span>
                    <Dot />
                </>
            )}
            <span className="inline-flex min-w-0 items-center gap-1">
                <UserRound className="size-3.5 shrink-0 text-[#71717a]" />
                <span className="break-words">{customer}</span>
            </span>
            {/* موعدُ الفعاليّة — وإلّا فيومُ الإنشاء باسمه */}
            {(starts || created) && (
                <>
                    <Dot />
                    <span className="inline-flex items-center gap-1">
                        <CalendarDays className="size-3.5 text-[#71717a]" />
                        {!starts && <span>{t('أُنشئ في')}</span>}
                        <span className="tabular-nums" dir="ltr">{starts ?? created}</span>
                    </span>
                </>
            )}
            {value && (
                <>
                    <Dot />
                    <span className="font-semibold tabular-nums text-[#27272a]">{value}</span>
                </>
            )}
        </>
    );
}

/** شارةُ تنبيهٍ صغيرة على البطاقة */
function Flag({ icon: Icon, children }: { icon: typeof AlertTriangle; children: ReactNode }) {
    return (
        <span className="inline-flex items-center gap-1 rounded-full bg-[#fef2f2] px-2 py-0.5 text-[11.5px] font-medium text-[#b91c1c]">
            <Icon className="size-3" />
            {children}
        </span>
    );
}

/** المرحلةُ التي يقف عليها الفريق — من `ProjectWorkflow` كما وصلت */
const focusOf = (steps: Stage[]): Stage => steps.find((s) => s.focus) ?? steps.find((s) => s.state === 'current') ?? steps[0];

function ProjectCard({ p, mine, money: m, manage }: { p: Row; mine: boolean; money: (v: number | null) => string; manage: boolean }) {
    const t = useTranslate();
    const show = { routeName: 'admin.projects.show', href: route('admin.projects.show', p.id) };
    const menu = [
        { label: 'فتح المشروع', ...show },
        { label: 'نطاق العمل', routeName: 'admin.projects.scope.show', href: route('admin.projects.scope.show', p.id) },
        { label: 'المهامّ', routeName: 'admin.projects.show', href: route('admin.projects.show', { id: p.id, tab: 'tasks' }) },
        ...(p.quotation ? [{ label: 'عرض السعر', routeName: 'admin.quotations.show', href: route('admin.quotations.show', p.quotation.id) }] : []),
        ...(p.operation ? [{ label: 'الفعاليّة', routeName: 'admin.operations.show', href: route('admin.operations.show', p.operation.id) }] : []),
        ...(p.delivery ? [{ label: 'حزمة التسليم', routeName: 'admin.deliveries.show', href: route('admin.deliveries.show', p.delivery.id) }] : []),
    ];
    const alerts: ReactNode[] = [
        ...(p.late_tasks > 0 ? [<Flag key="late" icon={ListChecks}>{t(':n متأخّرة', { n: String(p.late_tasks) })}</Flag>] : []),
        ...((p.inventory_shortages ?? 0) > 0 ? [<Flag key="stock" icon={AlertTriangle}>{t('نقص مخزون: :n بنود', { n: String(p.inventory_shortages) })}</Flag>] : []),
    ];
    const stage = focusOf(toStages(p.workflow));

    return (
        <Card
            edge={EDGE[p.status] ?? '#a1a1aa'}
            title={p.name}
            titleLink={show}
            pill={PILL[p.status] ?? PILL.draft}
            pillLabel={PROJECT_STATUS_LABEL[p.status] ?? p.status}
            mine={mine}
            meta={<Meta code={p.code} customer={p.customer} starts={p.starts_at} created={p.created_at} value={p.current_value !== null ? m(p.current_value) : null} />}
            stage={{ ...stage, note: stage.state === 'failed' ? stage.note : undefined }}
            alerts={alerts}
            next={projectNext(p, manage)}
            menu={menu}
            hint={p.workflow.pending?.[0] ?? null}
        />
    );
}

function QuoteCard({ q, mine, money: m }: { q: QuoteRow; mine: boolean; money: (v: number | null) => string }) {
    const show = { routeName: 'admin.quotations.show', href: route('admin.quotations.show', q.id) };

    return (
        <Card
            edge={EDGE[q.status === 'rejected' ? 'rejected' : 'quote']}
            title={q.title}
            titleLink={show}
            pill={PILL[q.status === 'rejected' ? 'rejected' : 'quote']}
            pillLabel={q.status === 'rejected' ? 'مرفوض' : 'عرض سعر'}
            mine={mine}
            meta={<Meta code={q.number} customer={q.customer} starts={q.starts_at} created={q.created_at} value={q.total !== null ? m(q.total) : null} />}
            stage={quoteStages(q.status, STATE_LABEL[q.status])[0]}
            alerts={[]}
            next={quoteNext(q)}
            menu={[{ label: 'فتح العرض', ...show }]}
        />
    );
}
