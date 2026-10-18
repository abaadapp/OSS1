import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import ExportMenu from '@/Components/ExportMenu';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { MANAGEMENT_TABS } from '@/Components/SectionTabs';
import Field, { Select } from '@/Components/Field';
import { Card } from '@/Components/ui/card';
import PeriodPicker from '@/Components/management/PeriodPicker';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PeriodInfo, PeriodOption } from '@/lib/management';
import type { Currency } from '@/types';

interface Column {
    key: string;
    label: string;
    kind: string;
}

interface Section {
    title: string;
    columns: Column[];
    rows: (string | number)[][];
}

interface Props {
    period: PeriodInfo;
    periods: PeriodOption[];
    catalog: { key: string; title: string; desc: string }[];
    report: string | null;
    title: string | null;
    filters: Record<string, string>;
    data: { periodLabel: string; sections: Section[] } | null;
    currency: Currency;
    export: { xlsx: string; csv: string; pdf: string } | null;
    may: Record<string, boolean>;
}

/**
 * التقاريرُ الإداريّة — تُقرأ هنا جدولًا جدولًا وتُنزَّل من باب التقارير العامّ.
 *
 * كلُّ رقمٍ في التقرير هو ذاتُه في الشاشة: الحسابُ واحد (`ManagementReports`)
 * والتصديرُ يمرّ بالبنية القائمة، فلا تُخترع صيغةٌ ثانية.
 */
export default function ManagementReports({ period, periods, catalog, report, title, filters, data, currency, export: exp, may }: Props) {
    const t = useTranslate();
    const cell = (v: string | number, kind: string) => (kind === 'money' ? money(Number(v), currency) : kind === 'number' ? String(v) : String(v));
    const extra: Record<string, string> = { report: report ?? '' };
    if (filters.budget) extra.budget = filters.budget;
    if (filters.horizon) extra.horizon = filters.horizon;

    return (
        <AdminLayout title="التقارير الإداريّة">
            <PageHeader
                title="التقارير الإداريّة"
                subtitle={t('ثمانيةُ تقاريرَ من الحساب نفسِه الذي تراه الشاشات — تُقرأ هنا وتُنزَّل من باب التقارير')}
                actions={report ? (
                    <div className="flex flex-wrap items-end gap-2">
                        {/* ربحيّةُ المشاريع تقرأ «الكلّ» أيضًا — مشاريعُ كلِّ الفترات (`ManagementReports::project_profitability`) */}
                        {!['budget_vs_actual'].includes(report) && <PeriodPicker period={period} periods={periods} routeName="admin.management.reports" extra={extra} allowAll={report === 'project_profitability'} />}
                        {report === 'budget_vs_actual' && <Field label="التجميع" className="w-[120px]" hint="الموازنةُ المعتمَدة للسنة الحاليّة ما لم تُختر من شاشة الموازنات"><Select value={filters.grouping ?? 'month'} onChange={(e) => router.get(route('admin.management.reports'), { report, budget: filters.budget, grouping: e.target.value }, { preserveState: true, replace: true })} options={[{ value: 'month', label: t('شهري') }, { value: 'quarter', label: t('ربعي') }, { value: 'year', label: t('سنوي') }]} /></Field>}
                        {report === 'cash_forecast' && <Field label="الأفق" className="w-[120px]"><Select value={filters.horizon ?? '90'} onChange={(e) => router.get(route('admin.management.reports'), { ...filters, report, horizon: e.target.value }, { preserveState: true, replace: true })} options={[30, 60, 90, 180, 365].map((h) => ({ value: h, label: `${h} ${t('يومًا')}` }))} /></Field>}
                    </div>
                ) : undefined}
            />
            <SectionTabs tabs={MANAGEMENT_TABS} current="admin.management.reports" className="mb-4" />

            <div className="grid gap-4 lg:grid-cols-4">
                <Card className="p-3">
                    <ul className="space-y-1">
                        {catalog.map((c) => (
                            <li key={c.key}>
                                <a href={route('admin.management.reports', { report: c.key })} className={'block rounded-lg px-3 py-2 text-[13px] hover:bg-[#fafafa] ' + (report === c.key ? 'bg-[#f4f4f5] font-semibold' : '')}>
                                    {c.title}
                                    <span className="block text-[11px] font-normal text-[#9ca3af]">{c.desc}</span>
                                </a>
                            </li>
                        ))}
                    </ul>
                    {!may.reports && <p className="mt-3 px-3 text-[11px] text-[#9ca3af]">{t('التنزيلُ يحتاج صلاحيّة التقارير')}</p>}
                </Card>

                <div className="space-y-4 lg:col-span-3">
                    {!report && <Card className="p-8 text-center text-[13px] text-[#9ca3af]">{t('اختر تقريرًا من القائمة')}</Card>}
                    {report && data && (
                        <>
                            <Card className="flex flex-wrap items-center justify-between gap-2 p-4">
                                <div>
                                    <h3 className="text-[15px] font-semibold">{title}</h3>
                                    <p className="text-[12px] text-[#6b7280]">{data.periodLabel}</p>
                                </div>
                                {/* زرٌّ واحد «تصدير» كأخواته — والروابطُ من الخادم بمدّة الشاشة ومرشّحاتها */}
                                {exp && <ExportMenu xlsx={exp.xlsx} pdf={exp.pdf} csv={exp.csv} checkPdf />}
                            </Card>
                            {data.sections.map((s) => (
                                <Card key={s.title} className="overflow-hidden">
                                    <h4 className="px-4 pt-3 text-[13px] font-semibold">{s.title} <span className="text-[11px] font-normal text-[#9ca3af]">({s.rows.length})</span></h4>
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-[12px]">
                                            <thead className="text-[11px] text-[#6b7280]">
                                                <tr>{s.columns.map((c) => <th key={c.key} className={'px-3 py-2 ' + (c.kind === 'money' || c.kind === 'number' ? 'text-end' : 'text-start')}>{c.label}</th>)}</tr>
                                            </thead>
                                            <tbody>
                                                {s.rows.length === 0 && <tr><td colSpan={s.columns.length} className="px-3 py-6 text-center text-[#9ca3af]">{t('لا صفوف')}</td></tr>}
                                                {s.rows.map((r, i) => (
                                                    <tr key={i} className="border-t border-[#f4f4f5]">
                                                        {r.map((v, j) => {
                                                            const c = s.columns[j];

                                                            return <td key={c?.key ?? j} className={'px-3 py-1.5 ' + (c?.kind === 'money' || c?.kind === 'number' ? 'text-end tabular-nums' : '')} dir={c?.kind === 'money' || c?.kind === 'number' ? 'ltr' : undefined}>{cell(v, c?.kind ?? 'text')}</td>;
                                                        })}
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </Card>
                            ))}
                        </>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}
