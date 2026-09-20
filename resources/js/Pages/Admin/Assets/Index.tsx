import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { CalendarDays, Package, PackageOpen, Plus, Wrench } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { assetTone, label, type Labels } from '@/lib/operations';

interface AssetRow {
    id: number;
    code: string;
    name: string;
    category: string;
    category_label: string;
    status: string;
    condition: string;
    location: string | null;
    brand: string | null;
    model: string | null;
    serial_number: string | null;
    out_of_service: boolean;
    free: boolean | null;
}

interface Props {
    assets: AssetRow[];
    pagination: { current_page: number; last_page: number; total: number };
    filters: Record<string, string | undefined>;
    statuses: string[];
    conditions: string[];
    categories: { value: string; label: string }[];
    locations: string[];
    totals: { all: number; available: number; deployed: number; out_of_service: number; outstanding: number };
    labels: Labels;
    may: { manage: boolean };
}

/**
 * سجلُّ المعدّات — كلُّ قطعةٍ ذاتِ هويّة بحالها ومكانها.
 *
 * والإتاحةُ تُسأل بنافذة: من ومتى إلى متى — فتُعلَّم المشغولةُ في تلك
 * النافذة. وليست مخزونًا: الكمّيّاتُ بلا هويّة في «المنتجات».
 */
