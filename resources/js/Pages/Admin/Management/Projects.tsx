import { router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { MANAGEMENT_TABS } from '@/Components/SectionTabs';
import Field, { Select } from '@/Components/Field';
import { Card } from '@/Components/ui/card';
import CompletenessBadge from '@/Components/management/CompletenessBadge';
import Kpi from '@/Components/management/Kpi';
import PeriodPicker from '@/Components/management/PeriodPicker';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { PROJECT_STATUS_LABEL, projectTone } from '@/lib/projects';
import { marginTone, pct, type CompletenessInfo, type PeriodInfo, type PeriodOption } from '@/lib/management';
import type { PageProps } from '@/types';

interface Row {
    project: { id: number; code: string; name: string; status: string; customer: string | null; starts_at: string | null };
    contracted: number;
    revenue: number;
    collected: number;
    receivable: number;
    direct_costs: { supplier_invoices: number; expenses: number; stock_consumed: number; total: number };
    committed: number;
    contribution: number;
    margin_percent: number | null;
    forecast_margin_percent: number | null;
    completeness: CompletenessInfo;
    invoiced_above_approved: boolean;
}

interface Props {
    period: PeriodInfo;
    periods: PeriodOption[];
    all: boolean;
    sort: string;
    list: { rows: Row[]; totals: { projects: number; contracted: number; revenue: number; collected: number; receivable: number; direct_costs: number; contribution: number; margin_percent: number | null; partial: number; insufficient: number; negative: number } };
}

/**
 * ربحيّةُ المشاريع — الصيغةُ واحدة (`ProjectFinancials`) والاكتمالُ مكتوبٌ على كلّ صفّ.
 *
 * الهامشُ الجزئيّ يُقال جزئيًّا: «على التكاليف المخصَّصة حاليًّا». ولا يُخفى
 * مشروعٌ بمساهمةٍ سالبة — يُقدَّم.
 */
export default function ManagementProjects({ period, periods, all, sort, list }: Props) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const m = (v: number | null | undefined) => (v === null || v === undefined ? '—' : money(v, context!.currency));
    const tt = list.totals;

    return (
        <AdminLayout title="ربحيّة المشاريع">
            <PageHeader
                title="ربحيّة المشاريع"
                subtitle={t('المتعاقَد والمفوتَر والمحصَّل والتكلفة المباشرة والمساهمة — بتاريخ بدء المشروع')}
                actions={
                    <div className="flex flex-wrap items-end gap-2">
                        <PeriodPicker period={period} periods={periods} routeName="admin.management.projects" extra={{ sort }} allowAll />
                        <Field label="الترتيب" className="min-w-[160px]">
                            <Select value={sort} onChange={(e) => router.get(route('admin.management.projects'), { period: all ? 'all' : period.key, from: period.from, to: period.to, sort: e.target.value }, { preserveState: true, replace: true })} options={[{ value: 'margin_asc', label: t('الأضعف هامشًا أوّلًا') }, { value: 'margin', label: t('الأقوى هامشًا أوّلًا') }, { value: 'revenue', label: t('الأعلى إيرادًا') }, { value: 'contribution', label: t('الأعلى مساهمة') }]} />
                        </Field>
                    </div>
                }
            />
            <SectionTabs tabs={MANAGEMENT_TABS} current="admin.management.projects" className="mb-4" />

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
                <Kpi label="مشاريع" value={String(tt.projects)} sub={<span>{t('جزئيّ التكلفة')}: {tt.partial} · {t('غير كافٍ')}: {tt.insufficient}</span>} />
                <Kpi label="المتعاقَد" value={m(tt.contracted)} basis={t('القيمةُ المعتمَدة الحاليّة — الأصلُ وأوامرُ التغيير المقبولة')} />
                <Kpi label="الإيراد الصافي" value={m(tt.revenue)} basis={t('فواتيرُ صادرة على المشروع صافيةً من الضريبة بعد إشعارات الدائن')} />
                <Kpi label="المحصَّل" value={m(tt.collected)} />
                <Kpi label="الذمم" value={m(tt.receivable)} tone={tt.receivable > 0 ? 'warning' : undefined} />
                <Kpi label="التكلفة المباشرة" value={m(tt.direct_costs)} basis={t('سنداتُ مورّدين معتمَدة ومصروفاتٌ مدفوعة وما استُهلك من الرفّ — على المشروع')} invert />
                <Kpi label="المساهمة" value={m(tt.contribution)} tone={tt.contribution < 0 ? 'danger' : 'ok'} sub={<span>{t('الهامش')}: {pct(tt.margin_percent)} · {t('سالبة')}: {tt.negative}</span>} />
            </div>

            <Card className="overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-[13px]">
                        <thead className="bg-[#fafafa] text-[12px] text-[#6b7280]">
                            <tr>
                                <th className="px-3 py-2 text-start">{t('المشروع')}</th>
                                <th className="px-3 py-2 text-start">{t('العميل')}</th>
                                <th className="px-3 py-2 text-center">{t('الحالة')}</th>
                                <th className="px-3 py-2 text-end">{t('المتعاقَد')}</th>
                                <th className="px-3 py-2 text-end">{t('الإيراد')}</th>
                                <th className="px-3 py-2 text-end">{t('المحصَّل')}</th>
                                <th className="px-3 py-2 text-end">{t('الذمّة')}</th>
                                <th className="px-3 py-2 text-end">{t('التكلفة')}</th>
                                <th className="px-3 py-2 text-end">{t('الملتزَم')}</th>
                                <th className="px-3 py-2 text-end">{t('المساهمة')}</th>
                                <th className="px-3 py-2 text-end">{t('الهامش')}</th>
                                <th className="px-3 py-2 text-center">{t('الاكتمال')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {list.rows.length === 0 && <tr><td colSpan={12} className="px-3 py-10 text-center text-[#9ca3af]">{t('لا مشاريعَ في المدّة')}</td></tr>}
                            {list.rows.map((r) => (
                                <tr key={r.project.id} className="border-t border-[#f4f4f5]">
                                    <td className="px-3 py-2"><a href={route('admin.projects.show', r.project.id)} className="font-mono text-[12px] text-[#1d4ed8]">{r.project.code}</a><span className="block text-[12px]">{r.project.name}</span></td>
                                    <td className="px-3 py-2 text-[12px] text-[#6b7280]">{r.project.customer ?? '—'}</td>
                                    <td className="px-3 py-2 text-center"><span className={'rounded-full px-2 py-0.5 text-[11px] ' + projectTone(r.project.status)}>{t(PROJECT_STATUS_LABEL[r.project.status] ?? r.project.status)}</span></td>
                                    <td className="px-3 py-2 text-end tabular-nums" dir="ltr">{m(r.contracted)}</td>
                                    <td className="px-3 py-2 text-end tabular-nums" dir="ltr">{m(r.revenue)}{r.invoiced_above_approved && <span className="ms-1 text-[10px] text-[#b91c1c]">{t('فوق المعتمَد')}</span>}</td>
                                    <td className="px-3 py-2 text-end tabular-nums" dir="ltr">{m(r.collected)}</td>
                                    <td className="px-3 py-2 text-end tabular-nums" dir="ltr">{m(r.receivable)}</td>
                                    <td className="px-3 py-2 text-end tabular-nums" dir="ltr" title={`${t('سندات')} ${m(r.direct_costs.supplier_invoices)} · ${t('مصروفات')} ${m(r.direct_costs.expenses)} · ${t('مستهلَكات')} ${m(r.direct_costs.stock_consumed)}`}>{m(r.direct_costs.total)}</td>
                                    <td className="px-3 py-2 text-end tabular-nums text-[#6b7280]" dir="ltr">{m(r.committed)}</td>
                                    <td className={'px-3 py-2 text-end tabular-nums font-semibold ' + (r.contribution < 0 ? 'text-[#b91c1c]' : '')} dir="ltr">{m(r.contribution)}</td>
                                    <td className={'px-3 py-2 text-end tabular-nums ' + marginTone(r.margin_percent)} dir="ltr">{pct(r.margin_percent)}</td>
                                    <td className="px-3 py-2 text-center"><CompletenessBadge completeness={r.completeness} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <p className="border-t border-[#f4f4f5] px-3 py-2 text-[11px] text-[#9ca3af]">{t('الهامشُ الجزئيّ محسوبٌ على التكاليف المخصَّصة حاليًّا — مرّر على شارة الاكتمال لتقرأ ما ينقص')}</p>
            </Card>
        </AdminLayout>
    );
}
