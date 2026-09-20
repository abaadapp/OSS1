import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { MANAGEMENT_TABS } from '@/Components/SectionTabs';
import AreaChart from '@/Components/charts/AreaChart';
import BarChart from '@/Components/charts/BarChart';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import AlertList from '@/Components/management/AlertList';
import Kpi from '@/Components/management/Kpi';
import PeriodPicker from '@/Components/management/PeriodPicker';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { pct, type Alert, type Change, type PeriodInfo, type PeriodOption } from '@/lib/management';
import type { PageProps } from '@/types';

interface Snapshot {
    basis: Record<string, string>;
    revenue: { net: number; gross: number; tax: number; credited: number; invoices: number };
    collections: { total: number; count: number };
    direct_costs: { supplier_invoices: number; stock_consumed: number; total: number; unallocated_supplier_invoices: number };
    expenses: { total: number; count: number; unallocated: number; by_type: { type: string; total: number; count: number }[] };
    payroll: number;
    gross_contribution: number;
    gross_margin_percent: number | null;
    operating_result: number;
    operating_margin_percent: number | null;
    previous?: { period: PeriodInfo; revenue: number; collections: number; expenses: number; direct_costs: number; operating_result: number; change: Record<string, Change> };
    generated_at: string;
}

interface Props {
    period: PeriodInfo;
    periods: PeriodOption[];
    snapshot: Snapshot;
    monthly: { month: string; revenue: number; invoices: number; collections: number; expenses: number; direct_costs: number; projects: number; average_project_value: number | null }[];
    ladder: Record<'quoted' | 'contracted' | 'invoiced' | 'collected' | 'outstanding', { value: number; basis: string; count?: number; overdue?: number; gross?: number }>;
    aging: { buckets: { key: string; label: string; amount: number }[]; customers: { customer_id: number; customer: string; outstanding: number; overdue: number; oldest_invoice: string | null; days_overdue: number }[]; total: number };
    unallocated: { count: number; value: number };
    alerts: Alert[];
    thresholds: { key: string; label: string; unit: string; value: number; default: number }[];
    may: Record<string, boolean>;
}

/**
 * الأداءُ الماليّ — الإيرادُ بتعريفاته، والتكلفةُ والمصروفُ والنتيجة، والذممُ بأعمارها.
 *
 * كلُّ رقمٍ يحمل أساسَه، والمقارنةُ بالمدّة السابقة لا تُختلق. وسلّمُ الإيراد
 * يفصل المعروضَ عن المتعاقَد عن المفوتَر عن المحصَّل عن الذمم — ولا يجمعها.
 */
