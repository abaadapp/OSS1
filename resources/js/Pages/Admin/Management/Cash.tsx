import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Check, Lock, Plus, Trash2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { MANAGEMENT_TABS } from '@/Components/SectionTabs';
import AreaChart from '@/Components/charts/AreaChart';
import Field, { Select } from '@/Components/Field';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import CompletenessBadge from '@/Components/management/CompletenessBadge';
import Kpi from '@/Components/management/Kpi';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { CATEGORY_LABEL, categoryTone, FORECAST_STATUS_LABEL, RUNWAY_LABEL, type CompletenessInfo } from '@/lib/management';
import type { PageProps } from '@/types';

interface Item {
    at: string;
    amount: number;
    direction: 'in' | 'out';
    category: string;
    label: string;
    source: { type: string; ref?: string | null; url?: string | null; id?: number };
}

interface Month {
    month: string;
    opening: number;
    inflows: Record<string, number>;
    outflows: Record<string, number>;
    net: number;
    net_with_scenarios: number;
    closing: number;
    closing_with_scenarios: number;
    items: Item[];
    more_items: number;
}

interface Projection {
    base_date: string;
    horizon_days: number;
    end_date: string;
    opening_cash: number;
    months: Month[];
    totals: { inflows: Record<string, number>; outflows: Record<string, number>; net: number; closing: number; closing_with_scenarios: number; lowest_closing: number; items: number };
    assumptions: Record<string, string | number | boolean>;
    scenario: { id: number; name: string; kind: string } | null;
    completeness: CompletenessInfo;
    basis: string;
    generated_at: string;
}

interface Assumption {
    id?: number;
    key: string;
    key_label?: string;
    label: string;
    amount: number | null;
    percent: number | null;
    starts_on: string | null;
    months: number | null;
    notes: string | null;
}

interface Scenario {
    id: number;
    name: string;
    kind: string;
    kind_label: string;
    description: string | null;
    active: boolean;
    assumptions: Assumption[];
}

interface ForecastRow {
    id: number;
    name: string;
    status: string;
    horizon_days: number;
    base_date: string;
    opening_cash: number;
    closing: number;
    lowest_closing: number;
    created_by: string | null;
    approved_by: string | null;
    approved_at: string | null;
    created_at: string | null;
    scenario: { name: string } | null;
}

interface Props {
    current: { cash: { liquid: number; cash: number; bank: number; cheques_under_collection: number; basis: string; bank_accounts: { id: number; name: string; balance: number }[] }; receivables: { outstanding: number; overdue: number; basis: string }; payables: { supplier_invoices: number; expenses_unpaid: number; total: number; open_purchase_orders: number }; net_position: number; basis: Record<string, string> };
    runway: { status: string; runway_months?: number; average_net?: number; liquid: number; reason?: string; definition: string; months?: { month: string; inflows: number; outflows: number; net: number }[] };
    horizon: number;
    horizons: number[];
    defaults: Record<string, string | number | boolean>;
    projection: Projection | null;
    scenarios: Scenario[];
    scenario_id: number | null;
    assumption_keys: { key: string; label: string; percent: boolean }[];
    forecasts: ForecastRow[];
    selected: { id: number; name: string; status: string; assumptions: Record<string, string | number | boolean>; projection: { months: Month[]; completeness: CompletenessInfo; basis: string; scenario: { name: string } | null }; totals: Projection['totals']; opening_cash: number; versus_actual: VersusActual } | null;
    may: Record<string, boolean>;
}

interface VersusActual {
    rows: { month: string; status: 'pending' | 'elapsed'; forecast: { inflows: number; outflows: number; net: number; closing: number }; actual?: { inflows: number; outflows: number; net: number; closing: number }; variance?: { inflows: number; outflows: number; net: number; closing: number } }[];
    elapsed_months: number;
    accuracy: { status: string; reason?: string; inflows_forecast?: number; inflows_actual?: number; outflows_forecast?: number; outflows_actual?: number };
    basis: string;
}

