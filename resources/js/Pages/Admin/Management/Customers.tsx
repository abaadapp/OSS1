import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { MANAGEMENT_TABS } from '@/Components/SectionTabs';
import BarChart from '@/Components/charts/BarChart';
import { Card } from '@/Components/ui/card';
import Kpi from '@/Components/management/Kpi';
import PeriodPicker from '@/Components/management/PeriodPicker';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { pct, type PeriodInfo, type PeriodOption } from '@/lib/management';
import type { PageProps } from '@/types';

interface Row {
    customer_id: number;
    customer: string;
    quotations: number;
    accepted: number;
    acceptance_rate: number | null;
    contracted: number;
    invoiced: number;
    invoices: number;
    collected: number;
    outstanding: number;
    overdue: number;
    projects: number;
    completed_projects: number;
    average_project_value: number | null;
    first_invoice_at: string | null;
    last_invoice_at: string | null;
    url: string;
}

interface Props {
    period: PeriodInfo;
    periods: PeriodOption[];
    list: { rows: Row[]; totals: { customers: number; invoiced: number; collected: number; outstanding: number; overdue: number }; basis: string };
    concentration: { status: string; reasons: string[]; total: number; customers: number; top: { customer_id: number; customer: string; net: number; percent: number; url: string }[]; top1_percent: number | null; top5_percent: number | null; top10_percent: number | null; threshold_percent: number; above_threshold: boolean; basis?: string };
    average_payment_delay: number | null;
}

/**
 * العملاءُ بالأرقام — وقائعُ لا أحكام.
 *
 * لا «عميلٌ جيّد» ولا درجة: عروضٌ ونسبةُ قبولٍ ومفوتَرٌ ومحصَّلٌ وذممٌ ومشاريع.
 * والتركيزُ حصصٌ تُقاس على عتبةٍ تضبطها الشركة — لا تصنيفٌ يُختلق.
 */