export default function ManagementFinancial({ period, periods, snapshot: s, monthly, ladder, aging, unallocated, alerts, thresholds, may }: Props) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const m = (v: number | null | undefined) => (v === null || v === undefined ? '—' : money(v, context!.currency));
    const [editing, setEditing] = useState(false);
    const [values, setValues] = useState<Record<string, string>>(Object.fromEntries(thresholds.map((th) => [th.key, String(th.value)])));

    const overdueTotal = aging.buckets.filter((b) => b.key !== 'not_due').reduce((n, b) => n + b.amount, 0);

    return (
        <AdminLayout title="الأداء المالي">
            <PageHeader title="الأداء المالي" subtitle={t('الإيراد والتحصيل والتكلفة والمصروفات والنتيجة — بأساسٍ مكتوب لكلّ رقم')} actions={<PeriodPicker period={period} periods={periods} routeName="admin.management.financial" />} />
            <SectionTabs tabs={MANAGEMENT_TABS} current="admin.management.financial" className="mb-4" />

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
                <Kpi label="الإيراد الصافي" value={m(s.revenue.net)} basis={s.basis.revenue} change={s.previous?.change.revenue} sub={<span>{t('إجمالي بالضريبة')}: <span dir="ltr">{m(s.revenue.gross)}</span> · {t('ضريبة')}: <span dir="ltr">{m(s.revenue.tax)}</span></span>} />
                <Kpi label="التحصيل" value={m(s.collections.total)} basis={s.basis.collections} change={s.previous?.change.collections} sub={<span>{s.collections.count} {t('دفعة')}</span>} />
                <Kpi label="التكلفة المباشرة" value={m(s.direct_costs.total)} basis={s.basis.direct_costs} change={s.previous?.change.direct_costs} invert sub={<span>{t('سندات')}: <span dir="ltr">{m(s.direct_costs.supplier_invoices)}</span> · {t('مستهلَكات')}: <span dir="ltr">{m(s.direct_costs.stock_consumed)}</span></span>} />
                <Kpi label="المصروفات المدفوعة" value={m(s.expenses.total)} basis={s.basis.expenses} change={s.previous?.change.expenses} invert sub={<span>{s.expenses.count} {t('مصروف')}</span>} />
                <Kpi label="الرواتب" value={m(s.payroll)} basis={s.basis.payroll} />
                <Kpi label="المساهمة الإجمالية" value={m(s.gross_contribution)} basis={t('الإيراد الصافي − التكلفة المباشرة')} tone={s.gross_contribution < 0 ? 'danger' : undefined} sub={<span>{t('الهامش')}: {pct(s.gross_margin_percent)}</span>} />
                <Kpi label="النتيجة التشغيلية" value={m(s.operating_result)} basis={s.basis.operating_result} tone={s.operating_result < 0 ? 'danger' : 'ok'} sub={<span>{t('الهامش')}: {pct(s.operating_margin_percent)}</span>} />
                <Kpi label="تكاليف بلا مشروع" value={m(unallocated.value)} basis={t('سنداتُ مورّدين ومصروفاتٌ في المدّة لم تُنسب إلى مشروع — تدخل الشركةَ ولا تدخل ربحيّة مشروع')} tone={unallocated.count > 0 ? 'warning' : undefined} sub={<span>{unallocated.count} {t('مستند')}</span>} />
            </div>

            <div className="mb-4 grid gap-4 lg:grid-cols-3">
                <Card className="p-4 lg:col-span-2">
                    <h3 className="mb-1 text-[14px] font-semibold">{t('سلّم الإيراد')}</h3>
                    <p className="mb-3 text-[11px] text-[#9ca3af]">{t('خمسةُ تعريفاتٍ لا تُجمع: كلٌّ يجيب سؤالًا مختلفًا')}</p>
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
                        {(['quoted', 'contracted', 'invoiced', 'collected', 'outstanding'] as const).map((k) => (
                            <div key={k} className="rounded-lg bg-[#fafafa] p-3">
                                <div className="text-[11px] text-[#6b7280]">{t(LADDER_LABEL[k])}</div>
                                <div className="text-[16px] font-semibold tabular-nums" dir="ltr">{m(ladder[k].value)}</div>
                                {k === 'outstanding' && ladder[k].overdue !== undefined && <div className="text-[11px] text-[#b91c1c]" dir="ltr">{t('متأخّر')}: {m(ladder[k].overdue)}</div>}
                                <div className="mt-1 text-[10px] leading-snug text-[#9ca3af]">{ladder[k].basis}</div>
                            </div>
                        ))}
                    </div>
                </Card>
                <Card className="p-4">
                    <h3 className="mb-1 text-[14px] font-semibold">{t('أعمار الذمم')}</h3>
                    <p className="mb-2 text-[11px] text-[#9ca3af]">{t('القائم')}: <span dir="ltr">{m(aging.total)}</span> · {t('المتأخّر')}: <span dir="ltr">{m(overdueTotal)}</span></p>
                    <BarChart labels={aging.buckets.map((b) => t(b.label))} series={aging.buckets.map((b) => b.amount)} format={(v) => m(v)} />
                    <a href={route('admin.finance.receivables')} className="mt-2 inline-block text-[12px] text-[#1d4ed8]">{t('الذمم المدينة')}</a>
                </Card>
            </div>

            <div className="mb-4 grid gap-4 lg:grid-cols-2">
                <Card className="p-4">
                    <h3 className="mb-1 text-[14px] font-semibold">{t('شهرًا بشهر — آخر 12 شهرًا')}</h3>
                    <AreaChart labels={monthly.map((r) => r.month.slice(5))} fullLabels={monthly.map((r) => r.month)} data={monthly.map((r) => r.revenue)} counts={monthly.map((r) => r.invoices)} format={(v) => m(v)} height={170} />
                    <div className="mt-2 overflow-x-auto">
                        <table className="w-full text-[12px]">
                            <thead className="text-[#6b7280]"><tr><th className="py-1 text-start">{t('الشهر')}</th><th className="py-1 text-end">{t('الإيراد')}</th><th className="py-1 text-end">{t('التحصيل')}</th><th className="py-1 text-end">{t('المصروفات')}</th><th className="py-1 text-end">{t('التكلفة')}</th><th className="py-1 text-end">{t('مشاريع')}</th><th className="py-1 text-end">{t('متوسّط القيمة')}</th></tr></thead>
                            <tbody>{monthly.map((r) => <tr key={r.month} className="border-t border-[#f4f4f5]"><td className="py-1" dir="ltr">{r.month}</td><td className="py-1 text-end tabular-nums" dir="ltr">{m(r.revenue)}</td><td className="py-1 text-end tabular-nums" dir="ltr">{m(r.collections)}</td><td className="py-1 text-end tabular-nums" dir="ltr">{m(r.expenses)}</td><td className="py-1 text-end tabular-nums" dir="ltr">{m(r.direct_costs)}</td><td className="py-1 text-end tabular-nums">{r.projects}</td><td className="py-1 text-end tabular-nums" dir="ltr">{m(r.average_project_value)}</td></tr>)}</tbody>
                        </table>
                    </div>
                </Card>
                <Card className="p-4">
                    <h3 className="mb-1 text-[14px] font-semibold">{t('المصروفات بالنوع')}</h3>
                    <p className="mb-2 text-[11px] text-[#9ca3af]">{s.basis.expenses} · {t('بلا مشروع')}: <span dir="ltr">{m(s.expenses.unallocated)}</span></p>
                    {s.expenses.by_type.length === 0 ? <p className="text-[12px] text-[#9ca3af]">{t('لا مصروفاتٍ مدفوعة في المدّة')}</p> : (
                        <>
                            <BarChart labels={s.expenses.by_type.slice(0, 8).map((r) => r.type)} series={s.expenses.by_type.slice(0, 8).map((r) => r.total)} format={(v) => m(v)} />
                            <table className="mt-2 w-full text-[12px]">
                                <tbody>{s.expenses.by_type.map((r) => <tr key={r.type} className="border-t border-[#f4f4f5]"><td className="py-1">{r.type}</td><td className="py-1 text-end tabular-nums">{r.count}</td><td className="py-1 text-end tabular-nums" dir="ltr">{m(r.total)}</td></tr>)}</tbody>
                            </table>
                        </>
                    )}
                </Card>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card className="p-4">
                    <h3 className="mb-2 text-[14px] font-semibold">{t('التنبيهات الإدارية')} <span className="text-[11px] font-normal text-[#9ca3af]">({alerts.length})</span></h3>
                    <AlertList alerts={alerts} />
                </Card>
                <Card className="p-4">
                    <div className="mb-2 flex items-center justify-between">
                        <h3 className="text-[14px] font-semibold">{t('عتبات التنبيهات')}</h3>
                        {may.thresholds && <Button variant="outline" size="sm" onClick={() => setEditing(!editing)}>{editing ? t('إلغاء') : t('ضبط')}</Button>}
                    </div>
                    {errors?.thresholds && <div className="mb-2 rounded-lg bg-[#fef2f2] px-3 py-2 text-[12px] text-[#b91c1c]">{errors.thresholds}</div>}
                    <ul className="space-y-1 text-[13px]">
                        {thresholds.map((th) => (
                            <li key={th.key} className="flex items-center justify-between gap-3">
                                <span className="text-[#6b7280]">{th.label}</span>
                                {editing ? (
                                    <Input type="number" step="0.001" className="w-32" value={values[th.key]} onChange={(e) => setValues({ ...values, [th.key]: e.target.value })} dir="ltr" />
                                ) : (
                                    <span className="tabular-nums" dir="ltr">{th.unit === 'money' ? m(th.value) : th.unit === 'percent' ? `${th.value}%` : th.value}</span>
                                )}
                            </li>
                        ))}
                    </ul>
                    {editing && <Button className="mt-3" onClick={() => router.put(route('admin.management.thresholds'), { thresholds: values }, { preserveScroll: true, onSuccess: () => setEditing(false) })}>{t('حفظ العتبات')}</Button>}
                    <p className="mt-2 text-[11px] text-[#9ca3af]">{t('كلُّ تنبيهٍ يقيس رقمًا على عتبةٍ من هنا ويقولها في نصّه — لا حكمَ مخبّأ في الكود')}</p>
                </Card>
            </div>
            <p className="mt-4 text-[11px] text-[#9ca3af]">{t('آخر قراءة')}: <span dir="ltr">{s.generated_at}</span></p>
        </AdminLayout>
    );
}

const LADDER_LABEL: Record<string, string> = { quoted: 'المعروض', contracted: 'المتعاقَد', invoiced: 'المفوتَر', collected: 'المحصَّل', outstanding: 'الذمم' };
