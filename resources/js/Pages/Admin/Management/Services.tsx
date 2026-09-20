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
    product_id: number;
    name: string;
    kind: string | null;
    quotations: number;
    quoted_quantity: number;
    quoted_value: number;
    accepted_quantity: number;
    accepted_value: number;
    invoiced_value: number;
    invoiced_quantity: number;
    invoices: number;
    projects: number;
    average_selling_price: number | null;
    discount_percent: number | null;
    unit_cost: number | null;
    contribution: number | null;
    contribution_basis: string;
    url: string;
}

interface Stage {
    key: string;
    label: string;
    count: number;
    value: number | null;
    committed: boolean;
}

interface Props {
    period: PeriodInfo;
    periods: PeriodOption[];
    list: { rows: Row[]; totals: { products: number; quoted_value: number; accepted_value: number; invoiced_value: number }; basis: string };
    funnel: { stages: Stage[]; total: { count: number; value: number }; acceptance_rate: number | null; acceptance_basis: string; average_value: number | null; average_days_to_accept: number | null; decided: number };
}

/** الخدماتُ والقمع — ما يُعرض وما يُقبل وما يُفوتر، من الكتالوج الفعليّ */
export default function ManagementServices({ period, periods, list, funnel }: Props) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const m = (v: number | null | undefined) => (v === null || v === undefined ? '—' : money(v, context!.currency));

    return (
        <AdminLayout title="الخدمات والقمع">
            <PageHeader title="الخدمات والقمع التجاري" subtitle={list.basis} actions={<PeriodPicker period={period} periods={periods} routeName="admin.management.services" />} />
            <SectionTabs tabs={MANAGEMENT_TABS} current="admin.management.services" className="mb-4" />

            <div className="mb-4 grid gap-4 lg:grid-cols-3">
                <Card className="p-4">
                    <h3 className="mb-1 text-[14px] font-semibold">{t('القمع التجاري')}</h3>
                    <p className="mb-2 text-[11px] text-[#9ca3af]">{t('العروض المنشأة في المدّة بحالها الآن — والمسودّة ليست إيرادًا محتمَلًا')}</p>
                    <div className="mb-3 grid grid-cols-3 gap-2 text-center text-[12px]">
                        <div className="rounded-lg bg-[#fafafa] p-2"><div className="text-[16px] font-semibold tabular-nums">{pct(funnel.acceptance_rate)}</div><div className="text-[#6b7280]">{t('نسبة القبول')}</div></div>
                        <div className="rounded-lg bg-[#fafafa] p-2"><div className="text-[16px] font-semibold tabular-nums" dir="ltr">{m(funnel.average_value)}</div><div className="text-[#6b7280]">{t('متوسّط العرض')}</div></div>
                        <div className="rounded-lg bg-[#fafafa] p-2"><div className="text-[16px] font-semibold tabular-nums">{funnel.average_days_to_accept ?? '—'}</div><div className="text-[#6b7280]">{t('أيّام حتى القبول')}</div></div>
                    </div>
                    <p className="mb-2 text-[11px] text-[#9ca3af]">{funnel.acceptance_basis} — {t('حُسم')}: {funnel.decided}</p>
                    <ul className="space-y-1.5 text-[13px]">
                        {funnel.stages.map((s) => {
                            const width = funnel.total.count > 0 ? Math.max(4, Math.round((s.count / funnel.total.count) * 100)) : 0;

                            return (
                                <li key={s.key}>
                                    <div className="flex items-center justify-between gap-2">
                                        <span>{s.label}{s.committed && <span className="ms-1 rounded-full bg-[#ecfdf5] px-1.5 text-[10px] text-[#047857]">{t('ملتزَم')}</span>}</span>
                                        <span className="tabular-nums text-[#6b7280]" dir="ltr">{s.count}{s.value !== null ? ` · ${m(s.value)}` : ''}</span>
                                    </div>
                                    <div className="h-1.5 overflow-hidden rounded-full bg-[#f4f4f5]"><div className={'h-full ' + (s.committed ? 'bg-[#059669]' : 'bg-[#a3a3a3]')} style={{ width: `${width}%` }} /></div>
                                </li>
                            );
                        })}
                    </ul>
                </Card>

                <Card className="p-4 lg:col-span-2">
                    <h3 className="mb-1 text-[14px] font-semibold">{t('الأصناف الأعلى فوترةً')}</h3>
                    <div className="mb-3 grid grid-cols-3 gap-2">
                        <Kpi label="المعروض" value={m(list.totals.quoted_value)} />
                        <Kpi label="المقبول" value={m(list.totals.accepted_value)} />
                        <Kpi label="المفوتَر" value={m(list.totals.invoiced_value)} />
                    </div>
                    {list.rows.length === 0 ? <p className="text-[12px] text-[#9ca3af]">{t('لا أصنافَ بحركةٍ في المدّة')}</p> : (
                        <BarChart labels={list.rows.slice(0, 8).map((r) => r.name)} series={list.rows.slice(0, 8).map((r) => r.invoiced_value)} format={(v) => m(v)} />
                    )}
                </Card>
            </div>

            <Card className="overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-[12px]">
                        <thead className="bg-[#fafafa] text-[11px] text-[#6b7280]">
                            <tr>
                                <th className="px-3 py-2 text-start">{t('الصنف')}</th>
                                <th className="px-3 py-2 text-end">{t('عروض')}</th>
                                <th className="px-3 py-2 text-end">{t('كمّية معروضة')}</th>
                                <th className="px-3 py-2 text-end">{t('المعروض')}</th>
                                <th className="px-3 py-2 text-end">{t('المقبول')}</th>
                                <th className="px-3 py-2 text-end">{t('المفوتَر')}</th>
                                <th className="px-3 py-2 text-end">{t('مشاريع')}</th>
                                <th className="px-3 py-2 text-end">{t('متوسّط السعر')}</th>
                                <th className="px-3 py-2 text-end">{t('الخصم')}</th>
                                <th className="px-3 py-2 text-end">{t('المساهمة')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {list.rows.length === 0 && <tr><td colSpan={10} className="px-3 py-10 text-center text-[#9ca3af]">{t('لا أصنافَ بحركةٍ في المدّة')}</td></tr>}
                            {list.rows.map((r) => (
                                <tr key={r.product_id} className="border-t border-[#f4f4f5]">
                                    <td className="px-3 py-1.5"><a href={r.url} className="font-medium hover:text-[#1d4ed8]">{r.name}</a>{r.kind && <span className="ms-1 text-[10px] text-[#9ca3af]">{r.kind}</span>}</td>
                                    <td className="px-3 py-1.5 text-end tabular-nums">{r.quotations}</td>
                                    <td className="px-3 py-1.5 text-end tabular-nums">{r.quoted_quantity}</td>
                                    <td className="px-3 py-1.5 text-end tabular-nums" dir="ltr">{m(r.quoted_value)}</td>
                                    <td className="px-3 py-1.5 text-end tabular-nums" dir="ltr">{m(r.accepted_value)}</td>
                                    <td className="px-3 py-1.5 text-end tabular-nums" dir="ltr">{m(r.invoiced_value)}</td>
                                    <td className="px-3 py-1.5 text-end tabular-nums">{r.projects}</td>
                                    <td className="px-3 py-1.5 text-end tabular-nums" dir="ltr">{m(r.average_selling_price)}</td>
                                    <td className="px-3 py-1.5 text-end tabular-nums" dir="ltr">{pct(r.discount_percent)}</td>
                                    <td className="px-3 py-1.5 text-end tabular-nums" dir="ltr" title={r.contribution_basis}>{m(r.contribution)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <p className="border-t border-[#f4f4f5] px-3 py-2 text-[11px] text-[#9ca3af]">{t('المساهمةُ تقديرٌ على تكلفة الصنف اليوم × الكميّة المفوتَرة — لا قيدٌ محاسبيّ؛ والبندُ الحرّ بلا صنفٍ لا يُنسب')}</p>
            </Card>
        </AdminLayout>
    );
}
