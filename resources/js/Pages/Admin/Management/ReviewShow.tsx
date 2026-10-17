import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Check, Lock, Plus, RefreshCw, Save } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { MANAGEMENT_TABS } from '@/Components/SectionTabs';
import Field, { Select } from '@/Components/Field';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import AlertList from '@/Components/management/AlertList';
import Kpi from '@/Components/management/Kpi';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { COMPLETENESS_LABEL, pct, REVIEW_STATUS_LABEL, type Alert } from '@/lib/management';
import type { PageProps } from '@/types';

interface Decision {
    id: number;
    decision: string;
    owner: string | null;
    owner_id: number | null;
    due_at: string | null;
    status: string;
    status_label: string;
    notes: string | null;
}

interface Snapshot {
    captured_at: string | null;
    period: { label?: string; from?: string; to?: string } | null;
    financial?: { revenue: number; collections: number; direct_costs: number; expenses: number; payroll: number; gross_contribution: number; operating_result: number; basis: Record<string, string> };
    cash?: { liquid: number; cash: number; bank: number; cheques_under_collection: number };
    receivables?: { outstanding: number; overdue: number };
    funnel?: { acceptance_rate: number | null; stages?: { key: string; label: string; count: number }[] };
    projects?: { totals: { projects: number; revenue: number; contribution: number; margin_percent: number | null; negative: number }; rows: { code: string; name: string; revenue: number; direct_costs: number; margin_percent: number | null; completeness: string }[] };
    operations?: { events: { total: number; completed: number; cancelled: number }; readiness: { clean_percent: number | null; overridden: number }; incidents: { total: number; severe: number } } | null;
    alerts?: Alert[];
}

interface Props {
    review: {
        id: number; title: string; period_start: string; period_end: string; status: string; prepared_by: string | null; reviewed_at: string | null; closed_at: string | null;
        notes: string | null; sections: Record<string, string>; snapshot: Snapshot | null; decisions: Decision[];
    };
    users: { id: number; name: string }[];
    section_keys: string[];
    may: { manage: boolean; financial: boolean };
}

const SECTION_LABEL: Record<string, string> = {
    financial: 'المال', budget: 'الموازنة', forecast: 'التوقّع', cash: 'النقد', projects: 'المشاريع', sales: 'القمع التجاري', operations: 'العمليّات', risks: 'المخاطر', notes: 'ملاحظات',
};

