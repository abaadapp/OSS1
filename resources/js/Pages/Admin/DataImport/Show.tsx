import { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Check, FlaskConical, Play, RefreshCw, ShieldCheck, Undo2, X } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import Field, { Select } from '@/Components/Field';
import Tabs from '@/Components/Tabs';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useConfirm } from '@/Components/ConfirmDialog';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import {
    DATASET_STATUS_LABEL,
    datasetTone,
    KIND_LABEL,
    MATCH_LABEL,
    OUTCOME_LABEL,
    outcomeTone,
    ROLLBACK_VERDICT_LABEL,
    RUN_KIND_LABEL,
    RUN_STATUS_LABEL,
    STEPS,
    stepOf,
} from '@/lib/imports';
import type { PageProps } from '@/types';

interface FieldDef { key: string; type: string; required: boolean; label: string; values: string[] | null; ref: string | null }
interface MappingColumn { source: string; target: string; transforms: string[]; map: Record<string, string>; default: string | null }
interface Mapping { columns: MappingColumn[]; constants: Record<string, string> }
interface ProfileColumn { name: string; kind: string; filled: number; empty_percent: number; distinct: number; formulas: number }
interface Totals { rows: number; units: number; created: number; linked: number; skipped: number; conflicts: number; errors: number; warnings: number }
interface Finding { row: number; level: string; outcome: string; match: string | null; candidates: number[]; messages: string[] }
interface Run {
    id: number; kind: string; status: string; by: string | null; started_at: string | null; finished_at: string | null;
    totals: Totals | Record<string, number> | null; financial: Record<string, number> | null; findings: Finding[];
    reconciliation: Reconciliation | null; error: string | null; mapping_version: number; strategy: string | null;
}
interface Reconciliation {
    source_rows: number; source_units: number; created: number; linked: number; skipped: number; conflicts: number; errors: number;
    destination_created: number; destination_linked: number; balanced: boolean;
    financial: Record<string, number> | null;
}
interface Conflict { row: number; source_ref: string; match: string | null; messages: string[]; decision: string | null; candidates: { id: number; label: string }[] }
interface Dataset {
    id: number; code: string; name: string; category: string; category_label: string; financial: boolean; rows: number | null;
    status: string; strategy: string | null; uploaded_at: string | null; uploaded_by: string | null; source: string | null;
    original_filename: string; extension: string; size: number; checksum: string; columns: string[]; column_count: number | null;
    profile: { columns: ProfileColumn[]; duplicates: { column: string; repeated_values: number; kind: string }[]; formulas: number; probed: number } | null;
    sample: (string | number | null)[][]; mapping: Mapping | null; mapping_version: number; template: { id: number; name: string; version: number } | null;
    validation: { totals: Totals; findings: Finding[]; at: string } | null; resolutions: Record<string, string>;
    approved_at: string | null; approved_by: string | null; approval_stands: boolean; imported_at: string | null; imported_by: string | null;
    reconciliation: Reconciliation | null; rollback: { at: string; by: string | null; reason: string; counts: Record<string, number>; remaining: number } | null;
    notes: string | null; is_open: boolean; is_imported: boolean;
}
interface Props {
    dataset: Dataset;
    fields: FieldDef[];
    transforms: string[];
    strategies: { value: string; label: string }[];
    templates: { id: number; name: string; version: number }[];
    dry_run: Run | null;
    import_run: Run | null;
    conflicts: Conflict[];
    runs: Run[];
    rollback_eligibility: { eligible: boolean; reversible: number; counts: Record<string, number>; items: { row: number; source_ref: string; type: string; id: number; verdict: string }[] } | null;
    activity: { at: string | null; user: string | null; text: string }[];
    cutover: string | null;
    may: { manage: boolean; execute: boolean; financial: boolean; rollback: boolean };
}

/**
 * مجموعةُ بياناتٍ واحدة — المعالجُ بخطواته العشر، ولا خطوةَ تُقفَز.
 *
 * التبويباتُ هي الخطوات: نظرة → معاينة → ربط → فحص → تكرارات → تجربة →
 * استيراد → مطابقة → سجلّ. والخادمُ يملك الحال: الزرُّ يُرسم حين تسمح
 * الحالُ، ويردّ الخادمُ ما لا تسمح به ولو رُسم.
 */
