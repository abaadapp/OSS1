import { usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import StatCard from '@/Components/StatCard';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import { Button } from '@/Components/ui/button';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { STATE_LABEL, stateTone } from '@/lib/quotations';
import type { PageProps } from '@/types';

interface Row {
    id: number;
    /** فارغٌ للمسودّة: الرقمُ يُقطع عند الإرسال */
    number: string | null;
    label: string;
    revision: number;
    title: string;
    customer: string;
    customer_id: number | null;
    status: string;
    /** الحالُ المعروضة — وفيها المشتقُّ: منتهية · لم تُفتح · بانتظار العميل */
    state: string;
    issued_at: string | null;
    valid_until: string | null;
    days_left: number | null;
    total: number;
    view_count: number;
    event_starts_at: string | null;
}

interface Props {
    quotations: Row[];
    pagination: ServerPagination;
    filters: Record<string, string | undefined>;
    states: string[];
    customers: { id: number; name: string }[];
    totals: {
        awaiting_client: number;
        awaiting_approval: number;
        expired: number;
        pipeline: number;
        accepted: number;
    };
    may: { create: boolean };
}

/**
 * عروضُ الأسعار.
 *
 * وليست فواتيرَ لم تُصدَر بعد: الفاتورةُ مطالبةٌ بمبلغ، وهذه وعدٌ بثمنٍ
 * يُقرأ ويُقارَن ثمّ يُقبل أو يُردّ. فما يُقاس عليها غيرُ ما يُقاس على
 * تلك — «كم ينتظر قرارًا» لا «كم بقي على العملاء».
 */
export default function QuotationsIndex({
    quotations,
    pagination,
    filters,
    states,
    customers,
    totals,
    may,
}: Props) {
    const { context } = usePage<PageProps>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const columns: Column<Row>[] = [
        {
            key: 'number',
            header: 'رقم العرض',
            cell: (q) => (
                <>
                    {/* والمسارُ من سجلّ المسارات لا مكتوبًا بالحرف — انظر `SmartLink` */}
                    <SmartLink
                        routeName="admin.quotations.show"
                        href={route('admin.quotations.show', q.id)}
                        className="font-medium text-[#1d4ed8]"
                    >
                        {/* ومسودّةٌ بلا رقم تُعرف بمعرّفها — لا بفراغ */}
                        {q.number ? q.label : `${t('مسودة')} #${q.id}`}
                    </SmartLink>
                    <div className="max-w-[26ch] truncate text-[11px] text-[#9ca3af]">{q.title}</div>
                </>
            ),
        },
        { key: 'customer', header: 'العميل', cell: (q) => q.customer },
        {
            key: 'event_starts_at',
            header: 'موعد الفعالية',
            cell: (q) => <span dir="ltr" className="text-[#6b7280]">{q.event_starts_at ?? '—'}</span>,
        },
        {
            key: 'total',
            header: 'الإجمالي',
            align: 'end',
            cell: (q) => <span className="tabular-nums font-semibold">{m(q.total)}</span>,
        },
        {
            key: 'valid_until',
            header: 'صالح حتى',
            cell: (q) => {
                if (!q.valid_until) return <span className="text-[#d4d4d8]">—</span>;

                /*
                    و«بقي يومان» أنفعُ من تاريخٍ يُحسب في الرأس: من يفتح
                    القائمة يسأل «أيُّها يفوت؟» لا «متى ينتهي كلٌّ منها».
                */
                const left = q.days_left;
                const urgent = q.status === 'sent' && left !== null && left <= 3;

                return (
                    <>
                        <span dir="ltr" className="text-[#6b7280]">{q.valid_until}</span>
                        {urgent && (
                            <div className={'text-[11px] ' + (left! < 0 ? 'text-[#b91c1c]' : 'text-[#b45309]')}>
                                {left! < 0 ? t('انتهى') : left === 0 ? t('ينتهي اليوم') : t('بقي :n يوم', { n: number(left!) })}
                            </div>
                        )}
                    </>
                );
            },
        },
        {
            key: 'state',
            header: 'الحالة',
            cell: (q) => (
                <span className={'rounded-full px-2 py-1 text-[11px] ' + stateTone(q.state)}>
                    {t(STATE_LABEL[q.state] ?? q.state)}
                </span>
            ),
        },
        {
            key: 'view_count',
            header: 'مرات الفتح',
            align: 'end',
            cell: (q) =>
                q.view_count > 0 ? (
                    <span className="tabular-nums text-[#047857]">{number(q.view_count)}</span>
                ) : (
                    <span className="text-[#d4d4d8]">—</span>
                ),
        },
    ];

    /*
     * والمرشِّحاتُ كلُّها خادميّة: ترشيحُ صفحةٍ بعد جلبها يعني ترشيحَ عشرين
     * صفًّا من أربعمئة — فتقول الشاشة «لا منتهية» ولها عشرون في الثالثة.
     */
    const tableFilters: Filter<Row>[] = [
        {
            label: 'كل الحالات',
            asTabs: true,
            param: 'state',
            options: states.map((s) => ({ label: STATE_LABEL[s] ?? s, value: s })),
        },
        {
            label: 'العميل',
            param: 'customer_id',
            options: customers.map((c) => ({ label: c.name, value: String(c.id) })),
        },
        { label: 'من تاريخ', type: 'date', param: 'from' },
        { label: 'إلى تاريخ', type: 'date', param: 'to' },
        {
            label: 'عدد الصفوف',
            param: 'per_page',
            options: [20, 50, 100].map((n) => ({ label: String(n), value: String(n) })),
        },
    ];

    return (
        <AdminLayout title={t('عروض الأسعار')}>
            <PageHeader
                title={t('عروض الأسعار')}
                subtitle={t('ما عُرض على العملاء، وما ينتظر قرارهم، وما انتهت صلاحيته.')}
                actions={
                    may.create && (
                        <Button asChild>
                            <SmartLink routeName="admin.quotations.create" href={route('admin.quotations.create')}>
                                <Plus />
                                {t('عرض سعر جديد')}
                            </SmartLink>
                        </Button>
                    )
                }
            />

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                {/* وقيمةُ ما ينتظر قرارًا أوّلًا: هي خطُّ الأعمال القادم لا عددُ أوراق */}
                <StatCard
                    stat={{ label: t('بانتظار العميل'), value: m(totals.pipeline), icon: 'clock', color: 'primary' }}
                    index={0}
                />
                <StatCard
                    stat={{ label: t('عروض مقبولة'), value: m(totals.accepted), icon: 'check-circle', color: 'success' }}
                    index={1}
                />
                <StatCard
                    stat={{
                        label: t('تنتظر اعتمادًا'),
                        value: number(totals.awaiting_approval),
                        icon: 'shield-alert',
                        color: 'warning',
                    }}
                    index={2}
                />
                <StatCard
                    stat={{ label: t('منتهية الصلاحية'), value: number(totals.expired), icon: 'alert-triangle', color: 'danger' }}
                    index={3}
                />
            </div>

            <DataTable
                rows={quotations}
                columns={columns}
                rowKey={(q) => q.id}
                searchPlaceholder="رقم العرض أو عنوان الفعالية أو العميل"
                searchable={() => ''}
                filters={tableFilters}
                empty="لا عروض أسعار بعد"
                server={{ pagination, params: filters }}
            />
        </AdminLayout>
    );
}