/** مراجعةٌ واحدة — اللقطةُ كما التُقطت، وأقسامٌ تكتبها الإدارة، وقراراتٌ بمالكٍ وموعد */
export default function ManagementReviewShow({ review: r, users, section_keys, may }: Props) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const [ask, dialog] = useConfirm();
    const m = (v: number | null | undefined) => (v === null || v === undefined ? '—' : money(v, context!.currency));
    const editable = may.manage && r.status !== 'closed';
    const [sections, setSections] = useState<Record<string, string>>(Object.fromEntries(section_keys.map((k) => [k, r.sections[k] ?? ''])));
    const [notes, setNotes] = useState(r.notes ?? '');
    const [dirty, setDirty] = useState(false);
    const [decision, setDecision] = useState({ decision: '', owner_id: '', due_at: '', notes: '' });
    const s = r.snapshot;

    const save = () => router.put(route('admin.management.reviews.update', r.id), { notes, sections }, { preserveScroll: true, onSuccess: () => setDirty(false) });

    return (
        <AdminLayout title={r.title}>
            <PageHeader
                title={r.title}
                subtitle={`${r.period_start} → ${r.period_end}`}
                actions={
                    <div className="flex flex-wrap gap-2">
                        <a href={route('admin.management.reviews')} className="self-center text-[12px] text-[#6b7280] hover:text-[#111]">← {t('كلّ المراجعات')}</a>
                        <span className="self-center rounded-full bg-[#f4f4f5] px-2 py-0.5 text-[11px]">{t(REVIEW_STATUS_LABEL[r.status] ?? r.status)}</span>
                        {editable && <Button size="sm" disabled={!dirty} onClick={save}><Save />{t('حفظ')}</Button>}
                        {may.manage && r.status === 'draft' && <Button size="sm" variant="outline" onClick={async () => { if (await ask({ message: t('تحديث اللقطة من الأرقام الحاليّة؟ تُستبدل اللقطةُ القديمة.'), action: t('تحديث') })) router.post(route('admin.management.reviews.refresh', r.id), {}, { preserveScroll: true }); }}><RefreshCw />{t('تحديث اللقطة')}</Button>}
                        {may.manage && r.status === 'draft' && <Button size="sm" variant="success" onClick={async () => { if (await ask({ message: t('تعليم المراجعة «رُوجعت»؟ تُجمَّد اللقطة.'), action: t('رُوجعت') })) router.post(route('admin.management.reviews.review', r.id), {}, { preserveScroll: true }); }}><Check />{t('رُوجعت')}</Button>}
                        {may.manage && r.status === 'reviewed' && <Button size="sm" variant="outline" onClick={async () => { if (await ask({ message: t('إقفال المراجعة؟ لا تُعدَّل بعدها — والقراراتُ تبقى تُتابَع.'), action: t('إقفال') })) router.post(route('admin.management.reviews.close', r.id), {}, { preserveScroll: true }); }}><Lock />{t('إقفال')}</Button>}
                    </div>
                }
            />
            <SectionTabs tabs={MANAGEMENT_TABS} current="admin.management.reviews" className="mb-4" />
            {(errors?.review || errors?.decision) && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.review ?? errors.decision}</div>}

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card className="p-4">
                        <div className="mb-2 flex items-center justify-between">
                            <h3 className="text-[14px] font-semibold">{t('اللقطة')}</h3>
                            <span className="text-[11px] text-[#9ca3af]">{s?.captured_at ? `${t('التُقطت')} ${s.captured_at}` : t('لا لقطة')}</span>
                        </div>
                        {!s && <p className="text-[12px] text-[#9ca3af]">{t('لا لقطة')}</p>}
                        {s && !may.financial && <p className="mb-2 text-[11px] text-[#b45309]">{t('أرقامُ المال في اللقطة لمن يقرأ التحليلات المالية')}</p>}
                        {s?.financial && (
                            <div className="mb-3 grid grid-cols-2 gap-2 md:grid-cols-4">
                                <Kpi label="الإيراد الصافي" value={m(s.financial.revenue)} basis={s.financial.basis?.revenue} />
                                <Kpi label="المحصَّل" value={m(s.financial.collections)} />
                                <Kpi label="التكلفة المباشرة" value={m(s.financial.direct_costs)} invert />
                                <Kpi label="المصروفات" value={m(s.financial.expenses)} invert />
                                <Kpi label="الرواتب" value={m(s.financial.payroll)} invert />
                                <Kpi label="المساهمة الإجماليّة" value={m(s.financial.gross_contribution)} />
                                <Kpi label="النتيجة التشغيليّة" value={m(s.financial.operating_result)} tone={s.financial.operating_result < 0 ? 'danger' : 'ok'} basis={s.financial.basis?.operating_result} />
                                {s.cash && <Kpi label="النقد السائل" value={m(s.cash.liquid)} sub={s.receivables ? <span>{t('ذمم')}: <span dir="ltr">{m(s.receivables.outstanding)}</span> · {t('متأخّر')}: <span dir="ltr">{m(s.receivables.overdue)}</span></span> : undefined} />}
                            </div>
                        )}
                        {s?.operations && (
                            <div className="mb-3 grid grid-cols-2 gap-2 md:grid-cols-4">
                                <Kpi label="فعاليّات" value={String(s.operations.events.total)} sub={<span>{t('أُتمّت')}: {s.operations.events.completed}</span>} />
                                <Kpi label="بُثّت بلا تجاوز" value={pct(s.operations.readiness.clean_percent)} />
                                <Kpi label="حوادث" value={String(s.operations.incidents.total)} sub={<span>{t('جسيمة')}: {s.operations.incidents.severe}</span>} />
                                <Kpi label="نسبة القبول" value={pct(s.funnel?.acceptance_rate ?? null)} />
                            </div>
                        )}
                        {s?.projects && s.projects.rows.length > 0 && (
                            <div className="mb-3">
                                <h4 className="mb-1 text-[12px] font-semibold text-[#6b7280]">{t('المشاريع — الأضعف هامشًا أوّلًا')} ({s.projects.totals.projects})</h4>
                                <table className="w-full text-[12px]">
                                    <thead className="text-[11px] text-[#6b7280]"><tr><th className="py-1 text-start">{t('المشروع')}</th><th className="py-1 text-end">{t('الإيراد')}</th><th className="py-1 text-end">{t('التكلفة')}</th><th className="py-1 text-end">{t('الهامش')}</th><th className="py-1 text-start">{t('الاكتمال')}</th></tr></thead>
                                    <tbody>{s.projects.rows.map((p) => <tr key={p.code} className="border-t border-[#f4f4f5]"><td className="py-1"><span className="font-mono text-[11px]">{p.code}</span> {p.name}</td><td className="py-1 text-end tabular-nums" dir="ltr">{m(p.revenue)}</td><td className="py-1 text-end tabular-nums" dir="ltr">{m(p.direct_costs)}</td><td className="py-1 text-end tabular-nums" dir="ltr">{pct(p.margin_percent)}</td><td className="py-1 text-[11px] text-[#6b7280]">{t(COMPLETENESS_LABEL[p.completeness] ?? p.completeness)}</td></tr>)}</tbody>
                                </table>
                            </div>
                        )}
                        {s?.alerts && s.alerts.length > 0 && <div><h4 className="mb-1 text-[12px] font-semibold text-[#6b7280]">{t('التنبيهات وقتَ اللقطة')}</h4><AlertList alerts={s.alerts} /></div>}
                    </Card>

                    <Card className="p-4">
                        <h3 className="mb-2 text-[14px] font-semibold">{t('أقسام المراجعة')}</h3>
                        <div className="grid gap-3 md:grid-cols-2">
                            {section_keys.map((k) => (
                                <Field key={k} label={SECTION_LABEL[k] ?? k}>
                                    {editable ? <Textarea rows={3} value={sections[k]} onChange={(e) => { setSections({ ...sections, [k]: e.target.value }); setDirty(true); }} /> : <p className="min-h-[40px] whitespace-pre-wrap rounded-lg bg-[#fafafa] px-3 py-2 text-[13px]">{sections[k] || <span className="text-[#c4c4c4]">—</span>}</p>}
                                </Field>
                            ))}
                        </div>
                        <Field label="ملاحظات" className="mt-3">
                            {editable ? <Textarea rows={2} value={notes} onChange={(e) => { setNotes(e.target.value); setDirty(true); }} /> : <p className="whitespace-pre-wrap text-[13px]">{notes || '—'}</p>}
                        </Field>
                    </Card>
                </div>

                <Card className="p-4">
                    <h3 className="mb-2 text-[14px] font-semibold">{t('القرارات')} <span className="text-[11px] font-normal text-[#9ca3af]">({r.decisions.length})</span></h3>
                    <p className="mb-2 text-[11px] text-[#9ca3af]">{t('قراراتُ الإدارة تُسجَّل هنا بمالكٍ وموعد — ولا يقترحها النظام')}</p>
                    <ul className="divide-y divide-[#f4f4f5] text-[12px]">
                        {r.decisions.length === 0 && <li className="py-3 text-[#9ca3af]">{t('لا قرارات')}</li>}
                        {r.decisions.map((d) => (
                            <li key={d.id} className="py-2">
                                <div className={d.status === 'dropped' ? 'line-through text-[#9ca3af]' : d.status === 'done' ? 'text-[#6b7280]' : ''}>{d.decision}</div>
                                <div className="text-[11px] text-[#9ca3af]">{d.owner ?? t('بلا مالك')}{d.due_at ? <span dir="ltr"> · {d.due_at}</span> : ''} · {d.status_label}</div>
                                {d.notes && <div className="text-[11px] text-[#6b7280]">{d.notes}</div>}
                                {may.manage && d.status === 'open' && (
                                    <div className="mt-1 flex gap-1">
                                        <Button size="sm" variant="ghost" onClick={() => router.post(route('admin.management.reviews.decisions.status', [r.id, d.id]), { status: 'done' }, { preserveScroll: true })}>{t('نُفّذ')}</Button>
                                        <Button size="sm" variant="ghost" onClick={() => router.post(route('admin.management.reviews.decisions.status', [r.id, d.id]), { status: 'dropped' }, { preserveScroll: true })}>{t('أُلغي')}</Button>
                                    </div>
                                )}
                                {may.manage && d.status !== 'open' && <Button size="sm" variant="ghost" className="mt-1" onClick={() => router.post(route('admin.management.reviews.decisions.status', [r.id, d.id]), { status: 'open' }, { preserveScroll: true })}>{t('إعادة فتح')}</Button>}
                            </li>
                        ))}
                    </ul>
                    {may.manage && r.status !== 'closed' && (
                        <div className="mt-3 space-y-2 border-t border-[#f4f4f5] pt-3">
                            <Field label="قرار جديد" required><Input value={decision.decision} onChange={(e) => setDecision({ ...decision, decision: e.target.value })} /></Field>
                            <div className="grid grid-cols-2 gap-2">
                                <Field label="المالك"><Select value={decision.owner_id} placeholder="—" onChange={(e) => setDecision({ ...decision, owner_id: e.target.value })} options={users.map((u) => ({ value: u.id, label: u.name }))} /></Field>
                                <Field label="الموعد"><Input type="date" value={decision.due_at} onChange={(e) => setDecision({ ...decision, due_at: e.target.value })} /></Field>
                            </div>
                            <Field label="ملاحظة"><Input value={decision.notes} onChange={(e) => setDecision({ ...decision, notes: e.target.value })} /></Field>
                            <Button size="sm" className="w-full" disabled={decision.decision.trim() === ''} onClick={() => router.post(route('admin.management.reviews.decisions.store', r.id), { ...decision, owner_id: decision.owner_id || null, due_at: decision.due_at || null }, { preserveScroll: true, onSuccess: () => setDecision({ decision: '', owner_id: '', due_at: '', notes: '' }) })}><Plus />{t('تسجيل القرار')}</Button>
                        </div>
                    )}
                </Card>
            </div>
            {dialog}
        </AdminLayout>
    );
}