export default function ManagementCustomers({ period, periods, list, concentration: c, average_payment_delay }: Props) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const m = (v: number | null | undefined) => (v === null || v === undefined ? '—' : money(v, context!.currency));

    return (
        <AdminLayout title="العملاء">
            <PageHeader title="تحليلات العملاء" subtitle={list.basis} actions={<PeriodPicker period={period} periods={periods} routeName="admin.management.customers" />} />
            <SectionTabs tabs={MANAGEMENT_TABS} current="admin.management.customers" className="mb-4" />

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <Kpi label="عملاء نشطون" value={String(list.totals.customers)} basis={t('من له عرضٌ أو فاتورةٌ أو مشروعٌ في المدّة أو ذمّةٌ قائمة')} />
                <Kpi label="المفوتَر" value={m(list.totals.invoiced)} />
                <Kpi label="المحصَّل" value={m(list.totals.collected)} />
                <Kpi label="الذمم القائمة" value={m(list.totals.outstanding)} tone={list.totals.overdue > 0 ? 'warning' : undefined} sub={<span>{t('متأخّر')}: <span dir="ltr">{m(list.totals.overdue)}</span></span>} />
                <Kpi label="حصّة أكبر عميل" value={pct(c.top1_percent)} tone={c.above_threshold ? 'warning' : undefined} sub={<span>{t('العتبة')}: {c.threshold_percent}%</span>} basis={c.basis} />
                <Kpi label="متوسّط تأخّر السداد" value={average_payment_delay === null ? t('لا بيانات كافية') : `${average_payment_delay} ${t('يومًا')}`} basis={t('أيّامٌ بين الاستحقاق والتحصيل — لدفعاتٍ في المدّة، بثلاثِ ملاحظاتٍ فأكثر')} />
            </div>

            <div className="mb-4 grid gap-4 lg:grid-cols-3">
                <Card className="p-4">
                    <h3 className="mb-1 text-[14px] font-semibold">{t('تركيز الإيراد')}</h3>
                    {c.status === 'insufficient' ? (
                        <p className="text-[12px] text-[#9ca3af]">{c.reasons.join(' · ')}</p>
                    ) : (
                        <>
                            <p className="mb-2 text-[11px] text-[#9ca3af]">{t('أكبر عميل')}: {pct(c.top1_percent)} · {t('أكبر 5')}: {pct(c.top5_percent)} · {t('أكبر 10')}: {pct(c.top10_percent)} — {t('من')} {c.customers} {t('عميلًا')}</p>
                            <BarChart labels={c.top.slice(0, 6).map((r) => r.customer)} series={c.top.slice(0, 6).map((r) => r.net)} format={(v) => m(v)} />
                            <ul className="mt-2 space-y-1 text-[12px]">
                                {c.top.map((r) => (
                                    <li key={r.customer_id} className="flex items-center justify-between gap-2">
                                        <a href={r.url} className="min-w-0 truncate hover:text-[#1d4ed8]">{r.customer}</a>
                                        <span className="tabular-nums text-[#6b7280]" dir="ltr">{m(r.net)} · {r.percent}%</span>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </Card>
                <Card className="overflow-hidden lg:col-span-2">
                    <div className="overflow-x-auto">
                        <table className="w-full text-[12px]">
                            <thead className="bg-[#fafafa] text-[11px] text-[#6b7280]">
                                <tr>
                                    <th className="px-3 py-2 text-start">{t('العميل')}</th>
                                    <th className="px-3 py-2 text-end">{t('عروض')}</th>
                                    <th className="px-3 py-2 text-end">{t('قُبل')}</th>
                                    <th className="px-3 py-2 text-end">{t('نسبة القبول')}</th>
                                    <th className="px-3 py-2 text-end">{t('المتعاقَد')}</th>
                                    <th className="px-3 py-2 text-end">{t('المفوتَر')}</th>
                                    <th className="px-3 py-2 text-end">{t('المحصَّل')}</th>
                                    <th className="px-3 py-2 text-end">{t('القائم')}</th>
                                    <th className="px-3 py-2 text-end">{t('المتأخّر')}</th>
                                    <th className="px-3 py-2 text-end">{t('مشاريع')}</th>
                                    <th className="px-3 py-2 text-end">{t('متوسّط المشروع')}</th>
                                    <th className="px-3 py-2 text-start">{t('أوّل / آخر فاتورة')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {list.rows.length === 0 && <tr><td colSpan={12} className="px-3 py-10 text-center text-[#9ca3af]">{t('لا عملاءَ بحركةٍ في المدّة')}</td></tr>}
                                {list.rows.map((r) => (
                                    <tr key={r.customer_id} className="border-t border-[#f4f4f5]">
                                        <td className="px-3 py-1.5"><a href={r.url} className="font-medium hover:text-[#1d4ed8]">{r.customer}</a></td>
                                        <td className="px-3 py-1.5 text-end tabular-nums">{r.quotations}</td>
                                        <td className="px-3 py-1.5 text-end tabular-nums">{r.accepted}</td>
                                        <td className="px-3 py-1.5 text-end tabular-nums" dir="ltr">{pct(r.acceptance_rate)}</td>
                                        <td className="px-3 py-1.5 text-end tabular-nums" dir="ltr">{m(r.contracted)}</td>
                                        <td className="px-3 py-1.5 text-end tabular-nums" dir="ltr">{m(r.invoiced)}</td>
                                        <td className="px-3 py-1.5 text-end tabular-nums" dir="ltr">{m(r.collected)}</td>
                                        <td className="px-3 py-1.5 text-end tabular-nums" dir="ltr">{m(r.outstanding)}</td>
                                        <td className={'px-3 py-1.5 text-end tabular-nums ' + (r.overdue > 0 ? 'text-[#b91c1c]' : '')} dir="ltr">{m(r.overdue)}</td>
                                        <td className="px-3 py-1.5 text-end tabular-nums">{r.projects} / {r.completed_projects}</td>
                                        <td className="px-3 py-1.5 text-end tabular-nums" dir="ltr">{m(r.average_project_value)}</td>
                                        <td className="px-3 py-1.5 text-[11px] text-[#9ca3af]" dir="ltr">{r.first_invoice_at ?? '—'} / {r.last_invoice_at ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            </div>
        </AdminLayout>
    );
}
