import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Database, Upload } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import { DATASET_STATUS_LABEL, datasetTone } from '@/lib/imports';

interface DatasetRow {
    id: number;
    code: string;
    name: string;
    category: string;
    category_label: string;
    financial: boolean;
    rows: number | null;
    status: string;
    status_label: string;
    strategy: string | null;
    uploaded_at: string | null;
    uploaded_by: string | null;
    validation: { errors: number; conflicts: number; warnings: number } | null;
    imported_at: string | null;
    balanced: boolean | null;
    rolled_back: boolean;
}

interface Props {
    datasets: DatasetRow[];
    pagination: { current_page: number; last_page: number; total: number };
    filters: { status?: string; category?: string };
    categories: { value: string; label: string; financial: boolean }[];
    statuses: { value: string; label: string }[];
    cutover: string | null;
    templates: { id: number; name: string; category: string; version: number }[];
    limits: { max_mb: number; max_rows: number };
    may: { manage: boolean; execute: boolean; financial: boolean; rollback: boolean };
}

/**
 * مركزُ استيراد البيانات — المجموعاتُ بأرقامها وحالها، ورفعُ ملفٍّ جديد.
 *
 * ═══ الرفعُ تسجيلٌ لا استيراد ═══
 *
 * الملفُّ المرفوع يُسجَّل برقمٍ ويُقرأ ويقف عند «تحتاج ربطًا». ولا يبلغ
 * قاعدةَ البيانات شيءٌ منه قبل أن يُربط ويُفحص ويُجرَّب ويُعتمَد بيدٍ — من
 * شاشة المجموعة.
 */
