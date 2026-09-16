import { usePage } from '@inertiajs/react';
import ReportScreen, { type Filter, type Option } from '@/Components/ReportScreen';
import { type ReportRange } from '@/Components/RangeTabs';
import { Badge } from '@/Components/ui/badge';
import { Card } from '@/Components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Row {
    id: number;
    number: string;
    customer: string | null;
    branch: string | null;
    /** حالُ السداد لا حالُ العمود — انظر CustomerInvoice::paymentState */
    status: string;
    total: number;
    paid: number;
    outstanding: number;
    at: string | null;
    due: string | null;
}

interface Props {
    rows: Row[];
    summary: {
        count: number;
        total: number;
        average: number;
        outstanding: number;
        overdue: number;
        drafts: number;
        cancelled: number;
    };
    filters: Record<string, string | null>;
    options: Record<string, Option[]>;
    truncated: { shown: number; total: number } | null;
    range: ReportRange;
    rangeLabel: string;
}

/**
 * تقرير فواتير العملاء — قراءةٌ لا إدارة.
 *
 * ═══ وكان هذا تقريرَ «الطلبات» ═══
 *
 * يفتح على جدولٍ فارغ أبدًا: لا مسارَ يُنشئ طلبًا منذ رُفعت نقطة البيع.
 * وما تبيعه الشركةُ فعلًا يعيش في فواتير العملاء، فصار التقريرُ عليها.
 *
 * والحالُ المعروضة حالُ **سداد** لا حالُ عمود: «متأخّرة» و«مدفوعة جزئيًّا»
 * تُشتقّان من الاستحقاق والتخصيصات ولا تُخزَّنان — والترشيحُ بهما يقع في
 * القاعدة بالتعبير نفسِه لا في الذاكرة بعد جلب الصفحة.
 */
export default function ReportsInvoices() {
    const { rows, summary, filters, options, truncated, range, rangeLabel, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const controls: Filter[] = [
        { kind: 'select', key: 'status', label: 'حالة المستند', options: options.statuses ?? [] },
        { kind: 'select', key: 'state', label: 'حالة السداد', options: options.states ?? [] },
        { kind: 'select', key: 'branch_id', label: 'الفرع', options: options.branches ?? [] },
    ];

    const stats = [
        { label: t('عدد الفواتير'), value: number(summary.count), icon: 'file-text', color: 'info' },
        { label: t('إجمالي الصادر'), value: m(summary.total), icon: 'wallet', color: 'primary' },
        { label: t('الباقي على العملاء'), value: m(summary.outstanding), icon: 'coins', color: summary.outstanding > 0 ? 'warning' : 'success' },
        {
            label: t('المتأخّرة'),
            value: number(summary.overdue),
            icon: 'circle-alert',
            color: summary.overdue > 0 ? 'danger' : 'success',
        },
    ];

    return (
        <ReportScreen
            reportKey="invoices"
            title="فواتير العملاء"
            subtitle="كل فاتورةٍ بحال سدادها وفرعها وقيمتها وما بقي عليها"
            range={range}
            rangeLabel={rangeLabel}
            filters={filters}
            controls={controls}
            stats={stats}
            truncated={truncated}
        >
            <Card className="overflow-hidden">
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead>{t('التاريخ')}</TableHead>
                            <TableHead>{t('رقم الفاتورة')}</TableHead>
                            <TableHead>{t('العميل')}</TableHead>
                            <TableHead>{t('الفرع')}</TableHead>
                            <TableHead>{t('الاستحقاق')}</TableHead>
                            <TableHead>{t('الحالة')}</TableHead>
                            <TableHead className="text-end">{t('الإجمالي')}</TableHead>
                            <TableHead className="text-end">{t('المسدَّد')}</TableHead>
                            <TableHead className="text-end">{t('الباقي')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 ? (
                            <TableEmpty colSpan={9}>{t('لا فواتير في هذه الفترة')}</TableEmpty>
                        ) : (
                            rows.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell className="text-[#6b7280]">{r.at ?? '—'}</TableCell>
                                    <TableCell className="font-medium text-[#111]">{r.number ?? '—'}</TableCell>
                                    <TableCell className="font-medium text-[#111]">{r.customer ?? '—'}</TableCell>
                                    <TableCell className="text-[#6b7280]">{r.branch ?? '—'}</TableCell>
                                    <TableCell className="text-[#6b7280]">{r.due ?? '—'}</TableCell>
                                    <TableCell>{r.status ? <Badge status={r.status} /> : null}</TableCell>
                                    <TableCell className="text-end tabular-nums">{m(r.total)}</TableCell>
                                    <TableCell className="text-end tabular-nums text-[#6b7280]">{m(r.paid)}</TableCell>
                                    {/* والباقي وحده يُبرز: هو السؤال الذي يُفتح التقريرُ لأجله */}
                                    <TableCell className="text-end font-medium tabular-nums text-[#111]">
                                        {m(r.outstanding)}
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </Card>
        </ReportScreen>
    );
}
