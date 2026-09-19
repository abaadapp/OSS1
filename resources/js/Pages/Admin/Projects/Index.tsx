import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import StatCard from '@/Components/StatCard';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { PROJECT_STATUS_LABEL, projectTone } from '@/lib/projects';
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
    baselined: boolean;
}

interface Props {
    projects: Row[];
    pagination: ServerPagination;
    filters: Record<string, string | undefined>;
    statuses: string[];
    customers: { id: number; name: string }[];
    totals: { open: number; upcoming: number; unbaselined: number; pending_change_orders: number };
}

/**
 * المشاريع — مركزُ العمل.
 *
 * ولا زرَّ «مشروع جديد»: المشروعُ يُنشأ من قبول عرض السعر لا بيد — انظر
 * `Projects::fromQuotation`. والقيمةُ المعروضة هي الحاليّةُ (الأصلُ +
 * أوامرُ التغيير المقبولة)، لأنّها ما يُفوتَر عليه اليوم.
 */
export default function ProjectsIndex({ projects, pagination, filters, statuses, customers, totals }: Props) {
    const { context } = usePage<PageProps>().props;
    const t = useTranslate();
    const m = (v: number | null) => (v === null ? '—' : money(v, context!.currency));

    const columns: Column<Row>[] = [
        {
            key: 'code',
            header: 'المشروع',
            cell: (p) => (
                <>
                    <SmartLink
                        routeName="admin.projects.show"
                        href={route('admin.projects.show', p.id)}
                        className="font-medium text-[#1d4ed8]"
                    >
                        {p.code}
                    </SmartLink>
                    <div className="max-w-[28ch] truncate text-[11px] text-[#9ca3af]">{p.name}</div>
                </>
            ),
        },
        { key: 'customer', header: 'العميل', cell: (p) => p.customer },
        {
            key: 'starts_at',
            header: 'موعد الفعالية',
            cell: (p) => (
                <span dir="ltr" className="text-[#6b7280]">
                    {p.starts_at ?? '—'}
                    {p.ends_at && p.ends_at !== p.starts_at ? ` → ${p.ends_at}` : ''}
                </span>
            ),
        },
        {
            key: 'current_value',
            header: 'القيمة المعتمَدة',
            align: 'end',
            cell: (p) => (
                <>
                    <span className="tabular-nums font-semibold">{m(p.current_value)}</span>
                    {/* وفرقُ أوامر التغيير يُقال تحته: من قرأ الرقمَ يعرف أنّه تغيّر */}
                    {p.current_value !== null && p.approved_value !== null && Math.abs(p.current_value - p.approved_value) > 0.0005 && (
                        <div className="text-[11px] text-[#9ca3af]">
                            {t('الأصل')}: {m(p.approved_value)}
                        </div>
                    )}
                </>
            ),
        },
        { key: 'owner', header: 'المسؤول', cell: (p) => p.owner ?? <span className="text-[#d4d4d8]">—</span> },
        {
            key: 'status',
            header: 'الحالة',
            cell: (p) => (
                <>
                    <span className={'rounded-full px-2 py-1 text-[11px] ' + projectTone(p.status)}>
                        {t(PROJECT_STATUS_LABEL[p.status] ?? p.status)}
                    </span>
                    {!p.baselined && (p.status === 'confirmed' || p.status === 'in_progress') && (
                        <div className="mt-1 text-[11px] text-[#b45309]">{t('المتطلّبات لم تُثبَّت')}</div>
                    )}
                </>
            ),
        },
    ];

    const tableFilters: Filter<Row>[] = [
        {
            label: 'كل الحالات',
            asTabs: true,
            param: 'status',
            options: statuses.map((s) => ({ label: PROJECT_STATUS_LABEL[s] ?? s, value: s })),
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
        <AdminLayout title={t('المشاريع')}>
            <PageHeader
                title={t('المشاريع')}
                subtitle={t('ما وافق عليه العملاء ويُنفَّذ الآن — والمشروعُ يُنشأ من قبول عرض السعر.')}
            />

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard stat={{ label: t('مشاريع قائمة'), value: number(totals.open), icon: 'clipboard-list', color: 'primary' }} index={0} />
                <StatCard stat={{ label: t('تبدأ خلال أسبوع'), value: number(totals.upcoming), icon: 'clock', color: 'success' }} index={1} />
                <StatCard stat={{ label: t('بلا متطلّبات مثبَّتة'), value: number(totals.unbaselined), icon: 'alert-triangle', color: 'warning' }} index={2} />
                <StatCard stat={{ label: t('أوامر تغيير معلَّقة'), value: number(totals.pending_change_orders), icon: 'git-branch', color: 'info' }} index={3} />
            </div>

            <DataTable
                rows={projects}
                columns={columns}
                rowKey={(p) => p.id}
                searchPlaceholder="رمز المشروع أو اسمه أو العميل"
                searchable={() => ''}
                filters={tableFilters}
                empty="لا مشاريع بعد — يُنشأ المشروع حين يقبل العميل عرض السعر"
                server={{ pagination, params: filters }}
            />
        </AdminLayout>
    );
}
