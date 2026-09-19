import { Mail, MessageCircle, Settings2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import StatCard from '@/Components/StatCard';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import { Button } from '@/Components/ui/button';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { CHANNEL_LABEL, STATUS_LABEL, SUBJECT_LABEL, statusTone, type CommunicationRow } from '@/lib/communications';

interface Props {
    communications: CommunicationRow[];
    pagination: ServerPagination;
    filters: Record<string, string | undefined>;
    totals: { sent: number; delivered: number; read: number; failed: number };
    statuses: string[];
    channels: { email: boolean; whatsapp: boolean };
    projects: { id: number; name: string }[];
    customers: { id: number; name: string }[];
    may: { send: boolean; retry: boolean; templates: boolean; view: boolean };
}

/**
 * سجلُّ المراسلات — ما قالته الشركةُ لعملائها، وماذا صار إليه.
 *
 * الأعدادُ على المصفّى نفسِه: «ما وصل» ممّا أراه أمامي لا ممّا في القاعدة
 * كلِّها، فمن صفّى على مشروعٍ قرأ أرقامَه هو.
 */
export default function CommunicationsIndex({ communications, pagination, filters, totals, statuses, channels, projects, customers }: Props) {
    const t = useTranslate();

    const columns: Column<CommunicationRow>[] = [
        {
            key: 'title',
            header: 'الرسالة',
            cell: (c) => (
                <>
                    <SmartLink routeName="admin.communications.show" href={route('admin.communications.show', c.id)} className="font-medium text-[#1d4ed8]">
                        {c.template_name}
                    </SmartLink>
                    <div className="max-w-[32ch] truncate text-[11px] text-[#9ca3af]">{c.title}</div>
                </>
            ),
        },
        {
            key: 'channel',
            header: 'القناة',
            cell: (c) => (
                <span className="inline-flex items-center gap-1 text-[#374151]">
                    {c.channel === 'whatsapp' ? <MessageCircle className="size-4" /> : <Mail className="size-4" />}
                    {t(CHANNEL_LABEL[c.channel] ?? c.channel)}
                </span>
            ),
        },
        {
            key: 'recipient',
            header: 'إلى',
            cell: (c) => (
                <>
                    <div dir="ltr" className="text-start">{c.recipient}</div>
                    <div className="text-[11px] text-[#9ca3af]">{c.recipient_name ?? c.customer ?? ''}</div>
                </>
            ),
        },
        {
            key: 'subject',
            header: 'عن',
            cell: (c) => (
                <>
                    <div>{c.subject_label ?? '—'}</div>
                    <div className="text-[11px] text-[#9ca3af]">
                        {c.subject_type ? t(SUBJECT_LABEL[c.subject_type] ?? c.subject_type) : ''}
                        {c.project ? ` · ${c.project}` : ''}
                    </div>
                </>
            ),
        },
        {
            key: 'status',
            header: 'الحالة',
            cell: (c) => (
                <>
                    <span className={'rounded-full px-2 py-1 text-[11px] ' + statusTone(c.status)}>{t(STATUS_LABEL[c.status] ?? c.status)}</span>
                    {c.internal && <div className="mt-1 text-[11px] text-[#9ca3af]">{t('إشعار داخلي')}</div>}
                </>
            ),
        },
        {
            key: 'created_at',
            header: 'متى',
            cell: (c) => (
                <div dir="ltr" className="text-start text-[#6b7280]">
                    <div>{c.created_at}</div>
                    {c.read_at ? (
                        <div className="text-[11px] text-[#047857]">{t('قُرئت')} {c.read_at}</div>
                    ) : c.delivered_at ? (
                        <div className="text-[11px] text-[#15803d]">{t('وصلت')} {c.delivered_at}</div>
                    ) : null}
                </div>
            ),
        },
        { key: 'initiated_by', header: 'أرسلها', cell: (c) => <span className="text-[#6b7280]">{c.initiated_by ?? '—'}</span> },
    ];

    const tableFilters: Filter<CommunicationRow>[] = [
        {
            label: 'كل القنوات',
            asTabs: true,
            param: 'channel',
            options: [
                { label: CHANNEL_LABEL.email, value: 'email' },
                { label: CHANNEL_LABEL.whatsapp, value: 'whatsapp' },
            ],
        },
        {
            label: 'الحالة',
            param: 'status',
            options: statuses.map((s) => ({ label: STATUS_LABEL[s] ?? s, value: s })),
        },
        {
            label: 'المشروع',
            param: 'project_id',
            options: projects.map((p) => ({ label: p.name, value: String(p.id) })),
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
            options: [25, 50, 100].map((n) => ({ label: String(n), value: String(n) })),
        },
    ];

    return (
        <AdminLayout title={t('المراسلات')}>
            <PageHeader
                title={t('المراسلات')}
                subtitle={t('كلُّ ما أُرسل للعملاء بالبريد وواتساب — وماذا صار إليه.')}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {!channels.email && !channels.whatsapp && (
                            <span className="text-[12px] text-[#b45309]">{t('لا قناةَ موصولةً على الخادم')}</span>
                        )}
                        <Button variant="outline" asChild>
                            <SmartLink routeName="admin.communications.templates" href={route('admin.communications.templates')}>
                                <Settings2 />
                                {t('القوالب')}
                            </SmartLink>
                        </Button>
                    </div>
                }
            />

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard stat={{ label: t('أُرسلت'), value: number(totals.sent), icon: 'file-text', color: 'primary' }} index={0} />
                <StatCard stat={{ label: t('وصلت'), value: number(totals.delivered), icon: 'circle-check', color: 'success' }} index={1} />
                <StatCard stat={{ label: t('قُرئت'), value: number(totals.read), icon: 'badge-check', color: 'info' }} index={2} />
                <StatCard stat={{ label: t('فشلت'), value: number(totals.failed), icon: 'alert-triangle', color: 'danger' }} index={3} />
            </div>

            <DataTable
                rows={communications}
                columns={columns}
                rowKey={(c) => c.id}
                searchPlaceholder="المُرسَل إليه أو العنوان أو العميل أو معرّف المزوّد"
                searchable={() => ''}
                filters={tableFilters}
                empty="لا مراسلات بعد"
                server={{ pagination, params: filters }}
            />
        </AdminLayout>
    );
}
