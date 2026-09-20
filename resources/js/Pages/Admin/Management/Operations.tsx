import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { MANAGEMENT_TABS } from '@/Components/SectionTabs';
import BarChart from '@/Components/charts/BarChart';
import { Card } from '@/Components/ui/card';
import CompletenessBadge from '@/Components/management/CompletenessBadge';
import Kpi from '@/Components/management/Kpi';
import PeriodPicker from '@/Components/management/PeriodPicker';
import { useTranslate } from '@/lib/i18n';
import { label, operationTone, type Labels } from '@/lib/operations';
import { pct, type CompletenessInfo, type PeriodInfo, type PeriodOption } from '@/lib/management';

interface OpRow {
    id: number;
    code: string;
    name: string;
    status: string;
    starts_at: string | null;
    incidents: number;
    severe_incidents: number;
    open_incidents: number;
    conflicts_critical: number;
    conflicts_warning: number;
    readiness_overridden: boolean;
    went_live_delay_minutes: number | null;
    url: string;
}

interface AssetRow {
    asset_id: number;
    code: string;
    name: string;
    category: string;
    category_label: string;
    status: string;
    reserved_days: number;
    deployed_days: number;
    events: number;
    utilization_percent: number;
    out_of_service_now: boolean;
    url: string;
}

interface CategoryRow {
    category: string;
    label: string;
    assets: number;
    deployed_days: number;
    events: number;
    out_of_service: number;
    idle: number;
    required_in_events: number;
    short_events: number;
    rented_events: number;
    utilization_percent: number | null;
    signal: string | null;
}

interface Props {
    period: PeriodInfo;
    periods: PeriodOption[];
    summary: {
        events: { total: number; completed: number; cancelled: number; went_live: number };
        readiness: { went_live: number; overridden: number; clean_percent: number | null; basis: string };
        incidents: { total: number; severe: number; open: number; per_event: number | null; severe_per_event: number | null; by_category: { category: string; count: number }[] };
        conflicts: { critical: number; warning: number };
        returns: { late: number; still_out: number; average_delay_days: number | null };
        setup: { measured: number; average_delay_minutes: number | null; late_starts: number; basis: string };
        rows: OpRow[];
        monthly: { month: string; events: number; incidents: number; severe: number }[];
    };
    utilization: { assets: number; rows: AssetRow[]; categories: CategoryRow[]; threshold_shortage_events: number; basis: string; completeness: CompletenessInfo };
    labels: Labels;
}

const SIGNAL_LABEL: Record<string, string> = {
    repeated_shortage: 'نقصٌ متكرّر عند الفعاليّات',
    rented_while_idle: 'استُؤجر وعندنا قطعٌ لم تُستخدم',
    rented_no_owned: 'يُستأجر ولا نملك منه',
};

/**
 * ذكاءُ العمليّات — وقائعُ عن الفعاليّات والمعدّات، لا ترتيبٌ للأشخاص.
 *
 * حوادثُ لكلّ فعاليّة، وجاهزيّةٌ بلا تجاوز، وردودٌ متأخّرة، واستخدامُ كلّ قطعة.
 * وإشارةُ الصنف تقول ما تكرّر — ولا تقول «اشترِ».
 */