export default function DataImportShow({ dataset: d, fields, transforms, strategies, templates, dry_run, import_run, conflicts, runs, rollback_eligibility, activity, cutover, may }: Props) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const m = (v: number | null | undefined) => (v === null || v === undefined ? '—' : money(v, context!.currency));
    const [tab, setTab] = useState(() => ({ needs_mapping: 'mapping', validation_failed: 'validation', needs_review: 'duplicates', ready: 'dry_run', approved: 'import', imported: 'reconciliation', verified: 'reconciliation' })[d.status] ?? 'overview');
    const step = stepOf(d.status);
    const [ask, confirmDialog] = useConfirm();

    const post = (name: string, data: Record<string, string> = {}) => router.post(route(`admin.imports.${name}`, d.id), data, { preserveScroll: true });

    const tabs = [
        { key: 'overview', label: 'نظرة' },
        { key: 'preview', label: 'معاينة' },
        { key: 'mapping', label: 'الربط', alert: d.status === 'needs_mapping' },
        { key: 'validation', label: 'الفحص', alert: d.status === 'validation_failed', count: d.validation?.totals.errors },
        { key: 'duplicates', label: 'التكرارات', alert: d.status === 'needs_review', count: conflicts.length },
        { key: 'dry_run', label: 'التجربة' },
        { key: 'import', label: 'الاستيراد' },
        { key: 'reconciliation', label: 'المطابقة' },
        { key: 'history', label: 'السجلّ' },
    ];

    return (
        <AdminLayout title={`${d.code} — ${d.name}`}>
            <BackLink routeName="admin.imports.index" href={route('admin.imports.index')} label="كل المجموعات" />
            <PageHeader
                title={`${d.code} — ${d.name}`}
                subtitle={`${d.category_label} · ${d.original_filename} · ${d.rows ?? '—'} ${t('صفّ')}`}
                actions={
                    <>
                        {may.manage && d.is_open && ['mapped', 'validation_failed', 'needs_review', 'ready', 'approved'].includes(d.status) && (
                            <Button variant="outline" onClick={() => post('dryRun')}><FlaskConical />{t('تجربة جافّة')}</Button>
                        )}
                        {may.execute && d.status === 'ready' && (
                            <Button onClick={() => post('approve')}><ShieldCheck />{t('اعتماد الاستيراد')}</Button>
                        )}
                        {may.execute && d.status === 'approved' && d.approval_stands && (
                            <Button variant="success" onClick={async () => { if (await ask({ message: 'استيرادٌ حقيقيّ يكتب في قاعدة البيانات — متابعة؟', action: 'تنفيذ الاستيراد' })) post('import'); }}><Play />{t('تنفيذ الاستيراد')}</Button>
                        )}
                    </>
                }
            />

            {errors?.import && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.import}</div>}

            {/* الخطواتُ العشر */}
            <Card className="mb-4 p-3">
                <div className="flex flex-wrap items-center gap-1 text-[11px]">
                    {STEPS.map((s, i) => (
                        <span key={s.key} className={'rounded-full px-2 py-1 ' + (i + 1 < step ? 'bg-[#ecfdf5] text-[#047857]' : i + 1 === step ? 'bg-[#eff6ff] font-semibold text-[#1d4ed8]' : 'bg-[#f4f4f5] text-[#9ca3af]')}>
                            {i + 1}. {t(s.label)}
                        </span>
                    ))}
                    <span className={'ms-auto rounded-full px-2 py-1 ' + datasetTone(d.status)}>{t(DATASET_STATUS_LABEL[d.status] ?? d.status)}</span>
                </div>
            </Card>

            <Tabs tabs={tabs} current={tab} onChange={setTab} className="mb-4" />

            {tab === 'overview' && <Overview d={d} cutover={cutover} may={may} />}
            {tab === 'preview' && <Preview d={d} />}
            {tab === 'mapping' && <MappingTab d={d} fields={fields} transforms={transforms} strategies={strategies} templates={templates} may={may} />}
            {tab === 'validation' && <ValidationTab d={d} run={dry_run} />}
            {tab === 'duplicates' && <DuplicatesTab d={d} conflicts={conflicts} may={may} />}
            {tab === 'dry_run' && <RunCard run={dry_run} title="آخر تجربة" m={m} financial={d.financial} />}
            {tab === 'import' && <ImportTab d={d} run={import_run} eligibility={rollback_eligibility} may={may} m={m} ask={ask} />}
            {tab === 'reconciliation' && <ReconciliationTab d={d} m={m} />}
            {tab === 'history' && <HistoryTab runs={runs} activity={activity} />}
            {confirmDialog}
        </AdminLayout>
    );
}

/* ─────────────────────────── نظرة ─────────────────────────── */

