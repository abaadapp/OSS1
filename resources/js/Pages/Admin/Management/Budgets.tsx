import { Fragment, useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Archive, Check, GitBranch, Plus, Save } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { MANAGEMENT_TABS } from '@/Components/SectionTabs';
import Field, { Select } from '@/Components/Field';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import Kpi from '@/Components/management/Kpi';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { BUDGET_STATUS_LABEL, budgetTone, pct } from '@/lib/management';
import type { PageProps } from '@/types';

interface BudgetRow {
    id: number;
    name: string;
    fiscal_year: number;
    version: number;
    status: string;
    status_label: string;
    created_by: string | null;
    approved_by: string | null;
    approved_at: string | null;
    revision_reason: string | null;
    revised_from_id: number | null;
}

interface Line {
    account_id: number;
    code: string | null;
    name: string | null;
    kind: 'revenue' | 'expense';
    month: number;
    planned_amount: number;
    notes: string | null;
}

interface Bucket {
    label: string;
    planned: number;
    actual: number;
    variance: number;
    variance_percent: number | null;
    reading: string;
    elapsed: boolean;
}

interface VersusRow {
    account_id: number;
    code: string;
    name: string;
    kind: 'revenue' | 'expense';
    buckets: Bucket[];
    planned: number;
    actual: number;
    variance: number;
    variance_percent: number | null;
    reading: string;
}

interface Account {
    id: number;
    code: string;
    name: string;
    kind: 'revenue' | 'expense';
}

interface Props {
    budgets: BudgetRow[];
    selected: { id: number; name: string; fiscal_year: number; version: number; status: string; notes: string | null; lines: Line[]; versus_actual: { grouping: string; rows: VersusRow[]; totals: { revenue: { planned: number; actual: number; variance: number }; expense: { planned: number; actual: number; variance: number } }; basis: string } } | null;
    grouping: string;
    accounts: Account[];
    reading_labels: Record<string, string>;
    may: { manage: boolean; approve: boolean; financial: boolean };
}

const MONTHS = Array.from({ length: 12 }, (_, i) => i + 1);

/** شبكةُ السطور: حساب → شهر → مبلغ (نصًّا في التحرير) */
type Grid = Record<number, Record<number, string>>;

const toGrid = (lines: Line[]): Grid => {
    const g: Grid = {};
    lines.forEach((l) => {
        g[l.account_id] = g[l.account_id] ?? {};
        g[l.account_id][l.month] = String(l.planned_amount);
    });

    return g;
};

const fromGrid = (g: Grid) => Object.entries(g).flatMap(([account, months]) => Object.entries(months).filter(([, v]) => v !== '' && Number(v) >= 0).map(([month, v]) => ({ account_id: Number(account), month: Number(month), planned_amount: Number(v) })));

/**
 * الموازنةُ السنويّة — نسخٌ لا تُمحى.
 *
 * مسودّةٌ تُحرَّر، ثمّ تُعتمد فتُجمَّد، ثمّ تُنقَّح نسخةً جديدةً بسببٍ مكتوب — ولا
 * معتمَدةٌ إلّا واحدةٌ للسنة. والفعليُّ من الدفتر، والانحرافُ يُقال بالوقائع.
 */