export default function DataImportIndex({ datasets, pagination, filters, categories, statuses, cutover, limits, may }: Props) {
    const t = useTranslate();
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const [uploading, setUploading] = useState(false);
    const [file, setFile] = useState<File | null>(null);
    const [form, setForm] = useState({ name: '', category: '', source: '', notes: '' });
    const [cutoverValue, setCutoverValue] = useState(cutover ?? '');

    const submit = () => {
        if (!file) return;
        const data = new FormData();
        data.append('file', file);
        Object.entries(form).forEach(([k, v]) => data.append(k, v));
        router.post(route('admin.imports.store'), data, { forceFormData: true, onSuccess: () => setUploading(false) });
    };

    const filter = (patch: Record<string, string>) =>
        router.get(route('admin.imports.index'), { ...filters, ...patch }, { preserveState: true, replace: true });

    return (
        <AdminLayout title="استيراد البيانات">
            <PageHeader
                title="استيراد البيانات"
                subtitle="البيانات التاريخية تدخل من بابٍ واحد: تسجيل → ربط → فحص → تجربة → اعتماد → استيراد → مطابقة"
                actions={
                    may.manage && (
                        <Button onClick={() => setUploading(true)}>
                            <Upload />
                            {t('رفع ملفّ')}
                        </Button>
                    )
                }
            />

            {errors?.file && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.file}</div>}
            {errors?.import && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.import}</div>}

            <div className="mb-4 grid gap-4 lg:grid-cols-3">
                <Card className="p-4 lg:col-span-2">
                    <div className="flex flex-wrap items-end gap-3">
                        <Field label="الحالة" className="min-w-[160px]">
                            <Select value={filters.status ?? ''} placeholder="كل الحالات" onChange={(e) => filter({ status: e.target.value })} options={statuses} />
                        </Field>
                        <Field label="الصنف" className="min-w-[180px]">
                            <Select value={filters.category ?? ''} placeholder="كل الأصناف" onChange={(e) => filter({ category: e.target.value })} options={categories} />
                        </Field>
                        <div className="text-[12px] text-[#9ca3af]">
                            {t('الحدّ: :mb ميغابايت · :rows صفّ · CSV أو XLSX', { mb: String(limits.max_mb), rows: limits.max_rows.toLocaleString() })}
                        </div>
                    </div>
                </Card>
                <Card className="p-4">
                    <div className="text-[13px] font-semibold">{t('تاريخ التحويل')}</div>
                    <p className="mb-2 text-[12px] text-[#9ca3af]">{t('قبله أرصدةٌ افتتاحية؛ ومنه مستنداتٌ كاملة — كي لا يُعدّ الدَّين مرّتين')}</p>
                    <div className="flex items-center gap-2">
                        <Input type="date" value={cutoverValue} onChange={(e) => setCutoverValue(e.target.value)} disabled={!may.financial} />
                        {may.financial && (
                            <Button variant="outline" size="sm" onClick={() => router.put(route('admin.imports.cutover'), { cutover: cutoverValue || null })}>
                                {t('حفظ')}
                            </Button>
                        )}
                    </div>
                </Card>
            </div>

            <Card className="overflow-hidden">
                <table className="w-full text-[13px]">
                    <thead className="bg-[#fafafa] text-[12px] text-[#6b7280]">
                        <tr>
                            <th className="px-4 py-2 text-start">{t('الرمز')}</th>
                            <th className="px-4 py-2 text-start">{t('الاسم')}</th>
                            <th className="px-4 py-2 text-start">{t('الصنف')}</th>
                            <th className="px-4 py-2 text-center">{t('الصفوف')}</th>
                            <th className="px-4 py-2 text-center">{t('الحالة')}</th>
                            <th className="px-4 py-2 text-center">{t('الفحص')}</th>
                            <th className="px-4 py-2 text-start">{t('رُفع')}</th>
                            <th className="px-4 py-2 text-start">{t('استُورد')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {datasets.length === 0 && (
                            <tr>
                                <td colSpan={8} className="px-4 py-10 text-center text-[#9ca3af]">
                                    <Database className="mx-auto mb-2 h-6 w-6" />
                                    {t('لا مجموعاتِ بيانات بعد — ارفع أوّل ملفّ')}
                                </td>
                            </tr>
                        )}
                        {datasets.map((d) => (
                            <tr key={d.id} className="border-t border-[#f4f4f5]">
                                <td className="px-4 py-2 font-mono text-[12px]">
                                    <SmartLink routeName="admin.imports.show" href={route('admin.imports.show', d.id)} className="text-[#1d4ed8]">
                                        {d.code}
                                    </SmartLink>
                                </td>
                                <td className="px-4 py-2 font-medium">{d.name}</td>
                                <td className="px-4 py-2">
                                    {d.category_label}
                                    {d.financial && <span className="ms-1 rounded-full bg-[#fffbeb] px-1.5 text-[10px] text-[#b45309]">{t('ماليّ')}</span>}
                                </td>
                                <td className="px-4 py-2 text-center tabular-nums">{d.rows ?? '—'}</td>
                                <td className="px-4 py-2 text-center">
                                    <span className={'rounded-full px-2 py-1 text-[11px] ' + datasetTone(d.status)}>{t(DATASET_STATUS_LABEL[d.status] ?? d.status)}</span>
                                    {d.rolled_back && <div className="mt-1 text-[10px] text-[#9ca3af]">{t('تُرُوجع عنه')}</div>}
                                </td>
                                <td className="px-4 py-2 text-center text-[12px]">
                                    {d.validation ? (
                                        <span>
                                            <span className={d.validation.errors > 0 ? 'text-[#b91c1c]' : 'text-[#6b7280]'}>{d.validation.errors} {t('خطأ')}</span>
                                            {' · '}
                                            <span className={d.validation.conflicts > 0 ? 'text-[#b45309]' : 'text-[#6b7280]'}>{d.validation.conflicts} {t('تعارض')}</span>
                                        </span>
                                    ) : '—'}
                                </td>
                                <td className="px-4 py-2 text-[12px] text-[#6b7280]" dir="ltr">
                                    {d.uploaded_at} {d.uploaded_by ? `· ${d.uploaded_by}` : ''}
                                </td>
                                <td className="px-4 py-2 text-[12px] text-[#6b7280]" dir="ltr">
                                    {d.imported_at ?? '—'} {d.balanced === true ? '✓' : d.balanced === false ? '✗' : ''}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {pagination.last_page > 1 && (
                    <div className="flex items-center justify-between border-t border-[#f4f4f5] px-4 py-2 text-[12px] text-[#6b7280]">
                        <span>{t('صفحة :n من :m', { n: String(pagination.current_page), m: String(pagination.last_page) })}</span>
                        <div className="flex gap-2">
                            <Button variant="outline" size="sm" disabled={pagination.current_page <= 1} onClick={() => router.get(route('admin.imports.index'), { ...filters, page: pagination.current_page - 1 })}>{t('السابق')}</Button>
                            <Button variant="outline" size="sm" disabled={pagination.current_page >= pagination.last_page} onClick={() => router.get(route('admin.imports.index'), { ...filters, page: pagination.current_page + 1 })}>{t('التالي')}</Button>
                        </div>
                    </div>
                )}
            </Card>

            <Dialog open={uploading} onOpenChange={(v) => !v && setUploading(false)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('رفع ملفّ بيانات — الخطوة 1: التسجيل')}</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <p className="text-[12px] text-[#9ca3af]">{t('يُسجَّل الملفّ برقمٍ وبصمة ويُقرأ — ولا يُستورد شيءٌ منه قبل الربط والفحص والتجربة والاعتماد.')}</p>
                        <Field label="الملفّ" required hint="CSV أو XLSX — لا معادلات تُنفَّذ ولا ماكرو">
                            <Input type="file" accept=".csv,.xlsx" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
                        </Field>
                        <Field label="الصنف" required error={errors?.category}>
                            <Select value={form.category} placeholder="اختر صنف البيانات" onChange={(e) => setForm({ ...form, category: e.target.value })} options={categories.map((c) => ({ value: c.value, label: c.financial ? `${c.label} (ماليّ)` : c.label }))} />
                        </Field>
                        <Field label="الاسم" hint="اسمٌ يُعرف به الملفّ — «عملاء الإكسل القديم»">
                            <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                        </Field>
                        <Field label="المصدر" hint="من أين جاء: نظام قديم · بريد · محاسب">
                            <Input value={form.source} onChange={(e) => setForm({ ...form, source: e.target.value })} />
                        </Field>
                        <Field label="ملاحظات">
                            <Textarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
                        </Field>
                    </div>
                    <DialogFooter>
                        <Button disabled={!file || form.category === ''} onClick={submit}>{t('تسجيل وقراءة')}</Button>
                        <Button variant="outline" onClick={() => setUploading(false)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