export default function ManagementOperations({ period, periods, summary: s, utilization: u, labels }: Props) {
    const t = useTranslate();

    return (
        <AdminLayout title="ذكاء العمليّات">
            <PageHeader title="العمليّات والموارد" subtitle={t('فعاليّاتٌ وحوادثُ وجاهزيّةٌ ومعدّاتٌ في المدّة — أرقامٌ عن التنفيذ لا عن الأشخاص')} actions={<PeriodPicker period={period} periods={periods} routeName="admin.management.operations" />} />
            <SectionTabs tabs={MANAGEMENT_TABS} current="admin.management.operations" className="mb-4" />

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
                <Kpi label="فعاليّات" value={String(s.events.total)} sub={<span>{t('أُتمّت')}: {s.events.completed} · {t('أُلغيت')}: {s.events.cancelled}</span>} />
                <Kpi label="بُثّت بلا تجاوز" value={pct(s.readiness.clean_percent)} basis={s.readiness.basis} sub={<span>{s.readiness.overridden} {t('تجاوزًا من')} {s.readiness.went_live}</span>} tone={s.readiness.overridden > 0 ? 'warning' : undefined} />
                <Kpi label="حوادث لكلّ فعاليّة" value={s.incidents.per_event === null ? '—' : String(s.incidents.per_event)} sub={<span>{t('إجمالًا')}: {s.incidents.total} · {t('جسيمة')}: {s.incidents.severe}</span>} tone={s.incidents.severe > 0 ? 'warning' : undefined} />
                <Kpi label="حوادث مفتوحة" value={String(s.incidents.open)} tone={s.incidents.open > 0 ? 'danger' : undefined} />
                <Kpi label="تعارضات حرجة" value={String(s.conflicts.critical)} sub={<span>{t('تحذيرات')}: {s.conflicts.warning}</span>} tone={s.conflicts.critical > 0 ? 'danger' : undefined} />
                <Kpi label="ردود متأخّرة" value={String(s.returns.late)} sub={<span>{t('لم تُردّ بعد')}: {s.returns.still_out}{s.returns.average_delay_days !== null ? ` · ${t('متوسّط')} ${s.returns.average_delay_days} ${t('يومًا')}` : ''}</span>} tone={s.returns.still_out > 0 ? 'warning' : undefined} />
                <Kpi label="تأخّر البداية (دقائق)" value={s.setup.average_delay_minutes === null ? '—' : String(s.setup.average_delay_minutes)} basis={s.setup.basis} sub={<span>{t('قيس')}: {s.setup.measured} · {t('متأخّرة')}: {s.setup.late_starts}</span>} />
                <Kpi label="المعدّات" value={String(u.assets)} sub={<span>{u.categories.filter((c) => c.signal).length} {t('إشارة')}</span>} />
            </div>

            <div className="mb-4 grid gap-4 lg:grid-cols-3">
                <Card className="p-4 lg:col-span-2">
                    <h3 className="mb-2 text-[14px] font-semibold">{t('فعاليّاتٌ وحوادثُ شهرًا بشهر')}</h3>
                    {s.monthly.every((mo) => mo.events === 0 && mo.incidents === 0) ? <p className="text-[12px] text-[#9ca3af]">{t('لا فعاليّاتٍ في المدّة')}</p> : (
                        <>
                            <BarChart labels={s.monthly.map((mo) => mo.month.slice(2))} series={s.monthly.map((mo) => mo.events)} format={(v) => String(v)} />
                            <table className="mt-2 w-full text-[11px]">
                                <thead className="text-[#6b7280]"><tr><th className="py-1 text-start">{t('الشهر')}</th>{s.monthly.map((mo) => <th key={mo.month} className="py-1 text-center" dir="ltr">{mo.month.slice(2)}</th>)}</tr></thead>
                                <tbody>
                                    <tr className="border-t border-[#f4f4f5]"><td className="py-1">{t('فعاليّات')}</td>{s.monthly.map((mo) => <td key={mo.month} className="py-1 text-center tabular-nums">{mo.events}</td>)}</tr>
                                    <tr className="border-t border-[#f4f4f5]"><td className="py-1">{t('حوادث')}</td>{s.monthly.map((mo) => <td key={mo.month} className={'py-1 text-center tabular-nums ' + (mo.severe > 0 ? 'text-[#b91c1c]' : '')}>{mo.incidents}{mo.severe > 0 ? ` (${mo.severe})` : ''}</td>)}</tr>
                                </tbody>
                            </table>
                        </>
                    )}
                </Card>
                <Card className="p-4">
                    <h3 className="mb-2 text-[14px] font-semibold">{t('الحوادث بالتصنيف')}</h3>
                    {s.incidents.by_category.length === 0 ? <p className="text-[12px] text-[#9ca3af]">{t('لا حوادث')}</p> : (
                        <ul className="space-y-1 text-[12px]">
                            {s.incidents.by_category.map((c) => <li key={c.category} className="flex justify-between"><span>{label(labels, 'incident_category', c.category)}</span><span className="tabular-nums">{c.count}</span></li>)}
                        </ul>
                    )}
                </Card>
            </div>

            <Card className="mb-4 overflow-hidden">
                <h3 className="px-4 pt-4 text-[14px] font-semibold">{t('الفعاليّات في المدّة')}</h3>
                <div className="overflow-x-auto">
                    <table className="w-full text-[12px]">
                        <thead className="text-[11px] text-[#6b7280]"><tr><th className="px-3 py-2 text-start">{t('الفعاليّة')}</th><th className="px-3 py-2 text-start">{t('الموعد')}</th><th className="px-3 py-2 text-center">{t('الحالة')}</th><th className="px-3 py-2 text-end">{t('حوادث')}</th><th className="px-3 py-2 text-end">{t('جسيمة')}</th><th className="px-3 py-2 text-end">{t('مفتوحة')}</th><th className="px-3 py-2 text-end">{t('تعارضات')}</th><th className="px-3 py-2 text-center">{t('الجاهزيّة')}</th><th className="px-3 py-2 text-end">{t('تأخّر البثّ')}</th></tr></thead>
                        <tbody>
                            {s.rows.length === 0 && <tr><td colSpan={9} className="px-3 py-8 text-center text-[#9ca3af]">{t('لا فعاليّاتٍ في المدّة')}</td></tr>}
                            {s.rows.map((r) => (
                                <tr key={r.id} className="border-t border-[#f4f4f5]">
                                    <td className="px-3 py-1.5"><a href={r.url} className="font-mono text-[11px] text-[#1d4ed8]">{r.code}</a> <span>{r.name}</span></td>
                                    <td className="px-3 py-1.5" dir="ltr">{r.starts_at ?? '—'}</td>
                                    <td className="px-3 py-1.5 text-center"><span className={'rounded-full px-2 py-0.5 text-[10px] ' + operationTone(r.status)}>{label(labels, 'status', r.status)}</span></td>
                                    <td className="px-3 py-1.5 text-end tabular-nums">{r.incidents}</td>
                                    <td className={'px-3 py-1.5 text-end tabular-nums ' + (r.severe_incidents > 0 ? 'text-[#b91c1c]' : '')}>{r.severe_incidents}</td>
                                    <td className="px-3 py-1.5 text-end tabular-nums">{r.open_incidents}</td>
                                    <td className="px-3 py-1.5 text-end tabular-nums">{r.conflicts_critical > 0 && <span className="text-[#b91c1c]">{r.conflicts_critical} </span>}{r.conflicts_warning > 0 && <span className="text-[#b45309]">{r.conflicts_warning}</span>}{r.conflicts_critical + r.conflicts_warning === 0 && '—'}</td>
                                    <td className="px-3 py-1.5 text-center">{r.readiness_overridden ? <span className="rounded-full bg-[#fffbeb] px-1.5 text-[10px] text-[#b45309]">{t('تجاوز')}</span> : '—'}</td>
                                    <td className="px-3 py-1.5 text-end tabular-nums" dir="ltr">{r.went_live_delay_minutes === null ? '—' : `${r.went_live_delay_minutes} ${t('د')}`}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card className="overflow-hidden">
                    <div className="flex items-center justify-between px-4 pt-4">
                        <h3 className="text-[14px] font-semibold">{t('الأصناف وإشاراتها')}</h3>
                        <CompletenessBadge completeness={u.completeness} />
                    </div>
                    <p className="px-4 pb-2 text-[11px] text-[#9ca3af]">{t('الإشارةُ واقعةٌ تكرّرت (عتبةُ النقص: :n فعاليّات) — والقرارُ للإدارة', { n: String(u.threshold_shortage_events) })}</p>
                    <div className="overflow-x-auto">
                        <table className="w-full text-[12px]">
                            <thead className="text-[11px] text-[#6b7280]"><tr><th className="px-3 py-2 text-start">{t('الصنف')}</th><th className="px-3 py-2 text-end">{t('قطع')}</th><th className="px-3 py-2 text-end">{t('الاستخدام')}</th><th className="px-3 py-2 text-end">{t('لم تُستخدم')}</th><th className="px-3 py-2 text-end">{t('متوقّفة')}</th><th className="px-3 py-2 text-end">{t('طُلب في')}</th><th className="px-3 py-2 text-end">{t('نقص')}</th><th className="px-3 py-2 text-end">{t('استُؤجر')}</th><th className="px-3 py-2 text-start">{t('الإشارة')}</th></tr></thead>
                            <tbody>
                                {u.categories.length === 0 && <tr><td colSpan={9} className="px-3 py-8 text-center text-[#9ca3af]">{t('لا معدّاتٍ مسجَّلة')}</td></tr>}
                                {u.categories.map((c) => (
                                    <tr key={c.category} className="border-t border-[#f4f4f5]">
                                        <td className="px-3 py-1.5 font-medium">{c.label}</td>
                                        <td className="px-3 py-1.5 text-end tabular-nums">{c.assets}</td>
                                        <td className="px-3 py-1.5 text-end tabular-nums" dir="ltr">{pct(c.utilization_percent)}</td>
                                        <td className="px-3 py-1.5 text-end tabular-nums">{c.idle}</td>
                                        <td className="px-3 py-1.5 text-end tabular-nums">{c.out_of_service}</td>
                                        <td className="px-3 py-1.5 text-end tabular-nums">{c.required_in_events}</td>
                                        <td className={'px-3 py-1.5 text-end tabular-nums ' + (c.short_events > 0 ? 'text-[#b91c1c]' : '')}>{c.short_events}</td>
                                        <td className="px-3 py-1.5 text-end tabular-nums">{c.rented_events}</td>
                                        <td className="px-3 py-1.5 text-[11px]">{c.signal ? <span className="rounded-full bg-[#fffbeb] px-1.5 text-[#b45309]">{t(SIGNAL_LABEL[c.signal] ?? c.signal)}</span> : '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
                <Card className="overflow-hidden">
                    <h3 className="px-4 pt-4 text-[14px] font-semibold">{t('القطع الأكثر استخدامًا')}</h3>
                    <p className="px-4 pb-2 text-[11px] text-[#9ca3af]">{u.basis}</p>
                    <div className="max-h-[480px] overflow-auto">
                        <table className="w-full text-[12px]">
                            <thead className="sticky top-0 bg-white text-[11px] text-[#6b7280]"><tr><th className="px-3 py-2 text-start">{t('القطعة')}</th><th className="px-3 py-2 text-start">{t('الصنف')}</th><th className="px-3 py-2 text-end">{t('أيّام الحجز')}</th><th className="px-3 py-2 text-end">{t('أيّام الميدان')}</th><th className="px-3 py-2 text-end">{t('فعاليّات')}</th><th className="px-3 py-2 text-end">{t('الاستخدام')}</th></tr></thead>
                            <tbody>
                                {u.rows.length === 0 && <tr><td colSpan={6} className="px-3 py-8 text-center text-[#9ca3af]">{t('لا معدّاتٍ مسجَّلة')}</td></tr>}
                                {u.rows.slice(0, 60).map((r) => (
                                    <tr key={r.asset_id} className="border-t border-[#f4f4f5]">
                                        <td className="px-3 py-1.5"><a href={r.url} className="font-mono text-[11px] text-[#1d4ed8]">{r.code}</a> {r.name}{r.out_of_service_now && <span className="ms-1 rounded-full bg-[#fef2f2] px-1.5 text-[10px] text-[#b91c1c]">{t('متوقّفة')}</span>}</td>
                                        <td className="px-3 py-1.5 text-[#6b7280]">{r.category_label}</td>
                                        <td className="px-3 py-1.5 text-end tabular-nums">{r.reserved_days}</td>
                                        <td className="px-3 py-1.5 text-end tabular-nums">{r.deployed_days}</td>
                                        <td className="px-3 py-1.5 text-end tabular-nums">{r.events}</td>
                                        <td className="px-3 py-1.5 text-end tabular-nums" dir="ltr">{pct(r.utilization_percent)}</td>
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