export default function ManagementBudgets(p: Props) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const [ask, dialog] = useConfirm();
    const m = (v: number | null | undefined) => (v === null || v === undefined ? '—' : money(v, context!.currency));

    const s = p.selected;
    const editable = Boolean(s && s.status === 'draft' && p.may.manage);
    const [grid, setGrid] = useState<Grid>(() => toGrid(s?.lines ?? []));
    const [name, setName] = useState(s?.name ?? '');
    const [notes, setNotes] = useState(s?.notes ?? '');
    const [dirty, setDirty] = useState(false);
    const [creating, setCreating] = useState(false);
    const [newYear, setNewYear] = useState(String(new Date().getFullYear() + 1));
    const [newName, setNewName] = useState('');
    const [revising, setRevising] = useState(false);
    const [reason, setReason] = useState('');
    const [pane, setPane] = useState<'plan' | 'actual'>(s?.status === 'approved' ? 'actual' : 'plan');

    const accountsByKind = useMemo(() => ({ revenue: p.accounts.filter((a) => a.kind === 'revenue'), expense: p.accounts.filter((a) => a.kind === 'expense') }), [p.accounts]);
    const rowTotal = (id: number) => MONTHS.reduce((acc, mo) => acc + (Number(grid[id]?.[mo] ?? 0) || 0), 0);
    const kindTotal = (kind: 'revenue' | 'expense') => accountsByKind[kind].reduce((acc, a) => acc + rowTotal(a.id), 0);
    const set = (id: number, mo: number, v: string) => { setGrid({ ...grid, [id]: { ...(grid[id] ?? {}), [mo]: v } }); setDirty(true); };
    const spread = (id: number) => {
        const first = grid[id]?.[1] ?? '';
        if (first === '') return;
        const row: Record<number, string> = {};
        MONTHS.forEach((mo) => { row[mo] = first; });
        setGrid({ ...grid, [id]: row });
        setDirty(true);
    };
    const save = () => router.put(route('admin.management.budgets.update', s!.id), { name, notes, lines: fromGrid(grid) }, { preserveScroll: true, onSuccess: () => setDirty(false) });

    const va = s?.versus_actual;
    const labels = va?.rows[0]?.buckets.map((b) => b.label) ?? [];

    return (
        <AdminLayout title="الموازنات">
            <PageHeader
                title="الموازنات"
                subtitle={t('خطّةٌ سنويّة بنسخٍ لا تُمحى — والفعليُّ يُقرأ من الدفتر جنبَها')}
                actions={p.may.manage ? <Button onClick={() => setCreating(true)}><Plus />{t('موازنة جديدة')}</Button> : undefined}
            />
            <SectionTabs tabs={MANAGEMENT_TABS} current="admin.management.budgets" className="mb-4" />

            {errors?.budget && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.budget}</div>}

            <div className="grid gap-4 lg:grid-cols-4">
                <Card className="p-4">
                    <h3 className="mb-2 text-[14px] font-semibold">{t('النسخ')}</h3>
                    {p.budgets.length === 0 && <p className="text-[12px] text-[#9ca3af]">{t('لا موازناتٍ بعد — أنشئ مسودّةً للسنة القادمة')}</p>}
                    <ul className="divide-y divide-[#f4f4f5] text-[12px]">
                        {p.budgets.map((b) => (
                            <li key={b.id} className={'py-2 ' + (s?.id === b.id ? '-mx-2 rounded bg-[#fafafa] px-2' : '')}>
                                <div className="flex items-center justify-between gap-2">
                                    <a href={route('admin.management.budgets', { budget: b.id, grouping: p.grouping })} className="font-medium hover:text-[#1d4ed8]">{b.name} <span className="text-[10px] text-[#9ca3af]">v{b.version}</span></a>
                                    <span className={'rounded-full px-1.5 text-[10px] ' + budgetTone(b.status)}>{t(BUDGET_STATUS_LABEL[b.status] ?? b.status)}</span>
                                </div>
                                <div className="text-[11px] text-[#9ca3af]"><span dir="ltr">{b.fiscal_year}</span> · {b.created_by ?? '—'}{b.approved_by ? ` · ${t('اعتمدها')} ${b.approved_by} ${b.approved_at}` : ''}</div>
                                {b.revision_reason && <div className="text-[11px] text-[#6b7280]">{t('سبب التنقيح')}: {b.revision_reason}</div>}
                            </li>
                        ))}
                    </ul>
                </Card>

                <div className="space-y-4 lg:col-span-3">
                    {!s ? (
                        <Card className="p-6 text-center text-[13px] text-[#9ca3af]">{t('اختر موازنةً أو أنشئ واحدة')}</Card>
                    ) : (
                        <>
                            <Card className="p-4">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <h3 className="text-[15px] font-semibold">{s.name} <span className="text-[11px] font-normal text-[#9ca3af]">v{s.version} · <span dir="ltr">{s.fiscal_year}</span></span> <span className={'ms-1 rounded-full px-1.5 text-[10px] ' + budgetTone(s.status)}>{t(BUDGET_STATUS_LABEL[s.status] ?? s.status)}</span></h3>
                                        {s.notes && !editable && <p className="text-[12px] text-[#6b7280]">{s.notes}</p>}
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Button variant={pane === 'plan' ? 'primary' : 'outline'} size="sm" onClick={() => setPane('plan')}>{t('الخطّة')}</Button>
                                        <Button variant={pane === 'actual' ? 'primary' : 'outline'} size="sm" onClick={() => setPane('actual')}>{t('الخطّة مقابل الفعلي')}</Button>
                                        {editable && <Button size="sm" disabled={!dirty} onClick={save}><Save />{t('حفظ')}</Button>}
                                        {s.status === 'draft' && p.may.approve && <Button size="sm" variant="success" onClick={async () => { if (await ask({ message: t('اعتماد الموازنة؟ تُجمَّد وتحلّ محلّ المعتمَدة للسنة نفسها إن وُجدت.'), action: t('اعتماد') })) router.post(route('admin.management.budgets.approve', s.id), {}, { preserveScroll: true }); }}><Check />{t('اعتماد')}</Button>}
                                        {s.status === 'approved' && p.may.manage && <Button size="sm" variant="outline" onClick={() => setRevising(true)}><GitBranch />{t('تنقيح')}</Button>}
                                        {(s.status === 'draft' || s.status === 'superseded') && p.may.manage && <Button size="sm" variant="ghost" onClick={async () => { if (await ask({ message: t('أرشفة الموازنة؟ تبقى للقراءة ولا تُمحى.'), action: t('أرشفة') })) router.post(route('admin.management.budgets.archive', s.id), {}, { preserveScroll: true }); }}><Archive />{t('أرشفة')}</Button>}
                                    </div>
                                </div>
                                {editable && (
                                    <div className="mt-3 grid gap-2 md:grid-cols-2">
                                        <Field label="الاسم"><Input value={name} onChange={(e) => { setName(e.target.value); setDirty(true); }} /></Field>
                                        <Field label="ملاحظات"><Input value={notes} onChange={(e) => { setNotes(e.target.value); setDirty(true); }} /></Field>
                                    </div>
                                )}
                            </Card>

                            {pane === 'plan' && (
                                <Card className="overflow-hidden">
                                    <div className="grid grid-cols-3 gap-2 p-4">
                                        <Kpi label="إيرادٌ مخطَّط" value={m(kindTotal('revenue'))} />
                                        <Kpi label="مصروفٌ مخطَّط" value={m(kindTotal('expense'))} invert />
                                        <Kpi label="الصافي المخطَّط" value={m(kindTotal('revenue') - kindTotal('expense'))} tone={kindTotal('revenue') - kindTotal('expense') < 0 ? 'danger' : undefined} />
                                    </div>
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-[11px]">
                                            <thead className="bg-[#fafafa] text-[#6b7280]">
                                                <tr><th className="sticky start-0 bg-[#fafafa] px-2 py-1.5 text-start">{t('الحساب')}</th>{MONTHS.map((mo) => <th key={mo} className="px-1 py-1.5 text-center" dir="ltr">{String(mo).padStart(2, '0')}</th>)}<th className="px-2 py-1.5 text-end">{t('السنة')}</th>{editable && <th />}</tr>
                                            </thead>
                                            <tbody>
                                                {(['revenue', 'expense'] as const).map((kind) => (
                                                    <Fragment key={kind}>
                                                        <tr className="bg-[#f9fafb]"><td colSpan={14 + (editable ? 1 : 0)} className="px-2 py-1 font-semibold">{kind === 'revenue' ? t('إيرادات') : t('مصروفات')}</td></tr>
                                                        {accountsByKind[kind].filter((a) => editable || rowTotal(a.id) > 0).map((a) => (
                                                            <tr key={a.id} className="border-t border-[#f4f4f5]">
                                                                <td className="sticky start-0 bg-white px-2 py-1"><span className="font-mono text-[10px] text-[#9ca3af]">{a.code}</span> {a.name}</td>
                                                                {MONTHS.map((mo) => (
                                                                    <td key={mo} className="px-0.5 py-0.5">
                                                                        {editable ? <Input className="h-6 w-[68px] px-1 py-0 text-end text-[11px]" inputMode="decimal" value={grid[a.id]?.[mo] ?? ''} onChange={(e) => set(a.id, mo, e.target.value)} dir="ltr" /> : <span className="block text-end tabular-nums" dir="ltr">{grid[a.id]?.[mo] ? m(Number(grid[a.id][mo])) : ''}</span>}
                                                                    </td>
                                                                ))}
                                                                <td className="px-2 py-1 text-end font-semibold tabular-nums" dir="ltr">{m(rowTotal(a.id))}</td>
                                                                {editable && <td className="px-1"><button type="button" className="text-[10px] text-[#6b7280] hover:text-[#111]" title={t('انسخ الشهر الأوّل على السنة')} onClick={() => spread(a.id)}>{t('⇥ توزيع')}</button></td>}
                                                            </tr>
                                                        ))}
                                                        {!editable && accountsByKind[kind].every((a) => rowTotal(a.id) <= 0) && <tr><td colSpan={14} className="px-2 py-2 text-center text-[#9ca3af]">{t('لا سطور')}</td></tr>}
                                                    </Fragment>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                    {editable && <p className="border-t border-[#f4f4f5] px-3 py-2 text-[11px] text-[#9ca3af]">{t('تُكتب المبالغ لكلّ شهرٍ على الحساب القابل للترحيل — واحفظ قبل الاعتماد')}</p>}
                                </Card>
                            )}

                            {pane === 'actual' && va && (
                                <Card className="overflow-hidden">
                                    <div className="flex flex-wrap items-center justify-between gap-2 p-4 pb-2">
                                        <div className="grid flex-1 grid-cols-3 gap-2">
                                            <Kpi label="إيراد: خطّة / فعلي" value={<span dir="ltr">{m(va.totals.revenue.planned)} / {m(va.totals.revenue.actual)}</span>} sub={<span dir="ltr">{m(va.totals.revenue.variance)}</span>} />
                                            <Kpi label="مصروف: خطّة / فعلي" value={<span dir="ltr">{m(va.totals.expense.planned)} / {m(va.totals.expense.actual)}</span>} sub={<span dir="ltr">{m(va.totals.expense.variance)}</span>} />
                                            <Kpi label="الصافي: خطّة / فعلي" value={<span dir="ltr">{m(va.totals.revenue.planned - va.totals.expense.planned)} / {m(va.totals.revenue.actual - va.totals.expense.actual)}</span>} />
                                        </div>
                                        <Field label="التجميع" className="min-w-[140px]"><Select value={p.grouping} onChange={(e) => router.get(route('admin.management.budgets'), { budget: s.id, grouping: e.target.value }, { preserveState: true, replace: true })} options={[{ value: 'month', label: t('شهري') }, { value: 'quarter', label: t('ربعي') }, { value: 'year', label: t('سنوي') }]} /></Field>
                                    </div>
                                    {!p.may.financial && <p className="px-4 pb-2 text-[11px] text-[#b45309]">{t('قراءةُ الفعلي تحتاج صلاحيّة التحليلات المالية')}</p>}
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-[11px]">
                                            <thead className="bg-[#fafafa] text-[#6b7280]">
                                                <tr><th className="sticky start-0 bg-[#fafafa] px-2 py-1.5 text-start">{t('الحساب')}</th>{labels.map((l) => <th key={l} className="px-1 py-1.5 text-center" dir="ltr">{l}</th>)}<th className="px-2 py-1.5 text-end">{t('خطّة')}</th><th className="px-2 py-1.5 text-end">{t('فعلي')}</th><th className="px-2 py-1.5 text-end">{t('الانحراف')}</th><th className="px-2 py-1.5 text-start">{t('القراءة')}</th></tr>
                                            </thead>
                                            <tbody>
                                                {va.rows.length === 0 && <tr><td colSpan={labels.length + 5} className="px-2 py-8 text-center text-[#9ca3af]">{t('لا سطورَ في الموازنة')}</td></tr>}
                                                {va.rows.map((r) => (
                                                    <tr key={r.account_id} className="border-t border-[#f4f4f5]">
                                                        <td className="sticky start-0 bg-white px-2 py-1"><span className="font-mono text-[10px] text-[#9ca3af]">{r.code}</span> {r.name}</td>
                                                        {r.buckets.map((b) => (
                                                            <td key={b.label} className={'px-1 py-1 text-center tabular-nums ' + (b.elapsed ? '' : 'text-[#c4c4c4]')} dir="ltr" title={`${t('خطّة')} ${m(b.planned)} · ${t('فعلي')} ${m(b.actual)} · ${t(p.reading_labels[b.reading] ?? b.reading)}`}>
                                                                <span className={b.variance !== 0 && b.elapsed ? (r.kind === 'expense' ? (b.variance > 0 ? 'text-[#b91c1c]' : 'text-[#047857]') : (b.variance > 0 ? 'text-[#047857]' : 'text-[#b91c1c]')) : ''}>{m(b.actual)}</span>
                                                                <span className="block text-[9px] text-[#9ca3af]">{m(b.planned)}</span>
                                                            </td>
                                                        ))}
                                                        <td className="px-2 py-1 text-end tabular-nums" dir="ltr">{m(r.planned)}</td>
                                                        <td className="px-2 py-1 text-end tabular-nums" dir="ltr">{m(r.actual)}</td>
                                                        <td className="px-2 py-1 text-end tabular-nums" dir="ltr">{m(r.variance)} {r.variance_percent !== null && <span className="text-[#9ca3af]">({pct(r.variance_percent)})</span>}</td>
                                                        <td className="px-2 py-1 text-[#6b7280]">{t(p.reading_labels[r.reading] ?? r.reading)}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                    <p className="border-t border-[#f4f4f5] px-3 py-2 text-[11px] text-[#9ca3af]">{va.basis} — {t('الخانةُ الباهتة مدّةٌ لم تنقضِ')}</p>
                                </Card>
                            )}
                        </>
                    )}
                </div>
            </div>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{t('موازنة جديدة')}</DialogTitle><DialogDescription>{t('مسودّةٌ فارغة تُملأ بالحسابات والأشهر ثمّ تُعتمد')}</DialogDescription></DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <Field label="السنة المالية" required><Input type="number" min={2000} max={2100} value={newYear} onChange={(e) => setNewYear(e.target.value)} dir="ltr" /></Field>
                        <Field label="الاسم" hint="يُشتقّ من السنة إن تُرك"><Input value={newName} onChange={(e) => setNewName(e.target.value)} /></Field>
                    </div>
                    <DialogFooter>
                        <Button onClick={() => router.post(route('admin.management.budgets.store'), { fiscal_year: Number(newYear), name: newName || null }, { onSuccess: () => setCreating(false) })}>{t('إنشاء')}</Button>
                        <Button variant="outline" onClick={() => setCreating(false)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={revising} onOpenChange={setRevising}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{t('تنقيح الموازنة')}</DialogTitle><DialogDescription>{t('تُنسخ المعتمَدةُ نسخةً جديدةً مسودّةً بسببٍ مكتوب — والمعتمَدةُ تبقى كما هي حتّى تُعتمد الجديدة')}</DialogDescription></DialogHeader>
                    <div className="px-5 pb-5"><Field label="سبب التنقيح" required><Textarea rows={3} value={reason} onChange={(e) => setReason(e.target.value)} /></Field></div>
                    <DialogFooter>
                        <Button disabled={reason.trim() === ''} onClick={() => router.post(route('admin.management.budgets.revise', s!.id), { reason }, { onSuccess: () => { setRevising(false); setReason(''); } })}>{t('إنشاء النسخة')}</Button>
                        <Button variant="outline" onClick={() => setRevising(false)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {dialog}
        </AdminLayout>
    );
}