/**
 * النقدُ والتوقّعُ والسيناريوهات — شاشةٌ واحدة.
 *
 * الآنَ من الدفتر؛ والإسقاطُ حيٌّ بافتراضاتٍ تُكتب هنا وتُغيَّر، ويُحفظ لقطةً
 * تُعتمد فتُجمَّد لتُقارَن بالواقع. والسيناريو محاكاةٌ تُعرض جنبَ الرقم ولا
 * تدخل الإقفال المعتمَد. ولا رقمَ بلا مصدر.
 */
export default function ManagementCash(p: Props) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const [ask, dialog] = useConfirm();
    const m = (v: number | null | undefined) => (v === null || v === undefined ? '—' : money(v, context!.currency));
    const proj = p.projection;

    const [assumptions, setAssumptions] = useState<Record<string, string | number | boolean>>(proj?.assumptions ?? p.defaults);
    const [saving, setSaving] = useState(false);
    const [name, setName] = useState('');
    const [editingScenario, setEditingScenario] = useState<{ id: number | null; name: string; description: string; assumptions: Assumption[] } | null>(null);
    const [tab, setTab] = useState<'live' | 'saved'>('live');

    const recompute = (patch: Record<string, string | number | boolean> = {}, horizon = p.horizon, scenario = p.scenario_id) => {
        const next = { ...assumptions, ...patch };
        setAssumptions(next);
        router.get(route('admin.management.cash'), { horizon, scenario: scenario ?? undefined, assumptions: next }, { preserveState: true, replace: true, preserveScroll: true });
    };

    const num = (k: string) => String(assumptions[k] ?? '');

    return (
        <AdminLayout title="النقد والتوقّع">
            <PageHeader title="النقد والتوقّع" subtitle={t('الآنَ من الدفتر — والغدُ إسقاطٌ بافتراضاتٍ مكتوبة وسيناريوهاتٍ لا تمسّ قيدًا')} />
            <SectionTabs tabs={MANAGEMENT_TABS} current="admin.management.cash" className="mb-4" />

            {(['forecast', 'scenario'] as const).map((k) => errors?.[k] && <div key={k} className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors[k]}</div>)}

            {/* ─ الآن ─ */}
            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <Kpi label="النقد السائل" value={m(p.current.cash.liquid)} basis={p.current.cash.basis} sub={<span>{t('صندوق')}: <span dir="ltr">{m(p.current.cash.cash)}</span> · {t('بنك')}: <span dir="ltr">{m(p.current.cash.bank)}</span></span>} />
                <Kpi label="شيكات تحت التحصيل" value={m(p.current.cash.cheques_under_collection)} basis={t('أوراقٌ قُبضت ولم تصل البنك — ليست نقدًا')} />
                <Kpi label="الذمم المدينة" value={m(p.current.receivables.outstanding)} basis={p.current.receivables.basis} sub={<span>{t('متأخّر')}: <span dir="ltr">{m(p.current.receivables.overdue)}</span></span>} href={route('admin.finance.receivables')} />
                <Kpi label="المستحقّ علينا" value={m(p.current.payables.total)} basis={t('سنداتُ مورّدين معتمَدة لم تُسدَّد ومصروفاتٌ غير مدفوعة')} invert sub={<span>{t('أوامر شراء مفتوحة')}: <span dir="ltr">{m(p.current.payables.open_purchase_orders)}</span></span>} />
                <Kpi label="الموقف الصافي" value={m(p.current.net_position)} basis={p.current.basis.net_position} tone={p.current.net_position < 0 ? 'danger' : undefined} />
                <Kpi
                    label="المدرج"
                    value={p.runway.status === 'ok' ? `${p.runway.runway_months} ${t('شهرًا')}` : t(RUNWAY_LABEL[p.runway.status] ?? p.runway.status)}
                    basis={p.runway.definition}
                    sub={p.runway.reason ? <span>{p.runway.reason}</span> : p.runway.average_net !== undefined ? <span>{t('متوسّط الصافي الشهري')}: <span dir="ltr">{m(p.runway.average_net)}</span></span> : undefined}
                />
            </div>

            <div className="mb-4 flex gap-2">
                <Button variant={tab === 'live' ? 'primary' : 'outline'} onClick={() => setTab('live')}>{t('إسقاط حيّ')}</Button>
                <Button variant={tab === 'saved' ? 'primary' : 'outline'} onClick={() => setTab('saved')}>{t('التوقّعات المحفوظة')} ({p.forecasts.length})</Button>
            </div>

            {tab === 'live' && proj && (
                <div className="grid gap-4 lg:grid-cols-4">
                    <Card className="p-4">
                        <h3 className="mb-2 text-[14px] font-semibold">{t('الافتراضات')}</h3>
                        <div className="space-y-2">
                            <Field label="الأفق"><Select value={String(p.horizon)} onChange={(e) => recompute({}, Number(e.target.value))} options={p.horizons.map((h) => ({ value: h, label: `${h} ${t('يومًا')}` }))} /></Field>
                            <Field label="السيناريو"><Select value={p.scenario_id ? String(p.scenario_id) : ''} placeholder="بلا سيناريو" onChange={(e) => recompute({}, p.horizon, e.target.value ? Number(e.target.value) : null)} options={p.scenarios.filter((s) => s.active).map((s) => ({ value: s.id, label: `${s.name} (${s.kind_label})` }))} /></Field>
                            <Field label="تأخّر التحصيل (أيّام)" hint="بعد تاريخ الاستحقاق"><Input type="number" min={0} value={num('collection_delay_days')} onChange={(e) => setAssumptions({ ...assumptions, collection_delay_days: e.target.value })} onBlur={() => recompute()} dir="ltr" /></Field>
                            <Field label="تحصيل المتأخّر خلال (أيّام)"><Input type="number" min={0} value={num('overdue_collection_days')} onChange={(e) => setAssumptions({ ...assumptions, overdue_collection_days: e.target.value })} onBlur={() => recompute()} dir="ltr" /></Field>
                            <Field label="مصروفات متكرّرة شهريًّا" hint={String(proj.assumptions.recurring_source ?? '')}><Input type="number" step="0.001" min={0} value={num('recurring_monthly_expenses')} onChange={(e) => setAssumptions({ ...assumptions, recurring_monthly_expenses: e.target.value })} onBlur={() => recompute()} dir="ltr" /></Field>
                            <Field label="رواتب شهريًّا" hint={String(proj.assumptions.payroll_source ?? '')}><Input type="number" step="0.001" min={0} value={num('payroll_monthly')} onChange={(e) => setAssumptions({ ...assumptions, payroll_monthly: e.target.value })} onBlur={() => recompute()} dir="ltr" /></Field>
                            <Field label="نموّ المصروفات شهريًّا (٪)"><Input type="number" step="0.1" value={num('expense_growth_percent')} onChange={(e) => setAssumptions({ ...assumptions, expense_growth_percent: e.target.value })} onBlur={() => recompute()} dir="ltr" /></Field>
                            <label className="flex items-center gap-2 text-[13px]"><input type="checkbox" checked={Boolean(assumptions.include_budget_expenses)} onChange={(e) => recompute({ include_budget_expenses: e.target.checked })} />{t('مصروفات الموازنة المعتمَدة بدل المتوسّط')}</label>
                            <label className="flex items-center gap-2 text-[13px]"><input type="checkbox" checked={Boolean(assumptions.include_pipeline)} onChange={(e) => recompute({ include_pipeline: e.target.checked })} />{t('ضمّ خطّ الأنابيب (عروضٌ مُرسَلة) — منفصلًا')}</label>
                            {Boolean(assumptions.include_pipeline) && <Field label="نسبة تحوّل خطّ الأنابيب (٪)"><Input type="number" min={0} max={100} value={num('pipeline_conversion_percent')} onChange={(e) => setAssumptions({ ...assumptions, pipeline_conversion_percent: e.target.value })} onBlur={() => recompute()} dir="ltr" /></Field>}
                        </div>
                        {p.may.forecasts_manage && (
                            <div className="mt-3 border-t border-[#f4f4f5] pt-3">
                                <Field label="اسم التوقّع" hint="يُحفظ لقطةً مسودّةً ثمّ يُعتمد فيُجمَّد"><Input value={name} onChange={(e) => setName(e.target.value)} /></Field>
                                <Button className="mt-2 w-full" disabled={saving} onClick={() => { setSaving(true); router.post(route('admin.management.forecasts.store'), { name, horizon_days: p.horizon, scenario_id: p.scenario_id, assumptions }, { onFinish: () => setSaving(false) }); }}>{t('حفظ التوقّع')}</Button>
                            </div>
                        )}
                    </Card>

                    <div className="space-y-4 lg:col-span-3">
                        <Card className="p-4">
                            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                <h3 className="text-[14px] font-semibold">{t('الإسقاط — :n يومًا من :d', { n: String(proj.horizon_days), d: proj.base_date })}</h3>
                                <CompletenessBadge completeness={proj.completeness} />
                            </div>
                            <div className="mb-3 grid grid-cols-2 gap-2 md:grid-cols-5">
                                <Kpi label="الافتتاحي (فعلي)" value={m(proj.opening_cash)} />
                                <Kpi label="داخل ملتزَم + متوقَّع" value={m(proj.totals.inflows.total)} sub={<span>{t('ملتزَم')}: <span dir="ltr">{m(proj.totals.inflows.committed)}</span></span>} />
                                <Kpi label="خارج ملتزَم + متوقَّع" value={m(proj.totals.outflows.total)} invert sub={<span>{t('ملتزَم')}: <span dir="ltr">{m(proj.totals.outflows.committed)}</span></span>} />
                                <Kpi label="الإقفال المتوقَّع" value={m(proj.totals.closing)} tone={proj.totals.closing < 0 ? 'danger' : undefined} sub={<span>{t('أدنى إقفال')}: <span dir="ltr">{m(proj.totals.lowest_closing)}</span></span>} />
                                <Kpi label="مع السيناريو وخطّ الأنابيب" value={m(proj.totals.closing_with_scenarios)} basis={t('يضمّ بنود السيناريو وخطّ الأنابيب — محاكاةٌ لا التزام')} />
                            </div>
                            <AreaChart labels={proj.months.map((mo) => mo.month.slice(5))} fullLabels={proj.months.map((mo) => mo.month)} data={proj.months.map((mo) => mo.closing)} format={(v) => m(v)} height={150} />
                            <div className="mt-2 overflow-x-auto">
                                <table className="w-full text-[12px]">
                                    <thead className="text-[#6b7280]"><tr><th className="py-1 text-start">{t('الشهر')}</th><th className="py-1 text-end">{t('الافتتاحي')}</th><th className="py-1 text-end">{t('داخل ملتزَم')}</th><th className="py-1 text-end">{t('داخل متوقَّع')}</th><th className="py-1 text-end">{t('خارج ملتزَم')}</th><th className="py-1 text-end">{t('خارج متوقَّع')}</th><th className="py-1 text-end">{t('الصافي')}</th><th className="py-1 text-end">{t('الإقفال')}</th><th className="py-1 text-end">{t('مع السيناريو')}</th></tr></thead>
                                    <tbody>{proj.months.map((mo) => (
                                        <tr key={mo.month} className="border-t border-[#f4f4f5]">
                                            <td className="py-1" dir="ltr">{mo.month}</td><td className="py-1 text-end tabular-nums" dir="ltr">{m(mo.opening)}</td><td className="py-1 text-end tabular-nums" dir="ltr">{m(mo.inflows.committed)}</td><td className="py-1 text-end tabular-nums" dir="ltr">{m(mo.inflows.expected)}</td><td className="py-1 text-end tabular-nums" dir="ltr">{m(mo.outflows.committed)}</td><td className="py-1 text-end tabular-nums" dir="ltr">{m(mo.outflows.expected)}</td><td className="py-1 text-end tabular-nums" dir="ltr">{m(mo.net)}</td><td className={'py-1 text-end tabular-nums font-semibold ' + (mo.closing < 0 ? 'text-[#b91c1c]' : '')} dir="ltr">{m(mo.closing)}</td><td className="py-1 text-end tabular-nums text-[#6b7280]" dir="ltr">{m(mo.closing_with_scenarios)}</td>
                                        </tr>
                                    ))}</tbody>
                                </table>
                            </div>
                            <p className="mt-2 text-[11px] text-[#9ca3af]">{proj.basis}</p>
                        </Card>

                        <Card className="p-4">
                            <h3 className="mb-2 text-[14px] font-semibold">{t('البنود بمصادرها')} <span className="text-[11px] font-normal text-[#9ca3af]">({proj.totals.items})</span></h3>
                            <div className="max-h-[420px] overflow-y-auto">
                                {proj.months.map((mo) => (
                                    <div key={mo.month} className="mb-3">
                                        <div className="mb-1 text-[12px] font-semibold text-[#6b7280]" dir="ltr">{mo.month}</div>
                                        {mo.items.length === 0 && <div className="text-[11px] text-[#9ca3af]">{t('لا بنود')}</div>}
                                        <ul className="divide-y divide-[#f4f4f5] text-[12px]">
                                            {mo.items.map((it, i) => (
                                                <li key={i} className="flex items-center gap-2 py-1">
                                                    <span className="w-[72px] shrink-0 text-[11px] text-[#9ca3af]" dir="ltr">{it.at}</span>
                                                    <span className={'shrink-0 rounded-full px-1.5 text-[10px] ' + categoryTone(it.category)}>{t(CATEGORY_LABEL[it.category] ?? it.category)}</span>
                                                    <span className="min-w-0 flex-1 truncate">{it.source.url ? <a href={it.source.url} className="hover:text-[#1d4ed8]">{it.label}</a> : it.label}</span>
                                                    <span className={'shrink-0 tabular-nums ' + (it.direction === 'in' ? 'text-[#047857]' : 'text-[#b91c1c]')} dir="ltr">{it.direction === 'in' ? '+' : '−'}{m(it.amount)}</span>
                                                </li>
                                            ))}
                                        </ul>
                                        {mo.more_items > 0 && <div className="text-[11px] text-[#9ca3af]">{t('و:n بندًا أخرى — في التقرير كاملةً', { n: String(mo.more_items) })}</div>}
                                    </div>
                                ))}
                            </div>
                        </Card>

                        <Card className="p-4">
                            <div className="mb-2 flex items-center justify-between">
                                <h3 className="text-[14px] font-semibold">{t('السيناريوهات')}</h3>
                                {p.may.scenarios && <Button size="sm" variant="outline" onClick={() => setEditingScenario({ id: null, name: '', description: '', assumptions: [] })}><Plus />{t('سيناريو')}</Button>}
                            </div>
                            <div className="grid gap-3 md:grid-cols-3">
                                {p.scenarios.map((s) => (
                                    <div key={s.id} className={'rounded-lg border p-3 text-[12px] ' + (p.scenario_id === s.id ? 'border-[#111]' : 'border-[#e5e7eb]')}>
                                        <div className="flex items-start justify-between gap-2">
                                            <div><span className="font-semibold">{s.name}</span> <span className="text-[10px] text-[#9ca3af]">{s.kind_label}</span>{s.description && <div className="text-[11px] text-[#6b7280]">{s.description}</div>}</div>
                                            {p.may.scenarios && (
                                                <div className="flex gap-1">
                                                    <Button variant="ghost" size="sm" onClick={() => setEditingScenario({ id: s.id, name: s.name, description: s.description ?? '', assumptions: s.assumptions.map((a) => ({ ...a })) })}>{t('تعديل')}</Button>
                                                    {s.kind === 'custom' && <Button variant="ghost" size="sm" onClick={async () => { if (await ask({ message: t('حذف السيناريو «:n»؟', { n: s.name }), danger: true, action: t('حذف') })) router.delete(route('admin.management.scenarios.destroy', s.id), { preserveScroll: true }); }}><Trash2 /></Button>}
                                                </div>
                                            )}
                                        </div>
                                        <ul className="mt-1 space-y-0.5 text-[11px] text-[#6b7280]">
                                            {s.assumptions.length === 0 && <li>{t('بلا افتراضات — الأساس')}</li>}
                                            {s.assumptions.map((a) => <li key={a.id ?? a.key}>{a.label}: <span dir="ltr">{a.percent !== null ? `${a.percent}%` : m(a.amount)}</span>{a.months ? ` × ${a.months} ${t('شهر')}` : ''}</li>)}
                                        </ul>
                                        <Button className="mt-2" size="sm" variant={p.scenario_id === s.id ? 'primary' : 'outline'} onClick={() => recompute({}, p.horizon, p.scenario_id === s.id ? null : s.id)}>{p.scenario_id === s.id ? t('مطبَّق') : t('طبّق')}</Button>
                                    </div>
                                ))}
                            </div>
                        </Card>
                    </div>
                </div>
            )}

            {tab === 'live' && !proj && <Card className="p-6 text-center text-[13px] text-[#9ca3af]">{t('قراءةُ التوقّع تحتاج صلاحيّة «قراءة التوقّعات النقدية»')}</Card>}

            {tab === 'saved' && (
                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="p-4">
                        <h3 className="mb-2 text-[14px] font-semibold">{t('التوقّعات')}</h3>
                        {p.forecasts.length === 0 && <p className="text-[12px] text-[#9ca3af]">{t('لا توقّعاتٍ محفوظة — احفظ إسقاطًا حيًّا')}</p>}
                        <ul className="divide-y divide-[#f4f4f5] text-[12px]">
                            {p.forecasts.map((f) => (
                                <li key={f.id} className={'py-2 ' + (p.selected?.id === f.id ? 'bg-[#fafafa]' : '')}>
                                    <div className="flex items-center justify-between gap-2">
                                        <a href={route('admin.management.cash', { forecast: f.id })} className="font-medium hover:text-[#1d4ed8]">{f.name}</a>
                                        <span className={'rounded-full px-1.5 text-[10px] ' + (f.status === 'approved' ? 'bg-[#ecfdf5] text-[#047857]' : f.status === 'draft' ? 'bg-[#fffbeb] text-[#b45309]' : 'bg-[#f4f4f5] text-[#71717a]')}>{t(FORECAST_STATUS_LABEL[f.status] ?? f.status)}</span>
                                    </div>
                                    <div className="text-[11px] text-[#9ca3af]" dir="ltr">{f.base_date} · {f.horizon_days}d · {m(f.closing)}</div>
                                    <div className="text-[11px] text-[#9ca3af]">{f.created_by ?? '—'}{f.approved_by ? ` · ${t('اعتمده')} ${f.approved_by} ${f.approved_at}` : ''}{f.scenario ? ` · ${f.scenario.name}` : ''}</div>
                                    <div className="mt-1 flex gap-1">
                                        {f.status === 'draft' && p.may.forecasts_approve && <Button size="sm" variant="success" onClick={async () => { if (await ask({ message: t('اعتماد التوقّع «:n»؟ يُجمَّد ويحلّ محلّ المعتمَد بالأفق نفسه.', { n: f.name }), action: t('اعتماد') })) router.post(route('admin.management.forecasts.approve', f.id), {}, { preserveScroll: true }); }}><Check />{t('اعتماد')}</Button>}
                                        {f.status === 'draft' && p.may.forecasts_manage && <Button size="sm" variant="ghost" onClick={async () => { if (await ask({ message: t('حذف المسودّة؟'), danger: true, action: t('حذف') })) router.delete(route('admin.management.forecasts.destroy', f.id), { preserveScroll: true }); }}><Trash2 /></Button>}
                                        {f.status === 'approved' && <span className="inline-flex items-center gap-1 text-[10px] text-[#9ca3af]"><Lock className="size-3" />{t('مجمَّد')}</span>}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </Card>
                    <div className="space-y-4 lg:col-span-2">
                        {p.selected ? (
                            <>
                                <Card className="p-4">
                                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                        <h3 className="text-[14px] font-semibold">{p.selected.name} — {t('التوقّع مقابل الواقع')}</h3>
                                        <CompletenessBadge completeness={p.selected.projection.completeness} />
                                    </div>
                                    {p.selected.versus_actual.accuracy.status === 'insufficient_data' ? (
                                        <p className="mb-2 text-[12px] text-[#9ca3af]">{p.selected.versus_actual.accuracy.reason}</p>
                                    ) : (
                                        <div className="mb-3 grid grid-cols-2 gap-2 md:grid-cols-4">
                                            <Kpi label="داخل — متوقَّع" value={m(p.selected.versus_actual.accuracy.inflows_forecast)} />
                                            <Kpi label="داخل — فعلي" value={m(p.selected.versus_actual.accuracy.inflows_actual)} />
                                            <Kpi label="خارج — متوقَّع" value={m(p.selected.versus_actual.accuracy.outflows_forecast)} />
                                            <Kpi label="خارج — فعلي" value={m(p.selected.versus_actual.accuracy.outflows_actual)} />
                                        </div>
                                    )}
                                    <table className="w-full text-[12px]">
                                        <thead className="text-[#6b7280]"><tr><th className="py-1 text-start">{t('الشهر')}</th><th className="py-1 text-end">{t('داخل متوقَّع')}</th><th className="py-1 text-end">{t('داخل فعلي')}</th><th className="py-1 text-end">{t('خارج متوقَّع')}</th><th className="py-1 text-end">{t('خارج فعلي')}</th><th className="py-1 text-end">{t('إقفال متوقَّع')}</th><th className="py-1 text-end">{t('إقفال فعلي')}</th><th className="py-1 text-end">{t('الفرق')}</th></tr></thead>
                                        <tbody>{p.selected.versus_actual.rows.map((r) => (
                                            <tr key={r.month} className="border-t border-[#f4f4f5]">
                                                <td className="py-1" dir="ltr">{r.month}{r.status === 'pending' && <span className="ms-1 text-[10px] text-[#9ca3af]">{t('لم ينقضِ')}</span>}</td>
                                                <td className="py-1 text-end tabular-nums" dir="ltr">{m(r.forecast.inflows)}</td><td className="py-1 text-end tabular-nums" dir="ltr">{m(r.actual?.inflows)}</td>
                                                <td className="py-1 text-end tabular-nums" dir="ltr">{m(r.forecast.outflows)}</td><td className="py-1 text-end tabular-nums" dir="ltr">{m(r.actual?.outflows)}</td>
                                                <td className="py-1 text-end tabular-nums" dir="ltr">{m(r.forecast.closing)}</td><td className="py-1 text-end tabular-nums" dir="ltr">{m(r.actual?.closing)}</td>
                                                <td className={'py-1 text-end tabular-nums ' + ((r.variance?.closing ?? 0) < 0 ? 'text-[#b91c1c]' : 'text-[#047857]')} dir="ltr">{m(r.variance?.closing)}</td>
                                            </tr>
                                        ))}</tbody>
                                    </table>
                                    <p className="mt-2 text-[11px] text-[#9ca3af]">{p.selected.versus_actual.basis}</p>
                                </Card>
                                <Card className="p-4">
                                    <h3 className="mb-2 text-[14px] font-semibold">{t('افتراضات اللقطة')}</h3>
                                    <dl className="grid grid-cols-2 gap-1 text-[12px] md:grid-cols-3">
                                        {Object.entries(p.selected.assumptions).map(([k, v]) => <div key={k} className="rounded bg-[#fafafa] px-2 py-1"><dt className="text-[10px] text-[#9ca3af]">{k}</dt><dd dir="ltr">{String(v)}</dd></div>)}
                                    </dl>
                                    {p.selected.projection.scenario && <p className="mt-2 text-[11px] text-[#6b7280]">{t('السيناريو')}: {p.selected.projection.scenario.name}</p>}
                                </Card>
                            </>
                        ) : (
                            <Card className="p-6 text-center text-[13px] text-[#9ca3af]">{t('اختر توقّعًا لقراءة لقطته ومقارنتها بالواقع')}</Card>
                        )}
                    </div>
                </div>
            )}

            <Dialog open={editingScenario !== null} onOpenChange={(v) => !v && setEditingScenario(null)}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{editingScenario?.id === null ? t('سيناريو جديد') : t('تعديل السيناريو')}</DialogTitle>
                        <DialogDescription>{t('محاكاةٌ لا تمسّ قيدًا ولا مستندًا — تُطبَّق على الإسقاط وتُقال جنبَ الرقم')}</DialogDescription>
                    </DialogHeader>
                    {editingScenario && (
                        <div className="max-h-[60vh] space-y-3 overflow-y-auto px-5 pb-5">
                            <Field label="الاسم" required><Input value={editingScenario.name} onChange={(e) => setEditingScenario({ ...editingScenario, name: e.target.value })} /></Field>
                            <Field label="الوصف"><Textarea rows={2} value={editingScenario.description} onChange={(e) => setEditingScenario({ ...editingScenario, description: e.target.value })} /></Field>
                            <div className="space-y-2">
                                {editingScenario.assumptions.map((a, i) => {
                                    const isPct = p.assumption_keys.find((k) => k.key === a.key)?.percent ?? false;
                                    const set = (patch: Partial<Assumption>) => setEditingScenario({ ...editingScenario, assumptions: editingScenario.assumptions.map((x, k) => (k === i ? { ...x, ...patch } : x)) });

                                    return (
                                        <div key={i} className="rounded-lg border border-[#f0f0f0] p-2">
                                            <div className="grid grid-cols-2 gap-2">
                                                <Field label="النوع"><Select value={a.key} onChange={(e) => set({ key: e.target.value })} options={p.assumption_keys.map((k) => ({ value: k.key, label: k.label }))} /></Field>
                                                <Field label="التسمية"><Input value={a.label} onChange={(e) => set({ label: e.target.value })} /></Field>
                                                {isPct ? (
                                                    <Field label="النسبة (٪)"><Input type="number" step="0.1" value={a.percent ?? ''} onChange={(e) => set({ percent: e.target.value === '' ? null : Number(e.target.value) })} dir="ltr" /></Field>
                                                ) : (
                                                    <Field label={a.key === 'collection_delay_days' ? 'الأيّام' : 'المبلغ'}><Input type="number" step="0.001" value={a.amount ?? ''} onChange={(e) => set({ amount: e.target.value === '' ? null : Number(e.target.value) })} dir="ltr" /></Field>
                                                )}
                                                {!isPct && a.key !== 'collection_delay_days' && <Field label="يبدأ في"><Input type="date" value={a.starts_on ?? ''} onChange={(e) => set({ starts_on: e.target.value || null })} /></Field>}
                                                {(a.key === 'monthly_outflow' || a.key === 'monthly_inflow') && <Field label="لكم شهرًا"><Input type="number" min={1} max={60} value={a.months ?? ''} onChange={(e) => set({ months: e.target.value === '' ? null : Number(e.target.value) })} dir="ltr" /></Field>}
                                            </div>
                                            <Button variant="ghost" size="sm" className="mt-1" onClick={() => setEditingScenario({ ...editingScenario, assumptions: editingScenario.assumptions.filter((_, k) => k !== i) })}><Trash2 />{t('إزالة')}</Button>
                                        </div>
                                    );
                                })}
                                <Button variant="outline" size="sm" onClick={() => setEditingScenario({ ...editingScenario, assumptions: [...editingScenario.assumptions, { key: 'expense_change_pct', label: '', amount: null, percent: null, starts_on: null, months: null, notes: null }] })}><Plus />{t('افتراض')}</Button>
                            </div>
                        </div>
                    )}
                    <DialogFooter>
                        <Button
                            disabled={!editingScenario || editingScenario.name.trim() === ''}
                            onClick={() => {
                                if (!editingScenario) return;
                                const data = { name: editingScenario.name, description: editingScenario.description, assumptions: editingScenario.assumptions.map((a) => ({ ...a })) as Record<string, string | number | null>[] };
                                const opts = { preserveScroll: true, onSuccess: () => setEditingScenario(null) };
                                editingScenario.id === null ? router.post(route('admin.management.scenarios.store'), data, opts) : router.put(route('admin.management.scenarios.update', editingScenario.id), data, opts);
                            }}
                        >
                            {t('حفظ')}
                        </Button>
                        <Button variant="outline" onClick={() => setEditingScenario(null)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {dialog}
        </AdminLayout>
    );
}
