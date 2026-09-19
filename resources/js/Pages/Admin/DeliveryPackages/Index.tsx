import { Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import StatCard from '@/Components/StatCard';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import { Button } from '@/Components/ui/button';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { DELIVERY_STATE_LABEL, deliveryTone } from '@/lib/deliveries';

interface Row {
    id: number;
    number: string | null;
    label: string;
    title: string;
    customer: string;
    status: string;
    items_count: number;
    sent_at: string | null;
    decided_at: string | null;
    view_count: number;
}

interface Props {
    packages: Row[];
    pagination: ServerPagination;
    filters: Record<string, string | undefined>;
    statuses: string[];
    customers: { id: number; name: string }[];
    totals: { in_preparation: number; awaiting_client: number; accepted: number; declined: number };
    may: { create: boolean };
}

/**
 * حزمُ التسليم — ما سلّمته الشركةُ للعملاء، وما ينتظر قبولهم.
 */
export default function DeliveryPackagesIndex({ packages, pagination, filters, statuses, customers, totals, may }: Props) {
    const t = useTranslate();

    const columns: Column<Row>[] = [
        {
            key: 'number',
            header: 'الحزمة',
            cell: (p) => (
                <>
                    <SmartLink
                        routeName="admin.deliveries.show"
                        href={route('admin.deliveries.show', p.id)}
                        className="font-medium text-[#1d4ed8]"
                    >
                        {p.number ? p.label : `${t('مسودة')} #${p.id}`}
                    </SmartLink>
                    <div className="max-w-[28ch] truncate text-[11px] text-[#9ca3af]">{p.title}</div>
                </>
            ),
        },
        { key: 'customer', header: 'العميل', cell: (p) => p.customer },
        {
            key: 'items_count',
            header: 'البنود',
            align: 'end',
            cell: (p) => <span className="tabular-nums">{number(p.items_count)}</span>,
        },
        {
            key: 'sent_at',
            header: 'أُرسلت',
            cell: (p) => <span dir="ltr" className="text-[#6b7280]">{p.sent_at ?? '—'}</span>,
        },
        {
            key: 'status',
            header: 'الحالة',
            cell: (p) => (
                <span className={'rounded-full px-2 py-1 text-[11px] ' + deliveryTone(p.status)}>
                    {t(DELIVERY_STATE_LABEL[p.status] ?? p.status)}
                </span>
            ),
        },
        {
            key: 'view_count',
            header: 'مرات الفتح',
            align: 'end',
            cell: (p) =>
                p.view_count > 0 ? (
                    <span className="tabular-nums text-[#047857]">{number(p.view_count)}</span>
                ) : (
                    <span className="text-[#d4d4d8]">—</span>
                ),
        },
    ];

    const tableFilters: Filter<Row>[] = [
        {
            label: 'كل الحالات',
            asTabs: true,
            param: 'status',
            options: statuses.map((s) => ({ label: DELIVERY_STATE_LABEL[s] ?? s, value: s })),
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
        <AdminLayout title={t('حزم التسليم')}>
            <PageHeader
                title={t('حزم التسليم')}
                subtitle={t('ما سُلِّم للعملاء في ختام العمل، وما ينتظر قبولهم.')}
                actions={
                    may.create && (
                        <Button asChild>
                            <SmartLink routeName="admin.deliveries.create" href={route('admin.deliveries.create')}>
                                <Plus />
                                {t('حزمة تسليم جديدة')}
                            </SmartLink>
                        </Button>
                    )
                }
            />

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard
                    stat={{ label: t('بانتظار العميل'), value: number(totals.awaiting_client), icon: 'clock', color: 'primary' }}
                    index={0}
                />
                <StatCard
                    stat={{ label: t('قبلها العميل'), value: number(totals.accepted), icon: 'check-circle', color: 'success' }}
                    index={1}
                />
                <StatCard
                    stat={{ label: t('قيد الإعداد'), value: number(totals.in_preparation), icon: 'package', color: 'warning' }}
                    index={2}
                />
                <StatCard
                    stat={{ label: t('رفضها العميل'), value: number(totals.declined), icon: 'alert-triangle', color: 'danger' }}
                    index={3}
                />
            </div>

            <DataTable
                rows={packages}
                columns={columns}
                rowKey={(p) => p.id}
                searchPlaceholder="رقم الحزمة أو عنوانها أو العميل"
                searchable={() => ''}
                filters={tableFilters}
                empty="لا حزم تسليم بعد"
                server={{ pagination, params: filters }}
            />
        </AdminLayout>
    );
}