function Overview({ d, cutover, may }: { d: Dataset; cutover: string | null; may: Props['may'] }) {
    const t = useTranslate();
    const [reason, setReason] = useState('');
    const [cancelling, setCancelling] = useState(false);

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <Card className="p-4">
                <h2 className="mb-3 text-[15px] font-semibold">{t('ما هذه البيانات؟')}</h2>
                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-[13px]">
                    <Row label="الرمز" value={d.code} ltr />
                    <Row label="الصنف" value={d.category_label + (d.financial ? ' — ' + t('ماليّ') : '')} />
                    <Row label="الملفّ" value={`${d.original_filename} (${(d.size / 1024).toFixed(1)} KB)`} />
                    <Row label="البصمة" value={d.checksum.slice(0, 16) + '…'} ltr />
                    <Row label="الصفوف × الأعمدة" value={`${d.rows ?? '—'} × ${d.column_count ?? '—'}`} ltr />
                    <Row label="المصدر" value={d.source ?? '—'} />
                    <Row label="رُفع" value={`${d.uploaded_at ?? ''} ${d.uploaded_by ?? ''}`} />
                    <Row label="إصدار الخريطة" value={String(d.mapping_version)} ltr />
                    <Row label="الاستراتيجيّة" value={d.strategy === 'opening_balance' ? t('رصيد افتتاحي فقط') : d.strategy === 'historical' ? t('مستندات كاملة (تاريخي)') : '—'} />
                    <Row label="تاريخ التحويل" value={cutover ?? '—'} ltr />
                    <Row label="الاعتماد" value={d.approved_at ? `${d.approved_at} — ${d.approved_by ?? ''}${d.approval_stands ? '' : ' (' + t('سقط') + ')'}` : '—'} />
                    <Row label="الاستيراد" value={d.imported_at ? `${d.imported_at} — ${d.imported_by ?? ''}` : '—'} />
                </dl>
                {d.notes && <p className="mt-3 whitespace-pre-line text-[12px] text-[#6b7280]">{d.notes}</p>}
            </Card>
            <Card className="p-4">
                <h2 className="mb-3 text-[15px] font-semibold">{t('الأعمدة كما قُرئت')}</h2>
                <p className="mb-2 text-[12px] text-[#9ca3af]">{t('الظنُّ في النوع إشارةٌ للربط لا قرار — أنت من يقرّر ماذا يعني كلُّ عمود.')}</p>
                <table className="w-full text-[12px]">
                    <thead className="text-[#6b7280]"><tr><th className="py-1 text-start">{t('العمود')}</th><th className="py-1">{t('يبدو')}</th><th className="py-1">{t('فارغ %')}</th><th className="py-1">{t('مميّز')}</th></tr></thead>
                    <tbody>
                        {(d.profile?.columns ?? []).map((c) => (
                            <tr key={c.name} className="border-t border-[#f4f4f5]">
                                <td className="py-1 font-medium">{c.name}{c.formulas > 0 && <span className="ms-1 text-[10px] text-[#b45309]">{t('معادلات')}: {c.formulas}</span>}</td>
                                <td className="py-1 text-center">{t(KIND_LABEL[c.kind] ?? c.kind)}</td>
                                <td className="py-1 text-center tabular-nums">{c.empty_percent}%</td>
                                <td className="py-1 text-center tabular-nums">{c.distinct}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {(d.profile?.duplicates ?? []).length > 0 && (
                    <p className="mt-2 text-[12px] text-[#b45309]">{t('قيمٌ مكرَّرة في')}: {d.profile!.duplicates.map((x) => `${x.column} (${x.repeated_values})`).join(' · ')}</p>
                )}
                {may.manage && d.is_open && (
                    <div className="mt-4 flex gap-2">
                        <Button variant="outline" size="sm" onClick={() => router.post(route('admin.imports.profile', d.id), {}, { preserveScroll: true })}><RefreshCw />{t('إعادة القراءة')}</Button>
                        <Button variant="outline" size="sm" className="text-[#b91c1c]" onClick={() => setCancelling(true)}><X />{t('إلغاء المجموعة')}</Button>
                    </div>
                )}
                <Dialog open={cancelling} onOpenChange={(v) => !v && setCancelling(false)}>
                    <DialogContent>
                        <DialogHeader><DialogTitle>{t('إلغاء المجموعة')}</DialogTitle></DialogHeader>
                        <div className="px-5 pb-5"><Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder={t('السبب')} /></div>
                        <DialogFooter>
                            <Button variant="danger" disabled={reason.trim() === ''} onClick={() => router.post(route('admin.imports.cancel', d.id), { reason })}>{t('إلغاء المجموعة')}</Button>
                            <Button variant="outline" onClick={() => setCancelling(false)}>{t('رجوع')}</Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </Card>
        </div>
    );
}

function Row({ label, value, ltr }: { label: string; value: string; ltr?: boolean }) {
    const t = useTranslate();

    return (
        <>
            <dt className="text-[#6b7280]">{t(label)}</dt>
            <dd className="font-medium" dir={ltr ? 'ltr' : undefined}>{value}</dd>
        </>
    );
}

/* ─────────────────────────── معاينة ─────────────────────────── */

function Preview({ d }: { d: Dataset }) {
    const t = useTranslate();
    const [page, setPage] = useState(1);
    const [rows, setRows] = useState<{ number: number; cells: (string | number | null)[] }[] | null>(null);
    const [total, setTotal] = useState(d.rows ?? 0);

    useEffect(() => {
        let alive = true;
        fetch(route('admin.imports.preview', { id: d.id, page, per_page: 50 }), { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((j) => { if (alive) { setRows(j.rows ?? []); setTotal(j.total ?? 0); } })
            .catch(() => alive && setRows([]));
        return () => { alive = false; };
    }, [d.id, page]);

    return (
        <Card className="overflow-x-auto p-4">
            <p className="mb-2 text-[12px] text-[#9ca3af]">{t('خمسون صفًّا في الصفحة — والهاتفُ والبريدُ مقنَّعان لمن لا يملك الإدارة. الملفُّ لا يُحمَّل كلُّه.')}</p>
            <table className="w-full text-[12px]">
                <thead className="bg-[#fafafa] text-[#6b7280]"><tr><th className="px-2 py-1">#</th>{d.columns.map((c) => <th key={c} className="px-2 py-1 text-start">{c}</th>)}</tr></thead>
                <tbody>
                    {rows === null && <tr><td colSpan={d.columns.length + 1} className="py-6 text-center text-[#9ca3af]">{t('يُقرأ…')}</td></tr>}
                    {rows?.map((r) => (
                        <tr key={r.number} className="border-t border-[#f4f4f5]">
                            <td className="px-2 py-1 tabular-nums text-[#9ca3af]">{r.number}</td>
                            {r.cells.map((v, i) => <td key={i} className="max-w-[240px] truncate px-2 py-1">{v === null ? '' : String(v)}</td>)}
                        </tr>
                    ))}
                </tbody>
            </table>
            <div className="mt-2 flex items-center justify-between text-[12px] text-[#6b7280]">
                <span>{t(':n صفًّا', { n: total })}</span>
                <div className="flex gap-2">
                    <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('السابق')}</Button>
                    <Button variant="outline" size="sm" disabled={page * 50 >= total} onClick={() => setPage(page + 1)}>{t('التالي')}</Button>
                </div>
            </div>
        </Card>
    );
}

/* ─────────────────────────── الربط ─────────────────────────── */

function MappingTab({ d, fields, transforms, strategies, templates, may }: { d: Dataset; fields: FieldDef[]; transforms: string[]; strategies: Props['strategies']; templates: Props['templates']; may: Props['may'] }) {
    const t = useTranslate();
    const editable = may.manage && d.is_open;
    const [columns, setColumns] = useState<MappingColumn[]>(() =>
        d.columns.map((source) => d.mapping?.columns.find((c) => c.source === source) ?? { source, target: '', transforms: [], map: {}, default: null }),
    );
    const [constants, setConstants] = useState<Record<string, string>>(d.mapping?.constants ?? {});
    const [templateName, setTemplateName] = useState('');
    const [templateId, setTemplateId] = useState('');
    const [strategy, setStrategy] = useState(d.strategy ?? '');
    const targets = new Set(columns.filter((c) => c.target).map((c) => c.target));
    const missing = fields.filter((f) => f.required && !targets.has(f.key) && !(f.key in constants));
    const patch = (i: number, p: Partial<MappingColumn>) => setColumns((rows) => rows.map((r, k) => (k === i ? { ...r, ...p } : r)));

    const save = () =>
        router.put(route('admin.imports.mapping', d.id), {
            columns: columns.filter((c) => c.target !== '').map((c) => ({ source: c.source, target: c.target, transforms: c.transforms, map: c.map, default: c.default })),
            constants,
        }, { preserveScroll: true });

    return (
        <div className="space-y-4">
            <Card className="p-4">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-[15px] font-semibold">{t('الخطوة 3 — ربط الأعمدة بحقول Event Plus')}</h2>
                    {missing.length > 0 ? (
                        <span className="text-[12px] text-[#b91c1c]">{t('إلزاميٌّ لم يُربط')}: {missing.map((f) => t(f.label)).join(' · ')}</span>
                    ) : (
                        <span className="text-[12px] text-[#047857]">{t('كل الإلزاميّ مربوط')}</span>
                    )}
                </div>
                <p className="mb-3 text-[12px] text-[#9ca3af]">{t('الحقولُ من قائمةٍ مغلقة لا جداول. والتحويلاتُ بأسمائها: قصٌّ، توحيدُ فراغ، بريدٌ صغير، هاتفٌ دوليّ، تاريخ، مبلغ، خريطةُ قيم.')}</p>
                <table className="w-full text-[13px]">
                    <thead className="text-[12px] text-[#6b7280]"><tr><th className="py-1 text-start">{t('عمود الملفّ')}</th><th className="py-1 text-start">{t('الحقل الهدف')}</th><th className="py-1 text-start">{t('التحويلات')}</th><th className="py-1 text-start">{t('خريطة القيم')}</th></tr></thead>
                    <tbody>
                        {columns.map((c, i) => (
                            <tr key={c.source} className="border-t border-[#f4f4f5] align-top">
                                <td className="py-2 font-medium">{c.source}</td>
                                <td className="py-2">
                                    <Select value={c.target} placeholder="لا يُربط" disabled={!editable} onChange={(e) => patch(i, { target: e.target.value })}
                                        options={fields.map((f) => ({ value: f.key, label: `${t(f.label)}${f.required ? ' *' : ''} (${f.type})`, disabled: targets.has(f.key) && c.target !== f.key }))} />
                                </td>
                                <td className="py-2">
                                    <div className="flex flex-wrap gap-1">
                                        {transforms.filter((x) => !['map', 'default'].includes(x)).map((x) => (
                                            <button key={x} type="button" disabled={!editable} onClick={() => patch(i, { transforms: c.transforms.includes(x) ? c.transforms.filter((y) => y !== x) : [...c.transforms, x] })}
                                                className={'rounded-full px-2 py-0.5 text-[11px] ' + (c.transforms.includes(x) ? 'bg-[#1d4ed8] text-white' : 'bg-[#f4f4f5] text-[#6b7280]')}>{x}</button>
                                        ))}
                                    </div>
                                </td>
                                <td className="py-2">
                                    <Input disabled={!editable} placeholder={t('تمّ=issued, ملغى=cancelled')} value={Object.entries(c.map ?? {}).map(([k, v]) => `${k}=${v}`).join(', ')}
                                        onChange={(e) => {
                                            const map: Record<string, string> = {};
                                            e.target.value.split(',').forEach((pair) => { const [k, v] = pair.split('='); if (k?.trim() && v !== undefined) map[k.trim()] = v.trim(); });
                                            patch(i, { map, transforms: Object.keys(map).length > 0 && !c.transforms.includes('map') ? [...c.transforms, 'map'] : c.transforms });
                                        }} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <div className="mt-3 grid gap-3 sm:grid-cols-2">
                    {fields.filter((f) => f.type === 'enum' && !targets.has(f.key)).map((f) => (
                        <Field key={f.key} label={f.label} hint="قيمةٌ ثابتة لكلّ الصفوف — لما ليس في الملفّ">
                            <Select value={constants[f.key] ?? ''} placeholder="—" disabled={!editable} onChange={(e) => setConstants((k) => { const n = { ...k }; if (e.target.value) n[f.key] = e.target.value; else delete n[f.key]; return n; })}
                                options={(f.values ?? []).map((v) => ({ value: v, label: v }))} />
                        </Field>
                    ))}
                </div>
                {editable && (
                    <div className="mt-4 flex flex-wrap items-center gap-2">
                        <Button onClick={save}><Check />{t('حفظ الخريطة')}</Button>
                        <Input className="max-w-[220px]" value={templateName} onChange={(e) => setTemplateName(e.target.value)} placeholder={t('اسم القالب')} />
                        <Button variant="outline" disabled={templateName.trim() === '' || !d.mapping} onClick={() => router.post(route('admin.imports.templates.store', d.id), { name: templateName })}>{t('حفظ قالبًا')}</Button>
                        {templates.length > 0 && (
                            <>
                                <Select className="max-w-[220px]" value={templateId} placeholder="تطبيق قالب" onChange={(e) => setTemplateId(e.target.value)} options={templates.map((x) => ({ value: x.id, label: `${x.name} (v${x.version})` }))} />
                                <Button variant="outline" disabled={templateId === ''} onClick={() => router.post(route('admin.imports.templates.apply', d.id), { template_id: Number(templateId) })}>{t('تطبيق')}</Button>
                            </>
                        )}
                    </div>
                )}
            </Card>

            {d.financial && (
                <Card className="p-4">
                    <h2 className="mb-1 text-[15px] font-semibold">{t('الاستراتيجيّة الماليّة')}</h2>
                    <p className="mb-3 text-[12px] text-[#9ca3af]">{t('مستنداتٌ كاملة تكتب قيودَها بتاريخها، أو رصيدٌ افتتاحيٌّ يومَ التحويل — لا الاثنان للحقيقة الواحدة.')}</p>
                    <div className="flex items-center gap-2">
                        <Select className="max-w-[280px]" value={strategy} placeholder="اختر" disabled={!editable} onChange={(e) => setStrategy(e.target.value)} options={strategies} />
                        {editable && <Button variant="outline" onClick={() => router.put(route('admin.imports.strategy', d.id), { strategy: strategy || null }, { preserveScroll: true })}>{t('حفظ')}</Button>}
                    </div>
                </Card>
            )}
        </div>
    );
}

/* ─────────────────────────── الفحص ─────────────────────────── */

function ValidationTab({ d, run }: { d: Dataset; run: Run | null }) {
    const t = useTranslate();
    const v = d.validation;

    if (!v) return <Card className="p-6 text-center text-[13px] text-[#9ca3af]">{t('لم تُجرَّب المجموعة بعد — اربط الأعمدة ثمّ «تجربة جافّة».')}</Card>;

    return (
        <div className="space-y-4">
            <Card className="p-4">
                <div className="grid grid-cols-2 gap-3 text-center sm:grid-cols-5">
                    {[['rows', 'صفوف'], ['created', 'سيُنشأ'], ['linked', 'سيُربط'], ['skipped', 'سيُتخطّى'], ['conflicts', 'تعارضات'], ['errors', 'أخطاء'], ['warnings', 'تنبيهات']].map(([k, label]) => (
                        <div key={k} className="rounded-lg bg-[#fafafa] p-3">
                            <div className={'text-[20px] font-bold tabular-nums ' + (k === 'errors' && v.totals.errors > 0 ? 'text-[#b91c1c]' : k === 'conflicts' && v.totals.conflicts > 0 ? 'text-[#b45309]' : '')}>{(v.totals as unknown as Record<string, number>)[k] ?? 0}</div>
                            <div className="text-[11px] text-[#6b7280]">{t(label)}</div>
                        </div>
                    ))}
                </div>
                <p className="mt-2 text-[11px] text-[#9ca3af]" dir="ltr">{v.at}{run?.error ? ` — ${run.error}` : ''}</p>
            </Card>
            <Card className="p-4">
                <h2 className="mb-2 text-[15px] font-semibold">{t('ما وجده الفحص')}</h2>
                <p className="mb-2 text-[12px] text-[#9ca3af]">{t('الخطأ يمنع الاستيراد حتى يُصلَح في الملفّ أو الخريطة. والتنبيه يُقرأ ولا يمنع.')}</p>
                <Findings findings={v.findings} />
            </Card>
        </div>
    );
}

function Findings({ findings }: { findings: Finding[] }) {
    const t = useTranslate();
    if (findings.length === 0) return <p className="text-[13px] text-[#047857]">{t('لا أخطاء ولا تعارضات.')}</p>;

    return (
        <ul className="divide-y divide-[#f4f4f5] text-[13px]">
            {findings.map((f) => (
                <li key={f.row} className="flex gap-3 py-2">
                    <span className="w-16 shrink-0 tabular-nums text-[#9ca3af]">#{f.row}</span>
                    <span className={'shrink-0 rounded-full px-2 py-0.5 text-[11px] ' + outcomeTone(f.outcome)}>{t(OUTCOME_LABEL[f.outcome] ?? f.outcome)}</span>
                    <span className="min-w-0">{f.messages.join(' · ') || (f.match ? t(MATCH_LABEL[f.match] ?? f.match) : '')}</span>
                </li>
            ))}
        </ul>
    );
}

/* ─────────────────────────── التكرارات ─────────────────────────── */

function DuplicatesTab({ d, conflicts, may }: { d: Dataset; conflicts: Conflict[]; may: Props['may'] }) {
    const t = useTranslate();
    const [decisions, setDecisions] = useState<Record<string, string>>(() => Object.fromEntries(conflicts.map((c) => [String(c.row), c.decision ?? ''])));
    const editable = may.manage && d.is_open;

    if (conflicts.length === 0) return <Card className="p-6 text-center text-[13px] text-[#9ca3af]">{t('لا تكراراتٍ تنتظر قرارًا في آخر تجربة.')}</Card>;

    return (
        <Card className="p-4">
            <h2 className="mb-1 text-[15px] font-semibold">{t('الخطوة 5 — قرّر في كلّ تطابقٍ محتمَل')}</h2>
            <p className="mb-3 text-[12px] text-[#9ca3af]">{t('لا دمجَ بصمت: أنشئ جديدًا، أو اربط بسجلٍّ قائم، أو تخطَّ الصفّ. ثمّ أعد التجربة.')}</p>
            <table className="w-full text-[13px]">
                <thead className="text-[12px] text-[#6b7280]"><tr><th className="py-1 text-start">{t('الصفّ')}</th><th className="py-1 text-start">{t('السبب')}</th><th className="py-1 text-start">{t('القرار')}</th></tr></thead>
                <tbody>
                    {conflicts.map((c) => (
                        <tr key={c.row} className="border-t border-[#f4f4f5]">
                            <td className="py-2 tabular-nums" dir="ltr">{c.source_ref}</td>
                            <td className="py-2">{c.match ? t(MATCH_LABEL[c.match] ?? c.match) : ''} — {c.messages.join(' · ')}</td>
                            <td className="py-2">
                                <Select value={decisions[String(c.row)] ?? ''} placeholder="لم يُقرَّر" disabled={!editable} onChange={(e) => setDecisions((k) => ({ ...k, [String(c.row)]: e.target.value }))}
                                    options={[{ value: 'create', label: t('إنشاء جديد') }, { value: 'skip', label: t('تخطٍّ') }, ...c.candidates.map((x) => ({ value: `link:${x.id}`, label: `${t('ربط بـ')} ${x.label}` }))]} />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            {editable && (
                <div className="mt-3 flex gap-2">
                    <Button onClick={() => router.put(route('admin.imports.resolve', d.id), { decisions: Object.fromEntries(Object.entries(decisions).filter(([, v]) => v !== '')) }, { preserveScroll: true })}><Check />{t('حفظ القرارات')}</Button>
                    <Button variant="outline" onClick={() => setDecisions((k) => Object.fromEntries(conflicts.map((c) => [String(c.row), c.match === 'exact' ? 'skip' : k[String(c.row)] ?? ''])))}>{t('تخطّي كلّ التامّ')}</Button>
                </div>
            )}
        </Card>
    );
}

/* ─────────────────────────── تنفيذ ─────────────────────────── */

function RunCard({ run, title, m, financial }: { run: Run | null; title: string; m: (v: number | null | undefined) => string; financial: boolean }) {
    const t = useTranslate();
    if (!run) return <Card className="p-6 text-center text-[13px] text-[#9ca3af]">{t('لا تنفيذَ بعد.')}</Card>;
    const totals = (run.totals ?? {}) as Record<string, number>;
    const f = run.financial;

    return (
        <Card className="p-4">
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-[15px] font-semibold">{t(title)} — {t(RUN_KIND_LABEL[run.kind] ?? run.kind)} #{run.id}</h2>
                <span className={'rounded-full px-2 py-1 text-[11px] ' + (run.status === 'success' ? 'bg-[#ecfdf5] text-[#047857]' : run.status === 'partial' ? 'bg-[#fffbeb] text-[#b45309]' : 'bg-[#fef2f2] text-[#b91c1c]')}>{t(RUN_STATUS_LABEL[run.status] ?? run.status)}</span>
            </div>
            <p className="mb-3 text-[12px] text-[#9ca3af]" dir="ltr">{run.started_at} → {run.finished_at ?? '…'} {run.by ? `· ${run.by}` : ''} · v{run.mapping_version}</p>
            <div className="grid grid-cols-2 gap-3 text-center sm:grid-cols-5">
                {[['created', 'أُنشئ/سيُنشأ'], ['linked', 'رُبط/سيُربط'], ['skipped', 'تُخطّي'], ['conflicts', 'تعارضات'], ['errors', 'أخطاء']].map(([k, label]) => (
                    <div key={k} className="rounded-lg bg-[#fafafa] p-3"><div className="text-[20px] font-bold tabular-nums">{totals[k] ?? 0}</div><div className="text-[11px] text-[#6b7280]">{t(label)}</div></div>
                ))}
            </div>
            {financial && f && (
                <div className="mt-4">
                    <h3 className="mb-1 text-[13px] font-semibold">{t('الأثر الماليّ')}</h3>
                    <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-[13px] sm:grid-cols-4">
                        {[['receivables', 'ذمم العملاء'], ['payables', 'ذمم الموردين'], ['revenue', 'الإيراد'], ['tax', 'الضريبة'], ['collections', 'التحصيلات'], ['expenses', 'المصروفات'], ['supplier_cost', 'تكلفة الموردين'], ['opening_receivables', 'أرصدة عملاء افتتاحية'], ['opening_payables', 'أرصدة موردين افتتاحية']]
                            .filter(([k]) => (f[k] ?? 0) !== 0)
                            .map(([k, label]) => (
                                <div key={k} className="flex justify-between rounded bg-[#fafafa] px-2 py-1"><span className="text-[#6b7280]">{t(label)}</span><span className="tabular-nums font-semibold">{m(f[k])}</span></div>
                            ))}
                    </dl>
                </div>
            )}
            {run.error && <p className="mt-3 text-[12px] text-[#b91c1c]">{run.error}</p>}
            {run.findings.length > 0 && <div className="mt-4"><Findings findings={run.findings} /></div>}
        </Card>
    );
}

function ImportTab({ d, run, eligibility, may, m, ask }: { d: Dataset; run: Run | null; eligibility: Props['rollback_eligibility']; may: Props['may']; m: (v: number | null | undefined) => string; ask: (o: { message: string; danger?: boolean; action?: string }) => Promise<boolean> }) {
    const t = useTranslate();
    const [reason, setReason] = useState('');

    return (
        <div className="space-y-4">
            <Card className="p-4">
                <h2 className="mb-1 text-[15px] font-semibold">{t('الخطوة 8 و9 — الاعتماد ثمّ التنفيذ')}</h2>
                <p className="text-[12px] text-[#9ca3af]">{t('الاعتماد بصمةٌ على الملفّ والخريطة والاستراتيجيّة والقرارات والتجربة. تغيّر أحدُها سقط الاعتماد وعادت المجموعة إلى التجربة.')}</p>
                <div className="mt-3 text-[13px]">
                    {d.status === 'approved' && d.approval_stands && <span className="text-[#047857]">{t('معتمَدة — التنفيذ من زرّ «تنفيذ الاستيراد» أعلى الشاشة')}</span>}
                    {d.status === 'ready' && <span className="text-[#1d4ed8]">{t('جاهزة للاعتماد')}{d.financial && !may.financial ? ' — ' + t('يعتمدها من يملك الاستيراد الماليّ') : ''}</span>}
                    {d.is_imported && <span className="text-[#047857]">{t('استُوردت في')} {d.imported_at} — {d.imported_by}</span>}
                    {['needs_mapping', 'mapped', 'validation_failed', 'needs_review'].includes(d.status) && <span className="text-[#6b7280]">{t('لم تصل بعدُ: أكمل الربط والفحص والتجربة أوّلًا.')}</span>}
                </div>
            </Card>
            {run && <RunCard run={run} title="الاستيراد" m={m} financial={d.financial} />}
            {d.is_imported && (
                <Card className="p-4">
                    <h2 className="mb-1 text-[15px] font-semibold">{t('التراجع')}</h2>
                    {d.rollback ? (
                        <p className="text-[13px]">{t('نُفّذ في')} {d.rollback.at} — {d.rollback.by} — {d.rollback.reason} · {Object.entries(d.rollback.counts).map(([k, v]) => `${k}: ${v}`).join(' · ')}</p>
                    ) : eligibility ? (
                        <>
                            <p className="mb-2 text-[12px] text-[#9ca3af]">{t('ما أُنشئ ولم يُستعمل يُزال. والمستندُ الماليّ يُلغى من بابه بقيدٍ عكسيّ لا يُحذف. وما استُعمل يبقى. وما سُدّد منه غيرُ قابل.')}</p>
                            <div className="mb-3 flex flex-wrap gap-2 text-[12px]">
                                {Object.entries(eligibility.counts).map(([k, v]) => v > 0 && <span key={k} className="rounded-full bg-[#f4f4f5] px-2 py-1">{t(ROLLBACK_VERDICT_LABEL[k] ?? k)}: {v}</span>)}
                            </div>
                            {!eligibility.eligible && <p className="mb-2 text-[12px] text-[#b91c1c]">{t('غيرُ قابلٍ للتراجع الكامل — ما لا يقبل يبقى ويُقال في التقرير.')}</p>}
                            {may.rollback && (
                                <div className="flex gap-2">
                                    <Input className="max-w-[320px]" value={reason} onChange={(e) => setReason(e.target.value)} placeholder={t('سبب التراجع')} />
                                    <Button variant="outline" className="text-[#b91c1c]" disabled={reason.trim() === ''} onClick={async () => { if (await ask({ message: 'تراجعٌ عن استيراد — متابعة؟', danger: true, action: 'تنفيذ التراجع' })) router.post(route('admin.imports.rollback', d.id), { reason }); }}><Undo2 />{t('تنفيذ التراجع')}</Button>
                                </div>
                            )}
                        </>
                    ) : <p className="text-[12px] text-[#9ca3af]">{t('التراجعُ لمن يملك صلاحيّته.')}</p>}
                </Card>
            )}
        </div>
    );
}

/* ─────────────────────────── المطابقة ─────────────────────────── */

function ReconciliationTab({ d, m }: { d: Dataset; m: (v: number | null | undefined) => string }) {
    const t = useTranslate();
    const r = d.reconciliation;
    if (!r) return <Card className="p-6 text-center text-[13px] text-[#9ca3af]">{t('لا مطابقةَ قبل الاستيراد.')}</Card>;
    const f = r.financial;

    return (
        <div className="space-y-4">
            <Card className="p-4">
                <div className="mb-3 flex items-center justify-between">
                    <h2 className="text-[15px] font-semibold">{t('الخطوة 10 — هل ما دخل هو ما كان في الملفّ؟')}</h2>
                    <span className={'rounded-full px-2 py-1 text-[11px] ' + (r.balanced ? 'bg-[#ecfdf5] text-[#047857]' : 'bg-[#fef2f2] text-[#b91c1c]')}>{r.balanced ? t('متوازنة') : t('فرق')}</span>
                </div>
                <dl className="grid grid-cols-2 gap-2 text-[13px] sm:grid-cols-4">
                    {[['source_rows', 'صفوف المصدر'], ['source_units', 'وحدات المصدر'], ['created', 'أُنشئ'], ['linked', 'رُبط'], ['skipped', 'تُخطّي'], ['conflicts', 'تعارضات'], ['errors', 'أخطاء'], ['destination_created', 'في الوجهة (أُنشئ)'], ['destination_linked', 'في الوجهة (رُبط)']].map(([k, label]) => (
                        <div key={k} className="rounded bg-[#fafafa] px-3 py-2"><div className="text-[11px] text-[#6b7280]">{t(label)}</div><div className="text-[16px] font-bold tabular-nums">{(r as unknown as Record<string, number>)[k]}</div></div>
                    ))}
                </dl>
            </Card>
            {f && (
                <Card className="p-4">
                    <h2 className="mb-3 text-[15px] font-semibold">{t('المال — المصدر مقابل المستورَد')}</h2>
                    <table className="w-full text-[13px]">
                        <tbody>
                            {[['source_total', 'إجمالي المصدر'], ['imported_total', 'إجمالي المستورَد'], ['difference', 'الفرق'], ['source_tax', 'ضريبة المصدر'], ['imported_tax', 'ضريبة المستورَد'], ['tax_difference', 'فرق الضريبة'], ['source_outstanding', 'باقي المصدر'], ['imported_outstanding', 'باقي المستورَد']]
                                .filter(([k]) => k in f)
                                .map(([k, label]) => (
                                    <tr key={k} className="border-t border-[#f4f4f5]"><td className="py-1 text-[#6b7280]">{t(label)}</td><td className={'py-1 text-end tabular-nums font-semibold ' + (k.includes('difference') && Math.abs(f[k]) > 0.0005 ? 'text-[#b91c1c]' : '')}>{m(f[k])}</td></tr>
                                ))}
                        </tbody>
                    </table>
                </Card>
            )}
            {d.is_imported && (
                <a href={route('admin.imports.report', d.id)} className="inline-flex text-[13px] text-[#1d4ed8] underline">{t('تنزيل تقرير الاستيراد (Markdown — بلا بيانات أشخاص)')}</a>
            )}
        </div>
    );
}

/* ─────────────────────────── السجلّ ─────────────────────────── */

function HistoryTab({ runs, activity }: { runs: Run[]; activity: Props['activity'] }) {
    const t = useTranslate();

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <Card className="p-4">
                <h2 className="mb-2 text-[15px] font-semibold">{t('التنفيذات')}</h2>
                <ul className="divide-y divide-[#f4f4f5] text-[13px]">
                    {runs.map((r) => (
                        <li key={r.id} className="flex flex-wrap items-center gap-2 py-2">
                            <span className="tabular-nums text-[#9ca3af]">#{r.id}</span>
                            <span>{t(RUN_KIND_LABEL[r.kind] ?? r.kind)}</span>
                            <span className={'rounded-full px-2 py-0.5 text-[11px] ' + (r.status === 'success' ? 'bg-[#ecfdf5] text-[#047857]' : r.status === 'partial' ? 'bg-[#fffbeb] text-[#b45309]' : 'bg-[#fef2f2] text-[#b91c1c]')}>{t(RUN_STATUS_LABEL[r.status] ?? r.status)}</span>
                            <span className="text-[12px] text-[#6b7280]" dir="ltr">{r.finished_at ?? r.started_at} {r.by ? `· ${r.by}` : ''} · v{r.mapping_version}</span>
                        </li>
                    ))}
                    {runs.length === 0 && <li className="py-2 text-[#9ca3af]">{t('لا تنفيذَ بعد.')}</li>}
                </ul>
            </Card>
            <Card className="p-4">
                <h2 className="mb-2 text-[15px] font-semibold">{t('الأثر')}</h2>
                <ul className="divide-y divide-[#f4f4f5] text-[13px]">
                    {activity.map((a, i) => (
                        <li key={i} className="py-2"><span className="text-[12px] text-[#9ca3af]" dir="ltr">{a.at}</span> · {a.user ? `${a.user} — ` : ''}{a.text}</li>
                    ))}
                </ul>
            </Card>
        </div>
    );
}
