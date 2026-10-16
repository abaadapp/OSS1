import type { ReactNode } from 'react';
import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, CalendarDays, ListChecks, MapPin, MoreHorizontal, Plus, UserRound } from 'lucide-react';
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
import { quoteStages, STAGE_LABEL, toStages, type Stage, type Workflow } from '@/lib/projectStages';
import ProjectStepper from '@/Components/ProjectStepper';
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
 * بطاقةٍ شريطُ مراحلها وزرُّ خطوتها التالية، فيُعرف أين هو وما يُنتظر منه
 * دون أن يُفتح. و«مشروع جديد» يبدأ بعرض سعر — انظر `Projects::fromQuotation`.
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
 * وما يُقرَّر هنا اسمُ الزرّ ووجهتُه فقط: أيُّ مرحلةٍ تمّت وهل يجوز الانتقال
 * جاء من الخادم.
 */
function projectNext(p: Row, manage: boolean): Next | null {
    const page = { routeName: 'admin.projects.show', href: route('admin.projects.show', p.id) };
    const at = (tab: string) => ({ routeName: 'admin.projects.show', href: route('admin.projects.show', { id: p.id, tab }) });
    const w = p.workflow;
    if (p.status === 'closed' || p.status === 'cancelled') return null;
    if (w.can_advance && w.next && manage) {
        return {
            label: 'تم — الانتقال إلى :stage',
            params: { stage: STAGE_LABEL[w.next] },
            run: () => router.post(route('admin.projects.workflow.advance', p.id), {}, { preserveScroll: true }),
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

function Card({
    edge,
    title,
    titleLink,
    pill,
    pillLabel,
    mine,
    created,
    meta,
    steps,
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
    created: string | null;
    meta: ReactNode;
    steps: Stage[];
    next: Next | null;
    menu: { label: string; routeName: string; href: string }[];
    /** ما ينقص المرحلةَ الحاليّة — سطرٌ تحت الشريط */
    hint?: string | null;
}) {
    const t = useTranslate();
    const { locale } = usePage<PageProps>().props;
    const date = created ? new Intl.DateTimeFormat(locale === 'en' ? 'en-GB' : 'ar-u-nu-latn', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(created + 'T00:00:00')) : null;

    return (
        <article className="relative overflow-hidden rounded-[14px] border border-[var(--ui-border,#e8e8e8)] bg-white transition-shadow hover:shadow-[0_4px_18px_rgba(0,0,0,0.05)]">
            <span className="absolute inset-y-0 start-0 w-[3px]" style={{ backgroundColor: edge }} />

            <div className="flex items-start justify-between gap-3 px-5 pt-4">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <SmartLink routeName={titleLink.routeName} href={titleLink.href} className="truncate text-[16px] font-semibold text-[#18181b] hover:underline">
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
                    <div className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[12.5px] text-[#52525b]">{meta}</div>
                </div>

                <div className="flex shrink-0 items-center gap-2">
                    {date && (
                        <span className="hidden text-[12px] text-[#71717a] sm:inline">
                            {t('أُنشئ في')} <span className="font-semibold text-[#27272a]">{date}</span>
                        </span>
                    )}
                    <span className="hidden h-4 w-px bg-[#e4e4e7] sm:block" />
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button type="button" aria-label={t('خيارات')} className="flex size-8 items-center justify-center rounded-full text-[#3f3f46] hover:bg-[#f4f4f5]">
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
            </div>

            <div className="mx-5 my-3 h-px bg-[#f0f0f0]" />

            <div className="flex flex-col gap-3 px-5 pb-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0">
                    <ProjectStepper stages={steps} />
                    {hint && <p className="mt-1.5 truncate text-[12px] text-[#71717a]">{hint}</p>}
                </div>
                {next &&
                    (next.run ? (
                        <Button variant="primary" className="shrink-0" onClick={next.run}>
                            {t(next.label, next.params)}
                            <ArrowLeft className="ltr:rotate-180" />
                        </Button>
                    ) : (
                        <Button variant="outline" asChild className="shrink-0">
                            <SmartLink routeName={next.routeName ?? 'admin.projects.show'} href={next.href ?? '#'}>
                                {t(next.label, next.params)}
                            </SmartLink>
                        </Button>
                    ))}
            </div>
        </article>
    );
}

const Dot = () => <span className="text-[#d4d4d8]">·</span>;

function Meta({ code, customer, starts, location, value }: { code: string | null; customer: string; starts: string | null; location: string | null; value: string | null }) {
    const t = useTranslate();

    return (
        <>
            {code && (
                <>
                    <span>
                        {t('رقم')}: <span className="font-semibold tabular-nums text-[#27272a]" dir="ltr">#{code}</span>
                    </span>
                    <span className="h-3 w-px bg-[#e4e4e7]" />
                </>
            )}
            <span className="inline-flex items-center gap-1">
                <UserRound className="size-3.5 text-[#71717a]" />
                {customer}
            </span>
            {starts && (
                <>
                    <Dot />
                    <span className="inline-flex items-center gap-1">
                        <CalendarDays className="size-3.5 text-[#71717a]" />
                        <span className="tabular-nums" dir="ltr">{starts}</span>
                    </span>
                </>
            )}
            {location && (
                <>
                    <Dot />
                    <span className="inline-flex min-w-0 items-center gap-1">
                        <MapPin className="size-3.5 shrink-0 text-[#71717a]" />
                        <span className="max-w-[16rem] truncate">{location}</span>
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

    return (
        <Card
            edge={EDGE[p.status] ?? '#a1a1aa'}
            title={p.name}
            titleLink={show}
            pill={PILL[p.status] ?? PILL.draft}
            pillLabel={PROJECT_STATUS_LABEL[p.status] ?? p.status}
            mine={mine}
            created={p.created_at}
            meta={
                <>
                    <Meta code={p.code} customer={p.customer} starts={p.starts_at} location={p.location} value={p.current_value !== null ? m(p.current_value) : null} />
                    {p.late_tasks > 0 && (
                        <span className="inline-flex items-center gap-1 rounded-full bg-[#fef2f2] px-2 py-0.5 text-[11.5px] font-medium text-[#b91c1c]">
                            <ListChecks className="size-3" />
                            {t(':n متأخّرة', { n: String(p.late_tasks) })}
                        </span>
                    )}
                    {(p.inventory_shortages ?? 0) > 0 && (
                        <span className="inline-flex items-center gap-1 rounded-full bg-[#fef2f2] px-2 py-0.5 text-[11.5px] font-medium text-[#b91c1c]">
                            <AlertTriangle className="size-3" />
                            {t('نقص مخزون: :n بنود', { n: String(p.inventory_shortages) })}
                        </span>
                    )}
                </>
            }
            steps={toStages(p.workflow)}
            next={projectNext(p, manage)}
            menu={menu}
            hint={p.workflow.can_advance ? null : (p.workflow.pending?.[0] ?? null)}
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
            created={q.created_at}
            meta={<Meta code={q.number} customer={q.customer} starts={q.starts_at} location={q.location} value={q.total !== null ? m(q.total) : null} />}
            steps={quoteStages(q.status, STATE_LABEL[q.status])}
            next={quoteNext(q)}
            menu={[{ label: 'فتح العرض', ...show }]}
        />
    );
}