export default function AssetsIndex({ assets, pagination, filters, statuses, conditions, categories, locations, totals, labels, may }: Props) {
    const t = useTranslate();
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const [creating, setCreating] = useState(false);
    const [form, setForm] = useState({ name: '', category: '', brand: '', model: '', serial_number: '', purchase_date: '', purchase_cost: '', condition: 'good', location: '', notes: '' });

    const filter = (patch: Record<string, string>) => router.get(route('admin.assets.index'), { ...filters, ...patch, page: undefined }, { preserveState: true, replace: true });

    return (
        <AdminLayout title="المعدّات">
            <PageHeader
                title="المعدّات"
                subtitle={t('القطعُ ذاتُ الهويّة — أين هي الآن وبأيّ حال ومع من')}
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <SmartLink routeName="admin.assets.calendar" href={route('admin.assets.calendar')}><CalendarDays />{t('التقويم')}</SmartLink>
                        </Button>
                        {may.manage && <Button onClick={() => setCreating(true)}><Plus />{t('قطعة جديدة')}</Button>}
                    </div>
                }
            />

            {errors?.asset && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.asset}</div>}

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-5">
                <Stat label="كلّ القطع" value={totals.all} tone="bg-[#6b7280]" icon={Package} onClick={() => filter({ status: '' })} />
                <Stat label="متاحة" value={totals.available} tone="bg-[#059669]" icon={Package} onClick={() => filter({ status: 'available' })} />
                <Stat label="في الميدان" value={totals.deployed} tone="bg-[#2563eb]" icon={PackageOpen} onClick={() => filter({ status: 'deployed' })} />
                <Stat label="خارج الخدمة" value={totals.out_of_service} tone={totals.out_of_service > 0 ? 'bg-[#dc2626]' : 'bg-[#d1d5db]'} icon={Wrench} onClick={() => filter({ status: 'damaged' })} />
                <Stat label="متأخّرة عن العودة" value={totals.outstanding} tone={totals.outstanding > 0 ? 'bg-[#d97706]' : 'bg-[#d1d5db]'} icon={PackageOpen} onClick={() => filter({ status: 'deployed' })} />
            </div>

            <Card className="mb-4 p-4">
                <div className="flex flex-wrap items-end gap-3">
                    <Field label="بحث" className="min-w-[200px] flex-1">
                        <Input defaultValue={filters.q ?? ''} placeholder="الرمز أو الاسم أو الرقم التسلسلي أو الطراز" onKeyDown={(e) => e.key === 'Enter' && filter({ q: (e.target as HTMLInputElement).value })} />
                    </Field>
                    <Field label="الحالة" className="min-w-[140px]">
                        <Select value={filters.status ?? ''} placeholder="الكلّ" onChange={(e) => filter({ status: e.target.value })} options={statuses.map((s) => ({ value: s, label: label(labels, 'asset_status', s) }))} />
                    </Field>
                    <Field label="الصنف" className="min-w-[150px]">
                        <Select value={filters.category ?? ''} placeholder="الكلّ" onChange={(e) => filter({ category: e.target.value })} options={categories} />
                    </Field>
                    <Field label="الحال" className="min-w-[130px]">
                        <Select value={filters.condition ?? ''} placeholder="الكلّ" onChange={(e) => filter({ condition: e.target.value })} options={conditions.map((c) => ({ value: c, label: label(labels, 'condition', c) }))} />
                    </Field>
                    <Field label="المكان" className="min-w-[140px]">
                        <Select value={filters.location ?? ''} placeholder="الكلّ" onChange={(e) => filter({ location: e.target.value })} options={locations.map((l) => ({ value: l, label: l }))} />
                    </Field>
                    <Field label="متاحة من" className="min-w-[170px]">
                        <Input type="datetime-local" value={filters.from ?? ''} onChange={(e) => filter({ from: e.target.value, available: '1' })} />
                    </Field>
                    <Field label="إلى" className="min-w-[170px]">
                        <Input type="datetime-local" value={filters.until ?? ''} onChange={(e) => filter({ until: e.target.value, available: '1' })} />
                    </Field>
                </div>
            </Card>

            <Card className="overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-[13px]">
                        <thead className="bg-[#fafafa] text-[12px] text-[#6b7280]">
                            <tr>
                                <th className="px-4 py-2 text-start">{t('الرمز')}</th>
                                <th className="px-4 py-2 text-start">{t('القطعة')}</th>
                                <th className="px-4 py-2 text-start">{t('الصنف')}</th>
                                <th className="px-4 py-2 text-start">{t('الرقم التسلسلي')}</th>
                                <th className="px-4 py-2 text-center">{t('الحالة')}</th>
                                <th className="px-4 py-2 text-center">{t('الحال')}</th>
                                <th className="px-4 py-2 text-start">{t('المكان')}</th>
                                {filters.from && filters.until && <th className="px-4 py-2 text-center">{t('في النافذة')}</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {assets.length === 0 && (
                                <tr><td colSpan={8} className="px-4 py-10 text-center text-[#9ca3af]"><Package className="mx-auto mb-2 size-6" />{t('لا قطع بعد — سجّل أوّل قطعة')}</td></tr>
                            )}
                            {assets.map((a) => (
                                <tr key={a.id} className="border-t border-[#f4f4f5]">
                                    <td className="px-4 py-2 font-mono text-[12px]"><SmartLink routeName="admin.assets.show" href={route('admin.assets.show', a.id)} className="text-[#1d4ed8]">{a.code}</SmartLink></td>
                                    <td className="px-4 py-2"><span className="font-medium">{a.name}</span>{(a.brand || a.model) && <span className="block text-[11px] text-[#9ca3af]">{[a.brand, a.model].filter(Boolean).join(' ')}</span>}</td>
                                    <td className="px-4 py-2 text-[12px]">{a.category_label}</td>
                                    <td className="px-4 py-2 font-mono text-[12px] text-[#6b7280]" dir="ltr">{a.serial_number ?? '—'}</td>
                                    <td className="px-4 py-2 text-center"><span className={'rounded-full px-2 py-1 text-[11px] ' + assetTone(a.status)}>{label(labels, 'asset_status', a.status)}</span></td>
                                    <td className="px-4 py-2 text-center text-[12px]">{label(labels, 'condition', a.condition)}</td>
                                    <td className="px-4 py-2 text-[12px] text-[#6b7280]">{a.location ?? '—'}</td>
                                    {filters.from && filters.until && <td className="px-4 py-2 text-center text-[12px]">{a.free === null ? '—' : a.free ? <span className="text-[#047857]">{t('حرّة')}</span> : <span className="text-[#b91c1c]">{t('مشغولة')}</span>}</td>}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {pagination.last_page > 1 && (
                    <div className="flex items-center justify-between border-t border-[#f4f4f5] px-4 py-2 text-[12px] text-[#6b7280]">
                        <span>{t('صفحة :n من :m', { n: String(pagination.current_page), m: String(pagination.last_page) })}</span>
                        <div className="flex gap-2">
                            <Button variant="outline" size="sm" disabled={pagination.current_page <= 1} onClick={() => router.get(route('admin.assets.index'), { ...filters, page: pagination.current_page - 1 })}>{t('السابق')}</Button>
                            <Button variant="outline" size="sm" disabled={pagination.current_page >= pagination.last_page} onClick={() => router.get(route('admin.assets.index'), { ...filters, page: pagination.current_page + 1 })}>{t('التالي')}</Button>
                        </div>
                    </div>
                )}
            </Card>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{t('قطعة جديدة')}</DialogTitle></DialogHeader>
                    <div className="space-y-3 px-5 pb-5">
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="الاسم" required error={errors?.name}><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
                            <Field label="الصنف" required error={errors?.category}>
                                <Select value={form.category} placeholder="اختر صنفًا" onChange={(e) => setForm({ ...form, category: e.target.value })} options={categories} />
                            </Field>
                            <Field label="العلامة"><Input value={form.brand} onChange={(e) => setForm({ ...form, brand: e.target.value })} /></Field>
                            <Field label="الطراز"><Input value={form.model} onChange={(e) => setForm({ ...form, model: e.target.value })} /></Field>
                            <Field label="الرقم التسلسلي" hint="فريدٌ في الشركة"><Input value={form.serial_number} onChange={(e) => setForm({ ...form, serial_number: e.target.value })} dir="ltr" /></Field>
                            <Field label="الحال">
                                <Select value={form.condition} onChange={(e) => setForm({ ...form, condition: e.target.value })} options={conditions.map((c) => ({ value: c, label: label(labels, 'condition', c) }))} />
                            </Field>
                            <Field label="تاريخ الشراء"><Input type="date" value={form.purchase_date} onChange={(e) => setForm({ ...form, purchase_date: e.target.value })} /></Field>
                            <Field label="تكلفة الشراء"><Input type="number" step="0.001" value={form.purchase_cost} onChange={(e) => setForm({ ...form, purchase_cost: e.target.value })} dir="ltr" /></Field>
                            <Field label="المكان" hint="المستودع أو الرفّ"><Input value={form.location} onChange={(e) => setForm({ ...form, location: e.target.value })} /></Field>
                        </div>
                        <Field label="ملاحظات"><Textarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></Field>
                    </div>
                    <DialogFooter>
                        <Button disabled={form.name.trim() === '' || form.category === ''} onClick={() => router.post(route('admin.assets.store'), form, { onSuccess: () => setCreating(false) })}>{t('تسجيل')}</Button>
                        <Button variant="outline" onClick={() => setCreating(false)}>{t('إلغاء')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}

function Stat({ label: text, value, tone, icon: Icon, onClick }: { label: string; value: number; tone: string; icon: typeof Package; onClick: () => void }) {
    const t = useTranslate();

    return (
        <button type="button" onClick={onClick} className="flex items-center gap-3 rounded-xl border border-[#e5e7eb] bg-white p-3 text-start transition hover:bg-[#fafafa]">
            <span className={'flex size-9 shrink-0 items-center justify-center rounded-lg text-white ' + tone}><Icon className="size-4" /></span>
            <span className="min-w-0">
                <span className="block text-[22px] font-bold leading-none tabular-nums text-[#111]">{number(value)}</span>
                <span className="block truncate text-[12px] text-[#6b7280]">{t(text)}</span>
            </span>
        </button>
    );
}
