import { usePage } from '@inertiajs/react';
import { Briefcase, CalendarCheck, FileSignature } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { MANAGEMENT_TABS } from '@/Components/SectionTabs';
import AreaChart from '@/Components/charts/AreaChart';
import { Card } from '@/Components/ui/card';
import AlertList from '@/Components/management/AlertList';
import CompletenessBadge from '@/Components/management/CompletenessBadge';
import Kpi from '@/Components/management/Kpi';
import PeriodPicker from '@/Components/management/PeriodPicker';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { marginTone, pct, RUNWAY_LABEL, type Alert, type Change, type CompletenessInfo, type Currency, type PeriodInfo, type PeriodOption } from '@/lib/management';
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
    previous?: { period: PeriodInfo; change: Record<string, Change> };
}

interface Overview {
    period: PeriodInfo;
    currency: Currency;
    generated_at: string;
    activity: Record<string, number>;
    may: { financial: boolean; projects: boolean; operations: boolean };
    financial?: Snapshot;
    cash?: { cash: { liquid: number; cash: number; bank: number; cheques_under_collection: number; basis: string }; receivables: { outstanding: number; overdue: number; basis: string }; payables: { total: number; open_purchase_orders: number } };
    trend?: { month: string; revenue: number; collections: number; expenses: number; direct_costs: number; projects: number }[];
    funnel?: { stages: { key: string; label: string; count: number; value: number | null; committed: boolean }[]; acceptance_rate: number | null; acceptance_basis: string };
    ladder?: Record<string, { value: number; basis: string; overdue?: number }>;
    forecast?: { id: number; name: string; horizon_days: number; closing: number; lowest_closing: number; completeness: CompletenessInfo | null } | null;
    budget?: { name: string; version: number; ytd: { revenue: { planned: number; actual: number }; expense: { planned: number; actual: number } }; basis: string } | null;
    runway?: { status: string; runway_months?: number; average_net?: number; reason?: string; definition: string };
    alerts?: Alert[];
    projects?: { totals: { projects: number; revenue: number; contribution: number; margin_percent: number | null; partial: number; negative: number }; weakest: ProjectRow[]; strongest: ProjectRow[] };
    operations?: { id: number; code: string; name: string; status: string; starts_at: string | null; lead: string | null; project: string | null; url: string }[];
}

interface ProjectRow {
    project: { id: number; code: string; name: string };
    revenue: number;
    contribution: number;
    margin_percent: number | null;
    completeness: CompletenessInfo;
}

interface Props {
    overview: Overview;
    period: PeriodInfo;
    periods: PeriodOption[];
    may: Record<string, boolean>;
}

/**
 * نظرةٌ عامّة — «كيف تؤدّي الشركة؟» تُقرأ في أقلَّ من نصف دقيقة.
 *
 * ستّةُ أرقامٍ أوّلًا، ثمّ الاتّجاهُ والنقدُ والموازنةُ والتنبيهاتُ والمشاريعُ
 * والقمعُ والفعاليّاتُ القادمة. وكلُّ رقمٍ يحمل أساسَه بالمرور، ويقود إلى
 * مصدره. ولا يُرسَل مالٌ لمن لا يملك رؤيته.
 */
export default function ManagementOverview({ overview: o, period, periods }: Props) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const m = (v: number | null | undefined) => (v === null || v === undefined ? '—' : money(v, context!.currency));
    const f = o.financial;

    return (
        <AdminLayout title="الإدارة">
            <PageHeader title="الإدارة" subtitle={t('كيف تؤدّي الشركة — أرقامٌ بأساسها ومدّتها، لا قراراتٌ')} actions={<PeriodPicker period={period} periods={periods} routeName="admin.management.index" />} />
            <SectionTabs tabs={MANAGEMENT_TABS} current="admin.management.index" className="mb-4" />

            {f && o.cash && (
                <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <Kpi label="الإيراد الصافي" value={m(f.revenue.net)} basis={f.basis.revenue} change={f.previous?.change.revenue} href={route('admin.management.financial', { period: period.key })} />
                    <Kpi label="التحصيل" value={m(f.collections.total)} basis={f.basis.collections} change={f.previous?.change.collections} href={route('admin.finance.receivables')} />
                    <Kpi label="المصروفات والتكلفة" value={m(f.expenses.total + f.direct_costs.total)} basis={f.basis.direct_costs + ' · ' + f.basis.expenses} change={f.previous?.change.expenses} invert href={route('admin.management.financial', { period: period.key })} />
                    <Kpi label="الذمم القائمة" value={m(o.cash.receivables.outstanding)} basis={o.cash.receivables.basis} tone={o.cash.receivables.overdue > 0 ? 'warning' : undefined} sub={<span>{t('متأخّر')}: <span dir="ltr">{m(o.cash.receivables.overdue)}</span></span>} href={route('admin.finance.receivables')} />
                    <Kpi label="النقد السائل" value={m(o.cash.cash.liquid)} basis={o.cash.cash.basis} sub={o.cash.cash.cheques_under_collection > 0 ? <span>{t('شيكات تحت التحصيل')}: <span dir="ltr">{m(o.cash.cash.cheques_under_collection)}</span></span> : undefined} href={route('admin.management.cash')} />
                    <Kpi label="مشاريع قائمة" value={number(o.activity.active_projects)} href={route('admin.projects.index')} sub={<span>{t('فاز بها في المدّة')}: {o.activity.projects_won}</span>} />
                </div>
            )}

            {!f && (
                <Card className="mb-4 p-4 text-[13px] text-[#6b7280]">{t('الأرقامُ الماليّة تُعرض لمن يملك «قراءة الأداء المالي» — وما هنا الحركةُ وحدَها.')}</Card>
            )}

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
                <Mini icon={FileSignature} label="عروض تنتظر" value={o.activity.quotations_pending} />
                <Mini icon={FileSignature} label="عروض قُبلت" value={o.activity.quotations_accepted} />
                <Mini icon={FileSignature} label="عروض رُفضت" value={o.activity.quotations_rejected} />
                <Mini icon={FileSignature} label="عروض انتهت" value={o.activity.quotations_expired} />
                <Mini icon={Briefcase} label="مشاريع أُقفلت" value={o.activity.projects_completed} />
                <Mini icon={CalendarCheck} label="فعاليّات خلال شهر" value={o.activity.upcoming_operations} />
                {f && <Mini icon={Briefcase} label="هامش المساهمة" value={pct(f.gross_margin_percent)} raw />}
                {f && <Mini icon={Briefcase} label="النتيجة التشغيلية" value={m(f.operating_result)} raw tone={f.operating_result < 0 ? 'danger' : undefined} />}
            </div>

            {f && o.trend && (
                <div className="mb-4 grid gap-4 lg:grid-cols-3">
                    <Card className="p-4 lg:col-span-2">
                        <h3 className="mb-1 text-[14px] font-semibold">{t('الاتّجاه — آخر 12 شهرًا')}</h3>
                        <p className="mb-2 text-[11px] text-[#9ca3af]">{t('الإيرادُ الصافي شهرًا بشهر — والجدولُ تحته بالأرقام')}</p>
                        <AreaChart labels={o.trend.map((r) => r.month.slice(5))} fullLabels={o.trend.map((r) => r.month)} data={o.trend.map((r) => r.revenue)} counts={o.trend.map((r) => r.projects)} format={(v) => m(v)} height={180} />
                        <details className="mt-2 text-[12px]">
                            <summary className="cursor-pointer text-[#6b7280]">{t('الأرقام')}</summary>
                            <table className="mt-2 w-full text-[12px]">
                                <thead className="text-[#6b7280]"><tr><th className="py-1 text-start">{t('الشهر')}</th><th className="py-1 text-end">{t('الإيراد')}</th><th className="py-1 text-end">{t('التحصيل')}</th><th className="py-1 text-end">{t('المصروفات')}</th><th className="py-1 text-end">{t('التكلفة المباشرة')}</th><th className="py-1 text-end">{t('مشاريع')}</th></tr></thead>
                                <tbody>{o.trend.map((r) => <tr key={r.month} className="border-t border-[#f4f4f5]"><td className="py-1" dir="ltr">{r.month}</td><td className="py-1 text-end tabular-nums" dir="ltr">{m(r.revenue)}</td><td className="py-1 text-end tabular-nums" dir="ltr">{m(r.collections)}</td><td className="py-1 text-end tabular-nums" dir="ltr">{m(r.expenses)}</td><td className="py-1 text-end tabular-nums" dir="ltr">{m(r.direct_costs)}</td><td className="py-1 text-end tabular-nums">{r.projects}</td></tr>)}</tbody>
                            </table>
                        </details>
                    </Card>
                    <div className="space-y-4">
                        <Card className="p-4">
                            <h3 className="mb-2 text-[14px] font-semibold">{t('النظرة النقدية')}</h3>
                            {o.forecast ? (
                                <dl className="space-y-1 text-[13px]">
                                    <Row label={t('التوقّع المعتمَد')} value={`${o.forecast.name} · ${o.forecast.horizon_days} ${t('يومًا')}`} />
                                    <Row label={t('الإقفال المتوقَّع')} value={m(o.forecast.closing)} ltr />
                                    <Row label={t('أدنى إقفال')} value={m(o.forecast.lowest_closing)} ltr tone={o.forecast.lowest_closing < 0 ? 'danger' : undefined} />
                                    {o.forecast.completeness && <div className="pt-1"><CompletenessBadge completeness={o.forecast.completeness} /></div>}
                                </dl>
                            ) : (
                                <p className="text-[12px] text-[#9ca3af]">{t('لا توقّعَ نقديًّا معتمَدًا — أنشئ واحدًا من «النقد والتوقّع»')}</p>
                            )}
                            {o.runway && (
                                <p className="mt-2 text-[12px] text-[#6b7280]">
                                    {t('المدرج')}: {t(RUNWAY_LABEL[o.runway.status] ?? o.runway.status)}
                                    {o.runway.status === 'ok' && ` — ${o.runway.runway_months} ${t('شهرًا')}`}
                                    {o.runway.reason && <span className="block text-[11px] text-[#9ca3af]">{o.runway.reason}</span>}
                                </p>
                            )}
                        </Card>
                        <Card className="p-4">
                            <h3 className="mb-2 text-[14px] font-semibold">{t('الموازنة')}</h3>
                            {o.budget ? (
                                <dl className="space-y-1 text-[13px]">
                                    <Row label={t('المعتمَدة')} value={`${o.budget.name} v${o.budget.version}`} />
                                    <Row label={t('الإيراد — الخطّة / الفعلي')} value={`${m(o.budget.ytd.revenue.planned)} / ${m(o.budget.ytd.revenue.actual)}`} ltr />
                                    <Row label={t('المصروف — الخطّة / الفعلي')} value={`${m(o.budget.ytd.expense.planned)} / ${m(o.budget.ytd.expense.actual)}`} ltr />
                                    <div className="text-[11px] text-[#9ca3af]">{o.budget.basis}</div>
                                </dl>
                            ) : (
                                <p className="text-[12px] text-[#9ca3af]">{t('لا موازنةَ معتمَدة لهذه السنة')}</p>
                            )}
                        </Card>
                    </div>
                </div>
            )}

            <div className="grid gap-4 lg:grid-cols-2">
                {f && o.alerts && (
                    <Card className="p-4">
                        <h3 className="mb-2 text-[14px] font-semibold">{t('التنبيهات الإدارية')} <span className="text-[11px] font-normal text-[#9ca3af]">({o.alerts.length})</span></h3>
                        <AlertList alerts={o.alerts.slice(0, 8)} />
                        {o.alerts.length > 8 && <a href={route('admin.management.financial')} className="text-[12px] text-[#1d4ed8]">{t('و:n أخرى', { n: String(o.alerts.length - 8) })}</a>}
                    </Card>
                )}
                {o.projects && (
                    <Card className="p-4">
                        <h3 className="mb-1 text-[14px] font-semibold">{t('أداء المشاريع')}</h3>
                        <p className="mb-2 text-[11px] text-[#9ca3af]">{t(':n مشروعًا · :p جزئيّ التكلفة · :neg بمساهمة سالبة', { n: String(o.projects.totals.projects), p: String(o.projects.totals.partial), neg: String(o.projects.totals.negative) })}</p>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <ProjectMini title="الأضعف هامشًا" rows={o.projects.weakest} m={m} />
                            <ProjectMini title="الأقوى هامشًا" rows={o.projects.strongest} m={m} />
                        </div>
                        <a href={route('admin.management.projects')} className="mt-2 inline-block text-[12px] text-[#1d4ed8]">{t('كلّ المشاريع')}</a>
                    </Card>
                )}
                {f && o.funnel && (
                    <Card className="p-4">
                        <h3 className="mb-1 text-[14px] font-semibold">{t('القمع التجاري')}</h3>
                        <p className="mb-2 text-[11px] text-[#9ca3af]">{t('نسبة القبول')}: {pct(o.funnel.acceptance_rate)} — {o.funnel.acceptance_basis}</p>
                        <ul className="space-y-1 text-[13px]">
                            {o.funnel.stages.filter((s) => s.count > 0).map((s) => (
                                <li key={s.key} className="flex items-center justify-between gap-3">
                                    <span>{s.label}{s.committed && <span className="ms-1 rounded-full bg-[#ecfdf5] px-1.5 text-[10px] text-[#047857]">{t('ملتزَم')}</span>}</span>
                                    <span className="tabular-nums text-[#6b7280]" dir="ltr">{s.count} · {s.value === null ? '—' : m(s.value)}</span>
                                </li>
                            ))}
                            {o.funnel.stages.every((s) => s.count === 0) && <li className="text-[#9ca3af]">{t('لا عروضَ في المدّة')}</li>}
                        </ul>
                    </Card>
                )}
                {o.operations && (
                    <Card className="p-4">
                        <h3 className="mb-2 text-[14px] font-semibold">{t('فعاليّات خلال 30 يومًا')}</h3>
                        {o.operations.length === 0 && <p className="text-[12px] text-[#9ca3af]">{t('لا فعاليّات قادمة')}</p>}
                        <ul className="divide-y divide-[#f3f4f6] text-[13px]">
                            {o.operations.map((op) => (
                                <li key={op.id} className="flex items-center justify-between gap-3 py-1.5">
                                    <a href={op.url} className="font-medium hover:text-[#1d4ed8]">{op.code} — {op.name}</a>
                                    <span className="text-[11px] text-[#9ca3af]" dir="ltr">{op.starts_at}</span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
            </div>
            <p className="mt-4 text-[11px] text-[#9ca3af]">{t('آخر قراءة')}: <span dir="ltr">{o.generated_at}</span> · {t('العملة')}: {String(o.currency.code)}</p>
        </AdminLayout>
    );
}

function Mini({ icon: Icon, label, value, raw, tone }: { icon: typeof Briefcase; label: string; value: number | string; raw?: boolean; tone?: 'danger' }) {
    const t = useTranslate();

    return (
        <div className="flex items-center gap-2 rounded-lg border border-[#f0f0f0] bg-white px-3 py-2">
            <Icon className="size-4 shrink-0 text-[#9ca3af]" />
            <span className="min-w-0">
                <span className={'block text-[16px] font-semibold leading-none tabular-nums ' + (tone === 'danger' ? 'text-[#b91c1c]' : '')} dir="ltr">{raw ? value : number(value as number)}</span>
                <span className="block truncate text-[11px] text-[#6b7280]">{t(label)}</span>
            </span>
        </div>
    );
}

function ProjectMini({ title, rows, m }: { title: string; rows: ProjectRow[]; m: (v: number | null | undefined) => string }) {
    const t = useTranslate();

    return (
        <div>
            <div className="mb-1 text-[12px] font-medium text-[#6b7280]">{t(title)}</div>
            {rows.length === 0 && <div className="text-[12px] text-[#9ca3af]">{t('لا بيانات')}</div>}
            <ul className="space-y-1 text-[12px]">
                {rows.map((r) => (
                    <li key={r.project.id} className="flex items-center justify-between gap-2">
                        <a href={route('admin.projects.show', r.project.id)} className="min-w-0 truncate hover:text-[#1d4ed8]">{r.project.code}</a>
                        <span className="flex shrink-0 items-center gap-1">
                            <span className={'tabular-nums ' + marginTone(r.margin_percent)} dir="ltr">{pct(r.margin_percent)}</span>
                            <span className="text-[#9ca3af]" dir="ltr">{m(r.contribution)}</span>
                            <CompletenessBadge completeness={r.completeness} />
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function Row({ label, value, ltr, tone }: { label: string; value: string; ltr?: boolean; tone?: 'danger' }) {
    return (
        <div className="flex justify-between gap-3">
            <dt className="text-[#6b7280]">{label}</dt>
            <dd className={(ltr ? 'tabular-nums ' : '') + (tone === 'danger' ? 'text-[#b91c1c]' : '')} dir={ltr ? 'ltr' : undefined}>{value}</dd>
        </div>
    );
}
